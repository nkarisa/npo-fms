<?php

namespace App\Libraries;

use App\Repositories\RuleViolation;
use CodeIgniter\Database\BaseConnection;
use Config\Auth;
use Throwable;

/**
 * What a new password has to be: the complexity policy set in Settings → Users.
 *
 * The policy is a matrix: a minimum length, a minimum count of each kind of
 * character (capital letters, small letters, digits, symbols or spaces), how many
 * of those kinds must appear at all, how many different characters, and whether a
 * password may carry the person's own name or email or be one of the first anyone
 * would try. Two rules look past the password itself: how many of the person's
 * earlier passwords it may not repeat (`history`, kept in password_history), and
 * after how many days it has to be changed (`maxAgeDays`, 0 for never). It is held
 * as one setting on the head office (key passwordPolicy); until someone saves one,
 * the standard below applies — twelve characters of any kind, which a passphrase
 * of a few words meets, with no history and no expiry.
 *
 * The matrix is checked when a password is chosen — from an invitation, a reset
 * link, My account or the installer — never at sign-in, so tightening it does not
 * lock anyone out: each person meets the new rules the next time they change
 * theirs. Expiry is the exception, and is asked at sign-in: once a password is
 * older than maxAgeDays, the person chooses a new one before they reach anything
 * (App\Libraries\SignIn::RENEW).
 */
final class PasswordPolicy
{
    public const KEY = 'passwordPolicy';

    /** The kinds of character, in the order the matrix lists them: key => [name, examples]. */
    public const KINDS = [
        'upper'  => ['Capital letters', 'A–Z'],
        'lower'  => ['Small letters', 'a–z'],
        'digit'  => ['Digits', '0–9'],
        'symbol' => ['Symbols or spaces', '! @ # % – or a space'],
    ];

    /** What each number in the policy may be: [lowest, highest]. */
    public const RANGES = [
        'minLength' => [8, 64],
        'distinct'  => [1, 20],
        'kinds'     => [1, 4],
        'kind'      => [0, 10],
        'history'   => [0, 24],
        'maxAgeDays' => [30, 365],
    ];

    /** Earlier passwords kept per person: as many as `history` can ever ask about. */
    public const HISTORY_KEPT = 24;

    /** Longer than this is a paste gone wrong, and bcrypt reads only the first 72 bytes. */
    public const MAX_LENGTH = 200;

    /**
     * Passwords refused wherever `common` is on, lower-cased. A password is also
     * refused when it is one of these with digits or symbols added at the end,
     * the way "Password2026!" is.
     */
    private const COMMON = [
        'password', 'passw0rd', 'p@ssword', 'p@ssw0rd', 'password1234', 'passwordpassword', '12345678', '123456789',
        '1234567890', '123456789012', 'qwertyui', 'qwertyuiop', 'qwertyuiopas', 'qwerty123', 'iloveyou', 'letmein',
        'welcome', 'admin', 'administrator', 'changeme', 'changemechangeme', 'abc12345', 'abcd1234', 'football', 'monkey',
        'sunshine', 'princess', 'dragon', 'trustno1', 'master', 'superman', 'baseball', 'starwars', 'whatever', 'secret',
    ];

    /** The policy when none has been saved. */
    public static function standard(): array
    {
        return [
            'minLength' => max(self::RANGES['minLength'][0], min(self::RANGES['minLength'][1], config(Auth::class)->minPasswordLength)),
            'distinct'  => 5,
            'kinds'     => 1,
            'upper'     => 0,
            'lower'     => 0,
            'digit'     => 0,
            'symbol'    => 0,
            'personal'  => true,
            'common'    => true,
            'history'   => 0,
            'maxAgeDays' => 0,
        ];
    }

    /**
     * The policy in force: the one saved on the head office, else the standard. An
     * instance still being installed has no head office yet, and gets the standard.
     */
    public static function current(?BaseConnection $db = null): array
    {
        $db ??= db_connect();
        try {
            $row = $db->table('settings s')->select('s.value')
                ->join('entities e', 'e.id = s.entity_id')
                ->where('e.parent_id', null)->where('s.key', self::KEY)
                ->orderBy('e.id')->limit(1)->get()->getRowArray();
        } catch (Throwable) {
            return self::standard();
        }
        $held = $row === null ? null : json_decode((string) $row['value'], true);

        return is_array($held) ? self::merged($held) : self::standard();
    }

