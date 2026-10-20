<?php

namespace App\Controllers;

use App\Libraries\Input;
use App\Libraries\Mailer;
use App\Models\DirectoryListingModel;
use App\Services\ComparisonService;
use App\Services\VerificationService;

/**
 * "Get in touch" for the directory.
 *
 * This used to be a footer link out to webscheduler.co.za/contact.html, which
 * is the marketing site's *Book a demo* form — the wrong thing to show someone
 * who is here to find a plumber or to fix a typo on their own profile. Visitors
 * now stay on this domain and get a form written for them.
 *
 * Mail goes out through the app's own `email.*` .env config, the same SMTP
 * mailbox the verification and manage emails use.
 */
class Contact extends BaseController
{
    /** Nobody reads the page and writes a message this fast. */
    private const MIN_FORM_SECONDS = 3;

    /** Free text cap, matching the listing description. */
    private const MAX_MESSAGE = 2000;

    /** What the visitor sees when it worked. */
    private const SENT_MESSAGE = 'Thanks, your message is on its way. We usually reply within one business day.';

    /**
     * The FAQ lives here rather than in Legal (which is documents) because it is
     * the other half of getting help: most of what it answers — editing a
     * profile, a link that expired, removing a listing — would otherwise arrive
     * as a message through the form below.
     */
    public function faq()
    {
        return view('directory/faq');
    }

    /**
     * Who we are. The directory's own page: the footer used to send "About" to
     * the marketing site's about.html, which describes the scheduling product.
     */
    public function company()
    {
        return view('directory/company', [
            'offered' => (new VerificationService())->isEnabled(),
        ]);
    }

    /**
     * What the Verified Business badge means.
     *
     * A page of its own rather than another FAQ entry because it is doing two
     * jobs at once: explaining to a visitor what the badge on a profile is
     * claiming, and explaining to an owner what they would be buying. It is also
     * the thing the badge itself links to, so it needs a stable, indexable URL.
     *
     * Lives here beside faq() for the same reason that does — it exists to
     * answer the question before it arrives through the contact form.
     */
    public function verified()
    {
        $svc = new VerificationService();

        return view('directory/verified', [
            'amount'  => $svc->monthlyAmount(),
            'offered' => $svc->isEnabled(),
        ]);
    }

    /**
     * WebScheduler Local next to Google Business Profile, LinkedIn and the
     * South African directories. The table itself is ComparisonService's.
     */
    public function compare()
    {
        $svc = new VerificationService();

        return view('directory/compare', [
            'table'   => (new ComparisonService())->forPage(),
            'amount'  => $svc->monthlyAmount(),
            'offered' => $svc->isEnabled(),
        ]);
    }

    /**
     * Guides and reports: the PDFs we publish for business owners, in one
     * place instead of one footer link each. The files sit in public/, so they
     * ship with the deploy bundle like any other asset; each entry's size is
     * read from disk so the page can't advertise a stale one. A file that is
     * missing is left off rather than linked to a 404.
     */
    public function resources()
    {
        $documents = [];
        foreach ([
            [
                'title'       => 'Local visibility playbook',
                'summary'     => 'How customers find local businesses today, and the practical steps that make a business easier to find, on search, on maps and in AI assistants.',
                'file'        => 'assets/playbook/webscheduler-local-visibility-playbook.pdf',
                'pages'       => 12,
                'tag'         => 'Guide',
            ],
            [
                'title'       => 'Verified Business brochure',
                'summary'     => 'Paying for visibility, or building a presence people can find? What a Verified profile adds, what we check, and every source behind the numbers.',
                'file'        => 'assets/documents/webscheduler-local-verified-business.pdf',
                'pages'       => 9,
                'tag'         => 'Brochure',
            ],
            [
                'title'       => '2027 local visibility outlook',
                'summary'     => 'Projected benchmarks for beauty and wellness, home services, health and professional practices, and fitness, and what a complete profile is likely to be worth. A forecast, labelled as one.',
                'file'        => 'assets/documents/webscheduler-local-2027-outlook.pdf',
                'pages'       => 13,
                'tag'         => 'Forecast',
            ],
            [
                'title'       => 'Hiring guide',
                'summary'     => 'Hire well, hire safely: how to write a job post people answer, choose fairly between applicants, check who they are with consent, and make an offer that keeps you on the right side of the law.',
                'file'        => 'assets/documents/webscheduler-local-hiring-guide.pdf',
                'pages'       => 15,
                'tag'         => 'Guide',
            ],
        ] as $doc) {
            $path = FCPATH . $doc['file'];
            if (! is_file($path)) {
                continue;
            }
            $doc['size'] = number_format(filesize($path) / 1048576, 1) . ' MB';
            // Same ?v=<filemtime> buster as the layout's CSS: a PDF replaced at
            // the same path gets a new URL, so Cloudflare needs no purge.
            $doc['version'] = filemtime($path);
            $documents[] = $doc;
        }

        return view('directory/resources', ['documents' => $documents]);
    }

    public function index()
    {
        // Stamped here, checked in submit() — see the timing floor below.
        session()->set('contact_form_rendered_at', time());

        return view('directory/contact', [
            // A failed submission's own input always wins over a pre-fill.
            'old'    => session()->getFlashdata('old') ?? $this->suggestEditPrefill(),
            'errors' => session()->getFlashdata('errors') ?? [],
        ]);
    }

