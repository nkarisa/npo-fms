<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use App\Libraries\Ledger;
use CodeIgniter\Database\Seeder;
use RuntimeException;

/**
 * The general ledger: an opening-balance journal plus every journal in JOURNALS.
 *
 * The prototype keeps account balances on the chart (SEED) and the journal list
 * separately; the balances are the book. So that the seeded ledger reproduces
 * every SEED balance exactly, the opening journal carries each balance less the
 * effect of the journals that are posted separately. It excludes 3900 (surplus
 * for the year), which is derived from income and expenditure, never posted.
 *
 * 3200 "Restricted fund balance" is held for "Multiple" funders. It is split so
 * that no restricted fund is ever taken below zero while the ledger is loaded (the
 * database refuses such a posting): each restricted fund first receives enough to
 * cover its lowest running balance, and the remainder is shared across the grant
 * funds in proportion to their opening balances in FUNDS.
 *
 * Journals are inserted as drafts, given their lines, and then moved to their
 * status through the same transitions the application uses, so every posting
 * passes the integrity triggers.
 */
class LedgerSeeder extends Seeder
{
    private const POSTED = ['Posted', 'Reversed'];

    private const RESTRICTED_BALANCE = '3200';

    private const OPENING_DATE = '2026-01-01';

    private SeedContext $ctx;

    /** @var array<string, array> SEED rows by code */
    private array $chart = [];

    public function run(): void
    {
        $this->ctx   = $ctx = SeedContext::get();
        $this->chart = array_column($ctx->data('SEED'), null, 'code');
        $entity      = $ctx->entityId();

        $journals = $ctx->data('JOURNALS');
        usort($journals, fn ($a, $b) => [$ctx->date($a['date']), $a['ref']] <=> [$ctx->date($b['date']), $b['ref']]);

        $lines  = array_map(fn ($j) => $this->resolveLines($j), array_column($journals, null, 'ref'));
        $posted = array_values(array_filter($journals, static fn ($j) => in_array($j['status'], self::POSTED, true)));

        $opening = $this->openingLines($posted, $lines);
        $opening = array_merge($opening, $this->restrictedBalanceLines($opening, $posted, $lines));

        $openingId = $this->createJournal([
            'reference' => 'OB-26-0001', 'journal_date' => self::OPENING_DATE, 'type' => 'adjustment',
            'memo' => 'Loaded from the prototype chart of accounts (SEED)',
            'narration' => 'Balances brought forward — FY2026 year to date, less journals loaded separately',
            'prepared_by' => $ctx->systemUserId(),
        ], $opening, 'posted', ['posted_at' => self::OPENING_DATE . ' 00:00:00']);
        $ctx->writeTrail('journal', $openingId, 'OB-26-0001', [['when' => '01 Jan 2026', 'what' => 'Opening balances loaded by data migration']], $entity);

        $ids = [];
        foreach ($journals as $j) {
            $ids[$j['ref']] = $id = $this->createJournal($this->header($j, $ids), $lines[$j['ref']], $this->status($j), $this->approval($j));
            $ctx->writeTrail('journal', $id, $j['ref'], $j['trail'], $entity);
        }

        // A reversed journal is marked once its reversal has posted.
        foreach ($journals as $j) {
            if ($j['status'] === 'Reversed') {
                $ctx->db()->table('journals')->where('id', $ids[$j['ref']])->update(['status' => 'reversed']);
            }
        }
    }

    /** Lines of a prototype journal with their fund, programme and grant resolved. */
    private function resolveLines(array $j): array
    {
        $resolved = [];

        // Income and expenditure lines first: their account names the funder.
        foreach ($j['lines'] as $i => $l) {
            if ($this->isIncomeOrExpense($l['code'])) {
                $resolved[$i] = $this->line($l, $this->ctx->fundId($l['fund'], null, $l['program'], $l['code'], true));
            }
        }

        // Balance sheet lines follow the income or expenditure line they pair with.
        foreach ($j['lines'] as $i => $l) {
            if (isset($resolved[$i])) {
                continue;
            }
            $sibling = null;
            if ($l['fund'] === 'Grant Fund') {
                foreach ($resolved as $k => $r) {
                    if ($j['lines'][$k]['fund'] === 'Grant Fund' && $j['lines'][$k]['program'] === $l['program']) {
                        $sibling = $r['fund_id'];
                        break;
                    }
                }
            }
            $resolved[$i] = $this->line($l, $sibling ?? $this->ctx->fundId($l['fund'], null, $l['program'], $l['code']));
        }

        ksort($resolved);

        return array_values($resolved);
    }

