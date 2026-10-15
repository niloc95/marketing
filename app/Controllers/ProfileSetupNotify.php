<?php

namespace App\Controllers;

use App\Libraries\PayFast;
use App\Services\ProfileSetupService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * PayFast's notification for a profile setup order: payfast/setup-notify.
 *
 * Separate from PayFastNotify, which handles subscriptions, so a once-off
 * payment can never be read as a subscription and the subscription path
 * stays exactly as it is. The checks are ProfileSetupService's, and they
 * are the same four PayFastNotify runs.
 *
 * As there: always 200 (PayFast retries anything else), CSRF and the
 * honeypot are exempt in Config\Filters, and the body is read raw because
 * the signature covers the fields in the order PayFast sent them. PayFast
 * posts multipart, which empties php://input, so the parsed body is the
 * fallback. See PayFastNotify::index() for the full story.
 */
class ProfileSetupNotify extends Controller
{
    public function index(): ResponseInterface
    {
        $raw = (string) file_get_contents('php://input');
        if (trim($raw) === '') {
            $post = $this->request->getPost();
            if (is_array($post) && $post !== []) {
                $raw = http_build_query($post);
            }
        }

        $outcome = (new ProfileSetupService())->handleNotification(
            $raw,
            (string) $this->request->getIPAddress(),
            new PayFast()
        );

        log_message(
            str_starts_with($outcome, 'rejected') ? 'warning' : 'info',
            'Profile setup ITN: ' . $outcome
        );

        return $this->response->setStatusCode(200)->setBody('');
    }
}
