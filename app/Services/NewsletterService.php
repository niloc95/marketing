<?php

namespace App\Services;

use App\Libraries\Mailer;
use App\Libraries\TokenHash;
use App\Models\NewsletterSubscriberModel;
use Config\Sites;

/**
 * The newsletter list: signup, confirm, unsubscribe. The only writer of
 * xs_newsletter_subscribers. Replaces the Mautic forms 1 and 2.
 *
 * One list for all three signup forms (owner, 4 Nov 2026): both Local sites
 * and the brand site webscheduler.co.za, which posts its footer form here.
 * `source` keeps them apart for sending.
 *
 * Real double opt in. A signup only stores a pending row and emails a link;
 * the link opens a page with a Confirm button, and only pressing it
 * subscribes. Mail scanners open every link in a message, so a link that
 * subscribed on GET would confirm addresses nobody confirmed. Mautic did
 * exactly that wrong: it added people on submit, while the privacy pages
 * said "only when you click".
 *
 * Nothing here tells a visitor whether an address is already on the list:
 * every signup answers the same "check your inbox", and an address already
 * subscribed gets no email at all.
 */
class NewsletterService
{
    /** How long a confirm link works. */
    public const CONFIRM_TTL = 7 * DAY;

    /** A pending row nobody confirmed is deleted after this. */
    public const PENDING_RETENTION = 30 * DAY;

    public const CONSENT_CONFIRM = 'confirm';
    public const CONSENT_IMPORT  = 'mautic-import';

    private NewsletterSubscriberModel $subscribers;

    public function __construct()
    {
        $this->subscribers = new NewsletterSubscriberModel();
    }

