<?php

namespace App\Commands;

use App\Models\DirectoryCategoryModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Give the imported doctors that came in with no category the generic
 * "Medical Doctor" one (owner, 16 Nov 2026; docs/plans/2026-11-medical-focus.md
 * phase 2).
 *
 *   php spark directory:recategorise-imports --report plan.csv            (dry run)
 *   php spark directory:recategorise-imports --report done.csv --apply    (writes)
 *
 * 253 imported medical profiles on production had no category: the source
 * CSVs (/home/deploy/imports) have a blank specialty for them. With no
 * category they read "business" and "customers" (listing_noun(),
 * listing_audience()) and sit outside every medical page.
 *
 * The rule, deliberately narrow:
 *  - signup_source import-doctors or import-lenmed, no category, not deleted;
 *  - a person titled "Dr" or "Prof" (the title column, or a display name
 *    that starts with either; in a doctors import a professor is a medical
 *    one) goes to medical-doctor, which claims no specialty;
 *  - everyone else (Mr, Ms, allied disciplines such as
 *    orthotists, and units such as "Dialysis Unit") is left alone and listed
 *    as "review" for admin to file by hand on /admin.
 *
 * A dry run unless --apply is given. Writes category_id and updated_at only.
 * The nightly rescore (directory:quality:recalculate --stale) picks up the
 * change. Run on production only with the owner's OK.
 */
class RecategoriseImports extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'directory:recategorise-imports';
    protected $description = 'File uncategorised imported doctors under Medical Doctor (dry run unless --apply).';
    protected $usage       = 'directory:recategorise-imports [--report <csv>] [--apply]';
    protected $options     = [
        '--report' => 'Write one line per profile: what happens to it and why.',
        '--apply'  => 'Write the change. Without it nothing is written.',
    ];

    public const SOURCES  = ['import-doctors', 'import-lenmed'];
    public const CATEGORY = 'medical-doctor';

    public function run(array $params): int
    {
        // $params as well as CLI::getOption(), so command() in a test passes them too.
        $apply  = array_key_exists('apply', $params) || CLI::getOption('apply');
        $report = (string) ($params['report'] ?? CLI::getOption('report') ?? '');

        $category = (new DirectoryCategoryModel())->where('slug', self::CATEGORY)->first();
        if (! is_array($category)) {
            CLI::error('No "' . self::CATEGORY . '" category. Run: php spark db:seed DirectoryCategoriesSeeder');

            return EXIT_ERROR;
        }
        $categoryId = (int) $category['id'];

        $db   = db_connect();
        $rows = $db->table('xs_directory_listings')
            ->select('id, display_name, title, type, signup_source, status')
            ->whereIn('signup_source', self::SOURCES)
            ->where('category_id', null)
            ->where('deleted_at', null)
            ->orderBy('id')
            ->get()->getResultArray();

        $lines = [];
        $ids   = [];
        foreach ($rows as $r) {
            $doctor  = self::isDoctor($r);
            $lines[] = $r + [
                'result' => $doctor ? self::CATEGORY : 'review',
                'reason' => $doctor ? 'titled Dr or Prof, specialty unknown' : 'not titled Dr or Prof: admin files it by hand',
            ];
            if ($doctor) {
                $ids[] = (int) $r['id'];
            }
        }

        if ($apply && $ids !== []) {
            $db->table('xs_directory_listings')
                ->whereIn('id', $ids)
                ->where('category_id', null)
                ->update(['category_id' => $categoryId, 'updated_at' => date('Y-m-d H:i:s')]);
        }

        if ($report !== '') {
            $this->writeReport($report, $lines);
        }

        CLI::write(sprintf(
            '%s: %d uncategorised imports, %d to %s, %d left for review.',
            $apply ? 'Applied' : 'Dry run',
            count($rows),
            count($ids),
            self::CATEGORY,
            count($rows) - count($ids),
        ));

        return EXIT_SUCCESS;
    }

    /** @param array<string,mixed> $row */
    public static function isDoctor(array $row): bool
    {
        $title = strtolower(trim((string) ($row['title'] ?? '')));
        if (in_array($title, ['dr', 'dr.', 'prof', 'prof.'], true)) {
            return true;
        }

        return ($row['type'] ?? '') === 'person'
            && preg_match('/^(dr|prof)\.?\s/i', trim((string) ($row['display_name'] ?? ''))) === 1;
    }

    /** @param list<array<string,mixed>> $lines */
    private function writeReport(string $path, array $lines): void
    {
        $cols = ['id', 'display_name', 'title', 'type', 'signup_source', 'status', 'result', 'reason'];
        $fh   = fopen($path, 'wb');
        fputcsv($fh, $cols, ',', '"', '');
        foreach ($lines as $l) {
            fputcsv($fh, array_map(static fn ($c) => (string) ($l[$c] ?? ''), $cols), ',', '"', '');
        }
        fclose($fh);
    }
}