    /**
     * Pre-fill for the "Suggest an edit" link on a profile's contact card.
     *
     * Only a slug is taken from the query string, and only a published
     * listing's is honoured — the subject and message are built here from our
     * own row, so the link cannot be used to put arbitrary words into this form
     * or to confirm that an unpublished listing exists.
     *
     * @return array<string,string>
     */
    private function suggestEditPrefill(): array
    {
        $slug = $this->request->getGet('listing');
        if (! is_string($slug) || $slug === '' || strlen($slug) > 255) {
            return [];
        }

        $listing = (new DirectoryListingModel())->findPublishedBySlug($slug);
        if ($listing === null) {
            return [];
        }

        return [
            // submit() caps the subject at 150; a 200-character business name
            // must not produce a pre-fill that silently loses its end.
            'subject' => mb_substr('Suggested edit: ' . $listing['display_name'], 0, 150),
            'message' => 'Profile: ' . base_url('directory/' . $listing['slug']) . "\n\nWhat needs changing:\n",
        ];
    }

    public function submit()
    {
        // Honeypot, same field name as the signup form. (The framework's own
        // Honeypot filter runs on every POST as well — two traps under
        // different names cost nothing and catch more than one does.)
        if (trim((string) $this->request->getPost('company_website_hp')) !== '') {
            return $this->fakeSuccess();
        }

        // Timing floor. A bot that posts the instant it loads the page — or
        // replays a captured POST with no GET first — trips this. Reported as
        // success so an attacker can't tune around it.
        $renderedAt = (int) session('contact_form_rendered_at');
        if ($renderedAt === 0 || (time() - $renderedAt) < self::MIN_FORM_SECONDS) {
            return $this->fakeSuccess();
        }

        $post = [
            'name'    => Input::line($this->request->getPost('name'), 120),
            'email'   => strtolower(Input::line($this->request->getPost('email'), 190)),
            'subject' => Input::line($this->request->getPost('subject'), 150),
            'message' => Input::text($this->request->getPost('message'), self::MAX_MESSAGE),
        ];

        $errors = $this->validateInput($post);
        if ($errors !== []) {
            return redirect()->to(base_url('contact'))
                ->with('errors', $errors)
                ->with('old', $post)
                ->with('error', 'Please correct the highlighted fields.');
        }

        // This endpoint sends mail on a stranger's say-so, so throttle both the
        // sender's IP and the address they claim. The || short-circuits so a
        // blocked IP does not also burn the address's budget — as in
        // Listing::store() and Manage::request().
        $throttler = service('throttler');
        $ipKey     = 'contact-ip-' . md5((string) $this->request->getIPAddress());
        $mailKey   = 'contact-from-' . md5($post['email']);

        if ($throttler->check($ipKey, 3, HOUR) === false
            || $throttler->check($mailKey, 2, HOUR) === false) {
            return redirect()->to(base_url('contact'))->with('old', $post)
                ->with('error', 'Too many messages. Please wait a little while and try again.');
        }

        $admin = config('Directory')->adminEmail();
        if ($admin === '') {
            // Nowhere to send it. Say so rather than showing a success page for
            // a message that went in the bin — the visitor can still email us
            // some other way, but only if they know it failed.
            log_message('error', 'Contact form submitted but directory.adminEmail is unset, message discarded.');

            return redirect()->to(base_url('contact'))->with('old', $post)
                ->with('error', 'Sorry, we could not send your message just now. Please try again later.');
        }

        $body = view('emails/contact', [
            'site'    => config('Directory')->siteName(),
            'name'    => $post['name'],
            'email'   => $post['email'],
            'subject' => $post['subject'],
            'message' => $post['message'],
            'ip'      => (string) $this->request->getIPAddress(),
        ]);

        // Reply-To is the visitor; From stays our own mailbox, because a
        // visitor-supplied From fails SPF/DMARC at the receiving end.
        $sent = (new Mailer())->send(
            $admin,
            'Contact form: ' . ($post['subject'] !== '' ? $post['subject'] : 'message from ' . $post['name']),
            $body,
            $post['email']
        );

        if (! $sent) {
            return redirect()->to(base_url('contact'))->with('old', $post)
                ->with('error', 'Sorry, we could not send your message just now. Please try again in a few minutes.');
        }

        session()->remove('contact_form_rendered_at');

        return redirect()->to(base_url('contact'))->with('success', self::SENT_MESSAGE);
    }

    /**
     * What a caught bot sees: the same page and wording a real submission gets.
     * Telling it which trap it hit is free tuning information.
     */
    private function fakeSuccess()
    {
        return redirect()->to(base_url('contact'))->with('success', self::SENT_MESSAGE);
    }

    /**
     * @param array<string,string> $post
     * @return array<string,string>
     */
    private function validateInput(array $post): array
    {
        $errors = [];

        if ($post['name'] === '') {
            $errors['name'] = 'Please tell us your name.';
        }
        if (! filter_var($post['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address so we can reply.';
        }
        if (mb_strlen($post['message']) < 10) {
            $errors['message'] = 'Please tell us a little more about what you need.';
        }

        return $errors;
    }
}
