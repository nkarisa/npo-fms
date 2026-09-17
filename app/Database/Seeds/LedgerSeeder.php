<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
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
 * The prototype's general ledger shows each account's year as a run of postings
 * rather than one opening figure: 38% of the balance brought forward and the rest
 * as 10–15 postings through the months, each against a contra account (its
 * buildLedger). The same history is seeded here, dated January to July (the closed
 * months, so the open month's bank reconciliations and close are untouched). Every
 * posting keeps the coding of the account's opening line, and the opening journal
 * carries the opposite of each contra line in that coding, so no balance by
 * account, fund, programme or grant changes. These postings are the detail of
 * the closed months (`source_type` "archive"): the general ledger lists them, the
 * journal register does not.
 *
 * The August cash book the bank reconciliation works from (BR_ACCOUNTS) is posted
 * too: the payment vouchers, receipts, transfers and M-Pesa batches on each bank
 * account. Their source documents live outside the journal register
 * (`source_type` "cash_book"), so the register does not list them; the general
 * ledger and the reconciliation do. Each voucher's other side goes to the account
 * it settles (CASH_BOOK_CONTRAS). The opening journal's brought-forward lines
 * absorb their effect, so neither the balances nor the history move.
 *
 * Receivables are posted from the claims (ReceivablesSeeder runs first): each
 * issued claim's issue entry, dated when it was issued, and each receipt not
 * already in the cash book, dated when it arrived. Grants receivable is a control
 * account, so its balance is what the claims have outstanding, not the chart's
 * figure. The opening journal absorbs the claims' entries like any other, except
 * for the part of the newest claims that takes 1210 from the chart's figure to the
 * claims' outstanding balance (plus what claims written off without an entry will
 * take out of it, see RecordedWriteOffSeeder): that part is this year's income on
 * top of the chart's, recognised as those claims were issued.
 *
 * Journals are inserted as drafts, given their lines, and then moved to their
 * status through the same transitions the application uses, so every posting
 * passes the integrity triggers.
 */
class LedgerSeeder extends Seeder
{
    private const POSTED = ['Posted', 'Reversed'];

    private const RESTRICTED_BALANCE = '3200';

    /** Surplus for the year: derived from income and expenditure, never posted. */
    private const DERIVED = ['3900'];

    private const OPENING_DATE = '2026-01-01';

    /** Share of an account's balance brought forward; the rest is its history of postings. */
    private const BROUGHT_FORWARD = 0.38;

    /** History is dated in these months (January to July), the closed part of the year. */
    private const HISTORY_MONTHS = 7;

    /** The prototype's preparers of historical postings, and who approved them. */
    private const PREPARERS = ['J. Achieng', 'M. Otieno', 'S. Njeri', 'P. Mwangi'];

    private const APPROVER = 'W. Kamau';

    private const RECEIVABLE = '1210';

    /**
     * The account the other side of a cash book voucher goes to, by voucher series
     * and then by voucher: payment vouchers settle trade payables, receipts settle
     * grants receivable, M-Pesa batches pay observer stipends against advances. A
     * voucher that already balances across bank accounts (a transfer) has none.
     */
    private const CASH_BOOK_CONTRAS = [
        'PV' => '2110', 'RC' => '1210', 'MP' => '1220',
        'PV-26-0468' => '1220',   // LTO stipends
        'PV-26-0470' => '2210',   // PAYE remitted to KRA
        'PV-26-0481' => '2120',   // office rent, accrued on the lease schedule
        'JV-26-0291' => '1110',   // Equity USD to KCB current: the receiving side, not yet on the KCB statement
    ];

    private const CASH_BOOK_PREPARERS = ['PV' => 'J. Achieng', 'RC' => 'M. Otieno', 'MP' => 'S. Njeri', 'JV' => 'M. Otieno'];

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

        $cashBook = $this->cashBook();
        $claims = $this->claimJournals($cashBook);

