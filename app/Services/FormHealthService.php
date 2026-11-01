<?php

namespace App\Services;

use App\Libraries\Mailer;
use App\Services\Search\DiscoveryEventService;
use CodeIgniter\HTTP\IncomingRequest;
use Config\Sites;
use Throwable;

/**
 * What happened to every form submission: saved, refused, throttled, lost.
 * The only writer of xs_directory_form_events.
 *
 * Added after 14 to 27 Oct 2026, when every failed signup's redirect landed
 * on a 404 and about 18 would-be businesses were lost before a customer told
 * us. Nothing recorded what a submitted form did, so nothing could notice.
 *
 * Rows come from the server (App\Filters\FormOutcome for every POST in
 * Config\Forms, CsrfGuard, BotTrap, Errors::notFound, the exception handler)
 * and from the browser (POST /forms/event: submit pressed, blocked by a
 * required field, script error). Comparing "pressed" with "arrived" is what
 * shows a submit that never reached us: an expired page, a Cloudflare rule,
 * the network.
 *
 * Nothing that identifies anyone is kept: no IP, email, session, cookie or
 * typed value. `detail` is field keys, an exception class or a script error
 * (Privacy, "Form diagnostics", 180 days). Writes never throw: tracking must
 * never cost anyone their submission.
 *
 * Alerts: a lost submission, an exception or a failed send emails the admin
 * at once (one email per form and outcome per hour), as do 5 CSRF failures on
 * one form in an hour; forms:digest sends the nightly summary.
 * Tests: FormHealthServiceTest, FormFailureLandingTest, AdminFormsTest.
 */
class FormHealthService
{
    public const KEEP_DAYS = 180;

    public const SERVER_OUTCOMES = ['ok', 'invalid', 'throttled', 'bot', 'csrf', 'expired', 'unavailable', 'send_failed', 'exception', 'lost'];

    public const BROWSER_OUTCOMES = ['attempt', 'blocked', 'js_error'];

    /** Outcomes that email the admin at once. */
    public const SERIOUS = ['lost', 'exception', 'send_failed'];

    /** CSRF failures on one form in an hour that email the admin. */
    public const CSRF_ALERT_AT = 5;

    public const TABLE = 'xs_directory_form_events';

    private const LOST_CACHE = 'form-health-lost-24h';

    /** @var array{0:int,1:string}|null request object id, outcome */
    private static ?array $tag = null;

    /**
     * Say what this request's form submission really was, when the response
     * alone would read wrong: a fake success for a bot, a throttle, a mail
     * that did not go. Read once by FormOutcome. Bound to the request object,
     * so a tag can never leak into another request (or test).
     */
    public static function tag(string $outcome): void
    {
        self::$tag = [spl_object_id(service('request')), $outcome];
    }

    public static function takeTag(): ?string
    {
        $tag       = self::$tag;
        self::$tag = null;

        return $tag !== null && $tag[0] === spl_object_id(service('request')) ? $tag[1] : null;
    }

    /**
     * Record one outcome. Never throws.
     *
     * @param string $source 'server' or 'browser'
     */
    public function record(string $form, string $outcome, ?string $detail = null, string $source = 'server', ?string $path = null): void
    {
        $allowed = $source === 'browser' ? self::BROWSER_OUTCOMES : self::SERVER_OUTCOMES;
        if (! config('Forms')->enabled || ! in_array($outcome, $allowed, true) || ! isset(config('Forms')->forms[$form])) {
            return;
        }

        try {
            $request = service('request');
            // A flood of POSTs must not become a flood of rows.
            if ($request instanceof IncomingRequest
                && service('throttler')->check('form-event-' . md5((string) $request->getIPAddress()), 120, MINUTE) === false) {
                return;
            }

            $this->insert([
                'form'    => $form,
                'outcome' => $outcome,
                'source'  => $source,
                'detail'  => $detail === null ? null : self::clean($detail, 255),
                'path'    => self::cleanPath($path ?? ($request instanceof IncomingRequest ? $request->getPath() : '')),
            ]);

            if ($source === 'server') {
                $this->alertIfSerious($form, $outcome, $detail);
            }
        } catch (Throwable $e) {
            log_message('warning', 'Form event not recorded: ' . Mailer::oneLine($e->getMessage()));
        }
    }

