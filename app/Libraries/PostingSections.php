<?php

namespace App\Libraries;

/**
 * The structured sections a vacancy is posted with, shared by our own roles
 * (/careers, CareersService) and the Jobs board (JobBoardService): About the
 * role, You may be a good fit if you, Strong candidates may also have
 * experience with, Representative projects, and Logistics.
 *
 * Every field but `description` is one item per line. The page (via
 * partials/_posting_sections) and the JobPosting markup (descriptionHtml())
 * both read sections(), so Google is never shown text the page does not show.
 */
final class PostingSections
{
    public const MAX_SECTION = 3000;

    /** Least number of "good fit" points a vacancy may have. */
    public const MIN_GOOD_FIT = 2;

    /**
     * column => [heading, type], in page order. 'paragraphs' splits on blank
     * lines, 'list' is one item per line, 'facts' is a list whose
     * "Label: value" lines get a bold label.
     */
    public const SECTIONS = [
        'description'       => ['About the role', 'paragraphs'],
        'good_fit'          => ['You may be a good fit if you:', 'list'],
        'strong_candidates' => ['Strong candidates may also have experience with:', 'list'],
        'projects'          => ['Representative projects:', 'list'],
        'logistics'         => ['Logistics:', 'facts'],
    ];

    /** The four one item per line columns, for forms and validation. */
    public const LIST_FIELDS = ['good_fit', 'strong_candidates', 'projects', 'logistics'];

    /**
     * The row's sections in page order, empty ones left out.
     *
     * @return list<array{key:string,heading:string,type:string,items:list<string|array{label:string,value:string}>}>
     */
    public static function sections(array $row): array
    {
        $out = [];
        foreach (self::SECTIONS as $key => [$heading, $type]) {
            $text  = (string) ($row[$key] ?? '');
            $items = $type === 'paragraphs' ? self::paragraphs($text) : self::lines($text);
            if ($type === 'facts') {
                $items = array_map(static function (string $line) {
                    // "Location: Remote" gets a bold label; a line without a
                    // short label before a colon stays plain text.
                    if (preg_match('/^([^:]{1,40}):\s+(.+)$/u', $line, $m)) {
                        return ['label' => trim($m[1]), 'value' => trim($m[2])];
                    }

                    return $line;
                }, $items);
            }
            if ($items !== []) {
                $out[] = ['key' => $key, 'heading' => $heading, 'type' => $type, 'items' => $items];
            }
        }

        return $out;
    }

    /**
     * One item per line: trimmed, blanks dropped, and a leading "-", "*",
     * "•" or "1." stripped, so a list pasted from a document keeps no stray
     * markers.
     *
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*(?:[-*•·]|\d+[.)])\s+/u', '', $line));
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /** @return list<string> paragraphs split on blank lines, single newlines kept */
    public static function paragraphs(string $text): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\R\s*\R/u', trim($text)) ?: []),
            static fn ($p) => $p !== ''
        ));
    }

    /**
     * The JobPosting description: every section as simple HTML, which Google
     * for Jobs renders, closed by an optional "About X" block that the page
     * also shows.
     *
     * @param array{heading:string,paragraphs:list<string>}|null $about
     */
    public static function descriptionHtml(array $row, ?array $about = null): string
    {
        $html = '';
        foreach (self::sections($row) as $section) {
            if ($section['key'] !== 'description') {
                $html .= '<h3>' . esc($section['heading']) . '</h3>';
            }
            if ($section['type'] === 'paragraphs') {
                foreach ($section['items'] as $p) {
                    $html .= '<p>' . nl2br(esc($p)) . '</p>';
                }
                continue;
            }
            $html .= '<ul>';
            foreach ($section['items'] as $item) {
                $html .= '<li>' . (is_array($item)
                    ? '<strong>' . esc($item['label']) . ':</strong> ' . esc($item['value'])
                    : esc($item)) . '</li>';
            }
            $html .= '</ul>';
        }

        if ($about !== null && $about['paragraphs'] !== []) {
            $html .= '<h3>' . esc($about['heading']) . '</h3>';
            foreach ($about['paragraphs'] as $p) {
                $html .= '<p>' . esc($p) . '</p>';
            }
        }

        return $html;
    }
}