    private function line(array $l, int $fundId): array
    {
        return [
            'code' => $l['code'], 'fund_id' => $fundId, 'programme' => $l['program'], 'description' => $l['desc'],
            'debit' => (float) ($l['dr'] ?? 0), 'credit' => (float) ($l['cr'] ?? 0),
        ];
    }

    /** SEED balances less the effect of separately posted journals, as debit/credit lines. */
    private function openingLines(array $posted, array $lines): array
    {
        $effect = [];
        foreach ($posted as $j) {
            foreach ($lines[$j['ref']] as $l) {
                $effect[$l['code']] = ($effect[$l['code']] ?? 0) + $l['debit'] - $l['credit'];
            }
        }

        $chart  = array_values($this->chart);
        $result = [];
        foreach ($chart as $i => $a) {
            $isLeaf = !isset($chart[$i + 1]) || $chart[$i + 1]['level'] <= $a['level'];
            if (!$isLeaf || in_array($a['code'], [...Ledger::DERIVED_CODES, self::RESTRICTED_BALANCE], true)) {
                continue;
            }

            $debitNormal = in_array($a['type'], ['Asset', 'Expense'], true);
            $net = round(($debitNormal ? $a['balance'] : -$a['balance']) - ($effect[$a['code']] ?? 0), 2);
            if ($net == 0) {
                continue;
            }

            $fund = match (true) {
                $a['restriction'] === 'Endowment' => $this->ctx->require('funds', 'FND-400'),
                $a['fund'] === 'Grant Fund'       => $this->ctx->fundId('Grant Fund', null, $a['program'], $a['code'], true),
                default                           => $this->ctx->fundId($a['fund']),
            };

            $result[] = [
                'code' => $a['code'], 'fund_id' => $fund, 'programme' => $a['program'], 'description' => $a['name'],
                'debit' => max($net, 0), 'credit' => max(-$net, 0),
            ];
        }

        return $result;
    }

    /** Splits 3200 across restricted funds; see the class comment. */
    private function restrictedBalanceLines(array $opening, array $posted, array $lines): array
    {
        $restricted = [];
        foreach ($this->ctx->db()->table('funds')->whereIn('restriction', ['restricted', 'endowment'])->get()->getResultArray() as $f) {
            $restricted[(int) $f['id']] = 0.0;
        }

        $running = $lowest = $restricted;
        foreach ([$opening, ...array_map(static fn ($j) => $lines[$j['ref']], $posted)] as $journalLines) {
            foreach ($journalLines as $l) {
                if (isset($running[$l['fund_id']]) && !in_array($this->chart[$l['code']]['type'], ['Asset', 'Liability'], true)) {
                    $running[$l['fund_id']] += $l['credit'] - $l['debit'];
                }
            }
            foreach ($running as $fund => $balance) {
                $lowest[$fund] = min($lowest[$fund], $balance);
            }
        }

        $allocation = array_map(static fn ($low) => round(max(0, -$low), 2), $lowest);
        $remainder  = round($this->chart[self::RESTRICTED_BALANCE]['balance'] - array_sum($allocation), 2);
        if ($remainder < 0) {
            throw new RuntimeException('3200 Restricted fund balance is too small to keep every restricted fund at or above zero.');
        }

        $weights = [];
        foreach ($this->ctx->data('FUNDS') as $f) {
            if ($f['ledgerFund'] === 'Grant Fund' && $f['opening'] > 0) {
                $weights[$this->ctx->require('funds', $f['code'])] = $f['opening'];
            }
        }
        $shared = 0.0;
        $last   = array_key_last($weights);
        foreach ($weights as $fund => $weight) {
            $share = $fund === $last ? round($remainder - $shared, 2) : round($remainder * $weight / array_sum($weights), 0);
            $allocation[$fund] += $share;
            $shared += $share;
        }

        $result = [];
        foreach (array_filter($allocation) as $fund => $amount) {
            $result[] = [
                'code' => self::RESTRICTED_BALANCE, 'fund_id' => $fund, 'programme' => '—',
                'description' => $this->chart[self::RESTRICTED_BALANCE]['name'], 'debit' => 0.0, 'credit' => $amount,
            ];
        }

        return $result;
    }

