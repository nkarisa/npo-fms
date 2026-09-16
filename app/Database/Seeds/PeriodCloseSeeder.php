<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use CodeIgniter\Database\Seeder;

/**
 * The close checklist and the periods the prototype shows as locked, run last so
 * the journals dated in them could be posted first.
 *
 * Locked: every month of FY2021 to FY2025, and January to July 2026. The prototype
 * gives who closed each 2026 month and who approved it (PC_CLOSE_META), and times
 * for the four closes in its close history (PC_LOG); where the two disagree the
 * history, which is the audit record, is used. Earlier months follow the
 * prototype's rule: closed on the 16th of the next month by M. Otieno, approved by
 * D. Kiptoo. Journal detail for locked months is held in an archive; each month
 * keeps the entry count and value the prototype shows (PC_ARCHIVE, and for months
 * it does not list, the same derivation the prototype uses).
 *
 * Nothing is confirmed for August 2026, the month being closed: the prototype
 * starts every confirmation unticked.
 */
class PeriodCloseSeeder extends Seeder
{
    /** key => [label, kind, owner short name, owner title, permission, note once settled] */
    private const CHECKS = [
        'journals'  => ['Every journal in the period is approved and posted', 'ledger', 'M. Otieno', 'Accountant', null, 'All journals in the period approved, posted and locked'],
        'bills'     => ['Supplier bills for the period are captured and approved', 'ledger', 'P. Mwangi', 'Payables', null, 'Every supplier bill captured, approved and settled or accrued'],
        'bank'      => ['Bank accounts reconciled to statement', 'ledger', 'J. Achieng', 'Assistant Accountant', null, 'KCB Current and the Equity USD grant account both agreed to statement'],
        'mpesa'     => ['M-Pesa paybill float reconciled', 'ledger', 'J. Achieng', 'Assistant Accountant', null, 'Paybill 509118 settlement report agreed to the float account'],
        'payroll'   => ['Payroll posted and statutory deductions accrued', 'ledger', 'S. Njeri', 'Payroll', null, 'Payroll posted with PAYE, NSSF, SHIF, the housing levy and NITA raised as liabilities'],
        'accruals'  => ['Accruals and prepayments raised', 'confirmation', 'M. Otieno', 'Accountant', 'journal.prepare', 'Goods and services received but not invoiced accrued; prepayments carried forward'],
        'disposals' => ['Asset disposals approved and posted', 'ledger', 'D. Kiptoo', 'Executive Director', null, 'No disposal left unapproved at close'],
        'depn'      => ['Depreciation charged for the month', 'ledger', 'M. Otieno', 'Accountant', null, 'The monthly charge was posted from the asset register to 5350'],
        'segments'  => ['Every posting carries its fund, grant and restriction', 'ledger', 'W. Kamau', 'Finance Manager', null, 'Fund, grant and restriction carried on every posting in the period'],
        'donor'     => ['Donor reports reconcile to the ledger', 'ledger', 'W. Kamau', 'Finance Manager', null, 'Every donor report tied to the postings behind it'],
        'budget'    => ['Budget variances explained', 'ledger', 'W. Kamau', 'Finance Manager', null, 'Variances explained; no line left over its approved amount'],
        'tb'        => ['Trial balance in balance', 'ledger', null, 'System check', null, 'Debits equalled credits across every active account'],
        'review'    => ['Reviewed by the Finance Manager', 'confirmation', 'W. Kamau', 'Finance Manager', 'period.close', 'Management accounts read against budget and the prior month'],
        'signoff'   => ['Authorised by the Executive Director', 'confirmation', 'D. Kiptoo', 'Executive Director', 'period.authorise', 'Authorised before the period was locked'],
    ];

    /** Who closed each 2026 month, and when (PC_CLOSE_META). */
    private const CLOSE_META = [
        'Jan 2026' => ['2026-02-14 09:00:00', 'M. Otieno'],
        'Feb 2026' => ['2026-03-16 09:00:00', 'M. Otieno'],
        'Mar 2026' => ['2026-04-17 09:00:00', 'S. Njeri'],
        'Apr 2026' => ['2026-05-15 09:00:00', 'M. Otieno'],
        'May 2026' => ['2026-06-16 09:00:00', 'M. Otieno'],
        'Jun 2026' => ['2026-07-17 09:00:00', 'S. Njeri'],
        'Jul 2026' => ['2026-08-19 09:00:00', 'M. Otieno'],
    ];

