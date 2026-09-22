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
 *
 * Where the bank statements contradict the claims list, the bank wins: the
 * prototype has DANIDA part-paying INV-26-0038 into KCB on 20 Aug, a receipt no
 * KCB statement shows, while the Equity USD statement and cash book carry DANIDA's
 * full 12,400,000 for CE-2025/27 on 2 Aug. The claim is received in full by that
 * voucher, and the ledger seeder links the receipt to it.
 *
 * Runs before the ledger seeder, which posts each claim's issue and receipt.
 */
class ReceivablesSeeder extends Seeder
{
    private const TYPES = ['Grant claim' => 'grant_claim', 'Cost reimbursement' => 'cost_reimbursement', 'Other income' => 'other_income'];

    private const STATUSES = ['Draft' => 'draft', 'Issued' => 'issued', 'Part received' => 'part_received', 'Received' => 'received', 'Written off' => 'written_off'];

    /** invoice => [cash book voucher that settled it, bank account] */
    private const RECEIPTS_FROM_CASH_BOOK = ['INV-26-0038' => ['RC-26-0308', '1120']];

    public function run(): void
    {
        $ctx = SeedContext::get();
        $now = $ctx->now();
        $entity = $ctx->entityId();

        foreach ($ctx->data('AR') as $inv) {
            if (isset(self::RECEIPTS_FROM_CASH_BOOK[$inv['no']])) {
                $inv = $this->receivedByCashBook($inv, ...self::RECEIPTS_FROM_CASH_BOOK[$inv['no']]);
            }
            $isOther  = $inv['type'] === 'Other income';
            $grant    = $ctx->grantId($inv['grantRef']);
            $fund     = $inv['fund'] === 'Grant Fund' && $grant === null && ($byFunder = $ctx->lookup('funder_grant_funds', $inv['donor'])) !== null
                ? $byFunder
                : $ctx->fundId($inv['fund'], $grant, $inv['program']);
            $rate     = (float) ($inv['fx'] ?? 1);
            // The prototype writes claims off to 5340, which the chart holds as bank
            // charges; bad and doubtful debts are 5370.
            $inv['trail'] = array_map(static fn ($t) => ['what' => preg_replace('/^Written off to 5340\b/', 'Written off to 5370', $t['what'])] + $t, $inv['trail']);
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
                    'entity_id' => $entity, 'invoice_id' => $id, 'bank_account_id' => $ctx->require('bank_accounts', $r['bank'] ?? '1110'),
                    'received_on' => $ctx->date($r['when']), 'reference' => $r['ref'], 'currency' => $inv['ccy'], 'fx_rate' => $rate,
                    'amount_fc' => round($r['amount'] / $rate, 2), 'amount' => $r['amount'], 'note' => $r['note'],
                    'created_by' => $ctx->systemUserId(), 'created_at' => $now,
                ]);
            }

            $ctx->writeTrail('invoice', $id, $inv['no'], $inv['trail'], $entity);
        }
    }

    /** The claim received in full by a cash book voucher, in place of the receipts the prototype lists. */
    private function receivedByCashBook(array $inv, string $voucher, string $bank): array
    {
        $ctx = SeedContext::get();
        $line = null;
        foreach ($ctx->data('BR_ACCOUNTS') as $b) {
            foreach ($b['code'] === $bank ? $b['book'] : [] as $l) {
                if ($l['ref'] === $voucher) {
                    $line = $l;
                }
            }
        }
        if ($line === null || round((float) $line['amt'], 2) !== round((float) $inv['amount'], 2)) {
            throw new \RuntimeException("Cash book voucher {$voucher} on {$bank} does not settle {$inv['no']}.");
        }

        $inv['status'] = 'Received';
        $inv['receipts'] = [['when' => $line['date'], 'ref' => $voucher, 'amount' => (float) $line['amt'], 'note' => $line['desc'], 'bank' => $bank]];
        $inv['trail'] = [
            ...array_values(array_filter($inv['trail'], static fn ($t) => preg_match('/^Part receipt|^Receipt/', $t['what']) !== 1)),
            ['when' => $line['date'], 'what' => 'Receipt in full of ' . number_format((float) $line['amt']) . ' posted to ' . $bank . ' as ' . $voucher],
        ];
        usort($inv['trail'], static fn ($a, $b) => $ctx->datetime($a['when']) <=> $ctx->datetime($b['when']));

        return $inv;
    }
}
