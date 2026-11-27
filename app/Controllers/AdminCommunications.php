<?php

namespace App\Controllers;

use App\Controllers\Concerns\LocksDownDocumentCsp;
use App\Models\CampaignModel;
use App\Services\Communications\CommunicationsService;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * /admin/communications: emails to a chosen group of profiles, sent in daily
 * batches (owner, 21 Nov 2026). Thin: CommunicationsService has the rules.
 * Behind the admin filter with the rest of /admin (see Routes.php).
 */
class AdminCommunications extends BaseController
{
    use LocksDownDocumentCsp;

    private CommunicationsService $svc;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->svc = new CommunicationsService();
    }

    public function index()
    {
        return view('admin/communications/index', [
            'campaigns' => $this->svc->all(),
            'config'    => config('Campaigns'),
            'paused'    => $this->svc->pausedProfiles(),
        ]);
    }

    /** New campaign: pick a template, then the form with its wording. */
    public function create()
    {
        $kind   = (string) $this->request->getGet('kind');
        $values = $this->svc->defaults($kind);
        if ($values === null) {
            return view('admin/communications/pick', ['config' => config('Campaigns')]);
        }

        return $this->form(null, session('old') ?? $values);
    }

    public function store()
    {
        $result = $this->svc->save(null, $this->input());
        if (! $result['ok']) {
            return redirect()->to(base_url('admin/communications/new?kind=' . rawurlencode((string) $this->request->getPost('kind'))))
                ->with('old', $this->input())->with('errors', $result['errors'])
                ->with('error', $result['errors']['form'] ?? 'Please fix the marked fields.');
        }

        return redirect()->to(base_url('admin/communications/' . $result['id']))
            ->with('success', 'Draft saved. Preview it and send yourself a test before you start it.');
    }

    public function show(int $id)
    {
        $campaign = $this->campaign($id);
        $audience = CommunicationsService::audienceOf($campaign);
        $steps    = $this->svc->stepsOf($campaign);
        $values   = session('old') ?? ($campaign + $audience + ['steps' => $steps]);
        $draft    = $campaign['status'] === CampaignModel::STATUS_DRAFT;

        return $this->form($campaign, $values, [
            'steps' => $steps,
            'stats' => $this->svc->stats($id),
            'log'   => $this->svc->log($id),
            'count' => $draft ? $this->svc->audience()->count($audience) : null,
        ]);
    }

    public function update(int $id)
    {
        $this->campaign($id);
        $result = $this->svc->save($id, $this->input());
        if (! $result['ok']) {
            return redirect()->to(base_url('admin/communications/' . $id))
                ->with('old', $this->input())->with('errors', $result['errors'])
                ->with('error', $result['errors']['form'] ?? 'Please fix the marked fields.');
        }

        return redirect()->to(base_url('admin/communications/' . $id))->with('success', 'Saved.');
    }

    /** The email for one profile, in the admin shell: subject, who, and a link to the real HTML. */
    public function preview(int $id)
    {
        $campaign  = $this->campaign($id);
        $listingId = (int) $this->request->getGet('listing');
        $email     = $this->svc->preview($campaign, $listingId > 0 ? $listingId : null, max(1, (int) $this->request->getGet('step')));

        return view('admin/communications/preview', [
            'campaign'  => $campaign,
            'email'     => $email,
            'listingId' => $listingId,
        ]);
    }

    /**
     * The email exactly as an inbox gets it. Emails carry inline styles, which
     * the site policy refuses, so this one response gets its own locked policy
     * that allows inline styles and nothing else (no script, no requests).
     */
    public function raw(int $id)
    {
        $campaign  = $this->campaign($id);
        $listingId = (int) $this->request->getGet('listing');
        $email     = $this->svc->preview($campaign, $listingId > 0 ? $listingId : null, max(1, (int) $this->request->getGet('step')));
        if ($email === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $this->lockDownCspForDocument();
        $this->response->getCSP()->addStyleSrc("'unsafe-inline'");

        return $this->response->setHeader('Cache-Control', 'no-store')->setBody($email['html']);
    }

    public function test(int $id)
    {
        $this->campaign($id);
        $listingId = (int) $this->request->getPost('listing');
        $result    = $this->svc->sendTest($id, (string) $this->request->getPost('to'), $listingId > 0 ? $listingId : null);

        return redirect()->to(base_url('admin/communications/' . $id))->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function start(int $id)
    {
        $this->campaign($id);
        $result = $this->svc->start($id);

        return redirect()->to(base_url('admin/communications/' . $id))->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function status(int $id)
    {
        $this->campaign($id);
        $ok = match ((string) $this->request->getPost('action')) {
            'pause'  => $this->svc->pause($id, 'Paused by admin.'),
            'resume' => $this->svc->resume($id),
            'cancel' => $this->svc->cancel($id),
            default  => false,
        };

        return redirect()->to(base_url('admin/communications/' . $id))
            ->with($ok ? 'success' : 'error', $ok ? 'Done.' : 'That cannot be done in this state.');
    }

    /** Hold every campaign email to one profile: the owner replied, or asked for a change. */
    public function pauseProfile(int $listingId)
    {
        $this->svc->pauseProfile($listingId, (string) $this->request->getPost('note'));

        return redirect()->to($this->back())->with('success', 'Emails to that profile are paused.');
    }

    public function resumeProfile(int $listingId)
    {
        $this->svc->resumeProfile($listingId);

        return redirect()->to($this->back())->with('success', 'Emails to that profile can go again.');
    }

    // ------------------------------------------------------------ internals

    /** Back to an admin page only, never anywhere a posted value names. */
    private function back(): string
    {
        $back = (string) $this->request->getPost('back');

        return base_url(preg_match('~^admin/(communications(/\d+)?|edit/\d+)$~', $back) === 1 ? $back : 'admin/communications');
    }

    /** @return array<string,mixed> */
    private function campaign(int $id): array
    {
        $campaign = $this->svc->find($id);
        if ($campaign === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $campaign;
    }

    /** @return array<string,mixed> */
    private function input(): array
    {
        $post  = $this->request->getPost(['kind', 'name', 'subject', 'body', 'daily_limit', 'segment', 'site', 'group', 'province', 'source']);
        $input = array_map(static fn ($v) => is_string($v) ? $v : '', $post);

        // A sequence's steps: only the three known keys, only strings.
        $steps = $this->request->getPost('steps');
        if (is_array($steps)) {
            $input['steps'] = array_values(array_map(static fn ($st): array => is_array($st) ? array_map(
                static fn ($v): string => is_string($v) ? $v : '',
                array_intersect_key($st, ['subject' => 1, 'body' => 1, 'delay_days' => 1])
            ) : [], $steps));
        }

        return $input;
    }

    /** @param array<string,mixed> $values */
    private function form(?array $campaign, array $values, array $extra = [])
    {
        $audience = $this->svc->audience();

        return view('admin/communications/edit', $extra + [
            'campaign'  => $campaign,
            'values'    => $values,
            'errors'    => session('errors') ?? [],
            'config'    => config('Campaigns'),
            'groups'    => $audience->groups(),
            'provinces' => $audience->provinces(),
            'sources'   => $audience->sources(),
            'adminTo'   => config('Directory')->adminEmail(),
            'stats'     => null,
            'log'       => [],
            'steps'     => [],
            'count'     => $campaign === null ? $audience->count($values) : null,
        ]);
    }
}
