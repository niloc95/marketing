<?php

namespace App\Libraries;

use Config\Screening as ScreeningConfig;

/**
 * Screening questions: three timed multiple choice questions a role can ask
 * every applicant, one of each kind in ScreeningBank (reading comprehension,
 * language, everyday numbers), drawn at random from one level, each with its
 * own details.
 *
 * The life of an attempt, all in the applicant's session (nothing is stored
 * until an application is, which keeps the Jobs board's "we keep nothing"
 * promise possible):
 *
 * 1. start(): the applicant presses Start. One template of each kind is
 *    drawn and generated, and the clock starts on our server. Pressing Start again,
 *    or reloading, returns the same attempt: no new questions, no new clock.
 * 2. answer(): the answers are locked with our time, so they cannot be
 *    changed after a search. Late answers are kept and flagged over time.
 *    How often the page was left is reported by the page, so it is clamped
 *    and labelled as such.
 * 3. consume(): the application is sent. The attempt is marked, turned into
 *    the result the application keeps, and forgotten.
 *
 * Nobody is turned away on a low score or a late answer. The result goes to
 * whoever reviews the application, who decides. The right answers never
 * leave the server until a reviewer looks.
 */
class Screening
{
    /** How many questions an applicant answers. */
    public const COUNT = 3;

    public const SESSION_KEY = 'screening_attempts';

    /** Attempts older than this are dropped; the session lasts about as long. */
    private const KEEP_SECONDS = 7200;

    /** The most page leaves recorded; anything past it is plainly not looking. */
    private const MAX_LEAVES = 99;

    private ScreeningConfig $config;
    private ScreeningBank $bank;

    public function __construct(?ScreeningConfig $config = null, ?ScreeningBank $bank = null)
    {
        $this->config = $config ?? config('Screening');
        $this->bank   = $bank ?? new ScreeningBank();
    }

    /** @return array<string,string> level => label */
    public function levels(): array
    {
        return $this->config->levels;
    }

    public function levelLabel(string $level): string
    {
        return $this->config->levels[$level] ?? '';
    }

    public function isLevel(?string $level): bool
    {
        return $level !== null && isset($this->config->levels[$level]);
    }

    /** Seconds allowed for the whole set at $level: one question of each kind. */
    public function limitFor(string $level): int
    {
        return (int) array_sum($this->config->secondsPerKind[$level] ?? []) ?: 60 * self::COUNT;
    }

    public function bank(): ScreeningBank
    {
        return $this->bank;
    }

    /**
     * Read the level select from a posted form. Empty means no screening.
     *
     * @param mixed $level
     *
     * @return array{level:string|null,error:string|null}
     */
    public function selection($level): array
    {
        $level = is_string($level) ? $level : '';
        if ($level === '') {
            return ['level' => null, 'error' => null];
        }

        return $this->isLevel($level)
            ? ['level' => $level, 'error' => null]
            : ['level' => null, 'error' => 'Choose a level for the questions.'];
    }

    // ------------------------------------------------------------ attempts

    /** The applicant's attempt for $context ("career:12"), or null. */
    public function current(string $context): ?array
    {
        foreach (array_reverse($this->attempts()) as $attempt) {
            if ($attempt['context'] === $context) {
                return $attempt;
            }
        }

        return null;
    }

    /**
     * Start, or return the attempt already running for $context.
     *
     * @return array<string,mixed> the attempt
     */
    public function start(string $context, string $level, ?int $now = null): array
    {
        $existing = $this->current($context);
        if ($existing !== null) {
            return $existing;
        }

        // One of each kind, in ScreeningBank::KINDS order: the passage first,
        // while the applicant is freshest.
        $drawn = [];
        foreach (ScreeningBank::KINDS as $kind) {
            $ids = $this->bank->idsOf($level, $kind);
            if ($ids === []) {
                throw new \InvalidArgumentException('Unknown screening level ' . $level);
            }
            $drawn[$ids[random_int(0, count($ids) - 1)]] = true;
        }

        $attempt = [
            'token'       => bin2hex(random_bytes(16)),
            'context'     => $context,
            'level'       => $level,
            'items'       => array_map(fn ($id) => array_intersect_key(
                $this->bank->generate($id),
                array_flip(['id', 'passage', 'prompt', 'options', 'answer'])
            ), array_keys($drawn)),
            'started_at'  => $now ?? time(),
            'limit_s'     => $this->limitFor($level),
            'answered_at' => null,
            'answers'     => null,
            'leaves'      => 0,
        ];

        $attempts                    = $this->attempts();
        $attempts[$attempt['token']] = $attempt;
        $this->save($attempts);

        return $attempt;
    }

    /**
     * What the page may see of an attempt: never where the right answers are.
     *
     * @return array{token:string,state:string,seconds_left:int,limit_s:int,questions:list<array{passage:string|null,prompt:string,options:list<string>}>,given:list<int|null>}
     */
    public function view(array $attempt, ?int $now = null): array
    {
        $now     = $now ?? time();
        $elapsed = $now - (int) $attempt['started_at'];

        return [
            'token'        => (string) $attempt['token'],
            'state'        => $attempt['answered_at'] !== null ? 'answered' : 'running',
            'seconds_left' => max(0, (int) $attempt['limit_s'] - $elapsed),
            'limit_s'      => (int) $attempt['limit_s'],
            'questions'    => array_map(static fn (array $i) => [
                'passage' => $i['passage'] ?? null,
                'prompt'  => $i['prompt'],
                'options' => $i['options'],
            ], $attempt['items']),
            'given'        => is_array($attempt['answers']) ? $attempt['answers'] : array_fill(0, count($attempt['items']), null),
        ];
    }

