<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * Every entity keeps its books on the organisation's calendar: the head office's
 * financial years and months, each opened and closed by the entity itself.
 *
 * A journal can only be posted into a period of its own entity (the ledger's
 * triggers check it), so an entity needs its periods before anything is recorded
 * in it. This gives it any of the head office's years and months it lacks, in the
 * state the head office holds them: a month the organisation has already closed
 * is closed for a newcomer too, so nothing can be back-dated into it.
 */
final class EntityCalendar
{
    /** Gives one entity (or, with none named, every entity) the months it lacks. Returns how many periods were added. */
    public static function fill(BaseConnection $db, ?int $entityId = null): int
    {
        $t = static fn (string $table) => $db->prefixTable($table);
        $head = $db->query('SELECT id FROM ' . $t('entities') . ' WHERE parent_id IS NULL ORDER BY id LIMIT 1')->getRowArray();
        if ($head === null) {
            return 0;
        }
        $headId = (int) $head['id'];

        $targets = $entityId !== null ? [$entityId] : array_map('intval', array_column(
            $db->query('SELECT id FROM ' . $t('entities') . ' WHERE id <> ? ORDER BY id', [$headId])->getResultArray(), 'id'
        ));
        $years = $db->query('SELECT * FROM ' . $t('fiscal_years') . ' WHERE entity_id = ? ORDER BY starts_on', [$headId])->getResultArray();
        $periods = $db->query('SELECT * FROM ' . $t('periods') . ' WHERE entity_id = ? ORDER BY starts_on', [$headId])->getResultArray();
        $now = Clock::timestamp();
        $added = 0;

        foreach ($targets as $target) {
            if ($target === $headId) {
                continue;
            }
            $haveYears = array_column($db->query('SELECT id, code FROM ' . $t('fiscal_years') . ' WHERE entity_id = ?', [$target])->getResultArray(), 'id', 'code');
            $havePeriods = array_flip(array_column($db->query('SELECT code FROM ' . $t('periods') . ' WHERE entity_id = ?', [$target])->getResultArray(), 'code'));
            $yearIds = [];

            foreach ($years as $y) {
                if (!isset($haveYears[$y['code']])) {
                    $db->table('fiscal_years')->insert([
                        'entity_id' => $target, 'code' => $y['code'], 'starts_on' => $y['starts_on'], 'ends_on' => $y['ends_on'],
                        'status' => $y['status'], 'created_at' => $now,
                    ]);
                    $haveYears[$y['code']] = (int) $db->insertID();
                }
                $yearIds[(int) $y['id']] = (int) $haveYears[$y['code']];
            }

            foreach ($periods as $p) {
                if (isset($havePeriods[$p['code']])) {
                    continue;
                }
                $db->table('periods')->insert([
                    'entity_id' => $target, 'fiscal_year_id' => $yearIds[(int) $p['fiscal_year_id']], 'code' => $p['code'], 'name' => $p['name'],
                    'starts_on' => $p['starts_on'], 'ends_on' => $p['ends_on'], 'status' => $p['status'],
                    'closed_by' => $p['closed_by'], 'closed_at' => $p['closed_at'], 'close_approved_by' => $p['close_approved_by'],
                    'archived_journals' => 0, 'archived_value' => 0, 'created_at' => $now,
                ]);
                $added++;
            }
        }

        return $added;
    }
}
