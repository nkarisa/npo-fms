<?php

namespace App\Repositories;

use App\Libraries\Clock;

/**
 * The classes an asset is registered under, chosen in Settings → Ledger: the name,
 * the prefix its tags are numbered under, the useful life a new asset starts with,
 * and the cost account it is carried in.
 *
 * The cost account is what ties the register to the ledger. A purchase on it can be
 * capitalised, a donated or found asset of the class is debited to it on approval,
 * a disposal credits it, and the register's cost is compared with its balance. So
 * it has to be a fixed-asset account — an asset account in the same group as the
 * accumulated depreciation account (1300 in the standard chart) — and it is fixed
 * while the class has assets carried in it: moving it would leave their cost in the
 * old account and send their disposal to the new one.
 *
 * Depreciation is not the class's: the charge and the accumulated depreciation post
 * to the organisation's posting accounts (PostingAccounts 'depreciation' and
 * 'accumulated'). asset_classes still records both columns, set from those accounts
 * when a class is added, but nothing posts from them.
 *
 * Useful life is copied onto each asset when it is registered, so changing a
 * class's life changes only the assets registered after.
 */
final class AssetClassRepository extends Repository
{
    public const MAX_LIFE = 50;

    /**
     * Every class, with its cost account and how many assets it carries.
     *
     * @return list<array{id: int, name: string, prefix: string, life: int, cost: string, costName: string, carried: int, assets: int}>
     */
    public function all(): array
    {
        return $this->cached('all', fn () => array_map(static fn ($c) => [
            'id' => (int) $c['id'], 'name' => $c['name'], 'prefix' => $c['tag_prefix'], 'life' => (int) $c['useful_life_years'],
            'cost' => $c['code'], 'costName' => $c['account_name'],
            // Carried in the ledger now, which fixes the cost account; and ever registered, which fixes nothing.
            'carried' => (int) $c['carried'], 'assets' => (int) $c['assets'],
        ], $this->rows(
            "SELECT c.*, a.code, a.name AS account_name,
                    (SELECT COUNT(*) FROM {all:assets} s WHERE s.asset_class_id = c.id AND s.capitalised = 1 AND s.status <> 'disposed') AS carried,
                    (SELECT COUNT(*) FROM {all:assets} s WHERE s.asset_class_id = c.id) AS assets
             FROM {asset_classes} c JOIN {accounts} a ON a.id = c.cost_account_id ORDER BY c.name"
        )));
    }

    /**
     * The accounts a class can be carried in: active, postable asset accounts in the
     * accumulated depreciation account's group, other than that account itself. A
     * chart with no such group offers every asset account that is not a bank.
     *
     * @return list<array{code: string, name: string}>
     */
    public function costOptions(): array
    {
        return $this->cached('cost-options', function () {
            $accum = $this->row('SELECT id, parent_id FROM {accounts} WHERE code = ?', [PostingAccounts::of('accumulated')]);
            $group = $accum['parent_id'] ?? null;

            return array_map(static fn ($a) => ['code' => $a['code'], 'name' => $a['name']], $this->rows(
                "SELECT code, name FROM {accounts} WHERE type = 'asset' AND is_leaf = 1 AND status = 'active' AND id <> ?"
                . ($group !== null ? ' AND parent_id = ?' : ' AND id NOT IN (SELECT account_id FROM {all:bank_accounts})')
                . ' ORDER BY code',
                $group !== null ? [(int) ($accum['id'] ?? 0), (int) $group] : [(int) ($accum['id'] ?? 0)]
            ));
        });
    }

