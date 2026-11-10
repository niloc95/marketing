<?php

namespace App\Controllers;

use App\Services\SystemHandbookService;
use Throwable;

/**
 * /admin/documents/system: the system handbook. Everything about Local,
 * Bookings, both sites and the brand site on one page: written chapters
 * (Config\Handbook, app/Views/admin/handbook/) beside live figures
 * (SystemHandbookService). Behind the admin filter with the rest of /admin.
 *
 * Each live section is read on its own, so one that fails shows as
 * unavailable and the rest of the page still renders.
 */
class AdminHandbook extends BaseController
{
    public function index()
    {
        $live   = new SystemHandbookService();
        $failed = [];
        $read   = static function (string $name, callable $fn) use (&$failed) {
            try {
                return $fn();
            } catch (Throwable $e) {
                log_message('error', 'Handbook section {name} failed: {message}', ['name' => $name, 'message' => $e->getMessage()]);
                $failed[] = $name;

                return null;
            }
        };

        $tables = $read('tables', static fn () => $live->tables());

        return $this->response
            ->setHeader('Cache-Control', 'no-store')
            ->setBody(view('admin/handbook', [
                'chapters'   => config('Handbook')->chapters,
                'tables'     => $tables,
                'siteCounts' => $tables === null ? null : $read('siteCounts', static fn () => $live->siteCounts($tables)),
                'breakdowns' => $read('breakdowns', static fn () => $live->breakdowns()),
                'migrations' => $read('migrations', static fn () => $live->migrations()),
                'routes'     => $read('routes', static fn () => $live->routes()),
                'stack'      => $read('stack', static fn () => $live->stack()),
                'schedule'   => $read('schedule', static fn () => $live->schedule()),
                'tokens'     => $read('tokens', static fn () => $live->tokens()),
                'ids'        => $read('ids', static fn () => $live->ids()),
                'failed'     => $failed,
                'generated'  => date('j M Y, H:i') . ' UTC',
            ]));
    }
}
