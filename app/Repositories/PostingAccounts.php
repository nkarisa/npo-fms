<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Prototype;

/**
 * Where the application's own postings go: the account in the chart that fills
 * each role — the payable a bill is owed on, the allowance a doubtful debt is
 * provided against, the bank a payroll is paid from.
 *
 * The roles are the application's; the account behind each is the organisation's,
 * chosen in Settings → Ledger. A role no one has set uses its standard code from
 * the chart templates, so a new instance posts exactly as it always has.
 */
final class PostingAccounts extends Repository
{
    /**
     * [label, module, standard code, account types it may be, what posts to it, holds a balance that is cleared later].
     * A control account is refused a change while it holds a balance: the entries
     * that clear it would land in the new account and leave the old one standing.
     */
    public const ROLES = [
        'payables'         => ['Trade payables', 'Payables', '2110', ['liability'], 'Approved bills, cleared when paid; an advance holder\'s overspend', true],
        'accrued'          => ['Goods received not invoiced', 'Payables', '2120', ['liability'], 'Goods received against an order, cleared when the invoice is billed', true],
        'whtPayable'       => ['Withholding tax payable', 'Payables', '2240', ['liability'], 'Withholding held on bills, cleared when remitted to KRA', true],
        'receivables'      => ['Grants receivable', 'Receivables', '1210', ['asset'], 'Donor claims issued, cleared when received', true],
        'allowance'        => ['Allowance for doubtful debts', 'Receivables', '1215', ['asset'], 'Provisions against claims, used on write-off', true],
        'badDebts'         => ['Bad and doubtful debts', 'Receivables', '5370', ['expense'], 'Write-offs and provisions charged', false],
        'grantIncome'      => ['Grant income claimed', 'Receivables', '4110', ['income'], 'The income side of a donor claim', false],
        'advances'         => ['Staff advances', 'Advances', '1220', ['asset'], 'Advances issued, cleared on surrender', true],
        'advanceBank'      => ['Advances paid by bank', 'Advances', '1110', ['asset'], 'Advances paid by bank transfer, and refunds of unspent balances', false],
        'advanceMpesa'     => ['Advances paid by M-Pesa', 'Advances', '1130', ['asset'], 'Advances paid by M-Pesa', false],
        'advanceCash'      => ['Advances paid in cash', 'Advances', '1140', ['asset'], 'Advances paid from petty cash', false],
        'payrollBank'      => ['Payroll bank', 'Payroll', '1110', ['asset'], 'Net pay, and the statutory deductions when remitted', false],
        'depreciation'     => ['Depreciation charge', 'Fixed assets', '5350', ['expense'], 'The monthly depreciation run', false],
        'accumulated'      => ['Accumulated depreciation', 'Fixed assets', '1390', ['asset'], 'The depreciation run, released on disposal', false],
        'disposalProceeds' => ['Disposal proceeds', 'Fixed assets', '1110', ['asset'], 'The bank a sale of an asset is paid into', false],
        'disposalGain'     => ['Gain on disposal', 'Fixed assets', '4250', ['income'], 'Proceeds above carrying value', false],
        'disposalLoss'     => ['Loss on disposal', 'Fixed assets', '5360', ['expense'], 'Carrying value above proceeds', false],
        'donatedAssets'    => ['Donated assets', 'Fixed assets', '4260', ['income'], 'Assets received in kind', false],
        'foundAssets'      => ['Fund balance — assets found', 'Fixed assets', '3100', ['equity'], 'Assets found in a count that were never recorded', false],
        'bankCharges'      => ['Bank charges', 'Bank reconciliation', '5340', ['expense'], 'Charges and fees taken from a statement', false],
        'bankInterest'     => ['Interest received', 'Bank reconciliation', '4230', ['income'], 'Interest credited on a statement', false],
        'fxGain'           => ['Exchange gain', 'Bank reconciliation', '4240', ['income'], 'Exchange gains credited on a statement', false],
        'suspense'         => ['Suspense', 'Bank reconciliation', '2190', ['liability'], 'Credits on a statement no one can yet identify', true],
    ];

