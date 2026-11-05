<?php

namespace App\Services\Bookings\Integrations;

/**
 * Calls to Zoom, Google and webhook endpoints. Never throws: an answer of
 * 400 or more, a timeout or a refused connection all come back as a result
 * the caller records. Redirects are not followed (a webhook endpoint could
 * otherwise send us on to a private address after SafeUrl passed it).
 *
 * Uses the shared `curlrequest` service, so a test swaps it with
 * Services::injectMock('curlrequest', $fake) where $fake has request().
 */
final class Http
{
    /**
     * @param array<string,mixed> $options CURLRequest options: headers, body, json, form_params, auth
     *
     * @return array{status:int,body:string,json:array<string,mixed>|null,error:?string}
     */
    public static function request(string $method, string $url, array $options = [], int $timeout = 10): array
    {
        try {
            $response = service('curlrequest')->request($method, $url, $options + [
                'timeout'         => $timeout,
                'connect_timeout' => 5,
                'http_errors'     => false,
                'allow_redirects' => false,
            ]);
            $status = $response->getStatusCode();
            $body   = (string) $response->getBody();
            $json   = json_decode($body, true);

            return [
                'status' => $status,
                'body'   => $body,
                'json'   => is_array($json) ? $json : null,
                'error'  => $status >= 200 && $status < 300 ? null : 'The answer was ' . $status . '.',
            ];
        } catch (\Throwable $e) {
            return ['status' => 0, 'body' => '', 'json' => null, 'error' => 'No answer: ' . mb_substr($e->getMessage(), 0, 180)];
        }
    }
}
