<?php

namespace App\Controllers;

use App\Services\FormHealthService;

/**
 * POST /forms/event: what the browser saw of a form, sent by directory.js
 * with navigator.sendBeacon (FormHealthService):
 *
 *   - attempt:  submit pressed and not stopped, so it should arrive;
 *   - blocked:  the browser's required field check (or our own script)
 *               stopped the submit; detail is the field names;
 *   - js_error: a script error on a page with a form.
 *
 * The form is named by the path it posts to, mapped through Config\Forms
 * like the server side, so no view carries a key and nothing unknown is
 * stored. Field names only, never values.
 *
 * CSRF exempt like stats/tap (Config\Filters): a beacon cannot carry the
 * token, and a forged one adds a row only /admin/forms reads. Throttled per
 * IP, crawlers ignored, always 204.
 */
class FormEvents extends BaseController
{
    public function store()
    {
        $response = $this->response->setStatusCode(204);
        if (FormHealthService::isBot($this->request)
            || service('throttler')->check('form-beacon-' . md5((string) $this->request->getIPAddress()), 30, MINUTE) === false) {
            return $response;
        }

        $outcome = (string) $this->request->getPost('outcome');
        $action  = (string) parse_url((string) $this->request->getPost('action'), PHP_URL_PATH);
        $page    = (string) $this->request->getPost('page');
        $key     = config('Forms')->keyFor($this->formPath($action));
        if ($key === null || ! in_array($outcome, FormHealthService::BROWSER_OUTCOMES, true)) {
            return $response;
        }

        $detail = (string) $this->request->getPost('detail');
        if ($outcome === 'blocked') {
            $names  = array_filter(explode(',', $detail), static fn (string $n): bool => preg_match('/^[a-z0-9_]{1,60}$/', $n) === 1);
            $detail = implode(',', array_slice(array_values(array_unique($names)), 0, 20));
        } elseif ($outcome === 'js_error') {
            $detail = FormHealthService::clean($detail, 200);
        } else {
            $detail = '';
        }

        (new FormHealthService())->record($key, $outcome, $detail !== '' ? $detail : null, 'browser', $page);

        return $response;
    }

    /** The posted action's route path: no base path, no index.php. */
    private function formPath(string $path): string
    {
        $base = trim((string) parse_url(base_url('/'), PHP_URL_PATH), '/');
        $path = trim($path, '/');
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base) + 1);
        }

        return (string) preg_replace('#^index\.php/#', '', $path);
    }
}
