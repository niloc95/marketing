<?php

namespace App\Services\Description;

use App\Libraries\ListingText;
use App\Libraries\RichText;

/**
 * The template draft, rewritten into warmer prose by a small model on
 * Cloudflare Workers AI. Free up to Cloudflare's daily allowance; see
 * Config\Directory::$workersAiToken.
 *
 * The templates stay the source of truth. The model is sent only the facts
 * the owner typed and the template draft, never anything from another
 * listing, and is told to add nothing. Its answer is then checked rather than
 * trusted: it must name the business, fit the length rules, carry no dashes,
 * links, emails or phone numbers, and use no number the owner did not give
 * (no invented years or prices). Any failure, a timeout, an error from
 * Cloudflare or the daily cap being reached, and the owner gets the template
 * draft instead. They can never tell the difference except by the wording.
 *
 * Never throws. The token travels in a header only, and nothing logged here
 * includes it.
 */
class WorkersAiWriter implements DescriptionWriter
{
    private const ENDPOINT = 'https://api.cloudflare.com/client/v4/accounts/%s/ai/run/%s';

    /** Seconds. The owner is watching a "Writing a draft…" message. */
    private const TIMEOUT = 6;

    private const MAX_TOKENS = 320;

    /** Shorter than the field allows: the model's job is to read better, not longer. */
    private const MAX_CHARS = 700;

    private const SYSTEM_PROMPT = <<<'TXT'
        You write short business descriptions for a South African local business directory.
        Write from the facts. A rough draft is included only as an example of the facts in a sentence; do not copy its wording, and fix anything that reads awkwardly in it.
        The category is a label from our directory, not always a word for the business. Turn it into natural English without changing its meaning.
        Rules you must follow:
        1. Say only what the facts say. Never add services, prices, numbers, years, awards, qualifications, staff, experience, quality claims or contact details, and never describe the atmosphere, the people it helps, or how good it is.
        2. Start with the business name.
        3. Write 2 to 4 sentences in warm, plain South African English. Use "we" after the first sentence.
        4. Never use dashes or hyphens of any kind. Write "walk ins", not "walk-ins".
        5. No lists, headings, emojis, quotation marks or links.
        6. Keep it under 500 characters. With few facts, write fewer sentences, and never say the same fact twice.
        Reply with the description only.
        TXT;

    /**
     * One worked example, sent ahead of the real request. Small models follow
     * an example far better than a rule. Fictional, and from a field no real
     * request is likely to share, so its words do not leak into answers.
     */
    private const EXAMPLE_FACTS = <<<'TXT'
        Facts:
        Business name: Karoo Kites
        Category: Kite Making & Repairs
        Town: Graaff Reinet
        Services: Custom kites, Kite repairs
        Areas of focus: Kids' kites
        Features: Card payments accepted, Parking available

        Rough draft (example only):
        Karoo Kites is a kite making and repairs business based in Graaff Reinet. We offer custom kites and kite repairs. Our areas of focus include kids' kites. Good to know: card payments accepted and parking available.
        TXT;

    private const EXAMPLE_ANSWER = 'Karoo Kites makes and repairs kites in Graaff Reinet. We build custom kites, including kites for kids, and fix the ones that have seen a few too many windy days. There is parking on site, and we take card payments.';

    /**
     * Claims an owner has to make themselves. A reply containing one of these
     * that the facts do not is rejected for the template.
     */
    private const OWNER_ONLY_CLAIMS = [
        'award', 'certified', 'qualified', 'accredited', 'registered', 'guarantee',
        'leading', 'best', 'number one', 'experienced', 'experience', 'established',
        'family owned', 'family run', 'affordable', 'cheapest', 'expert',
    ];

    private DescriptionDraftService $templates;

    public function __construct(
        private readonly string $accountId,
        private readonly string $token,
        private readonly string $model,
        private readonly int $dailyCap,
        ?DescriptionDraftService $templates = null,
    ) {
        $this->templates = $templates ?? new DescriptionDraftService();
    }