    /**
     * A failed submission that cost someone their typing, or worse, emails
     * the admin straight away: one email per site, form and outcome per hour.
     * Every alert is itself recorded (source 'alert'), delivered or not, so
     * /admin/forms can show whether the email went.
     */
    public function alertIfSerious(string $form, string $outcome, ?string $detail): bool
    {
        $site = service('site')->key();
        if ($outcome === 'csrf') {
            if ($this->countSince($form, 'csrf', date('Y-m-d H:i:s', time() - HOUR), $site) < self::CSRF_ALERT_AT) {
                return false;
            }
        } elseif (! in_array($outcome, self::SERIOUS, true)) {
            return false;
        }

        $key = 'form-alert-' . md5($site . '|' . $form . '|' . $outcome);
        if (cache()->get($key) !== null) {
            return false;
        }
        cache()->save($key, 1, HOUR);
        cache()->delete(self::LOST_CACHE);

        $label = config('Forms')->label($form);
        $what  = match ($outcome) {
            'lost'        => 'A submission of this form landed on an error page, so whatever the person typed was lost.',
            'exception'   => 'The server crashed while handling this form. The person saw an error page.',
            'send_failed' => 'This form could not send its email. The person was told to try again.',
            default       => self::CSRF_ALERT_AT . ' or more people sent this form from a page that had expired in the last hour.',
        };

        $admin = config('Directory')->adminEmail();
        $sent  = $admin !== '' && (new Mailer())->notice($admin, 'Form problem: ' . $label . ' (' . self::outcomeLabel($outcome) . ')', [
            'heading'    => 'Form problem on ' . $label,
            'paragraphs' => array_values(array_filter([
                $what,
                'Site: ' . service('site')->host() . '. Time: ' . date('j M Y H:i') . ' UTC.',
                $detail !== null && $detail !== '' ? 'Detail: ' . self::clean($detail, 255) : null,
            ])),
            'button'   => ['Open form health', site_url_for(Sites::ZA, 'admin/forms' . ($site === Sites::APP ? '?site=app' : ''))],
            'footnote' => 'You get at most one of these an hour for each form and problem. A summary of every form comes each morning.',
        ]);

        $this->insert([
            'form'    => $form,
            'outcome' => $outcome,
            'source'  => 'alert',
            'detail'  => $sent ? 'delivered' : 'not delivered',
            'path'    => '',
        ]);

        return $sent;
    }

    /**
     * Lost submissions and crashes in the last 24 hours, both sites: the
     * admin bar pill and the admin home banner. Cached for a minute.
     *
     * @return array{count:int,last:?string,form:?string}
     */
    public function recentLost(): array
    {
        $cached = cache()->get(self::LOST_CACHE);
        if (is_array($cached)) {
            return $cached;
        }

        $out = ['count' => 0, 'last' => null, 'form' => null];
        try {
            $rows = db_connect()->table(self::TABLE)
                ->select('form, created_at')
                ->where('source', 'server')
                ->whereIn('outcome', ['lost', 'exception'])
                ->where('created_at >=', date('Y-m-d H:i:s', time() - DAY))
                ->orderBy('id', 'DESC')
                ->get()->getResultArray();
            $out = [
                'count' => count($rows),
                'last'  => $rows[0]['created_at'] ?? null,
                'form'  => $rows[0]['form'] ?? null,
            ];
        } catch (Throwable) {
            // The table may not exist yet during a release; show nothing.
        }
        cache()->save(self::LOST_CACHE, $out, MINUTE);

        return $out;
    }

    /**
     * Everything /admin/forms shows, in one call.
     *
     * @return array<string,mixed>
     */
    public function dashboard(string $site, int $days, ?string $form = null, ?string $outcome = null): array
    {
        $now      = time();
        $from     = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days', $now));
        $to       = date('Y-m-d H:i:s', $now);
        $prevFrom = date('Y-m-d 00:00:00', strtotime('-' . (2 * $days - 1) . ' days', $now));

        $counts   = $this->counts($site, $from, $to, $form);
        $previous = $this->counts($site, $prevFrom, $from, $form);

        return [
            'from'     => $from,
            'to'       => $to,
            'banner'   => $this->banner($site),
            'tiles'    => self::tiles($counts),
            'previous' => self::tiles($previous),
            'daily'    => $this->daily($site, $from, $to, $form),
            'forms'    => $this->perForm($counts, $form),
            'fields'   => $this->topFields($site, $from, $to, $form),
            'recent'   => $this->recent($site, $from, $form, $outcome),
            'alerts'   => $this->alerts($site),
            'any'      => $this->anyEver($site),
        ];
    }

