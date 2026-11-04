<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Online bookings, the rebuild of WebScheduler Bookings inside Local
 * (docs/bookings/, skill `bookings`).
 *
 * Everything is dark until `bookings.enabled = true` in .env: every /business
 * route answers 404 and /manage shows no offer. A profile then still needs its
 * own switch (xs_directory_listings.bookings_enabled), which only
 * BookingSetupService writes.
 */
class Bookings extends BaseConfig
{
    /** The global switch (`bookings.enabled`). */
    public bool $enabled = false;

    /**
     * Sites that may use bookings (Config\Sites keys). co.za first, decision
     * D2 in docs/bookings/decisions.md; .app shows nothing until it is added.
     *
     * @var list<string>
     */
    public array $sites = ['za'];

    /** A signed in session ends after this long without a request (Bookings: 2 hours). */
    public int $idleSeconds = 7200;

    /** Sign in attempts per IP (Bookings' lockout: 5 per 15 minutes). */
    public int $ipAttempts = 5;

    public int $ipWindowSeconds = 900;

    /**
     * Failed attempts per account, from any address, before the account waits.
     * Stops slow guessing spread across many IPs.
     */
    public int $accountAttempts = 10;

    public int $accountWindowSeconds = 3600;

    public int $passwordMinLength = 10;

    /** Password reset link lifetime. */
    public int $resetSeconds = 3600;

    /** Emailed sign in link lifetime. */
    public int $signInLinkSeconds = 900;

    /**
     * Per business settings and their defaults (docs/bookings/settings.md).
     * A key not listed here cannot be stored. Each value is the default, and
     * `$settingChoices` limits what may replace it.
     *
     * @var array<string,string>
     */
    public array $settingDefaults = [
        'localization.time_format' => '24h',
        'localization.first_day'   => 'monday',
        // Booking rules (Phase 3). Minutes unless the key says otherwise.
        'booking.slot_step'        => '0',   // 0 = every service's own length
        'booking.lead_minutes'     => '0',   // minimum notice
        'business.buffer_time'     => '0',   // kept free after every appointment
        'business.future_limit'    => '60',  // days ahead customers may book online
        // The public booking form (Phase 5): which customer fields show and
        // which must be filled. Email or phone must stay required (we need a
        // way to reach the customer); BookingSettings::save() refuses otherwise.
        'booking.first_names_display' => '1', 'booking.first_names_required' => '1',
        'booking.surname_display'     => '1', 'booking.surname_required'     => '1',
        'booking.email_display'       => '1', 'booking.email_required'       => '1',
        'booking.phone_display'       => '1', 'booking.phone_required'       => '1',
        'booking.address_display'     => '0', 'booking.address_required'     => '0',
        'booking.notes_display'       => '0', 'booking.notes_required'       => '0',
        'booking.default_appointment_status' => 'pending', // what an online booking starts as
        'business.reschedule'         => '24',  // hours before; 0 = not online
        'business.cancel'             => '24',  // hours before; 0 = not online
        'legal.terms'                 => '',
        'legal.cancellation_policy'   => '',
        'legal.rescheduling_policy'   => '',
        'legal.terms_url'             => '',
        'legal.privacy_url'           => '',
        // The business's own questions (Phase 4), see customFieldKeys().
        'booking.custom_field_1_enabled' => '0', 'booking.custom_field_1_title' => '', 'booking.custom_field_1_type' => 'text', 'booking.custom_field_1_required' => '0', 'booking.custom_field_1_sensitive' => '0', 'booking.custom_field_1_options' => '',
        'booking.custom_field_2_enabled' => '0', 'booking.custom_field_2_title' => '', 'booking.custom_field_2_type' => 'text', 'booking.custom_field_2_required' => '0', 'booking.custom_field_2_sensitive' => '0', 'booking.custom_field_2_options' => '',
        'booking.custom_field_3_enabled' => '0', 'booking.custom_field_3_title' => '', 'booking.custom_field_3_type' => 'text', 'booking.custom_field_3_required' => '0', 'booking.custom_field_3_sensitive' => '0', 'booking.custom_field_3_options' => '',
        'booking.custom_field_4_enabled' => '0', 'booking.custom_field_4_title' => '', 'booking.custom_field_4_type' => 'text', 'booking.custom_field_4_required' => '0', 'booking.custom_field_4_sensitive' => '0', 'booking.custom_field_4_options' => '',
        'booking.custom_field_5_enabled' => '0', 'booking.custom_field_5_title' => '', 'booking.custom_field_5_type' => 'text', 'booking.custom_field_5_required' => '0', 'booking.custom_field_5_sensitive' => '0', 'booking.custom_field_5_options' => '',
        'booking.custom_field_6_enabled' => '0', 'booking.custom_field_6_title' => '', 'booking.custom_field_6_type' => 'text', 'booking.custom_field_6_required' => '0', 'booking.custom_field_6_sensitive' => '0', 'booking.custom_field_6_options' => '',
    ];