    /**
     * Checks a policy as the settings screen sends it, and returns it in the shape it
     * is held. Refuses a rule out of range, and more different characters than the
     * length allows. (The four kinds at most ask for 40 characters, within any length.)
     */
    public static function normalise(array $in): array
    {
        $out = self::standard();
        $whole = static function (mixed $value, string $range, string $what): int {
            [$low, $high] = self::RANGES[$range];
            $text = trim((string) $value);
            if (!ctype_digit($text) || (int) $text < $low || (int) $text > $high) {
                throw new RuleViolation($what . ' has to be a whole number from ' . $low . ' to ' . $high . '.');
            }

            return (int) $text;
        };

        $out['minLength'] = $whole($in['minLength'] ?? $out['minLength'], 'minLength', 'The minimum length');
        $out['distinct'] = $whole($in['distinct'] ?? $out['distinct'], 'distinct', 'The number of different characters');
        $out['kinds'] = $whole($in['kinds'] ?? $out['kinds'], 'kinds', 'The number of kinds of character');
        foreach (self::KINDS as $key => [$name]) {
            $out[$key] = $whole($in[$key] ?? 0, 'kind', 'The minimum of ' . mb_strtolower($name));
        }
        $out['history'] = $whole($in['history'] ?? 0, 'history', 'The number of earlier passwords that cannot be used again');
        // 0 for never; otherwise at least a month, so nobody is asked more often than that.
        $out['maxAgeDays'] = (string) ($in['maxAgeDays'] ?? '0') === '0' ? 0 : $whole($in['maxAgeDays'], 'maxAgeDays', 'The days a password lasts (0 for never)');
        $out['personal'] = filter_var($in['personal'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $out['common'] = filter_var($in['common'] ?? true, FILTER_VALIDATE_BOOLEAN);

        if ($out['distinct'] > $out['minLength']) {
            throw new RuleViolation('A password of ' . $out['minLength'] . ' characters cannot have ' . $out['distinct'] . ' different ones. Ask for fewer different characters, or a longer password.');
        }
        // Asking for a kind by its own minimum already asks for it to appear.
        $named = count(array_filter(array_keys(self::KINDS), static fn ($k) => $out[$k] > 0));
        $out['kinds'] = max($out['kinds'], $named);

        return $out;
    }

    /**
     * Refuses a password the policy does not allow, with what to change in words
     * the person can act on. $user carries the email and, where known, the name.
     */
    public static function check(array $policy, string $password, array $user): void
    {
        $policy = self::merged($policy);
        $length = mb_strlen($password);
        $counts = self::counts($password);

        if ($length < $policy['minLength']) {
            throw new RuleViolation('Use at least ' . $policy['minLength'] . ' characters. A few unrelated words make a long password that is easy to remember.');
        }
        if (strlen($password) > self::MAX_LENGTH) {
            throw new RuleViolation('That password is longer than ' . self::MAX_LENGTH . ' characters.');
        }
        foreach (self::KINDS as $key => [$name, $examples]) {
            if ($counts[$key] < $policy[$key]) {
                throw new RuleViolation('Include at least ' . self::countOf($policy[$key], $name) . ' (' . $examples . ').');
            }
        }
        $present = count(array_filter($counts));
        if ($present < $policy['kinds']) {
            throw new RuleViolation('Mix at least ' . $policy['kinds'] . ' of the four kinds of character: capital letters, small letters, digits, and symbols or spaces. That password has ' . $present . '.');
        }
        if (count(array_unique(mb_str_split($password))) < $policy['distinct']) {
            throw new RuleViolation('Use at least ' . $policy['distinct'] . ' different characters. That password repeats too few to be hard to guess.');
        }
        if ($policy['personal'] && ($part = self::personalPart($password, $user)) !== null) {
            throw new RuleViolation('Leave your ' . $part . ' out of your password.');
        }
        if ($policy['common'] && self::isCommon($password)) {
            throw new RuleViolation('That password is one of the first anyone would try.');
        }
    }

    /**
     * The rules as the forms list them, each with the numbers a browser needs to tick
     * it off as the person types: {key, text, min}. `common` is checked on the server
     * only, so it is listed without a number.
     *
     * @return list<array{key: string, text: string, min?: int}>
     */
    public static function describe(array $policy): array
    {
        $policy = self::merged($policy);
        $rules = [['key' => 'length', 'text' => 'At least ' . $policy['minLength'] . ' characters', 'min' => $policy['minLength']]];
        foreach (self::KINDS as $key => [$name, $examples]) {
            if ($policy[$key] > 0) {
                $rules[] = ['key' => $key, 'text' => 'At least ' . self::countOf($policy[$key], $name) . ' (' . $examples . ')', 'min' => $policy[$key]];
            }
        }
        $named = count(array_filter(array_keys(self::KINDS), static fn ($k) => $policy[$k] > 0));
        if ($policy['kinds'] > max(1, $named)) {
            $rules[] = ['key' => 'kinds', 'text' => 'At least ' . $policy['kinds'] . ' of: capital letters, small letters, digits, symbols or spaces', 'min' => $policy['kinds']];
        }
        if ($policy['distinct'] > 1) {
            $rules[] = ['key' => 'distinct', 'text' => 'At least ' . $policy['distinct'] . ' different characters', 'min' => $policy['distinct']];
        }
        if ($policy['personal']) {
            $rules[] = ['key' => 'personal', 'text' => 'Not your name or email address'];
        }
        if ($policy['common']) {
            $rules[] = ['key' => 'common', 'text' => 'Not a password anyone would try first'];
        }
        if ($policy['history'] > 0) {
            $rules[] = ['key' => 'history', 'text' => $policy['history'] === 1 ? 'Not the password you have now' : 'Not one of your last ' . $policy['history'] . ' passwords'];
        }

        return $rules;
    }

    /** The policy in one line, for the audit log. */
    public static function summary(array $policy): string
    {
        $policy = self::merged($policy);
        $words = array_column(self::describe($policy), 'text');
        $words[] = $policy['maxAgeDays'] > 0 ? 'Changed every ' . $policy['maxAgeDays'] . ' days' : 'Never expires';

        return implode(' · ', $words);
    }

    /**
     * When a password set at $changedAt stops working under the policy, as a Unix
     * time; null when passwords do not expire or the date it was set is not known.
     */
    public static function expiresAt(array $policy, ?string $changedAt): ?int
    {
        $days = self::merged($policy)['maxAgeDays'];
        if ($days <= 0 || $changedAt === null || $changedAt === '') {
            return null;
        }

        return strtotime($changedAt) + $days * 86400;
    }

    // ------------------------------------------------------------------

    /** A held policy over the standard, so a rule added later has its standard value. */
    private static function merged(array $held): array
    {
        $out = self::standard();
        foreach ($out as $key => $standard) {
            if (array_key_exists($key, $held)) {
                $out[$key] = is_bool($standard) ? (bool) $held[$key] : (int) $held[$key];
            }
        }

        return $out;
    }

    /** @return array{upper: int, lower: int, digit: int, symbol: int} */
    private static function counts(string $password): array
    {
        $letters = preg_match_all('/\p{L}/u', $password);
        $digits = preg_match_all('/\p{Nd}/u', $password);

        return [
            'upper'  => preg_match_all('/\p{Lu}/u', $password),
            'lower'  => preg_match_all('/\p{Ll}/u', $password),
            'digit'  => $digits,
            'symbol' => mb_strlen($password) - $letters - $digits,
        ];
    }

    /** "1 capital letter", "2 digits". */
    private static function countOf(int $n, string $name): string
    {
        $noun = mb_strtolower($name);
        if ($n === 1) {
            $noun = match ($noun) {
                'symbols or spaces' => 'symbol or space',
                default             => rtrim($noun, 's'),
            };
        }

        return $n . ' ' . $noun;
    }

    /** Which of the person's own details the password carries: "email address", "name", or null. */
    private static function personalPart(string $password, array $user): ?string
    {
        $lower = mb_strtolower($password);
        $local = mb_strtolower(explode('@', (string) ($user['email'] ?? ''))[0]);
        if (mb_strlen($local) >= 3 && str_contains($lower, $local)) {
            return 'email address';
        }
        // Each part of the email before the @ and of the name, where long enough to mean something.
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $local . ' ' . mb_strtolower((string) ($user['name'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($parts as $part) {
            if (mb_strlen($part) >= 4 && str_contains($lower, $part)) {
                return str_contains(mb_strtolower((string) ($user['name'] ?? '')), $part) ? 'name' : 'email address';
            }
        }

        return null;
    }

    private static function isCommon(string $password): bool
    {
        $lower = mb_strtolower($password);
        // "Password2026!" is "password" with the year and a symbol on the end.
        $stem = preg_replace('/[\p{Nd}\p{P}\p{S}\s]+$/u', '', $lower);

        return in_array($lower, self::COMMON, true) || ($stem !== '' && in_array($stem, self::COMMON, true));
    }
}
