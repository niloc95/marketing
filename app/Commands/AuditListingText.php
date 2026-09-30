<?php

namespace App\Commands;

use App\Libraries\ListingText;
use App\Models\DirectoryListingModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * List listings whose name or description breaks the rules new signups are
 * now held to (see ListingText):
 *
 *   php spark directory:audit-text
 *   php spark directory:audit-text --all      # include unpublished/pending
 *
 * Report only. It writes nothing: a name that breaks the rules may be spam to
 * unpublish or a real business to rename, and that is a judgement for the
 * admin edit page, not a script. Validation only catches what is typed from
 * now on, and an unchanged stored name is never re-judged on an owner edit,
 * so this is how the listings from before the rules get found.
 */
class AuditListingText extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'directory:audit-text';
    protected $description = 'Report listings whose name or description breaks the signup text rules.';
    protected $usage       = 'directory:audit-text [--all]';
    protected $options     = [
        '--all' => 'Include listings that are not published.',
    ];

    public function run(array $params): int
    {
        $model = new DirectoryListingModel();
        $query = $model->select('id, slug, status, display_name, description_text');
        if (CLI::getOption('all') === null) {
            $query->where('status', 'published');
        }

        $found = 0;
        foreach ($query->findAll() as $row) {
            $name     = (string) $row['display_name'];
            $problems = [];

            if (($p = ListingText::nameProblem($name)) !== null) {
                $problems[] = 'name: ' . $p;
            } elseif (mb_strlen($name) > ListingText::NAME_MAX) {
                $problems[] = 'name: longer than ' . ListingText::NAME_MAX . ' characters';
            }
            if (ListingText::isShouting($name, 0.7, 6)) {
                $problems[] = 'name: in capitals';
            }
            if (ListingText::isShouting((string) $row['description_text'], 0.5, 20)) {
                $problems[] = 'description: in capitals';
            }
            if (trim((string) $row['description_text']) === '') {
                $problems[] = 'description: empty';
            }

            if ($problems === []) {
                continue;
            }
            $found++;
            CLI::write(sprintf('#%d [%s] %s', $row['id'], $row['status'], mb_strimwidth($name, 0, 90, '…')), 'yellow');
            CLI::write('    ' . base_url('admin/edit/' . $row['id']));
            foreach ($problems as $problem) {
                CLI::write('    - ' . $problem);
            }
        }

        CLI::write($found === 0 ? 'Nothing to report.' : sprintf('%d listing(s) to review.', $found), $found === 0 ? 'green' : 'white');

        return EXIT_SUCCESS;
    }
}
