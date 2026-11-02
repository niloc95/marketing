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
        'profile_setup'    => ['label' => 'Profile setup order', 'posts' => ['profile-setup' => 'profile-setup#order']],
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
        'owner_job'        => ['label' => 'Owner job post', 'posts' => ['manage/jobs' => 'manage/jobs/new', 'manage/jobs/(:num)/edit' => 'manage/jobs/$1/edit']],
        'owner_job_action' => ['label' => 'Owner job close, renew, alerts', 'posts' => ['manage/jobs/alerts' => 'manage/edit#jobs', 'manage/jobs/(:num)/close' => 'manage/edit#jobs', 'manage/jobs/(:num)/renew' => 'manage/edit#jobs']],
        'review'           => ['label' => 'Write a review', 'posts' => ['reviews/(:segment)' => 'directory/$1#reviews'], 'old' => 'review_old', 'pages' => []],
        'review_report'    => ['label' => 'Report a review', 'posts' => ['reviews/(:num)/report' => 'directory'], 'pages' => []],
        'owner_reviews'    => ['label' => 'Owner reviews', 'posts' => ['manage/reviews/switch' => 'manage/edit#reviews', 'manage/reviews/(:num)/reply' => 'manage/edit#reviews', 'manage/reviews/(:num)/report' => 'manage/edit#reviews']],
        'manage_link'      => ['label' => 'Manage sign in link', 'posts' => ['manage' => 'manage']],
        'manage_edit'      => ['label' => 'Edit profile', 'posts' => ['manage/edit' => 'manage/edit']],
        'manage_media'     => ['label' => 'Remove photo or brand', 'posts' => ['manage/photo-delete/(:num)' => 'manage/edit', 'manage/brand-delete/(:num)' => 'manage/edit']],
        'verification'     => ['label' => 'Verified or Plus application', 'posts' => ['manage/verification' => 'manage/edit', 'manage/verification/cancel' => 'manage/edit', 'manage/verification/yearly' => 'manage/edit']],
        'preview'          => ['label' => 'Preview approval', 'posts' => ['preview/(:segment)/approve' => 'preview/$1', 'preview/(:segment)/changes' => 'preview/$1']],
    ];

    /**
     * Non-admin POST routes that are not forms a person fills in: webhooks,
     * beacons, live helpers that answer JSON and the one-click unsubscribe.
     *
     * @var list<string>
     */
    public array $notForms = [
        // A 301 to add-profile, registered for every method.
        'list-your-practice',
        'add-profile/score',
        'careers/screening/start',
        'careers/screening/answer',
        'jobs/screening/start',
        'jobs/screening/answer',
        'manage/preview',
        'unsubscribe/(:segment)',
        'csp-report',
        'payfast/notify',
        'payfast/setup-notify',
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
            if (($post['form_mode'] ?? '') === 'verified-only') {
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
