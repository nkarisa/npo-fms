<?php

namespace App\Repositories;

use App\Libraries\Clock;

/**
 * The chart of accounts with each account's balance from the posted ledger.
 *
 * 3900, surplus for the year, is never posted: its balance is income less
 * expenditure, which is how the statements articulate.
 */
final class ChartRepository extends Repository
{
    public const DERIVED_SURPLUS = '3900';

    /** Every account code has this many digits. */
    public const CODE_LENGTH = 4;

    private const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    private const CONTRA_RX = '/accumulated depreciation|provision for|allowance for|impairment/i';

    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    /** Every account in code order — the depth-first order the chart tree is built from. */
    public function accounts(): array
    {
        return $this->cached('accounts', function () {
            $rows = $this->rows(
                'SELECT a.*, p.code AS parent_code, p.name AS parent_name, fu.name AS funder_name, g.short_name AS grant_short
                 FROM {accounts} a
                 LEFT JOIN {accounts} p ON p.id = a.parent_id
                 LEFT JOIN {funders} fu ON fu.id = a.funder_id
                 LEFT JOIN {grants} g ON g.id = a.default_grant_id
                 ORDER BY a.code'
            );

            return array_map(fn ($a) => $this->present($a), $rows);
        });
    }

    public function find(string $code): ?array
    {
        foreach ($this->accounts() as $a) {
            if ($a['code'] === $code) {
                return $a;
            }
        }

        return null;
    }

    /** Debit for asset and expense accounts, credit for the rest; a contra account takes the other side. */
    public static function normal(array $a): string
    {
        $debitClass = in_array(ucfirst((string) $a['type']), ['Asset', 'Expense'], true);

        return ($debitClass !== (bool) preg_match(self::CONTRA_RX, (string) ($a['name'] ?? ''))) ? 'Debit' : 'Credit';
    }

    /** Income and expenditure close out to the income statement; everything else is carried on the balance sheet. */
    public static function statement(array $a): string
    {
        return in_array(ucfirst((string) $a['type']), ['Income', 'Expense'], true) ? 'Income statement' : 'Balance sheet';
    }

    /**
     * Balances brought forward into the working year: the postings of the year's
     * opening-balance journal, signed to each account's normal side.
     *
     * @return array<string, float>
     */
    public function openings(): array
    {
        return $this->cached('openings', function () {
            $year = $this->lookups->yearPeriods()[0]['fiscal_year_id'] ?? null;
            $out = [];
            foreach ($this->rows(
                "SELECT a.code, a.type, SUM(l.debit) AS dr, SUM(l.credit) AS cr
                 FROM {journal_lines} l JOIN {journals} j ON j.id = l.journal_id JOIN {accounts} a ON a.id = l.account_id
                 WHERE j.status IN ('posted', 'reversed') AND j.source_type = 'fiscal_year' AND j.source_id = ? GROUP BY a.code, a.type",
                [$year]
            ) as $r) {
                $out[$r['code']] = in_array($r['type'], ['asset', 'expense'], true) ? $r['dr'] - $r['cr'] : $r['cr'] - $r['dr'];
            }

            return $out;
        });
    }

    /** The latest change to the chart: when, and who made it. */
    public function lastEdited(): ?array
    {
        $e = $this->row("SELECT occurred_at, actor_user_id FROM {audit_events} WHERE object_type = 'account' ORDER BY occurred_at DESC, id DESC LIMIT 1");

        return $e === null ? null : ['date' => self::dmy($e['occurred_at']), 'who' => $this->lookups->shortName($e['actor_user_id'] === null ? null : (int) $e['actor_user_id'])];
    }

    private function present(array $a): array
    {
        $balances = $this->lookups->balances();
        $balance  = $a['code'] === self::DERIVED_SURPLUS
            ? array_sum(array_map(fn ($code) => $this->signedFor($code, 'income'), array_keys($balances)))
                - array_sum(array_map(fn ($code) => $this->signedFor($code, 'expense'), array_keys($balances)))
            : ($balances[$a['code']] ?? 0);
        $opening = $a['code'] === self::DERIVED_SURPLUS ? 0 : ($this->openings()[$a['code']] ?? 0);

        return [
            'code'        => $a['code'],
            'name'        => $a['name'],
            'level'       => (int) $a['level'],
            'type'        => ucfirst($a['type']),
            'restriction' => $a['restriction'] ? ucfirst($a['restriction']) : '—',
            'fund'        => $this->lookups->fundGroupLabel($a['default_fund_id'] === null ? null : (int) $a['default_fund_id']),
            'program'     => $this->lookups->programmeName($a['default_programme_id'] === null ? null : (int) $a['default_programme_id']),
            'funder'      => $a['funder_name'] ?? '—',
            'balance'     => self::num($balance),
            'opening'     => self::num($opening),
            'movement'    => self::num($balance - $opening),
            'status'      => ucfirst($a['status']),
            'parent'      => $a['parent_code'] ? $a['parent_code'] . ' · ' . $a['parent_name'] : '— (top level)',
            'currency'    => $a['currency'] ?? 'KES',
            'grant'       => $a['grant_short'] ?? 'Unassigned',
            'notes'       => $a['notes'] ?? '',
            'postable'    => (bool) $a['is_leaf'],
            'reconcile'   => (bool) ($a['requires_reconciliation'] ?? false),
            'donorReport' => (bool) ($a['in_donor_reports'] ?? true),
        ];
    }