    public static function normaliseEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function isValidEmail(string $email): bool
    {
        return $email !== '' && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Start a signup: store or refresh the pending row and email the confirm
     * link. Anyone can type anyone's address, so this never changes a
     * subscribed row and never sends it anything.
     *
     * @return string sent | already | failed | invalid
     */
    public function subscribe(string $email, string $source): string
    {
        $email = self::normaliseEmail($email);
        if (! self::isValidEmail($email) || ! in_array($source, NewsletterSubscriberModel::SOURCES, true)) {
            return 'invalid';
        }

        $this->pruneUnconfirmed();

        $row = $this->subscribers->where('email', $email)->first();
        if (is_array($row) && $row['status'] === NewsletterSubscriberModel::STATUS_SUBSCRIBED) {
            return 'already';
        }

        $token   = TokenHash::mint();
        $columns = [
            'source'             => $source,
            'confirm_token_hash' => TokenHash::hash($token),
            'confirm_expires'    => date('Y-m-d H:i:s', time() + self::CONFIRM_TTL),
            'confirm_sent_at'    => date('Y-m-d H:i:s'),
        ];

        // An unsubscribed row keeps its status (and so stays off every send)
        // until the person confirms again: asking is not consenting.
        if (is_array($row)) {
            $this->subscribers->update((int) $row['id'], $columns);
        } else {
            $this->subscribers->insert($columns + [
                'email'             => $email,
                'status'            => NewsletterSubscriberModel::STATUS_PENDING,
                'unsubscribe_token' => TokenHash::mint(),
            ]);
        }

        $siteKey = self::siteFor($source);
        $body    = view('emails/newsletter-confirm', [
            'brand'      => self::brandFor($source),
            'confirmUrl' => site_url_for($siteKey, 'newsletter/confirm/' . $token),
            'days'       => (int) (self::CONFIRM_TTL / DAY),
        ], ['saveData' => false]);

        $sent = (new Mailer())->send($email, 'Please confirm your ' . self::brandFor($source) . ' subscription', $body);

        return $sent ? 'sent' : 'failed';
    }

    /**
     * The row a confirm link belongs to, while it still works, or null.
     *
     * @return array<string,mixed>|null
     */
    public function findForConfirm(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $row = $this->subscribers->where('confirm_token_hash', TokenHash::hash($token))->first();
        if (! is_array($row) || strtotime((string) $row['confirm_expires']) < time()) {
            return null;
        }

        return $row;
    }

    /** The Confirm button. Returns the subscribed row, or null for a dead link. */
    public function confirm(string $token): ?array
    {
        $row = $this->findForConfirm($token);
        if ($row === null) {
            return null;
        }

        $this->subscribers->update((int) $row['id'], [
            'status'             => NewsletterSubscriberModel::STATUS_SUBSCRIBED,
            'consent_at'         => date('Y-m-d H:i:s'),
            'consent_source'     => self::CONSENT_CONFIRM,
            'unsubscribed_at'    => null,
            'confirm_token_hash' => null,
            'confirm_expires'    => null,
        ]);

        return $this->subscribers->find((int) $row['id']);
    }

    /**
     * The row an unsubscribe link belongs to, or null.
     *
     * @return array<string,mixed>|null
     */
    public function findByUnsubscribeToken(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $row = $this->subscribers->where('unsubscribe_token', $token)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Take an address off the list for good. Also kills an open confirm link,
     * so a pending signup can be refused from the same page.
     */
    public function unsubscribe(string $token): ?array
    {
        $row = $this->findByUnsubscribeToken($token);
        if ($row === null) {
            return null;
        }

        if ($row['status'] !== NewsletterSubscriberModel::STATUS_UNSUBSCRIBED) {
            $this->subscribers->update((int) $row['id'], [
                'status'             => NewsletterSubscriberModel::STATUS_UNSUBSCRIBED,
                'unsubscribed_at'    => date('Y-m-d H:i:s'),
                'confirm_token_hash' => null,
                'confirm_expires'    => null,
            ]);
        }

        return $this->subscribers->find((int) $row['id']);
    }

    /** The unsubscribe link for a row, on the site that row belongs to. */
    public function unsubscribeUrl(array $row): string
    {
        return site_url_for(self::siteFor((string) $row['source']), 'newsletter/unsubscribe/' . $row['unsubscribe_token']);
    }

    /**
     * Bring one Mautic contact across. A confirmed subscriber keeps the date
     * Mautic holds; a do not contact entry becomes an unsubscribed row and
     * beats everything, so nobody who left the Mautic list is ever back on
     * this one without confirming again.
     *
     * @return string added | unsubscribed | skipped
     */
    public function importFromMautic(string $email, string $source, bool $doNotContact, string $since): string
    {
        $email = self::normaliseEmail($email);
        if (! self::isValidEmail($email) || ! in_array($source, NewsletterSubscriberModel::SOURCES, true)) {
            return 'skipped';
        }
        $since = strtotime($since) !== false ? date('Y-m-d H:i:s', strtotime($since)) : date('Y-m-d H:i:s');
        $row   = $this->subscribers->where('email', $email)->first();

        if ($doNotContact) {
            $columns = [
                'status' => NewsletterSubscriberModel::STATUS_UNSUBSCRIBED, 'unsubscribed_at' => $since,
                'confirm_token_hash' => null, 'confirm_expires' => null,
            ];
            if (is_array($row)) {
                $this->subscribers->update((int) $row['id'], $columns);
            } else {
                $this->subscribers->insert($columns + ['email' => $email, 'source' => $source, 'unsubscribe_token' => TokenHash::mint()]);
            }

            return 'unsubscribed';
        }

        if (is_array($row)) {
            return 'skipped';
        }

        $this->subscribers->insert([
            'email' => $email, 'source' => $source, 'status' => NewsletterSubscriberModel::STATUS_SUBSCRIBED,
            'consent_at' => $since, 'consent_source' => self::CONSENT_IMPORT, 'unsubscribe_token' => TokenHash::mint(),
        ]);

        return 'added';
    }

    /** Pending rows nobody confirmed in time. Run on every signup; cheap. */
    public function pruneUnconfirmed(): int
    {
        $this->subscribers
            ->where('status', NewsletterSubscriberModel::STATUS_PENDING)
            ->where('created_at <', date('Y-m-d H:i:s', time() - self::PENDING_RETENTION))
            ->delete();

        return $this->subscribers->db->affectedRows();
    }

    /** The Local site whose pages serve a row: the brand site's signups land on co.za. */
    public static function siteFor(string $source): string
    {
        return $source === NewsletterSubscriberModel::SOURCE_APP ? Sites::APP : Sites::ZA;
    }

    /** The name a row signed up to: the brand site's form is WebScheduler's, not Local's. */
    public static function brandFor(string $source): string
    {
        return $source === NewsletterSubscriberModel::SOURCE_BRAND ? 'WebScheduler' : config('Directory')->siteName();
    }
}
