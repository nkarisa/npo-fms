<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Prototype;

/**
 * Staff and observer advances. What is accounted for is the sum of the receipts
 * surrendered; what is recovered is the sum of payroll deductions and cash paid
 * back. The control balance is the posted balance on 1220.
 */
final class AdvancesRepository extends Repository
{
    public const CONTROL_ACCOUNT = '1220';

    private const METHOD_LABELS = ['mpesa' => 'M-Pesa', 'bank' => 'Bank', 'cash' => 'Cash'];

    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    public function all(): array
    {
        return $this->cached('all', function () {
            $surrenders = [];
            foreach ($this->rows('SELECT s.*, a.code FROM {advance_surrenders} s JOIN {accounts} a ON a.id = s.account_id ORDER BY s.advance_id, s.id') as $s) {
                $surrenders[(int) $s['advance_id']][] = ['code' => $s['code'], 'desc' => $s['description'], 'amount' => self::num($s['amount'])];
            }
            $recovered = array_column($this->rows('SELECT advance_id, SUM(amount) AS total FROM {advance_recoveries} GROUP BY advance_id'), 'total', 'advance_id');
            $trails = $this->trails('advance');

            return array_map(function ($a) use ($surrenders, $recovered, $trails) {
                $id = (int) $a['id'];
                $fund = $this->lookups->funds()[$a['fund_id']];

                return [
                    'ref'       => $a['reference'],
                    'holder'    => $a['holder_name'],
                    'kind'      => ucfirst($a['holder_kind']),
                    'role'      => $a['holder_role'] ?? '',
                    'purpose'   => $a['purpose'],
                    'program'   => $this->lookups->programmeName((int) $a['programme_id']),
                    'fund'      => self::FUND_GROUPS[$fund['ledger_group']],
                    'amount'    => self::num($a['amount']),
                    'reqDate'   => self::dmy($a['requested_on']),
                    'dueDate'   => self::dmy($a['due_on']),
                    'dueIn'     => Clock::daysUntil($a['due_on']),
                    'status'    => ucfirst($a['status']),
                    'method'    => self::METHOD_LABELS[$a['payment_method']] ?? '',
                    'issueDate' => self::dmy($a['issued_on'], ''),
                    'accounted' => self::num(array_sum(array_column($surrenders[$id] ?? [], 'amount'))),
                    'receipts'  => $surrenders[$id] ?? [],
                    'recovered' => self::num($recovered[$id] ?? 0),
                    'trail'     => $trails[$id] ?? [],
                    'grant'     => $a['grant_short'] ?? ($fund['restriction'] === 'unrestricted' ? 'Unrestricted — own income' : 'Unassigned'),
                ];
            }, $this->rows(
                'SELECT a.*, g.short_name AS grant_short FROM {advances} a LEFT JOIN {grants} g ON g.id = a.grant_id
                 ORDER BY a.requested_on DESC, a.reference DESC'
            ));
        });
    }

    public function find(string $ref): ?array
    {
        foreach ($this->all() as $a) {
            if ($a['ref'] === $ref) {
                return $a;
            }
        }

        return null;
    }

    public function controlBalance(): float
    {
        return $this->lookups->balance(self::CONTROL_ACCOUNT);
    }

    /**
     * Surrenders an issued advance against receipts. "refund" banks any unspent
     * balance and closes it; "outstanding" leaves the balance owed by the holder.
     *
     * @param list<array{code: string, desc: string, amount: float}> $receipts
     * @return float the balance: positive unspent, negative overspent
     */
    public function surrender(string $ref, array $receipts, string $mode, int $actorId): float
    {
        $advance = $this->row('SELECT * FROM {advances} WHERE reference = ?', [$ref]);
        if ($advance === null) {
            throw new RuleViolation($ref . ' was not found in the advances register.');
        }
        foreach ($receipts as $r) {
            if (!isset($this->lookups->accounts()[$r['code']])) {
                throw new RuleViolation('Account ' . $r['code'] . ' is not in the chart of accounts.');
            }
        }

        $accounted = array_sum(array_column($receipts, 'amount'));
        $balance   = (float) $advance['amount'] - $accounted;

        $this->transaction(function () use ($advance, $receipts, $mode, $actorId, $accounted, $balance) {
            $id  = (int) $advance['id'];
            $now = Clock::timestamp();
            $who = $this->lookups->shortName($actorId);

            // A surrender replaces any receipts captured earlier for the advance.
            $this->db->table('advance_surrenders')->where('advance_id', $id)->delete();
            foreach ($receipts as $r) {
                $this->insert('advance_surrenders', [
                    'advance_id' => $id, 'account_id' => $this->lookups->accounts()[$r['code']]['id'], 'description' => $r['desc'] !== '' ? $r['desc'] : 'Receipt',
                    'amount' => $r['amount'], 'surrendered_on' => Clock::date(), 'created_by' => $actorId, 'created_at' => $now,
                ]);
            }

            if ($balance > 0 && $mode === 'outstanding') {
                $this->audit('advance', $id, $advance['reference'], 'Part surrender of ' . Prototype::fmt($accounted) . ' with receipts · '
                    . Prototype::fmt($balance) . ' still outstanding against the holder', $actorId);

                return;
            }

            $this->db->table('advances')->where('id', $id)->update(['status' => 'surrendered', 'updated_at' => $now]);
            $this->audit('advance', $id, $advance['reference'], 'Surrendered with receipts of ' . Prototype::fmt($accounted) . ' by ' . $who, $actorId);

            if ($balance > 0) {
                $this->insert('advance_recoveries', [
                    'advance_id' => $id, 'method' => 'bank', 'amount' => $balance, 'recovered_on' => Clock::date(),
                    'reference' => 'Unspent balance refunded at surrender', 'created_by' => $actorId, 'created_at' => $now,
                ]);
                $this->audit('advance', $id, $advance['reference'], 'Unspent ' . Prototype::fmt($balance) . ' refunded to bank', $actorId);
            } elseif ($balance < 0) {
                $this->audit('advance', $id, $advance['reference'], 'Overspend of ' . Prototype::fmt(-$balance) . ' reimbursed to holder on the next payment run', $actorId);
            }
        });

        return $balance;
    }
}
