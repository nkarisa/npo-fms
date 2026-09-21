<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Daraja;
use App\Libraries\Prototype;
use App\Libraries\Secret;

/**
 * Settings → Integrations → M-Pesa: the Safaricom short code the organisation
 * collects and pays through, the credentials it signs with, and which of the two
 * services are switched on.
 *
 * Collections (a payer sends money to the short code) and disbursements (the
 * organisation pays out — supplier bills and observer stipends) are separate
 * services at Safaricom and separate here: each is switched on only once
 * everything it needs is in place, and the screen says what is still missing
 * rather than failing at the first payment.
 *
 * Whatever the integration moves lands on one mobile-money cash account, so the
 * M-Pesa statement reconciles against the same account the ledger posts to. The
 * credentials are held encrypted (App\Libraries\Secret) and never served to the
 * browser — the screen is told which are set and their last four characters.
 *
 * Every change is written to the same audit log as the rest of Settings, in words
 * ("M-Pesa environment moved from Sandbox to Production"), and never records a
 * credential's value.
 */
final class MpesaRepository extends Repository
{
    /** Short enough to read in a sentence in the audit log. */
    public const ENVIRONMENTS = ['sandbox' => 'Sandbox', 'production' => 'Production'];

    public const SHORTCODE_KINDS = ['paybill' => 'Paybill', 'till' => 'Buy Goods till'];

    /** How the same choices read on the screen, where there is room to explain them. */
    private const CHOICE_NOTES = [
        'sandbox' => 'Safaricom\'s test environment', 'production' => 'live money',
        'paybill' => 'payers quote an account number', 'till' => 'payers quote nothing',
    ];

    /**
     * The credentials Daraja signs with: the column each is held in, what it is
     * called on the screen, and which service cannot run without it.
     */
    public const CREDENTIALS = [
        'consumerKey'        => ['column' => 'consumer_key', 'label' => 'Consumer key', 'needs' => 'both', 'note' => 'From the app on the Daraja portal.'],
        'consumerSecret'     => ['column' => 'consumer_secret', 'label' => 'Consumer secret', 'needs' => 'both', 'note' => 'Shown once when the Daraja app is created.'],
        'passkey'            => ['column' => 'passkey', 'label' => 'Passkey', 'needs' => 'collections', 'note' => 'Issued with the short code; signs the STK push password.'],
        'securityCredential' => ['column' => 'security_credential', 'label' => 'Security credential', 'needs' => 'disbursements', 'note' => 'The initiator password encrypted with Safaricom\'s certificate.'],
    ];

    /** What Safaricom posts back to, under the callback address. */
    public const CALLBACKS = [
        'validation'  => ['path' => '/api/mpesa/validation', 'label' => 'Validation', 'needs' => 'collections'],
        'confirmation' => ['path' => '/api/mpesa/confirmation', 'label' => 'Confirmation', 'needs' => 'collections'],
        'result'      => ['path' => '/api/mpesa/result', 'label' => 'Payment result', 'needs' => 'disbursements'],
        'timeout'     => ['path' => '/api/mpesa/timeout', 'label' => 'Payment timeout', 'needs' => 'disbursements'],
    ];

    private const DEFAULTS = [
        'environment' => 'sandbox', 'shortcode' => null, 'shortcode_kind' => 'paybill', 'account_reference' => null,
        'callback_base' => null, 'consumer_key' => null, 'consumer_secret' => null, 'passkey' => null,
        'initiator_name' => null, 'security_credential' => null, 'collections_on' => 0, 'disbursements_on' => 0,
        'auto_match' => 1, 'payment_ceiling' => 150000, 'bank_account_id' => null, 'checked_at' => null, 'check_result' => null,
    ];

