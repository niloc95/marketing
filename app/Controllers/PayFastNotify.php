<?php

namespace App\Controllers;

use App\Libraries\PayFast;
use App\Models\DirectoryVerificationItnRejectionModel;
use App\Models\DirectoryVerificationModel;
use App\Services\VerificationService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * PayFast Instant Transaction Notification — the server-to-server callback that
 * says a Verified Business subscription has been paid.
 *
 * This is the only unauthenticated endpoint in the app that grants anything, so
 * it is worth being explicit about the shape of the problem. A POST here that
 * we believe hands out a paid badge for free. There is no session, no CSRF
 * token and no user: the only thing separating a real notification from a
 * forged one is what we check.
 *
 * So: four independent checks, and a request must pass all four. Signature
 * proves the sender knows the passphrase. Source IP proves it came from a
 * PayFast machine. The validation POST-back proves PayFast agrees it sent this,
 * over a connection we opened. The amount check proves it is the payment we
 * asked for and not a one-cent one. Any single check could be defeated or could
 * fail wrongly; all four together is a high bar.
 *
 * Two things that look like bugs and are not:
 *
 *  1. Every outcome returns HTTP 200, including the rejections. PayFast retries
 *     on anything else, so a 4xx for a forged notification would earn us an
 *     endless retry loop for a request we have already decided about. The
 *     response body is empty and says nothing; the log is where the reason goes.
 *
 *  2. The request body is read raw and parsed by hand rather than through
 *     $this->request->getPost(). The signature covers the fields in the order
 *     PayFast sent them, and an associative array's order is not something to
 *     stake a payment on.
 *
 * CSRF and the honeypot filter are both disabled for this route in
 * Config/Filters.php. They have to be — there is no browser here to carry a
 * token — and that exemption is exactly why the four checks above exist.
 */
class PayFastNotify extends Controller
{
    /**
     * Where the notification body was read from, carried into the log line so
     * that "which path did this take" is answerable after the fact rather than
     * by re-deriving it from content types.
     */
    private string $bodySource = 'php://input';

    public function index(): ResponseInterface
    {
        $payfast = new PayFast();

        // Nothing to do if the feature was never switched on. Answering 200
        // keeps a stray notification from retrying forever.
        if (! $payfast->isConfigured()) {
            return $this->done('PayFast is not configured; notification ignored.', level: 'info');
        }

        // Read the raw stream first, because that is what PayFast signed and
        // what check 3 has to echo back verbatim.
        //
        // But it cannot be the only source. PayFast posts notifications as
        // multipart/form-data, and PHP makes php://input unavailable for that
        // content type — it consumes the body into $_POST and leaves the stream
        // empty. Reading only the stream therefore saw every notification as
        // empty, logged it at a level production discards, and answered 200. The
        // result was silent and total: PayFast recorded "Success" for every
        // delivery, and not one ITN in this deployment's history was ever
        // processed. Falling back to the parsed body is what makes the endpoint
        // work at all.
        //
        // The round-trip is faithful: PHP preserves the order the fields arrived
        // in, parseNotification() urldecodes what we re-encode here, and the
        // signature is computed over decoded values.
        $raw = (string) file_get_contents('php://input');

        if (trim($raw) === '') {
            $post = $this->request->getPost();
            if (is_array($post) && $post !== []) {
                $raw               = http_build_query($post);
                $this->bodySource = 'parsed-body';
            }
        }

        if (trim($raw) === '') {
            // A body that was declared and then arrived empty is an anomaly
            // worth seeing. No body at all is a bot poking a public endpoint,
            // and would only fill the log.
            $declared = (int) ($this->request->getServer('CONTENT_LENGTH') ?? 0);

            return $declared > 0
                ? $this->done(sprintf('Notification unreadable: %d bytes declared, none readable.', $declared))
                : $this->done('Empty notification body.', level: 'info');
        }

        $fields = $payfast->parseNotification($raw);
        $ip     = (string) $this->request->getIPAddress();

        // --- Check 1: signature ------------------------------------------------
        if (! $payfast->verifySignature($fields)) {
            return $this->done('Notification rejected: bad signature.', $fields, $ip);
        }

        // --- Check 2: source address -------------------------------------------
        if (! $payfast->isValidSourceIp($ip)) {
            return $this->done('Notification rejected: source address is not PayFast.', $fields, $ip);
        }

        // --- Check 3: PayFast confirms it sent this ----------------------------
        if (! $payfast->validateWithPayFast($raw)) {
            // Production refuses, always — this is the strongest of the four.
            //
            // The sandbox is the exception, and only the sandbox: its validate
            // endpoint answers INVALID for notifications it did itself send, so
            // enforcing this there makes an end-to-end test of the badge
            // impossible while proving nothing about the live path. Gated on
            // isSandbox() so a correctly configured production box can never
            // reach it, and loud when it runs.
            if (! $payfast->isSandbox()) {
                return $this->done('Notification rejected: PayFast did not confirm it.', $fields, $ip);
            }

            log_message('warning', 'PayFast ITN: sandbox POST-back validation failed; continuing '
                . 'because directory.payfastSandbox is on. This must never run in production.');
        }

        // Which subscription is this? m_payment_id is ours and is what we look
        // up on; custom_str2 carries the same id and is the fallback for the
        // rare notification that arrives without it.
        $verifications = new DirectoryVerificationModel();
        $verification  = $verifications->findByPaymentId((string) ($fields['m_payment_id'] ?? ''));

        if ($verification === null && ! empty($fields['custom_str2'])) {
            $row          = $verifications->find((int) $fields['custom_str2']);
            $verification = is_array($row) ? $row : null;
        }

        if ($verification === null) {
            return $this->done('Notification rejected: no matching verification.', $fields, $ip);
        }

        // --- Check 4: the amount we asked for ----------------------------------
        $amountGross = isset($fields['amount_gross']) ? (float) $fields['amount_gross'] : null;
        $status      = strtoupper(trim((string) ($fields['payment_status'] ?? '')));

        // Only a completed payment has to match an amount. A cancellation
        // carries no money and would fail a comparison that means nothing.
        if ($status === 'COMPLETE' && ! $payfast->amountMatches($amountGross, (string) $verification['amount'])) {
            return $this->done(sprintf(
                'Notification rejected: amount %s does not match the expected %s.',
                $amountGross === null ? 'missing' : (string) $amountGross,
                (string) $verification['amount']
            ), $fields, $ip, 'warning', (int) $verification['id']);
        }

        $outcome = (new VerificationService())->recordPayment(
            $verification,
            (string) ($fields['pf_payment_id'] ?? ''),
            $status,
            $amountGross,
            $fields
        );

        return $this->done(sprintf(
            'Notification %s for verification %d (%s).',
            $outcome,
            (int) $verification['id'],
            $status
        ), $fields, $ip, 'info');
    }

