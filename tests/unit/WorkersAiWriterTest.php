<?php

use App\Libraries\RichText;
use App\Services\Description\DescriptionDraftService;
use App\Services\Description\DescriptionWriter;
use App\Services\Description\WorkersAiWriter;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/**
 * Workers AI rewriting the template draft, and every way back to the template.
 *
 * Cloudflare is never called: ask() is answered by hand, and each test says
 * what the "model" replied. The template draft is the expected result whenever
 * the reply breaks a rule.
 *
 * @internal
 */
final class WorkersAiWriterTest extends CIUnitTestCase
{
    private const FACTS = [
        'type'     => 'practice',
        'name'     => 'Sandton Smiles',
        'category' => 'Dentist',
        'city'     => 'Johannesburg',
        'suburb'   => 'Sandton',
        'services' => ['Teeth whitening', 'Fillings'],
        'features' => ['Medical aid accepted'],
    ];

    /** @var list<list<array{role:string,content:string}>> */
    private array $sent = [];

    protected function tearDown(): void
    {
        unset($_ENV['directory.workersAiAccountId'], $_ENV['directory.workersAiToken']);
        Services::reset();
        parent::tearDown();
    }

    public function testAGoodReplyIsUsed(): void
    {
        $html = $this->writer('Sandton Smiles is a friendly dental practice in Sandton. We offer teeth whitening and fillings, and medical aid is accepted.')
            ->draft(self::FACTS);

        $this->assertSame('<p>Sandton Smiles is a friendly dental practice in Sandton. We offer teeth whitening and fillings, and medical aid is accepted.</p>', $html);
    }

    public function testOnlyTheOwnersFactsAndTheTemplateAreSent(): void
    {
        $this->writer('Sandton Smiles is a dentist in Sandton, Johannesburg, offering teeth whitening.')->draft(self::FACTS);

        $prompt = $this->sent[0][3]['content'];
        $this->assertStringContainsString('Business name: Sandton Smiles', $prompt);
        $this->assertStringContainsString('Services: Teeth whitening, Fillings', $prompt);
        $this->assertStringContainsString(RichText::toPlainText($this->template()), $prompt);
        // Only travellers have places they travel to.
        $this->assertStringNotContainsString('Travels to', $prompt);
    }

    public function testAnnouncementsAndQuotesAreTrimmed(): void
    {
        $html = $this->writer("Here is your description:\n\n\"Sandton Smiles is a dentist in Sandton offering teeth whitening and fillings.\"")
            ->draft(self::FACTS);

        $this->assertSame('<p>Sandton Smiles is a dentist in Sandton offering teeth whitening and fillings.</p>', $html);
    }

    public function testParagraphsSurvive(): void
    {
        $html = $this->writer("Sandton Smiles is a dentist in Sandton.\n\nWe offer teeth whitening and fillings.")->draft(self::FACTS);

        $this->assertSame('<p>Sandton Smiles is a dentist in Sandton.</p><p>We offer teeth whitening and fillings.</p>', $html);
    }

    public function testDashesAreTakenOutButProperNamesKeepTheirs(): void
    {
        $facts = ['name' => 'Bela-Bela Smiles', 'city' => 'Bela-Bela'] + self::FACTS;

        $html = $this->writer('Bela-Bela Smiles is a friendly dentist in Bela-Bela — we offer walk-in check ups.')->draft($facts);

        $this->assertSame('<p>Bela-Bela Smiles is a friendly dentist in Bela-Bela, we offer walk in check ups.</p>', $html);
    }

    /** @return iterable<string,array{string}> */
    public static function badReplies(): iterable
    {
        yield 'empty' => [''];
        yield 'no business name' => ['A friendly dentist in Sandton offering teeth whitening and fillings.'];
        yield 'too short' => ['Sandton Smiles.'];
        yield 'too long' => ['Sandton Smiles is a dentist. ' . str_repeat('We care about every smile. ', 40)];
        yield 'a list' => ["Sandton Smiles offers:\n- Teeth whitening\n- Fillings"];
        yield 'a link' => ['Sandton Smiles is a dentist in Sandton. Book at www.sandtonsmiles.co.za today.'];
        yield 'an email' => ['Sandton Smiles is a dentist in Sandton. Email hello@smiles.test to book a visit.'];
        yield 'a phone number' => ['Sandton Smiles is a dentist in Sandton. Call 011 555 1234 to book a visit.'];
        yield 'invented years' => ['Sandton Smiles has been a trusted dentist in Sandton for over 20 years.'];
        yield 'invented price' => ['Sandton Smiles is a dentist in Sandton with whitening from R950 a session.'];
        yield 'invented award' => ['Sandton Smiles is an award winning dentist in Sandton offering teeth whitening.'];
        yield 'invented experience' => ['Sandton Smiles is a dentist in Sandton with an experienced, caring team.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badReplies')]
    public function testABadReplyFallsBackToTheTemplate(string $reply): void
    {
        $this->assertSame($this->template(), $this->writer($reply)->draft(self::FACTS));
    }

