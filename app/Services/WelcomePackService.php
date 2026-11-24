<?php

namespace App\Services;

use App\Libraries\Mailer;
use App\Libraries\TokenHash;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\PublicBookingService;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use Config\Sites;
use Dompdf\Dompdf;
use Dompdf\Options;
use Throwable;

/**
 * The welcome pack (owner, 23 Nov 2026): a branded PDF for a Verified (co.za)
 * or Plus (.app) profile. A cover with a welcome letter, the profile in print
 * with a QR code, a QR poster and counter cards for the practice or business,
 * and a share kit.
 *
 * - **Paid only.** available() is the one rule: published, email confirmed,
 *   not a demo, and listing_has_premium(). Every way in (the emailed link,
 *   /business/welcome-pack, the automatic send) asks it. Admin may preview
 *   any published profile, never send to a free one.
 * - **Sent once, when the plan starts.** VerificationService calls
 *   sendIfDue() after the first activation (activated_at was empty), and
 *   Directory::verify() calls it for a profile that was paid for before its
 *   email was confirmed. welcome_pack_sent_at is the guard, claimed with a
 *   conditional UPDATE so two payment notifications cannot both send it.
 *   Renewals never resend it. Admin can resend (send()), which mints a new link.
 * - **Built on every download, never stored.** The PDF always matches the live
 *   profile, and there is no file to back up or purge. It holds only what the
 *   public profile shows: every row goes through listing_public_view() (hidden
 *   addresses), the email only when email_public, team and branches only on
 *   premium (profileFor() already does that).
 * - **The link** is /welcome-pack/{token}: the token's SHA-256 in
 *   welcome_pack_token (TokenHash), the raw value only in the email. It opens
 *   public facts, so it does not expire; a resend replaces it.
 * - **The site is the profile's, not the request's.** Admin on co.za can
 *   download an .app pack, so copy and links read $isApp and
 *   listing_site_url(), never site_copy() or base_url().
 *
 * This service is the only writer of welcome_pack_token and
 * welcome_pack_sent_at (query builder; neither is in the model's
 * allowedFields nor OWNER_EDITABLE). Tests: tests/database/WelcomePackTest.php.
 */
class WelcomePackService
{
    private DirectoryListingModel $listings;

    public function __construct(?DirectoryListingModel $listings = null)
    {
        $this->listings = $listings ?? new DirectoryListingModel();
    }

    /**
     * Whether this profile gets a welcome pack: published, email confirmed,
     * not a demo, and Verified or Plus right now.
     *
     * @param array<string,mixed> $listing
     */
    public function available(array $listing): bool
    {
        return ($listing['status'] ?? '') === 'published'
            && ! empty($listing['is_verified'])
            && ! listing_is_demo($listing)
            && trim((string) ($listing['email'] ?? '')) !== ''
            && listing_has_premium($listing);
    }

    /**
     * Email the pack if this profile has never had one and is due one now.
     * Never throws: a pack must never cost anyone a payment or a sign in.
     */
    public function sendIfDue(int $listingId): bool
    {
        try {
            $listing = $this->listings->find($listingId);
            if (! is_array($listing) || ! empty($listing['welcome_pack_sent_at']) || ! $this->available($listing)) {
                return false;
            }

            $token = TokenHash::mint();
            $db    = db_connect();
            $db->table('directory_listings')
                ->where('id', $listingId)
                ->where('welcome_pack_sent_at', null)
                ->update([
                    'welcome_pack_sent_at' => date('Y-m-d H:i:s'),
                    'welcome_pack_token'   => TokenHash::hash($token),
                ]);
            if ($db->affectedRows() !== 1) {
                return false; // another request claimed it first
            }

            if ($this->mail($listing, $token)) {
                return true;
            }

            // Not sent: release the claim so the next activation, or admin's
            // Send button, can try again.
            $db->table('directory_listings')->where('id', $listingId)
                ->update(['welcome_pack_sent_at' => null]);

            return false;
        } catch (Throwable $e) {
            log_message('error', 'Welcome pack for listing {id} not sent: {msg}', [
                'id'  => $listingId,
                'msg' => Mailer::oneLine($e->getMessage()),
            ]);

            return false;
        }
    }

