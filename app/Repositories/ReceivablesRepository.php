<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Prototype;

/** Donor claims and other invoices, and the receipts against them. */
final class ReceivablesRepository extends Repository
{
    private const TYPE_LABELS = ['grant_claim' => 'Grant claim', 'cost_reimbursement' => 'Cost reimbursement', 'other_income' => 'Other income'];

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
            foreach ($this->rows('SELECT l.*, a.code FROM {invoice_lines} l JOIN {accounts} a ON a.id = l.account_id ORDER BY l.invoice_id, l.line_no') as $l) {
                $lines[(int) $l['invoice_id']][] = ['code' => $l['code'], 'desc' => $l['description'], 'amount' => self::num($l['amount'])];
            }

            $receipts = [];
            foreach ($this->rows('SELECT * FROM {receipts} WHERE invoice_id IS NOT NULL ORDER BY received_on, id') as $r) {
                $receipts[(int) $r['invoice_id']][] = [
                    'when' => self::dm($r['received_on']), 'ref' => $r['reference'], 'amount' => self::num($r['amount']), 'note' => $r['note'] ?? '',
                ];
            }

            $trails = $this->trails('invoice');

            return array_map(function ($i) use ($lines, $receipts, $trails) {
                $id = (int) $i['id'];
                $invoice = [
                    'no'       => $i['reference'],
                    'donor'    => $i['funder_name'] ?? $i['bill_to'],
                    'grantRef' => $i['award_ref'] ?? $i['donor_reference'] ?? '—',
                    'type'     => self::TYPE_LABELS[$i['type']],
                    'program'  => $this->lookups->programmeName((int) $i['programme_id']),
                    'fund'     => self::FUND_GROUPS[$i['ledger_group']],
                    'issue'    => self::dmy($i['issue_date']),
                    'due'      => self::dmy($i['due_date']),
                    'dueIn'    => Clock::daysUntil($i['due_date']),
                    'ccy'      => $i['currency'],
                    'amount'   => self::num($i['amount']),
                    'received' => self::num(array_sum(array_column($receipts[$id] ?? [], 'amount'))),
                    'status'   => self::label($i['status']),
                    'basis'    => $i['basis'] ?? '',
                    'lines'    => $lines[$id] ?? [],
                    'receipts' => $receipts[$id] ?? [],
                    'trail'    => $trails[$id] ?? [],
                ];

                return $i['currency'] === 'KES' ? $invoice : $invoice + ['fx' => self::num($i['fx_rate']), 'amountFc' => self::num($i['amount_fc'])];
            }, $this->rows(
                'SELECT i.*, fu.name AS funder_name, g.award_ref, f.ledger_group
                 FROM {invoices} i LEFT JOIN {funders} fu ON fu.id = i.funder_id LEFT JOIN {grants} g ON g.id = i.grant_id
                 JOIN {funds} f ON f.id = i.fund_id ORDER BY i.issue_date DESC, i.reference DESC'
            ));
        });
    }

    public function find(string $no): ?array
    {
        foreach ($this->all() as $i) {
            if ($i['no'] === $no) {
                return $i;
            }
        }

        return null;
    }

    /** Banks a receipt against a claim and moves it to part received or received. */
    public function recordReceipt(string $no, float $amount, string $ref, string $note, string $accountCode, int $actorId): array
    {
        $invoice = $this->row('SELECT i.*, f.name AS fund_name FROM {invoices} i JOIN {funds} f ON f.id = i.fund_id WHERE i.reference = ?', [$no]);
        $bank    = $this->lookups->bankAccounts()[$accountCode] ?? null;
        if ($invoice === null) {
            throw new RuleViolation($no . ' was not found in receivables.');
        }
        if ($bank === null) {
            throw new RuleViolation($accountCode . ' is not a bank or mobile-money account.');
        }

        $this->transaction(function () use ($invoice, $amount, $ref, $note, $bank, $actorId, $accountCode) {
            $rate = (float) $invoice['fx_rate'];
            $this->insert('receipts', [
                'entity_id' => $invoice['entity_id'], 'invoice_id' => $invoice['id'], 'bank_account_id' => $bank['id'],
                'received_on' => Clock::date(), 'reference' => $ref, 'currency' => $invoice['currency'], 'fx_rate' => $rate,
                'amount_fc' => round($amount / $rate, 2), 'amount' => $amount, 'note' => $note !== '' ? $note : 'Receipt posted',
                'created_by' => $actorId, 'created_at' => Clock::timestamp(),
            ]);

            $received = (float) $this->value('SELECT COALESCE(SUM(amount), 0) FROM {receipts} WHERE invoice_id = ?', [$invoice['id']]);
            $this->db->table('invoices')->where('id', $invoice['id'])->update([
                'status' => $received >= (float) $invoice['amount'] ? 'received' : 'part_received', 'updated_at' => Clock::timestamp(),
            ]);

            $this->audit('invoice', (int) $invoice['id'], $invoice['reference'],
                'Receipt of ' . Prototype::fmt($amount) . ' posted to ' . $accountCode . ' against ' . self::FUND_GROUPS[$this->lookups->funds()[$invoice['fund_id']]['ledger_group']], $actorId);
        });

        return $this->find($no);
    }
}
