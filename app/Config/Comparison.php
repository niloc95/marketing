<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * The default table on /compare: WebScheduler Local next to Google Business
 * Profile, LinkedIn and the South African directories.
 *
 * These are only the defaults. /admin/comparison saves an edited copy into
 * xs_directory_settings (App\Services\ComparisonService), and a saved copy wins
 * until someone presses "Reset to defaults" there. So a change made here shows
 * only on a site where nobody has saved the table yet.
 *
 * Two rules, because the page has to survive a skeptical reader:
 *
 * 1. Every competitor cell is something their own help pages say, checked on
 *    $checkedOn. Sources, as checked on 3 Oct 2026:
 *    - Google, individual practitioners get their own profile:
 *      https://support.google.com/business/answer/3038177
 *    - Google Local Services Ads countries (not South Africa) and the
 *      Screened / Guaranteed badges:
 *      https://support.google.com/localservices/answer/6224841
 *    - LinkedIn Pages, multiple locations:
 *      https://www.linkedin.com/help/linkedin/answer/111872
 *    - LinkedIn Service Pages (members, not company Pages): requests for
 *      proposals and reviews: https://www.linkedin.com/help/lms/answer/a7125941
 *    - Snupit, pay-per-lead quote requests:
 *      https://pressoffice.mg.co.za/snupit/content/wbrpO7gYWbpqDLZn
 *    - Cylex, free profile with hours, photos, services and reviews; premium
 *      placement paid: https://cylex-international.com/products
 *    - Medpages: free search and a free basic listing; qualifications, a
 *      biography, appointment requests and placement above standard listings
 *      come with the paid Highlighted listing (R2,750/year):
 *      https://www.medpages.info/sf/index.php?page=free-listing
 *    - Brabys: a free listing with category, contact details, address and
 *      opening hours. brabys.com refuses automated fetches, so this was
 *      checked via https://www.juicydesigns.co.za/blog/free-business-directories-south-africa/
 *      and https://sherrlinn.co.za/top-10-free-business-directories-in-south-africa/
 *    Where a cell names no site it says "varies" rather than claim more.
 *
 * 2. The Local column must match what the code gates. Staff, branches and
 *    vacancies are Verified Business (TeamMemberService, PracticeLocationService,
 *    JobBoardService::canUseJobsFeatures()); the free profile is info, services,
 *    contact, map, photos and hours. tests/database/ComparisonPageTest.php pins
 *    the defaults. Caps are {placeholders}, filled in at render from the service
 *    constants, so an edited copy can never freeze an old number.
 */
class Comparison extends BaseConfig
{
    /** Statuses for the WebScheduler Local column. */
    public const LOCAL_STATUSES = [
        'free'          => 'Free',
        'verified'      => 'Verified',
        'free_verified' => 'Free + Verified',
        'no'            => 'Not offered',
    ];

    /** Statuses for every other column. */
    public const OTHER_STATUSES = [
        'yes'     => 'Yes',
        'partial' => 'Partly',
        'paid'    => 'Paid',
        'varies'  => 'Varies',
        'no'      => 'No',
    ];

    /** Column keys, in order. The first is always ours. */
    public const COLUMNS = ['local', 'google', 'linkedin', 'sa'];

    public string $checkedOn = '2026-10-03';

    /** @var array<string,array{label:string,sub:string}> */
    public array $columns = [
        'local'    => ['label' => 'WebScheduler Local', 'sub' => 'Free profile, Verified Business R{price}/month'],
        'google'   => ['label' => 'Google Business Profile', 'sub' => 'Free'],
        'linkedin' => ['label' => 'LinkedIn', 'sub' => 'Company Pages and member profiles'],
        'sa'       => ['label' => 'SA directories', 'sub' => 'Brabys, Snupit, Cylex, Medpages'],
    ];

