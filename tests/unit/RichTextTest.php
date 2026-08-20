<?php

use App\Libraries\RichText;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The listing description is the only field this site renders unescaped, so
 * these are the tests that stand between a crafted POST and script execution on
 * every profile page.
 *
 * @internal
 */
final class RichTextTest extends CIUnitTestCase
{
    // ------------------------------------------------------------ injection

    public function testDropsScriptAndItsContents(): void
    {
        $out = RichText::sanitise('<p>hello</p><script>alert(1)</script>');

        $this->assertSame('<p>hello</p>', $out);
    }

    public function testDropsStyleAndItsContents(): void
    {
        $out = RichText::sanitise('<p>hi</p><style>body{display:none}</style>');

        $this->assertStringNotContainsString('display:none', $out);
        $this->assertStringNotContainsString('<style', $out);
    }

    public function testDropsForeignContentWhereMutationXssLives(): void
    {
        $out = RichText::sanitise('<p>a</p><svg><script>alert(1)</script></svg><math><mtext></mtext></math>');

        $this->assertSame('<p>a</p>', $out);
    }

    public function testStripsEventHandlerAttributes(): void
    {
        $out = RichText::sanitise('<p onclick="alert(1)">x</p><img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringNotContainsString('<img', $out);
    }

    public function testDropsComments(): void
    {
        // A conditional comment is markup some browsers still act on.
        $out = RichText::sanitise('<p>a<!--[if IE]><script>x</script><![endif]-->b</p>');

        $this->assertSame('<p>ab</p>', $out);
    }

    public function testSurvivesUnbalancedMarkup(): void
    {
        // The classic pattern that defeats a regex sanitiser: libxml, not a
        // pattern, decides where the tag ends, so the nested <script> is a real
        // script element and gets dropped with its contents.
        //
        // The leftover "ipt>alert(1)" is asserted on deliberately — it is text,
        // and the &gt; proves it. Harmless nonsense on the page is the correct
        // outcome for nonsense input; the thing that must never survive is an
        // element.
        $out = RichText::sanitise('<p>a<scr<script>ipt>alert(1)</script>b</p>');

        $this->assertSame('<p>aipt&gt;alert(1)b</p>', $out);
        $this->assertStringNotContainsString('<script', $out);
    }

    // ------------------------------------------------------------------ urls

    public function testRejectsJavascriptHrefButKeepsTheText(): void
    {
        $out = RichText::sanitise('<p><a href="javascript:alert(1)">click</a></p>');

        $this->assertSame('<p>click</p>', $out);
    }

    public function testRejectsJavascriptHrefSplitByControlCharacters(): void
    {
        $out = RichText::sanitise('<p><a href="java&#9;script:alert(1)">click</a></p>');

        $this->assertStringNotContainsString('javascript', $out);
        $this->assertStringNotContainsString('href', $out);
    }

    public function testRejectsAboutBlank(): void
    {
        // What Quill itself rewrites a javascript: href to before the form is
        // even submitted. It has to be rejected here as well, or a link that
        // goes nowhere ships to the public page.
        $out = RichText::sanitise('<p><a href="about:blank">x</a></p>');

        $this->assertSame('<p>x</p>', $out);
    }

    public function testNormalisesNonBreakingSpacesFromQuillExport(): void
    {
        // getSemanticHTML() emits &nbsp; for every space. Left alone, a
        // description would refuse to wrap on a phone.
        $out = RichText::sanitise('<p>We&nbsp;fix&nbsp;geysers</p>');

        $this->assertSame('<p>We fix geysers</p>', $out);
        $this->assertStringNotContainsString("\xC2\xA0", $out);
    }

    public function testRejectsDataHref(): void
    {
        $out = RichText::sanitise('<p><a href="data:text/html,<script>alert(1)</script>">x</a></p>');

        $this->assertStringNotContainsString('href', $out);
    }

    public function testKeepsHttpMailtoAndTel(): void
    {
        foreach (['https://example.co.za/x', 'http://example.co.za', 'mailto:a@b.co.za', 'tel:+27115550000'] as $href) {
            $out = RichText::sanitise('<p><a href="' . $href . '">x</a></p>');

            $this->assertStringContainsString('href="' . $href . '"', $out, $href);
        }
    }

    public function testForcesRelAndTargetOnEveryLink(): void
    {
        $out = RichText::sanitise('<p><a href="https://example.co.za">x</a></p>');

        $this->assertStringContainsString('rel="nofollow noopener noreferrer"', $out);
        $this->assertStringContainsString('target="_blank"', $out);
    }

    public function testGivesSchemelessAndSchemeRelativeUrlsAnExplicitHttps(): void
    {
        $this->assertStringContainsString(
            'href="https://example.co.za"',
            RichText::sanitise('<p><a href="example.co.za">x</a></p>')
        );
        $this->assertStringContainsString(
            'href="https://evil.example/x"',
            RichText::sanitise('<p><a href="//evil.example/x">x</a></p>')
        );
    }

    // ------------------------------------------------------------ attributes

    public function testStripsStyleAttribute(): void
    {
        // Would be dropped by the CSP on the public page anyway; removing it
        // here is what stops the editor showing a format that cannot survive.
        $out = RichText::sanitise('<p style="color:red">x</p>');

        $this->assertSame('<p>x</p>', $out);
    }

    public function testKeepsOnlyQuillAlignAndIndentClasses(): void
    {
        $out = RichText::sanitise('<p class="ql-align-center evil ql-indent-2">x</p>');

        $this->assertStringContainsString('ql-align-center', $out);
        $this->assertStringContainsString('ql-indent-2', $out);
        $this->assertStringNotContainsString('evil', $out);
    }

    public function testStripsIdAndDataAttributes(): void
    {
        $out = RichText::sanitise('<p id="x" data-foo="bar">x</p>');

        $this->assertSame('<p>x</p>', $out);
    }

    // ---------------------------------------------------------------- shapes

    public function testConvertsQuillBulletListToRealUl(): void
    {
        // Quill 2 has no <ul>: bullets are an <ol> with data-list on the items.
        $out = RichText::sanitise('<ol><li data-list="bullet">one</li><li data-list="bullet">two</li></ol>');

        $this->assertSame('<ul><li>one</li><li>two</li></ul>', $out);
    }

    public function testLeavesOrderedListsAlone(): void
    {
        $out = RichText::sanitise('<ol><li data-list="ordered">one</li></ol>');

        $this->assertSame('<ol><li>one</li></ol>', $out);
    }

    public function testRemapsCloseEnoughTags(): void
    {
        $out = RichText::sanitise('<h1>T</h1><b>b</b><i>i</i><strike>s</strike>');

        $this->assertStringContainsString('<h2>T</h2>', $out);
        $this->assertStringContainsString('<strong>b</strong>', $out);
        $this->assertStringContainsString('<em>i</em>', $out);
        $this->assertStringContainsString('<s>s</s>', $out);
        $this->assertStringNotContainsString('<h1>', $out);
    }

    public function testUnwrapsDisallowedElementsButKeepsTheirText(): void
    {
        $out = RichText::sanitise('<p><span class="x">kept</span></p><table><tr><td>cell</td></tr></table>');

        $this->assertStringNotContainsString('<span', $out);
        $this->assertStringNotContainsString('<table', $out);
        $this->assertStringContainsString('kept', $out);
        $this->assertStringContainsString('cell', $out);
    }

    public function testWrapsStrayInlineContentInAParagraph(): void
    {
        $out = RichText::sanitise('hello <strong>world</strong><p>next</p>');

        $this->assertSame('<p>hello <strong>world</strong></p><p>next</p>', $out);
    }

    // ----------------------------------------------------------------- empty

    public function testAnUntouchedEditorIsEmpty(): void
    {
        // Quill posts this, not ''. Storing it would light up the About panel
        // on every profile whose owner left the field alone.
        $this->assertSame('', RichText::sanitise('<p><br></p>'));
    }

    public function testWhitespaceOnlyMarkupIsEmpty(): void
    {
        $this->assertSame('', RichText::sanitise('<p></p><p>   </p><p><br></p>'));
        $this->assertSame('', RichText::sanitise('   '));
        $this->assertSame('', RichText::sanitise(''));
    }

    // ------------------------------------------------------------ plain text

    public function testPlainTextInputBecomesParagraphs(): void
    {
        // A no-JS submit, or a row written before the editor existed.
        $out = RichText::sanitise("Line one\nLine two\n\nPara two");

        $this->assertSame('<p>Line one<br>Line two</p><p>Para two</p>', $out);
    }

    public function testPlainTextInputIsEscapedNotInterpreted(): void
    {
        $out = RichText::sanitise('5 > 3 & 2 < 4');

        $this->assertStringNotContainsString('<4', $out);
        $this->assertStringContainsString('&gt;', $out);
        $this->assertStringContainsString('&amp;', $out);
    }

    public function testPlainTextKeepsItsLineBreaksEvenWithAnInlineTagInIt(): void
    {
        // The no-JS path. Someone typing "use <b> for bold" into a plain
        // textarea means those characters literally — and treating the field as
        // HTML on the strength of them used to collapse every line break into
        // insignificant whitespace inside one paragraph.
        $out = RichText::sanitise("One\nTwo\n\nUse <b>this</b>.");

        $this->assertSame('<p>One<br>Two</p><p>Use &lt;b&gt;this&lt;/b&gt;.</p>', $out);
    }

    public function testEditorOutputIsTreatedAsMarkup(): void
    {
        // The other side of the same decision: real editor output always
        // carries block structure, so it must never take the plain-text branch.
        $out = RichText::sanitise('<p>Real <strong>editor</strong> output</p><ul><li>a</li></ul>');

        $this->assertSame('<p>Real <strong>editor</strong> output</p><ul><li>a</li></ul>', $out);
    }

    public function testToPlainTextFlattensBlocksToLines(): void
    {
        $text = RichText::toPlainText('<h2>T</h2><p>a<br>b</p><ul><li>x</li><li>y</li></ul>');

        $this->assertSame("T\na\nb\nx\ny", $text);
    }

    public function testToPlainTextDecodesEntitiesAndNormalisesSpaces(): void
    {
        $this->assertSame('Café — ok', RichText::toPlainText('<p>Caf&eacute;&nbsp;&mdash; ok</p>'));
    }

    public function testLengthIsCountedOnWordsNotMarkup(): void
    {
        // The whole point of the plain-text cap: formatting must not eat into
        // the owner's allowance.
        $html = RichText::sanitise('<p><strong>abcde</strong></p>');

        $this->assertSame(5, mb_strlen(RichText::toPlainText($html)));
    }

    public function testAccentedCharactersSurviveTheRoundTrip(): void
    {
        $out = RichText::sanitise('<p>Café — Müller ‘quoted’</p>');

        $this->assertStringContainsString('Café — Müller ‘quoted’', $out);
    }

    // ---------------------------------------------------------------- limits

    public function testCapsPathologicalNesting(): void
    {
        $html = str_repeat('<blockquote>', 200) . 'deep' . str_repeat('</blockquote>', 200);
        $out  = RichText::sanitise($html);

        $this->assertLessThan(20, substr_count($out, '<blockquote>'));
        $this->assertStringContainsString('deep', $out);
    }

    public function testCapsNodeCount(): void
    {
        $out = RichText::sanitise(str_repeat('<p>x</p>', 5000));

        $this->assertLessThan(2100, substr_count($out, '<p>'));
    }
}
