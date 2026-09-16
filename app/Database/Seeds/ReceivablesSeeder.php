<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use CodeIgniter\Database\Seeder;

/**
 * Donor claims and other invoices, and receipts against them.
 *
 * Source: AR. A claim to a donor is billed to the funder; "Other income" invoices
 * (a county government, a former tenant) are billed to the name on the invoice.
 * A grant claim whose award reference is not in the grant register keeps its
 * funder but no grant, and is coded to that funder's grant fund.
 */
class ReceivablesSeeder extends Seeder
{
    private const TYPES = ['Grant claim' => 'grant_claim', 'Cost reimbursement' => 'cost_reimbursement', 'Other income' => 'other_income'];

    private const STATUSES = ['Draft' => 'draft', 'Issued' => 'issued', 'Part received' => 'part_received', 'Received' => 'received', 'Written off' => 'written_off'];

    public function run(): void
    {
        $ctx = SeedContext::get();
        $now = $ctx->now();
        $entity = $ctx->entityId();

        foreach ($ctx->data('AR') as $inv) {
            $isOther  = $inv['type'] === 'Other income';
            $grant    = $ctx->grantId($inv['grantRef']);
            $fund     = $inv['fund'] === 'Grant Fund' && $grant === null && ($byFunder = $ctx->lookup('funder_grant_funds', $inv['donor'])) !== null
                ? $byFunder
                : $ctx->fundId($inv['fund'], $grant, $inv['program']);
            $rate     = (float) ($inv['fx'] ?? 1);
            $prepared = $ctx->trailEntry($inv['trail'], '/^(Draft raised|Claim assembled|Claim prepared|Claim issued|Invoice issued)/');
            $issued   = $ctx->trailEntry($inv['trail'], '/^Issued to donor by/');
            $writeOff = $ctx->trailEntry($inv['trail'], '/^Written off/');

            $id = $ctx->insert('invoices', [
                'entity_id' => $entity, 'reference' => $inv['no'], 'type' => self::TYPES[$inv['type']],
                'funder_id' => $isOther ? null : $ctx->require('funders', $inv['donor']), 'bill_to' => $isOther ? $inv['donor'] : null,
                'grant_id' => $grant, 'donor_reference' => $grant === null && $inv['grantRef'] !== '—' ? $inv['grantRef'] : null,
                'programme_id' => $ctx->programmeId($inv['program']), 'fund_id' => $fund,
                'issue_date' => $ctx->date($inv['issue']), 'due_date' => $ctx->date($inv['due']), 'currency' => $inv['ccy'], 'fx_rate' => $rate,
                'amount_fc' => $inv['amountFc'] ?? $inv['amount'], 'amount' => $inv['amount'], 'status' => self::STATUSES[$inv['status']],
                'basis' => $inv['basis'], 'prepared_by' => $ctx->userOrSystem($prepared['who'] ?? null),
                'approved_by' => $ctx->userId($issued['who'] ?? null), 'written_off_reason' => $writeOff['what'] ?? null, 'created_at' => $now,
            ]);

            // Line amounts are in KES; the document-currency split puts rounding on the last line.
            $fcTotal = (float) ($inv['amountFc'] ?? $inv['amount']);
            $fcSoFar = 0.0;
            foreach ($inv['lines'] as $i => $l) {
                $fc = $i === array_key_last($inv['lines']) ? round($fcTotal - $fcSoFar, 2) : round($l['amount'] / $rate, 2);
                $fcSoFar += $fc;
                $ctx->insert('invoice_lines', [
                    'invoice_id' => $id, 'line_no' => $i + 1, 'account_id' => $ctx->accountId($l['code']), 'description' => $l['desc'],
                    'amount_fc' => $fc, 'amount' => $l['amount'],
                ]);
            }

            foreach ($inv['receipts'] as $r) {
                $ctx->insert('receipts', [
                    'entity_id' => $entity, 'invoice_id' => $id, 'bank_account_id' => $ctx->require('bank_accounts', '1110'),
                    'received_on' => $ctx->date($r['when']), 'reference' => $r['ref'], 'currency' => $inv['ccy'], 'fx_rate' => $rate,
                    'amount_fc' => round($r['amount'] / $rate, 2), 'amount' => $r['amount'], 'note' => $r['note'],
                    'created_by' => $ctx->systemUserId(), 'created_at' => $now,
                ]);
            }

            $ctx->writeTrail('invoice', $id, $inv['no'], $inv['trail'], $entity);
        }
    }
}
