<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\EntityScope;
use App\Libraries\StatementCsv;

/**
 * Statement formats: how each bank's CSV maps onto a line of the reconciliation's
 * bank statement, and which format each cash account's statements use.
 *
 * A built-in format (the M-Pesa organisation portal's export) is the same for
 * every organisation and cannot be changed, only copied. A format in use by an
 * account cannot be deleted.
 */
final class StatementFormatRepository extends Repository
{
    private Lookups $lookups;

    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        parent::__construct($db);
        $this->lookups = new Lookups();
    }

    /** @return list<array> every format, built-in first, in the screen shape */
    public function formats(): array
    {
        return $this->cached('formats', function () {
            // Formats are the organisation's, so every entity's accounts count as using one.
            $accounts = [];
            foreach (EntityScope::across(fn () => $this->lookups->bankAccounts()) as $b) {
                if ($b['statement_format_id'] !== null) {
                    $accounts[(int) $b['statement_format_id']][] = (string) $b['code'];
                }
            }

            return array_map(fn ($f) => self::shape($f) + ['accounts' => $accounts[(int) $f['id']] ?? []], $this->rows(
                'SELECT * FROM {bank_statement_formats} WHERE entity_id = ? ORDER BY is_builtin DESC, name',
                [$this->lookups->headOfficeId()]
            ));
        });
    }

    public function find(int $id): ?array
    {
        return current(array_filter($this->formats(), static fn ($f) => $f['id'] === $id)) ?: null;
    }

    /** The format a cash account's statements are read with, or null when none is set. */
    public function forAccount(string $code): ?array
    {
        $bank = $this->lookups->bankAccount($code);

        return $bank === null || $bank['statement_format_id'] === null ? null : $this->find((int) $bank['statement_format_id']);
    }

    /**
     * The cash accounts a statement can be loaded for (bank and mobile money, not
     * petty cash) and the format each uses.
     *
     * @return list<array{code: string, name: string, short: string, kind: string, currency: string, formatId: int|null, format: string}>
     */
    public function accounts(): array
    {
        $names = array_column($this->formats(), 'name', 'id');

        return array_values(array_map(static fn ($b) => [
            'code' => (string) $b['code'], 'name' => $b['name'], 'short' => $b['short_name'], 'kind' => $b['kind'], 'currency' => $b['currency'],
            'formatId' => $b['statement_format_id'] === null ? null : (int) $b['statement_format_id'],
            'format' => $names[(int) $b['statement_format_id']] ?? '',
        ], array_filter($this->lookups->bankAccounts(), static fn ($b) => $b['status'] === 'active' && in_array($b['kind'], ['bank', 'mobile_money'], true))));
    }

    /**
     * Every active cash account of the entity, petty cash included, for Settings →
     * Bank statements: with its details, and what already refers to it, since it
     * can be corrected only while nothing does.
     */
    public function cashAccounts(): array
    {
        $names = array_column($this->formats(), 'name', 'id');

        return array_values(array_map(fn ($b) => [
            'code' => (string) $b['code'], 'name' => $b['name'], 'short' => $b['short_name'], 'kind' => $b['kind'], 'currency' => $b['currency'],
            'bankName' => (string) ($b['bank_name'] ?? ''), 'accountNumber' => (string) ($b['account_number'] ?? ''),
            'formatId' => $b['statement_format_id'] === null ? null : (int) $b['statement_format_id'],
            'format' => $names[(int) $b['statement_format_id']] ?? '',
            'uses' => $this->uses($b),
        ], array_filter($this->lookups->bankAccounts(), static fn ($b) => $b['status'] === 'active')));
    }

    /**
     * Records that refer to a cash account, as "3 receipts", "1 bank statement": the
     * documents that name it, and every journal line the entity holding it has on the
     * ledger account behind it, drafts included, since those post there. Other
     * entities' lines on the same code of the shared chart are theirs, not this account's.
     *
     * @return list<string>
     */
    public function uses(array $bank): array
    {
        $id = (int) $bank['id'];
        $counts = EntityScope::across(fn () => [
            'journal line'   => (int) $this->value(
                'SELECT COUNT(*) FROM {journal_lines} l JOIN {journals} j ON j.id = l.journal_id WHERE l.account_id = ? AND j.entity_id = ?',
                [(int) $bank['account_id'], (int) $bank['entity_id']]
            )
                + (int) $this->value("SELECT COUNT(*) FROM {journals} WHERE source_type = 'cash_book' AND source_id = ?", [$id]),
            'receipt'        => (int) $this->value('SELECT COUNT(*) FROM {receipts} WHERE bank_account_id = ?', [$id]),
            'payment'        => (int) $this->value('SELECT COUNT(*) FROM {payments} WHERE bank_account_id = ?', [$id]),
            'payment run'    => (int) $this->value('SELECT COUNT(*) FROM {payment_runs} WHERE bank_account_id = ?', [$id]),
            'bill'           => (int) $this->value('SELECT COUNT(*) FROM {bills} WHERE pay_from_bank_account_id = ?', [$id]),
            'advance'        => (int) $this->value('SELECT COUNT(*) FROM {advances} WHERE bank_account_id = ?', [$id]),
            'bank statement' => (int) $this->value('SELECT COUNT(*) FROM {bank_statements} WHERE bank_account_id = ?', [$id]),
            'reconciliation' => (int) $this->value('SELECT COUNT(*) FROM {reconciliations} WHERE bank_account_id = ?', [$id]),
            'WHT remittance' => (int) $this->value('SELECT COUNT(*) FROM {wht_remittances} WHERE bank_account_id = ?', [$id]),
        ]);

        return array_values(array_map(
            static fn ($what, $n) => $n . ' ' . $what . ($n === 1 ? '' : 's'),
            array_keys(array_filter($counts)), array_filter($counts)
        ));
    }

    /**
     * The ledger accounts the entity could open a cash account on: postable asset
     * accounts it holds none on yet, that either carry another entity's cash account
     * (whose kind and currency one opened beside it takes) or that no other entity
     * posts to. A code the head office uses for its grants receivable is not a
     * branch's to bank on; the head office's bank code is.
     *
     * @return list<array{code: string, name: string, kind: ?string, currency: ?string, sharedWith: list<string>}>
     */
    public function cashCandidates(): array
    {
        $entityId = $this->lookups->entityId();
        $out = [];
        foreach ($this->lookups->accounts() as $a) {
            if ($a['type'] !== 'asset' || (int) $a['is_leaf'] !== 1 || $a['status'] !== 'active'
                || $this->ledgerRefusal($a, $entityId, null) !== null) {
                continue;
            }
            $beside = $this->cashOnLedger((int) $a['id'], $entityId);
            $out[] = [
                'code' => (string) $a['code'], 'name' => $a['name'],
                'kind' => $beside[0]['kind'] ?? null, 'currency' => $beside[0]['currency'] ?? null,
                'sharedWith' => array_values(array_unique(array_column($beside, 'entity'))),
            ];
        }

        return $out;
    }

    /**
     * Creates a format (no id) or changes one.
     *
     * @return array the saved format
     */
    public function save(?int $id, array $input, int $actorId): array
    {
        $existing = $id === null ? null : ($this->find($id) ?? throw new RuleViolation('That statement format no longer exists.'));
        if ($existing !== null && $existing['builtin']) {
            throw new RuleViolation($existing['name'] . ' is built in and cannot be changed. Duplicate it to make a version of your own.');
        }

        $f = self::validate($input);
        $clash = $this->value(
            'SELECT id FROM {bank_statement_formats} WHERE entity_id = ? AND LOWER(name) = LOWER(?) AND id <> ?',
            [$this->lookups->headOfficeId(), $f['name'], $id ?? 0]
        );
        if ($clash !== null) {
            throw new RuleViolation('A statement format called ' . $f['name'] . ' already exists.');
        }

        $row = self::toRow($f);
        $now = Clock::timestamp();

        $savedId = $this->transaction(function () use ($id, $row, $f, $actorId, $now) {
            if ($id === null) {
                $id = $this->insert('bank_statement_formats', $row + [
                    'entity_id' => $this->lookups->headOfficeId(), 'is_builtin' => 0, 'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->logChange('Statement format ' . $f['name'] . ' created', $actorId);
            } else {
                $this->db->table('bank_statement_formats')->where('id', $id)->update($row + ['updated_by' => $actorId, 'updated_at' => $now]);
                $this->logChange('Statement format ' . $f['name'] . ' changed', $actorId);
            }

            return $id;
        });

        return $this->find((int) $savedId);
    }

    public function delete(int $id, int $actorId): void
    {
        $f = $this->find($id) ?? throw new RuleViolation('That statement format no longer exists.');
        if ($f['builtin']) {
            throw new RuleViolation($f['name'] . ' is built in and cannot be deleted.');
        }
        if ($f['accounts'] !== []) {
            throw new RuleViolation($f['name'] . ' is used by ' . implode(', ', $f['accounts']) . '. Give those accounts another format first.');
        }

        $this->transaction(function () use ($f, $actorId) {
            $this->db->table('bank_statement_formats')->where('id', $f['id'])->delete();
            $this->logChange('Statement format ' . $f['name'] . ' deleted', $actorId);
        });
    }

    /** Sets the format an account's statements are read with (null clears it). */
    /** What a cash account may be. Petty cash takes no statement, so it takes no format. */
    public const KINDS = ['bank' => 'Bank account', 'mobile_money' => 'Mobile money', 'petty_cash' => 'Petty cash'];

    /**
     * Opens a cash account against a ledger account.
     *
     * A bank reconciliation is between a cash account and the statement of the
     * account behind it, so the ledger account comes first: it must be a postable
     * asset account, and one entity's two cash accounts cannot sit on the same one or
     * the reconciliation would not know which balance it was agreeing. Each entity's
     * can (ledgerRefusal()).
     *
     * @param array{code: string, name: string, shortName?: string, kind?: string,
     *              bankName?: string, accountNumber?: string, currency?: string} $input
     */
    public function createAccount(array $input, int $actorId): array
    {
        $code = trim((string) ($input['code'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $kind = trim((string) ($input['kind'] ?? 'bank'));

        $account = $this->lookups->accounts()[$code] ?? null;
        $currency = mb_strtoupper(trim((string) ($input['currency'] ?? ''))) ?: 'KES';
        $refusal = match (true) {
            $account === null                    => 'Account ' . $code . ' is not in the chart of accounts.',
            $account['type'] !== 'asset'         => $code . ' ' . $account['name'] . ' is ' . $account['type'] . '. Cash is held on an asset account.',
            (int) $account['is_leaf'] === 0      => $code . ' ' . $account['name'] . ' is a heading, not a postable account.',
            $account['status'] !== 'active'      => $code . ' ' . $account['name'] . ' is archived.',
            $name === ''                         => 'Give the account the name it is known by — it is what the reconciliation and the cash book show.',
            !isset(self::KINDS[$kind])           => 'A cash account is a ' . implode(', a ', array_map('lcfirst', self::KINDS)) . '.',
            default                              => $this->ledgerRefusal($account, $this->lookups->entityId(), null, $kind, $currency),
        };
        if ($refusal !== null) {
            throw new RuleViolation($refusal);
        }

        $short = trim((string) ($input['shortName'] ?? '')) ?: mb_substr($name, 0, 40);
        if ($this->value('SELECT code FROM {currencies} WHERE code = ?', [$currency]) === null) {
            throw new RuleViolation($currency . ' is not a currency this instance holds. Add it in Settings → Currencies first.');
        }

        $this->transaction(function () use ($account, $code, $name, $short, $kind, $currency, $input, $actorId) {
            $this->insert('bank_accounts', [
                'entity_id' => $this->lookups->entityId(), 'account_id' => $account['id'], 'name' => $name,
                'short_name' => $short, 'kind' => $kind, 'currency' => $currency,
                'bank_name' => trim((string) ($input['bankName'] ?? '')) ?: null,
                'account_number' => trim((string) ($input['accountNumber'] ?? '')) ?: null,
                'status' => 'active', 'created_at' => Clock::timestamp(),
            ]);
            $this->logChange($name . ' opened on ' . $code . ' ' . $account['name'] . ' as ' . lcfirst(self::KINDS[$kind])
                . ($currency === 'KES' ? '' : ' in ' . $currency), $actorId);
        });

        return ['code' => $code, 'name' => $name, 'short' => $short, 'kind' => $kind, 'currency' => $currency];
    }

    /**
     * Corrects a cash account opened by mistake — the wrong ledger account, name,
     * kind, bank, number or currency — while nothing refers to it yet. Once a
     * receipt, payment, statement or journal line does, it stays as it is: changing
     * it would change what those records say was paid from, or into, where.
     *
     * @param array{code?: string, name?: string, shortName?: string, kind?: string,
     *              bankName?: string, accountNumber?: string, currency?: string} $input
     * @return array{code: string, name: string, changes: list<string>}
     */
    public function updateAccount(string $code, array $input, int $actorId): array
    {
        $bank = $this->lookups->bankAccount($code) ?? throw new RuleViolation('Account ' . $code . ' is not a cash account of this entity.');
        if (($uses = $this->uses($bank)) !== []) {
            throw new RuleViolation($bank['name'] . ' can no longer be changed: ' . self::andList($uses)
                . ' already refer to it. Open a new cash account for the corrected details instead.');
        }

        $held = [
            'code' => $code, 'name' => $bank['name'], 'shortName' => $bank['short_name'], 'kind' => $bank['kind'],
            'bankName' => (string) ($bank['bank_name'] ?? ''), 'accountNumber' => (string) ($bank['account_number'] ?? ''), 'currency' => $bank['currency'],
        ];
        $next = array_map(static fn ($v) => trim((string) $v), array_intersect_key($input, $held)) + $held;
        $next['currency'] = mb_strtoupper($next['currency']);
        if ($next['kind'] === 'petty_cash') {
            // Petty cash is counted, not banked.
            $next['bankName'] = $next['accountNumber'] = '';
        }

        $account = $this->lookups->accounts()[$next['code']] ?? null;
        $mpesa = EntityScope::across(fn () => $this->value('SELECT COUNT(*) FROM {mpesa_integrations} WHERE bank_account_id = ?', [(int) $bank['id']]));
        $refusal = match (true) {
            $account === null                    => 'Account ' . $next['code'] . ' is not in the chart of accounts.',
            $account['type'] !== 'asset'         => $next['code'] . ' ' . $account['name'] . ' is ' . $account['type'] . '. Cash is held on an asset account.',
            (int) $account['is_leaf'] === 0      => $next['code'] . ' ' . $account['name'] . ' is a heading, not a postable account.',
            $account['status'] !== 'active'      => $next['code'] . ' ' . $account['name'] . ' is archived.',
            $next['name'] === ''                 => 'Give the account the name it is known by — it is what the reconciliation and the cash book show.',
            !isset(self::KINDS[$next['kind']])   => 'A cash account is a ' . implode(', a ', array_map('lcfirst', self::KINDS)) . '.',
            $mpesa > 0 && $next['kind'] !== 'mobile_money' => 'M-Pesa settles onto ' . $bank['name'] . ', so it stays a mobile money account. Choose another settlement account in Settings → Integrations first.',
            $this->value('SELECT code FROM {currencies} WHERE code = ?', [$next['currency']]) === null
                                                 => $next['currency'] . ' is not a currency this instance holds. Add it in Settings → Currencies first.',
            default => $this->ledgerRefusal($account, (int) $bank['entity_id'], (int) $bank['id'], $next['kind'], $next['currency']),
        };
        if ($refusal !== null) {
            throw new RuleViolation($refusal);
        }
        $next['shortName'] = $next['shortName'] !== '' ? mb_substr($next['shortName'], 0, 40) : mb_substr($next['name'], 0, 40);

        $labels = ['code' => 'ledger account', 'name' => 'name', 'shortName' => 'short name', 'kind' => 'kind',
            'bankName' => 'bank', 'accountNumber' => 'account number', 'currency' => 'currency'];
        $shown = static fn (string $key, string $v) => $v === '' ? 'blank' : ($key === 'kind' ? lcfirst(self::KINDS[$v]) : $v);
        $changes = [];
        foreach ($labels as $key => $label) {
            if ($next[$key] !== $held[$key]) {
                $changes[] = $label . ' ' . $shown($key, $held[$key]) . ' → ' . $shown($key, $next[$key]);
            }
        }
        if ($changes === []) {
            return ['code' => $code, 'name' => $bank['name'], 'changes' => []];
        }

        $this->transaction(function () use ($bank, $account, $next, $changes, $actorId) {
            $this->db->table('bank_accounts')->where('id', (int) $bank['id'])->update([
                'account_id' => $account['id'], 'name' => $next['name'], 'short_name' => $next['shortName'], 'kind' => $next['kind'],
                'bank_name' => $next['bankName'] !== '' ? $next['bankName'] : null,
                'account_number' => $next['accountNumber'] !== '' ? $next['accountNumber'] : null,
                'currency' => $next['currency'],
                // Petty cash takes no statement, so it keeps no format.
                'statement_format_id' => $next['kind'] === 'petty_cash' ? null : $bank['statement_format_id'],
                'updated_at' => Clock::timestamp(),
            ]);
            $this->logChange($bank['name'] . ' corrected before first use: ' . implode('; ', $changes), $actorId);
        });

        return ['code' => $next['code'], 'name' => $next['name'], 'changes' => $changes];
    }

    public function assign(string $code, ?int $formatId, int $actorId): void
    {
        $account = current(array_filter($this->accounts(), static fn ($a) => $a['code'] === $code))
            ?: throw new RuleViolation('Account ' . $code . ' is not a bank or mobile money account.');
        $format = $formatId === null ? null : ($this->find($formatId) ?? throw new RuleViolation('That statement format no longer exists.'));

        $this->transaction(function () use ($account, $format, $actorId) {
            $this->db->table('bank_accounts')->where('id', $this->lookups->bankAccount($account['code'])['id'])
                ->update(['statement_format_id' => $format['id'] ?? null, 'updated_at' => Clock::timestamp()]);
            $this->logChange($account['code'] . ' ' . $account['short'] . ' statements ' . ($format === null ? 'no longer have a format' : 'now read as ' . $format['name']), $actorId);
        });
    }

    /**
     * Why an entity cannot hold a cash account on a ledger account, or null when it
     * can. The chart is shared and every journal is an entity's, so a ledger account
     * carries a cash account of each entity — the Coast's bank on 1110 beside the
     * head office's — but only one of each, or a reconciliation could not say which
     * balance it agreed. The cash accounts on one ledger account are of one kind and
     * currency, as the account's balance is. A ledger account no one banks on but
     * another entity posts to is that entity's receivable, advance or asset, not cash.
     *
     * $exceptId is the cash account being corrected; with no kind given, only where
     * the account stands is checked.
     */
    private function ledgerRefusal(array $account, int $entityId, ?int $exceptId, ?string $kind = null, ?string $currency = null): ?string
    {
        $label = $account['code'] . ' ' . $account['name'];
        $here = $beside = [];
        foreach ($this->cashOnLedger((int) $account['id']) as $b) {
            if ($b['id'] === $exceptId) {
                continue;
            }
            $b['entity_id'] === $entityId ? $here[] = $b : $beside[] = $b;
        }

        if ($here !== []) {
            return $label . ' already carries ' . $here[0]['name'] . '. An entity holds one cash account on a ledger account, or a reconciliation cannot say which balance it agreed.';
        }
        if ($beside !== []) {
            $b = $beside[0];
            if ($kind !== null && $kind !== $b['kind']) {
                return $label . ' carries ' . $b['entity'] . "'s " . $b['name'] . ', ' . self::article(lcfirst(self::KINDS[$b['kind']])) . ' ' . lcfirst(self::KINDS[$b['kind']])
                    . '. The cash accounts on one ledger account are of one kind: open this one on another.';
            }
            if ($currency !== null && $currency !== $b['currency']) {
                return $label . ' carries ' . $b['entity'] . "'s " . $b['name'] . ' in ' . $b['currency']
                    . '. The cash accounts on one ledger account hold one currency: open this one in ' . $b['currency'] . ', or on another ledger account.';
            }

            return null;
        }
        if (($other = $this->postedByOthers($entityId)[(int) $account['id']] ?? null) !== null) {
            return $label . ' carries postings of ' . $other . ' and no cash account, so it is not a cash ledger. '
                . 'Open the cash account on a bank ledger account, or add one to the chart of accounts first.';
        }

        return null;
    }

    /**
     * The cash accounts on a ledger account, whichever entity holds them, but for the
     * one entity named.
     *
     * @return list<array{id: int, entity_id: int, entity: string, name: string, kind: string, currency: string}>
     */
    private function cashOnLedger(int $accountId, ?int $exceptEntityId = null): array
    {
        $all = $this->cached('cash-on-ledger', fn () => EntityScope::across(function () {
            $out = [];
            foreach ($this->rows('SELECT b.id, b.entity_id, b.account_id, b.name, b.kind, b.currency, e.name AS entity
                FROM {bank_accounts} b JOIN {entities} e ON e.id = b.entity_id ORDER BY b.entity_id') as $r) {
                $out[(int) $r['account_id']][] = ['id' => (int) $r['id'], 'entity_id' => (int) $r['entity_id']] + $r;
            }

            return $out;
        }));

        return array_values(array_filter($all[$accountId] ?? [], static fn ($b) => $b['entity_id'] !== $exceptEntityId));
    }

    /**
     * Ledger accounts that an entity other than the one given posts to, with the first
     * such entity's name.
     *
     * @return array<int, string> account id => entity name
     */
    private function postedByOthers(int $entityId): array
    {
        return $this->cached('posted-by-others:' . $entityId, fn () => EntityScope::across(fn () => array_column($this->rows(
            'SELECT l.account_id, MIN(e.name) AS entity FROM {journal_lines} l JOIN {journals} j ON j.id = l.journal_id
             JOIN {entities} e ON e.id = j.entity_id WHERE j.entity_id <> ? GROUP BY l.account_id',
            [$entityId]
        ), 'entity', 'account_id')));
    }

    private static function article(string $word): string
    {
        return in_array($word[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
    }

    private static function andList(array $items): string
    {
        if (count($items) < 2) {
            return (string) ($items[0] ?? '');
        }
        $last = array_pop($items);

        return implode(', ', $items) . ' and ' . $last;
    }

    /** Format changes are settings changes, and show in Settings → Audit log. */
    private function logChange(string $what, int $actorId): void
    {
        $this->audit('settings:bank statements', null, null, $what, $actorId, 'settings.changed', $this->lookups->headOfficeId());
    }

    /**
     * Reads a sample file with a format that may not be saved yet, for the editor's
     * live preview. Only the column mapping has to be complete.
     */
    public function sample(string $csv, array $input): array
    {
        $f = self::normalise($input);
        $read = StatementCsv::read($csv, $f);
        $rows = array_values(array_filter($read['rows'], static fn ($r) => $r['skip'] === null));
        $breaks = StatementCsv::balanceBreaks($rows);

        return [
            'headers' => $read['headers'], 'headerLine' => $read['headerLine'], 'error' => $read['error'],
            'rowCount' => count($read['rows']), 'skipped' => count($read['rows']) - count($rows),
            'problems' => count(array_filter($rows, static fn ($r) => $r['errors'] !== [])),
            'balanceChecked' => ($f['balanceColumn'] ?? '') !== '' && $read['error'] === null,
            'balanceBreaks' => $breaks['breaks'], 'newestFirst' => $breaks['newestFirst'],
            'rows' => array_map([self::class, 'previewRow'], array_slice($read['rows'], 0, 30)),
        ];
    }

    /** A read row as the bank statement card draws it. */
    public static function previewRow(array $r): array
    {
        $entry = BankRepository::BANK_ENTRIES[$r['entry'] ?? ''] ?? null;

        return [
            'line' => $r['line'], 'date' => $r['date'] === null ? '—' : date('d M', strtotime($r['date'])), 'isoDate' => $r['date'],
            'ref' => $r['ref'], 'desc' => $r['desc'], 'amt' => $r['amount'], 'amount' => $r['amount'] === null ? '—' : BankRepository::money($r['amount']),
            'balance' => $r['balance'] === null ? '' : BankRepository::money($r['balance']),
            'entry' => $r['entry'], 'entryLabel' => $entry['label'] ?? '', 'skip' => $r['skip'] ?? '', 'errors' => $r['errors'],
        ];
    }

    /** What the editor offers. */
    public static function options(): array
    {
        return [
            'delimiters'  => array_map(static fn ($k) => ['value' => $k, 'text' => ucfirst($k)], array_keys(StatementCsv::DELIMITERS)),
            'dateFormats' => array_keys(StatementCsv::DATE_FORMATS),
            'layouts'     => array_map(static fn ($k, $v) => ['value' => $k, 'text' => $v], array_keys(StatementCsv::LAYOUTS), StatementCsv::LAYOUTS),
            'entries'     => array_map(static fn ($k, $v) => ['value' => $k, 'text' => $v['desc']], array_keys(BankRepository::BANK_ENTRIES), BankRepository::BANK_ENTRIES),
        ];
    }

    // ------------------------------------------------------------------

    /** Screen input → a format the reader accepts, without insisting on a name. */
    private static function normalise(array $in): array
    {
        $str = static fn (string $k, int $max = 80) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        $dateFormats = array_values(array_filter(array_map('trim', explode('|', (string) ($in['dateFormat'] ?? ''))), static fn ($d) => $d !== ''));

        return [
            'name'               => $str('name'),
            'delimiter'          => array_key_exists((string) ($in['delimiter'] ?? ''), StatementCsv::DELIMITERS) ? (string) $in['delimiter'] : 'comma',
            'dateColumn'         => $str('dateColumn'),
            'dateFormat'         => implode('|', $dateFormats),
            'referenceColumn'    => $str('referenceColumn'),
            'descriptionColumns' => array_values(array_filter(array_map(static fn ($c) => mb_substr(trim((string) $c), 0, 80), (array) ($in['descriptionColumns'] ?? [])), static fn ($c) => $c !== '')),
            'amountLayout'       => array_key_exists((string) ($in['amountLayout'] ?? ''), StatementCsv::LAYOUTS) ? (string) $in['amountLayout'] : 'signed',
            'amountColumn'       => $str('amountColumn'),
            'debitColumn'        => $str('debitColumn'),
            'creditColumn'       => $str('creditColumn'),
            'indicatorColumn'    => $str('indicatorColumn'),
            'creditIndicator'    => $str('creditIndicator', 10),
            'balanceColumn'      => $str('balanceColumn'),
            'decimalMark'        => ($in['decimalMark'] ?? '.') === ',' ? ',' : '.',
            'statusColumn'       => $str('statusColumn'),
            'statusValue'        => $str('statusValue', 40),
            'rules'              => array_values(array_filter(array_map(static fn ($r) => [
                'match' => mb_substr(trim((string) ($r['match'] ?? '')), 0, 60), 'entry' => (string) ($r['entry'] ?? ''),
            ], (array) ($in['rules'] ?? [])), static fn ($r) => $r['match'] !== '')),
        ];
    }

    /** A complete format, or the first thing missing from it. */
    private static function validate(array $in): array
    {
        $f = self::normalise($in);
        $need = static function (string $value, string $what): void {
            if ($value === '') {
                throw new RuleViolation($what);
            }
        };

        $need($f['name'], 'Give the format a name, such as the bank and where the file is downloaded from.');
        $need($f['dateColumn'], 'Choose the column that holds the transaction date.');
        $need($f['dateFormat'], 'Choose how the dates are written.');
        foreach (explode('|', $f['dateFormat']) as $d) {
            if (!isset(StatementCsv::DATE_FORMATS[$d])) {
                throw new RuleViolation($d . ' is not a date layout the reader knows.');
            }
        }
        if ($f['descriptionColumns'] === []) {
            throw new RuleViolation('Choose at least one column for the description.');
        }
        match ($f['amountLayout']) {
            'split' => [$need($f['debitColumn'], 'Choose the money out column.'), $need($f['creditColumn'], 'Choose the money in column.')],
            'indicator' => [$need($f['amountColumn'], 'Choose the amount column.'), $need($f['indicatorColumn'], 'Choose the column that marks debits and credits.'),
                $need($f['creditIndicator'], 'Say how that column marks money in, such as CR or C.')],
            default => $need($f['amountColumn'], 'Choose the amount column.'),
        };
        if ($f['statusColumn'] !== '') {
            $need($f['statusValue'], 'Say which status a completed transaction has, such as Completed.');
        }
        foreach ($f['rules'] as $r) {
            if (!isset(BankRepository::BANK_ENTRIES[$r['entry']])) {
                throw new RuleViolation('The rule for "' . $r['match'] . '" needs a kind of bank entry.');
            }
        }
        if ($f['amountLayout'] === 'split' && strcasecmp($f['debitColumn'], $f['creditColumn']) === 0) {
            throw new RuleViolation('Money out and money in must be different columns.');
        }

        return $f;
    }

    /** Screen shape → database row. */
    private static function toRow(array $f): array
    {
        $layout = $f['amountLayout'];
        $blank = static fn (string $v) => $v === '' ? null : $v;

        return [
            'name' => $f['name'], 'delimiter' => $f['delimiter'], 'date_column' => $f['dateColumn'], 'date_format' => $f['dateFormat'],
            'reference_column' => $blank($f['referenceColumn']), 'description_columns' => json_encode($f['descriptionColumns']),
            'amount_layout' => $layout,
            'amount_column' => $layout === 'split' ? null : $blank($f['amountColumn']),
            'debit_column' => $layout === 'split' ? $f['debitColumn'] : null,
            'credit_column' => $layout === 'split' ? $f['creditColumn'] : null,
            'indicator_column' => $layout === 'indicator' ? $f['indicatorColumn'] : null,
            'credit_indicator' => $layout === 'indicator' ? $f['creditIndicator'] : null,
            'balance_column' => $blank($f['balanceColumn']), 'decimal_mark' => $f['decimalMark'],
            'status_column' => $blank($f['statusColumn']), 'status_value' => $f['statusColumn'] === '' ? null : $f['statusValue'],
            'entry_rules' => json_encode($f['rules']),
        ];
    }

    /** Database row → screen shape. */
    private static function shape(array $r): array
    {
        $layout = $r['amount_layout'];

        return [
            'id' => (int) $r['id'], 'name' => $r['name'], 'builtin' => (bool) $r['is_builtin'], 'delimiter' => $r['delimiter'],
            'dateColumn' => $r['date_column'], 'dateFormat' => $r['date_format'], 'referenceColumn' => (string) $r['reference_column'],
            'descriptionColumns' => json_decode((string) $r['description_columns'], true) ?: [],
            'amountLayout' => $layout, 'amountColumn' => (string) $r['amount_column'], 'debitColumn' => (string) $r['debit_column'],
            'creditColumn' => (string) $r['credit_column'], 'indicatorColumn' => (string) $r['indicator_column'],
            'creditIndicator' => (string) $r['credit_indicator'], 'balanceColumn' => (string) $r['balance_column'],
            'decimalMark' => $r['decimal_mark'], 'statusColumn' => (string) $r['status_column'], 'statusValue' => (string) $r['status_value'],
            'rules' => json_decode((string) $r['entry_rules'], true) ?: [],
            'summary' => match ($layout) {
                'split'     => 'Money out "' . $r['debit_column'] . '", money in "' . $r['credit_column'] . '"',
                'indicator' => 'Amount "' . $r['amount_column'] . '", marked ' . $r['credit_indicator'] . ' in "' . $r['indicator_column'] . '"',
                default     => 'Signed amount "' . $r['amount_column'] . '"',
            } . ' · dates ' . str_replace('|', ' or ', $r['date_format']),
        ];
    }
}
