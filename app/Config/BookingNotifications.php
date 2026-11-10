<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * What online bookings can tell customers and the team, and the default
 * wording (Phase 6, docs/bookings/parity.md §9). Ported from Bookings'
 * NotificationCatalog and the default templates in NotificationTemplateService,
 * with the copy rewritten to docs/bookings/copy.md.
 *
 * Defaults live here; a business's own choices are rows in
 * xs_booking_notification_rules and xs_booking_templates (NotificationRules,
 * NotificationTemplates). Team messages are fixed wording: only customer
 * messages are the business's to edit.
 */
class BookingNotifications extends BaseConfig
{
    /** SMS was removed on 5 Nov 2026 (owner, decision T20): email, and WhatsApp on the business's own account. */
    public const CHANNELS = ['email', 'whatsapp'];

    public const CUSTOMER = 'customer';
    public const TEAM     = 'team';

    /** Event keys (code) and how the settings screen names them. */
    public const EVENTS = [
        'pending'     => 'Booking request received',
        'confirmed'   => 'Booking confirmed',
        'rescheduled' => 'Booking moved to a new time',
        'cancelled'   => 'Booking cancelled',
        'reminder'    => 'Reminder before the appointment',
        'no_show'     => 'Marked as a no show',
    ];

    /** Events the team hears about, when the customer did it (booked, moved, cancelled online). */
    public const TEAM_EVENTS = ['pending', 'confirmed', 'rescheduled', 'cancelled'];

    public const CHANNEL_LABELS = ['email' => 'Email', 'whatsapp' => 'WhatsApp'];

    /**
     * Rules with no row: customer email on for everything but no show,
     * WhatsApp off (it needs the business's own account, decision D8), team
     * email on.
     *
     * @var array<string,array<string,bool>> recipient => "event.channel" => on
     */
    public array $defaultRules = [
        self::CUSTOMER => [
            'pending.email' => true, 'confirmed.email' => true, 'rescheduled.email' => true,
            'cancelled.email' => true, 'reminder.email' => true, 'no_show.email' => false,
        ],
        self::TEAM => [
            'pending.email' => true, 'confirmed.email' => true, 'rescheduled.email' => true, 'cancelled.email' => true,
        ],
    ];

    /** Retries before a message is given up on, and the longest wait between them. */
    public int $maxAttempts = 5;

    public int $maxBackoffMinutes = 60;

    /** A claimed row whose worker died is taken again after this long. */
    public int $staleLockMinutes = 15;

    /** Most messages one run sends per channel; the rest wait for the next minute. */
    public array $perRunLimit = ['email' => 200, 'whatsapp' => 60];

    /** How long delivery logs and finished queue rows are kept (bookings:prune). */
    public int $logDays = 90;

    public const SUBJECT_MAX = 190;

    public const BODY_MAX = 4000;

    /** A WhatsApp message body. */
    public const TEXT_MAX = 480;

    /**
     * What a template may use. Values are escaped where they are rendered;
     * {answers} lists the answers to the business's own questions except any
     * marked sensitive, and email only.
     *
     * @var array<string,string>
     */
    public const PLACEHOLDERS = [
        '{customer_first_name}' => 'The customer\'s first name',
        '{customer_name}'       => 'The customer\'s full name',
        '{service}'             => 'The service booked',
        '{duration}'            => 'How long it takes, like "45 minutes"',
        '{team_member}'         => 'Who the appointment is with',
        '{business}'            => 'Your business name',
        '{date}'                => 'The day, like "Tuesday 7 January 2030"',
        '{time}'                => 'The start time',
        '{when}'                => '"tomorrow at 09:00" or "on Tuesday 7 January at 09:00"',
        '{location}'            => 'Where, when the address is public',
        '{business_phone}'      => 'Your phone number',
        '{reference}'           => 'The booking reference',
        '{manage_link}'         => 'The customer\'s link to change or cancel',
        '{booking_link}'        => 'Your booking page',
        '{cancellation_policy}' => 'Your cancellation policy',
        '{rescheduling_policy}' => 'Your rescheduling policy',
        '{answers}'             => 'Answers to your questions (email only, never sensitive ones)',
        '{video_link}'          => 'The link to join an online appointment (Zoom or Jitsi)',
    ];

