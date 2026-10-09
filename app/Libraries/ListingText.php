<?php

namespace App\Libraries;

use DOMText;
use Normalizer;

/**
 * House rules for the two pieces of free text every profile leads with: the
 * business name and the description.
 *
 * Written against a real production profile whose name was
 * "Polokwane ➸ [+②⑦⑦①③③⑥③⓪④⑦]”➸ A TRADITIONAL HEALER /SANGOMA /LOVE SPELLS in
 * Polokwane, Mankweng, Tzaneen, …": a phone number spelled in enclosed digits
 * to dodge a digit filter, decorative arrows, a list of towns for search
 * stuffing, and capitals throughout.
 *
 * The split is deliberate. What only a spammer does (a phone number, emoji,
 * brackets, a list of towns in the name) is *rejected*, so the person has to
 * look at it. What honest owners do too (typing in capitals) is *fixed*
 * quietly — rejecting it would cost real signups for a cosmetic problem.
 *
 * Called by DirectoryListingMutationService on public signup and owner edit.
 * Admin intake does not use it: imports carry names as the source spelled them.
 */
final class ListingText
{
    /** A new or changed name longer than this is refused. Stored longer names stay valid while unchanged. */
    public const NAME_MAX = 100;

    /** Plain-text floor for the now-compulsory description. */
    public const DESCRIPTION_MIN = 50;

    /** More commas than this in a name is a list of places, not a name. */
    private const NAME_MAX_COMMAS = 3;

    /** Upper-case words that stay upper-case when a shouting name is fixed. */
    private const ACRONYMS = ['SA', 'CC', 'NPC', 'PTY', 'LTD', 'CEO', 'MD', 'HR', 'TV', 'DJ', 'ATM', 'GP', 'ICT', 'DIY', 'BBQ', 'CPA', 'SME', 'NGO', 'PC', 'UK', 'USA', 'JHB', 'CPT', 'PTA', 'KZN'];

    /** Short words that are words, not initials, when a shouting name is fixed. */
    private const SHORT_WORDS = ['A', 'AN', 'THE', 'AND', 'OR', 'OF', 'IN', 'ON', 'AT', 'TO', 'BY', 'FOR', 'MY', 'OUR', 'YOU', 'ALL', 'NEW', 'BIG', 'TOP', 'ONE', 'TWO', 'HUB', 'BAR', 'CAR', 'CUT', 'DOG', 'CAT', 'PET', 'SPA', 'GYM', 'ART', 'LAW', 'TAX', 'BUY', 'EAT', 'FIX', 'MR', 'MRS', 'DR', 'ST', 'MAX', 'PRO', 'VAN', 'DE', 'DU', 'LE', 'LA', 'KA', 'WA', 'NA', 'YA', 'LAB', 'KIDS'];

    /**
     * Letters (any script, with their combining marks), digits, spaces and
     * ordinary business-name punctuation. Anything else — emoji, arrows,
     * brackets, enclosed digits, decorative quotes — is refused.
     */
    private const NAME_ALLOWED = "/^[\\p{L}\\p{M}\\p{Nd} &'\\x{2019}\\x{2013}.,\\-()\\/+!:@]+$/u";

    /**
     * Why $name cannot be a business name, or null when it can.
     *
     * Checked on the raw input first, so "②⑦⑦" is refused as a symbol rather
     * than quietly normalised into a phone number; the digit count then runs
     * on the NFKC form, which is what would be displayed.
     */
    public static function nameProblem(string $name): ?string
    {
        $name = self::collapse($name);
        if ($name === '') {
            return null; // "required" is validate()'s message, not ours
        }

        if (preg_match('#https?://|www\.|\.(co\.za|com|net|org)\b|@\S+\.\S#i', $name) === 1) {
            return 'Please leave website and email addresses out of your business name. There are fields for them below.';
        }
        if (preg_match_all('/\d/u', self::nfkc($name)) >= 7) {
            return 'Please put your phone number in the Phone field, not in your business name.';
        }
        if (preg_match(self::NAME_ALLOWED, $name) !== 1) {
            return 'Please use only letters, numbers and ordinary punctuation in your business name, no symbols, emoji or brackets.';
        }
        if (substr_count($name, ',') > self::NAME_MAX_COMMAS) {
            return 'Please list the areas you serve in your description, not in your business name.';
        }

        return null;
    }

