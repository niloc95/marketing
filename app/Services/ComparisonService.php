<?php

namespace App\Services;

use App\Controllers\Listing;
use Config\Comparison;

/**
 * The /compare table: what it says, and the admin's edits to it.
 *
 * The defaults live in Config\Comparison. An edit from /admin/comparison is
 * stored whole, as JSON, in xs_directory_settings (DirectorySettings), so the
 * page can be corrected without a deploy. A stored copy goes back through the
 * same validation on every read, so a row damaged by hand falls back to the
 * defaults rather than rendering something the form would have refused.
 *
 * Text is plain, never HTML: the view escapes every string, and save refuses
 * anything with a tag in it so nobody is surprised by literal "<strong>".
 */
class ComparisonService
{
    public const MAX_ROWS         = 30;
    public const MAX_LABEL        = 120;
    public const MAX_NOTE         = 220;
    public const MAX_COLUMN_LABEL = 60;
    public const MAX_COLUMN_SUB   = 80;

    private Comparison $config;
    private DirectorySettings $settings;

    public function __construct(?Comparison $config = null, ?DirectorySettings $settings = null)
    {
        $this->config   = $config ?? config('Comparison');
        $this->settings = $settings ?? new DirectorySettings();
    }

    /**
     * The table as stored or, failing that, the defaults. Placeholders are
     * still in it: this is what the admin form edits.
     *
     * @return array{columns:array<string,array{label:string,sub:string}>,rows:list<array>,checkedOn:string,custom:bool}
     */
    public function table(): array
    {
        $json = $this->settings->comparisonJson();
        if ($json !== null) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $result = $this->normalise($decoded);
                if ($result['errors'] === []) {
                    return $result['table'] + ['custom' => true];
                }
            }
            log_message('warning', 'The saved comparison table is invalid; showing the defaults.');
        }

        return $this->defaults() + ['custom' => false];
    }

    /** The table with {gallery}, {locations}, {team} and {price} filled in, for /compare. */
    public function forPage(): array
    {
        $fill = [
            '{gallery}'   => (string) Listing::GALLERY_MAX,
            '{locations}' => (string) PracticeLocationService::MAX_LOCATIONS,
            '{team}'      => (string) TeamMemberService::MAX_MEMBERS,
            '{price}'     => (new VerificationService())->monthlyAmount(),
        ];

        $table = $this->table();
        array_walk_recursive($table, static function (&$v) use ($fill): void {
            if (is_string($v)) {
                $v = strtr($v, $fill);
            }
        });

        return $table;
    }

    /** @return array{columns:array,rows:list<array>,checkedOn:string} */
    public function defaults(): array
    {
        return [
            'columns'   => $this->config->columns,
            'rows'      => $this->config->rows,
            'checkedOn' => $this->config->checkedOn,
        ];
    }

    /**
     * Save the admin form. All or nothing, like DirectorySettings::save().
     *
     * @param array<string,mixed> $post
     *
     * @return array{ok:bool,errors:array<string,string>,message:string}
     */
    public function save(array $post, string $by): array
    {
        $result = $this->normalise($this->fromPost($post));
        if ($result['errors'] !== []) {
            return ['ok' => false, 'errors' => $result['errors'], 'message' => 'Nothing was saved. Please check the highlighted fields.'];
        }

        $json = json_encode($result['table'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || ! $this->settings->putComparisonJson($json, $by)) {
            return ['ok' => false, 'errors' => [], 'message' => 'Could not save the table. Please try again.'];
        }

        return ['ok' => true, 'errors' => [], 'message' => 'Comparison saved. It is live on /compare now.'];
    }

    /** Go back to the defaults in Config\Comparison. */
    public function reset(string $by): bool
    {
        return $this->settings->putComparisonJson('', $by);
    }

    /**
     * The form's POST, reshaped into a table: rows sorted by their position
     * field, rows with a blank label dropped (that is how a row is deleted).
     *
     * @param array<string,mixed> $post
     */
    private function fromPost(array $post): array
    {
        $rows = [];
        foreach ((array) ($post['rows'] ?? []) as $i => $r) {
            if (! is_array($r) || trim((string) ($r['label'] ?? '')) === '') {
                continue;
            }
            $rows[] = [
                'pos'   => (float) ($r['position'] ?? 0),
                'i'     => (int) $i,
                'key'   => (string) ($r['key'] ?? ''),
                'label' => (string) $r['label'],
                'cells' => (array) ($r['cells'] ?? []),
            ];
        }
        // Position first, then the order they were on the form, so two rows
        // given the same number keep the order the admin saw.
        usort($rows, static fn ($a, $b) => [$a['pos'], $a['i']] <=> [$b['pos'], $b['i']]);

        return [
            'columns'   => (array) ($post['columns'] ?? []),
            'rows'      => array_map(static fn ($r) => array_diff_key($r, ['pos' => 1, 'i' => 1]), $rows),
            'checkedOn' => (string) ($post['checked_on'] ?? ''),
        ];
    }

    /**
     * Check a table and return a clean copy of it. Used for both the admin's
     * POST and a stored copy, so both obey the same rules.
     *
     * @return array{table:array,errors:array<string,string>}
     */
    private function normalise(array $in): array
    {
        $errors = [];
        $text   = static function (mixed $v, int $max, bool $required, string $field, string $what) use (&$errors): string {
            $s = trim(preg_replace('/\s+/u', ' ', is_scalar($v) ? (string) $v : '') ?? '');
            if ($required && $s === '') {
                $errors[$field] = $what . ' cannot be empty.';
            } elseif (mb_strlen($s) > $max) {
                $errors[$field] = $what . ' is too long (at most ' . $max . ' characters).';
            } elseif ($s !== strip_tags($s)) {
                $errors[$field] = $what . ' cannot contain HTML.';
            }

            return $s;
        };

        $columns = [];
        foreach (Comparison::COLUMNS as $col) {
            $c             = (array) ($in['columns'][$col] ?? []);
            $columns[$col] = [
                'label' => $text($c['label'] ?? '', self::MAX_COLUMN_LABEL, true, "columns.$col.label", 'A column heading'),
                'sub'   => $text($c['sub'] ?? '', self::MAX_COLUMN_SUB, false, "columns.$col.sub", 'A column subheading'),
            ];
        }

        $rowsIn = array_values((array) ($in['rows'] ?? []));
        if ($rowsIn === []) {
            $errors['rows'] = 'The table needs at least one row.';
        } elseif (count($rowsIn) > self::MAX_ROWS) {
            $errors['rows'] = 'At most ' . self::MAX_ROWS . ' rows.';
        }

        $rows = [];
        $seen = [];
        foreach (array_slice($rowsIn, 0, self::MAX_ROWS) as $n => $r) {
            $r     = (array) $r;
            $where = 'Row ' . ($n + 1);
            $label = $text($r['label'] ?? '', self::MAX_LABEL, true, "rows.$n.label", $where . "'s label");
            if ($label !== '') {
                $where = '"' . mb_strimwidth($label, 0, 40, '…') . '"';
            }

            // A key only identifies a row to the tests and the stylesheet. A new
            // row gets one from its position; a duplicate is renumbered.
            $key = preg_match('/^[a-z0-9_]{1,40}$/', (string) ($r['key'] ?? '')) ? (string) $r['key'] : 'row_' . ($n + 1);
            if (isset($seen[$key])) {
                $key = 'row_' . ($n + 1);
            }
            $seen[$key] = true;

            $cells = [];
            foreach (Comparison::COLUMNS as $col) {
                $cell    = (array) ($r['cells'][$col] ?? []);
                $allowed = $col === 'local' ? Comparison::LOCAL_STATUSES : Comparison::OTHER_STATUSES;
                $status  = (string) ($cell['status'] ?? '');
                if (! array_key_exists($status, $allowed)) {
                    $errors["rows.$n.$col.status"] = $where . ': pick a status for ' . $columns[$col]['label'] . '.';
                }
                $cells[$col] = [
                    'status' => $status,
                    'note'   => $text($cell['note'] ?? '', self::MAX_NOTE, false, "rows.$n.$col.note", $where . ', the ' . $columns[$col]['label'] . ' note'),
                ];
            }

            $rows[] = ['key' => $key, 'label' => $label, 'cells' => $cells];
        }

        $checkedOn = trim((string) ($in['checkedOn'] ?? ''));
        $d         = \DateTimeImmutable::createFromFormat('!Y-m-d', $checkedOn);
        if ($d === false || $d->format('Y-m-d') !== $checkedOn) {
            $errors['checked_on'] = 'Enter the date the facts were checked, as YYYY-MM-DD.';
        }

        return ['table' => ['columns' => $columns, 'rows' => $rows, 'checkedOn' => $checkedOn], 'errors' => $errors];
    }
}
