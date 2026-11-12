<?php

namespace App\Services;

use App\Libraries\Mailer;
use App\Libraries\VerificationDocumentProcessor;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * "Email this business": a visitor writes to a business through the platform
 * and never sees its address (owner, 12 Nov 2026, against spam, scraping and
 * abuse).
 *
 * The business gets the message from WebScheduler Local with Reply-To set to
 * the visitor, so its reply goes straight to them and we drop out of the
 * conversation (the Jobs board relays applications the same way). The visitor
 * gets a receipt in fixed wording.
 *
 * The only writer of `xs_directory_listing_messages`. Nothing typed is stored:
 * one row per attempt with the sender and IP as sha256 hashes, for abuse
 * review, purged after RETENTION_DAYS.
 *
 * Whether the address is also shown on the profile is the owner's switch,
 * `email_public`; messages are relayed either way.
 *
 * A sent message counts as an email tap in the owner's statistics
 * (Directory::message() calls ListingStatsService::countTap()).
 *
 * Tests: tests/database/ListingMessageTest.php.
 */
class ListingMessageService
{
    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_BLOCKED = 'blocked';

    /** What the controller answers besides the three stored statuses. */
    public const THROTTLED = 'throttled';

    public const MIN_MESSAGE    = 20;
    public const MAX_MESSAGE    = 2000;
    public const RETENTION_DAYS = 90;

    /**
     * Attachments: PDF, photos or Word (.docx), 5 MB each, at most 3 and
     * 10 MB together. The total is what production takes in one request
     * (PHP post_max_size 12M, Apache LimitRequestBody 13 MB); raise those
     * before raising this. Never stored: attached from the upload's temp file.
     */
    public const MAX_FILES       = 3;
    public const MAX_FILE_BYTES  = 5 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 10 * 1024 * 1024;

    /** Links in a message before it reads as spam. */
    private const MAX_LINKS = 2;

    /** Per IP an hour, per sender a day, per business a day. */
    private const LIMIT_IP      = 5;
    private const LIMIT_SENDER  = 5;
    private const LIMIT_LISTING = 30;

    private const TABLE = 'directory_listing_messages';

    /**
     * Where a message for this profile (or one of its branches) goes, or ''
     * when there is nowhere to send it. A branch counts only on a premium
     * profile and only when it belongs to that profile, the same terms the
     * profile shows branches on.
     *
     * @param array<string,mixed>      $listing
     * @param array<string,mixed>|null $branch
     */
    public function recipient(array $listing, ?array $branch): string
    {
        if ($branch === null) {
            return trim((string) ($listing['email'] ?? ''));
        }
        if ((int) ($branch['listing_id'] ?? 0) !== (int) $listing['id'] || ! listing_has_premium($listing)) {
            return '';
        }

        return trim((string) ($branch['email'] ?? ''));
    }

    /**
     * Check what the visitor typed. Returns field => message.
     *
     * @param array{name:string,email:string,phone:string,message:string,consent:bool} $in
     *
     * @return array<string,string>
     */
    public function validate(array $in): array
    {
        $errors = [];
        if ($in['name'] === '') {
            $errors['name'] = 'Please tell the business your name.';
        }
        if (! filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'The business replies to this address, so it must work.';
        }
        if ($in['phone'] !== '' && preg_match_all('/\d/', $in['phone']) < 9) {
            $errors['phone'] = 'That phone number looks too short. You can leave it empty.';
        }
        $length = mb_strlen($in['message']);
        if ($length < self::MIN_MESSAGE) {
            $errors['message'] = 'Please write a little more (at least ' . self::MIN_MESSAGE . ' characters).';
        } elseif ($length > self::MAX_MESSAGE) {
            $errors['message'] = 'Please keep it under ' . self::MAX_MESSAGE . ' characters.';
        }
        if (! $in['consent']) {
            $errors['consent'] = 'Please agree to us passing your message and details to this business.';
        }

        return $errors;
    }

