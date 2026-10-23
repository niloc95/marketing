<?php

namespace App\Services;

use App\Libraries\Mailer;
use App\Libraries\PublicHolidays;
use App\Models\DirectoryListingModel;
use Throwable;

/**
 * Public holiday hours, the nightly half (directory:holidays:remind):
 *
 *  - refresh(): rolls each listing's holiday_open_dates forward a day, so the
 *    "open today" search filter keeps a year of dates ahead of it.
 *  - due() and remind(): emails owners who have not said what they do on
 *    public holidays, in the days before one.
 *
 * The reminder goes only to owners with marketing_opt_in set, the service
 * email preference ProfileNudgeService also honours, with the same stop link.
 * At most one every REMIND_EVERY_DAYS, never once answered, and only while a
 * holiday is REMIND_DAYS_BEFORE away or closer: a reminder in July about
 * Christmas would be noise. holiday_reminded_at is stamped only after Mailer
 * reports success, so a mail outage leaves the row due for the next night.
 */
class HolidayHoursService
{
    /** Start asking this many days before a holiday. */
    public const REMIND_DAYS_BEFORE = 10;

    /** Never ask the same owner more often than this. */
    public const REMIND_EVERY_DAYS = 90;

    private DirectoryListingModel $listings;
    private PublicHolidays $calendar;

    public function __construct(?PublicHolidays $calendar = null)
    {
        $this->listings = new DirectoryListingModel();
        $this->calendar = $calendar ?? new PublicHolidays();
        helper(['directory_hours', 'directory_ui']);
    }

    /**
     * Recompute holiday_open_dates for every answered listing from $today.
     * Written with the query builder, so updated_at is left alone: nothing an
     * owner did changed.
     */
    public function refresh(string $today): int
    {
        $db   = db_connect();
        $rows = $db->table('xs_directory_listings')
            ->select('id, trading_hours, holiday_open_dates')
            ->where('holiday_hours IS NOT NULL')
            ->where('deleted_at', null)
            ->get()->getResultArray();

        $changed = 0;
        foreach ($rows as $row) {
            $dates = hours_holiday_open_dates($row['trading_hours'], $today, $this->calendar);
            if ($dates !== $row['holiday_open_dates']) {
                $db->table('xs_directory_listings')->where('id', $row['id'])->update(['holiday_open_dates' => $dates]);
                $changed++;
            }
        }

        return $changed;
    }

    /** The holiday the reminders are about on $today, or null when none is close. */
    public function upcoming(string $today): ?array
    {
        $next = $this->calendar->next(date('Y-m-d', strtotime($today . ' +1 day')));

        return $next !== null && $next['date'] <= date('Y-m-d', strtotime($today . ' +' . self::REMIND_DAYS_BEFORE . ' days'))
            ? $next
            : null;
    }

    /**
     * Published, email confirmed, opted in, unanswered, not reminded lately,
     * not by appointment only (they set times per booking), and with an address.
     *
     * @return list<array<string,mixed>>
     */
    public function due(string $today, int $limit = 200): array
    {
        if ($this->upcoming($today) === null) {
            return [];
        }
        $since = date('Y-m-d H:i:s', strtotime($today . ' -' . self::REMIND_EVERY_DAYS . ' days'));

        return $this->listings
            ->where('status', 'published')
            ->where('is_verified', 1)
            ->where('holiday_hours', null)
            ->where('by_appointment', 0)
            ->where('marketing_opt_in', 1)
            ->where('email IS NOT NULL')
            ->where('email !=', '')
            ->groupStart()
                ->where('holiday_reminded_at', null)
                ->orWhere('holiday_reminded_at <', $since)
            ->groupEnd()
            ->orderBy('quality_score', 'DESC')
            ->limit(max(1, $limit))
            ->findAll();
    }

    /** Send one reminder. Returns 'sent' or 'failed'. */
    public function remind(array $listing, string $today): string
    {
        $holiday = $this->upcoming($today);
        if ($holiday === null) {
            return 'failed';
        }
        $id   = (int) $listing['id'];
        $soon = array_slice(array_filter(
            $this->calendar->between($holiday['date'], date('Y-m-d', strtotime($holiday['date'] . ' +30 days'))),
            static fn (array $d): bool => $d['date'] !== $holiday['date']
        ), 0, 4);

        $site = config('Directory')->siteName();
        $body = view('emails/holiday-hours', [
            'site'        => $site,
            'name'        => (string) $listing['display_name'],
            'holiday'     => PublicHolidays::label($holiday),
            'holidayDate' => date('l j F', strtotime($holiday['date'])),
            'upcoming'    => array_map(static fn (array $d): string => PublicHolidays::label($d) . ', ' . date('l j F', strtotime($d['date'])), array_values($soon)),
            'profileUrl'  => listing_site_url($listing, 'directory/' . $listing['slug']),
            'manageLink'  => $this->manageLink($id),
            'unsubscribe' => (new MarketingConsentService())->unsubscribeUrl($id),
        ], ['saveData' => false]);

        $sent = (new Mailer())->send(
            (string) $listing['email'],
            'Are you open on ' . PublicHolidays::label($holiday) . '? Tell your customers',
            $body
        );
        if (! $sent) {
            return 'failed';
        }
        db_connect()->table('xs_directory_listings')->where('id', $id)->update(['holiday_reminded_at' => date('Y-m-d H:i:s')]);

        return 'sent';
    }

    /** A live sign in link, as ProfileNudgeService::manageLink(). */
    private function manageLink(int $id): string
    {
        try {
            $token = (new DirectoryListingMutationService())
                ->mintManageToken($id, config('Directory')->approvalLinkTtl);

            return listing_site_url($id, 'manage/' . $token);
        } catch (Throwable $e) {
            log_message('error', 'Could not mint a holiday reminder link for listing {id}: {msg}', [
                'id'  => $id,
                'msg' => trim((string) preg_replace('/\s+/', ' ', $e->getMessage())),
            ]);

            return listing_site_url($id, 'manage');
        }
    }
}