    /**
     * Checks a class as edited and returns what the audit log should say of each
     * change, or [] when nothing changes. The caller writes it with set().
     *
     * @param array{id?: int|string|null, name?: string, prefix?: string, life?: int|string, cost?: string} $in
     * @param list<array> $others the other classes as they will stand, for names and prefixes in use
     * @return list<string>
     */
    public function check(array $in, array $others): array
    {
        $current = isset($in['id']) && $in['id'] !== '' && $in['id'] !== null
            ? (current(array_filter($this->all(), static fn ($c) => $c['id'] === (int) $in['id'])) ?: throw new RuleViolation('There is no asset class ' . $in['id'] . '.'))
            : null;
        $name = trim((string) ($in['name'] ?? ''));
        $prefix = strtoupper(trim((string) ($in['prefix'] ?? '')));
        $life = (string) ($in['life'] ?? '');
        $cost = trim((string) ($in['cost'] ?? ''));
        $called = $name !== '' ? $name : ($current['name'] ?? 'A new asset class');

        $refusal = match (true) {
            $name === ''                                    => 'Every asset class needs a name.',
            mb_strlen($name) > 60                           => $name . ': a class name is at most 60 characters.',
            preg_match('/^[A-Z0-9]{1,10}$/', $prefix) !== 1 => $called . ': the tag prefix is 1 to 10 letters or digits, such as VEH — tags are numbered VEH-001, VEH-002.',
            !ctype_digit($life) || (int) $life < 1 || (int) $life > self::MAX_LIFE => $called . ': enter a useful life of 1 to ' . self::MAX_LIFE . ' years.',
            default                                         => null,
        };
        if ($refusal !== null) {
            throw new RuleViolation($refusal);
        }
        foreach ($others as $o) {
            if (mb_strtolower(trim((string) $o['name'])) === mb_strtolower($name)) {
                throw new RuleViolation('There is already an asset class called ' . $name . '.');
            }
            if (strtoupper(trim((string) $o['prefix'])) === $prefix) {
                throw new RuleViolation(trim((string) $o['name']) . ' already numbers its tags under ' . $prefix . '. Give ' . $called . ' a prefix of its own.');
            }
        }
        $options = array_column($this->costOptions(), 'name', 'code');
        if ($current === null || $cost !== $current['cost']) {
            if (!isset($options[$cost])) {
                throw new RuleViolation($called . ' has to be carried in a fixed-asset account — an asset account in the group of ' . PostingAccounts::of('accumulated')
                    . ' accumulated depreciation' . ($cost === '' ? '. Choose one.' : '. ' . $cost . ' is not one.'));
            }
            if ($current !== null && $current['carried'] > 0) {
                throw new RuleViolation($current['name'] . ' carries ' . $current['carried'] . ' ' . ($current['carried'] === 1 ? 'asset' : 'assets') . ' at cost in ' . $current['cost']
                    . ', so its cost account is fixed: moving it would leave their cost in ' . $current['cost'] . ' and send their disposal to ' . $cost
                    . '. Add a new class on ' . $cost . ' for assets registered from now on.');
            }
        }

        if ($current === null) {
            return ['Asset class ' . $name . ' added: tags ' . $prefix . '-001 on, ' . (int) $life . '-year life, carried in ' . $cost . ' ' . $options[$cost]];
        }
        $changes = [];
        if ($name !== $current['name']) {
            $changes[] = 'Asset class ' . $current['name'] . ' renamed ' . $name;
        }
        if ($prefix !== $current['prefix']) {
            $changes[] = $name . ': new tags numbered under ' . $prefix . ' instead of ' . $current['prefix'] . ($current['assets'] > 0 ? ' — tags already issued keep theirs' : '');
        }
        if ((int) $life !== $current['life']) {
            $changes[] = $name . ': useful life for new assets ' . ((int) $life > $current['life'] ? 'raised' : 'lowered') . ' from ' . $current['life'] . ' to ' . (int) $life . ' years'
                . ($current['assets'] > 0 ? ' — assets already registered keep theirs' : '');
        }
        if ($cost !== $current['cost']) {
            $changes[] = $name . ': carried in ' . $cost . ' ' . $options[$cost] . ' instead of ' . $current['cost'];
        }

        return $changes;
    }

    /** Writes a class as check() passed it: an update when it has an id, else a new class. */
    public function set(array $in): void
    {
        $now = Clock::timestamp();
        $row = [
            'name' => trim((string) $in['name']), 'tag_prefix' => strtoupper(trim((string) $in['prefix'])),
            'useful_life_years' => (int) $in['life'], 'cost_account_id' => $this->accountId(trim((string) $in['cost'])),
        ];
        if (!empty($in['id'])) {
            $this->db->table('asset_classes')->where('id', (int) $in['id'])->update($row + ['updated_at' => $now]);

            return;
        }
        $this->insert('asset_classes', $row + [
            'accumulated_depreciation_account_id' => $this->accountId(PostingAccounts::of('accumulated')),
            'depreciation_expense_account_id'     => $this->accountId(PostingAccounts::of('depreciation')),
            'created_at' => $now,
        ]);
    }

    private function accountId(string $code): int
    {
        return (int) $this->value('SELECT id FROM {accounts} WHERE code = ?', [$code]);
    }
}
