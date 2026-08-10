<?php

namespace App\Controllers;

use App\Libraries\Mailer;
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
    private const SENT_MESSAGE = 'Thanks — your message is on its way. We usually reply within one business day.';

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

    public function index()
    {
        // Stamped here, checked in submit() — see the timing floor below.
        session()->set('contact_form_rendered_at', time());

        return view('directory/contact', [
            'old'    => session()->getFlashdata('old') ?? [],
            'errors' => session()->getFlashdata('errors') ?? [],
        ]);
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
            'name'    => $this->clean($this->request->getPost('name'), 120),
            'email'   => strtolower($this->clean($this->request->getPost('email'), 190)),
            'subject' => $this->clean($this->request->getPost('subject'), 150),
            'message' => $this->cleanText($this->request->getPost('message'), self::MAX_MESSAGE),
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
            log_message('error', 'Contact form submitted but directory.adminEmail is unset — message discarded.');

            return redirect()->to(base_url('contact'))->with('old', $post)
                ->with('error', 'Sorry — we could not send your message just now. Please try again later.');
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
                ->with('error', 'Sorry — we could not send your message just now. Please try again in a few minutes.');
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

    /**
     * Trim and flatten newlines. The flattening is header-injection defence:
     * name and email both reach mail headers, and a bare CR/LF there would let
     * a submitter add a Bcc.
     */
    private function clean($v, int $max): string
    {
        if (! is_scalar($v)) {
            return '';
        }

        return mb_substr(trim((string) preg_replace('/[\r\n\t]+/', ' ', (string) $v)), 0, $max);
    }

    /** Free text: keep the visitor's line breaks, cap the length. */
    private function cleanText($v, int $max): string
    {
        if (! is_scalar($v)) {
            return '';
        }

        return mb_substr(trim(str_replace("\r\n", "\n", (string) $v)), 0, $max);
    }
}
