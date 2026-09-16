<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use CodeIgniter\Database\Seeder;

/**
 * Supplier bills, the payment run scheduled bills belong to, and payments.
 *
 * Source: BILLS. The prototype records the tax base ("taxable") with no VAT, so the
 * bill total equals the taxable amount and withholding tax is taken from it.
 */
class PayablesSeeder extends Seeder
{
    private const STATUSES = ['Awaiting approval' => 'pending_approval', 'Approved' => 'approved', 'Scheduled' => 'scheduled',
        'Paid' => 'paid', 'Rejected' => 'rejected'];

    public function run(): void
    {
        $ctx = SeedContext::get();
        $now = $ctx->now();
        $entity = $ctx->entityId();

        $bills = $ctx->data('BILLS');
        $runs  = $this->seedPaymentRuns($ctx, $bills, $now);

        foreach ($bills as $b) {
            $grant    = $ctx->grantId($b['grant']);
            $fund     = $ctx->fundId($b['fund'], $grant, $b['program']);
            $wht      = round($b['taxable'] * $b['whtRate'] / 100, 2);
            $bank     = $this->bankFor($ctx, $b['method']);
            $prepared = $ctx->trailEntry($b['trail'], '/^(Received and coded|Partner report reviewed) by/');
            $decision = $ctx->trailEntry($b['trail'], '/^(Approved|Rejected) by/');
            $order    = $ctx->lookup('bill_orders', $b['no']);

            $id = $ctx->insert('bills', [
                'entity_id' => $entity, 'reference' => $b['no'], 'supplier_id' => $ctx->require('suppliers', $b['supplier']),
                'invoice_date' => $ctx->date($b['invDate']), 'due_date' => $ctx->date($b['dueDate']), 'terms_days' => (int) $b['terms'],
                'subtotal' => $b['taxable'], 'vat' => 0, 'wht_rate_pct' => $b['whtRate'], 'wht_amount' => $wht, 'total' => $b['taxable'],
                'status' => self::STATUSES[$b['status']], 'pay_from_bank_account_id' => $bank, 'payment_run_id' => $runs[$b['no']] ?? null,
                'purchase_order_id' => $order['po'] ?? null, 'goods_received_note_id' => $order['grn'] ?? null,
                // Bills imported from a supplier portal have no preparer.
                'prepared_by' => $ctx->userOrSystem($prepared['who'] ?? null),
                'approved_by' => $ctx->userId($decision['who'] ?? null), 'approved_at' => $decision['when'] ?? null,
                'rejected_reason' => $b['status'] === 'Rejected' ? trim(explode('—', $decision['what'], 2)[1] ?? '') : null,
                'created_at' => $now,
            ]);

            foreach ($b['lines'] as $i => $l) {
                $ctx->insert('bill_lines', [
                    'bill_id' => $id, 'line_no' => $i + 1, 'account_id' => $ctx->accountId($l['code']), 'fund_id' => $fund,
                    'programme_id' => $ctx->programmeId($b['program']), 'grant_id' => $grant, 'description' => $l['desc'], 'amount' => $l['amount'],
                ]);
            }

            $paid = $ctx->trailEntry($b['trail'], '/^Paid by/');
            if ($b['status'] === 'Paid' && $paid !== null) {
                $ctx->insert('payments', [
                    'entity_id' => $entity, 'bill_id' => $id, 'bank_account_id' => $bank, 'paid_on' => substr($paid['when'], 0, 10),
                    'method' => str_starts_with($b['method'], 'M-Pesa') ? 'mpesa' : 'eft', 'amount' => $b['taxable'] - $wht, 'wht_amount' => $wht,
                    'reference' => preg_match('/ref (\S+)/', $paid['what'], $m) === 1 ? $m[1] : null,
                    'created_by' => $ctx->systemUserId(), 'created_at' => $now,
                ]);
            }

            $ctx->writeTrail('bill', $id, $b['no'], $b['trail'], $entity);
        }
    }

    /** One run per "Scheduled in 05 Sep payment run". Returns bill reference → run id. */
    private function seedPaymentRuns(SeedContext $ctx, array $bills, string $now): array
    {
        $byDate = [];
        foreach ($bills as $b) {
            $entry = $ctx->trailEntry($b['trail'], '/^Scheduled in .+ payment run$/');
            if ($entry !== null && preg_match('/^Scheduled in (\d{1,2} [A-Z][a-z]{2}) payment run$/', $entry['what'], $m) === 1) {
                $byDate[$ctx->date($m[1])][] = $b;
            }
        }

        $runs = [];
        foreach ($byDate as $date => $scheduled) {
            $run = $ctx->insert('payment_runs', [
                'entity_id' => $ctx->entityId(), 'reference' => 'RUN-' . $date, 'run_date' => $date,
                'bank_account_id' => $ctx->require('bank_accounts', '1110'),
                'total' => array_sum(array_map(static fn ($b) => $b['taxable'] - round($b['taxable'] * $b['whtRate'] / 100, 2), $scheduled)),
                'status' => 'draft', 'prepared_by' => $ctx->systemUserId(), 'created_at' => $now,
            ]);
            foreach ($scheduled as $b) {
                $runs[$b['no']] = $run;
            }
        }

        return $runs;
    }

    private function bankFor(SeedContext $ctx, string $method): int
    {
        return $ctx->require('bank_accounts', str_starts_with($method, 'M-Pesa') ? '1130' : '1110');
    }
}
