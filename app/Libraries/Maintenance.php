<?php

namespace App\Libraries;

use App\Repositories\MaintenanceRepository;
use Throwable;

/**
 * Maintenance mode: the application closed to everyone but the people who close it.
 *
 * It is closed two ways, and they mean the same thing to whoever is signed in.
 * Someone holding `settings.maintenance` switches it closed there and then — an
 * upgrade that has to happen now, a figure that must stop moving while it is
 * investigated — or books a window in advance, which closes the application by
 * itself while it runs and opens it again at the end. A window is announced to
 * everyone when it is booked and is on every page as it approaches, so nobody
 * loses work to a closure they were never told about.
 *
 * While it is closed, only a holder of `settings.maintenance` gets in: their
 * session survives (App\Filters\SignedIn), they can still sign in
 * (Api\Auth), and every page they open says the application is closed and to
 * whom. Everyone else is turned away with the message the person closing it
 * wrote, whether they are signed in already or signing in now. Nothing is done to
 * the ledger either way — maintenance mode stops people reaching the application,
 * it does not stop the books being what they are.
 *
 * This is the reading side, and it is deliberately quiet: the shell asks it on
 * every page, before anything else has touched the database, so a state that
 * cannot be read leaves the application open rather than shutting everyone out of
 * an instance that is working perfectly well. MaintenanceRepository does the
 * writing, checks the rules, and records every switch in the audit log.
 */
final class Maintenance
{
    /** Switching the application closed, and scheduling a closure. */
    public const PERMISSION = 'settings.maintenance';

    /** The settings row holding whether it is closed now, on the head office. */
    public const KEY = 'maintenance';

    public const KIND = 'maintain';

    /** The Settings section it is switched from. */
    public const SECTION = 'Maintenance';

    /** What people are told when whoever closed the application wrote nothing. */
    public const DEFAULT_MESSAGE = 'The application is closed for maintenance and will be back shortly. Nothing you have saved is affected.';

    public const MIN_REASON = 5;

    public const MAX_REASON = 240;

    /** A window has to be at least this long, and at most this long. */
    public const MIN_MINUTES = 5;

    public const MAX_HOURS = 72;

    /** How far ahead a booked window is carried on every page. */
    public const WARN_DAYS = 7;

    /**
     * How the application stands: whether it is closed, why, what to tell people
     * and what is booked next. Never throws — see the class note.
     *
     * @return array{on: bool, switched: bool, message: string, since: ?string, by: ?string,
     *               until: ?string, window: ?array, next: ?array}
     */
    public static function state(): array
    {
        try {
            return (new MaintenanceRepository())->state();
        } catch (Throwable) {
            return self::open();
        }
    }

    /** An instance with nothing to say: open, nothing booked. */
    public static function open(): array
    {
        return ['on' => false, 'switched' => false, 'message' => '', 'since' => null, 'by' => null,
            'until' => null, 'window' => null, 'next' => null];
    }

    public static function on(): bool
    {
        return self::state()['on'];
    }

    /**
     * Whether someone may use the application as it stands. Open, everyone may;
     * closed, only whoever can close it. Nobody signed in is nobody.
     *
     * @param array|null $actor as UserRepository describes one
     */
    public static function admits(?array $actor): bool
    {
        return !self::on() || in_array(self::PERMISSION, $actor['permissions'] ?? [], true);
    }

    /** What somebody turned away is told, in the words of whoever closed it. */
    public static function refusal(): string
    {
        $state = self::state();

        return ($state['message'] !== '' ? $state['message'] : self::DEFAULT_MESSAGE)
            . ($state['until'] !== null ? ' It is expected back by ' . self::clock($state['until']) . '.' : '');
    }

    /**
     * The line the application carries on every page, or null when there is nothing
     * to say: that it is closed, for those inside it, or that a window is coming.
     *
     * @return array{tone: string, title: string, note: string}|null
     */
    public static function banner(): ?array
    {
        $state = self::state();

        if ($state['on']) {
            return [
                'tone'  => 'closed',
                'title' => 'Maintenance mode — the application is closed to everyone else.',
                'note'  => trim(($state['message'] !== '' ? $state['message'] . ' ' : '')
                    . ($state['until'] !== null
                        ? 'It opens again at ' . self::when($state['until']) . '.'
                        : 'It stays closed until somebody with ' . self::PERMISSION . ' opens it again.')),
            ];
        }

        $next = $state['next'];
        if ($next === null || $next['days'] > self::WARN_DAYS) {
            return null;
        }

        return [
            'tone'  => $next['days'] <= 1 ? 'soon' : 'planned',
            'title' => 'Planned maintenance ' . $next['relative'] . ' — ' . $next['when'] . '.',
            'note'  => $next['reason'] . ' The application is closed to everyone for ' . $next['lasts'] . '. Finish and save your work before it starts.',
        ];
    }

    /**
     * The same thing said to somebody who is not inside the application: the
     * sign-in screen, where "closed to everyone else" would be the wrong way round.
     *
     * @return array{tone: string, title: string, note: string}|null
     */
    public static function notice(): ?array
    {
        $state = self::state();

        if ($state['on']) {
            return ['tone' => 'closed', 'title' => 'Closed for maintenance', 'note' => self::refusal()];
        }

        return self::banner();
    }

    // ------------------------------------------------------------------
    // Wording. One statement of it, so the shell, the API, the sign-in screen
    // and the notifications all say a time the same way.
    // ------------------------------------------------------------------

    /** "18:00" */
    public static function clock(string $at): string
    {
        return date('H:i', strtotime($at));
    }

    /** "Fri 26 Sep, 18:00" */
    public static function when(string $at): string
    {
        return date('D j M, H:i', strtotime($at));
    }

    /** "Fri 26 Sep, 18:00 – 20:00", or with both dates when it runs past midnight. */
    public static function period(string $from, string $to): string
    {
        return self::when($from) . ' – ' . (date('Y-m-d', strtotime($from)) === date('Y-m-d', strtotime($to))
            ? self::clock($to)
            : self::when($to));
    }

    /** "2 hours", "45 minutes", "1 hour 30 minutes". */
    public static function lasts(string $from, string $to): string
    {
        $minutes = (int) round((strtotime($to) - strtotime($from)) / 60);
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return trim(($hours > 0 ? $hours . ($hours === 1 ? ' hour' : ' hours') : '')
            . ($rest > 0 ? ' ' . $rest . ($rest === 1 ? ' minute' : ' minutes') : ''));
    }

    /** "in 3 days", "tomorrow", "today", "now" — how a booked window is announced. */
    public static function relative(string $at): string
    {
        $minutes = (int) round((strtotime($at) - strtotime(Clock::timestamp())) / 60);

        return match (true) {
            $minutes <= 0   => 'now',
            $minutes < 60   => 'in ' . $minutes . ($minutes === 1 ? ' minute' : ' minutes'),
            $minutes < 1440 => 'in ' . intdiv($minutes, 60) . (intdiv($minutes, 60) === 1 ? ' hour' : ' hours'),
            $minutes < 2880 => 'tomorrow',
            default         => 'in ' . intdiv($minutes, 1440) . ' days',
        };
    }
}
