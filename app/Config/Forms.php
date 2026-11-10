<?php

namespace Config;

use App\Services\CareersService;
use App\Services\JobBoardService;
use CodeIgniter\Config\BaseConfig;

/**
 * Every public and owner form the platform takes, for form health tracking
 * (FormHealthService, /admin/forms).
 *
 * Added after 14 to 27 Oct 2026, when every failed signup landed on a 404 and
 * about 18 would-be businesses were lost without anyone knowing. Each POST
 * route here is classified by App\Filters\FormOutcome, and a CSRF failure on
 * one goes back to its own form with the typing kept (App\Filters\CsrfGuard).
 *
 * `posts` maps a route pattern (CI4 placeholders) to the form a failure goes
 * back to; `$1` is the first placeholder. `old` is the flash key the form
 * refills from. `pages` replaces the pages worked out from `posts` (see
 * pageKeyFor()) where those would be every profile or discovery page. FormsRegistryTest fails when a non-admin POST route is in
 * neither $forms nor $notForms, so a new form cannot go untracked.
 */
class Forms extends BaseConfig
{
    /** Switch for all recording (`forms.enabled` in .env). */
    public bool $enabled = true;

    /**
     * @var array<string,array{label:string,posts:array<string,string>,old?:string,pages?:list<string>}>
     */
    public array $forms = [
        'signup'           => ['label' => 'Add your business', 'posts' => ['add-profile' => 'add-profile', 'add-listing' => 'add-profile']],
        'contact'          => ['label' => 'Contact us', 'posts' => ['contact' => 'contact']],
        'profile_setup'    => ['label' => 'Profile setup order', 'posts' => ['profile-setup' => 'profile-setup#order', 'profile-setup/pay' => 'profile-setup/checkout']],
        'recommend'        => ['label' => 'Recommend a business', 'posts' => ['recommend' => 'recommend']],
        'recommend_stop'   => ['label' => 'Stop recommendation emails', 'posts' => ['recommend/stop/(:segment)' => 'recommend/stop/$1']],
        'partners_apply'   => ['label' => 'Partner application', 'posts' => ['partners' => 'partners#apply']],
        'partners_login'   => ['label' => 'Partner sign in', 'posts' => ['partners/login' => 'partners/login']],
        'partners_bank'    => ['label' => 'Partner bank details', 'posts' => ['partners/bank' => 'partners/dashboard#bank']],
        'careers_apply'    => ['label' => 'Careers application', 'posts' => ['careers/apply' => 'careers#apply']],
        'jobs_post'        => ['label' => 'Post a job or request', 'posts' => ['jobs/post' => 'jobs/post']],
        'job_apply'        => ['label' => 'Apply for a job', 'posts' => ['jobs/(:num)/apply' => 'jobs/$1#apply']],
        'job_respond'      => ['label' => 'Reply to a request', 'posts' => ['jobs/(:num)/respond' => 'jobs/$1#respond']],
        'job_report'       => ['label' => 'Report a job post', 'posts' => ['jobs/(:num)/report' => 'jobs/$1']],
        'job_alerts_off'   => ['label' => 'Stop lead alerts', 'posts' => ['jobs/alerts/off/(:segment)' => 'jobs/alerts/off/$1']],
        'jobs_manage'      => ['label' => 'Manage a job post', 'posts' => ['jobs/manage' => 'jobs/manage', 'jobs/manage/close' => 'jobs/manage', 'jobs/manage/renew' => 'jobs/manage']],
        'owner_job'        => ['label' => 'Owner job post', 'posts' => ['business/jobs' => 'business/jobs/new', 'business/jobs/(:num)/edit' => 'business/jobs/$1/edit']],
        'owner_job_action' => ['label' => 'Owner job close, renew, alerts', 'posts' => ['business/jobs/alerts' => 'business/jobs', 'business/jobs/(:num)/close' => 'business/jobs', 'business/jobs/(:num)/renew' => 'business/jobs']],
        'review'           => ['label' => 'Write a review', 'posts' => ['reviews/(:segment)' => 'directory/$1#reviews'], 'old' => 'review_old', 'pages' => []],
        'review_report'    => ['label' => 'Report a review', 'posts' => ['reviews/(:num)/report' => 'directory'], 'pages' => []],
        'owner_reviews'    => ['label' => 'Owner reviews', 'posts' => ['business/reviews/switch' => 'business/reviews', 'business/reviews/(:num)/reply' => 'business/reviews', 'business/reviews/(:num)/report' => 'business/reviews']],
        'manage_link'      => ['label' => 'Manage sign in link', 'posts' => ['manage' => 'business/sign-in']],
        'verification'     => ['label' => 'Verified or Plus application', 'posts' => ['manage/verification' => 'business/badge', 'manage/verification/cancel' => 'business/badge', 'manage/verification/yearly' => 'business/badge', 'manage/verification/pay' => 'business/badge']],
        'preview'          => ['label' => 'Preview approval', 'posts' => ['preview/(:segment)/approve' => 'preview/$1', 'preview/(:segment)/changes' => 'preview/$1']],
        'newsletter'       => ['label' => 'Newsletter signup', 'posts' => ['newsletter' => 'newsletter'], 'pages' => ['newsletter']],
        'newsletter_confirm' => ['label' => 'Newsletter confirm', 'posts' => ['newsletter/confirm/(:segment)' => 'newsletter/confirm/$1']],
        // Online bookings (docs/bookings/).
        'business_profile'  => ['label' => 'Edit profile (business area)', 'posts' => ['business/profile/(:segment)' => 'business/profile/$1', 'business/profile/photo-delete/(:num)' => 'business/profile/photos', 'business/profile/brand-delete/(:num)' => 'business/profile/photos']],
        'business_bookings' => ['label' => 'Turn on online bookings', 'posts' => ['business/bookings/turn-on' => 'business/calendar']],
        'business_sign_in'  => ['label' => 'Business sign in', 'posts' => ['business/sign-in' => 'business/sign-in', 'business/sign-in/link' => 'business/sign-in', 'business/sign-in/(:segment)' => 'business/sign-in/$1']],
        'business_sign_out' => ['label' => 'Business sign out', 'posts' => ['business/sign-out' => 'business/sign-in']],
        'business_password' => ['label' => 'Business password reset', 'posts' => ['business/password' => 'business/password', 'business/password/(:segment)' => 'business/password/$1']],
        'business_account'  => ['label' => 'Business account', 'posts' => ['business/account/name' => 'business/account', 'business/account/password' => 'business/account#password']],
        'business_choose'   => ['label' => 'Choose a business', 'posts' => ['business/choose' => 'business/choose']],
        'business_settings' => ['label' => 'Business settings', 'posts' => ['business/settings/localization' => 'business/settings/localization', 'business/settings/booking' => 'business/settings/booking', 'business/settings/questions' => 'business/settings/questions', 'business/settings/notifications' => 'business/settings/notifications', 'business/settings/notifications/template' => 'business/settings/notifications#wording', 'business/settings/notifications/(whatsapp)' => 'business/settings/notifications', 'business/settings/notifications/(whatsapp)/test' => 'business/settings/notifications', 'business/settings/payments' => 'business/settings/payments', 'business/settings/payments/check' => 'business/settings/payments', 'business/settings/connections/(zoom|jitsi)' => 'business/settings/connections', 'business/settings/connections/zoom/check' => 'business/settings/connections', 'business/settings/connections/google' => 'business/settings/connections', 'business/settings/connections/google/check' => 'business/settings/connections', 'business/settings/connections/google/disconnect' => 'business/settings/connections', 'business/settings/connections/webhook' => 'business/settings/connections#webhook', 'business/settings/connections/webhook/test' => 'business/settings/connections#webhook', 'business/settings/connections/webhook/secret' => 'business/settings/connections#webhook', 'business/settings/connections/webhook/remove' => 'business/settings/connections#webhook', 'business/settings/connections/webhook/(:num)/retry' => 'business/settings/connections#deliveries', 'business/settings/api/keys' => 'business/settings/api', 'business/settings/plan/subscribe' => 'business/settings/plan', 'business/settings/plan/cancel' => 'business/settings/plan', 'business/settings/api/keys/(:num)/revoke' => 'business/settings/api', 'business/turn-off' => 'business/settings/general']],
        'business_notices'  => ['label' => 'Business notices', 'posts' => ['business/notifications/read' => 'business/notifications', 'business/notifications/preferences' => 'business/notifications']],
        'business_invite'   => ['label' => 'Accept a business invitation', 'posts' => ['business/invite/(:segment)' => 'business/invite/$1']],
        'business_services' => ['label' => 'Business services', 'posts' => ['business/services/new' => 'business/services/new', 'business/services/(:num)' => 'business/services/$1', 'business/services/(:num)/archive' => 'business/services', 'business/services/categories' => 'business/services#categories', 'business/services/categories/(:num)' => 'business/services#categories', 'business/services/categories/(:num)/delete' => 'business/services#categories']],
        'business_team'     => ['label' => 'Business team', 'posts' => ['business/team' => 'business/team', 'business/team/(:num)' => 'business/team/$1', 'business/team/(:num)/hours' => 'business/team/$1#hours', 'business/team/(:num)/archive' => 'business/team', 'business/team/invite' => 'business/team#access', 'business/team/members/(:num)/role' => 'business/team#access', 'business/team/members/(:num)/remove' => 'business/team#access']],
        'business_time_off' => ['label' => 'Business time off', 'posts' => ['business/time-off' => 'business/time-off', 'business/time-off/(:num)/delete' => 'business/time-off']],
        'book'              => ['label' => 'Book online', 'posts' => ['directory/(:segment)/book' => 'directory/$1/book'], 'old' => 'book_old', 'pages' => []],
        'book_change'       => ['label' => 'Change or cancel a booking', 'posts' => ['book/(:segment)/reschedule' => 'book/$1', 'book/(:segment)/cancel' => 'book/$1', 'book/customer/(:segment)/(:segment)/reschedule' => 'book/find', 'book/customer/(:segment)/(:segment)/cancel' => 'book/find'], 'pages' => []],
        'book_pay'          => ['label' => 'Pay a booking deposit', 'posts' => ['book/(:segment)/pay' => 'book/$1', 'book/customer/(:segment)/(:segment)/pay' => 'book/find'], 'pages' => []],
        'book_find'         => ['label' => 'Find my booking', 'posts' => ['book/find' => 'book/find']],
        'business_appointment' => ['label' => 'Book an appointment (business)', 'posts' => ['business/appointments/new' => 'business/appointments/new'], 'old' => 'appointment_old'],
        'business_customers'   => ['label' => 'Business customers', 'posts' => ['business/customers/new' => 'business/customers/new', 'business/customers/(:segment)' => 'business/customers/$1#edit', 'business/customers/(:segment)/delete' => 'business/customers/$1#delete', 'business/customers/(:segment)/emails' => 'business/customers/$1'], 'old' => 'customer_old'],
    ];