        $fullOpening = $this->openingLines($posted, $lines, $claims['absorbed']);
        // The restricted balance is split as the ledger stood before its history was drawn out,
        // so every fund keeps the balance it has always had.
        $dated = array_merge(
            array_map(fn ($j) => [$ctx->date($j['date']), $lines[$j['ref']]], $posted),
            array_map(static fn ($c) => [$c['header']['journal_date'], $c['lines']], $claims['journals']),
        );
        usort($dated, static fn ($a, $b) => $a[0] <=> $b[0]);
        $restrictedBalance = $this->restrictedBalanceLines($fullOpening, array_column($dated, 1));
        $taken = array_merge(array_column($journals, 'ref'), array_column($cashBook, 'reference'));
        [$opening, $history] = $this->history($fullOpening, $taken);
        $opening = $this->lessCashBook($opening, $cashBook);
        $claimJournals = $this->numbered($claims['journals'], array_merge($taken, array_map(static fn ($h) => $h['header']['reference'], $history)));

        // Everything after the opening journal posts in date order: history, JOURNALS and the claims together.
        $sequence = array_merge(
            array_map(static fn ($h) => ['date' => $h['header']['journal_date'], 'ref' => $h['header']['reference'], 'history' => $h], $history),
            array_map(fn ($j) => ['date' => $ctx->date($j['date']), 'ref' => $j['ref'], 'journal' => $j], $journals),
            array_map(static fn ($c) => ['date' => $c['header']['journal_date'], 'ref' => $c['header']['reference'], 'claim' => $c], $claimJournals),
        );
        usort($sequence, static fn ($a, $b) => [$a['date'], $a['ref']] <=> [$b['date'], $b['ref']]);
        $sequence = $this->fundedOrder($sequence, array_merge($opening, $restrictedBalance), $lines);
        $postedLines = [];
        foreach ($sequence as $item) {
            if (isset($item['history'])) {
                $postedLines[] = $item['history']['lines'];
            } elseif (isset($item['claim'])) {
                $postedLines[] = $item['claim']['lines'];
            } elseif (in_array($item['journal']['status'], self::POSTED, true)) {
                $postedLines[] = $lines[$item['ref']];
            }
        }

        $opening = array_merge($opening, $restrictedBalance);
        $this->assertFundsStayFunded($opening, $postedLines);

        $openingId = $this->createJournal([
            'reference' => 'OB-26-0001', 'journal_date' => self::OPENING_DATE, 'type' => 'adjustment',
            // The year's opening balances: the chart reads its brought-forward figures from this journal.
            'source_type' => 'fiscal_year', 'source_id' => $ctx->require('fiscal_years', 'FY' . SeedContext::YEAR),
            'memo' => 'Loaded from the prototype chart of accounts (SEED)',
            'narration' => 'Balances brought forward — FY2026 year to date, less journals loaded separately',
            'prepared_by' => $ctx->systemUserId(),
        ], $opening, 'posted', ['posted_at' => self::OPENING_DATE . ' 00:00:00']);
        $ctx->writeTrail('journal', $openingId, 'OB-26-0001', [['when' => '01 Jan 2026', 'what' => 'Opening balances loaded by data migration']], $entity);

        $ids = [];
        foreach ($sequence as $item) {
            if (isset($item['history'])) {
                $this->createJournal($item['history']['header'], $item['history']['lines'], 'posted', $item['history']['approval']);
                continue;
            }
            if (isset($item['claim'])) {
                $c = $item['claim'];
                $id = $this->createJournal($c['header'], $c['lines'], 'posted', $c['approval']);
                $ctx->db()->table($c['links'][0])->where('id', $c['links'][1])->update(['journal_id' => $id]);
                continue;
            }
            $j = $item['journal'];
            $ids[$j['ref']] = $id = $this->createJournal($this->header($j, $ids), $lines[$j['ref']], $this->status($j), $this->approval($j));
            $ctx->writeTrail('journal', $id, $j['ref'], $j['trail'], $entity);
        }

        $approver = $ctx->userId(self::APPROVER);
        foreach ($cashBook as $voucher) {
            $lines = $voucher['lines'];
            unset($voucher['lines']);
            $this->createJournal($voucher, $lines, 'posted', [
                'approved_by' => $approver, 'approved_at' => $voucher['journal_date'] . ' 17:00:00', 'posted_at' => $voucher['journal_date'] . ' 17:00:00',
            ]);
        }