    /**
     * Log the outcome and answer 200.
     *
     * The log line names the payment, never the payer: pf_payment_id and
     * m_payment_id are ours to correlate with, while the email address, name and
     * signature in the notification are not things that need to sit in a log
     * file to make an incident debuggable. Same discipline Mailer applies to
     * recipient addresses.
     *
     * Rejections log at 'warning' and everything else at 'info', because
     * production runs at threshold 5 (see Config\Logger) — at 'info' the reason
     * a notification was refused is written nowhere and the failure is
     * invisible. That matters more here than anywhere else in the app: we
     * always answer 200, so PayFast's dashboard reports "Success" for a
     * notification we threw away, and the only other symptom is a badge that
     * quietly never activates.
     *
     * A refused notification is either an attack or a misconfiguration costing
     * money, which is what 'warning' is for. The benign cases — an empty body
     * from a bot probing the endpoint, a notification arriving before the
     * feature is switched on, and the successful path, whose full payload is
     * already stored in verification_events — stay at 'info' so the production
     * log does not fill with noise.
     *
     * @param array<string,string> $fields
     */
    private function done(
        string $message,
        array $fields = [],
        string $ip = '',
        string $level = 'warning',
        ?int $verificationId = null
    ): ResponseInterface {
        $context = [];
        if (isset($fields['pf_payment_id'])) {
            $context[] = 'pf_payment_id=' . $fields['pf_payment_id'];
        }
        if (isset($fields['m_payment_id'])) {
            $context[] = 'm_payment_id=' . $fields['m_payment_id'];
        }
        if ($ip !== '') {
            $context[] = 'from=' . $ip;
        }
        $context[] = 'body=' . $this->bodySource;

        log_message(
            $level,
            'PayFast ITN: ' . $message . ($context === [] ? '' : ' [' . implode(' ', $context) . ']')
        );

        // A refusal — and only a refusal — is also written down, using the same
        // severity split that decides what gets logged, so there is one
        // definition of "we turned money away" rather than two that can drift.
        // record() swallows its own failures: see the model.
        if ($level === 'warning') {
            (new DirectoryVerificationItnRejectionModel())->record($message, $fields, $ip, $verificationId);
        }

        return $this->response->setStatusCode(200)->setBody('');
    }
}