    /**
     * Yesterday on every form, both sites, emailed to the admin. Sends
     * nothing on a day where every submission that arrived was saved and
     * nothing went missing on the way. Also purges old rows.
     *
     * @return array{sent:bool,problems:int,purged:int}
     */
    public function digest(?string $day = null): array
    {
        $purged = $this->purge();
        $day ??= date('Y-m-d', strtotime('-1 day'));
        $from  = $day . ' 00:00:00';
        $to    = date('Y-m-d 00:00:00', strtotime($day . ' +1 day'));

        $paragraphs = [];
        $problems   = 0;
        $saved      = 0;
        foreach ([Sites::ZA, Sites::APP] as $site) {
            $counts = $this->counts($site, $from, $to, null);
            $fields = $this->topFields($site, $from, $to, null);
            foreach ($this->perForm($counts, null) as $row) {
                $saved += $row['ok'];
                $bad    = $row['invalid'] + $row['throttled'] + $row['csrf'] + $row['expired'] + $row['unavailable']
                    + $row['send_failed'] + $row['lost'] + $row['blocked'] + $row['js_error'];
                if ($bad === 0 && $row['never'] === 0) {
                    continue;
                }
                $problems += $bad + $row['never'];

                $parts = [];
                foreach (['lost' => 'lost', 'send_failed' => 'mail failed', 'csrf' => 'page expired', 'expired' => 'expired (session or page)',
                    'invalid' => 'refused', 'blocked' => 'blocked in the browser', 'throttled' => 'throttled',
                    'unavailable' => 'unavailable', 'js_error' => 'script errors'] as $k => $label) {
                    if ($row[$k] > 0) {
                        $parts[] = $row[$k] . ' ' . $label;
                    }
                }
                if ($row['never'] > 0) {
                    $parts[] = $row['never'] . ' pressed but never arrived';
                }
                $top = array_slice($fields[$row['key']] ?? [], 0, 3);

                $paragraphs[] = $row['label'] . ($site === Sites::APP ? ' (.app)' : '') . ': '
                    . $row['ok'] . ' saved, ' . implode(', ', $parts) . '.'
                    . ($top !== [] ? ' Most missed: ' . implode(', ', array_map(
                        static fn (array $f): string => $f['label'] . ' ' . $f['count'],
                        $top
                    )) . '.' : '');
            }
        }

        $admin = config('Directory')->adminEmail();
        if ($paragraphs === [] || $admin === '') {
            return ['sent' => false, 'problems' => $problems, 'purged' => $purged];
        }

        $sent = (new Mailer())->notice($admin, 'Forms on ' . date('j M', strtotime($day)) . ': ' . $saved . ' saved, ' . $problems . ' problems', [
            'heading'    => 'Forms on ' . date('l j F', strtotime($day)),
            'paragraphs' => $paragraphs,
            'button'     => ['Open form health', site_url_for(Sites::ZA, 'admin/forms?days=1')],
            'footnote'   => 'Refused means the form came back with something to fix. Lost and mail failed are the ones to look at first.',
        ]);

        return ['sent' => $sent, 'problems' => $problems, 'purged' => $purged];
    }

    /** Delete rows older than KEEP_DAYS. */
    public function purge(): int
    {
        $db = db_connect();
        $db->table(self::TABLE)->where('created_at <', date('Y-m-d H:i:s', strtotime('-' . self::KEEP_DAYS . ' days')))->delete();

        return $db->affectedRows();
    }