    /**
     * Check the visitor's files. Empty file inputs (nothing chosen) are
     * skipped. Returns the files ready for Mailer, or why they cannot go.
     *
     * @param list<UploadedFile> $uploads
     *
     * @return array{files:list<array{content:string,name:string,mime:string}>,error:string}
     */
    public function checkAttachments(array $uploads): array
    {
        $chosen = array_values(array_filter(
            $uploads,
            static fn ($f): bool => $f instanceof UploadedFile && $f->getError() !== UPLOAD_ERR_NO_FILE
        ));
        if ($chosen === []) {
            return ['files' => [], 'error' => ''];
        }
        if (count($chosen) > self::MAX_FILES) {
            return ['files' => [], 'error' => 'Please attach at most ' . self::MAX_FILES . ' files.'];
        }

        $checker = new VerificationDocumentProcessor(VerificationDocumentProcessor::MESSAGE_EXT);
        $files   = [];
        $total   = 0;
        foreach ($chosen as $file) {
            if (($problem = $checker->check($file, self::MAX_FILE_BYTES)) !== null) {
                return ['files' => [], 'error' => $problem];
            }
            $total += (int) $file->getSize();
            $content = @file_get_contents($file->getTempName());
            if ($content === false) {
                return ['files' => [], 'error' => 'We could not read “' . $file->getClientName() . '”. Please try again.'];
            }
            $files[] = [
                'content' => $content,
                'name'    => $this->fileName($file),
                'mime'    => strtolower(trim(explode(';', (string) $file->getMimeType())[0])),
            ];
        }
        if ($total > self::MAX_TOTAL_BYTES) {
            return ['files' => [], 'error' => 'Your files come to more than 10 MB together. Please send fewer or smaller files.'];
        }

        return ['files' => $files, 'error' => ''];
    }

    /**
     * Relay one checked message. Returns a STATUS_* constant or THROTTLED.
     * BLOCKED is answered to the visitor exactly like SENT, so a spammer
     * cannot tell which rule caught them.
     *
     * @param array<string,mixed>                                       $listing a published profile
     * @param array<string,mixed>|null                                  $branch
     * @param array{name:string,email:string,phone:string,message:string} $in
     * @param list<array{content:string,name:string,mime:string}>         $files from checkAttachments()
     */
    public function send(array $listing, ?array $branch, array $in, string $ip, array $files = []): string
    {
        $to = $this->recipient($listing, $branch);
        if ($to === '') {
            return self::STATUS_FAILED;
        }

        $sender    = hash('sha256', strtolower(trim($in['email'])));
        $ipHash    = hash('sha256', 'ip|' . $ip);
        $listingId = (int) $listing['id'];
        $branchId  = $branch === null ? null : (int) $branch['id'];

        $throttler = service('throttler');
        if ($throttler->check('listingmsg-ip-' . $ipHash, self::LIMIT_IP, HOUR) === false
            || $throttler->check('listingmsg-from-' . $sender, self::LIMIT_SENDER, DAY) === false
            || $throttler->check('listingmsg-to-' . $listingId, self::LIMIT_LISTING, DAY) === false) {
            return self::THROTTLED;
        }

        if (preg_match_all('#https?://|www\.#i', $in['message']) > self::MAX_LINKS) {
            $this->log($listingId, $branchId, $sender, $ipHash, self::STATUS_BLOCKED);

            return self::STATUS_BLOCKED;
        }

        $siteName = config('Directory')->siteName();
        $business = (string) ($listing['display_name'] ?? '');
        if ($branch !== null) {
            $business .= ', ' . $this->branchLabel($branch);
        }

        $body = view('emails/profile-message', [
            'site'     => $siteName,
            'heading'  => 'New message for ' . $business,
            'intro'    => $in['name'] . ' wrote to you from your business profile on ' . $siteName
                . '. Reply to this email to answer them directly.',
            'fields'   => array_filter([
                'Name'  => $in['name'],
                'Email' => $in['email'],
                'Phone' => $in['phone'],
            ]),
            'message'  => $in['message'],
            'files'    => array_column($files, 'name'),
            'profile'  => listing_url($listing),
            'footnote' => 'We passed this message on without showing the sender your email address, and we do not keep a copy. '
                . (service('site')->isApp()
                    ? 'The sender shared their details for this message only; please handle them in line with your data protection law.'
                    : 'The sender shared their details for this message only; please handle them in line with POPIA.'),
        ], ['saveData' => false]);

        $sent = (new Mailer())->send(
            $to,
            'Message from ' . $this->oneLine($in['name'], 80) . ' via ' . $siteName,
            $body,
            $in['email'],
            $files === [] ? [] : ['attachments' => $files]
        );
        $this->log($listingId, $branchId, $sender, $ipHash, $sent ? self::STATUS_SENT : self::STATUS_FAILED);
        if ($sent) {
            $this->receipt($listing, $in, count($files));
        }

        return $sent ? self::STATUS_SENT : self::STATUS_FAILED;
    }

