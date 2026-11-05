<?php
/**
 * An appointment's day and time in the business's zone.
 *
 * @var array<string,mixed> $a
 * @var DateTimeZone        $timezone
 * @var bool                $twelveHour
 */
$s = (new DateTimeImmutable((string) $a['start_at'], new DateTimeZone('UTC')))->setTimezone($timezone);
$e = (new DateTimeImmutable((string) $a['end_at'], new DateTimeZone('UTC')))->setTimezone($timezone);
$f = ($twelveHour ?? false) ? 'g:ia' : 'H:i';
echo esc($s->format('l j F Y') . ', ' . $s->format($f) . ' to ' . $e->format($f));