    /** One line, at most $max characters, no control characters. */
    public static function clean(string $s, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $s)), 0, $max);
    }

    /**
     * The route path without the query, with secret tokens taken out: a
     * preview, manage or stop link carries one in the path.
     */
    public static function cleanPath(string $path): string
    {
        $path = trim((string) strtok($path, '?#'), '/');
        $path = (string) preg_replace('#(?<=^|/)[A-Za-z0-9_]{24,}(?=/|$)#', ':token', $path);

        return self::clean($path, 160);
    }

    /** Is this request from a crawler or a tool? */
    public static function isBot(IncomingRequest $request): bool
    {
        return DiscoveryEventService::isBot($request);
    }

    public static function outcomeLabel(string $outcome): string
    {
        return match ($outcome) {
            'ok'          => 'saved',
            'invalid'     => 'refused',
            'throttled'   => 'throttled',
            'bot'         => 'bot',
            'csrf'        => 'page expired',
            'expired'     => 'expired',
            'unavailable' => 'unavailable',
            'send_failed' => 'mail failed',
            'exception'   => 'crashed',
            'lost'        => 'lost',
            'attempt'     => 'submit pressed',
            'blocked'     => 'blocked in the browser',
            'js_error'    => 'script error',
            default       => $outcome,
        };
    }

    // ----------------------------------------------------------- internals

    /** @param array<string,mixed> $row */
    private function insert(array $row): void
    {
        db_connect()->table(self::TABLE)->insert($row + [
            'site'       => service('site')->key(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function countSince(string $form, string $outcome, string $since, string $site): int
    {
        return db_connect()->table(self::TABLE)
            ->where('site', $site)
            ->where('form', $form)
            ->where('outcome', $outcome)
            ->where('source', 'server')
            ->where('created_at >=', $since)
            ->countAllResults();
    }

    /** @return array<string,array<string,int>> form => "source:outcome" => n */
    private function counts(string $site, string $from, string $to, ?string $form): array
    {
        $b = db_connect()->table(self::TABLE)
            ->select('form, outcome, source, COUNT(*) AS n')
            ->where('site', $site)
            ->whereIn('source', ['server', 'browser'])
            ->where('created_at >=', $from)
            ->where('created_at <', $to);
        if ($form !== null) {
            $b->where('form', $form);
        }

        $out = [];
        foreach ($b->groupBy(['form', 'outcome', 'source'])->get()->getResultArray() as $r) {
            $out[(string) $r['form']][$r['source'] . ':' . $r['outcome']] = (int) $r['n'];
        }

        return $out;
    }

    /**
     * @param array<string,array<string,int>> $counts
     * @return array{pressed:int,arrived:int,saved:int,rate:?int,lost:int,never:int}
     */
    private static function tiles(array $counts): array
    {
        $t = ['pressed' => 0, 'arrived' => 0, 'saved' => 0, 'rate' => null, 'lost' => 0, 'never' => 0];
        foreach ($counts as $byKey) {
            $row = self::formRow($byKey);
            $t['pressed'] += $row['pressed'];
            $t['arrived'] += $row['arrived'];
            $t['saved']   += $row['ok'];
            $t['lost']    += $row['lost'];
            $t['never']   += $row['never'];
        }
        $t['rate'] = $t['arrived'] > 0 ? (int) round($t['saved'] * 100 / $t['arrived']) : null;

        return $t;
    }

    /**
     * One form's numbers. "Arrived" leaves bots out, so the success rate is
     * about people; "never" is submits pressed that the server never saw
     * (floored at 0: a visitor without JavaScript arrives unannounced).
     *
     * @param array<string,int> $byKey
     * @return array<string,int|null>
     */
    private static function formRow(array $byKey): array
    {
        $s   = static fn (string $o): int => $byKey['server:' . $o] ?? 0;
        $row = [
            'pressed'     => $byKey['browser:attempt'] ?? 0,
            'blocked'     => $byKey['browser:blocked'] ?? 0,
            'js_error'    => $byKey['browser:js_error'] ?? 0,
            'ok'          => $s('ok'),
            'invalid'     => $s('invalid'),
            'throttled'   => $s('throttled'),
            'csrf'        => $s('csrf'),
            'expired'     => $s('expired'),
            'unavailable' => $s('unavailable'),
            'send_failed' => $s('send_failed'),
            'lost'        => $s('lost') + $s('exception'),
            'bot'         => $s('bot'),
        ];
        $row['arrived'] = array_sum(array_map($s, self::SERVER_OUTCOMES)) - $row['bot'];
        $row['never']   = max(0, $row['pressed'] - $row['arrived']);
        $row['rate']    = $row['arrived'] > 0 ? (int) round($row['ok'] * 100 / $row['arrived']) : null;

        return $row;
    }

    /**
     * Every registered form, quiet ones too, problems first: lost, then the
     * lowest success rate, then the busiest.
     *
     * @param array<string,array<string,int>> $counts
     * @return list<array<string,mixed>>
     */
    private function perForm(array $counts, ?string $only): array
    {
        $cfg  = config('Forms');
        $rows = [];
        foreach (array_keys($cfg->forms) as $key) {
            if ($only !== null && $key !== $only) {
                continue;
            }
            $rows[] = ['key' => $key, 'label' => $cfg->label($key)] + self::formRow($counts[$key] ?? []);
        }

        usort($rows, static function (array $a, array $b): int {
            return [$b['lost'] + $b['send_failed'], $a['rate'] ?? 101, $b['arrived'], $a['label']]
                <=> [$a['lost'] + $a['send_failed'], $b['rate'] ?? 101, $a['arrived'], $b['label']];
        });

        return $rows;
    }

    /** @return list<array{day:string,arrived:int,ok:int,invalid:int,lost:int}> */
    private function daily(string $site, string $from, string $to, ?string $form): array
    {
        $b = db_connect()->table(self::TABLE)
            ->select('DATE(created_at) AS day, outcome, COUNT(*) AS n')
            ->where('site', $site)
            ->where('source', 'server')
            ->where('outcome !=', 'bot')
            ->where('created_at >=', $from)
            ->where('created_at <', $to);
        if ($form !== null) {
            $b->where('form', $form);
        }

        $days = [];
        foreach ($b->groupBy(['day', 'outcome'])->get()->getResultArray() as $r) {
            $d = (string) $r['day'];
            $days[$d] ??= ['day' => $d, 'arrived' => 0, 'ok' => 0, 'invalid' => 0, 'lost' => 0];
            $n = (int) $r['n'];
            $days[$d]['arrived'] += $n;
            match ($r['outcome']) {
                'ok'                => $days[$d]['ok'] += $n,
                'invalid'           => $days[$d]['invalid'] += $n,
                'lost', 'exception' => $days[$d]['lost'] += $n,
                default             => null,
            };
        }
        ksort($days);

        return array_values($days);
    }

    /**
     * The fields people trip on, per form: server refusals and the browser's
     * own required field checks together, most often first.
     *
     * @return array<string,list<array{field:string,label:string,count:int}>>
     */
    private function topFields(string $site, string $from, string $to, ?string $form): array
    {
        $b = db_connect()->table(self::TABLE)
            ->select('form, detail')
            ->where('site', $site)
            ->groupStart()
                ->groupStart()->where('source', 'server')->where('outcome', 'invalid')->groupEnd()
                ->orGroupStart()->where('source', 'browser')->where('outcome', 'blocked')->groupEnd()
            ->groupEnd()
            ->where('detail IS NOT NULL', null, false)
            ->where('created_at >=', $from)
            ->where('created_at <', $to);
        if ($form !== null) {
            $b->where('form', $form);
        }

        $tally = [];
        foreach ($b->limit(5000)->get()->getResultArray() as $r) {
            foreach (array_unique(array_filter(array_map('trim', explode(',', (string) $r['detail'])))) as $field) {
                if (str_starts_with($field, 'http_')) {
                    continue;
                }
                $tally[(string) $r['form']][$field] = ($tally[(string) $r['form']][$field] ?? 0) + 1;
            }
        }

        $cfg = config('Forms');
        $out = [];
        foreach ($tally as $key => $fields) {
            arsort($fields);
            foreach (array_slice($fields, 0, 10, true) as $field => $n) {
                $out[$key][] = ['field' => (string) $field, 'label' => $cfg->fieldLabel((string) $field), 'count' => $n];
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function recent(string $site, string $from, ?string $form, ?string $outcome): array
    {
        $b = db_connect()->table(self::TABLE)
            ->where('site', $site)
            ->whereIn('source', ['server', 'browser'])
            ->where('created_at >=', $from);
        if ($form !== null) {
            $b->where('form', $form);
        }
        if ($outcome !== null) {
            $outcome === 'lost' ? $b->whereIn('outcome', ['lost', 'exception']) : $b->where('outcome', $outcome);
        }

        return $b->orderBy('id', 'DESC')->limit(100)->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> */
    private function alerts(string $site): array
    {
        return db_connect()->table(self::TABLE)
            ->where('site', $site)
            ->where('source', 'alert')
            ->orderBy('id', 'DESC')
            ->limit(10)
            ->get()->getResultArray();
    }

    /** @return array{count:int,last:?string,form:?string} */
    private function banner(string $site): array
    {
        $rows = db_connect()->table(self::TABLE)
            ->select('form, created_at')
            ->where('site', $site)
            ->where('source', 'server')
            ->whereIn('outcome', ['lost', 'exception'])
            ->where('created_at >=', date('Y-m-d H:i:s', time() - DAY))
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();

        return ['count' => count($rows), 'last' => $rows[0]['created_at'] ?? null, 'form' => $rows[0]['form'] ?? null];
    }

    private function anyEver(string $site): bool
    {
        return db_connect()->table(self::TABLE)->where('site', $site)->limit(1)->countAllResults() > 0;
    }
}