    private function signedFor(string $code, string $type): float
    {
        $account = $this->lookups->accounts()[$code] ?? null;

        return $account !== null && $account['type'] === $type ? $this->lookups->balance($code) : 0.0;
    }

    /** Adds an account under its parent. A parent that already carries postings cannot become a heading. */
    public function create(array $input, ?int $actorId = null): array
    {
        $code = trim((string) ($input['code'] ?? ''));
        self::checkCode($code);
        if (isset($this->lookups->accounts()[$code])) {
            throw new RuleViolation($code . ' already exists in the chart of accounts.');
        }

        $this->transaction(function () use ($code, $input, $actorId) {
            $parent = $this->parentFrom($input['parent'] ?? null);
            $row = $this->fields($input, null);
            $id = $this->insert('accounts', ['code' => $code, 'level' => $parent === null ? 0 : (int) $parent['level'] + 1]
                + $row + ['parent_id' => $parent['id'] ?? null, 'created_at' => Clock::timestamp()]);
            $this->demoteToHeading($parent);
            $this->audit('account', $id, $code, $code . ' ' . $row['name'] . ' added to the chart', $actorId, 'account.created');
        });

        return $this->find($code);
    }

    public function update(string $code, array $input, ?int $actorId = null): array
    {
        $existing = $this->lookups->accounts()[$code] ?? null;
        if ($existing === null) {
            throw new RuleViolation($code . ' was not found in the chart of accounts.');
        }

        $this->transaction(function () use ($existing, $input, $actorId) {
            $row = $this->fields($input, $existing);
            $hasPostings = (int) $this->value('SELECT COUNT(*) FROM {journal_lines} WHERE account_id = ?', [$existing['id']]) > 0;
            if ($hasPostings && $row['type'] !== $existing['type']) {
                throw new RuleViolation($existing['code'] . ' carries postings, so its type cannot change. Archive it and open a new account instead.');
            }

            if (array_key_exists('parent', $input)) {
                $parent = $this->parentFrom($input['parent']);
                $row['parent_id'] = $parent['id'] ?? null;
                $this->demoteToHeading($parent);
            }

            $this->db->table('accounts')->where('id', $existing['id'])->update($row + ['updated_at' => Clock::timestamp()]);
            $this->audit('account', (int) $existing['id'], $existing['code'], 'Changes to ' . $existing['code'] . ' ' . $row['name'] . ' saved', $actorId, 'account.updated');
        });

        return $this->find($code);
    }

    /** Archiving stops further posting; history and balance stay where they are. */
    public function archive(string $code, ?int $actorId = null): array
    {
        $existing = $this->lookups->accounts()[$code] ?? null;
        if ($existing === null) {
            throw new RuleViolation($code . ' was not found in the chart of accounts.');
        }

        $this->transaction(function () use ($existing, $actorId) {
            $this->db->table('accounts')->where('id', $existing['id'])->update(['status' => 'archived', 'updated_at' => Clock::timestamp()]);
            $this->audit('account', (int) $existing['id'], $existing['code'], $existing['code'] . ' ' . $existing['name'] . ' archived', $actorId, 'account.archived');
        });

        return $this->find($code);
    }