    /** How many of the business's own questions there can be (Bookings: 6). */
    public const CUSTOM_FIELDS = 6;

    /** Longest question title, and longest answer. */
    public const CUSTOM_FIELD_TITLE_MAX = 80;

    public const CUSTOM_FIELD_VALUE_MAX = 2000;

    /** Choices of a select question: one per line, at most this many, each this long. */
    public const CUSTOM_FIELD_OPTIONS_MAX = 20;

    public const CUSTOM_FIELD_OPTION_LENGTH = 80;

    /**
     * Longest value of a free text setting, by key; anything else free text
     * is a question title (CUSTOM_FIELD_TITLE_MAX).
     *
     * @var array<string,int>
     */
    public array $settingMaxLength = [];

    /**
     * Whole number settings and their allowed range, inclusive.
     *
     * @var array<string,array{0:int,1:int}>
     */
    public array $settingRanges = [
        'booking.slot_step'     => [0, 240],
        'booking.lead_minutes'  => [0, 20160],
        'business.buffer_time'  => [0, 240],
        'business.future_limit' => [1, 730],
        'business.reschedule'   => [0, 336],
        'business.cancel'       => [0, 336],
    ];

    /**
     * The fields of the public booking form, setting stem => form field. First
     * name is always asked. `notes` are the appointment's ("Anything we should
     * know"), not the customer record's.
     */
    public const CUSTOMER_FIELDS = [
        'first_names' => 'first_name', 'surname' => 'last_name', 'email' => 'email',
        'phone' => 'phone', 'address' => 'address', 'notes' => 'notes',
    ];

    /** Longest business terms or policy text. */
    public const LEGAL_TEXT_MAX = 5000;

    /** @var array<string,list<string>> */
    public array $settingChoices = [
        'localization.time_format' => ['24h', '12h'],
        'localization.first_day'   => ['monday', 'sunday'],
        'booking.default_appointment_status' => ['pending', 'confirmed'],
    ];

    public function __construct()
    {
        parent::__construct();
        foreach (array_keys(self::CUSTOMER_FIELDS) as $stem) {
            $this->settingChoices['booking.' . $stem . '_display']  = ['0', '1'];
            $this->settingChoices['booking.' . $stem . '_required'] = ['0', '1'];
        }
        foreach (['legal.terms', 'legal.cancellation_policy', 'legal.rescheduling_policy'] as $key) {
            $this->settingMaxLength[$key] = self::LEGAL_TEXT_MAX;
        }
        $this->settingMaxLength['legal.terms_url']   = 500;
        $this->settingMaxLength['legal.privacy_url'] = 500;
        // Questions: a line, a paragraph, one of a list, or a tick box.
        for ($n = 1; $n <= self::CUSTOM_FIELDS; $n++) {
            foreach (['enabled', 'required', 'sensitive'] as $flag) {
                $this->settingChoices['booking.custom_field_' . $n . '_' . $flag] = ['0', '1'];
            }
            $this->settingChoices['booking.custom_field_' . $n . '_type'] = ['text', 'textarea', 'select', 'checkbox'];
            $this->settingMaxLength['booking.custom_field_' . $n . '_options'] = self::CUSTOM_FIELD_OPTIONS_MAX * (self::CUSTOM_FIELD_OPTION_LENGTH + 1);
        }
    }

    public function enabled(): bool
    {
        $env = env('bookings.enabled');
        if ($env === null || $env === '') {
            return $this->enabled;
        }

        return filter_var($env, FILTER_VALIDATE_BOOLEAN);
    }

    /** Whether bookings may be used on a site (a Config\Sites key). */
    public function siteAllowed(string $siteKey): bool
    {
        return $this->enabled() && in_array($siteKey, $this->sites, true);
    }
}
