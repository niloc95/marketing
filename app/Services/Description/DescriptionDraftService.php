<?php

namespace App\Services\Description;

use App\Libraries\RichText;
use Config\DescriptionTemplates;

/**
 * "Help me write this": a first draft of the business description, written
 * from templates (Config\DescriptionTemplates) and the owner's own answers
 * elsewhere on the form. No model, no network, no cost.
 *
 * The draft only ever says what the owner told us: their category and town,
 * the services and areas of focus they typed, the places they travel to, the
 * features they ticked. Insights from other listings (CategoryInsightsService)
 * are offered as chips the owner adds themselves; they never go into a draft
 * unasked, because a description must not claim a service the business does
 * not offer.
 *
 * The wording is picked from the name, so the same business always gets the
 * same draft and two salons on one street do not read word for word alike.
 */
final class DescriptionDraftService implements DescriptionWriter
{
    private DescriptionTemplates $config;

    public function __construct(?DescriptionTemplates $config = null)
    {
        $this->config = $config ?? config('DescriptionTemplates');
    }

    public function draft(array $facts): string
    {
        $name     = $this->clean((string) ($facts['name'] ?? ''));
        $category = $this->item((string) ($facts['category'] ?? ''));
        if ($name === '' || $category === '') {
            return '';
        }

        $type   = (string) ($facts['type'] ?? 'practice');
        $family = $this->config->family[$type] ?? 'business';
        $set    = $this->config->templates[$family];
        $seed   = crc32(mb_strtolower($name));

        $city   = $this->clean((string) ($facts['city'] ?? ''));
        $suburb = $this->clean((string) ($facts['suburb'] ?? ''));
        $place  = $suburb !== '' && $city !== '' && mb_strtolower($suburb) !== mb_strtolower($city)
            ? $suburb . ', ' . $city
            : ($city !== '' ? $city : $suburb);

        $values = [
            'name'       => $name,
            'category'   => $category,
            'a_category' => $this->aCategory($category, $family),
            'a_kind'     => $this->config->kinds[$type] ?? '',
            'place'      => $place,
            'list'       => $this->join($facts['services'] ?? []),
            'focus'      => $this->join($facts['tags'] ?? []),
            'features'   => $this->join($facts['features'] ?? []),
            'areas'      => '',
        ];

        $location = (string) ($facts['customer_location'] ?? 'visit');
        $reachSlot = $location === 'both' ? 'reach_both' : 'reach';
        if ($location !== 'visit') {
            $values['areas'] = $this->join($facts['service_areas'] ?? [], keepCase: true);
        }

        // Sentences in reading order, each tagged with how willing we are to
        // lose it when the draft runs over the cap: features first, then
        // where you work. The opening and the offer always stay.
        $first  = [];
        $second = [];
        $first[]  = ['keep', $this->pick($set['intro'] ?? [], $values, $seed)];
        $first[]  = ['keep', $this->pick($set['offer'] ?? [], $values, $seed + 1)];
        $first[]  = ['focus', $this->pick($set['focus'] ?? [], $values, $seed + 5)];
        $second[] = ['reach', $this->pick($set[$reachSlot] ?? [], $values, $seed + 2)];
        $second[] = ['features', $this->pick($set['features'] ?? [], $values, $seed + 3)];
        $second[] = ['keep', $this->pick($set['closing'] ?? [], $values, $seed + 4)];

        foreach ([null, 'features', 'reach', 'focus'] as $drop) {
            if ($drop !== null) {
                $keep   = static fn (array $s): bool => $s[0] !== $drop;
                $first  = array_values(array_filter($first, $keep));
                $second = array_values(array_filter($second, $keep));
            }
            $html = $this->paragraphs($first, $second);
            if (! RichText::exceedsCap(RichText::toPlainText($html))) {
                return $html;
            }
        }

        return $html;
    }

    /** @param list<array{0:string,1:?string}> ...$paragraphs */
    private function paragraphs(array ...$paragraphs): string
    {
        $html = '';
        foreach ($paragraphs as $sentences) {
            $text = implode(' ', array_filter(array_column($sentences, 1)));
            if ($text !== '') {
                $html .= '<p>' . esc($text) . '</p>';
            }
        }

        return $html;
    }