    /**
     * Classifies each row of an import file without changing anything: new,
     * update, skipped (an existing code when the mode is "skip") or rejected with
     * the reason. Balances are never imported; an account carrying postings keeps
     * its type; a new code must sit under a heading that already exists.
     *
     * @param list<array{line?: int, code?: string, name?: string, type?: string, restriction?: string, fund?: string, program?: string, funder?: string}> $rows
     * @return list<array>
     */
    public function importDryRun(array $rows, string $mode): array
    {
        $accounts = $this->lookups->accounts();
        $seen = [];

        return array_map(function ($r, $i) use ($accounts, $mode, &$seen) {
            $line = (int) ($r['line'] ?? $i + 2);
            $code = trim((string) ($r['code'] ?? ''));
            $name = trim((string) ($r['name'] ?? ''));
            $type = trim((string) ($r['type'] ?? ''));
            $existing = $accounts[$code] ?? null;
            $parentCode = strlen($code) === self::CODE_LENGTH ? self::parentCodeOf($code) : '';
            $parent = $parentCode !== '' && $parentCode !== $code ? ($accounts[$parentCode] ?? null) : null;
            $types = array_map('ucfirst', self::TYPES);

            $reason = match (true) {
                $code === ''                                 => 'Account code is missing',
                preg_match('/^[0-9]+$/', $code) !== 1        => 'Account code must be digits only',
                strlen($code) !== self::CODE_LENGTH          => 'Account code must be ' . self::CODE_LENGTH . ' digits',
                isset($seen[$code])                          => 'Duplicate of line ' . $seen[$code] . ' in this file',
                $name === ''                                 => 'Account name is missing',
                $type === ''                                 => 'Type is missing',
                !in_array($type, $types, true)               => 'Type must be one of ' . implode(', ', $types),
                $existing === null && ($parent === null || (int) $parent['level'] >= 2) => 'No parent account exists for ' . $parentCode,
                $existing === null && (int) $parent['is_leaf'] === 1 && $this->hasPostings((int) $parent['id'])
                                                             => $parentCode . ' carries postings, so no account can be added under it',
                $existing !== null && $existing['type'] !== strtolower($type) && $this->hasPostings((int) $existing['id'])
                                                             => 'Type cannot change on an account that already carries postings',
                default                                      => '',
            };
            if ($reason === '') {
                $seen[$code] = $line;
            }
            $skipped = $reason === '' && $existing !== null && $mode === 'skip';

            return [
                'line' => $line, 'code' => $code, 'name' => $name, 'type' => $type,
                'restriction' => trim((string) ($r['restriction'] ?? '')) ?: '—',
                'fund'        => trim((string) ($r['fund'] ?? '')) ?: 'All funds',
                'program'     => trim((string) ($r['program'] ?? '')) ?: '—',
                'funder'      => trim((string) ($r['funder'] ?? '')) ?: '—',
                'reason'      => $reason,
                'state'       => $reason !== '' ? 'Rejected' : ($skipped ? 'Skipped' : ($existing !== null ? 'Update' : 'New')),
            ];
        }, $rows, array_keys($rows));
    }

    /**
     * Applies the rows a dry run accepts, in one transaction. New accounts open at
     * zero under their heading; existing ones take only their descriptive fields.
     *
     * @return array{added: int, updated: int}
     */
    public function import(array $rows, string $mode, string $fileName, ?int $actorId): array
    {
        $classified = $this->importDryRun($rows, $mode);
        $adds = array_values(array_filter($classified, static fn ($r) => $r['state'] === 'New'));
        $edits = array_values(array_filter($classified, static fn ($r) => $r['state'] === 'Update'));
        if ($adds === [] && $edits === []) {
            throw new RuleViolation('Nothing in this file can be imported — every row was rejected.');
        }

        $this->transaction(function () use ($adds, $edits, $fileName, $actorId) {
            $descriptive = static fn ($r) => array_filter([
                'name' => $r['name'], 'restriction' => $r['restriction'] === '—' ? null : $r['restriction'],
                'fund' => $r['fund'], 'program' => $r['program'] === '—' ? 'Shared' : $r['program'], 'funder' => $r['funder'],
            ], static fn ($v) => $v !== null);

            foreach ($edits as $r) {
                $existing = $this->lookups->accounts()[$r['code']];
                $row = $this->fields($descriptive($r) + ['type' => $existing['type']], $existing);
                $this->db->table('accounts')->where('id', $existing['id'])->update($row + ['updated_at' => Clock::timestamp()]);
            }

            // Codes in order, so a heading added by the file exists before the accounts under it.
            usort($adds, static fn ($a, $b) => strcmp($a['code'], $b['code']));
            foreach ($adds as $r) {
                Repository::forget();
                $parent = $this->lookups->accounts()[self::parentCodeOf($r['code'])];
                $this->insert('accounts', ['code' => $r['code'], 'level' => (int) $parent['level'] + 1, 'parent_id' => $parent['id']]
                    + $this->fields($descriptive($r) + ['type' => $r['type'], 'postable' => true], null) + ['created_at' => Clock::timestamp()]);
                $this->demoteToHeading($parent);
            }

            $summary = count($adds) . (count($adds) === 1 ? ' account' : ' accounts') . ' added and ' . count($edits) . ' updated from ' . ($fileName !== '' ? $fileName : 'an import file');
            $this->audit('account', null, null, $summary, $actorId, 'account.imported');
        });

        return ['added' => count($adds), 'updated' => count($edits)];
    }

