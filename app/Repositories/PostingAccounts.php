<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\EntityScope;
use App\Libraries\Prototype;

/**
 * Where the application's own postings go: the account in the chart that fills
 * each role — the payable a bill is owed on, the allowance a doubtful debt is
 * provided against, the bank a payroll is paid from.
 *
 * The roles are the application's; the account behind each is the organisation's,
 * chosen in Settings → Ledger and held on the head office. A role no one has set
 * uses its standard code from the chart templates, so a new instance posts exactly
 * as it always has.
 *
 * A role that pays out of a bank or cash account (ENTITY_ROLES) is each entity's
 * own, because bank accounts are: an entity that has chosen none follows the head
 * office, and a posting is refused rather than paid out of a bank account that
 * belongs to another entity. A ledger account carries a cash account of each
 * entity, so a branch that opens its own on the head office's bank code (1110)
 * follows the head office and pays out of its own bank.
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

    /**
     * Roles that pay out of, or into, a bank or cash account. Each entity chooses its
     * own; the rest are chosen once for the organisation.
     */
    public const ENTITY_ROLES = ['advanceBank', 'advanceMpesa', 'advanceCash', 'payrollBank', 'disposalProceeds'];

    private Lookups $lookups;

    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        parent::__construct($db);
        $this->lookups = new Lookups();
    }

    /**
     * The code of the account a posting made now goes to. Refused when the role pays
     * out of a bank account another entity holds: the money would leave that
     * entity's bank in this entity's books.
     */
    public static function of(string $role): string
    {
        $accounts = new self();
        if (($refusal = $accounts->refusal($role)) !== null) {
            throw new RuleViolation($refusal);
        }

        return $accounts->code($role);
    }

    /** Why a posting to the role's account would be refused now, or null when it would not. */
    public function refusal(string $role): ?string
    {
        $code = $this->code($role);
        $owner = $this->otherEntitysBank($role, $code);

        return $owner === null ? null : self::ROLES[$role][0] . ' is ' . $code . ', a bank account of ' . $owner . ' that '
            . $this->entityName() . ' holds no cash account on. Open ' . $this->entityName() . "'s own on " . $code
            . ' in Settings → Bank statements, or choose another of its accounts in Settings → Ledger → Posting accounts.';
    }

    public function code(string $role): string
    {
        $standard = self::ROLES[$role][2] ?? throw new \InvalidArgumentException('No posting role ' . $role . '.');

        return $this->own()[$role] ?? $this->chosen()[$role] ?? $standard;
    }

    public static function isEntityRole(string $role): bool
    {
        return in_array($role, self::ENTITY_ROLES, true);
    }

    /** Every role with the account filling it, for Settings → Ledger. */
    public function all(): array
    {
        $lookups = new Lookups();
        $accounts = $lookups->accounts();

        return array_map(function (string $role) use ($accounts, $lookups) {
            [$label, $module, $standard, $types, $what, $control] = self::ROLES[$role];
            $code = $this->code($role);
            $entity = self::isEntityRole($role);

            return [
                'role' => $role, 'label' => $label, 'module' => $module, 'what' => $what, 'types' => $types,
                'code' => $code, 'name' => $accounts[$code]['name'] ?? '', 'missing' => !isset($accounts[$code]),
                'standard' => $standard, 'control' => $control,
                // A control account is the organisation's, so its balance is the whole organisation's.
                'balance' => $control ? self::num(EntityScope::across(fn () => $lookups->balance($code))) : null,
                // Chosen by each entity, and whether this one has chosen, or follows the head office.
                'entity' => $entity, 'own' => $entity && isset($this->own()[$role]),
                'otherEntity' => $entity ? $this->otherEntitysBank($role, $code) : null,
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
        if (($owner = $this->otherEntitysBank($role, $code)) !== null) {
            throw new RuleViolation($code . ' ' . $account['name'] . ' is a bank account of ' . $owner . ' and ' . $this->entityName() . ' holds no cash account on it, so it cannot take '
                . lcfirst($label) . ' from it. Open ' . $this->entityName() . "'s own on " . $code . ' in Settings → Bank statements first, or choose one of its own accounts.');
        }
        // The organisation's control accounts hold every entity's balance.
        $balance = EntityScope::across(fn () => (new Lookups())->balance($current));
        if ($control && round($balance, 2) != 0) {
            throw new RuleViolation($current . ' still holds KES ' . Prototype::fmt(abs($balance)) . ' as ' . strtolower($label)
                . '. Its entries are cleared from the account they were posted to, so it can move once that is nil — or journal the balance across to ' . $code . ' first.');
        }

        return ($this->holderId($role) === $this->lookups->headOfficeId() ? '' : $this->entityName() . ': ')
            . $label . ' posts to ' . $code . ' ' . $account['name'] . ' instead of ' . $current;
    }

    /** Sets the account for a role: the entity's own for an entity role, else the organisation's. */
    public function set(string $role, string $code): void
    {
        $id = (int) $this->value('SELECT id FROM {accounts} WHERE code = ?', [$code]);
        $entityId = $this->holderId($role);
        $now = Clock::timestamp();
        $table = $this->db->table('posting_accounts');
        if ($table->where('role', $role)->where('entity_id', $entityId)->countAllResults() === 0) {
            $this->insert('posting_accounts', ['entity_id' => $entityId, 'role' => $role, 'account_id' => $id, 'created_at' => $now]);
        } else {
            $this->db->table('posting_accounts')->where('role', $role)->where('entity_id', $entityId)->update(['account_id' => $id, 'updated_at' => $now]);
        }
    }

    /**
     * Drops the entity's own account for a role, so that it follows the head office
     * again. Returns what the audit log should say, or null when it already does.
     */
    public function follow(string $role): ?string
    {
        if (!self::isEntityRole($role) || !isset($this->own()[$role])) {
            return null;
        }
        $label = self::ROLES[$role][0];
        $to = $this->chosen()[$role] ?? self::ROLES[$role][2];

        return $this->entityName() . ': ' . $label . ' follows the head office again, posting to ' . $to . ' instead of ' . $this->own()[$role];
    }

    public function unset(string $role): void
    {
        $this->db->table('posting_accounts')->where('role', $role)->where('entity_id', $this->holderId($role))->delete();
    }

    /** Whose choice a role is: the entity being worked in for an entity role, else the head office. */
    private function holderId(string $role): int
    {
        return self::isEntityRole($role) ? $this->lookups->entityId() : $this->lookups->headOfficeId();
    }

    /** @return array<string, string> role => code, for the roles the organisation has set */
    private function chosen(): array
    {
        return $this->cached('chosen', fn () => $this->choicesOf($this->lookups->headOfficeId()));
    }

    /** @return array<string, string> role => code, for the entity roles the entity being worked in has set */
    private function own(): array
    {
        $entityId = $this->lookups->entityId();

        return $entityId === $this->lookups->headOfficeId() ? [] : $this->cached('own:' . $entityId, fn () => array_intersect_key(
            $this->choicesOf($entityId), array_flip(self::ENTITY_ROLES)
        ));
    }

    private function choicesOf(int $entityId): array
    {
        return array_column($this->rows(
            'SELECT p.role, a.code FROM {posting_accounts} p JOIN {accounts} a ON a.id = p.account_id WHERE p.entity_id = ?', [$entityId]
        ), 'code', 'role');
    }

    /**
     * The name of an entity that holds $code as a bank account, when a role that
     * pays out of the bank would take it from another entity's because this one holds
     * no cash account on it; else null. Petty cash and other accounts no entity holds
     * as a bank account are anyone's.
     */
    private function otherEntitysBank(string $role, string $code): ?string
    {
        if (!self::isEntityRole($role)) {
            return null;
        }
        $holders = $this->cached('bank-holders', function () {
            $out = [];
            foreach ($this->rows('SELECT a.code, b.entity_id, e.name FROM {all:bank_accounts} b JOIN {accounts} a ON a.id = b.account_id JOIN {entities} e ON e.id = b.entity_id') as $r) {
                $out[$r['code']][(int) $r['entity_id']] = $r['name'];
            }

            return $out;
        });
        $held = $holders[$code] ?? [];

        return $held === [] || isset($held[$this->lookups->entityId()]) ? null : (string) reset($held);
    }

    private function entityName(): string
    {
        $id = $this->lookups->entityId();

        return (string) $this->cached('entity-name:' . $id, fn () => $this->value('SELECT name FROM {entities} WHERE id = ?', [$id]));
    }

    private static function article(string $word): string
    {
        return in_array($word[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
    }
}
