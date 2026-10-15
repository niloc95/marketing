<?php

use App\Libraries\ScreeningBank;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The screening templates (App\Libraries\ScreeningBank), drawn hundreds of
 * times each.
 *
 * A template that marks a right answer wrong would quietly pass over good
 * applicants, so every answer is worked out again here from the details the
 * template drew, with this test's own reasoning, and compared with the
 * option the template says is right. Language pool answers are checked
 * against the pool's first entry with its names filled in.
 *
 * @internal
 */
final class ScreeningBankTest extends CIUnitTestCase
{
    private const DRAWS = 300;

    private ScreeningBank $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bank = new ScreeningBank();
    }

    /** @return iterable<string,array{string}> */
    public static function ids(): iterable
    {
        foreach (ScreeningBank::LEVELS as $kinds) {
            foreach ($kinds as $ids) {
                foreach ($ids as $id) {
                    yield $id => [$id];
                }
            }
        }
    }

    public function testEveryLevelHasEveryKind(): void
    {
        $this->assertSame(['basic', 'intermediate', 'advanced'], array_keys(ScreeningBank::LEVELS));
        foreach (ScreeningBank::LEVELS as $level => $kinds) {
            $this->assertSame(ScreeningBank::KINDS, array_keys($kinds), $level);
            foreach ($kinds as $kind => $ids) {
                $this->assertGreaterThanOrEqual(3, count($ids), $level . ' ' . $kind);
            }
        }
        $all = iterator_to_array(self::ids());
        $this->assertCount(count(array_unique(array_keys($all))), $all, 'ids are unique');
    }

    /** @dataProvider ids */
    public function testEveryDrawIsWellFormedAndFollowsTheCopyRules(string $id): void
    {
        for ($i = 0; $i < self::DRAWS; $i++) {
            $q = $this->bank->generate($id);

            $this->assertSame($id, $q['id']);
            $this->assertCount(4, $q['options']);
            $this->assertCount(4, array_unique($q['options']), $id . ': four different options');
            $this->assertArrayHasKey($q['answer'], $q['options']);
            $this->assertSame($id[1] === 'c', $q['passage'] !== null, $id . ': only comprehension has a passage');

            $text = $q['passage'] . ' ' . $q['prompt'] . ' ' . implode(' | ', $q['options']);
            $this->assertStringNotContainsString('—', $text, $id);
            $this->assertStringNotContainsString('–', $text, $id);
            $this->assertStringNotContainsString('-', $text, $id . ': no hyphens, and minus is the maths sign');
            $this->assertStringNotContainsString('{', $text, $id . ': every placeholder filled');
            $this->assertDoesNotMatchRegularExpression('/R\d[\d ]*\.\d(?!\d)/', $text, $id . ': rand to the cent or whole');
        }
    }

    /** @dataProvider ids */
    public function testTheAnswerIsRight(string $id): void
    {
        for ($i = 0; $i < self::DRAWS; $i++) {
            $q      = $this->bank->generate($id);
            $answer = $q['options'][$q['answer']];
            $expect = $this->expected($id, $q['params']);

            str_starts_with($expect, '/')
                ? $this->assertMatchesRegularExpression($expect, $answer, $id . ' ' . $q['prompt'])
                : $this->assertSame($expect, $answer, $id . ' ' . $q['passage'] . ' ' . $q['prompt']);
        }
    }

    /** @dataProvider ids */
    public function testApplicantsRarelySeeTheSameQuestion(string $id): void
    {
        // What an applicant sees is the passage, prompt and options together.
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $q = $this->bank->generate($id);
            $seen[$q['passage'] . '|' . $q['prompt'] . '|' . implode('|', $q['options'])] = true;
        }
        $this->assertGreaterThan(40, count($seen), $id);
    }

    public function testTheRightAnswerMovesAround(): void
    {
        $places = [];
        for ($i = 0; $i < 400; $i++) {
            $places[$this->bank->generate('bl1')['answer']] = true;
        }
        $this->assertCount(4, $places);
    }

    // ------------------------------------------------------------ the answers

    /** The right option, or a /regex/ it must match, worked out from $p. */
    private function expected(string $id, array $p): string
    {
        if (isset($p['pool'])) {
            return $this->poolAnswer($id, $p);
        }

        return match ($id) {
            // basic
            'bc1' => $p['times'][$p['ask']],
            'bc2' => $p['ask'] === 'day' ? $p['day'] : 'After ' . $p['after'],
            'bc3' => (string) ($p['delivered'] - $p['damaged']),
            'bc4' => $p['steps'][$p['pos']],
            'bn1' => $this->rand($p['price'] * (100 - $p['pct']) / 100),
            'bn2' => (new DateTimeImmutable('2026-01-01 ' . $p['start']))->modify("+{$p['minutes']} minutes")->format('H:i'),
            'bn3' => $this->rand($p['paid'] - $p['items'] * $p['each']),
            'bn4' => $p['pa'] / $p['a'] < $p['pb'] / $p['b'] ? 'Pack A' : 'Pack B',
            'bn5' => $this->duration($p['minutes']),
            // intermediate
            'ic1' => match ($p['ask']) {
                'why'   => $p['reason'],
                'where' => 'The ' . $p['area'],
                'when'  => $p['mins'] . ' minutes before their normal start time',
            },
            'ic2' => [
                'refund'   => 'A full refund',
                'exchange' => 'An exchange, but not a refund',
                'sale'     => 'Nothing, because sale items are only taken back when faulty',
                'late'     => '/^Nothing, because it is more than \d+ days$/',
            ][$p['case']],
            'ic3' => $this->rostered($p),
            'ic4' => $p['actions'][array_search($p['colour'], ['red', 'orange', 'green'], true)],
            'in1' => $this->rand($p['base'] * $p['rate'] + ($p['hours'] - $p['base']) * $p['rate'] * 1.5),
            'in2' => (string) ($p['start'] + $p['received'] - $p['sold'] - $p['damaged']),
            'in3' => ($p['distance'] / $p['hours'] * $p['minutes'] / 60) . ' km',
            'in4' => number_format($p['grams'] / $p['people'] * $p['for'], 0, '.', ' ') . ' g',
            'in5' => (string) (5 * $p['average'] - array_sum($p['four'])),
            // advanced
            'ac1' => [
                'fee'         => 'A refund of the R' . $p['fee'] . ' delivery fee',
                'replacement' => 'A working ' . $p['item'] . ' in place of this one',
                'date'        => 'To know when it will be delivered',
            ][$p['want']],
            'ac2' => $p['case'] === 'before' ? 'Yes, because it was approved before the shift' : '/^Only if the store manager signs it off/',
            'ac3' => $this->booking($p),
            'ac4' => [
                'frustrated' => 'Frustrated but still polite',
                'pleased'    => 'Pleased and thankful',
                'confused'   => 'Unsure and asking for help',
                'angry'      => 'Angry and making a threat',
            ][$p['tone']],
            'an1' => round(($p['to'] - $p['from']) / $p['from'] * 100) . '%',
            'an2' => (string) $this->appointments($p['len'], $p['gap'], $p['span']),
            'an3' => $this->duration((int) round($p['w1'] * $p['h1'] / $p['w2'] * 60)),
            'an4' => (string) ($p['use'] * $p['lead'] + $p['backup']),
            'an5' => $this->cheaper($p),
        };
    }

    /** Who the roster puts in a slot, read the way a person would. */
    private function rostered(array $p): string
    {
        [$a, $b, $c]           = $p['names'];
        [$d1, $d2, $d3]        = $p['days'];
        if (! preg_match('/^on (\w+) (morning|afternoon)$/', $p['slot'], $m)) {
            $this->fail('unexpected slot ' . $p['slot']);
        }
        [, $day, $part] = $m;

        if ($part === 'morning') {
            return match (true) {
                in_array($day, [$d1, $d2], true) => $a,
                $day === 'Saturday'              => $c,
                default                          => 'Nobody is listed',
            };
        }

        return $day === $d3 ? $c : $b;
    }

    private function booking(array $p): string
    {
        return match (true) {
            $p['people'] > $p['size'] => '/group is too big/',
            $p['days'] < $p['ahead']  => 'No, they are booking too late',
            $p['hours'] > $p['max']   => 'No, the booking is too long',
            default                   => 'Yes, they can book it',
        };
    }

    /** Count appointments one by one until the next would run past the end. */
    private function appointments(int $len, int $gap, int $span): int
    {
        $n = 0;
        for ($t = 0; $t + $len <= $span; $t += $len + $gap) {
            $n++;
        }

        return $n;
    }

    private function cheaper(array $p): string
    {
        $a = $p['fee'] + $p['rateA'] * $p['hours'];
        $b = $p['rateB'] * $p['hours'];

        return '/^(Plumber|Electrician|Builder) ' . ($a < $b ? 'A' : 'B') . ', by ' . preg_quote($this->rand(abs($a - $b)), '/') . '$/';
    }

    /** The pool's first right entry, its placeholders matching any filled value. */
    private function poolAnswer(string $id, array $p): string
    {
        $level = ['b' => 'basic', 'i' => 'intermediate', 'a' => 'advanced'][$id[0]];
        $pools = (new ReflectionClass(ScreeningBank::class))->getConstants();
        $item  = $pools[strtoupper($p['pool'])][$level][$p['item']];
        $right = match ($p['pool']) {
            'grammar'    => $item[0],
            'vocabulary' => $item[2],
            'writing'    => $item[1],
        };

        return '/^' . preg_replace('/\\\\\{\w+\\\\\}/', '.+', preg_quote($right, '/')) . '$/u';
    }

    // ------------------------------------------------------------ helpers

    private function rand(float|int $amount): string
    {
        $amount = round((float) $amount, 2);

        return 'R' . number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2, '.', ' ');
    }

    private function duration(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return trim(($h > 0 ? $h . ($h === 1 ? ' hour' : ' hours') : '') . ' ' . ($m > 0 || $h === 0 ? $m . ($m === 1 ? ' minute' : ' minutes') : ''));
    }
}
