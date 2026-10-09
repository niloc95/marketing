<?= $this->extend('layouts/public') ?>

<?php
$siteName  = config('Directory')->siteName();
$canonical = base_url('faq');
$admin     = config('Directory')->adminEmail();
$gallery   = \App\Controllers\Listing::GALLERY_MAX;
$team      = \App\Services\TeamMemberService::MAX_MEMBERS;
$locations = \App\Services\PracticeLocationService::MAX_LOCATIONS;

/**
 * One source of truth for the page.
 *
 * The same array renders the visible questions and builds the FAQPage JSON-LD
 * below, so the structured data can never drift from the copy — which is what
 * Google penalises. Answers are HTML (they carry links); the schema strips the
 * tags, because a rich result must be plain text.
 *
 * Everything here is checked against behaviour, not aspiration: the 48-hour and
 * one-hour links are Directory::$verifyTtl / $manageTtl, and the photo, team
 * and location caps are read from the constants that enforce them.
 *
 * The word "verified" does two jobs in this codebase and the copy below is
 * careful to keep them apart. `is_verified` on a listing means the owner clicked
 * the emailed confirmation link — every published profile has that, and it says
 * nothing about the business. The paid <strong>Verified Business</strong> badge,
 * driven by `verified_until`, means someone read a company registration document
 * and an owner's ID. Answers that blur the two would be worse than saying
 * nothing, because the badge is the thing people are being asked to trust.
 *
 * An optional 'id' on a question becomes the anchor on the rendered <details>;
 * directory/show.php links the badge to #verified.
 */
