<?php

namespace App\Filters;

use App\Services\FormHealthService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Sites;

/**
 * Records what every form POST in Config\Forms did, from the response the
 * controller produced: saved, refused (with the field keys), throttled, lost.
 * One place, so a form needs no code of its own to be tracked; a controller
 * only tags what the response cannot show (form_outcome()).
 *
 * A redirect into /index.php/ or off our hosts is recorded as lost on the
 * POST itself: that is the 14 to 27 Oct 2026 bug, where every failed signup
 * was sent to a URL that 404'd.
 *
 * After filters do not run when a before filter answered (CsrfGuard, BotTrap)
 * or for a 404 or an exception; those record themselves.
 */
class FormOutcome implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (! $request instanceof IncomingRequest || $request->getMethod() !== 'POST') {
            return null;
        }
        $key = config('Forms')->keyFor($request->getPath());
        if ($key === null) {
            return null;
        }

        [$outcome, $detail] = $this->classify($request, $response);
        (new FormHealthService())->record($key, $outcome, $detail);

        return null;
    }

    /** @return array{0:string,1:?string} */
    private function classify(IncomingRequest $request, ResponseInterface $response): array
    {
        $tag      = FormHealthService::takeTag();
        $location = $response->getHeaderLine('Location');
        if ($location !== '' && self::badRedirect($location, $request)) {
            return ['lost', 'bad_redirect ' . FormHealthService::cleanPath((string) parse_url($location, PHP_URL_PATH))];
        }

        $errors = flash('errors') ?? flash('review_errors');
        $fields = is_array($errors) && $errors !== [] ? self::fieldKeys($errors) : null;
        if ($tag !== null) {
            return [$tag, $fields];
        }

        $status = $response->getStatusCode();
        if ($status >= 500) {
            return ['exception', 'http_' . $status];
        }
        if ($status === 429) {
            return ['throttled', null];
        }
        if ($status === 401) {
            return ['expired', null];
        }
        if ($status >= 400) {
            return ['invalid', 'http_' . $status];
        }

        if ($fields !== null) {
            return ['invalid', $fields];
        }
        if (is_string(flash('error')) && flash('error') !== '') {
            return ['invalid', null];
        }

        $uploads = flash('upload_errors');

        return ['ok', is_array($uploads) && $uploads !== [] ? 'upload_errors' : null];
    }

    /**
     * Into /index.php/, or to a host that is neither this request's nor one
     * of our two sites (a signup abroad is handed to the other site on
     * purpose).
     */
    public static function badRedirect(string $location, IncomingRequest $request): bool
    {
        $path = (string) parse_url($location, PHP_URL_PATH);
        if (preg_match('#/index\.php(/|$)#', $path) === 1) {
            return true;
        }
        $host = strtolower((string) parse_url($location, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        $sites = config('Sites');

        return ! in_array($host, [strtolower($request->getUri()->getHost()), $sites->host(Sites::ZA), $sites->host(Sites::APP)], true);
    }

    /**
     * The error keys, nested ones flattened ("services.0.name" → "services"),
     * as a comma list. Keys only: an error message can quote what was typed.
     *
     * @param array<mixed> $errors
     */
    private static function fieldKeys(array $errors): string
    {
        $keys = [];
        foreach (array_keys($errors) as $k) {
            $k = (string) preg_replace('/[^a-z0-9_]/', '', strtolower((string) strtok((string) $k, '.[')));
            if ($k !== '' && ! is_numeric($k)) {
                $keys[$k] = true;
            }
        }

        return implode(',', array_slice(array_keys($keys), 0, 20));
    }
}
