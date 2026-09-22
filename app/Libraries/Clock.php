<?php

namespace App\Libraries;

use DateTimeImmutable;

/**
 * The application's "today".
 *
 * Days overdue, grant time elapsed and the current period are all measured from
 * this date. It is the real date unless app.asOf is set in .env, which pins the
 * books to a date — for example to review the ledger as it stood at a period end.
 */
final class Clock
{
    public static function today(): DateTimeImmutable
    {
        $asOf = env('app.asOf');

        return new DateTimeImmutable(is_string($asOf) && $asOf !== '' ? $asOf : 'today');
    }

    /** Y-m-d */
    public static function date(): string
    {
        return self::today()->format('Y-m-d');
    }

    /** Today's date at the current time of day, for timestamps written now. */
    public static function timestamp(): string
    {
        return self::date() . ' ' . date('H:i:s');
    }

    /** Whole days from today to a date: negative once it has passed. */
    public static function daysUntil(?string $date): ?int
    {
        if ($date === null || $date === '') {
            return null;
        }

        $diff = self::today()->diff(new DateTimeImmutable(substr($date, 0, 10)));

        return (int) $diff->format('%r%a');
    }
}