    /**
     * Lock the answers.
     *
     * @param mixed $answers the posted `screening` array, an option index per question
     * @param mixed $leaves  how often the page was hidden while the clock ran
     *
     * @return array{ok:bool,message:string}
     */
    public function answer(string $token, $answers, $leaves, ?int $now = null): array
    {
        $attempts = $this->attempts();
        $attempt  = $attempts[$token] ?? null;
        if ($attempt === null) {
            return ['ok' => false, 'message' => 'Those questions have expired. Please reload the page and start again.'];
        }
        if ($attempt['answered_at'] !== null) {
            return ['ok' => false, 'message' => 'Your answers are already saved.'];
        }

        $given = [];
        foreach ($attempt['items'] as $i => $item) {
            $pick = is_array($answers) ? ($answers[$i] ?? null) : null;
            if (! is_string($pick) || ! ctype_digit($pick) || (int) $pick >= count($item['options'])) {
                return ['ok' => false, 'message' => 'Please answer all ' . count($attempt['items']) . ' questions.'];
            }
            $given[] = (int) $pick;
        }

        $attempt['answers']     = $given;
        $attempt['answered_at'] = $now ?? time();
        $attempt['leaves']      = max(0, min(self::MAX_LEAVES, is_numeric($leaves) ? (int) $leaves : 0));
        $attempts[$token]       = $attempt;
        $this->save($attempts);

        return ['ok' => true, 'message' => 'Answers saved. You can send your application now.'];
    }

    /** The attempt behind $token when it is answered and for $context, else null. */
    public function answeredFor(string $token, string $context): ?array
    {
        $attempt = $this->attempts()[$token] ?? null;

        return $attempt !== null && $attempt['context'] === $context && $attempt['answered_at'] !== null ? $attempt : null;
    }

    /**
     * Mark an answered attempt and forget it. Null when there is none for
     * $context, so a token cannot be spent twice or on another role.
     *
     * @return array{level:string,score:int,total:int,time_s:int,limit_s:int,over_time:bool,leaves:int,items:list<array{id:string,passage:string|null,prompt:string,given:string,answer:string,correct:bool}>}|null
     */
    public function consume(string $token, string $context): ?array
    {
        $attempt = $this->answeredFor($token, $context);
        if ($attempt === null) {
            return null;
        }

        $attempts = $this->attempts();
        unset($attempts[$token]);
        $this->save($attempts);

        return $this->mark($attempt);
    }

    /** @return array<string,mixed> the result an application keeps */
    public function mark(array $attempt): array
    {
        $items = [];
        $score = 0;
        foreach ($attempt['items'] as $i => $item) {
            $given   = $attempt['answers'][$i] ?? null;
            $correct = $given === $item['answer'];
            $score  += $correct ? 1 : 0;
            $items[] = [
                'id'      => (string) $item['id'],
                'passage' => $item['passage'] ?? null,
                'prompt'  => (string) $item['prompt'],
                'given'   => $given !== null ? (string) ($item['options'][$given] ?? '') : '',
                'answer'  => (string) $item['options'][$item['answer']],
                'correct' => $correct,
            ];
        }
        $time = max(0, (int) $attempt['answered_at'] - (int) $attempt['started_at']);

        return [
            'level'     => (string) $attempt['level'],
            'score'     => $score,
            'total'     => count($items),
            'time_s'    => $time,
            'limit_s'   => (int) $attempt['limit_s'],
            'over_time' => $time > (int) $attempt['limit_s'],
            'leaves'    => (int) $attempt['leaves'],
            'items'     => $items,
        ];
    }

    // ------------------------------------------------------------ wording

    /** "2:14". */
    public function clock(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return intdiv($seconds, 60) . ':' . sprintf('%02d', $seconds % 60);
    }

    /**
     * "2 of 3 correct in 2:14 of 3:00, left the page twice": the line the
     * inbox and the admin queue lead with.
     */
    public function summary(array $result): string
    {
        $line = $result['score'] . ' of ' . $result['total'] . ' correct';
        if (isset($result['time_s'], $result['limit_s'])) {
            $line .= (! empty($result['over_time']) ? ', over time: ' : ' in ')
                . $this->clock((int) $result['time_s']) . ' of ' . $this->clock((int) $result['limit_s']);
        }
        $leaves = (int) ($result['leaves'] ?? 0);
        if ($leaves > 0) {
            $line .= ', left the page ' . match ($leaves) {
                1       => 'once',
                2       => 'twice',
                default => $leaves . ' times',
            };
        }

        return $line;
    }

    /**
     * One line per answer for an email: "✓ Right: prompt Answered: given",
     * or "✗ Wrong: …" with the right answer as well. The emails/job-notice
     * template bolds whatever comes before the first ': ', so the verdict
     * leads and a prompt with a colon in it cannot steal the bold.
     *
     * @return list<string>
     */
    public function lines(array $result): array
    {
        return array_map(static fn (array $i) => ($i['correct'] ? '✓ Right: ' : '✗ Wrong: ') . $i['prompt']
            . ' Answered: ' . ($i['given'] !== '' ? $i['given'] : 'nothing')
            . ($i['correct'] ? '' : '. Correct: ' . $i['answer']), $result['items']);
    }

    // ------------------------------------------------------------ session

    /** @return array<string,array<string,mixed>> token => attempt, oldest first, stale ones dropped */
    private function attempts(): array
    {
        $all = session(self::SESSION_KEY);
        if (! is_array($all)) {
            return [];
        }
        $cutoff = time() - self::KEEP_SECONDS;

        return array_filter($all, static fn ($a) => is_array($a) && (int) ($a['started_at'] ?? 0) >= $cutoff);
    }

    private function save(array $attempts): void
    {
        session()->set(self::SESSION_KEY, $attempts);
    }
}