    /**
     * Admin's Send button: a fresh link (the old one stops working) and the
     * email, whether or not one went before.
     *
     * @return array{ok:bool,message:string}
     */
    public function send(int $listingId): array
    {
        $listing = $this->listings->find($listingId);
        if (! is_array($listing)) {
            return ['ok' => false, 'message' => 'That profile no longer exists.'];
        }
        if (! $this->available($listing)) {
            return ['ok' => false, 'message' => 'The welcome pack is for published Verified and Plus profiles only.'];
        }

        $token = TokenHash::mint();
        db_connect()->table('directory_listings')->where('id', $listingId)->update([
            'welcome_pack_token' => TokenHash::hash($token),
        ]);

        if (! $this->mail($listing, $token)) {
            return ['ok' => false, 'message' => 'The email could not be sent. The old link no longer works; try again.'];
        }

        db_connect()->table('directory_listings')->where('id', $listingId)->update([
            'welcome_pack_sent_at' => date('Y-m-d H:i:s'),
        ]);

        return ['ok' => true, 'message' => 'Welcome pack emailed to the owner.'];
    }

    /**
     * The listing an emailed link belongs to, or null.
     *
     * @return array<string,mixed>|null
     */
    public function forToken(string $token): ?array
    {
        $token = trim($token);
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $row = $this->listings->where('welcome_pack_token', TokenHash::hash($token))->first();

        return is_array($row) ? $row : null;
    }