    /**
     * Non-admin POST routes that are not forms a person fills in: webhooks,
     * beacons, live helpers that answer JSON and the one-click unsubscribe.
     *
     * @var list<string>
     */
    public array $notForms = [
        // The calendar's JSON writes (resources/business/calendar/): no page to send a failure back to.
        'business/api/appointments',
        'business/api/appointments/(:num)/reschedule',
        'business/api/appointments/(:num)/status',
        'business/api/appointments/(:num)/notes',
        'business/api/appointments/(:num)/notify',
        'business/api/appointments/(:num)/payment',
        // A 301 to add-profile, registered for every method.
        'list-your-practice',
        // A 307 to /business/..., for pages still open on the old /bookings paths.
        'bookings/(:any)',
        // 307s to business/jobs and business/reviews, for pages still open on the old paths.
        'manage/jobs',
        'manage/jobs/(:any)',
        'manage/reviews/(:any)',
        'add-profile/score',
        'careers/screening/start',
        'careers/screening/answer',
        'jobs/screening/start',
        'jobs/screening/answer',
        // 307s from the old one page owner form to business/profile/... (10 Nov 2026).
        'manage/edit',
        'manage/preview',
        'manage/photo-delete/(:num)',
        'manage/brand-delete/(:num)',
        'business/profile/(:segment)/preview',
        'unsubscribe/(:segment)',
        'newsletter/unsubscribe/(:segment)',
        'book/unsubscribe/(:segment)',
        'csp-report',
        'payfast/notify',
        'payfast/setup-notify',
        'paystack/webhook',
        'book/payfast/notify',
        // The bookings API: JSON for programs, no page to send a failure back to.
        'api/v1/appointments',
        'api/v1/appointments/(:num)/reschedule',
        'api/v1/appointments/(:num)/status',
        'api/v1/appointments/(:num)/notes',
        'api/v1/customers',
        'api/v1/customers/(:num)',
        'discovery/opened',
        'stats/tap',
        'forms/event',
    ];