    /**
     * Below this many services, areas of focus and features together, the
     * model is not asked. Given only a name, a category and a town it can add
     * nothing true, and in testing it filled the gap with inventions ("and a
     * venue for events") or the town said twice.
     */
    public const MIN_FACTS = 2;

    public function draft(array $facts): string
    {
        $fallback = $this->templates->draft($facts);
        $detail   = count(array_filter(array_merge(
            (array) ($facts['services'] ?? []),
            (array) ($facts['tags'] ?? []),
            (array) ($facts['features'] ?? []),
        ), static fn ($v): bool => trim((string) $v) !== ''));

        if ($fallback === '' || $detail < self::MIN_FACTS || ! $this->takeFromBudget()) {
            return $fallback;
        }

        $answer = $this->ask([
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' => self::EXAMPLE_FACTS],
            ['role' => 'assistant', 'content' => self::EXAMPLE_ANSWER],
            ['role' => 'user', 'content' => $this->prompt($facts, RichText::toPlainText($fallback))],
        ]);

        return ($answer === null ? null : $this->accept($answer, $facts)) ?? $fallback;
    }

    /**
     * The model's answer as HTML paragraphs, or null when it breaks a rule.
     *
     * @param array<string,mixed> $facts
     */
    public function accept(string $answer, array $facts): ?string
    {
        $text = trim(str_replace("\r", '', $answer));
        // Small models like to announce themselves: "Here is the description:".
        $text = trim((string) preg_replace('/^(here\b|sure\b|certainly\b)[^\n]*:\s*/i', '', $text));
        $text = trim($text, " \t\n\"'\u{201C}\u{201D}");

        $name = trim((string) ($facts['name'] ?? ''));
        if ($text === '' || $name === '' || mb_stripos($text, $name) === false) {
            return null;
        }
        if (preg_match('/^\s*([-*\x{2022}#]|\d+\.)\s/mu', $text) === 1) {
            return null; // a list or a heading, not prose
        }
        if (preg_match('#https?://|www\.|@|\.co\.za\b|\.com\b|\+27|\d[\d ]{6,}\d#i', $text) === 1) {
            return null; // a link, email or phone number the owner never gave us
        }
        if (! $this->numbersAreTheOwners($text, $facts) || ! $this->claimsAreTheOwners($text, $facts)) {
            return null;
        }

        $text = $this->withoutDashes($text, $facts);
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $length = mb_strlen((string) preg_replace('/\s+/', ' ', $text));
        if ($length < ListingText::DESCRIPTION_MIN || $length > self::MAX_CHARS) {
            return null;
        }

        $html = '';
        foreach (preg_split('/\n\s*\n/', $text) ?: [] as $paragraph) {
            $paragraph = trim((string) preg_replace('/\s+/', ' ', $paragraph));
            if ($paragraph !== '') {
                $html .= '<p>' . esc($paragraph) . '</p>';
            }
        }

        return $html === '' ? null : $html;
    }

    /**
     * The chat call. Null on any failure. Protected so tests can answer it.
     *
     * @param list<array{role:string,content:string}> $messages
     */
    protected function ask(array $messages): ?string
    {
        try {
            $response = service('curlrequest', [
                'timeout'         => self::TIMEOUT,
                'connect_timeout' => self::TIMEOUT,
                'http_errors'     => false,
            ], null, null, false)->post(sprintf(self::ENDPOINT, rawurlencode($this->accountId), $this->model), [
                'headers' => ['Authorization' => 'Bearer ' . $this->token],
                'json'    => ['messages' => $messages, 'max_tokens' => self::MAX_TOKENS, 'temperature' => 0.6],
            ]);

            $status = $response->getStatusCode();
            if ($status !== 200) {
                // 429 is the daily allowance running out on Cloudflare's side;
                // 401/403 a bad or revoked token. Templates cover both.
                log_message($status === 429 ? 'warning' : 'error', 'WorkersAiWriter: HTTP ' . $status);

                return null;
            }

            $decoded = json_decode($response->getBody(), true);
            $text    = $decoded['result']['response'] ?? null;

            return is_string($text) ? $text : null;
        } catch (\Throwable $e) {
            log_message('warning', 'WorkersAiWriter: ' . str_replace($this->token, '[token]', $e->getMessage()));

            return null;
        }
    }