        // A receipt the cash book already carries is settled by that voucher.
        foreach ($ctx->db()->table('receipts')->where('journal_id', null)->where('invoice_id IS NOT NULL', null, false)->get()->getResultArray() as $r) {
            $voucher = $ctx->db()->table('journals')->select('id')->where(['reference' => $r['reference'], 'source_type' => 'cash_book', 'source_id' => $r['bank_account_id']])->get()->getRowArray();
            if ($voucher === null) {
                throw new RuntimeException("Receipt {$r['reference']} has no ledger entry.");
            }
            $ctx->db()->table('receipts')->where('id', $r['id'])->update(['journal_id' => $voucher['id']]);
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

    /**
     * SEED balances less the effect of separately posted journals, as debit/credit
     * lines. `$absorbed` are further lines whose effect is taken out the same way.
     */
    private function openingLines(array $posted, array $lines, array $absorbed = []): array
    {
        $effect = [];
        foreach ($posted as $j) {
            foreach ($lines[$j['ref']] as $l) {
                $effect[$l['code']] = ($effect[$l['code']] ?? 0) + $l['debit'] - $l['credit'];
            }
        }
        foreach ($absorbed as $l) {
            $effect[$l['code']] = ($effect[$l['code']] ?? 0) + $l['debit'] - $l['credit'];
        }

        $chart  = array_values($this->chart);
        $result = [];
        foreach ($chart as $i => $a) {
            $isLeaf = !isset($chart[$i + 1]) || $chart[$i + 1]['level'] <= $a['level'];
            if (!$isLeaf || in_array($a['code'], [...self::DERIVED, self::RESTRICTED_BALANCE], true)) {
                continue;
            }

            $debitNormal = in_array($a['type'], ['Asset', 'Expense'], true);
            $net = round(($debitNormal ? $a['balance'] : -$a['balance']) - ($effect[$a['code']] ?? 0), 2);
            if ($net == 0) {
                continue;
            }

            $result[] = [
                'code' => $a['code'], 'fund_id' => $this->chartFund($a['code']), 'programme' => $a['program'], 'description' => $a['name'],
                'debit' => max($net, 0), 'credit' => max(-$net, 0),
            ];
        }

        return $result;
    }

    /** The fund an account's balance is held in, as the chart codes it. */
    private function chartFund(string $code): int
    {
        $a = $this->chart[$code];

        return match (true) {
            $a['restriction'] === 'Endowment' => $this->ctx->require('funds', 'FND-400'),
            $a['fund'] === 'Grant Fund'       => $this->ctx->fundId('Grant Fund', null, $a['program'], $a['code'], true),
            default                           => $this->ctx->fundId($a['fund']),
        };
    }

    /**
     * The cash book behind each bank reconciliation, one journal per voucher: its
     * lines on the bank accounts it moves, and the account it settles.
     *
     * @return list<array> journal headers, each with its `lines`
     */
    private function cashBook(): array
    {
        $ctx = $this->ctx;
        $vouchers = [];
        foreach ($ctx->data('BR_ACCOUNTS') as $b) {
            foreach ($b['book'] as $l) {
                $vouchers[$l['ref']]['date'] ??= $ctx->date($l['date']);
                $vouchers[$l['ref']]['narration'] ??= $l['desc'];
                $vouchers[$l['ref']]['bank'] ??= $b['code'];
                $vouchers[$l['ref']]['lines'][] = ['code' => $b['code'], 'description' => $l['desc'], 'amount' => (float) $l['amt']];
            }
        }

        $journals = [];
        foreach ($vouchers as $ref => $v) {
            $series = substr($ref, 0, 2);
            $net = array_sum(array_column($v['lines'], 'amount'));
            if (round($net, 2) != 0) {
                $contra = self::CASH_BOOK_CONTRAS[$ref] ?? self::CASH_BOOK_CONTRAS[$series]
                    ?? throw new RuntimeException("No contra account for cash book voucher {$ref}.");
                $transfer = current(array_filter($ctx->data('BR_ACCOUNTS'), static fn ($b) => $b['code'] === $v['bank']));
                $v['lines'][] = [
                    'code' => $contra, 'amount' => -$net,
                    'description' => $this->chart[$contra]['type'] === 'Asset' && str_starts_with($contra, '11') ? 'Transfer from ' . $transfer['short'] : $this->chart[$contra]['name'],
                ];
            }

            $journals[] = [
                'reference' => $ref, 'journal_date' => $v['date'], 'type' => 'standard',
                'document_type_id' => $ctx->lookup('document_types', $series),
                'source_type' => 'cash_book', 'source_id' => $ctx->require('bank_accounts', $v['bank']),
                'narration' => $v['narration'], 'prepared_by' => $ctx->userOrSystem(self::CASH_BOOK_PREPARERS[$series] ?? null),
                'lines' => array_map(fn ($l) => [
                    'code' => $l['code'], 'fund_id' => $this->chartFund($l['code']), 'programme' => $this->chart[$l['code']]['program'],
                    'description' => $l['description'], 'debit' => max($l['amount'], 0), 'credit' => max(-$l['amount'], 0),
                ], $v['lines']),
            ];
        }
        usort($journals, static fn ($a, $b) => [$a['journal_date'], $a['reference']] <=> [$b['journal_date'], $b['reference']]);

        return $journals;
    }

    /**
     * The claims' entries: an issue entry per claim issued, and a receipt entry per
     * receipt the cash book does not already carry. `absorbed` is what the opening
     * journal takes out: every receipt, and every issue except the part of the newest
     * claims that brings 1210 to what the claims have outstanding; see the class comment.
     *
     * @return array{journals: list<array>, absorbed: list<array>}
     */
    private function claimJournals(array $cashBook): array
    {
        $ctx = $this->ctx;
        $db = $ctx->db();
        $t = static fn (string $table) => $db->prefixTable($table);
        $inCashBook = array_flip(array_map(static fn ($v) => $v['source_id'] . '|' . $v['reference'], $cashBook));

        $receipts = [];
        foreach ($db->query("SELECT r.*, a.code FROM {$t('receipts')} r JOIN {$t('bank_accounts')} b ON b.id = r.bank_account_id
                             JOIN {$t('accounts')} a ON a.id = b.account_id WHERE r.invoice_id IS NOT NULL ORDER BY r.received_on, r.id")->getResultArray() as $r) {
            $receipts[(int) $r['invoice_id']][] = $r;
        }
        $invoices = $db->query(
            "SELECT i.*, COALESCE(f.name, i.bill_to) AS customer, g.award_ref, p.name AS programme
             FROM {$t('invoices')} i LEFT JOIN {$t('funders')} f ON f.id = i.funder_id LEFT JOIN {$t('grants')} g ON g.id = i.grant_id
             JOIN {$t('programmes')} p ON p.id = i.programme_id
             WHERE i.status <> 'draft' ORDER BY i.issue_date DESC, i.reference DESC"
        )->getResultArray();

        $outstanding = 0.0;
        foreach ($invoices as $i) {
            if (in_array($i['status'], ['issued', 'part_received', 'written_off'], true)) {
                $outstanding += (float) $i['amount'] - array_sum(array_column($receipts[(int) $i['id']] ?? [], 'amount'));
            }
        }
        $remaining = round($outstanding - (float) $this->chart[self::RECEIVABLE]['balance'], 2);
        if ($remaining < 0) {
            throw new RuntimeException('The chart carries more in 1210 than the claims have outstanding.');
        }

        $journals = $absorbed = [];
        foreach ($invoices as $i) {
            $amount = (float) $i['amount'];
            $coding = ['fund_id' => (int) $i['fund_id'], 'programme' => $i['programme'], 'grant_id' => $i['grant_id'] === null ? null : (int) $i['grant_id']];
            $issued = [['code' => self::RECEIVABLE, 'description' => 'Receivable from ' . $i['customer'] . ' · ' . $i['reference'], 'debit' => $amount, 'credit' => 0.0] + $coding];
            foreach ($db->query("SELECT l.*, a.code FROM {$t('invoice_lines')} l JOIN {$t('accounts')} a ON a.id = l.account_id WHERE l.invoice_id = ? ORDER BY l.line_no", [$i['id']])->getResultArray() as $l) {
                $issued[] = ['code' => $l['code'], 'description' => $l['description'], 'debit' => 0.0, 'credit' => (float) $l['amount']] + $coding;
            }

            // The part of this claim that is new to the chart stays out of the opening journal.
            $new = min($remaining, $amount);
            $remaining = round($remaining - $new, 2);
            $keep = round($amount - $new, 2);
            $split = 0.0;
            foreach ($issued as $k => $l) {
                $share = $k === 0 ? $keep : ($k === array_key_last($issued) ? round($keep - $split, 2) : round($l['credit'] * $keep / $amount, 2));
                $split += $k === 0 ? 0 : $share;
                if ($share != 0) {
                    $absorbed[] = ['debit' => $k === 0 ? $share : 0.0, 'credit' => $k === 0 ? 0.0 : $share] + $l;
                }
            }

            $at = $i['issue_date'] . ' 17:00:00';
            $journals[] = [
                'header' => [
                    'journal_date' => $i['issue_date'], 'type' => 'standard', 'document_type_id' => $ctx->lookup('document_types', 'JV'),
                    'document_ref' => $i['reference'], 'source_type' => 'invoice', 'source_id' => (int) $i['id'],
                    'narration' => $i['customer'] . ' — ' . strtolower(str_replace('_', ' ', $i['type'])) . ', ' . $i['reference'],
                    'memo' => 'Claim issued to ' . $i['customer'] . ($i['award_ref'] ? ' under ' . $i['award_ref'] : '') . '.',
                    'prepared_by' => (int) $i['prepared_by'],
                ],
                'lines' => $issued,
                'approval' => ['approved_by' => $i['approved_by'], 'approved_at' => $i['approved_by'] === null ? null : $at, 'posted_at' => $at],
                'links' => ['invoices', (int) $i['id']],
            ];

            foreach ($receipts[(int) $i['id']] ?? [] as $r) {
                if (isset($inCashBook[$r['bank_account_id'] . '|' . $r['reference']])) {
                    continue;
                }
                $lines = [
                    ['code' => $r['code'], 'description' => 'Receipt banked · ' . $r['reference'], 'debit' => (float) $r['amount'], 'credit' => 0.0] + $coding,
                    ['code' => self::RECEIVABLE, 'description' => 'Grants receivable settled — ' . $i['reference'], 'debit' => 0.0, 'credit' => (float) $r['amount']] + $coding,
                ];
                array_push($absorbed, ...$lines);
                $at = $r['received_on'] . ' 17:00:00';
                $journals[] = [
                    'header' => [
                        'journal_date' => $r['received_on'], 'type' => 'standard', 'document_type_id' => $ctx->lookup('document_types', 'RC'),
                        'document_ref' => $r['reference'], 'source_type' => 'receipt', 'source_id' => (int) $r['id'],
                        'narration' => 'Receipt from ' . $i['customer'] . ' against ' . $i['reference'], 'memo' => 'Donor receipt applied against the claim.',
                        'prepared_by' => (int) $r['created_by'],
                    ],
                    'lines' => $lines,
                    'approval' => ['approved_by' => null, 'approved_at' => null, 'posted_at' => $at],
                    'links' => ['receipts', (int) $r['id']],
                ];
            }
        }
        if ($remaining > 0) {
            throw new RuntimeException('The claims issued cannot bring 1210 to what they have outstanding.');
        }

        usort($journals, static fn ($a, $b) => [$a['header']['journal_date'], $a['header']['source_type'], $a['header']['source_id']] <=> [$b['header']['journal_date'], $b['header']['source_type'], $b['header']['source_id']]);

        return ['journals' => $journals, 'absorbed' => $absorbed];
    }

    /** Gives the claims' entries the JV numbers after every reference already taken, in date order. */
    private function numbered(array $journals, array $taken): array
    {
        $next = 1;
        foreach ($taken as $ref) {
            if (preg_match('/^JV-' . (SeedContext::YEAR % 100) . '-(\d{4})$/', $ref, $m) === 1) {
                $next = max($next, (int) $m[1] + 1);
            }
        }

        return array_map(static function ($j) use (&$next) {
            $j['header']['reference'] = sprintf('JV-%02d-%04d', SeedContext::YEAR % 100, $next++);

            return $j;
        }, $journals);
    }

    /** Takes the cash book's effect out of the balances brought forward, line by line in the same coding. */
    private function lessCashBook(array $opening, array $cashBook): array
    {
        foreach ($cashBook as $voucher) {
            foreach ($voucher['lines'] as $l) {
                $effect = $l['debit'] - $l['credit'];
                $found = false;
                foreach ($opening as $k => $o) {
                    if ($o['code'] === $l['code'] && $o['fund_id'] === $l['fund_id'] && $o['programme'] === $l['programme']) {
                        $opening[$k] = $this->signedLine($o, $o['debit'] - $o['credit'] - $effect);
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $opening[] = $this->signedLine($l, -$effect);
                }
            }
        }

        return $opening;
    }

    /**
     * Splits each account's opening line into the share brought forward and a
     * history of postings; see the class comment.
     *
     * @param list<string> $takenRefs references already used by JOURNALS
     * @return array{0: list<array>, 1: list<array{header: array, lines: list<array>, approval: array}>}
     */
    private function history(array $opening, array $takenRefs): array
    {
        $ctx = $this->ctx;
        $sources = $ctx->data('SOURCES');
        $narrations = $ctx->data('NARRATION');
        $taken = array_flip(array_merge($takenRefs, ['OB-26-0001']));
        $approver = $ctx->userId(self::APPROVER);
        $offsets = [];
        $journals = [];
        $kept = [];

        foreach ($opening as $line) {
            $account = $this->chart[$line['code']];
            $debitNormal = in_array($account['type'], ['Asset', 'Expense'], true);
            $net = $debitNormal ? $line['debit'] - $line['credit'] : $line['credit'] - $line['debit'];
            if ($account['type'] === 'Equity' || round($net) == 0) {
                $kept[] = $line;
                continue;
            }

            $r = self::rng($line['code'] . $account['name']);
            $broughtForward = round($net * self::BROUGHT_FORWARD / 1000) * 1000;
            $movement = $net - $broughtForward;
            $kept[] = $this->signedLine($line, $debitNormal ? $broughtForward : -$broughtForward);

            $n = 10 + (int) floor($r() * 6);
            $weights = [];
            for ($i = 0; $i < $n; $i++) {
                $weights[] = (0.4 + $r()) * ($r() < 0.16 ? -0.35 : 1);
            }
            $sum = array_sum($weights) ?: 1;
            $choices = $narrations[$line['code']] ?? $narrations[substr($line['code'], 0, 2)] ?? $narrations[substr($line['code'], 0, 1)] ?? $narrations['5'];
            $lastNarration = -1;
            $posted = 0.0;

            for ($i = 0; $i < $n; $i++) {
                $amount = $i === $n - 1 ? $movement - $posted : round($movement * $weights[$i] / $sum / 100) * 100;
                $posted += $amount;
                $month = min(self::HISTORY_MONTHS - 1, (int) floor($i / $n * self::HISTORY_MONTHS + $r() * 0.8));
                $day = min(2 + (int) floor($r() * 26), (int) date('t', mktime(0, 0, 0, $month + 1, 1, SeedContext::YEAR)));
                $source = $sources[(int) floor($r() * count($sources)) % count($sources)];
                $number = 140 + (int) floor($r() * 800);
                $k = (int) floor($r() * count($choices)) % count($choices);
                if ($k === $lastNarration) {
                    $k = ($k + 1) % count($choices);
                }
                $lastNarration = $k;
                $preparer = self::PREPARERS[(int) floor($r() * count(self::PREPARERS)) % count(self::PREPARERS)];
                $contra = $this->contraFor($account['type'], $r(), $line['code']);
                if (round($amount) == 0) {
                    continue;
                }

                do {
                    $ref = sprintf('%s-%02d-%04d', $source['ref'], SeedContext::YEAR % 100, $number++);
                } while (isset($taken[$ref]));
                $taken[$ref] = true;

                // The account moves by $amount on its normal side; the contra takes the other side.
                $accountLine = $this->signedLine($line, $debitNormal ? $amount : -$amount);
                $contraLine = ['code' => $contra, 'description' => $this->chart[$contra]['name']] + $this->signedLine($line, $debitNormal ? -$amount : $amount);
                $offsetKey = $contra . '|' . $line['fund_id'] . '|' . $line['programme'];
                $offsets[$offsetKey] = ($offsets[$offsetKey] ?? 0) + $contraLine['credit'] - $contraLine['debit'];

                $date = sprintf('%d-%02d-%02d', SeedContext::YEAR, $month + 1, $day);
                $journals[] = [
                    'header' => [
                        'reference' => $ref, 'journal_date' => $date, 'type' => 'standard',
                        'document_type_id' => $ctx->lookup('document_types', $source['ref']),
                        'document_ref' => 'ELOG/' . $line['code'] . '/' . (10 + $number % 89),
                        'source_type' => 'archive', 'source_id' => $ctx->periodId($date),
                        'narration' => $choices[$k], 'prepared_by' => $ctx->userOrSystem($preparer),
                    ],
                    'lines' => [$accountLine, $contraLine],
                    'approval' => ['approved_by' => $approver, 'approved_at' => $date . ' 17:00:00', 'posted_at' => $date . ' 17:00:00'],
                ];
            }
        }

        // The opening journal carries the opposite of every contra line, in the same coding.
        foreach ($offsets as $key => $net) {
            [$code, $fund, $programme] = explode('|', $key, 3);
            if (round($net, 2) != 0) {
                $kept[] = ['code' => $code, 'fund_id' => (int) $fund, 'programme' => $programme, 'description' => $this->chart[$code]['name'],
                    'debit' => max($net, 0), 'credit' => max(-$net, 0)];
            }
        }

        return [$kept, $journals];
    }

    /** A copy of a line carrying a signed amount: positive is a debit, negative a credit. */
    private function signedLine(array $line, float $debit): array
    {
        return ['debit' => round(max($debit, 0), 2), 'credit' => round(max(-$debit, 0), 2)] + $line;
    }

    /** The prototype's contra accounts: grants received or receivable for income, bank or payables for spend. */
    private function contraFor(string $type, float $draw, string $code): string
    {
        $contra = match ($type) {
            'Income'    => $draw < 0.55 ? '1120' : '1210',
            'Expense'   => $draw < 0.6 ? '1110' : '2110',
            'Asset'     => $draw < 0.5 ? '1110' : '2110',
            default     => '1110',
        };

        return $contra === $code ? ($code === '1110' ? '2110' : '1110') : $contra;
    }

    /** The prototype's seeded generator (mulberry32 over an FNV-1a hash), so the history is the same on every load. */
    private static function rng(string $seed): callable
    {
        $int32 = static fn (int $x): int => ($x & 0xFFFFFFFF) >= 0x80000000 ? ($x & 0xFFFFFFFF) - 0x100000000 : ($x & 0xFFFFFFFF);
        $imul = static function (int $a, int $b) use ($int32): int {
            $a &= 0xFFFFFFFF;
            $b &= 0xFFFFFFFF;

            return $int32((($a & 0xFFFF) * $b) + ((((($a >> 16) & 0xFFFF) * $b) & 0xFFFF) << 16));
        };

        $h = 2166136261;
        foreach (str_split($seed) as $char) {
            $h = $imul($h ^ ord($char), 16777619);
        }
        $state = $h & 0xFFFFFFFF;

        return static function () use (&$state, $int32, $imul): float {
            $state = $int32($state + 0x6D2B79F5);
            $t = $imul($state ^ (($state & 0xFFFFFFFF) >> 15), 1 | $state);
            $t = $int32(($t + $imul($t ^ (($t & 0xFFFFFFFF) >> 7), 61 | $t)) ^ $t);

            return (($t ^ (($t & 0xFFFFFFFF) >> 14)) & 0xFFFFFFFF) / 4294967296;
        };
    }

    /**
     * Posts history no earlier than the fund can bear it: a historical posting that
     * would take a restricted fund below zero waits until the fund has received
     * more, and takes the date of the posting that funded it. JOURNALS keep their
     * dates.
     */
    private function fundedOrder(array $sequence, array $opening, array $lines): array
    {
        $running = [];
        foreach ($this->ctx->db()->table('funds')->whereIn('restriction', ['restricted', 'endowment'])->get()->getResultArray() as $f) {
            $running[(int) $f['id']] = 0.0;
        }
        $effect = function (array $journalLines) use (&$running): array {
            $delta = [];
            foreach ($journalLines as $l) {
                if (isset($running[$l['fund_id']]) && !in_array($this->chart[$l['code']]['type'], ['Asset', 'Liability'], true)) {
                    $delta[$l['fund_id']] = ($delta[$l['fund_id']] ?? 0) + $l['credit'] - $l['debit'];
                }
            }

            return $delta;
        };
        $bears = static function (array $delta) use (&$running): bool {
            foreach ($delta as $fund => $d) {
                if ($running[$fund] + $d < -0.005) {
                    return false;
                }
            }

            return true;
        };
        $apply = static function (array $delta) use (&$running): void {
            foreach ($delta as $fund => $d) {
                $running[$fund] += $d;
            }
        };

        $apply($effect($opening));
        $ordered = [];
        $waiting = [];
        foreach ($sequence as $item) {
            $journalLines = isset($item['history']) ? $item['history']['lines']
                : (isset($item['claim']) ? $item['claim']['lines']
                : (in_array($item['journal']['status'], self::POSTED, true) ? $lines[$item['ref']] : []));
            $delta = $effect($journalLines);
            if (isset($item['history']) && !$bears($delta)) {
                $waiting[] = $item + ['delta' => $delta];
                continue;
            }
            $apply($delta);
            $ordered[] = $item;

            foreach ($waiting as $k => $w) {
                if ($bears($w['delta'])) {
                    $apply($w['delta']);
                    $w['date'] = $item['date'];
                    $w['history']['header']['journal_date'] = $item['date'];
                    $w['history']['header']['source_id'] = $this->ctx->periodId($item['date']);
                    $w['history']['approval'] = ['approved_at' => $item['date'] . ' 17:00:00', 'posted_at' => $item['date'] . ' 17:00:00'] + $w['history']['approval'];
                    unset($w['delta']);
                    $ordered[] = $w;
                    unset($waiting[$k]);
                }
            }
        }

        if ($waiting !== []) {
            throw new RuntimeException(count($waiting) . ' historical postings could not be funded; adjust BROUGHT_FORWARD.');
        }

        return $ordered;
    }

    /** The history must not take a restricted fund below zero at any point, or the database would refuse it. */
    private function assertFundsStayFunded(array $opening, array $postedLines): void
    {
        $running = [];
        foreach ($this->ctx->db()->table('funds')->whereIn('restriction', ['restricted', 'endowment'])->get()->getResultArray() as $f) {
            $running[(int) $f['id']] = 0.0;
        }
        foreach ([$opening, ...$postedLines] as $journalLines) {
            foreach ($journalLines as $l) {
                if (isset($running[$l['fund_id']]) && !in_array($this->chart[$l['code']]['type'], ['Asset', 'Liability'], true)) {
                    $running[$l['fund_id']] += $l['credit'] - $l['debit'];
                }
            }
            foreach ($running as $fund => $balance) {
                if ($balance < -0.005) {
                    throw new RuntimeException("The seeded history takes fund {$fund} below zero; adjust BROUGHT_FORWARD.");
                }
            }
        }
    }

    /** Splits 3200 across restricted funds; see the class comment. */
    private function restrictedBalanceLines(array $opening, array $postedLines): array
    {
        $restricted = [];
        foreach ($this->ctx->db()->table('funds')->whereIn('restriction', ['restricted', 'endowment'])->get()->getResultArray() as $f) {
            $restricted[(int) $f['id']] = 0.0;
        }

        $running = $lowest = $restricted;
        foreach ([$opening, ...$postedLines] as $journalLines) {
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
                'programme_id' => $ctx->programmeId($l['programme']),
                'grant_id' => array_key_exists('grant_id', $l) ? $l['grant_id'] : $ctx->grantOfFund($l['fund_id']),
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
