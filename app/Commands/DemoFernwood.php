<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Fernwood Dental Studio: the demo business profile (owner, 12 Nov 2026), a
 * made up family dental practice that shows what a Verified profile with a
 * branch, a team and online booking looks like, for the brand site's
 * screenshots and for showing prospects.
 *
 *   php spark demo:fernwood                     # create, or rebuild in place
 *   php spark demo:fernwood --sample-bookings   # plus fictional appointments (never in production)
 *   php spark demo:fernwood --owner             # plus a sign in for /business (prints a new password)
 *   php spark demo:fernwood --remove            # delete it and everything under it
 *
 * Everything is fictional. It is is_demo = 1 (see the AddIsDemoToListings
 * migration): out of discovery, noindex, a demo banner, and Book::store()
 * never books. It has no email, phone or WhatsApp, so no call, message or
 * notice can reach a real person, and reviews are off. The photos are our
 * own licensed stock from public/assets/categories.
 */
class DemoFernwood extends BaseCommand
{
    public const SLUG = 'fernwood-dental-studio';

    /** The owner sign in, on a reserved domain that can never receive mail. */
    public const OWNER_EMAIL = 'lindiwe@fernwood-demo.example';

    protected $group       = 'Directory';
    protected $name        = 'demo:fernwood';
    protected $description = 'Create, rebuild or remove the Fernwood Dental Studio demo profile.';
    protected $usage       = 'demo:fernwood [--sample-bookings] [--owner] [--remove]';
    protected $options     = [
        '--sample-bookings' => 'Add fictional customers and appointments for this fortnight (refused in production).',
        '--owner'           => 'Create a /business sign in for the demo and print its password.',
        '--remove'          => 'Delete the demo and everything under it.',
    ];

    private const PEOPLE = [
        ['Dr Lindiwe Khumalo', 'Dentist and practice owner', 'BDS (Wits)', 'Family dentistry, Cosmetic dentistry, Teeth whitening', 'Lindiwe started Fernwood Dental Studio to give families one place for every kind of dental care. She is known for calm, unhurried appointments.', 'ocean'],
        ['Dr James Fourie', 'Dentist', 'BChD (Pretoria)', 'Fillings, Root canal treatment, Emergency dentistry', 'James sees most of our same day emergencies and looks after patients at our Rosebank rooms on Fridays.', 'teal'],
        ['Sarah Naidoo', 'Oral hygienist', 'BOH (UKZN)', 'Scale and polish, Gum health, Children', 'Sarah does our cleaning appointments and loves helping nervous children feel at home in the chair.', 'violet'],
        ['Zanele Dube', 'Practice manager', '', 'Medical aid claims, Appointments', 'Zanele runs the front desk and can answer any question about your medical aid.', null],
    ];

    /** name, minutes, price in cents (null = label only), profile price label */
    private const SERVICES = [
        ['Check up and clean', 45, 65000, 'R650'],
        ['Teeth whitening', 60, 250000, 'R2 500'],
        ['Fillings', 45, null, 'Medical aid rates'],
        ["Children's check up", 30, 45000, 'R450'],
        ['Emergency appointment', 30, null, 'On request'],
        ['Root canal consultation', 30, 55000, 'R550'],
    ];

    /** person index => service indexes */
    private const OFFERS = [0 => [0, 1, 2, 3, 5], 1 => [0, 2, 4, 5], 2 => [0, 3]];

    private const PHOTOS = ['dentist-1600.webp', 'group-health-medical-1600.webp', 'group-health-medical-2-1600.webp', 'general-practitioner-1600.webp'];

    public function run(array $params): int
    {
        $db  = db_connect();
        $opt = static fn (string $k): bool => array_key_exists($k, $params) || CLI::getOption($k) !== null;

        $old = $db->table('directory_listings')->select('id, is_demo')->where('slug', self::SLUG)->get()->getRowArray();
        if ($old !== null && (int) $old['is_demo'] !== 1) {
            CLI::error('Profile ' . $old['id'] . ' has the slug ' . self::SLUG . ' but is not a demo. Nothing was changed.');

            return EXIT_ERROR;
        }
        if ($old !== null) {
            $this->remove($db, (int) $old['id']);
            CLI::write('Removed the old demo (profile ' . $old['id'] . ').');
        }
        if ($opt('remove')) {
            return EXIT_SUCCESS;
        }
        if ($opt('sample-bookings') && ENVIRONMENT === 'production') {
            CLI::error('Sample appointments are for local dev only.');

            return EXIT_ERROR;
        }

        $db->transStart();
        $id = $this->build($db, $opt('sample-bookings'));
        $password = $opt('owner') ? $this->owner($db, $id) : null;
        $db->transComplete();
        if (! $db->transStatus()) {
            CLI::error('Nothing was saved: the demo could not be built.');

            return EXIT_ERROR;
        }

        cache()->clean();
        CLI::write('Fernwood Dental Studio is profile ' . $id . ': ' . listing_url(['slug' => self::SLUG, 'country_code' => 'ZA']), 'green');
        if ($password !== null) {
            CLI::write('Sign in at /business as ' . self::OWNER_EMAIL . ' with password: ' . $password);
        }
        CLI::write('Run `php spark directory:quality:recalculate --stale` to score it.');

        return EXIT_SUCCESS;
    }