    /**
     * What the dashboard calls a field key. Anything not listed is shown as
     * the key with underscores turned into spaces.
     *
     * @var array<string,string>
     */
    public array $fieldLabels = [
        '_has_photo'     => 'Logo or photo',
        'display_name'   => 'Business name',
        'contact_person' => 'Contact person',
        'category_id'    => 'Category',
        'description'    => 'Description',
        'email'          => 'Email',
        'phone'          => 'Phone',
        'whatsapp'       => 'WhatsApp',
        'website'        => 'Website',
        'address_line'   => 'Street address',
        'suburb'         => 'Suburb',
        'city'           => 'City',
        'province'       => 'Province',
        'region'         => 'Region',
        'postal_code'    => 'Postal code',
        'country'        => 'Country',
        'trading_hours'  => 'Hours',
        'hours'          => 'Hours',
        'services'       => 'Services',
        'consent'        => 'Consent box',
        'lat'            => 'Map pin',
        'lng'            => 'Map pin',
        'cv'             => 'CV',
        'message'        => 'Message',
        'name'           => 'Name',
        'screening'      => 'Screening questions',
    ];

    /** The form a POST path belongs to, or null. */
    public function keyFor(string $path): ?string
    {
        return $this->match($path)['key'] ?? null;
    }

    /**
     * The form a POST path belongs to, the URL a failure goes back to, and
     * the flash key it refills from.
     *
     * @param array<string,mixed> $post what was posted, for the signup form's mode
     * @return array{key:string,url:string,old:string}|null
     */
    public function match(string $path, array $post = []): ?array
    {
        $path = trim($path, '/');
        foreach ($this->forms as $key => $form) {
            foreach ($form['posts'] as $pattern => $return) {
                if (preg_match(self::regex($pattern), $path, $m) !== 1) {
                    continue;
                }

                return [
                    'key' => $key,
                    'url' => $this->returnUrl($key, $return, $m[1] ?? '', $post),
                    'old' => $form['old'] ?? 'old',
                ];
            }
        }

        return null;
    }

