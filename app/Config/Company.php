<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Who sends our email: the company details in the footer of every customer
 * email (emails/_footer.php, owner 23 Nov 2026).
 *
 * One set for both sites. Cron and CLI sends always run as co.za
 * (SiteContext), so a per site footer would give a .app profile's campaign or
 * reminder the co.za wording; the international phone format and "registered
 * in South Africa" (as the .app terms say) read right on both.
 *
 * The legal pages keep their own copy of these details.
 */
class Company extends BaseConfig
{
    public string $name = 'WebScheduler (Pty) Ltd';

    public string $registration = '2026/138798/07';

    public string $phone = '+27 76 829 7070';

    /** For tel: links. */
    public string $phoneTel = '+27768297070';

    public string $email = 'za_admin@webscheduler.co.za';

    public string $website = 'https://webscheduler.co.za/';

    /** The logo, a path under public/, made absolute when rendered. */
    public string $logoPath = 'assets/brand/logo-64.png';

    /**
     * The footer's two text lines, worded once for the email
     * (emails/_footer.php) and the admin's view of it
     * (admin/communications/_footer_preview.php).
     *
     * @return list<string>
     */
    public function legalLines(): array
    {
        return [
            $this->name . ', registered in South Africa',
            'Registration number ' . $this->registration,
        ];
    }

    /** The website without scheme or trailing slash, as shown. */
    public function websiteLabel(): string
    {
        return (string) preg_replace('#^https?://|/$#i', '', $this->website);
    }
}