    /** @param array<string,mixed> $facts */
    private function prompt(array $facts, string $draft): string
    {
        $line = static function (string $label, mixed $value): string {
            $value = is_array($value) ? implode(', ', array_filter(array_map('strval', $value))) : trim((string) $value);

            return $value === '' ? '' : $label . ': ' . $value . "\n";
        };

        $where = (string) ($facts['customer_location'] ?? 'visit');

        return "Facts:\n"
            . $line('Business name', $facts['name'] ?? '')
            . $line('Category', $facts['category'] ?? '')
            . $line('Profile type', \App\Models\DirectoryListingModel::TYPES[(string) ($facts['type'] ?? '')] ?? '')
            . $line('Suburb', $facts['suburb'] ?? '')
            . $line('Town', $facts['city'] ?? '')
            . $line('Services', $facts['services'] ?? [])
            . $line('Areas of focus', $facts['tags'] ?? [])
            . ($where !== 'visit' ? $line('Travels to customers in', $facts['service_areas'] ?? []) : '')
            . $line('Features', $facts['features'] ?? [])
            . "\nRough draft (example only):\n"
            . $draft;
    }

    /**
     * Every number in the answer must appear somewhere in what the owner told
     * us. Catches "for over 20 years", "from R250", "since 1998".
     *
     * @param array<string,mixed> $facts
     */
    private function numbersAreTheOwners(string $text, array $facts): bool
    {
        preg_match_all('/\d+/', $text, $m);
        if ($m[0] === []) {
            return true;
        }

        $given = json_encode($facts, JSON_UNESCAPED_UNICODE) ?: '';
        foreach ($m[0] as $number) {
            if (preg_match('/(?<!\d)' . $number . '(?!\d)/', $given) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $facts */
    private function claimsAreTheOwners(string $text, array $facts): bool
    {
        $given = mb_strtolower(json_encode($facts, JSON_UNESCAPED_UNICODE) ?: '');
        foreach (self::OWNER_ONLY_CLAIMS as $claim) {
            if (preg_match('/\b' . $claim . '/i', $text) === 1 && ! str_contains($given, $claim)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Customer copy has no dashes or hyphenated words. Same rules as
     * DescriptionDraftService::item(), except for the business, suburb and
     * town names, which are proper names and stay as the owner typed them.
     *
     * @param array<string,mixed> $facts
     */
    private function withoutDashes(string $text, array $facts): string
    {
        $keep = [];
        foreach (['name', 'suburb', 'city'] as $key) {
            $value = trim((string) ($facts[$key] ?? ''));
            if ($value !== '' && preg_match('/[\x{2010}-\x{2014}-]/u', $value) === 1) {
                $token        = "\u{E000}" . count($keep) . "\u{E001}";
                $keep[$token] = $value;
                $text         = str_ireplace($value, $token, $text);
            }
        }

        $text = (string) preg_replace('/(?<=\p{L})[\x{2010}\x{2011}-](?=\p{L})/u', ' ', $text);
        $text = (string) preg_replace('/\s*[\x{2013}\x{2014}]+\s*|\s+-+\s+/u', ', ', $text);

        return strtr($text, $keep);
    }

    /** One draft off today's allowance. False once the day's cap is used. */
    private function takeFromBudget(): bool
    {
        $key = 'ai_drafts_' . gmdate('Ymd');
        try {
            $used = (int) cache()->get($key);
            if ($used >= $this->dailyCap) {
                return false;
            }
            cache()->save($key, $used + 1, DAY);
        } catch (\Throwable) {
            // A broken cache means no budget we can trust: use the templates.
            return false;
        }

        return true;
    }
}
