<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Prototype;

/** Supplier bills, with their coding, budget position and payment method. */
final class PayablesRepository extends Repository
{
    private const STATUS_LABELS = ['pending_approval' => 'Awaiting approval'];

    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    public function all(): array
    {
        return $this->cached('all', function () {
            $lines = [];
            foreach ($this->rows(
                'SELECT l.*, a.code, f.ledger_group, g.short_name AS grant_short
                 FROM {bill_lines} l JOIN {accounts} a ON a.id = l.account_id JOIN {funds} f ON f.id = l.fund_id
                 LEFT JOIN {grants} g ON g.id = l.grant_id ORDER BY l.bill_id, l.line_no'
            ) as $l) {
                $lines[(int) $l['bill_id']][] = $l;
            }

            $budgets = $this->budgetPositions();
            $banks   = array_column($this->lookups->bankAccounts(), null, 'id');
            $trails  = $this->trails('bill');

            return array_map(function ($b) use ($lines, $budgets, $banks, $trails) {
                $billLines = $lines[(int) $b['id']] ?? [];
                $first     = $billLines[0] ?? null;
                $position  = $first === null ? null : ($budgets[$first['account_id'] . ':' . $first['fund_id'] . ':' . $first['programme_id']] ?? null);
                $bank      = $banks[$b['pay_from_bank_account_id']] ?? null;

                return [
                    'no'       => $b['reference'],
                    'supplier' => $b['supplier_name'],
                    'pin'      => $b['kra_pin'] ?? '—',
                    'category' => $b['category'],
                    'invDate'  => self::dm($b['invoice_date']),
                    'dueDate'  => self::dm($b['due_date']),
                    'dueIn'    => Clock::daysUntil($b['due_date']),
                    'terms'    => $b['terms_days'] . ' days',
                    'fund'     => $first === null ? 'General Fund' : self::FUND_GROUPS[$first['ledger_group']],
                    'program'  => $first === null ? 'Shared services' : $this->lookups->programmeName((int) $first['programme_id']),
                    'grant'    => $first['grant_short'] ?? 'Unassigned',
                    'budget'   => $position === null ? '—' : 'KES ' . Prototype::fmt($position['actual'] + $position['committed']) . ' of ' . Prototype::fmt($position['budget']),
                    'taxable'  => self::num($b['subtotal']),
                    'whtRate'  => self::num($b['wht_rate_pct']),
                    'status'   => self::label($b['status'], self::STATUS_LABELS),
                    'method'   => $bank === null ? '—' : Lookups::paymentMethod($bank),
                    'lines'    => array_map(static fn ($l) => ['code' => $l['code'], 'desc' => $l['description'], 'amount' => self::num($l['amount'])], $billLines),
                    'trail'    => $trails[(int) $b['id']] ?? [],
                ];
            }, $this->rows(
                'SELECT b.*, s.name AS supplier_name, s.kra_pin, s.category FROM {bills} b JOIN {suppliers} s ON s.id = b.supplier_id
                 ORDER BY b.invoice_date, b.reference'
            ));
        });
    }

    public function find(string $no): ?array
    {
        foreach ($this->all() as $b) {
            if ($b['no'] === $no) {
                return $b;
            }
        }

        return null;
    }

    /** Payment methods: local bank transfer, mobile money, cheque, then foreign-currency accounts. */
    public function methods(): array
    {
        $rank = static fn (array $b) => match (true) {
            $b['kind'] === 'mobile_money' => 1,
            $b['currency'] !== 'KES'      => 3,
            default                       => 0,
        };
        $accounts = array_values(array_filter($this->lookups->bankAccounts(), static fn ($b) => $b['status'] === 'active' && $b['kind'] !== 'petty_cash'));
        usort($accounts, static fn ($a, $b) => [$rank($a), $a['code']] <=> [$rank($b), $b['code']]);

        $methods = array_map([Lookups::class, 'paymentMethod'], $accounts);
        $foreign = count(array_filter($accounts, static fn ($b) => $rank($b) === 3));
        array_splice($methods, count($methods) - $foreign, 0, ['Cheque']);

        return $methods;
    }

    /**
     * Approved budget, actual and committed per account, fund and programme, summed
     * across grants, from the approved budget version.
     *
     * @return array<string, array{budget: float, actual: float, committed: float}>
     */
    public function budgetPositions(): array
    {
        return $this->cached('budget-positions', function () {
            $out = [];
            foreach ($this->rows(
                "SELECT v.account_id, v.fund_id, v.programme_id, SUM(v.budget) AS budget, SUM(v.actual) AS actual, SUM(v.committed) AS committed
                 FROM {v_budget_availability} v JOIN {budget_versions} bv ON bv.id = v.budget_version_id
                 WHERE bv.status = 'approved' GROUP BY v.account_id, v.fund_id, v.programme_id"
            ) as $r) {
                $out[$r['account_id'] . ':' . $r['fund_id'] . ':' . $r['programme_id']] = [
                    'budget' => (float) $r['budget'], 'actual' => (float) $r['actual'], 'committed' => (float) $r['committed'],
                ];
            }

            return $out;
        });
    }
}