    private Lookups $lookups;

    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        parent::__construct($db);
        $this->lookups = new Lookups();
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /** The integration as the screen shows it. No credential value is included. */
    public function integration(): array
    {
        $r = $this->held();
        $account = $this->accountCode($r['bank_account_id'] === null ? null : (int) $r['bank_account_id']);
        $base = (string) ($r['callback_base'] ?? '');

        return [
            'environment'      => (string) $r['environment'],
            'shortcode'        => (string) ($r['shortcode'] ?? ''),
            'shortcodeKind'    => (string) $r['shortcode_kind'],
            'accountReference' => (string) ($r['account_reference'] ?? ''),
            'callbackBase'     => $base,
            'initiatorName'    => (string) ($r['initiator_name'] ?? ''),
            'account'          => $account,
            'collections'      => (bool) $r['collections_on'],
            'disbursements'    => (bool) $r['disbursements_on'],
            'autoMatch'        => (bool) $r['auto_match'],
            'ceiling'          => number_format((float) $r['payment_ceiling'], 2, '.', ''),
            'credentials'      => $this->credentialState($r),
            'callbacks'        => $this->callbacks($base),
            'statementFormat'  => $account === '' ? '' : (string) (($this->statementFormat($account))['name'] ?? ''),
            'status'           => $this->status($r),
            'checked'          => $r['checked_at'] === null ? null : [
                'when' => date('j M Y H:i', strtotime((string) $r['checked_at'])), 'result' => (string) ($r['check_result'] ?? ''),
            ],
            'outstanding'      => $this->outstanding($r),
            'pending'          => $this->pendingDisbursements(),
            'encryption'       => Secret::configured(),
        ];
    }

    /** What the screen offers in its selects. */
    public function options(): array
    {
        return [
            'environments' => self::choices(self::ENVIRONMENTS),
            'kinds'        => self::choices(self::SHORTCODE_KINDS),
            'accounts'     => array_values(array_map(static fn ($b) => [
                'value' => (string) $b['code'], 'text' => $b['code'] . ' · ' . $b['name'], 'currency' => $b['currency'],
            ], $this->mobileAccounts())),
            'ceiling'      => Daraja::TRANSACTION_CEILING,
            'hosts'        => Daraja::HOSTS,
        ];
    }

    /** The credentials the Daraja client signs with. Server-side only. */
    public function credentials(): array
    {
        $r = $this->held();

        return array_map(static fn ($c) => Secret::open($r[$c['column']] ?? null), self::CREDENTIALS);
    }

    // ------------------------------------------------------------------
    // Saving
    // ------------------------------------------------------------------

    /**
     * Applies what differs from what is held, in one transaction, and writes each
     * change to the audit log in words.
     *
     * @param array $in any of environment, shortcode, shortcodeKind, accountReference,
     *        account, callbackBase, initiatorName, collections, disbursements, autoMatch,
     *        ceiling; a credential only when it has been typed; `clear` names credentials to remove
     * @return list<array{area: string, what: string}>
     */
    public function save(array $in, int $actorId): array
    {
        $held = $this->held();
        $next = $held;
        $changes = [];
        $say = static function (string $what) use (&$changes): void {
            $changes[] = ['area' => 'Integrations', 'what' => $what];
        };

        $this->planConnection($in, $held, $next, $say);
        $this->planCredentials($in, $held, $next, $say);
        $this->planServices($in, $held, $next, $say);

        // Nothing is written until the whole of the next state stands up.
        $this->assertComplete($held, $next);

        if ($changes === []) {
            return [];
        }

        $this->transaction(function () use ($held, $next, $changes, $actorId) {
            $row = array_intersect_key($next, self::DEFAULTS) + ['updated_by' => $actorId, 'updated_at' => Clock::timestamp()];
            if ($held['id'] === null) {
                $this->insert('mpesa_integrations', $row + ['entity_id' => $this->lookups->headOfficeId(), 'created_at' => Clock::timestamp()]);
            } else {
                $this->db->table('mpesa_integrations')->where('id', $held['id'])->update($row);
            }
            foreach ($changes as $c) {
                $this->log($c['what'], $actorId);
            }
        });

        return $changes;
    }

