<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use CodeIgniter\Database\Seeder;

/**
 * Donor reports: the detailed reports in DREPORTS, and the reporting schedule each
 * grant carries in GRANTS for reports not yet prepared or submitted earlier.
 *
 * A schedule entry is the same report as a DREPORTS entry when both are for the
 * same grant and due date. Schedule-only reports have no reference in the
 * prototype and get one from their award ("USAID/URAIA/2026/R1"); those shown as
 * submitted are dated as submitted on their due date, the latest they could have
 * been. Attachment names are not seeded: there are no files behind them.
 */
class DonorReportSeeder extends Seeder
{
    private const STATUSES = ['Draft' => 'draft', 'In review' => 'in_review', 'Overdue' => 'draft', 'Submitted' => 'submitted',
        'Queried' => 'queried', 'Accepted' => 'accepted'];

    private const SUBMITTED = ['submitted', 'queried', 'accepted'];

    public function run(): void
    {
        $ctx = SeedContext::get();
        $now = $ctx->now();
        $entity = $ctx->entityId();
        $covered = [];

        foreach ($ctx->data('DREPORTS') as $r) {
            [$start, $end] = $ctx->periodRange($r['period']);
            $status    = self::STATUSES[$r['status']];
            $review    = $this->trailMatch($ctx, $r['trail'], '/^(?:Sent for review to|Approved by) ([A-Z]\. [A-Z][a-z]+)/');
            $submitted = $ctx->trailEntry($r['trail'], '/^Submitted/');
            $accepted  = $ctx->trailEntry($r['trail'], '/^Accepted/');
            $grant     = $ctx->require('grants', $r['grantRef']);

            $id = $ctx->insert('donor_reports', [
                'entity_id' => $entity, 'reference' => $r['ref'], 'grant_id' => $grant, 'title' => $r['title'],
                'type' => str_replace('-', '_', strtolower($r['type'])), 'period_starts_on' => $start, 'period_ends_on' => $end,
                'due_on' => $ctx->date($r['due']), 'status' => $status, 'funds_received' => $r['received'],
                'reported_adjustment' => $r['reportedAdj'], 'note' => $r['note'] !== '' ? $r['note'] : null,
                'prepared_by' => $ctx->userOrSystem($r['preparer']), 'reviewed_by' => $ctx->userId($review),
                'submitted_at' => $submitted['when'] ?? null, 'accepted_at' => $accepted['when'] ?? null, 'created_at' => $now,
            ]);
            $covered[$grant . '|' . $ctx->date($r['due'])] = true;

            foreach ($r['lines'] as $l) {
                $ctx->insert('donor_report_lines', [
                    'donor_report_id' => $id, 'account_id' => $ctx->accountId($l['code']), 'budget' => $l['budget'],
                    'period_amount' => $l['period'], 'cumulative_amount' => $l['cumulative'],
                ]);
            }

            foreach ($r['compliance'] as $i => $text) {
                $ctx->insert('donor_report_checks', [
                    'donor_report_id' => $id, 'sort_order' => $i + 1, 'text' => $text, 'is_confirmed' => (int) in_array($status, self::SUBMITTED, true),
                ]);
            }

            foreach ($r['queries'] as $q) {
                $ctx->insert('donor_report_queries', [
                    'donor_report_id' => $id, 'reference' => $q['ref'], 'raised_on' => $ctx->date($q['when']), 'text' => $q['text'],
                    'response' => $q['response'] !== '' ? $q['response'] : null, 'created_at' => $now,
                ]);
            }

            $ctx->writeTrail('donor_report', $id, $r['ref'], $r['trail'], $entity);
        }

        $this->seedSchedules($ctx, $covered, $entity, $now);
    }

    private function seedSchedules(SeedContext $ctx, array $covered, int $entity, string $now): void
    {
        foreach ($ctx->data('GRANTS') as $g) {
            $grant = $ctx->require('grants', $g['ref']);
            foreach ($g['reports'] as $i => $s) {
                $due = $ctx->date($s['due']);
                if (isset($covered[$grant . '|' . $due])) {
                    continue;
                }

                $range = $ctx->periodRange($s['period']) ?? [$ctx->date($g['start']), $ctx->date($g['end'])];
                $status = $s['status'] === 'Submitted' ? 'submitted' : 'draft';
                $type = match (true) {
                    stripos($s['name'], 'close-out') !== false => 'close_out',
                    stripos($s['name'], 'narrative') !== false => 'narrative',
                    default                                    => 'financial',
                };

                $ctx->insert('donor_reports', [
                    'entity_id' => $entity, 'reference' => $g['ref'] . '/R' . ($i + 1), 'grant_id' => $grant, 'title' => $s['name'],
                    'type' => $type, 'period_starts_on' => $range[0], 'period_ends_on' => $range[1], 'due_on' => $due, 'status' => $status,
                    'prepared_by' => $ctx->userOrSystem($g['manager']), 'submitted_at' => $status === 'submitted' ? "{$due} 00:00:00" : null,
                    'created_at' => $now,
                ]);
            }
        }
    }

    private function trailMatch(SeedContext $ctx, array $trail, string $pattern): ?string
    {
        foreach ($trail as $entry) {
            if (preg_match($pattern, $entry['what'], $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }
}
