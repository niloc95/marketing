<?php
/**
 * The FAQ as local.webscheduler.app answers it, included by faq.php in place
 * of co.za's groups. Same shape ([heading, faqs[q, a]]), same rules: answers
 * are authored here, our own anchors and <strong> only, and every one must
 * stay true of what the code does (Plus: VerificationService, the gates in
 * listing_has_premium(); billing: Legal terms section 4 on .app).
 *
 * The "Changing or removing your profile" group is the same on both sites, so
 * it is passed in ($shared) rather than written twice.
 *
 * @var string      $siteName
 * @var string      $admin
 * @var int         $gallery
 * @var int         $team
 * @var int         $locations
 * @var array       $shared    co.za's group of the same name
 * @var string|null $plusUsd   Plus's dollar price, or null while it is not on sale
 */
$plusPrice = $plusUsd !== null ? esc(money($plusUsd, 'USD')) . ' a month' : 'a monthly fee';
$zaSite    = esc(site_url_for(\Config\Sites::ZA), 'attr');

return [
    [
        'heading' => 'Using ' . esc($siteName),
        'faqs'    => [
            [
                'q' => 'Is ' . esc($siteName) . ' free to use?',
                'a' => 'Yes. Discovering businesses is free, and so is a business profile: no monthly fee, no subscription, no obligation. There is one optional upgrade, <strong>Plus</strong> (' . $plusPrice . '), which adds your other locations, your team and the Jobs board, and a once off profile setup service if you would rather we filled your profile in for you. Nothing about a free profile depends on buying either.',
            ],
            [
                'q' => 'Which countries is it for?',
                'a' => 'Businesses outside South Africa. The United Kingdom, the United States, Australia, New Zealand, Namibia, Botswana, Kenya and Nigeria have their own country and region pages and address lookup, and a business anywhere else can add a profile too. Businesses in South Africa are listed on our sister site, <a href="' . $zaSite . '">local.webscheduler.co.za</a>, and if you add one here we take you there with what you typed.',
            ],
            [
                'q' => 'How do I add my business?',
                'a' => 'Fill in the <a href="' . base_url('add-profile') . '">Add your business</a> form. We email you a link; clicking it confirms the address and publishes your profile straight away. It takes a couple of minutes.',
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
                'a' => 'Everything you enter (business name, description, phone number, address, opening hours, website and social links) is published and can be indexed by search engines. The one exception is <strong>your email address, which we never display</strong>; it is only how we recognise you as the owner. Do not put anything in the form that you would not want found publicly.',
            ],
        ],
    ],
    [
        'heading' => 'Plus',
        'faqs'    => [
            [
                'id' => 'plus',
                'q'  => 'What is Plus?',
                'a'  => 'An optional upgrade for your profile. With Plus you can add up to ' . (int) $locations . ' more branches or offices, each with its own address, map pin, phone and hours; show up to ' . (int) $team . ' team members with a photo and what they do; post job vacancies and reply to requests for your kind of service on the <a href="' . base_url('jobs') . '">Jobs board</a>; and your profile carries a Plus label. Add it from <a href="' . base_url('manage') . '">your dashboard</a> once your profile is live.',
            ],
            [
                'q' => 'What does Plus cost, and in which currency?',
                'a' => 'Plus is ' . $plusPrice . '. <strong>WebScheduler is a South African company, so Plus is billed in South African rand</strong> through PayFast, and your bank converts the charge to your own currency. The amount on your statement may therefore differ slightly from the dollar price, and some banks add a foreign transaction fee. The rand amount is fixed for your subscription when it starts.',
            ],
            [
                'q' => 'Does Plus mean my business has been checked?',
                'a' => 'No. We do not review any documents for Plus, and the Plus label says only that the business has Plus. It is not an endorsement or a recommendation.',
            ],
            [
                'q' => 'Does Plus move me up the discovery results?',
                'a' => 'No. The order is the same whether you pay us or not. Plus gives you more to show, not a better place in the list.',
            ],
            [
                'q' => 'Can I cancel Plus?',
                'a' => 'Any time, with one button on <a href="' . base_url('manage') . '">your dashboard</a>. Plus stays on for the month you have already paid for, and then its features switch off. Your profile stays published, and nothing you added is deleted: your branches and team come back if you start Plus again.',
            ],
        ],
    ],
    $shared,
    [
        'heading' => 'Help, privacy and who we are',
        'faqs'    => [
            [
                'q' => 'How do I get help?',
                'a' => 'Use the <a href="' . base_url('contact') . '">contact form</a>'
                    . ($admin !== '' ? ', or email us at <a href="mailto:' . esc($admin, 'attr') . '">' . esc($admin) . '</a>' : '')
                    . '. We usually reply within one business day.',
            ],
            [
                'q' => 'Who runs ' . esc($siteName) . '?',
                'a' => 'WebScheduler (Pty) Ltd, a company registered in South Africa. Our <a href="' . base_url('company') . '">about page</a> says more.',
            ],
            [
                'q' => 'What do you do with my personal information?',
                'a' => 'Only what is needed to run ' . esc($siteName) . ' and reply to you. Our <a href="' . base_url('privacy') . '">privacy policy</a> sets out exactly what we collect and why, and your rights under the data protection law of your country. We do not sell personal information and we do not share it for advertising.',
            ],
            [
                'q' => 'What emails will you send me?',
                'a' => 'The ones your profile needs (verification, edit links and, if you buy Plus, billing notices) plus a monthly report on your own profile: how many views it got and where your leads came from. That report is ticked on by default when you sign up, and you can untick it there, switch it off in <a href="' . base_url('manage') . '">Manage your profile</a>, or use the unsubscribe link in any report. We do not send you news, tips or offers about other products, and we never sell or share your address for advertising.',
            ],
        ],
    ],
];