    /**
     * The form whose page this path is: where a failure goes back to, or the
     * POST path itself. Errors::notFound uses it to tell a 404 reached from a
     * form (a submission lost) from any other.
     */
    public function pageKeyFor(string $path): ?string
    {
        $path = trim($path, '/');
        foreach ($this->forms as $key => $form) {
            $pages = $form['pages'] ?? null;
            if ($pages === null) {
                $pages = [];
                foreach ($form['posts'] as $pattern => $return) {
                    $page    = str_replace('$1', '(:segment)', (string) strtok($return, '?#'));
                    $pages[] = $pattern;
                    $pages[] = $page;
                }
                // The signup form also lives at add-profile/verified.
                if ($key === 'signup') {
                    $pages[] = 'add-profile/verified';
                }
            }
            foreach ($pages as $p) {
                if (preg_match(self::regex($p), $path) === 1) {
                    return $key;
                }
            }
        }

        return null;
    }

    /** Is this path one of $notForms? */
    public function isNotForm(string $path): bool
    {
        $path = trim($path, '/');
        foreach ($this->notForms as $pattern) {
            if (preg_match(self::regex($pattern), $path) === 1) {
                return true;
            }
        }

        return false;
    }

    public function label(string $key): string
    {
        return $this->forms[$key]['label'] ?? str_replace('_', ' ', $key);
    }

    public function fieldLabel(string $field): string
    {
        return $this->fieldLabels[$field] ?? ucfirst(str_replace('_', ' ', trim($field, '_')));
    }

    /** A CI4 route pattern as an anchored regex. */
    public static function regex(string $pattern): string
    {
        $quoted = preg_quote($pattern, '#');

        return '#^' . strtr($quoted, [
            preg_quote('(:num)', '#')     => '([0-9]+)',
            preg_quote('(:segment)', '#') => '([^/]+)',
            preg_quote('(:any)', '#')     => '(.*)',
        ]) . '$#';
    }

    /**
     * Where a failed submission goes back to. Named outright, never
     * redirect()->back() (see Listing::formUrl()). Three forms need more
     * than the template: signup has three modes, and a job or a role page
     * lives at its slug (the bare id 301s, and a redirect hop drops the
     * flashed input).
     *
     * @param array<string,mixed> $post
     */
    private function returnUrl(string $key, string $template, string $capture, array $post): string
    {
        if ($key === 'signup') {
            if (\App\Controllers\Listing::isVerifiedPageMode($post['form_mode'] ?? '')) {
                return base_url('add-profile/verified');
            }

            return base_url(($post['plan'] ?? '') === 'free' ? 'add-profile?plan=free' : 'add-profile');
        }

        if (in_array($key, ['job_apply', 'job_respond', 'job_report'], true)) {
            $jobs = new JobBoardService();
            $job  = $jobs->find((int) $capture);
            if ($job !== null) {
                return $jobs->url($job) . match ($key) {
                    'job_apply'   => '#apply',
                    'job_respond' => '#respond',
                    default       => '',
                };
            }

            return base_url('jobs');
        }

        if ($key === 'careers_apply' && (int) ($post['role_id'] ?? 0) > 0) {
            $svc  = new CareersService();
            $role = (new \App\Models\CareerRoleModel())->find((int) $post['role_id']);
            if (is_array($role) && $svc->isOpen($role)) {
                return $svc->url($role) . '#apply';
            }
        }

        [$path, $fragment] = array_pad(explode('#', str_replace('$1', $capture, $template), 2), 2, '');

        return base_url($path) . ($fragment !== '' ? '#' . $fragment : '');
    }
}
