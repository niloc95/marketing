<?php

namespace App\Libraries;

/**
 * The one outbound-mail path for the whole app.
 *
 * Extracted from DirectoryListingMutationService so the contact form could send
 * without copying the error handling — which is the part that matters here. The
 * behaviour and the reasoning below came from that class verbatim; see its
 * git history for the original post-mortem.
 */
class Mailer
{
    /**
     * Send one email, and make it obvious when that didn't work.
     *
     * Deliberately non-fatal: a broken mailer must not fail a signup or an edit
     * halfway through. But "don't fail" is not the same as "don't tell anyone",
     * and this used to be both.
     *
     * The bug worth remembering: the original called `$email->send(false)` under
     * a comment claiming the argument suppressed exceptions. It does not — that
     * parameter is CodeIgniter's $autoClear. Email::send() signals an SMTP
     * failure by *returning false*, not by throwing, so the catch below never
     * ran for the one failure mode that actually happens, and the error line it
     * logs was unreachable. Outbound mail could stop entirely and leave no trace
     * anywhere. Hence: check the return value, and record it somewhere a health
     * check can see (MailHealth).
     *
     * Letting $autoClear default to true also matters. service('email') is a
     * shared instance, so without the reset each send would inherit the previous
     * one's recipients.
     *
     * @param string $replyTo Optional Reply-To. Used by the contact form so a
     *                        reply reaches the visitor; the From address stays
     *                        ours either way, because putting a visitor-supplied
     *                        address in From fails SPF/DMARC at the recipient.
     *
     * @return bool Whether it went out. Callers that must not block on mail
     *              (signup, owner edit) can ignore it; the contact form uses it
     *              to tell the visitor the truth.
     */
    public function send(string $to, string $subject, string $body, string $replyTo = ''): bool
    {
        try {
            $email = service('email');
            $email->setTo($to);
            if ($replyTo !== '') {
                $email->setReplyTo($replyTo);
            }
            $email->setSubject($subject);
            $email->setMessage($body);
            $email->setMailType('html');

            if ($email->send()) {
                MailHealth::recordSuccess();

                return true;
            }

            // printDebugger() is where CI4 keeps the actual SMTP reason —
            // nothing else in the app reads it, so it would otherwise be lost.
            // The empty array matters: the default includes the full headers,
            // subject and body, which would put the recipient's address and the
            // message content into the log and into /health.
            $reason = $this->oneLine(strip_tags($email->printDebugger([])));
            log_message('error', 'Directory email to ' . $this->domainOf($to) . ' failed: ' . $reason);
            MailHealth::recordFailure($reason);
        } catch (\Throwable $e) {
            // Malformed Config\Email, or the cache being unavailable underneath
            // MailHealth. Still must not surface to the visitor.
            log_message('error', 'Directory email to ' . $this->domainOf($to) . ' threw: ' . $this->oneLine($e->getMessage()));

            try {
                MailHealth::recordFailure($e->getMessage());
            } catch (\Throwable) {
                // Nothing left to do — the log line above is the last resort.
            }
        }

        return false;
    }

    /**
     * The recipient's domain, for logging.
     *
     * Enough to diagnose — "every failure is to one provider" is a reputation
     * problem, "all of them" is an outage — without writing a subscriber's
     * address into a 0644 log file that gets copied into backups and support
     * threads.
     */
    private function domainOf(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? '(malformed address)' : '@' . substr($email, $at + 1);
    }

    /** Collapse newlines so a failure message can't forge extra log lines. */
    private function oneLine(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }
}