    /** What the downloaded file is called. */
    public function filename(array $listing): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($listing['slug'] ?? 'profile')));

        return 'webscheduler-local-welcome-pack-' . trim((string) $slug, '-') . '.pdf';
    }

    /** The PDF bytes. */
    public function pdf(array $listing): string
    {
        $options = new Options();
        // Everything is inlined as data: URIs below, so nothing is fetched:
        // no remote URLs and no files outside the app (a crafted image path
        // in a profile row could otherwise point dompdf at the disk).
        $options->setIsRemoteEnabled(false);
        $options->setChroot([FCPATH]);
        $options->setDefaultFont('DejaVu Sans');
        $options->setDefaultPaperSize('A4');
        $options->setTempDir(WRITEPATH . 'cache');
        $options->setFontCache(WRITEPATH . 'cache');

        $pdf = new Dompdf($options);
        $pdf->loadHtml($this->html($listing), 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        return (string) $pdf->output();
    }

    /**
     * The pack as HTML, the input to dompdf. Public so the tests can read
     * what the PDF says (its text is compressed in the file).
     *
     * @param array<string,mixed> $listing a listings row
     */
    public function html(array $listing): string
    {
        $profile = (new DirectoryService())->profileFor($listing);

        return hours_within($profile, function () use ($profile): string {
            $data = $this->viewData($profile);

            return view('welcome_pack/pack', $data, ['saveData' => false]);
        });
    }

    /**
     * @param array<string,mixed> $profile profileFor()
     * @return array<string,mixed>
     */
    private function viewData(array $profile): array
    {
        $isApp = service('site')->forRow($profile) === Sites::APP;

        $booking = PublicBookingService::profileView($profile);
        $bookUrl = $booking['book_url'] !== ''
            ? listing_site_url($profile, 'directory/' . $profile['slug'] . '/book')
            : '';
        $profile['_booking_services'] = $booking['services'];
        $profile['_practitioners']    = $booking['practitioners'];

        $l          = listing_public_view($profile);
        $profileUrl = listing_url($profile);
        $group      = $l['category']['group_name'] ?? null;
        $catSlug    = (string) ($l['category']['slug'] ?? '');

        return [
            'l'          => $l,
            'v'          => vertical_profile($group, $catSlug !== '' ? $catSlug : null, (string) ($l['type'] ?? '')),
            'isApp'      => $isApp,
            // The plan is the site's, not today's dates: an admin preview of a
            // Free or lapsed co.za profile still shows the Verified pack it
            // would get, and an .app row never says Verified.
            'verified'   => ! $isApp,
            'plus'       => $isApp,
            'noun'       => listing_noun($profile),
            'audience'   => listing_audience($profile),
            'profileUrl' => $profileUrl,
            'shortUrl'   => (string) preg_replace('#^https?://#i', '', $profileUrl),
            'bookUrl'    => $bookUrl,
            'shortBook'  => (string) preg_replace('#^https?://#i', '', $bookUrl),
            'ownerUrl'   => listing_site_url($profile, 'business'),
            'shortOwner' => (string) preg_replace('#^https?://#i', '', listing_site_url($profile, 'business')),
            'siteLabel'  => (string) preg_replace('#^https?://|/$#i', '', listing_site_url($profile, '')),
            'qrProfile'  => $this->qr($profileUrl),
            'qrBook'     => $bookUrl !== '' ? $this->qr($bookUrl) : '',
            'logo'       => $isApp ? '' : $this->image(FCPATH . 'assets/brand/logo-512.png', 'image/png'),
            'seal'       => ! $isApp ? $this->image(FCPATH . 'assets/verified-seal.svg', 'image/svg+xml') : '',
            'ownLogo'    => $this->ownImage($l['logo_path'] ?? null),
            'regulator'  => \App\Libraries\ProfessionalRegistration::regulatorFor($catSlug !== '' ? $catSlug : null, $group, \App\Libraries\ProfessionalRegistration::countryOf($profile)),
            'builtOn'    => date('j F Y'),
            'contact'    => $this->greetingName($profile),
        ];
    }

    /**
     * A QR code as an SVG data URI: vector, so it is sharp at any print size,
     * and light to render (a 1000 px PNG per code cost the test suite 28 MB).
     * connectPaths stays false: dompdf's SVG renderer drops modules when they
     * are joined into one path, and draws them all as separate shapes.
     * Error correction M keeps the code readable through a scuffed or glossy
     * print.
     */
    public function qr(string $url): string
    {
        $options = new QROptions([
            'outputType'           => QROutputInterface::MARKUP_SVG,
            'eccLevel'             => EccLevel::M,
            'outputBase64'         => true,
            'connectPaths'         => false,
            'drawLightModules'     => false,
            'svgUseFillAttributes' => true,
            'addQuietzone'         => true,
            'quietzoneSize'        => 2,
        ]);

        return (new QRCode($options))->render($url);
    }

    /** A file of ours as a data URI, or '' when it is missing. */
    private function image(string $path, string $mime): string
    {
        return is_file($path) ? 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path)) : '';
    }

    /**
     * The profile's own logo, only from inside public/, redrawn as a PNG
     * through GD (uploads are WebP, which dompdf cannot always draw, and a
     * redraw also drops anything that is not pixels). Capped at 600 px.
     */
    private function ownImage(?string $path): string
    {
        $path = ltrim(trim((string) $path), '/');
        if ($path === '' || str_contains($path, '..') || preg_match('#^https?://#i', $path) === 1) {
            return '';
        }
        $real = realpath(FCPATH . $path);
        if ($real === false || ! str_starts_with($real, (string) realpath(FCPATH) . DIRECTORY_SEPARATOR) || ! is_file($real)) {
            return '';
        }
        if (@getimagesize($real) === false) {
            return '';
        }
        $img = @imagecreatefromstring((string) file_get_contents($real));
        if ($img === false) {
            return '';
        }
        if (imagesx($img) > 600) {
            $scaled = imagescale($img, 600);
            if ($scaled !== false) {
                $img = $scaled;
            }
        }
        imagesavealpha($img, true);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();

        return $png === '' ? '' : 'data:image/png;base64,' . base64_encode($png);
    }

    /** "Hi Thandi Mokoena" from the contact person, else the profile's name. */
    private function greetingName(array $listing): string
    {
        $contact = trim((string) ($listing['contact_person'] ?? ''));

        return $contact !== '' ? $contact : (string) ($listing['display_name'] ?? '');
    }

    private function mail(array $listing, string $token): bool
    {
        $link = listing_site_url($listing, 'welcome-pack/' . $token);

        $body = view('emails/welcome-pack', [
            'name'     => (string) ($listing['display_name'] ?? ''),
            'contact'  => $this->greetingName($listing),
            'noun'     => listing_noun($listing),
            'audience' => listing_audience($listing),
            'link'     => $link,
            'owner'    => listing_site_url($listing, 'business/welcome-pack'),
            'plan'     => service('site')->forRow($listing) === Sites::APP ? 'Plus' : 'Verified',
        ], ['saveData' => false]);

        return (new Mailer())->send(
            (string) $listing['email'],
            'Your welcome pack: print your QR poster',
            $body
        );
    }
}
