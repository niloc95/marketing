<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The hidden trap on every public form. Browser autofill ignores
 * autocomplete="off" and filled the old "Company website" field, so a real
 * customer could not order the profile setup service (29 Oct 2026).
 */
final class HoneypotFieldTest extends CIUnitTestCase
{
    /** Words browsers and password managers match a field on before filling it. */
    private const AUTOFILL_WORDS = '/company|business|organi[sz]ation|website|url|homepage|e-?mail|phone|tel|address|street|city|postal|zip|country|first|last|full.?name|user/i';

    public function testNeitherTheNameNorTheLabelInvitesAutofill(): void
    {
        $html = honeypot_field();

        $this->assertStringContainsString('name="' . HONEYPOT_FIELD . '"', $html);
        $this->assertDoesNotMatchRegularExpression(self::AUTOFILL_WORDS, HONEYPOT_FIELD);
        $this->assertDoesNotMatchRegularExpression(self::AUTOFILL_WORDS, strip_tags($html));
        $this->assertStringContainsString('class="hp"', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
    }

    public function testEveryFormUsesTheHelperRatherThanItsOwnCopy(): void
    {
        $views = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(APPPATH . 'Views'));
        $users = 0;
        foreach ($views as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('company_website_hp', $source, $file->getFilename());
            $this->assertStringNotContainsString('<div class="hp"', $source, $file->getFilename() . ' writes its own trap');
            $users += substr_count($source, 'honeypot_field()');
        }
        $this->assertGreaterThanOrEqual(9, $users, 'the nine public forms carry the trap');
    }
}