    public function testNoReplyFallsBackToTheTemplate(): void
    {
        $this->assertSame($this->template(), $this->writer(null)->draft(self::FACTS));
    }

    public function testNumbersTheOwnerGaveAreAllowed(): void
    {
        $facts = ['name' => '24 Hour Plumbing', 'category' => 'Plumber', 'services' => ['Burst pipes', 'Geysers']];

        $html = $this->writer('24 Hour Plumbing is a plumber you can call on any day of the week.')->draft($facts);

        $this->assertStringContainsString('24 Hour Plumbing is a plumber you can call', $html);
    }

    public function testAClaimTheOwnerMadeIsAllowed(): void
    {
        $facts = ['features' => ['Guarantee on work']] + self::FACTS;

        $html = $this->writer('Sandton Smiles is a dentist in Sandton, and every treatment comes with a guarantee on work.')->draft($facts);

        $this->assertStringContainsString('guarantee on work', $html);
    }

    public function testTheWorkedExampleGoesAheadOfTheRequest(): void
    {
        $this->writer('Sandton Smiles is a dentist in Sandton offering teeth whitening.')->draft(self::FACTS);

        $this->assertSame(['system', 'user', 'assistant', 'user'], array_column($this->sent[0], 'role'));
        $this->assertStringContainsString('Sandton Smiles', $this->sent[0][3]['content']);
    }

    public function testPastTheDailyCapTheModelIsNotAsked(): void
    {
        $html = $this->writer('Sandton Smiles is a dentist in Sandton offering teeth whitening.', cap: 0)->draft(self::FACTS);

        $this->assertSame($this->template(), $html);
        $this->assertSame([], $this->sent);
    }

    public function testTooFewFactsAreLeftToTheTemplate(): void
    {
        $facts = ['name' => 'Optic World', 'category' => 'Optometrist', 'city' => 'Pretoria', 'services' => ['Eye tests']];

        $this->assertSame((new DescriptionDraftService())->draft($facts), $this->writer('Optic World is an optometrist in Pretoria offering eye tests.')->draft($facts));
        $this->assertSame([], $this->sent);
    }

    public function testNothingToWriteFromNeverCallsTheModel(): void
    {
        $this->assertSame('', $this->writer('anything')->draft(['name' => 'Sandton Smiles']));
        $this->assertSame([], $this->sent);
    }

    public function testTheServiceUsesTemplatesUntilBothCredentialsAreSet(): void
    {
        // Blank, not unset: a developer's own .env may hold real ones.
        $_ENV['directory.workersAiAccountId'] = '';
        $_ENV['directory.workersAiToken']     = '';
        $this->assertInstanceOf(DescriptionDraftService::class, Services::descriptionWriter(false));

        $_ENV['directory.workersAiToken'] = 'cf-test-token';
        $this->assertInstanceOf(DescriptionDraftService::class, Services::descriptionWriter(false));

        $_ENV['directory.workersAiAccountId'] = 'abc123';
        $this->assertInstanceOf(WorkersAiWriter::class, Services::descriptionWriter(false));
    }

    private function template(): string
    {
        return (new DescriptionDraftService())->draft(self::FACTS);
    }

    /** A writer whose model always replies $reply; null stands for a failed call. */
    private function writer(?string $reply, int $cap = 1000): DescriptionWriter
    {
        $sent = &$this->sent;

        return new class ($reply, $cap, $sent) extends WorkersAiWriter {
            /** @var array<int,mixed> */
            private array $sent;

            public function __construct(private readonly ?string $reply, int $cap, array &$sent)
            {
                parent::__construct('account', 'token', '@cf/test/model', $cap);
                $this->sent = &$sent;
            }

            protected function ask(array $messages): ?string
            {
                $this->sent[] = $messages;

                return $this->reply;
            }
        };
    }
}