    /** A code ending in 00 is a heading under its class root (5400 → 5000); any other code sits under its heading (5410 → 5400). */
    public static function parentCodeOf(string $code): string
    {
        return str_ends_with($code, '00') ? substr($code, 0, 1) . '000' : substr($code, 0, 2) . '00';
    }

    private static function checkCode(string $code): void
    {
        if ($code === '' || preg_match('/^[0-9]+$/', $code) !== 1 || strlen($code) !== self::CODE_LENGTH) {
            throw new RuleViolation('An account code is ' . self::CODE_LENGTH . ' digits, e.g. 5350.');
        }
    }

    private function hasPostings(int $accountId): bool
    {
        return (int) $this->value('SELECT COUNT(*) FROM {journal_lines} WHERE account_id = ?', [$accountId]) > 0;
    }

    /** Screen fields → columns, keeping existing values for anything not sent. */
    private function fields(array $in, ?array $existing): array
    {
        $name = trim((string) ($in['name'] ?? $existing['name'] ?? ''));
        if ($name === '') {
            throw new RuleViolation('Account name is required.');
        }

        $type = strtolower((string) ($in['type'] ?? $existing['type'] ?? 'expense'));
        if (!in_array($type, self::TYPES, true)) {
            throw new RuleViolation('"' . ($in['type'] ?? '') . '" is not an account type.');
        }

        $restriction = strtolower((string) ($in['restriction'] ?? $existing['restriction'] ?? 'unrestricted'));
        $grantId     = array_key_exists('grant', $in) ? $this->lookups->grantId($in['grant']) : ($existing['default_grant_id'] ?? null);
        $programme   = array_key_exists('program', $in) ? $this->lookups->programmeId($in['program']) : ($existing['default_programme_id'] ?? null);
        $fundName    = $in['fund'] ?? null;

        $fundId = $existing['default_fund_id'] ?? null;
        if ($fundName !== null) {
            $fundId = $fundName === 'All funds'
                ? null
                : $this->lookups->resolveFund($fundName, $grantId === null ? null : (int) $grantId, $this->lookups->programmeName($programme === null ? null : (int) $programme), $existing['code'] ?? null);
        }

        return [
            'name'                    => $name,
            'type'                    => $type,
            'restriction'             => in_array($restriction, ['unrestricted', 'restricted', 'endowment'], true) ? $restriction : null,
            'default_fund_id'         => $fundId,
            'default_programme_id'    => $programme,
            'default_grant_id'        => $grantId,
            'funder_id'               => array_key_exists('funder', $in) ? $this->lookups->funderId($in['funder']) : ($existing['funder_id'] ?? null),
            'currency'                => strtoupper(substr((string) ($in['currency'] ?? $existing['currency'] ?? 'KES'), 0, 3)),
            'notes'                   => array_key_exists('notes', $in) ? (trim((string) $in['notes']) ?: null) : ($existing['notes'] ?? null),
            'is_leaf'                 => (int) (array_key_exists('postable', $in) ? (bool) $in['postable'] : (bool) ($existing['is_leaf'] ?? true)),
            'requires_reconciliation' => (int) (array_key_exists('reconcile', $in) ? (bool) $in['reconcile'] : (bool) ($existing['requires_reconciliation'] ?? false)),
            'in_donor_reports'        => (int) (array_key_exists('donorReport', $in) ? (bool) $in['donorReport'] : (bool) ($existing['in_donor_reports'] ?? true)),
        ];
    }

    /** "5100 · Programme costs" → that account; "— (top level)" → none. */
    private function parentFrom(?string $label): ?array
    {
        $code = trim(explode('·', (string) $label)[0]);

        return $code === '' || str_starts_with($code, '—') ? null : ($this->lookups->accounts()[$code] ?? null);
    }

    private function demoteToHeading(?array $parent): void
    {
        if ($parent === null || !$parent['is_leaf']) {
            return;
        }
        if ((int) $this->value('SELECT COUNT(*) FROM {journal_lines} WHERE account_id = ?', [$parent['id']]) > 0) {
            throw new RuleViolation($parent['code'] . ' carries postings, so it cannot become a heading. Choose another parent.');
        }

        $this->db->table('accounts')->where('id', $parent['id'])->update(['is_leaf' => 0]);
    }
}