$groups = [
    [
        'heading' => 'Using ' . esc($siteName),
        'faqs'    => [
            [
                'q' => 'Is ' . esc($siteName) . ' free to use?',
                'a' => 'Yes. Searching is free, and so is a business profile for a business with a South African address: no monthly fee, no subscription, no obligation. Two things are paid, and neither affects a South African profile: the optional <strong>Verified Business</strong> badge, described further down, and an <strong>International Profile</strong> subscription for a business based outside South Africa. Nothing about a South African profile depends on buying anything. The badge does add features on top: your other locations, your team, and posting job vacancies and replying to requests on the Jobs board.',
            ],
            [
                'q' => 'What is ' . esc($siteName) . '?',
                'a' => 'A local business discovery and visibility platform built for South Africa. People use it to discover businesses, services, locations, professionals and opportunities near them. Businesses use it to build their local online presence: a profile that shows what they do, the services they provide, where they operate, how to reach them and, with a Verified Business profile, their other locations, their people and their job vacancies. It is more than a business listing.',
            ],
            [
                'q' => 'Do I need an account to search?',
                'a' => 'No. There is no signup and no password anywhere on the site. Just <a href="' . base_url('directory') . '">browse</a> or search.',
            ],
            [
                'q' => 'How do I find someone near me?',
                'a' => 'Search by name or keyword, then narrow it down by category, province and town. On the <a href="' . base_url('directory') . '">browse page</a> you can also press <strong>Use my location</strong> to sort by distance. Your browser asks permission first, you can refuse, and we never store where you are.',
            ],
            [
                'q' => 'How is the order of search results decided?',
                'a' => 'If you have shared your location, by distance, nearest first. Otherwise it is a small number of businesses we have picked out by hand, then the most complete profiles, then the most recent. &ldquo;Complete&rdquo; means the things you would actually want to know: a category, an address and a map pin, a phone number, a website or another way to reach you online (WhatsApp or a social page, no website needed), a description, photos, a list of services and opening hours. Every one of those is free to fill in on any profile, and nothing that costs money counts towards it. The <strong>Verified Business</strong> badge, the team panel and the extra branch locations are all worth exactly zero. So are reviews and star ratings: they are there for you to read, not to sort by. We worked out how the order should work before we worked out what to sell, and we are not going to sell it. Every profile goes live as soon as you confirm your email, however much is filled in; a profile with very little on it is simply not offered to search engines like Google until it has a bit more, and it is picked up automatically once it does.',
            ],
            [
                'q' => 'Are the businesses here checked?',
                'a' => 'Some more than others, and it is worth being precise. Before <em>any</em> profile appears we confirm that whoever submitted it can receive email at the address they gave. That is all, and it says nothing about the business itself. A profile carrying a green <strong>Verified Business</strong> badge has been through more: we have seen its company registration document and the owner\'s ID. Even then we do not check qualifications, licences or insurance. Please satisfy yourself as you would with any supplier, and <a href="' . base_url('contact') . '">tell us</a> if a profile looks wrong.',
            ],
            [
                'id' => 'reviews',
                'q'  => 'How do reviews work?',
                'a'  => 'Anyone who has used a business can review it on its profile: a star rating from one to five and a few words about how it went. You confirm your email address first, and a person here reads every review before it is published. We publish honest reviews whether they are good or bad, and we do not publish reviews that are fake, from someone who was not a customer, abusive, or that share someone\'s private details. Your email address is never shown; the review appears under your first name and surname initial. The business can reply publicly, and anyone can report a review they think breaks our <a href="' . base_url('terms') . '#reviews">terms</a>. Businesses cannot pay to remove a review, and the star rating does not change where a business appears in search results.',
            ],
            [
                'id' => 'verified',
                'q'  => 'What does the Verified Business badge actually mean?',
                'a'  => 'That we asked the business for two documents and a person looked at them: a company registration document, and an ID document for the owner. We checked that the business is really registered and that the person running the profile is really its owner. It is a check on <em>identity</em>, not on quality. It is not a rating, a recommendation, or any statement about the work. Businesses without the badge are not suspect; most simply have not applied. <a href="' . base_url('verified') . '">Full details of what we check</a>.',
            ],
        ],
    ],
    [
        'heading' => 'Your business profile',
        'faqs'    => [
            [
                'q' => 'Does it cost anything to create a business profile?',
                'a' => 'Not if your business is in South Africa. Your business profile is free and stays free: no monthly fee, no subscription, no obligation, no commission and no card required. Nothing about your profile is held back or downgraded if you never pay us anything. There is one optional paid extra, the <strong>Verified Business</strong> badge, which adds a trust mark plus your locations, your people and your job vacancies. See below.',
            ],
            [
                'q' => 'What can a free business profile include?',
                'a' => 'Your business information and description, your services (with prices if you want to show them), your phone number, website and social links, your address with a map pin, your opening hours, and a logo plus up to ' . (int) $gallery . ' photos. Customers can review your business, and you can reply publicly to every review. You can edit any of it at any time.',
            ],
            [
                'q' => 'Do I need a website to create a profile?',
                'a' => 'No. Your profile has its own address, ' . esc(preg_replace('#^https?://#i', '', base_url('directory'))) . '/your-business, and it already holds what most small business websites hold: your services and prices, opening hours, photos, a map, WhatsApp and phone buttons, and reviews. Put that address anywhere you would put a website: your Google Business Profile, Facebook page, email signature and business cards. If you get a website later, add it to your profile and we will link to it.',
            ],
            [
                'q' => 'Can I add more than one location?',
                'a' => 'Yes, with a <a href="' . base_url('verified') . '">Verified Business</a> profile. You can add up to ' . (int) $locations . ' more branches, practices or offices, each with its own address, map pin, phone number and opening hours, so customers find the right business in the right location. A free profile shows your main address.',
            ],
            [
                'q' => 'Can I show my team and their qualifications?',
                'a' => 'Yes, with a <a href="' . base_url('verified') . '">Verified Business</a> profile. People want to know who they are dealing with, so you can show up to ' . (int) $team . ' team members with a photo, their role, their qualifications and their areas of expertise. A search for one of your people by name, or for something only one of them does, then brings up your business too. Qualifications are as the business states them; we do not check them.',
            ],
            [
                'q' => 'How do jobs work?',
                'a' => 'The <a href="' . base_url('jobs') . '">Jobs board</a> brings vacancies and requests for work together. Anyone can post a request for a service, free. A Verified Business can post job vacancies straight from its profile and reply to requests for its kind of service, and is the first we alert when a matching request comes in.',
            ],
            [
                'q' => 'My business is not in South Africa. Can I still create a profile?',
                'a' => 'Yes, on a paid <strong>International Profile</strong> subscription. ' . esc($siteName) . ' is built for South African businesses. The search, the categories and the province pages are all built around South African places, so a South African business profile is free and always will be. A business based anywhere else is welcome, and its profile goes live once the monthly subscription is paid. You fill in the same form, confirm your email the same way, and your profile is saved either way; it publishes when the first payment clears. Cancel any time: the profile stays live until the paid period ends and then comes down, and nothing you entered is deleted, if you subscribe again later it comes back exactly as it was. Payment is taken in South African rand.',
            ],
            [
                'q' => 'How much is the Verified Business badge, and what do I get?',
                'a' => 'R' . esc((new App\Services\DirectorySettings())->badgePrice()) . ' a month. Your profile and every search result you appear in carry a green “Verified Business” badge, and the badge links to an explanation of what we checked. It also lets you add your other locations, show your team by name with their qualifications and expertise, and post job vacancies and reply to requests on the Jobs board. Once your team is on your profile a search for one of your people, or for something only one of them does, brings up your business too. What it does <em>not</em> do is move you up the search results: the order is the same whether you pay us or not. We sell the check, not the ranking.',
            ],
            [
                'q' => 'How do I get verified, and when do I pay?',
                'a' => 'Send us two documents (your company registration document and an ID document for the owner) either from the optional section on the <a href="' . base_url('add-profile') . '">business profile</a> form or, at any time afterwards, from <a href="' . base_url('manage') . '">manage your profile</a>. We review them, usually within two working days, and email you either way. <strong>You are only asked to pay after we have approved you.</strong> If we cannot verify your business, nothing is charged and you are told exactly why, so you can send better documents.',
            ],
            [
                'q' => 'What happens to my registration document and ID?',
                'a' => 'They are stored privately on our server, outside anything the web can reach, and are never shown on your profile or given to anyone else. Only our review team opens them. We keep them while your badge is active so we do not have to ask again if you renew, and we delete them when you tell us to, <a href="' . base_url('contact') . '">just ask</a>. Sending a new set replaces the old one immediately. Our <a href="' . base_url('privacy') . '">privacy policy</a> covers this in full.',
            ],
            [
                'q' => 'Can I cancel the badge?',
                'a' => 'Any time, with one button on <a href="' . base_url('manage') . '">your dashboard</a>: no email, no waiting for us. The badge stays up for the month you have already paid for and then quietly comes down. Your business profile is completely unaffected: it stays published exactly as it is.',
            ],
            [
                'q' => 'How do I add my business?',
                'a' => 'Fill in the <a href="' . base_url('add-profile') . '">Create your free business profile</a> form. We email you a link; clicking it confirms the address and publishes your profile straight away. It takes a couple of minutes.',
            ],
            [
                'q' => 'I submitted my business but it is not showing. Why?',
                'a' => 'Almost always the verification email. A profile stays unpublished until you click the link we sent, and that link is good for 48 hours. Check your spam folder. If it has expired or never arrived, just <a href="' . base_url('add-profile') . '">submit the form again</a> with the same email address and we will send a fresh one.',
            ],
            [
                'q' => 'Can I add a logo and photos?',
                'a' => 'Yes, one logo and up to ' . (int) $gallery . ' photographs of your work or premises. You can add or replace them at any time from <a href="' . base_url('manage') . '">manage your profile</a>.',
            ],
            [
                'q' => 'What appears publicly on my profile?',
                'a' => 'Everything you enter (business name, description, phone number, address, trading hours, website and social links) is published and can be indexed by search engines. The one exception is <strong>your email address, which we never display</strong>; it is only how we recognise you as the owner. Do not put anything in the form that you would not want found publicly.',
            ],
        ],
    ],
    [
        'heading' => 'Changing or removing your profile',
        'faqs'    => [
            [
                'q' => 'How do I edit my profile? Do I need a password?',
                'a' => 'No password. There is no account to create. Go to <a href="' . base_url('manage') . '">manage your profile</a>, enter the email address on your profile, and we email you a link that signs you in. The link works once and lasts an hour.',
            ],
            [
                'q' => 'My edit link expired.',
                'a' => 'Request another from <a href="' . base_url('manage') . '">manage your profile</a>. They are short lived on purpose: an old link in an inbox should not still open your profile months later.',
            ],
            [
                'q' => 'Can I change the email address on my profile?',
                'a' => 'Not from the edit form, because that address is what proves the profile is yours. Letting it be changed from inside the session it unlocks would be a way to take a profile over. <a href="' . base_url('contact') . '">Send us a message</a> from the current address and we will move it for you.',
            ],
            [
                'q' => 'How do I remove my business profile?',
                'a' => 'Ask us and we will take it down. <a href="' . base_url('contact') . '">Message us</a> from the email address on the profile so we know the request is genuine.',
            ],
        ],
    ],
    [
        'heading' => 'Help, privacy and who we are',
        'faqs'    => [
            [
                'q' => 'How do I get help?',
                'a' => 'Use the <a href="' . base_url('contact') . '">contact form</a>'
                    . ($admin !== '' ? ', or email us at <a href="mailto:' . esc($admin, 'attr') . '">' . esc($admin) . '</a>' : '')
                    . '. We usually reply within one business day. If your question is about editing or removing your own profile, the two answers above will be faster than waiting for us.',
            ],
            [
                'q' => 'What do you do with my personal information?',
                'a' => 'Only what is needed to run ' . esc($siteName) . ' and reply to you. Our <a href="' . base_url('privacy') . '">privacy policy</a> sets out exactly what we collect and why, in line with POPIA. We do not sell personal information and we do not share it for advertising.',
            ],
            [
                'q' => 'What emails will you send me?',
                'a' => 'The ones your profile needs (verification, edit links and, if you buy the Verified Business badge, billing notices) plus a monthly report on your own profile: how many views it got and where your leads came from. That report is ticked on by default when you sign up, and you can untick it there, switch it off in <a href="' . base_url('manage') . '">Manage your profile</a>, or use the unsubscribe link in any report. Doing so is free and changes nothing about your profile. We do not send you news, tips or offers about other products, and we never sell or share your address for advertising.',
            ],
            [
                'q' => 'Is this the same thing as WebScheduler, the booking software?',
                'a' => 'Related, but separate, and worth not confusing. <strong>' . esc($siteName) . '</strong> is this local business discovery platform: people discover your business, and your business profile is free. <strong>WebScheduler</strong> is our paid appointment scheduling software, which runs on your own infrastructure. You can read about it at <a href="https://webscheduler.co.za/">webscheduler.co.za</a>. A profile here does not sign you up for it, and you never have to buy anything to keep your profile.',
            ],
        ],
    ],
];