    /** The code of the account that fills a role. */
    public static function of(string $role): string
    {
        return (new self())->code($role);
    }

    public function code(string $role): string
    {
        $standard = self::ROLES[$role][2] ?? throw new \InvalidArgumentException('No posting role ' . $role . '.');

        return $this->chosen()[$role] ?? $standard;
    }

    /** Every role with the account filling it, for Settings → Ledger. */
    public function all(): array
    {
        $lookups = new Lookups();
        $accounts = $lookups->accounts();

        return array_map(function (string $role) use ($accounts, $lookups) {
            [$label, $module, $standard, $types, $what, $control] = self::ROLES[$role];
            $code = $this->code($role);

            return [
                'role' => $role, 'label' => $label, 'module' => $module, 'what' => $what, 'types' => $types,
                'code' => $code, 'name' => $accounts[$code]['name'] ?? '', 'missing' => !isset($accounts[$code]),
                'standard' => $standard, 'control' => $control, 'balance' => $control ? self::num($lookups->balance($code)) : null,
            ];
        }, array_keys(self::ROLES));
    }

    /** Postable accounts a role could use, by type. */
    public function options(): array
    {
        return array_map(static fn ($a) => ['code' => $a['code'], 'name' => $a['name'], 'type' => $a['type']], $this->rows(
            "SELECT code, name, type FROM {accounts} WHERE is_leaf = 1 AND status = 'active' ORDER BY code"
        ));
    }

    /**
     * Checks a change of account for a role and returns what the audit log should
     * say, or null when nothing changes. The caller writes it with set().
     */
    public function check(string $role, string $code): ?string
    {
        if (!isset(self::ROLES[$role])) {
            throw new RuleViolation('There is no posting role called ' . $role . '.');
        }
        [$label, , , $types, , $control] = self::ROLES[$role];
        $current = $this->code($role);
        if ($code === $current) {
            return null;
        }
        $account = $this->row("SELECT * FROM {accounts} WHERE code = ? AND is_leaf = 1 AND status = 'active'", [$code])
            ?? throw new RuleViolation($code . ' is not an active account that can be posted to, so it cannot take ' . $label . '.');
        if (!in_array($account['type'], $types, true)) {
            throw new RuleViolation($label . ' has to be ' . self::article($types[0]) . ' ' . $types[0] . ' account. ' . $code . ' ' . $account['name'] . ' is ' . self::article($account['type']) . ' ' . $account['type'] . ' account.');
        }
        $balance = (new Lookups())->balance($current);
        if ($control && round($balance, 2) != 0) {
            throw new RuleViolation($current . ' still holds KES ' . Prototype::fmt(abs($balance)) . ' as ' . strtolower($label)
                . '. Its entries are cleared from the account they were posted to, so it can move once that is nil — or journal the balance across to ' . $code . ' first.');
        }

        return $label . ' posts to ' . $code . ' ' . $account['name'] . ' instead of ' . $current;
    }

    public function set(string $role, string $code): void
    {
        $id = (int) $this->value('SELECT id FROM {accounts} WHERE code = ?', [$code]);
        $now = Clock::timestamp();
        if ($this->value('SELECT id FROM {posting_accounts} WHERE role = ?', [$role]) === null) {
            $this->insert('posting_accounts', ['role' => $role, 'account_id' => $id, 'created_at' => $now]);
        } else {
            $this->db->table('posting_accounts')->where('role', $role)->update(['account_id' => $id, 'updated_at' => $now]);
        }
    }

    /** @return array<string, string> role => code, for the roles someone has set */
    private function chosen(): array
    {
        return $this->cached('chosen', fn () => array_column($this->rows(
            'SELECT p.role, a.code FROM {posting_accounts} p JOIN {accounts} a ON a.id = p.account_id'
        ), 'code', 'role'));
    }

    private static function article(string $word): string
    {
        return in_array($word[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
    }
}