    /**
     * @var list<array{key:string,label:string,cells:array<string,array{status:string,note:string}>}>
     */
    public array $rows = [
        [
            'key'   => 'free_profile',
            'label' => 'Free business profile',
            'cells' => [
                'local'    => ['status' => 'free', 'note' => 'No monthly fee, no subscription, for a South African business'],
                'google'   => ['status' => 'yes', 'note' => ''],
                'linkedin' => ['status' => 'yes', 'note' => 'A free company Page'],
                'sa'       => ['status' => 'yes', 'note' => 'A basic listing is free; better placement is paid'],
            ],
        ],
        [
            'key'   => 'services',
            'label' => 'Services, with prices',
            'cells' => [
                'local'    => ['status' => 'free', 'note' => ''],
                'google'   => ['status' => 'yes', 'note' => ''],
                'linkedin' => ['status' => 'partial', 'note' => 'Service Pages belong to individual members, not company Pages'],
                'sa'       => ['status' => 'varies', 'note' => 'Cylex lists services on a free profile'],
            ],
        ],
        [
            'key'   => 'contact_map',
            'label' => 'Contact details and map',
            'cells' => [
                'local'    => ['status' => 'free', 'note' => ''],
                'google'   => ['status' => 'yes', 'note' => ''],
                'linkedin' => ['status' => 'partial', 'note' => 'Address and website; built for networking rather than walk-in customers'],
                'sa'       => ['status' => 'yes', 'note' => ''],
            ],
        ],
        [
            'key'   => 'photos',
            'label' => 'Photos',
            'cells' => [
                'local'    => ['status' => 'free', 'note' => 'A logo and up to {gallery} photos'],
                'google'   => ['status' => 'yes', 'note' => ''],
                'linkedin' => ['status' => 'yes', 'note' => ''],
                'sa'       => ['status' => 'varies', 'note' => 'Often more photos on a paid tier'],
            ],
        ],
        [
            'key'   => 'hours',
            'label' => 'Opening hours',
            'cells' => [
                'local'    => ['status' => 'free', 'note' => ''],
                'google'   => ['status' => 'yes', 'note' => ''],
                'linkedin' => ['status' => 'no', 'note' => ''],
                'sa'       => ['status' => 'yes', 'note' => ''],
            ],
        ],
        [
            'key'   => 'locations',
            'label' => 'Multiple locations',
            'cells' => [
                'local'    => ['status' => 'verified', 'note' => 'Up to {locations} more branches on one profile, each with its own address, phone, map pin and hours'],
                'google'   => ['status' => 'yes', 'note' => 'A separate profile for each location'],
                'linkedin' => ['status' => 'yes', 'note' => 'One Page can list several office locations'],
                'sa'       => ['status' => 'varies', 'note' => 'Usually a separate listing for each branch'],
            ],
        ],
        [
            'key'   => 'staff_profiles',
            'label' => 'Staff profiles on the business profile',
            'cells' => [
                'local'    => ['status' => 'verified', 'note' => 'Up to {team} people, each with a photo, role and expertise'],
                'google'   => ['status' => 'partial', 'note' => 'An individual practitioner can have their own separate profile, not one nested under the business'],
                'linkedin' => ['status' => 'partial', 'note' => 'Employees link their own personal profiles; the business cannot curate them'],
                'sa'       => ['status' => 'varies', 'note' => 'Medpages lists healthcare practitioners individually'],
            ],
        ],
        [
            'key'   => 'staff_qualifications',
            'label' => 'Staff qualifications',
            'cells' => [
                'local'    => ['status' => 'verified', 'note' => 'Shown as the business enters them; we check the business, not each qualification'],
                'google'   => ['status' => 'no', 'note' => 'A practitioner may put a title or degree in their profile name'],
                'linkedin' => ['status' => 'partial', 'note' => 'Self-reported on each person\'s own profile'],
                'sa'       => ['status' => 'varies', 'note' => 'Medpages shows them on its paid listing, for healthcare practitioners only'],
            ],
        ],
        [
            'key'   => 'staff_search',
            'label' => 'Search a staff member by name and find the business',
            'cells' => [
                'local'    => ['status' => 'verified', 'note' => 'Their name, role, qualification or expertise brings up the business'],
                'google'   => ['status' => 'partial', 'note' => 'Finds the practitioner\'s own profile, if they have one'],
                'linkedin' => ['status' => 'yes', 'note' => 'People search shows the person\'s employer'],
                'sa'       => ['status' => 'varies', 'note' => 'Medpages, for healthcare practitioners'],
            ],
        ],
        [
            'key'   => 'vacancies',
            'label' => 'Job vacancies',
            'cells' => [
                'local'    => ['status' => 'verified', 'note' => 'Set up so eligible vacancies can appear in Google\'s job search'],
                'google'   => ['status' => 'no', 'note' => 'Google\'s job search lists vacancies from other websites, not from the Business Profile'],
                'linkedin' => ['status' => 'yes', 'note' => 'Basic job posts, with paid promotion'],
                'sa'       => ['status' => 'varies', 'note' => 'Not a core feature of these sites'],
            ],
        ],
        [
            'key'   => 'service_requests',
            'label' => 'Service requests from the public',
            'cells' => [
                'local'    => ['status' => 'free_verified', 'note' => 'Anyone can post a request free; replying needs Verified Business'],
                'google'   => ['status' => 'no', 'note' => 'Local Services Ads take paid leads in some countries, not in South Africa'],
                'linkedin' => ['status' => 'partial', 'note' => 'Members can request proposals from people with a Service Page'],
                'sa'       => ['status' => 'varies', 'note' => 'Snupit: yes, and businesses pay per lead to respond'],
            ],
        ],
        [
            'key'   => 'document_verification',
            'label' => 'Document-checked verification',
            'cells' => [
                'local'    => ['status' => 'verified', 'note' => 'Company registration and the owner\'s ID, checked by a person'],
                'google'   => ['status' => 'partial', 'note' => 'Standard verification proves you control the business. Paid "Google Screened" and "Google Guaranteed" badges, with licence or background checks, exist in some countries, not South Africa'],
                'linkedin' => ['status' => 'partial', 'note' => 'Identity and workplace checks for members; no public check of business documents'],
                'sa'       => ['status' => 'varies', 'note' => ''],
            ],
        ],
        [
            'key'   => 'booking',
            'label' => 'Online booking',
            'cells' => [
                'local'    => ['status' => 'free', 'note' => 'A "Book online" button linking to your own booking page'],
                'google'   => ['status' => 'yes', 'note' => 'A booking link, or Reserve with Google through booking partners'],
                'linkedin' => ['status' => 'no', 'note' => ''],
                'sa'       => ['status' => 'varies', 'note' => 'Medpages takes appointment requests on its paid listing'],
            ],
        ],
        [
            'key'   => 'reviews',
            'label' => 'Customer reviews',
            'cells' => [
                'local'    => ['status' => 'free', 'note' => 'Email-confirmed, read by us before they go up, and you can reply'],
                'google'   => ['status' => 'yes', 'note' => ''],
                'linkedin' => ['status' => 'partial', 'note' => 'On members\' Service Pages'],
                'sa'       => ['status' => 'varies', 'note' => 'Cylex and Snupit have reviews'],
            ],
        ],
    ];
}
