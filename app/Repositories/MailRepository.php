<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Secret;
use Config\Email;

/**
 * Settings → Integrations → Email: the SMTP server invitations, password resets
 * and sign-in codes go out through.
 *
 * Production sets it here rather than in .env, so the Finance Manager can move
 * the organisation to another mail provider without a deployment, and every change
 * is in the settings audit log. It is held on the head office's settings rows
 * (kind 'mail'); the password encrypted (App\Libraries\Secret) and never served —
 * the screen is told whether it is set and its last four characters.
 *
 * Which server a message actually goes through (transport()):
 *
 * - outside production, .env wins whenever it names an SMTP host (email.SMTPHost),
 *   so a developer's Mailpit catches everything even on a copy of a production
 *   database that carries the real server;
 * - otherwise the server set here, once it has a host;
 * - otherwise whatever .env holds (Config\Email), as before this screen existed.
 */
final class MailRepository extends Repository
{
    public const ENCRYPTIONS = ['tls' => 'STARTTLS', 'ssl' => 'SSL/TLS', '' => 'None'];

    /** Screen field → settings key, label, and the value an unset field reads as. */
    private const FIELDS = [
        'host'      => ['mailHost', 'SMTP server', ''],
        'port'      => ['mailPort', 'SMTP port', '587'],
        'crypto'    => ['mailCrypto', 'Encryption', 'tls'],
        'username'  => ['mailUsername', 'SMTP username', ''],
        'fromEmail' => ['mailFromEmail', 'Messages come from', ''],
        'fromName'  => ['mailFromName', 'Sender name', ''],
    ];

    private const PASSWORD_KEY = 'mailPassword';

    private Lookups $lookups;

    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        parent::__construct($db);
        $this->lookups = new Lookups();
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /** The server as the screen shows it. The password is never included. */
    public function server(): array
    {
        $held = $this->held();
        $out = [];
        foreach (self::FIELDS as $field => [$key, , $default]) {
            $out[$field] = (string) ($held[$key] ?? $default);
        }
        $sealed = $held[self::PASSWORD_KEY] ?? null;
        $transport = $this->transport();

        return $out + [
            'password'   => ['set' => Secret::isSet($sealed), 'readable' => Secret::readable($sealed), 'hint' => Secret::hint($sealed)],
            'inUse'      => ['source' => $transport['source'], 'note' => $this->inUseNote($transport)],
            'encryption' => Secret::configured(),
        ];
    }

    /** What the screen offers in its selects. */
    public function options(): array
    {
        return ['encryptions' => array_map(static fn ($v, $t) => ['value' => (string) $v, 'text' => $t], array_keys(self::ENCRYPTIONS), self::ENCRYPTIONS)];
    }

    /**
     * The settings CodeIgniter's email service is initialised with for the next
     * message, and where they came from: 'env' or 'settings'.
     *
     * @return array{source: string, config: array<string, mixed>}
     */
    public function transport(): array
    {
        $env = config(Email::class);
        $held = $this->held();
        $host = trim((string) ($held['mailHost'] ?? ''));

        if ((ENVIRONMENT !== 'production' && trim($env->SMTPHost) !== '') || $host === '') {
            return ['source' => 'env', 'config' => [
                'protocol' => $env->protocol, 'SMTPHost' => $env->SMTPHost, 'SMTPPort' => $env->SMTPPort, 'SMTPUser' => $env->SMTPUser,
                'SMTPPass' => $env->SMTPPass, 'SMTPCrypto' => $env->SMTPCrypto, 'SMTPTimeout' => $env->SMTPTimeout,
                'fromEmail' => $env->fromEmail, 'fromName' => $env->fromName,
            ]];
        }

        return ['source' => 'settings', 'config' => [
            'protocol' => 'smtp', 'SMTPHost' => $host, 'SMTPPort' => (int) ($held['mailPort'] ?? 587),
            'SMTPUser' => (string) ($held['mailUsername'] ?? ''), 'SMTPPass' => Secret::open($held[self::PASSWORD_KEY] ?? null),
            'SMTPCrypto' => (string) ($held['mailCrypto'] ?? 'tls'), 'SMTPTimeout' => max(5, $env->SMTPTimeout),
            'fromEmail' => (string) ($held['mailFromEmail'] ?? ''), 'fromName' => (string) ($held['mailFromName'] ?? ''),
        ]];
    }