    private function remove($db, int $id): void
    {
        $tables = $db->query("SELECT table_name AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'listing_id'")->getResultArray();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $t) {
            $db->query('DELETE FROM `' . $t['t'] . '` WHERE listing_id = ?', [$id]);
        }
        $db->query('DELETE FROM xs_directory_listings WHERE id = ? AND is_demo = 1', [$id]);
        $db->query('DELETE FROM xs_accounts WHERE email = ?', [self::OWNER_EMAIL]);
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        foreach (glob(FCPATH . 'assets/listings/gallery/demo_fernwood_*.webp') ?: [] as $f) {
            @unlink($f);
        }
    }

    private function build($db, bool $sample): int
    {
        $now   = gmdate('Y-m-d H:i:s');
        $hours = static fn (string $open, string $close) => json_encode([
            'mon' => ['closed' => false, 'open' => $open, 'close' => $close, 'note' => ''],
            'tue' => ['closed' => false, 'open' => $open, 'close' => $close, 'note' => ''],
            'wed' => ['closed' => false, 'open' => $open, 'close' => $close, 'note' => ''],
            'thu' => ['closed' => false, 'open' => $open, 'close' => $close, 'note' => ''],
            'fri' => ['closed' => false, 'open' => $open, 'close' => $close, 'note' => ''],
            'sat' => ['closed' => false, 'open' => '08:00', 'close' => '12:00', 'note' => ''],
            'sun' => ['closed' => true, 'open' => '', 'close' => '', 'note' => ''],
        ]);
        $desc = '<p><strong>Fernwood Dental Studio</strong> is a family dental practice in Parkhurst, Johannesburg. '
            . 'We look after the whole family, from a first check up for a toddler to whitening before a wedding.</p>'
            . '<p>Our team offers check ups and cleaning, fillings, teeth whitening, children\'s dentistry and same day '
            . 'emergency appointments. We work with most medical aids and you can book online any time, day or night.</p>';
        $category = $db->table('directory_categories')->select('id')->where('slug', 'dentist')->get()->getRowArray();

        $db->table('directory_listings')->insert([
            'type' => 'practice', 'display_name' => 'Fernwood Dental Studio', 'contact_person' => 'Lindiwe Khumalo',
            'title' => '', 'position' => 'Owner', 'category_id' => $category['id'] ?? null, 'credentials' => '',
            'description' => $desc, 'description_text' => trim(strip_tags(str_replace('</p><p>', "\n", $desc))),
            'phone' => '', 'phone_alt' => '', 'whatsapp' => '', 'email' => '', 'website' => '',
            'address_line' => '12 Fernwood Avenue', 'address_line_2' => '', 'suburb' => 'Parkhurst', 'city' => 'Johannesburg',
            'province' => 'Gauteng', 'postal_code' => '2193', 'country' => 'South Africa', 'country_code' => 'ZA',
            'latitude' => '-26.1380000', 'longitude' => '28.0170000', 'geocode_precision' => 'manual', 'geocoding_status' => 'manual',
            'trading_hours' => $hours('08:00', '17:00'), 'holiday_hours' => 'closed', 'holiday_open_dates' => '',
            'by_appointment' => 0, 'accepts_card_payments' => 1, 'offers_delivery' => 0, 'offers_online_booking' => 1,
            'booking_url' => '', 'slug' => self::SLUG, 'status' => 'published', 'signup_source' => 'demo', 'source' => 'public_form',
            'is_verified' => 1, 'is_featured' => 0, 'is_demo' => 1, 'verified_until' => date('Y-m-d', strtotime('+10 years')),
            'admin_plan' => 'verified', 'location_name' => 'Parkhurst', 'bookings_enabled' => 1, 'bookings_enabled_at' => $now,
            'reviews_enabled' => 0, 'marketing_opt_in' => 0, 'job_alerts' => 0, 'email_public' => 0,
            'customer_location' => 'visit', 'show_address' => 1, 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $db->insertID();
        $db->query('INSERT INTO xs_directory_listing_points (listing_id, location) VALUES (?, ST_GeomFromText(?, 4326))', [$id, 'POINT(-26.138 28.017)']);

        $dir = FCPATH . 'assets/listings/gallery/';
        is_dir($dir) || mkdir($dir, 0775, true);
        foreach (self::PHOTOS as $i => $file) {
            $path = 'assets/listings/gallery/demo_fernwood_' . $i . '.webp';
            copy(FCPATH . 'assets/categories/' . $file, FCPATH . $path);
            [$w, $h] = getimagesize(FCPATH . $path) ?: [1600, 1067];
            $db->table('directory_listing_photos')->insert(['listing_id' => $id, 'path' => $path, 'original_name' => $file, 'width' => $w, 'height' => $h, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now]);
        }

        $db->table('directory_practice_locations')->insert([
            'listing_id' => $id, 'name' => 'Fernwood Dental Rosebank', 'contact_person' => 'Zanele Dube',
            'address_line' => '4 Oxford Lane', 'suburb' => 'Rosebank', 'city' => 'Johannesburg', 'province' => 'Gauteng',
            'postal_code' => '2196', 'country' => 'South Africa', 'country_code' => 'ZA', 'latitude' => '-26.1460000', 'longitude' => '28.0420000',
            'geocode_precision' => 'manual', 'geocoding_status' => 'manual', 'trading_hours' => $hours('08:30', '16:30'),
            'phone' => '', 'email' => '', 'is_primary' => 0, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $team = [];
        foreach (self::PEOPLE as $i => [$name, $role, $cred, $spec, $bio]) {
            $db->table('directory_listing_team')->insert([
                'listing_id' => $id, 'name' => $name, 'slug' => url_title($name, '-', true), 'role' => $role,
                'credentials' => $cred, 'specializations' => $spec, 'bio' => $bio, 'photo_path' => null,
                'sort_order' => $i, 'show_on_profile' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $team[$i] = (int) $db->insertID();
        }

        $db->table('booking_categories')->insert(['listing_id' => $id, 'name' => 'General dentistry', 'color' => 'ocean', 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $cat = (int) $db->insertID();
        $svc = [];
        foreach (self::SERVICES as $i => [$name, $min, $cents, $label]) {
            $db->table('directory_listing_services')->insert(['listing_id' => $id, 'name' => $name, 'price_label' => $label, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now]);
            $db->table('booking_services')->insert([
                'listing_id' => $id, 'slug' => url_title($name, '-', true), 'name' => $name, 'duration_min' => $min,
                'length_checked' => 1, 'price_cents' => $cents, 'currency' => 'ZAR', 'deposit_pct' => 0, 'buffer_before' => 0,
                'buffer_after' => 0, 'category_id' => $cat, 'delivery_modes' => '["onsite"]', 'profile_service_name' => $name,
                'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $svc[$i] = (int) $db->insertID();
        }

        $prov = [];
        foreach (self::PEOPLE as $i => [$name, $role, , , $bio, $color]) {
            if ($color === null) {
                continue;
            }
            $db->table('booking_providers')->insert([
                'listing_id' => $id, 'team_member_id' => $team[$i], 'member_id' => null, 'slug' => url_title($name, '-', true),
                'name' => $name, 'title' => $role, 'bio' => $bio, 'color' => $color, 'bookable' => 1, 'hours_mode' => 'profile',
                'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $prov[$i] = (int) $db->insertID();
        }
        foreach (self::OFFERS as $p => $list) {
            foreach ($list as $s) {
                $db->table('booking_provider_services')->insert(['listing_id' => $id, 'provider_id' => $prov[$p], 'service_id' => $svc[$s]]);
            }
        }

        $settings = [
            'business.future_limit'               => '180',
            'booking.custom_field_1_enabled'      => '1',
            'booking.custom_field_1_title'        => 'Medical aid',
            'booking.custom_field_1_type'         => 'select',
            'booking.custom_field_1_required'     => '0',
            'booking.custom_field_1_options'      => "Discovery Health Medical Scheme\nBonitas Medical Fund\nMomentum Medical Scheme\nFedhealth Medical Scheme\nBestmed Medical Scheme\nNo medical aid",
            'legal.cancellation_policy'           => 'Please cancel or move your appointment at least 24 hours before it starts, from the link in your confirmation email.',
            'legal.rescheduling_policy'           => 'You can move your appointment yourself up to 24 hours before it starts.',
        ];
        foreach ($settings as $name => $value) {
            $db->table('booking_settings')->insert(['listing_id' => $id, 'name' => $name, 'value' => $value]);
        }

        if ($sample) {
            $this->sampleBookings($db, $id, $svc, $prov, $now);
        }

        return $id;
    }

    /** @return string the new password */
    private function owner($db, int $listingId): string
    {
        $password = bin2hex(random_bytes(8));
        $now      = gmdate('Y-m-d H:i:s');
        $db->table('accounts')->insert([
            'email' => self::OWNER_EMAIL, 'name' => 'Lindiwe Khumalo', 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'status' => 'active', 'password_changed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->table('business_members')->insert([
            'account_id' => (int) $db->insertID(), 'listing_id' => $listingId, 'role' => 'admin', 'status' => 'active',
            'notify_on_appointments' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $password;
    }

    /**
     * Fictional customers and appointments from five weeks ago to the end of
     * next week, for screenshots of the calendar, dashboard and analytics.
     * Never in production.
     *
     * @param array<int,int> $svc
     * @param array<int,int> $prov
     */
    private function sampleBookings($db, int $id, array $svc, array $prov, string $now): void
    {
        $names = [['Thabo', 'Nkosi'], ['Megan', 'van Wyk'], ['Ayesha', 'Patel'], ['Sipho', 'Mthembu'], ['Claire', 'Botha'],
            ['Kagiso', 'Molefe'], ['Priya', 'Govender'], ['Daniel', 'Smit'], ['Nomvula', 'Zulu'], ['Ruan', 'du Plessis'],
            ['Fatima', 'Ismail'], ['Lerato', 'Mokoena'], ['Pieter', 'Kruger'], ['Amahle', 'Ndlovu']];
        $customers = [];
        foreach ($names as $i => [$first, $last]) {
            $db->table('booking_customers')->insert([
                'listing_id' => $id, 'hash' => bin2hex(random_bytes(32)), 'first_name' => $first, 'last_name' => $last,
                'email' => strtolower(str_replace(' ', '', $first . '.' . $last)) . '@customer.example',
                'phone' => sprintf('08255501%02d', $i + 10), 'custom_fields' => '[]',
                'created_at' => gmdate('Y-m-d H:i:s', strtotime('-' . (40 - $i * 2) . ' days')), 'updated_at' => $now,
            ]);
            $customers[] = (int) $db->insertID();
        }

        $tz    = new DateTimeZone('Africa/Johannesburg');
        $utc   = new DateTimeZone('UTC');
        $mon   = new DateTimeImmutable('monday this week', $tz);
        $nowTz = new DateTimeImmutable('now', $tz);
        mt_srand(20261112);
        $c = 0;
        // Five weeks back (for analytics) to the end of next week (for the calendar).
        for ($d = -35; $d < 12; $d++) {
            $day = $mon->modify("+{$d} days");
            $dow = (int) $day->format('N');
            if ($dow === 7) {
                continue;
            }
            foreach ($prov as $p => $providerId) {
                foreach ($dow === 6 ? ['08:00', '09:00', '10:30'] : ['08:00', '09:00', '10:00', '11:30', '13:00', '14:00', '15:30'] as $time) {
                    if (mt_rand(0, 99) < 62) {
                        continue;
                    }
                    $s = self::OFFERS[$p][array_rand(self::OFFERS[$p])];
                    [$name, $min, $cents] = self::SERVICES[$s];
                    $start = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $time, $tz);
                    $end   = $start->modify("+{$min} minutes");
                    $past  = $end < $nowTz;
                    $db->table('booking_appointments')->insert([
                        'listing_id' => $id, 'reference' => 'pending-' . bin2hex(random_bytes(4)), 'hash' => bin2hex(random_bytes(16)),
                        'provider_id' => $providerId, 'service_id' => $svc[$s], 'customer_id' => $customers[$c++ % count($customers)],
                        'start_at' => $start->setTimezone($utc)->format('Y-m-d H:i:s'), 'end_at' => $end->setTimezone($utc)->format('Y-m-d H:i:s'),
                        'hold_before_min' => 0, 'hold_after_min' => 0, 'stored_timezone' => 'Africa/Johannesburg',
                        'status' => $past ? ['cancelled', 'no_show'][mt_rand(0, 19)] ?? 'completed' : ($d >= 7 && mt_rand(0, 9) === 0 ? 'pending' : 'confirmed'),
                        'source' => mt_rand(0, 2) ? 'public' : 'business', 'delivery_mode' => 'onsite', 'service_name' => $name,
                        'service_duration_min' => $min, 'price_cents' => $cents, 'currency' => 'ZAR', 'provider_name' => self::PEOPLE[$p][0],
                        'location_name' => 'Parkhurst', 'location_address' => '12 Fernwood Avenue, Parkhurst, Johannesburg, Gauteng, 2193',
                        'payment_status' => 'none', 'reminder_sent' => $past ? 1 : 0, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $apptId = (int) $db->insertID();
                    $db->table('booking_appointments')->where('id', $apptId)->update(['reference' => \App\Libraries\PaymentReference::make(\App\Libraries\PaymentReference::BOOKING, $apptId)]);
                }
            }
        }
    }
}