    /**
     * Asks Safaricom for an access token with the credentials held, and records
     * what came back. Nothing is sent and no money moves.
     *
     * @return array{ok: bool, note: string}
     */
    public function check(int $actorId): array
    {
        $held = $this->held();
        $credentials = $this->credentials();
        $result = Daraja::token((string) $held['environment'], $credentials['consumerKey'], $credentials['consumerSecret']);
        $where = self::ENVIRONMENTS[$held['environment']] ?? $held['environment'];

        $this->transaction(function () use ($held, $result, $actorId, $where) {
            $row = ['checked_at' => Clock::timestamp(), 'check_result' => mb_substr($result['note'], 0, 160), 'updated_at' => Clock::timestamp()];
            if ($held['id'] === null) {
                $this->insert('mpesa_integrations', array_intersect_key($held, self::DEFAULTS) + $row + [
                    'entity_id' => $this->lookups->headOfficeId(), 'updated_by' => $actorId, 'created_at' => Clock::timestamp(),
                ]);
            } else {
                $this->db->table('mpesa_integrations')->where('id', $held['id'])->update($row + ['updated_by' => $actorId]);
            }
            $this->log('M-Pesa connection checked against ' . $where . ' — ' . $result['note'], $actorId);
        });

        return $result;
    }

    // ------------------------------------------------------------------
    // The connection
    // ------------------------------------------------------------------

