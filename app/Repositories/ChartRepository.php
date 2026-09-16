<?php

namespace App\Repositories;

/**
 * The chart of accounts with each account's balance from the posted ledger.
 *
 * 3900, surplus for the year, is never posted: its balance is income less
 * expenditure, which is how the statements articulate.
 */
final class ChartRepository extends Repository
{
    public const DERIVED_SURPLUS = '3900';

    private const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

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

    private function present(array $a): array
    {
        $balances = $this->lookups->balances();
        $balance  = $a['code'] === self::DERIVED_SURPLUS
            ? array_sum(array_map(fn ($code) => $this->signedFor($code, 'income'), array_keys($balances)))
                - array_sum(array_map(fn ($code) => $this->signedFor($code, 'expense'), array_keys($balances)))
            : ($balances[$a['code']] ?? 0);

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
    public function create(array $input): array
    {
        $code = trim((string) ($input['code'] ?? ''));
        if (isset($this->lookups->accounts()[$code])) {
            throw new RuleViolation($code . ' already exists in the chart of accounts.');
        }

        $this->transaction(function () use ($code, $input) {
            $parent = $this->parentFrom($input['parent'] ?? null);
            $this->insert('accounts', ['code' => $code, 'level' => $parent === null ? 0 : (int) $parent['level'] + 1]
                + $this->fields($input, null) + ['parent_id' => $parent['id'] ?? null, 'created_at' => date('Y-m-d H:i:s')]);
            $this->demoteToHeading($parent);
        });

        return $this->find($code);
    }

    public function update(string $code, array $input): array
    {
        $existing = $this->lookups->accounts()[$code] ?? null;
        if ($existing === null) {
            throw new RuleViolation($code . ' was not found in the chart of accounts.');
        }

        $this->transaction(function () use ($existing, $input) {
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

            $this->db->table('accounts')->where('id', $existing['id'])->update($row + ['updated_at' => date('Y-m-d H:i:s')]);
        });

        return $this->find($code);
    }

    /** Archiving stops further posting; history and balance stay where they are. */
    public function archive(string $code): array
    {
        $existing = $this->lookups->accounts()[$code] ?? null;
        if ($existing === null) {
            throw new RuleViolation($code . ' was not found in the chart of accounts.');
        }

        $this->transaction(fn () => $this->db->table('accounts')->where('id', $existing['id'])->update(['status' => 'archived', 'updated_at' => date('Y-m-d H:i:s')]));

        return $this->find($code);
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
