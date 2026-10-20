<?php

namespace App\Libraries;

/**
 * The two shapes posted form text is cut to before it is stored or mailed.
 *
 * These were private clean()/cleanText()/headerSafe() copies in every
 * controller and service that takes a public form, identical byte for byte.
 */
final class Input
{
    /**
     * One line, capped. No CR, LF or tab survives, because names and emails
     * reach mail headers, and a bare newline there lets a submitter add a Bcc.
     */
    public static function line(mixed $v, int $max = PHP_INT_MAX): string
    {
        if (! is_scalar($v)) {
            return '';
        }

        return mb_substr(trim((string) preg_replace('/[\r\n\t]+/', ' ', (string) $v)), 0, $max);
    }

    /** Free text: keep the visitor's line breaks, cap the length. */
    public static function text(mixed $v, int $max): string
    {
        if (! is_scalar($v)) {
            return '';
        }

        return mb_substr(trim(str_replace("\r\n", "\n", (string) $v)), 0, $max);
    }
}