    /**
     * The visitor's receipt. Fixed wording plus the profile's own name: nothing
     * typed is echoed back except a first name that is only letters, so the
     * form cannot be used to send a stranger text of someone else's choosing.
     *
     * @param array<string,mixed>                $listing
     * @param array{name:string,email:string} $in
     */
    private function receipt(array $listing, array $in, int $fileCount = 0): void
    {
        $first    = (string) strtok($in['name'], ' ');
        $siteName = config('Directory')->siteName();

        (new Mailer())->notice($in['email'], 'Your message has been sent', [
            'eyebrow'    => $siteName,
            'heading'    => 'Your message has been sent',
            'paragraphs' => [
                preg_match('/^\p{L}[\p{L}\']{0,29}$/u', $first) === 1 ? 'Hi ' . $first . ',' : 'Hello,',
                'We have passed your message on to ' . $listing['display_name']
                    . ($fileCount > 0 ? ', with ' . ($fileCount === 1 ? 'the file' : 'the ' . $fileCount . ' files') . ' you attached' : '') . '.',
                'The business replies to you directly by email. We do not keep a copy of your message or files.',
            ],
            'button'     => ['View the business profile', listing_url($listing)],
            'footnote'   => 'You are receiving this because this address was used to send a message on ' . $siteName
                . '. If that was not you, you can ignore this email.',
        ]);
    }

    /** Delete rows older than RETENTION_DAYS. Returns how many went. */
    public function purgeOld(): int
    {
        $this->db()->table(self::TABLE)
            ->where('created_at <', date('Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY))
            ->delete();

        return $this->db()->affectedRows();
    }

    private function log(int $listingId, ?int $branchId, string $sender, string $ipHash, string $status): void
    {
        try {
            $this->db()->table(self::TABLE)->insert([
                'listing_id'  => $listingId,
                'location_id' => $branchId,
                'sender_hash' => $sender,
                'ip_hash'     => $ipHash,
                'status'      => $status,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // A lost log row must never cost the visitor their message.
            log_message('error', 'ListingMessageService: log failed: ' . $e->getMessage());
        }
    }

    /**
     * The name the business sees: the visitor's own, cut to a plain basename
     * of letters, digits, spaces, dots, dashes and underscores, ending in the
     * checked extension.
     */
    private function fileName(UploadedFile $file): string
    {
        $ext  = strtolower((string) $file->getClientExtension());
        $base = pathinfo($file->getClientName(), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^\p{L}\p{N} ._-]+/u', '', $base), ' .');
        $base = mb_substr($base, 0, 80);

        return ($base !== '' ? $base : 'attachment') . '.' . $ext;
    }

    /** A branch by its name, else its suburb or city. */
    public function branchLabel(array $branch): string
    {
        foreach (['name', 'suburb', 'city'] as $field) {
            if (trim((string) ($branch[$field] ?? '')) !== '') {
                return trim((string) $branch[$field]);
            }
        }

        return 'branch';
    }

    private function oneLine(string $v, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/[\r\n\t]+/', ' ', $v)), 0, $max);
    }

    private function db(): \CodeIgniter\Database\BaseConnection
    {
        return db_connect();
    }
}