    // ------------------------------------------------------------------
    // Saving
    // ------------------------------------------------------------------

    /**
     * Applies what differs from what is held, in one transaction, and writes each
     * change to the audit log in words — never the password itself.
     *
     * @param array $in any of host, port, crypto, username, fromEmail, fromName;
     *        password only when it has been typed; clearPassword to remove it
     * @return list<array{area: string, what: string}>
     */
    public function save(array $in, int $actorId): array
    {
        $held = $this->held();
        $next = [];
        $changes = [];

        foreach (self::FIELDS as $field => [$key, $label, $default]) {
            if (!array_key_exists($field, $in)) {
                continue;
            }
            $value = $this->checked($field, trim((string) $in[$field]));
            $current = (string) ($held[$key] ?? $default);
            if ($value === $current) {
                continue;
            }
            $next[$key] = [$value, $label];
            $changes[] = $this->said($field, $label, $current, $value);
        }

        $sealed = $held[self::PASSWORD_KEY] ?? null;
        if (!empty($in['clearPassword'])) {
            if (Secret::isSet($sealed)) {
                $next[self::PASSWORD_KEY] = ['', 'SMTP password'];
                $changes[] = 'Mail server password cleared';
            }
        } elseif (trim((string) ($in['password'] ?? '')) !== '') {
            $password = (string) $in['password'];
            if (!Secret::configured()) {
                throw new RuleViolation('The mail server password is held encrypted, and this installation has no encryption key. Set encryption.key in .env (php spark key:generate) before entering it.');
            }
            if (mb_strlen($password) > 120) {
                throw new RuleViolation('The mail server password is longer than 120 characters — check it was pasted whole.');
            }
            if (Secret::open($sealed) !== $password) {
                $next[self::PASSWORD_KEY] = [Secret::seal($password), 'SMTP password'];
                $changes[] = 'Mail server password ' . (Secret::isSet($sealed) ? 'replaced' : 'set');
            }
        }

        // A server that would be used has to be able to say who the mail is from.
        $host = $next['mailHost'][0] ?? (string) ($held['mailHost'] ?? '');
        $from = $next['mailFromEmail'][0] ?? (string) ($held['mailFromEmail'] ?? '');
        if ($host !== '' && $from === '') {
            throw new RuleViolation('Give the address messages come from. Most mail providers refuse mail from an address they have not verified.');
        }

        if ($changes === []) {
            return [];
        }

        $this->transaction(function () use ($next, $changes, $actorId) {
            foreach ($next as $key => [$value, $label]) {
                $this->hold($key, $value, $label);
            }
            foreach ($changes as $what) {
                $this->audit('settings:integrations', null, null, $what, $actorId, 'settings.changed', $this->lookups->headOfficeId());
            }
        });

        return array_map(static fn ($what) => ['area' => 'Integrations', 'what' => $what], $changes);
    }

    /** Records that a test message was sent, and whether the server took it. */
    public function logTest(string $to, bool $sent, int $actorId): void
    {
        $this->transaction(fn () => $this->audit('settings:integrations', null, null,
            'Test message ' . ($sent ? 'sent' : 'could not be sent') . ' to ' . $to . ' through ' . $this->describe($this->transport()), $actorId, 'settings.changed', $this->lookups->headOfficeId()));
    }

    // ------------------------------------------------------------------

