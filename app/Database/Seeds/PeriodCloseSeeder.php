<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use CodeIgniter\Database\Seeder;

/**
 * Closes the periods the prototype shows as closed (January to July 2026), last, so the
 * journals dated in them could be posted first.
 *
 * July's close is in the settings audit log ("July 2026 period closed…", with who
 * and when). The prototype does not record who closed January to June or when;
 * those are attributed to the data-migration user and dated the first day of the
 * following month. The close checklist steps are not seeded: the prototype holds
 * no evidence for them.
 */
class PeriodCloseSeeder extends Seeder
{
    private const CLOSED = ['Jan 2026', 'Feb 2026', 'Mar 2026', 'Apr 2026', 'May 2026', 'Jun 2026', 'Jul 2026'];

    public function run(): void
    {
        $ctx = SeedContext::get();

        $logged = [];
        foreach ($ctx->data('ST_AUDIT') as $e) {
            if (preg_match('/^(\w+ \d{4}) period closed/', $e['what'], $m) === 1) {
                $logged[date('M Y', strtotime('1 ' . $m[1]))] = ['by' => $ctx->userId($e['who']), 'at' => $ctx->datetime($e['when'])];
            }
        }

        foreach (self::CLOSED as $month) {
            $closedAt = $logged[$month]['at'] ?? date('Y-m-d 00:00:00', strtotime('first day of next month', strtotime('1 ' . $month)));
            $closedBy = $logged[$month]['by'] ?? $ctx->systemUserId();

            $ctx->db()->table('periods')->where('id', $ctx->require('period_names', $month))->update([
                'status' => 'closed', 'closed_by' => $closedBy, 'closed_at' => $closedAt, 'updated_at' => $ctx->now(),
            ]);

            if (!isset($logged[$month])) {
                $ctx->insert('audit_events', [
                    'entity_id' => $ctx->entityId(), 'occurred_at' => $closedAt, 'actor_user_id' => $closedBy, 'action' => 'period.closed',
                    'object_type' => 'period', 'object_id' => $ctx->require('period_names', $month), 'object_ref' => $month,
                    'summary' => "{$month} period closed (loaded from the prototype, which does not record who closed it)",
                ]);
            }
        }
    }
}
