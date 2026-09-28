<?php

use CodeIgniter\Debug\ExceptionHandler;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Exceptions;

/**
 * Trace masking must not break the error handler.
 *
 * Production runs with `zend.exception_ignore_args = On`, so trace frames have
 * no 'args' key, and CI4's maskSensitiveData() reads it unguarded. Every 404
 * crashed the handler and went out as a blank 500. Config\Exceptions turns
 * masking off when there are no arguments to mask. See its constructor.
 *
 * @internal
 */
final class ExceptionsConfigTest extends CIUnitTestCase
{
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = (string) ini_get('zend.exception_ignore_args');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->original);
        parent::tearDown();
    }

    public function testMaskingIsOffWhenTracesCarryNoArguments(): void
    {
        ini_set('zend.exception_ignore_args', '1');

        $this->assertSame([], (new Exceptions())->sensitiveDataInTrace);
    }

    public function testMaskingStaysOnWhenArgumentsAreRecorded(): void
    {
        ini_set('zend.exception_ignore_args', '0');

        $this->assertContains('manage_token', (new Exceptions())->sensitiveDataInTrace);
    }

    public function testTheHandlerCollectsATraceWithoutArguments(): void
    {
        ini_set('zend.exception_ignore_args', '1');
        $exception = PageNotFoundException::forPageNotFound();
        $this->assertArrayNotHasKey('args', $exception->getTrace()[0] ?? [], 'precondition: no args in the trace');

        $handler = new ExceptionHandler(new Exceptions());
        $collect = (new ReflectionMethod($handler, 'collectVars'))->getClosure($handler);

        // Threw "Undefined array key args" before the fix.
        $this->assertSame(404, $collect($exception, 404)['code']);
    }
}