    /** The close history (PC_LOG): when, who, and what was recorded. */
    private const CLOSE_LOG = [
        'Jul 2026' => ['2026-08-19 11:22:00', 'M. Otieno', 'July 2026 closed to further posting'],
        'Jun 2026' => ['2026-07-17 09:04:00', 'M. Otieno', 'June 2026 closed after the audit sample was drawn'],
        'May 2026' => ['2026-06-12 16:40:00', 'W. Kamau', 'May 2026 reopened to correct a misposted stipend batch, then closed the same day'],
        'Mar 2026' => ['2026-04-14 10:15:00', 'M. Otieno', 'Q1 2026 closed and the quarterly donor pack issued'],
    ];

    /** Entries posted in each locked 2026 month, held in the archive (PC_ARCHIVE). */
    private const ARCHIVE = [
        'Jan 2026' => [68, 41284600], 'Feb 2026' => [74, 38916200], 'Mar 2026' => [91, 52470800], 'Apr 2026' => [83, 46138900],
        'May 2026' => [79, 44025300], 'Jun 2026' => [96, 58712400], 'Jul 2026' => [88, 49863700],
    ];

    private const APPROVER = 'D. Kiptoo';

    private const DEFAULT_CLOSER = 'M. Otieno';

    public function run(): void
    {
        $ctx = SeedContext::get();
        $now = $ctx->now();

        $order = 0;
        foreach (self::CHECKS as $key => [$label, $kind, $owner, $title, $permission, $settled]) {
            $ctx->remember('period_close_checks', $key, $ctx->insert('period_close_checks', [
                'key' => $key, 'sort_order' => ++$order, 'label' => $label, 'kind' => $kind, 'owner_user_id' => $ctx->userId($owner),
                'owner_title' => $title, 'permission' => $permission, 'settled_note' => $settled, 'created_at' => $now,
            ]));
        }

        $approver = $ctx->userId(self::APPROVER);
        for ($year = SeedContext::YEAR - 5; $year <= SeedContext::YEAR; $year++) {
            for ($m = 1; $m <= 12; $m++) {
                $start = sprintf('%d-%02d-01', $year, $m);
                $month = date('M Y', strtotime($start));
                if ($year === SeedContext::YEAR && $m > 7) {
                    break;
                }
                $this->close($ctx, $month, $start, $approver, $now);
            }
            if ($year < SeedContext::YEAR) {
                $ctx->db()->table('fiscal_years')->where('id', $ctx->require('fiscal_years', 'FY' . $year))->update(['status' => 'closed', 'updated_at' => $now]);
            }
        }
    }

    private function close(SeedContext $ctx, string $month, string $start, ?int $approver, string $now): void
    {
        [$at, $by, $summary] = self::CLOSE_LOG[$month]
            ?? [...(self::CLOSE_META[$month] ?? [date('Y-m-16 09:00:00', strtotime('first day of next month', strtotime($start))), self::DEFAULT_CLOSER]), "{$month} closed to further posting"];
        [$journals, $value] = self::ARCHIVE[$month] ?? self::derivedArchive($month);
        $closedBy = $ctx->userOrSystem($by);
        $periodId = $ctx->require('period_names', $month);

        $ctx->db()->table('periods')->where('id', $periodId)->update([
            'status' => 'closed', 'closed_by' => $closedBy, 'closed_at' => $at, 'close_approved_by' => $approver,
            'archived_journals' => $journals, 'archived_value' => $value, 'updated_at' => $now,
        ]);

        $ctx->insert('audit_events', [
            'entity_id' => $ctx->entityId(), 'occurred_at' => $at, 'actor_user_id' => $closedBy, 'approver_user_id' => $approver,
            'action' => 'period.closed', 'object_type' => 'period', 'object_id' => $periodId, 'object_ref' => $month, 'summary' => $summary,
        ]);
    }

    /** The prototype's figures for a locked month it does not list: a hash of the month's name. */
    private static function derivedArchive(string $month): array
    {
        $h = 0;
        foreach (str_split($month) as $char) {
            $h = ($h * 31 + ord($char)) % 9973;
        }

        return [62 + $h % 38, (34 + $h % 27) * 1000000 + ($h % 900) * 1000 + 400];
    }
}