    /**
     * One template from the slot whose placeholders all have values, chosen
     * by $seed. Null when none fits, which simply leaves the sentence out.
     *
     * @param list<string>          $templates
     * @param array<string,string>  $values
     */
    private function pick(array $templates, array $values, int $seed): ?string
    {
        $usable = array_values(array_filter($templates, static function (string $t) use ($values): bool {
            preg_match_all('/\{(\w+)\}/', $t, $m);
            foreach ($m[1] as $key) {
                if (($values[$key] ?? '') === '') {
                    return false;
                }
            }

            return true;
        }));
        if ($usable === []) {
            return null;
        }

        // The longest, most specific wordings come first in each slot, so
        // only choose between those that fit as well as the first one does.
        $placeholders = static fn (string $t): int => preg_match_all('/\{\w+\}/', $t);
        $best         = $placeholders($usable[0]);
        $usable       = array_values(array_filter($usable, static fn (string $t): bool => $placeholders($t) === $best));

        $template = $usable[abs($seed) % count($usable)];

        return (string) preg_replace_callback(
            '/\{(\w+)\}/',
            static fn (array $m): string => $values[$m[1]],
            $template
        );
    }

    /**
     * "x", "x and y", "x, y and z" from the owner's items: tidied, deduplicated,
     * at most maxListItems of them, overlong ones skipped.
     *
     * @param list<mixed> $items
     */
    private function join(array $items, bool $keepCase = false): string
    {
        $out  = [];
        $seen = [];
        foreach ($items as $raw) {
            $item = $keepCase ? $this->clean((string) $raw) : $this->item((string) $raw);
            $key  = mb_strtolower($item);
            if ($item === '' || isset($seen[$key]) || mb_strlen($item) > $this->config->maxItemLength) {
                continue;
            }
            $seen[$key] = true;
            $out[]      = $item;
            if (count($out) === $this->config->maxListItems) {
                break;
            }
        }

        if (count($out) < 2) {
            return $out[0] ?? '';
        }
        $last = array_pop($out);

        return implode(', ', $out) . ' and ' . $last;
    }

    /**
     * A generic item for the middle of a sentence: a service, a tag, a feature
     * label, a category. No dashes or hyphens (customer copy rule), "&" and
     * "/" spelt out, and ordinary capitalised words lowered: "Teeth Whitening"
     * reads "teeth whitening", while "HIV" and "iPhone" stay as they are.
     */
    public function item(string $text): string
    {
        $text = $this->clean($text);
        $text = (string) preg_replace('/(?<=\p{L})[\x{2010}\x{2011}-](?=\p{L})/u', ' ', $text);
        $text = (string) preg_replace('/\s*[\x{2013}\x{2014}-]+\s*/u', ', ', $text);
        $text = (string) preg_replace('/\s*&\s*/u', ' and ', $text);
        $text = (string) preg_replace('#\s*/\s*#u', ' or ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text), " ,.;:");

        return implode(' ', array_map(
            static fn (string $w): string => preg_match('/^\p{Lu}[\p{Ll}\x{2019}\']*$/u', $w) === 1 ? mb_strtolower($w) : $w,
            explode(' ', $text)
        ));
    }

    /** Trimmed, single spaced, without trailing sentence punctuation. */
    private function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)), " \t\n\r\0\x0B.;:");
    }

    /**
     * "a dentist", or "a towing business" when the category names a field of
     * work rather than the business. See DescriptionTemplates::$nouns.
     */
    private function aCategory(string $category, string $family): string
    {
        // The last word as written, never singularised: "cleaning services"
        // is a field of work, even though "service" alone would be a noun.
        $words = explode(' ', mb_strtolower($category));
        $last  = (string) end($words);

        $noun = in_array($last, $this->config->nouns, true);
        if (! $noun && ! in_array($last, $this->config->notNouns, true)) {
            foreach ($this->config->nounEndings as $ending) {
                if (str_ends_with($last, $ending) && mb_strlen($last) >= mb_strlen($ending) + 3) {
                    $noun = true;
                    break;
                }
            }
        }

        $suffix = $noun ? '' : ($this->config->nounSuffix[$family] ?? '');

        return $this->article($category) . ' ' . $category . ($suffix !== '' ? ' ' . $suffix : '');
    }

    /**
     * By sound, not spelling: "a university", "a urologist", but "an NGO" and
     * "an IT support business", because an acronym is read letter by letter.
     */
    private function article(string $phrase): string
    {
        $first = (string) strtok($phrase, ' ');
        if (preg_match('/^\p{Lu}{2,}$/u', $first) === 1) {
            return str_contains('AEFHILMNORSX', $first[0]) ? 'an' : 'a';
        }
        if (preg_match('/^(uni|uro|use|usu|uti|eu|one)/i', $first) === 1) {
            return 'a';
        }

        return preg_match('/^[aeiou]/i', $first) === 1 ? 'an' : 'a';
    }
}