    /**
     * Customer messages. Email bodies are plain text paragraphs separated by
     * a blank line; the email itself adds the appointment details, the change
     * or cancel button and the unsubscribe link, so a business cannot edit
     * them away. WhatsApp carries {manage_link} itself.
     *
     * @var array<string,array<string,array{subject?:string,body:string}>>
     */
    public array $customerTemplates = [
        'pending' => [
            'email'    => [
                'subject' => 'Your booking request with {business}',
                'body'    => "Hi {customer_first_name},\n\nWe have your request for {service} {when}. {business} will confirm it, and we will email you when they do.\n\n{answers}",
            ],
            'whatsapp' => ['body' => 'Hi {customer_first_name}, {business} has your request for {service} {when}. We will let you know when it is confirmed. Change or cancel: {manage_link}'],
        ],
        'confirmed' => [
            'email'    => [
                'subject' => 'Confirmed: {service} with {business} on {date} at {time}',
                'body'    => "Hi {customer_first_name},\n\nYour booking is confirmed: {service} with {team_member} {when}.\n\n{answers}",
            ],
            'whatsapp' => ['body' => 'Hi {customer_first_name}, your booking with {business} for {service} {when} is confirmed. Change or cancel: {manage_link}'],
        ],
        'rescheduled' => [
            'email'    => [
                'subject' => 'New time: {service} with {business} on {date} at {time}',
                'body'    => "Hi {customer_first_name},\n\nYour booking has a new time: {service} with {team_member} {when}.",
            ],
            'whatsapp' => ['body' => 'Hi {customer_first_name}, your booking with {business} for {service} is now {when}. Change or cancel: {manage_link}'],
        ],
        'cancelled' => [
            'email'    => [
                'subject' => 'Cancelled: {service} with {business} on {date}',
                'body'    => "Hi {customer_first_name},\n\nYour booking for {service} {when} is cancelled.\n\nYou are welcome to book again: {booking_link}",
            ],
            'whatsapp' => ['body' => 'Hi {customer_first_name}, your booking with {business} for {service} {when} is cancelled. Book again: {booking_link}'],
        ],
        'reminder' => [
            'email'    => [
                'subject' => 'Reminder: {service} with {business} {when}',
                'body'    => "Hi {customer_first_name},\n\nThis is a reminder of your booking: {service} with {team_member} {when}.\n\nIf you cannot make it, please change or cancel your booking so someone else can have the time.",
            ],
            'whatsapp' => ['body' => 'Hi {customer_first_name}, a reminder of your booking with {business}: {service} {when}. Change or cancel: {manage_link}'],
        ],
        'no_show' => [
            'email'    => [
                'subject' => 'We missed you at {business}',
                'body'    => "Hi {customer_first_name},\n\nWe missed you for {service} {when}. You are welcome to book a new time: {booking_link}",
            ],
            'whatsapp' => ['body' => 'Hi {customer_first_name}, {business} missed you for {service} {when}. Book a new time: {booking_link}'],
        ],
    ];

    /**
     * The heading of each customer email, above the business's own
     * paragraphs, so the email reads at a glance (the reference app's
     * "Appointment Confirmed" line). Fixed wording.
     *
     * @var array<string,string>
     */
    public array $customerHeadings = [
        'pending'     => 'Booking request received',
        'confirmed'   => 'Booking confirmed',
        'rescheduled' => 'New time for your booking',
        'cancelled'   => 'Booking cancelled',
        'reminder'    => 'A reminder of your booking',
        'no_show'     => 'We missed you',
    ];

    /**
     * The heading of each team email. The reference app headed its pending
     * notice "Appointment Confirmed"; here each event says what happened.
     *
     * @var array<string,string>
     */
    public array $teamHeadings = [
        'pending'     => 'New booking request',
        'confirmed'   => 'New booking',
        'rescheduled' => 'Booking moved',
        'cancelled'   => 'Booking cancelled',
    ];

    /**
     * Team emails (fixed wording). {customer_name}, {service}, {when} and
     * {team_member} as above; the email adds a button to the calendar.
     *
     * @var array<string,array{subject:string,body:string,inbox:string}>
     */
    public array $teamTemplates = [
        'pending' => [
            'subject' => 'New booking request: {service} {when}',
            'body'    => '{customer_name} asked for {service} with {team_member} {when}. It is waiting for you to confirm it.',
            'inbox'   => 'New booking request from {customer_name}',
        ],
        'confirmed' => [
            'subject' => 'New booking: {service} {when}',
            'body'    => '{customer_name} booked {service} with {team_member} {when}.',
            'inbox'   => 'New booking from {customer_name}',
        ],
        'rescheduled' => [
            'subject' => 'Booking moved: {service} is now {when}',
            'body'    => '{customer_name} moved their booking for {service} with {team_member}. It is now {when}.',
            'inbox'   => '{customer_name} moved their booking',
        ],
        'cancelled' => [
            'subject' => 'Booking cancelled: {service} {when}',
            'body'    => '{customer_name} cancelled their booking for {service} with {team_member} {when}.',
            'inbox'   => '{customer_name} cancelled their booking',
        ],
    ];
}