    private function planConnection(array $in, array $held, array &$next, callable $say): void
    {
        if (array_key_exists('environment', $in)) {
            $value = (string) $in['environment'];
            if (!isset(self::ENVIRONMENTS[$value])) {
                throw new RuleViolation('Choose either Safaricom\'s sandbox or production.');
            }
            if ($value !== $held['environment']) {
                $next['environment'] = $value;
                $say('M-Pesa environment moved from ' . self::ENVIRONMENTS[$held['environment']] . ' to ' . self::ENVIRONMENTS[$value]
                    . ($value === 'production' ? ' — requests now move real money' : ' — nothing raised from here will settle'));
            }
        }

        if (array_key_exists('shortcodeKind', $in)) {
            $value = (string) $in['shortcodeKind'];
            if (!isset(self::SHORTCODE_KINDS[$value])) {
                throw new RuleViolation('A short code is either a paybill or a Buy Goods till.');
            }
            if ($value !== $held['shortcode_kind']) {
                $next['shortcode_kind'] = $value;
                $say('M-Pesa short code treated as a ' . ($value === 'paybill' ? 'paybill' : 'Buy Goods till'));
            }
        }

        if (array_key_exists('shortcode', $in)) {
            $value = preg_replace('/\s+/', '', (string) $in['shortcode']);
            if ($value !== '' && preg_match('/^\d{5,7}$/', $value) !== 1) {
                throw new RuleViolation($value . ' is not a Safaricom short code. A paybill or till is five to seven digits — 509118.');
            }
            if ($value === '' && (bool) $held['collections_on'] === true) {
                throw new RuleViolation('The short code cannot be cleared while collections are switched on.');
            }
            if ($value !== (string) ($held['shortcode'] ?? '')) {
                $next['shortcode'] = $value === '' ? null : $value;
                $say($held['shortcode'] === null
                    ? 'M-Pesa short code set to ' . $value
                    : ($value === '' ? 'M-Pesa short code cleared' : 'M-Pesa short code changed from ' . $held['shortcode'] . ' to ' . $value));
            }
        }

        if (array_key_exists('accountReference', $in)) {
            $value = trim((string) $in['accountReference']);
            if (mb_strlen($value) > 20) {
                throw new RuleViolation('The account number payers quote is at most 20 characters.');
            }
            if ($value !== '' && preg_match('/^[A-Za-z0-9 _.\-#]+$/', $value) !== 1) {
                throw new RuleViolation('The account number payers quote can hold letters, digits, spaces and - _ . # only — it is typed on a phone keypad.');
            }
            if ($value !== '' && ($next['shortcode_kind'] ?? $held['shortcode_kind']) === 'till') {
                throw new RuleViolation('A Buy Goods till takes no account number — payers are not asked for one. Clear it, or make this a paybill.');
            }
            if ($value !== (string) ($held['account_reference'] ?? '')) {
                $next['account_reference'] = $value === '' ? null : $value;
                $say($value === '' ? 'M-Pesa account number payers quote cleared' : 'M-Pesa account number payers quote set to ' . $value);
            }
        }

        if (array_key_exists('account', $in)) {
            $code = trim((string) $in['account']);
            $accounts = array_column($this->mobileAccounts(), null, 'code');
            if ($code !== '' && !isset($accounts[$code])) {
                throw new RuleViolation('M-Pesa settles onto a mobile-money account, and ' . $code . ' is not one. Choose '
                    . (implode(' or ', array_keys($accounts)) ?: 'a mobile-money account set up in the chart of accounts') . '.');
            }
            $current = $this->accountCode($held['bank_account_id'] === null ? null : (int) $held['bank_account_id']);
            if ($code === '' && ((bool) $held['collections_on'] || (bool) $held['disbursements_on'])) {
                throw new RuleViolation('The settlement account cannot be cleared while M-Pesa is collecting or paying.');
            }
            if ($code !== $current) {
                $next['bank_account_id'] = $code === '' ? null : (int) $accounts[$code]['id'];
                $say($current === ''
                    ? 'M-Pesa settles to ' . $code . ' ' . $accounts[$code]['name']
                    : ($code === '' ? 'M-Pesa settlement account cleared' : 'M-Pesa settlement account changed from ' . $current . ' to ' . $code . ' ' . $accounts[$code]['name']));
            }
        }

        if (array_key_exists('callbackBase', $in)) {
            $value = rtrim(trim((string) $in['callbackBase']), '/');
            if ($value !== '') {
                $this->assertCallbackAddress($value, (string) ($next['environment'] ?? $held['environment']));
            }
            if ($value !== (string) ($held['callback_base'] ?? '')) {
                $next['callback_base'] = $value === '' ? null : $value;
                $say($value === '' ? 'M-Pesa callback address cleared' : 'M-Pesa callback address set to ' . $value);
            }
        }

        if (array_key_exists('initiatorName', $in)) {
            $value = trim((string) $in['initiatorName']);
            if ($value !== '' && preg_match('/^[A-Za-z0-9._-]{1,60}$/', $value) !== 1) {
                throw new RuleViolation('The initiator is the API user Safaricom issued — letters, digits and . _ - only.');
            }
            if ($value !== (string) ($held['initiator_name'] ?? '')) {
                $next['initiator_name'] = $value === '' ? null : $value;
                $say($value === '' ? 'M-Pesa initiator cleared' : 'M-Pesa payments initiated as ' . $value);
            }
        }

        if (array_key_exists('ceiling', $in)) {
            $text = preg_replace('/[^0-9.]/', '', (string) $in['ceiling']);
            $value = $text === '' ? 0.0 : round((float) $text, 2);
            if ($value > Daraja::TRANSACTION_CEILING) {
                throw new RuleViolation('Safaricom will not pass a single M-Pesa payment above ' . self::money(Daraja::TRANSACTION_CEILING)
                    . '. A larger payment goes by EFT, or in instalments.');
            }
            if ($value < 0) {
                throw new RuleViolation('The per-payment ceiling cannot be below nil.');
            }
            if (round((float) $held['payment_ceiling'], 2) != $value) {
                $next['payment_ceiling'] = $value;
                $say('M-Pesa per-payment ceiling ' . ($value > (float) $held['payment_ceiling'] ? 'raised' : 'lowered') . ' from '
                    . self::money((float) $held['payment_ceiling']) . ' to ' . self::money($value));
            }
        }
    }