    /** Whitespace collapsed, and ALL-CAPS turned into Title Case. */
    public static function tidyName(string $name): string
    {
        $name = self::collapse($name);
        if (! self::isShouting($name, 0.7, 6)) {
            return $name;
        }

        return (string) preg_replace_callback('/[\p{L}\p{M}\']+/u', static function (array $m): string {
            $word  = $m[0];
            $upper = mb_strtoupper($word);
            if (in_array($upper, self::ACRONYMS, true)) {
                return $upper;
            }
            // No vowels means an abbreviation (CC, PTY), not a word — and a
            // short capitalised word that is not an ordinary one is probably
            // initials (ABC Plumbing), which must not become "Abc".
            if (preg_match('/[aeiouy]/iu', $word) !== 1 && mb_strlen($word) <= 4) {
                return $upper;
            }
            if (mb_strlen($word) <= 3 && ! in_array($upper, self::SHORT_WORDS, true)) {
                return $upper;
            }

            return mb_convert_case(mb_strtolower($word), MB_CASE_TITLE);
        }, $name);
    }

    /**
     * Sanitised description HTML with decorative symbols removed and, when most
     * of it is in capitals, sentence case restored. Markup is left alone — see
     * RichText::mapText().
     */
    public static function tidyDescriptionHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $html = RichText::mapText($html, static fn (string $t): string => self::stripSymbols(self::nfkc($t)));

        if (! self::isShouting(RichText::toPlainText($html), 0.5, 20)) {
            return $html;
        }

        // One pass over every text node in document order. A sentence starts
        // after . ! or ?, at a new paragraph / list item / heading, and after
        // a <br>. The flag is shared across nodes, so a sentence running from
        // plain text into a bold span is capitalised once, not twice.
        $startOfSentence = true;
        $lastBlock       = null;

        return RichText::mapText($html, static function (string $text, DOMText $node) use (&$startOfSentence, &$lastBlock): string {
            $block = self::blockOf($node);
            if ($block !== $lastBlock || $node->previousSibling?->nodeName === 'br') {
                $startOfSentence = true;
                $lastBlock       = $block;
            }

            $out = '';
            foreach (mb_str_split(mb_strtolower($text)) as $char) {
                if ($startOfSentence && preg_match('/\p{L}/u', $char) === 1) {
                    $char            = mb_strtoupper($char);
                    $startOfSentence = false;
                } elseif (in_array($char, ['.', '!', '?'], true)) {
                    $startOfSentence = true;
                }
                $out .= $char;
            }

            return self::keepAcronyms($out);
        });
    }

    /** Nearest paragraph-level ancestor, as an identity for "same block". */
    private static function blockOf(DOMText $node): ?string
    {
        for ($n = $node->parentNode; $n !== null; $n = $n->parentNode) {
            if (in_array($n->nodeName, ['p', 'li', 'h2', 'h3', 'blockquote', 'body'], true)) {
                return $n->getNodePath();
            }
        }

        return null;
    }

    /** Put the known acronyms back after a whole-text lower-casing. */
    private static function keepAcronyms(string $text): string
    {
        return (string) preg_replace_callback(
            '/\b(' . implode('|', array_map('strtolower', self::ACRONYMS)) . ')\b/u',
            static fn (array $m): string => mb_strtoupper($m[1]),
            $text
        );
    }

    /** True when at least $ratio of the letters are upper-case, and there are at least $minLetters of them. */
    public static function isShouting(string $text, float $ratio, int $minLetters): bool
    {
        $letters = preg_match_all('/\p{L}/u', $text);
        if ($letters < $minLetters) {
            return false;
        }

        return preg_match_all('/\p{Lu}/u', $text) / $letters >= $ratio;
    }

    /** Emoji, arrows and other pictographs out; letters, digits and punctuation kept. */
    private static function stripSymbols(string $text): string
    {
        $text = (string) preg_replace('/[\p{So}\p{Sk}\p{Co}\x{FE0F}\x{200D}]+/u', '', $text);

        return (string) preg_replace('/ {2,}/', ' ', $text);
    }

    /** ② → 2, 𝓛𝓸𝓿𝓮 → Love. */
    private static function nfkc(string $text): string
    {
        $out = class_exists(Normalizer::class) ? Normalizer::normalize($text, Normalizer::FORM_KC) : $text;

        return is_string($out) ? $out : $text;
    }

    private static function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
