<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * The document library: every PDF we publish or keep for staff, in one list.
 *
 * Two folders, and only these two:
 *
 * - $published sit in public/assets/documents/ and have a public URL
 *   (/assets/documents/<file>). /resources lists the ones with 'listed' true
 *   (Contact::resources()); an unlisted one, like the proposal, is linked
 *   only where we send it. The old /assets/playbook/ and /assets/proposal/
 *   URLs 301 here (Contact::legacyDocument()).
 * - $internal sit in resources/documents/, which scripts/build-listing-app.js
 *   copies into directory-app/ beside the code, outside the web root. Nothing
 *   there has a public URL: the only way to a file is Admin::document(),
 *   behind the admin filter and Cloudflare Access. The route takes a key from
 *   this list, never a path, so no request can name another file.
 *
 * To add one: build it, put the PDF in the right folder, add an entry here.
 * 'builder' names the command that rebuilds it (null: replaced by hand).
 * The build fails if an entry's file is missing, and so does
 * DocumentLibraryTest.
 */
class Documents extends BaseConfig
{
    /** Where published files live, relative to the web root. */
    public const PUBLIC_DIR = 'assets/documents/';

    /**
     * 'inside' is what the document covers, from its own page headings, shown
     * when a /resources row rolls down. Change it when the PDF changes.
     *
     * @var array<string,array{title:string,summary:string,file:string,pages:int,tag:string,inside:list<string>,listed:bool,builder:?string}>
     */
    public array $published = [
        'profile-guide' => [
            'title'   => 'Profile guide',
            'summary' => 'Build a business profile customers choose: photos that work, a description drafted with ChatGPT, your services and brands, then online bookings, locations, team members, jobs and reviews with Verified Business.',
            'file'    => 'webscheduler-local-profile-guide.pdf',
            'pages'   => 19,
            'tag'     => 'Guide',
            'inside'  => [
                'before you start',
                'profile strength',
                'photos that work',
                'your description with ChatGPT',
                'services',
                'hours and map',
                'brands we carry',
                'Verified Business',
                'locations',
                'team members',
                'jobs',
                'reviews',
                'a launch checklist',
            ],
            'listed'  => true,
            'builder' => 'marketing-site/outreach/profile-guide/build_profile_guide.py --publish',
        ],
        'visibility-playbook' => [
            'title'   => 'Local visibility playbook',
            'summary' => 'How customers find local businesses today, and the practical steps that make a business easier to find, on search, on maps and in AI assistants.',
            'file'    => 'webscheduler-local-visibility-playbook.pdf',
            'pages'   => 12,
            'tag'     => 'Guide',
            'inside'  => [
                'why this matters now',
                'how local search works',
                'what you own and what you rent',
                'the local visibility loop',
                'a complete profile',
                'local SEO',
                'service and location visibility',
                'the customer journey',
                'a worked example',
                'a 30 day plan',
            ],
            'listed'  => true,
            'builder' => 'marketing-site/outreach/playbook/build_playbook.py --only pdf --profile web --publish',
        ],
        'verified-business' => [
            'title'   => 'Verified Business brochure',
            'summary' => 'Paying for visibility, or building a presence people can find? What a Verified profile adds, what we check, and every source behind the numbers.',
            'file'    => 'webscheduler-local-verified-business.pdf',
            'pages'   => 9,
            'tag'     => 'Brochure',
            'inside'  => [
                'when the advert budget runs out',
                'impressions are not sales',
                'start free, get verified',
                'everything Verified adds, online bookings included',
                'built for search, maps and AI',
                'fast, by Google\'s own measure',
                'every source behind the numbers',
            ],
            'listed'  => true,
            'builder' => 'marketing-site/outreach/verified-brochure/build_brochure.py --publish',
        ],
        '2027-outlook' => [
            'title'   => '2027 local visibility outlook',
            'summary' => 'Projected benchmarks for beauty and wellness, home services, health and professional practices, and fitness, and what a complete profile is likely to be worth. A forecast, labelled as one.',
            'file'    => 'webscheduler-local-2027-outlook.pdf',
            'pages'   => 13,
            'tag'     => 'Forecast',
            'inside'  => [
                'five things to know',
                'the growth picture',
                'the research behind it',
                'four segments',
                'the completeness advantage',
                'what customers look for',
                'projected benchmarks',
                'where to focus first',
                'methodology and sources',
            ],
            'listed'  => true,
            'builder' => null,
        ],
        'hiring-guide' => [
            'title'   => 'Hiring guide',
            'summary' => 'Hire well, hire safely: how to write a job post people answer, choose fairly between applicants, check who they are with consent, and make an offer that keeps you on the right side of the law.',
            'file'    => 'webscheduler-local-hiring-guide.pdf',
            'pages'   => 15,
            'tag'     => 'Guide',
            'inside'  => [
                'define the role',
                'write a post people answer',
                'fair wording',
                'post it',
                'shortlist',
                'interview',
                'check before you commit',
                'treat CVs like customer records',
                'job scams',
                'offer and onboarding',
                'a one page checklist',
            ],
            'listed'  => true,
            'builder' => null,
        ],
        'proposal' => [
            'title'   => 'Business proposal',
            'summary' => 'The 12 page proposal for prospective clients, without the founder\'s contact details. Sent, not listed.',
            'file'    => 'webscheduler-local-proposal.pdf',
            'pages'   => 12,
            'tag'     => 'Proposal',
            'inside'  => [],
            'listed'  => false,
            'builder' => 'marketing-site/outreach/proposal/build_proposal.py --publish',
        ],
    ];

    /**
     * @var array<string,array{title:string,description:string,file:string}>
     */
    public array $internal = [
        'partner-program-guide' => [
            'title'       => 'How the Partner Program works',
            'description' => 'Internal guide: the rules, a worked example of one partner and two customers, '
                . 'every edge case, and the decisions before launch. 12 pages. Rebuild with '
                . 'marketing-site/outreach/partner-guide/build_partner_guide.py --admin.',
            'file' => 'partner-program-guide.pdf',
        ],
        'business-proposal-print' => [
            'title'       => 'Business proposal (founder version)',
            'description' => 'The 12 page proposal with the founder\'s own contact details, for emailing to '
                . 'prospective clients. The public web version is on the site; this one is not. Rebuild with '
                . 'marketing-site/outreach/proposal/build_proposal.py --profile both --admin.',
            'file' => 'business-proposal-print.pdf',
        ],
    ];

    /** Absolute path to a published document's file, or null for an unknown key or a missing file. */
    public function publishedPath(string $key): ?string
    {
        $doc = $this->published[$key] ?? null;
        if ($doc === null) {
            return null;
        }

        $full = FCPATH . self::PUBLIC_DIR . basename($doc['file']);

        return is_file($full) ? $full : null;
    }

    /** The published entry whose file is $file, or null. */
    public function publishedByFile(string $file): ?string
    {
        foreach ($this->published as $key => $doc) {
            if ($doc['file'] === $file) {
                return $key;
            }
        }

        return null;
    }

    /** Absolute path to an internal document's file, or null for an unknown key or a missing file. */
    public function path(string $key): ?string
    {
        $doc = $this->internal[$key] ?? null;
        if ($doc === null) {
            return null;
        }

        $full = ROOTPATH . 'resources/documents/' . basename($doc['file']);

        return is_file($full) ? $full : null;
    }
}