    /** Safaricom posts results to this address; it has to be one it can actually reach. */
    private function assertCallbackAddress(string $value, string $environment): void
    {
        $parts = parse_url($value);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
            throw new RuleViolation('The callback address is the https address Safaricom posts results to — https://finance.elog.or.ke.');
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuleViolation('Give the address only — the callback paths are added to it.');
        }
        if ($environment !== 'production') {
            return;
        }
        if ($parts['scheme'] !== 'https') {
            throw new RuleViolation('Safaricom posts production results over https only. ' . $value . ' would never be called back.');
        }
        $host = strtolower($parts['host']);
        if ($host === 'localhost' || preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host) === 1 || !str_contains($host, '.')) {
            throw new RuleViolation($host . ' cannot be reached from Safaricom\'s network. Production callbacks need a public address.');
        }
    }

    // ------------------------------------------------------------------
    // Credentials
    // ------------------------------------------------------------------

    private function planCredentials(array $in, array $held, array &$next, callable $say): void
    {
        $clear = array_map('strval', (array) ($in['clear'] ?? []));

        foreach (self::CREDENTIALS as $key => $c) {
            $column = $c['column'];

            if (in_array($key, $clear, true)) {
                if (!Secret::isSet($held[$column])) {
                    continue;
                }
                $next[$column] = null;
                $say('M-Pesa ' . lcfirst($c['label']) . ' cleared');

                continue;
            }

            if (!array_key_exists($key, $in) || trim((string) $in[$key]) === '') {
                continue;
            }
            if (!Secret::configured()) {
                throw new RuleViolation('M-Pesa credentials are held encrypted, and this installation has no encryption key. Set encryption.key in .env (php spark key:generate) before entering them.');
            }
            $value = trim((string) $in[$key]);
            if (mb_strlen($value) > 500) {
                throw new RuleViolation('The ' . lcfirst($c['label']) . ' is longer than anything Safaricom issues — check it was pasted whole.');
            }
            if (Secret::open($held[$column]) === $value) {
                continue;
            }
            $next[$column] = Secret::seal($value);
            $say('M-Pesa ' . lcfirst($c['label']) . ' ' . (Secret::isSet($held[$column]) ? 'replaced' : 'set'));
        }
    }

    // ------------------------------------------------------------------
    // Services
    // ------------------------------------------------------------------

    private function planServices(array $in, array $held, array &$next, callable $say): void
    {
        if (array_key_exists('collections', $in) && (bool) $in['collections'] !== (bool) $held['collections_on']) {
            $on = (bool) $in['collections'];
            $next['collections_on'] = (int) $on;
            $say('M-Pesa collections turned ' . ($on ? 'on — payments to the short code are received into the ledger' : 'off — the short code still receives money, but nothing reaches the ledger from it'));
        }

        if (array_key_exists('disbursements', $in) && (bool) $in['disbursements'] !== (bool) $held['disbursements_on']) {
            $on = (bool) $in['disbursements'];
            if (!$on) {
                $pending = $this->pendingDisbursements();
                if ($pending['total'] > 0) {
                    throw new RuleViolation('M-Pesa payments cannot be switched off while ' . $pending['note']
                        . '. Pay or reschedule them to another method first.');
                }
            }
            $next['disbursements_on'] = (int) $on;
            $say('M-Pesa payments turned ' . ($on ? 'on — bills and advances can be paid by M-Pesa' : 'off — M-Pesa is no longer offered as a payment method'));
        }

        if (array_key_exists('autoMatch', $in) && (bool) $in['autoMatch'] !== (bool) $held['auto_match']) {
            $on = (bool) $in['autoMatch'];
            $next['auto_match'] = (int) $on;
            $say('M-Pesa receipts ' . ($on ? 'matched to the cash book automatically by receipt number' : 'left to be matched by hand on the reconciliation'));
        }
    }

    /** A service is only switched on once everything it needs is in place. */
    private function assertComplete(array $held, array $next): void
    {
        foreach (['collections' => 'collections_on', 'disbursements' => 'disbursements_on'] as $service => $column) {
            if (!(bool) $next[$column]) {
                continue;
            }
            $missing = $this->missingFor($service, $next);
            if ($missing === []) {
                continue;
            }
            // Switching a service on, and taking away what one already running needs,
            // are the same shortfall reported two ways.
            $name = $service === 'collections' ? 'Collections' : 'M-Pesa payments';
            throw new RuleViolation((bool) $held[$column]
                ? $name . ' are switched on and cannot run without ' . self::andList($missing) . '. Set '
                    . (count($missing) === 1 ? 'it' : 'them') . ' again, or switch ' . lcfirst($name) . ' off first.'
                : $name . ' cannot be switched on until ' . self::andList($missing) . ' ' . (count($missing) === 1 ? 'is' : 'are') . ' set.');
        }
    }

    /** What one service is still short of, in the screen's own words. */
    private function missingFor(string $service, array $r): array
    {
        $missing = [];
        if (($r['shortcode'] ?? null) === null) {
            $missing[] = 'the short code';
        }
        if (($r['bank_account_id'] ?? null) === null) {
            $missing[] = 'the settlement account';
        }
        if (($r['callback_base'] ?? null) === null) {
            $missing[] = 'the callback address';
        }
        foreach (self::CREDENTIALS as $c) {
            if (in_array($c['needs'], ['both', $service], true) && !Secret::isSet($r[$c['column']] ?? null)) {
                $missing[] = 'the ' . lcfirst($c['label']);
            }
        }
        if ($service === 'disbursements') {
            if (($r['initiator_name'] ?? null) === null) {
                $missing[] = 'the initiator';
            }
            if ((float) ($r['payment_ceiling'] ?? 0) <= 0) {
                $missing[] = 'a per-payment ceiling';
            }
        }

        return $missing;
    }

    // ------------------------------------------------------------------
    // Shapes for the screen
    // ------------------------------------------------------------------

    /** @return list<array{key: string, label: string, set: bool, hint: string, note: string, needs: string}> */
    private function credentialState(array $r): array
    {
        $out = [];
        foreach (self::CREDENTIALS as $key => $c) {
            $sealed = $r[$c['column']] ?? null;
            $out[] = [
                'key' => $key, 'label' => $c['label'], 'note' => $c['note'], 'needs' => $c['needs'],
                'set' => Secret::isSet($sealed), 'readable' => Secret::readable($sealed), 'hint' => Secret::hint($sealed),
            ];
        }

        return $out;
    }

    /** The addresses to register on the Daraja portal, once a callback address is set. */
    private function callbacks(string $base): array
    {
        return array_map(static fn ($c) => [
            'label' => $c['label'], 'needs' => $c['needs'], 'url' => $base === '' ? '' : $base . $c['path'],
        ], self::CALLBACKS);
    }

    private function status(array $r): array
    {
        $on = (bool) $r['collections_on'] || (bool) $r['disbursements_on'];
        $services = implode(' and ', array_filter([(bool) $r['collections_on'] ? 'collections' : '', (bool) $r['disbursements_on'] ? 'payments' : '']));
        $shortcode = ($r['shortcode_kind'] === 'till' ? 'Till ' : 'Paybill ') . ($r['shortcode'] ?? '—');
        $account = $this->accountCode($r['bank_account_id'] === null ? null : (int) $r['bank_account_id']);

        if (!$on) {
            return ['state' => 'off', 'label' => 'Not connected',
                'note' => 'Nothing is collected or paid through M-Pesa. Bills and advances marked M-Pesa are recorded, but sent to Safaricom by hand.'];
        }
        if ($r['environment'] !== 'production') {
            return ['state' => 'sandbox', 'label' => 'Sandbox',
                'note' => ucfirst($services) . ' run against Safaricom\'s test environment. Nothing settles, and no money moves.'];
        }

        return ['state' => 'live', 'label' => 'Live',
            'note' => $shortcode . ' · ' . ucfirst($services) . ' settle to ' . ($account === '' ? 'the settlement account' : $account) . '.'];
    }

    /** What each service still needs, for the screen to show beside its switch. */
    private function outstanding(array $r): array
    {
        return [
            'collections'   => self::andList($this->missingFor('collections', $r)),
            'disbursements' => self::andList($this->missingFor('disbursements', $r)),
        ];
    }

    /**
     * Payments already committed to M-Pesa: bills scheduled to be paid by it and
     * advances approved to be issued by it.
     *
     * @return array{total: int, note: string}
     */
    private function pendingDisbursements(): array
    {
        $bills = (int) $this->value("SELECT COUNT(*) FROM {bills} WHERE status = 'scheduled' AND payment_method = 'mpesa'");
        $advances = (int) $this->value("SELECT COUNT(*) FROM {advances} WHERE status = 'approved' AND payment_method = 'mpesa'");
        $parts = array_filter([
            $bills > 0 ? $bills . ($bills === 1 ? ' bill is scheduled' : ' bills are scheduled') . ' to be paid by M-Pesa' : '',
            $advances > 0 ? $advances . ($advances === 1 ? ' advance is approved' : ' advances are approved') . ' for issue by M-Pesa' : '',
        ]);

        return ['total' => $bills + $advances, 'note' => implode(' and ', $parts)];
    }

    // ------------------------------------------------------------------

    /** The row held, or the defaults a first save starts from. */
    private function held(): array
    {
        return $this->cached('integration', function () {
            $row = $this->row('SELECT * FROM {mpesa_integrations} WHERE entity_id = ?', [$this->lookups->headOfficeId()]);

            return $row ?? ['id' => null] + self::DEFAULTS;
        });
    }

    /** @return list<array{id: int, code: string, name: string, currency: string}> active mobile-money accounts */
    private function mobileAccounts(): array
    {
        return array_values(array_map(static fn ($b) => [
            'id' => (int) $b['id'], 'code' => (string) $b['code'], 'name' => $b['name'], 'currency' => $b['currency'],
        ], array_filter($this->lookups->bankAccounts(), static fn ($b) => $b['kind'] === 'mobile_money' && $b['status'] === 'active')));
    }

    private function accountCode(?int $bankAccountId): string
    {
        if ($bankAccountId === null) {
            return '';
        }
        foreach ($this->lookups->bankAccounts() as $code => $b) {
            if ((int) $b['id'] === $bankAccountId) {
                return (string) $code;
            }
        }

        return '';
    }

    private function statementFormat(string $accountCode): array
    {
        return (new StatementFormatRepository($this->db))->forAccount($accountCode) ?? [];
    }

    private function log(string $what, int $actorId): void
    {
        $this->audit('settings:integrations', null, null, $what, $actorId, 'settings.changed', $this->lookups->headOfficeId());
    }

    /** @return list<array{value: string, text: string, note: string}> a choice and what it means, for a select */
    private static function choices(array $labels): array
    {
        return array_map(static fn ($k, $v) => ['value' => $k, 'text' => $v, 'note' => self::CHOICE_NOTES[$k]], array_keys($labels), $labels);
    }

    /** An amount as the rest of Settings writes one. */
    private static function money(float $amount): string
    {
        return $amount == 0.0 ? 'nil' : Prototype::fmt($amount);
    }

    /** "the short code, the passkey and the callback address" */
    private static function andList(array $items): string
    {
        if (count($items) < 2) {
            return (string) ($items[0] ?? '');
        }
        $last = array_pop($items);

        return implode(', ', $items) . ' and ' . $last;
    }
}
