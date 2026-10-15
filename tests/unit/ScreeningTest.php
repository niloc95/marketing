<?php

use App\Libraries\Screening;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The life of a screening attempt (App\Libraries\Screening), in the session
 * CIUnitTestCase mocks: start, answer, consume, and the wording reviewers
 * read.
 *
 * @internal
 */
final class ScreeningTest extends CIUnitTestCase
{
    private Screening $s;

    protected function setUp(): void
    {
        parent::setUp();
        session()->remove(Screening::SESSION_KEY);
        $this->s = new Screening();
    }

    /** @return list<string> the right option index for each question, as posted */
    private function rightAnswers(array $attempt): array
    {
        return array_map(static fn (array $i) => (string) $i['answer'], $attempt['items']);
    }

    // ------------------------------------------------------------- the level

    public function testTheLevelIsAllARoleChooses(): void
    {
        $this->assertSame(['level' => null, 'error' => null], $this->s->selection(''));
        $this->assertSame(['level' => 'advanced', 'error' => null], $this->s->selection('advanced'));
        $this->assertNotNull($this->s->selection('expert')['error']);
        $this->assertNull($this->s->selection(['basic'])['level'], 'only a string is a level');
    }

    public function testTheTimeAllowedGrowsWithTheLevel(): void
    {
        $this->assertSame(225, $this->s->limitFor('basic'));
        $this->assertSame(285, $this->s->limitFor('intermediate'));
        $this->assertSame(345, $this->s->limitFor('advanced'));
    }

    // ------------------------------------------------------------- starting

    public function testStartDrawsOneOfEachKindFromTheLevel(): void
    {
        $attempt = $this->s->start('career:1', 'intermediate');

        // Comprehension, language, numbers: ic*, il*, in*.
        $this->assertSame(['ic', 'il', 'in'], array_map(static fn ($i) => substr($i['id'], 0, 2), $attempt['items']));
        $this->assertNotNull($attempt['items'][0]['passage'], 'the comprehension question has its passage');
    }

    public function testStartingAgainGivesTheSameQuestionsAndClock(): void
    {
        $first = $this->s->start('career:1', 'basic', time() - 50);
        $again = $this->s->start('career:1', 'basic');

        $this->assertSame($first['token'], $again['token']);
        $this->assertSame($first['items'], $again['items']);
        $this->assertSame(175, $this->s->view($again)['seconds_left'], 'the clock kept running');

        $other = $this->s->start('career:2', 'basic');
        $this->assertNotSame($first['token'], $other['token'], 'another role is another attempt');
    }

    public function testThePageNeverSeesWhereTheAnswersAre(): void
    {
        $view = $this->s->view($this->s->start('career:1', 'advanced'));

        $json = json_encode($view);
        $this->assertStringNotContainsString('"answer"', $json);
        $this->assertSame(['passage', 'prompt', 'options'], array_keys($view['questions'][0]));
        $this->assertSame('running', $view['state']);
    }

    // ------------------------------------------------------------- answering

    public function testAnsweringLocksTheAnswers(): void
    {
        $attempt = $this->s->start('career:1', 'basic');

        $this->assertFalse($this->s->answer($attempt['token'], ['0', '1'], 0)['ok'], 'all three');
        $this->assertFalse($this->s->answer($attempt['token'], ['0', '1', '9'], 0)['ok'], 'a real option');
        $this->assertFalse($this->s->answer('nope', ['0', '1', '2'], 0)['ok'], 'a real attempt');

        $this->assertTrue($this->s->answer($attempt['token'], ['0', '1', '2'], 0)['ok']);
        $this->assertFalse($this->s->answer($attempt['token'], $this->rightAnswers($attempt), 0)['ok'], 'no second go after a search');
        $this->assertSame('answered', $this->s->view($this->s->current('career:1'))['state']);
    }

    public function testTheScoreTimeAndLeavesAreKept(): void
    {
        $attempt = $this->s->start('career:1', 'basic', time() - 134);
        $answers = $this->rightAnswers($attempt);
        $answers[2] = (string) (((int) $answers[2] + 1) % 4);
        $this->s->answer($attempt['token'], $answers, '2');

        $result = $this->s->consume($attempt['token'], 'career:1');

        $this->assertSame(2, $result['score']);
        $this->assertSame(3, $result['total']);
        $this->assertFalse($result['over_time']);
        $this->assertSame(2, $result['leaves']);
        $this->assertSame([true, true, false], array_column($result['items'], 'correct'));
        $this->assertSame('2 of 3 correct in 2:14 of 3:45, left the page twice', $this->s->summary($result));
    }

    public function testLateAnswersAreKeptAndFlagged(): void
    {
        $attempt = $this->s->start('career:1', 'basic', time() - 400);
        $this->s->answer($attempt['token'], $this->rightAnswers($attempt), 0);

        $result = $this->s->consume($attempt['token'], 'career:1');
        $this->assertSame(3, $result['score'], 'late is not wrong');
        $this->assertTrue($result['over_time']);
        $this->assertStringContainsString('over time: 6:40 of 3:45', $this->s->summary($result));
    }

    public function testTheLeaveCountIsClamped(): void
    {
        $attempt = $this->s->start('career:1', 'basic');
        $this->s->answer($attempt['token'], ['0', '0', '0'], '5000');
        $this->assertSame(99, $this->s->consume($attempt['token'], 'career:1')['leaves']);

        $attempt = $this->s->start('career:2', 'basic');
        $this->s->answer($attempt['token'], ['0', '0', '0'], 'lots');
        $this->assertSame(0, $this->s->consume($attempt['token'], 'career:2')['leaves']);
    }

    // ------------------------------------------------------------- spending

    public function testATokenIsSpentOnceAndOnlyOnItsRole(): void
    {
        $attempt = $this->s->start('career:1', 'basic');
        $this->assertNull($this->s->consume($attempt['token'], 'career:1'), 'not answered yet');

        $this->s->answer($attempt['token'], ['0', '0', '0'], 0);
        $this->assertNull($this->s->consume($attempt['token'], 'career:2'), 'another role');
        $this->assertNotNull($this->s->consume($attempt['token'], 'career:1'));
        $this->assertNull($this->s->consume($attempt['token'], 'career:1'), 'only once');
        $this->assertNull($this->s->current('career:1'), 'forgotten, so the next application starts afresh');
    }

    // ------------------------------------------------------------- wording

    public function testEmailLinesLeadWithTheVerdict(): void
    {
        $lines = $this->s->lines(['items' => [
            ['prompt' => 'Solve for x: 4x − 7 = 2x + 9', 'given' => 'x = 8', 'answer' => 'x = 8', 'correct' => true],
            ['prompt' => 'What is 2 + 2?', 'given' => '5', 'answer' => '4', 'correct' => false],
        ]]);

        $this->assertSame('✓ Right: Solve for x: 4x − 7 = 2x + 9 Answered: x = 8', $lines[0]);
        $this->assertSame('✗ Wrong: What is 2 + 2? Answered: 5. Correct: 4', $lines[1]);
    }
}