    private function header(array $j, array $insertedIds): array
    {
        $ctx = $this->ctx;
        $reverses = $j['reversalOf'] ?? (preg_match('/^Reversal of ([A-Z]{2}-\d{2}-\d{4})/', $j['narration'], $m) === 1 ? $m[1] : null);
        if ($reverses !== null && !isset($insertedIds[$reverses])) {
            throw new RuntimeException("{$j['ref']} reverses {$reverses}, which has not been loaded.");
        }

        $submitted = $ctx->trailEntry($j['trail'], '/^Submitted for approval/');
        $rejected  = $ctx->trailEntry($j['trail'], '/^Rejected by/');

        return [
            'reference' => $j['ref'], 'journal_date' => $ctx->date($j['date']), 'type' => strtolower($j['type']),
            'document_type_id' => $ctx->lookup('document_types', substr($j['ref'], 0, 2)),
            'document_ref' => $j['doc'] !== '' ? $j['doc'] : null, 'memo' => $j['memo'] !== '' ? $j['memo'] : null,
            'narration' => $j['narration'], 'prepared_by' => $ctx->userOrSystem($j['preparer']),
            'submitted_at' => $submitted['when'] ?? null,
            // The application returns a rejected journal to draft; the reason is kept.
            'rejected_reason' => $rejected === null ? null : trim(explode(':', $rejected['what'], 2)[1] ?? $rejected['what']),
            'reverses_journal_id' => $reverses === null ? null : $insertedIds[$reverses],
        ];
    }

    private function status(array $j): string
    {
        return match ($j['status']) {
            'Posted', 'Reversed' => 'posted',
            'Pending approval'   => 'pending_approval',
            default              => 'draft',
        };
    }

    private function approval(array $j): array
    {
        $approved = $this->ctx->trailEntry($j['trail'], '/^Approved and posted by/');
        $at = $approved['when'] ?? $this->ctx->date($j['date']) . ' 00:00:00';

        return ['approved_by' => $this->ctx->userId($approved['who'] ?? null), 'approved_at' => $approved === null ? null : $at, 'posted_at' => $at];
    }

    /** Inserts a draft with its lines, then moves it to the final status. */
    private function createJournal(array $header, array $lines, string $status, array $approval): int
    {
        $ctx = $this->ctx;
        $journalDate = $header['journal_date'];

        $id = $ctx->insert('journals', $header + [
            'entity_id' => $ctx->entityId(), 'period_id' => $ctx->periodId($journalDate), 'status' => 'draft', 'created_at' => $ctx->now(),
        ]);

        foreach ($lines as $i => $l) {
            $ctx->insert('journal_lines', [
                'journal_id' => $id, 'line_no' => $i + 1, 'account_id' => $ctx->accountId($l['code']), 'fund_id' => $l['fund_id'],
                'programme_id' => $ctx->programmeId($l['programme']), 'grant_id' => $ctx->grantOfFund($l['fund_id']),
                'description' => mb_substr($l['description'], 0, 255), 'debit' => $l['debit'], 'credit' => $l['credit'],
            ]);
        }

        if ($status === 'pending_approval') {
            $ctx->db()->table('journals')->where('id', $id)->update(['status' => 'pending_approval']);
        } elseif ($status === 'posted') {
            $ctx->db()->table('journals')->where('id', $id)->update(['status' => 'posted'] + array_filter($approval, static fn ($v) => $v !== null));
        }

        return $id;
    }

    private function isIncomeOrExpense(string $code): bool
    {
        return in_array($this->chart[$code]['type'] ?? '', ['Income', 'Expense'], true);
    }
}
