<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Prototype;

/**
 * How assets come onto the register.
 *
 * - Bought: capitalised from the purchase already in the ledger — a posted debit to
 *   a class cost account (1310, 1320) that is not yet on the register. The
 *   purchase carries the entry, so nothing posts: the asset takes its cost, date,
 *   fund, programme, grant and document from the line. One line can become several
 *   assets, and part of a line can be capitalised and the rest left waiting.
 * - Not bought: donated in kind, or found in a count but never recorded. Proposed
 *   with its value, source and reason, and posted when a second person approves:
 *   Dr the class cost account, Cr 4260 donated assets or 3100 unrestricted fund
 *   balance. A proposal whose value matches a purchase waiting on the same account
 *   is refused: that asset is capitalised from its purchase.
 *
 * Depreciation starts the month after the asset is acquired, as for every asset.
 */
final class AssetAdditionRepository extends Repository
{
    public const BASES = ['donation' => 'Donated in kind', 'found' => 'Found in a count, never recorded'];



    /** Journals that put cost on 1310/1320 without being a purchase: the balances brought forward, their history, and the register's own entries. */
    private const NOT_PURCHASES = ['fiscal_year', 'archive', 'asset_disposal', 'asset_addition', 'depreciation_run'];

    private Lookups $lookups;

    private AssetRepository $assets;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
        $this->assets = new AssetRepository();
    }

    /** @return array<string, array> asset classes by name, with their cost account */
    public function classes(): array
    {
        return $this->cached('classes', fn () => array_column($this->rows(
            'SELECT c.*, a.code AS cost_code, a.name AS cost_name FROM {asset_classes} c JOIN {accounts} a ON a.id = c.cost_account_id ORDER BY c.name'
        ), null, 'name'));
    }

    /** @return list<string> location names for the working entity */
    public function locations(): array
    {
        return array_column($this->rows('SELECT name FROM {locations} WHERE entity_id = ? ORDER BY name', [$this->lookups->entityId()]), 'name');
    }

    // ---- Capitalised from a purchase ----

    /**
     * Posted purchases on a class cost account with cost not yet on the register.
     *
     * @return list<array{line: int, ref: string, date: string, dateLabel: string, account: string, accountName: string, desc: string, doc: string, narration: string, amount: float, capitalised: float, remaining: float, fundId: int, programmeId: ?int, grantId: ?int}>
     */
    public function awaiting(): array
    {
        return $this->cached('awaiting', function () {
            $out = [];
            foreach ($this->rows(
                "SELECT l.id, l.debit, l.description, l.fund_id, l.programme_id, l.grant_id, a.code, a.name AS account_name,
                        j.reference, j.journal_date, j.document_ref, j.narration,
                        (SELECT COALESCE(SUM(s.cost), 0) FROM {assets} s WHERE s.acquisition_journal_line_id = l.id) AS capitalised
                 FROM {journal_lines} l JOIN {journals} j ON j.id = l.journal_id JOIN {accounts} a ON a.id = l.account_id
                 WHERE j.entity_id = ? AND j.status = 'posted' AND l.debit > 0 AND j.reverses_journal_id IS NULL
                   AND a.id IN (SELECT cost_account_id FROM {asset_classes})
                   AND (j.source_type IS NULL OR j.source_type NOT IN ('" . implode("', '", self::NOT_PURCHASES) . "'))
                 ORDER BY j.journal_date, l.id",
                [$this->lookups->entityId()]
            ) as $r) {
                $remaining = round((float) $r['debit'] - (float) $r['capitalised'], 2);
                if ($remaining <= 0) {
                    continue;
                }
                $out[] = [
                    'line' => (int) $r['id'], 'ref' => $r['reference'], 'date' => $r['journal_date'], 'dateLabel' => self::dmy($r['journal_date']),
                    'account' => $r['code'], 'accountName' => $r['account_name'], 'desc' => (string) $r['description'],
                    'doc' => (string) ($r['document_ref'] ?? ''), 'narration' => (string) $r['narration'],
                    'amount' => (float) $r['debit'], 'capitalised' => (float) $r['capitalised'], 'remaining' => $remaining,
                    'fundId' => (int) $r['fund_id'], 'programmeId' => $r['programme_id'] === null ? null : (int) $r['programme_id'],
                    'grantId' => $r['grant_id'] === null ? null : (int) $r['grant_id'],
                ];
            }

            return $out;
        });
    }

    /**
     * Puts part or all of a purchase on the register as one asset or several of the
     * same kind. Posts nothing: the purchase is already in the ledger.
     *
     * @param array{class: string, description: string, units: int, amount: float, life: int, location: string, custodian: string, title: string, serial: string} $f
     * @return list<string> the tags issued
     */
    public function capitalise(int $lineId, array $f, int $actorId): array
    {
        $line = current(array_filter($this->awaiting(), static fn ($l) => $l['line'] === $lineId))
            ?: throw new RuleViolation('That purchase is not waiting to be capitalised — it may already be on the register.');
        $class = $this->classes()[$f['class']] ?? throw new RuleViolation('Choose the asset class.');
        $amount = round($f['amount'], 2);

        $block = match (true) {
            $class['cost_code'] !== $line['account'] => $f['class'] . ' is carried on ' . $class['cost_code'] . ', but the purchase was charged to ' . $line['account'] . '. Choose a class on ' . $line['account'] . ', or correct the purchase.',
            trim($f['description']) === ''           => 'Describe the asset as it should appear on the register.',
            $f['units'] < 1 || $f['units'] > 500     => 'Enter how many assets to tag, between 1 and 500.',
            $amount <= 0                             => 'Enter the cost to capitalise.',
            $amount > $line['remaining']             => 'Only ' . Prototype::fmt($line['remaining']) . ' of ' . $line['ref'] . ' is left to capitalise.',
            $f['life'] < 1 || $f['life'] > 50        => 'Enter a useful life of 1 to 50 years.',
            trim($f['location']) === ''              => 'Record where the asset is kept.',
            default                                  => null,
        };
        if ($block !== null) {
            throw new RuleViolation($block);
        }

        $who = $this->lookups->shortName($actorId);
        $journalId = (int) $this->value('SELECT journal_id FROM {journal_lines} WHERE id = ?', [$lineId]);

        return $this->transaction(function () use ($line, $class, $f, $amount, $who, $actorId, $journalId, $lineId) {
            $now = Clock::timestamp();
            $location = $this->location(trim($f['location']));
            $each = floor($amount / $f['units'] * 100) / 100;
            $tags = $this->nextTags($class['tag_prefix'], $f['units']);
            foreach ($tags as $i => $tag) {
                $cost = $i === $f['units'] - 1 ? round($amount - $each * ($f['units'] - 1), 2) : $each;
                $id = $this->insert('assets', [
                    'entity_id' => $this->lookups->entityId(), 'tag' => $tag, 'description' => trim($f['description']),
                    'asset_class_id' => $class['id'], 'serial_no' => trim($f['serial']) !== '' ? trim($f['serial']) : null,
                    'acquired_on' => $line['date'], 'cost' => $cost, 'useful_life_years' => $f['life'],
                    'fund_id' => $line['fundId'], 'programme_id' => $line['programmeId'], 'grant_id' => $line['grantId'],
                    'title_condition' => trim($f['title']) !== '' ? trim($f['title']) : null, 'location_id' => $location,
                    'custodian' => trim($f['custodian']) !== '' ? trim($f['custodian']) : null, 'custodian_user_id' => $this->lookups->userId(trim($f['custodian'])),
                    'status' => 'in_use', 'capitalised' => 1, 'document_ref' => $line['doc'] !== '' ? $line['doc'] : $line['ref'],
                    'acquisition_journal_id' => $journalId, 'acquisition_journal_line_id' => $lineId, 'created_at' => $now,
                ]);
                $this->audit('asset', $id, $tag, 'Capitalised from ' . $line['ref'] . ' at ' . Prototype::fmt($cost) . ' by ' . $who
                    . ($f['units'] > 1 ? ', ' . $f['units'] . ' units tagged ' . $tags[0] . ' to ' . end($tags) : ''), $actorId);
            }

            return $tags;
        });
    }

    // ---- Donated, or found and never recorded ----

    /** Assets loaded but never capitalised (found in a count), still held, with any proposal. */
    public function uncapitalised(): array
    {
        $additions = $this->additions();

        return array_values(array_filter(
            $this->assets->all(),
            static fn ($a) => !$a['capitalised'] && $a['status'] !== 'Disposed' && !isset($additions[$a['tag']])
        ));
    }

    /** Proposals by tag, pending or posted. */
    public function additions(): array
    {
        return $this->cached('additions', function () {
            $out = [];
            foreach ($this->rows(
                'SELECT d.*, a.tag, a.description, ca.code AS credit_code, j.reference AS journal_ref
                 FROM {asset_additions} d JOIN {assets} a ON a.id = d.asset_id JOIN {accounts} ca ON ca.id = d.credit_account_id
                 LEFT JOIN {journals} j ON j.id = d.journal_id WHERE d.entity_id = ? ORDER BY d.id',
                [$this->lookups->entityId()]
            ) as $d) {
                $out[$d['tag']] = $d;
            }

            return $out;
        });
    }

    /**
     * Why an addition cannot be proposed as it stands, or null.
     *
     * @param array{basis: string, tag: string, class: string, description: string, amount: float, date: string, source: string, reference: string, reason: string, life: int, location: string} $f
     */
    public function block(array $f): ?string
    {
        $found = $f['basis'] === 'found';
        $asset = $found ? (current(array_filter($this->uncapitalised(), static fn ($a) => $a['tag'] === $f['tag'])) ?: null) : null;
        $class = $found ? ($asset === null ? null : $this->classes()[$asset['cls']] ?? null) : ($this->classes()[$f['class']] ?? null);
        $period = $this->assets->periodOf($f['date']);
        $amount = round($f['amount'], 2);
        $purchase = $class === null ? null : (current(array_filter(
            $this->awaiting(),
            static fn ($l) => $l['account'] === $class['cost_code'] && round($l['remaining'], 2) == $amount
        )) ?: null);

        return match (true) {
            !isset(self::BASES[$f['basis']])                 => 'Say whether the asset was donated or found in a count.',
            $found && $asset === null                        => 'Choose an asset found in the count that is not yet on the register.',
            !$found && $class === null                       => 'Choose the asset class.',
            !$found && trim($f['description']) === ''        => 'Describe the asset as it should appear on the register.',
            $amount <= 0                                     => $found ? 'Enter the deemed cost the asset comes onto the register at.' : 'Enter the fair value of the donated asset.',
            $purchase !== null                               => $purchase['ref'] . ' put ' . Prototype::fmt($amount) . ' on ' . $class['cost_code'] . ' and is waiting to be capitalised. An asset that was bought is capitalised from its purchase, not added here.',
            trim($f['source']) === ''                        => $found ? 'Name the count that found the asset.' : 'Name the donor.',
            !$found && trim($f['reference']) === ''          => 'Reference the deed of gift or the donor’s letter.',
            $period === null                                 => 'Enter the date as a date in the financial year.',
            $period['status'] === 'closed'                   => $period['name'] . ' is closed to posting. Date the addition in an open period.',
            !$found && ($f['life'] < 1 || $f['life'] > 50)   => 'Enter a useful life of 1 to 50 years.',
            !$found && trim($f['location']) === ''           => 'Record where the asset is kept.',
            trim($f['reason']) === ''                        => $found ? 'Record why the asset was never recorded and how its value was set.' : 'Record what was donated and how its fair value was set.',
            default                                          => null,
        };
    }

    /** Proposes a donated or found asset. Nothing reaches the register or the ledger until it is approved. */
    public function propose(array $f, int $actorId): string
    {
        $block = $this->block($f);
        if ($block !== null) {
            throw new RuleViolation($block);
        }
        $found = $f['basis'] === 'found';
        $who = $this->lookups->shortName($actorId);
        $credit = $this->lookups->accounts()[$found ? PostingAccounts::of('foundAssets') : PostingAccounts::of('donatedAssets')];
        // Recommended: the donor's letter, the valuation or a photo of what was found.
        $attachments = new AttachmentRepository($this->db);
        $documents = $attachments->pending($f['documents'] ?? [], $actorId);

        return $this->transaction(function () use ($f, $found, $who, $credit, $actorId, $attachments, $documents) {
            $now = Clock::timestamp();
            if ($found) {
                $asset = current(array_filter($this->uncapitalised(), static fn ($a) => $a['tag'] === $f['tag']));
                [$assetId, $tag] = [$asset['id'], $asset['tag']];
            } else {
                $class = $this->classes()[$f['class']];
                $tag = $this->nextTags($class['tag_prefix'], 1)[0];
                $capital = (int) $this->value("SELECT id FROM {funds} WHERE ledger_group = 'capital' ORDER BY code LIMIT 1");
                $assetId = $this->insert('assets', [
                    'entity_id' => $this->lookups->entityId(), 'tag' => $tag, 'description' => trim($f['description']), 'asset_class_id' => $class['id'],
                    'acquired_on' => $f['date'], 'cost' => round($f['amount'], 2), 'useful_life_years' => $f['life'],
                    'fund_id' => $capital, 'programme_id' => $this->lookups->programmeId('Shared services'),
                    'title_condition' => trim($f['title'] ?? '') !== '' ? trim($f['title']) : 'ELOG holds title — donated in kind',
                    'location_id' => $this->location(trim($f['location'])),
                    'custodian' => trim($f['custodian'] ?? '') !== '' ? trim($f['custodian']) : null,
                    'custodian_user_id' => $this->lookups->userId(trim($f['custodian'] ?? '')),
                    'status' => 'in_use', 'capitalised' => 0, 'document_ref' => trim($f['reference']), 'created_at' => $now,
                ]);
            }
            $this->insert('asset_additions', [
                'entity_id' => $this->lookups->entityId(), 'asset_id' => $assetId, 'basis' => $f['basis'], 'amount' => round($f['amount'], 2),
                'recognised_on' => $f['date'], 'source' => trim($f['source']), 'reference' => trim($f['reference']) !== '' ? trim($f['reference']) : null,
                'reason' => trim($f['reason']), 'credit_account_id' => $credit['id'], 'status' => 'pending_approval', 'requested_by' => $actorId, 'created_at' => $now,
            ]);
            $attachments->claim($documents, 'asset', (int) $assetId);
            $this->audit('asset', $assetId, $tag, ($found ? 'Found in the count; addition at deemed cost of ' : 'Donated by ' . trim($f['source']) . '; addition at fair value of ')
                . Prototype::fmt($f['amount']) . ' proposed by ' . $who . ' — waiting for approval'
                . ($documents === [] ? '' : ' · ' . count($documents) . ' document' . (count($documents) === 1 ? '' : 's') . ' attached'), $actorId);

            return $tag;
        });
    }

    /** Takes back a proposal that has not been approved. A donated asset proposed with it comes off the list too. */
    public function withdraw(string $tag, int $actorId): void
    {
        $d = $this->additions()[$tag] ?? null;
        if ($d === null || $d['status'] !== 'pending_approval') {
            throw new RuleViolation('There is no addition of ' . $tag . ' waiting for approval.');
        }

        $this->transaction(function () use ($d, $tag, $actorId) {
            $this->audit('asset', (int) $d['asset_id'], $tag, 'Addition withdrawn by ' . $this->lookups->shortName($actorId), $actorId);
            $this->db->table('asset_additions')->where('id', $d['id'])->delete();
            if ($d['basis'] === 'donation') {
                // The asset proposed with it goes, and so do the documents filed against it.
                (new AttachmentRepository($this->db))->removeFiles(array_column($this->rows("SELECT storage_key FROM {attachments} WHERE object_type = 'asset' AND object_id = ?", [(int) $d['asset_id']]), 'storage_key'));
                $this->db->table('attachments')->where(['object_type' => 'asset', 'object_id' => (int) $d['asset_id']])->delete();
                $this->db->table('assets')->where('id', $d['asset_id'])->delete();
            }
        });
    }

    /**
     * Approves a proposal and posts it: Dr the class cost account, Cr 4260 or 3100,
     * on the asset's fund. The asset comes onto the register dated when it was
     * recognised. The person who proposed it cannot approve it.
     *
     * @return array{journal: string, amount: float, credit: string}
     */
    public function approve(string $tag, int $actorId): array
    {
        $d = $this->additions()[$tag] ?? null;
        if ($d === null || $d['status'] !== 'pending_approval') {
            throw new RuleViolation('There is no addition of ' . $tag . ' waiting for approval.');
        }
        if ((int) $d['requested_by'] === $actorId) {
            throw new RuleViolation('The person who proposed an addition cannot approve it. It needs a second approver.');
        }
        $period = $this->assets->periodOf($d['recognised_on']);
        if ($period === null || $period['status'] === 'closed') {
            throw new RuleViolation(($period['name'] ?? self::dmy($d['recognised_on'])) . ' is closed to posting. Withdraw the proposal and date it in an open period.');
        }
        $asset = current(array_filter($this->assets->all(), static fn ($a) => $a['tag'] === $tag));
        $found = $d['basis'] === 'found';
        $amount = (float) $d['amount'];
        $line = static fn (string $code, string $desc, float $dr, float $cr) => [
            'code' => $code, 'fund_id' => $asset['fundId'], 'programme_id' => $asset['programmeId'], 'grant_id' => $asset['grantId'], 'desc' => $desc, 'dr' => $dr, 'cr' => $cr,
        ];
        $who = $this->lookups->shortName($actorId);

        return $this->transaction(function () use ($d, $asset, $tag, $found, $amount, $line, $who, $actorId) {
            $journal = (new JournalRepository())->postFromSource([
                'date' => $d['recognised_on'], 'sourceType' => 'asset_addition', 'sourceId' => (int) $d['id'], 'docRef' => $d['reference'] ?? $tag, 'series' => 'JV',
                'narration' => ($found ? 'Asset found in count brought onto the register — ' : 'Donated asset brought onto the register — ') . $asset['name'] . ' (' . $tag . ')',
                'memo' => mb_substr(($found ? 'Found in ' : 'Donated by ') . $d['source'] . '. ' . $d['reason'], 0, 255),
            ], [
                $line($asset['costAcct'], 'Asset capitalised — ' . $tag, $amount, 0),
                $line($d['credit_code'], $found ? 'Asset omitted from the register in earlier years — ' . $tag : 'Donated in kind by ' . $d['source'] . ' — ' . $tag, 0, $amount),
            ], (int) $d['requested_by'], $actorId, 'Raised by the asset register on the addition of ' . $tag);

            $now = Clock::timestamp();
            $this->db->table('asset_additions')->where('id', $d['id'])->update([
                'status' => 'posted', 'approved_by' => $actorId, 'approved_at' => $now,
                'journal_id' => (int) $this->value('SELECT id FROM {journals} WHERE reference = ?', [$journal]), 'updated_at' => $now,
            ]);
            $this->db->table('assets')->where('id', $asset['id'])->update([
                'capitalised' => 1, 'cost' => $amount, 'acquired_on' => $d['recognised_on'], 'opening_accumulated_depreciation' => 0,
                'acquisition_journal_id' => (int) $this->value('SELECT id FROM {journals} WHERE reference = ?', [$journal]), 'updated_at' => $now,
            ]);
            $this->audit('asset', $asset['id'], $tag, 'Addition approved by ' . $who . ' and posted as ' . $journal . ' — ' . Prototype::fmt($amount)
                . ' to ' . $asset['costAcct'] . ' against ' . $d['credit_code'], $actorId);

            return ['journal' => $journal, 'amount' => $amount, 'credit' => $d['credit_code']];
        });
    }

    // ---- Helpers ----

    /** The next free tags for a class prefix: VEH-003, VEH-004 … */
    private function nextTags(string $prefix, int $count): array
    {
        $max = 0;
        foreach ($this->rows('SELECT tag FROM {all:assets} WHERE tag LIKE ?', [$prefix . '-%']) as $r) {
            $suffix = substr($r['tag'], strlen($prefix) + 1);
            if (ctype_digit($suffix)) {
                $max = max($max, (int) $suffix);
            }
        }

        return array_map(static fn ($n) => $prefix . '-' . str_pad((string) $n, 3, '0', STR_PAD_LEFT), range($max + 1, $max + $count));
    }

    private function location(string $name): int
    {
        $entity = $this->lookups->entityId();
        $id = $this->value('SELECT id FROM {locations} WHERE entity_id = ? AND name = ?', [$entity, $name]);

        return $id !== null ? (int) $id : $this->insert('locations', ['entity_id' => $entity, 'name' => $name, 'created_at' => Clock::timestamp()]);
    }
}