// FAQPage schema, built from the same array. Google stopped rendering FAQ rich
// results in August 2023 (authoritative government and health sites only), so
// this earns no snippet — it stays because the markup is simply true of this
// page, it costs nothing, and other consumers still read it. The templated
// version that landing/province pages used to generate was removed precisely
// because neither of those held there.
$faqs = [];
foreach ($groups as $group) {
    foreach ($group['faqs'] as $faq) {
        $faqs[] = $faq;
    }
}
$schema = schema_page(
    [schema_faq_page($faqs, $canonical)],
    $canonical,
    'WebPage',
    'Frequently asked questions'
);
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Frequently asked questions | ' . $siteName,
    'description' => 'Answers about ' . $siteName . ': your free business profile, locations, team members, jobs, what the Verified Business badge means, and how to get help.',
    'canonical'   => $canonical,
    'schema'      => $schema,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero">
    <div class="container">
        <h1 class="text-2xl sm:text-3xl">Frequently asked questions</h1>
        <p class="mt-2 text-sm text-white/80">What it costs, what your business profile can include, and how to reach us.</p>
    </div>
</section>

<section class="section">
    <div class="container prose-legal page-flow">
        <div class="panel">
            <?php foreach ($groups as $group): ?>
                <div class="faq-group">
                    <h2><?= esc($group['heading']) ?></h2>
                    <?php foreach ($group['faqs'] as $faq): ?>
                        <?php // `open` when linked to directly, so the badge's #verified
                              // link lands on a visible answer rather than a closed row. ?>
                        <details class="faq-item"<?= isset($faq['id']) ? ' id="' . esc($faq['id'], 'attr') . '" open' : '' ?>>
                            <summary><?= esc($faq['q']) ?></summary>
                            <?php // Answers are authored above and contain only our own
                                  // anchors and <strong> — no user input reaches here. ?>
                            <div class="faq-answer"><?= $faq['a'] ?></div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="panel mt-6 text-center">
            <h2 class="mb-2 text-lg font-bold text-slate-900 dark:text-white">Still stuck?</h2>
            <p class="mb-5 text-sm text-slate-600 dark:text-slate-400">Ask us directly. We read every message.</p>
            <a class="btn btn-accent" href="<?= base_url('contact') ?>">Contact us</a>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
