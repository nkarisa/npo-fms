<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use CodeIgniter\Database\Seeder;

/**
 * The recurring journal templates (the prototype's RECURRING): depreciation, the
 * support cost allocation, the quarterly rent accrual and the paused audit fee
 * accrual.
 *
 * A run whose journal is in the ledger links to it and reads its status from
 * there; the other runs predate the ledger's migration and keep the status the
 * prototype records for them.
 */
class RecurringTemplateSeeder extends Seeder
{
    private const TEMPLATES = [
        ['code' => 'RT-01', 'name' => 'Monthly depreciation charge', 'type' => 'Recurring', 'frequency' => 'Monthly', 'rule' => 'Last day of the month',
            'next' => '30 Sep 2026', 'last' => '31 Aug 2026', 'owner' => 'M. Otieno', 'status' => 'Active', 'autoSubmit' => true, 'doc' => 'ELOG/AC/RT-01',
            'narration' => 'Monthly depreciation charge',
            'memo' => 'Straight-line charge generated from the asset register. Amount is refreshed from the register at each run.',
            'lines' => [['5350', 'Depreciation charge', 'Capital Fund', 'Shared services', 364000, 0], ['1390', 'Accumulated depreciation', 'Capital Fund', 'Shared services', 0, 364000]],
            'runs' => [['31 Aug 2026', 'AC-26-0308', 'Posted'], ['31 Jul 2026', 'AC-26-0290', 'Posted'], ['22 Jun 2026', 'AC-26-0255', 'Posted']]],
        ['code' => 'RT-02', 'name' => 'Support cost allocation to programmes', 'type' => 'Allocation', 'frequency' => 'Monthly', 'rule' => 'Last day of the month',
            'next' => '30 Sep 2026', 'last' => '18 Aug 2026', 'owner' => 'M. Otieno', 'status' => 'Active', 'autoSubmit' => true, 'doc' => 'ELOG/AL/RT-02',
            'narration' => 'Support cost allocation to programmes',
            'memo' => 'Shared support costs spread on the approved headcount basis. Percentages are held in the cost allocation methodology.',
            'lines' => [['5310', 'Rent allocated to observation', 'Grant Fund', 'Election Observation', 320000, 0], ['5310', 'Rent allocated to civic education', 'Grant Fund', 'Civic Education', 190000, 0], ['5310', 'Rent reversal from shared pool', 'General Fund', 'Shared services', 0, 510000]],
            'runs' => [['18 Aug 2026', 'JV-26-0310', 'Pending approval'], ['31 Jul 2026', 'JV-26-0288', 'Posted']]],
        ['code' => 'RT-03', 'name' => 'Office rent and service charge accrual', 'type' => 'Accrual', 'frequency' => 'Quarterly', 'rule' => 'First day of the quarter',
            'next' => '01 Oct 2026', 'last' => '01 Jul 2026', 'owner' => 'S. Njeri', 'status' => 'Active', 'autoSubmit' => false, 'doc' => 'ELOG/AC/RT-03',
            'narration' => 'Quarterly office rent and service charge accrual',
            'memo' => "Raised on the lease schedule ahead of the landlord's invoice; reversed when the invoice is captured in payables.",
            'lines' => [['5310', 'Secretariat office rent — quarter', 'General Fund', 'Shared services', 1380000, 0], ['5310', 'Service charge and parking', 'General Fund', 'Shared services', 180000, 0], ['2120', 'Accrued expenses', 'General Fund', 'Shared services', 0, 1560000]],
            'runs' => [['01 Jul 2026', 'AC-26-0271', 'Posted'], ['01 Apr 2026', 'AC-26-0202', 'Posted']]],
        ['code' => 'RT-04', 'name' => 'External audit fee accrual', 'type' => 'Accrual', 'frequency' => 'Monthly', 'rule' => '25th of the month',
            'next' => '25 Sep 2026', 'last' => '19 Aug 2026', 'owner' => 'J. Achieng', 'status' => 'Paused', 'autoSubmit' => false, 'doc' => 'ELOG/AC/RT-04',
            'narration' => 'Audit fee accrual — monthly instalment',
            'memo' => 'Paused while the audit engagement letter for 2026/27 is renegotiated. Resume once the fee is agreed.',
            'lines' => [['5330', 'Audit fee accrual', 'General Fund', 'Shared services', 260000, 0], ['2120', 'Accrued expenses', 'General Fund', 'Shared services', 0, 260000]],
            'runs' => [['19 Aug 2026', 'JV-26-0311', 'Draft'], ['25 Jul 2026', 'AC-26-0284', 'Posted']]],
    ];

    public function run(): void
    {
        $ctx = SeedContext::get();
        $db  = $ctx->db();
        $chart = array_column($ctx->data('SEED'), 'type', 'code');

        foreach (self::TEMPLATES as $t) {
            $id = $ctx->insert('recurring_templates', [
                'entity_id' => $ctx->entityId(), 'code' => $t['code'], 'name' => $t['name'], 'type' => strtolower($t['type']),
                'frequency' => strtolower($t['frequency']), 'rule' => $t['rule'], 'next_due' => $ctx->date($t['next']),
                'last_run_on' => $ctx->date($t['last']), 'owner_user_id' => $ctx->userOrSystem($t['owner']),
                'status' => strtolower($t['status']), 'auto_submit' => $t['autoSubmit'] ? 1 : 0, 'document_ref' => $t['doc'],
                'narration' => $t['narration'], 'memo' => $t['memo'], 'created_at' => $ctx->now(),
            ]);

            foreach ($t['lines'] as $i => [$code, $desc, $fund, $programme, $debit, $credit]) {
                // Income and expenditure lines name their funder in the chart; the grant follows the fund.
                $fundId = $ctx->fundId($fund, null, $programme, $code, in_array($chart[$code] ?? '', ['Income', 'Expense'], true));
                $ctx->insert('recurring_template_lines', [
                    'template_id' => $id, 'line_no' => $i + 1, 'account_id' => $ctx->accountId($code), 'fund_id' => $fundId,
                    'programme_id' => $ctx->programmeId($programme), 'grant_id' => $ctx->grantOfFund($fundId),
                    'description' => $desc, 'debit' => $debit, 'credit' => $credit,
                ]);
            }

            foreach ($t['runs'] as [$when, $reference, $status]) {
                // The prototype reuses JV-26-0288 for a cash book transfer; a run is never a cash book voucher.
                $journal = $db->table('journals')->select('id')->where('reference', $reference)
                    ->groupStart()->where('source_type', null)->orWhereNotIn('source_type', ['archive', 'cash_book'])->groupEnd()
                    ->get()->getRowArray();
                $ctx->insert('recurring_template_runs', [
                    'template_id' => $id, 'run_on' => $ctx->date($when), 'journal_id' => $journal['id'] ?? null, 'reference' => $reference,
                    'status' => $journal === null ? strtolower(str_replace(' ', '_', $status)) : null,
                ]);
            }
        }
    }
}