    private function checked(string $field, string $value): string
    {
        switch ($field) {
            case 'host':
                $value = strtolower($value);
                if ($value !== '' && (strlen($value) > 253 || (filter_var($value, FILTER_VALIDATE_IP) === false
                        && preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $value) !== 1))) {
                    throw new RuleViolation($value . ' is not a server name. Give the host only — smtp.office365.com — without smtp:// or a port.');
                }

                return $value;
            case 'port':
                if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 65535) {
                    throw new RuleViolation('The SMTP port is a number — usually 587 with STARTTLS, or 465 with SSL/TLS.');
                }

                return (string) (int) $value;
            case 'crypto':
                if (!array_key_exists($value, self::ENCRYPTIONS)) {
                    throw new RuleViolation('Choose STARTTLS, SSL/TLS or none for the mail server\'s encryption.');
                }

                return $value;
            case 'username':
                if (mb_strlen($value) > 200) {
                    throw new RuleViolation('The SMTP username is longer than 200 characters.');
                }

                return $value;
            case 'fromEmail':
                $value = mb_strtolower($value);
                if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    throw new RuleViolation($value . ' is not an email address.');
                }

                return $value;
            case 'fromName':
                if (mb_strlen($value) > 120 || preg_match('/[\r\n]/', $value) === 1) {
                    throw new RuleViolation('The sender name is one line of at most 120 characters.');
                }

                return $value;
        }

        return $value;
    }

    private function said(string $field, string $label, string $from, string $to): string
    {
        $show = static fn (string $v) => $field === 'crypto' ? self::ENCRYPTIONS[$v] : ($v === '' ? 'nothing' : $v);

        return match (true) {
            $to === ''   => $label . ' cleared',
            $from === '' => $label . ' set to ' . $show($to),
            default      => $label . ' changed from ' . $show($from) . ' to ' . $show($to),
        };
    }

    private function inUseNote(array $transport): string
    {
        $c = $transport['config'];
        if ($transport['source'] === 'settings') {
            return 'Mail goes out through ' . $this->describe($transport) . '.';
        }
        if (ENVIRONMENT !== 'production' && trim((string) $c['SMTPHost']) !== '') {
            return 'This is a ' . ENVIRONMENT . ' instance, so mail goes to ' . $this->describe($transport)
                . ' as .env says, whatever is set here. Production uses the server below.';
        }

        return 'No mail server is set here, so mail goes out ' . ($c['protocol'] === 'smtp' && $c['SMTPHost'] !== '' ? 'through ' . $this->describe($transport) : 'by the server\'s own ' . $c['protocol'])
            . ' as .env says. Set a server below to send through your mail provider.';
    }

    private function describe(array $transport): string
    {
        $c = $transport['config'];

        return $c['protocol'] === 'smtp' ? $c['SMTPHost'] . ':' . $c['SMTPPort'] : 'the server\'s ' . $c['protocol'];
    }

    /** @return array<string, string> settings key → value, as held on the head office */
    private function held(): array
    {
        return $this->cached('mail', fn () => array_column($this->rows(
            "SELECT s.key, s.value FROM {settings} s WHERE s.entity_id = ? AND s.kind = 'mail'", [$this->lookups->headOfficeId()]
        ), 'value', 'key'));
    }

    /** Written rather than updated blind: until a field is first set it has no row. */
    private function hold(string $key, string $value, string $label): void
    {
        $entityId = $this->lookups->headOfficeId();
        $held = $this->db->table('settings')->select('id')->where('entity_id', $entityId)->where('key', $key)->get()->getRowArray();
        $now = Clock::timestamp();

        if ($held === null) {
            $this->insert('settings', ['entity_id' => $entityId, 'key' => $key, 'kind' => 'mail', 'value' => $value, 'label' => $label, 'created_at' => $now]);

            return;
        }

        $this->db->table('settings')->where('id', $held['id'])->update(['value' => $value, 'updated_at' => $now]);
    }
}
