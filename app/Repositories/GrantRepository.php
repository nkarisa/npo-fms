<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Prototype;
use Config\Documents;

/**
 * Grants and awards. Received is the tranches received; spent is the
 * expenditure posted to the grant; elapsed is the share of the agreement period
 * gone by today. A scheduled tranche expected within 30 days shows as due, and a
 * donor report falling due within 45 days is flagged.
 *
 * Recording an award (record()) writes it from the signed agreement in one
 * transaction: the fund it is held in — opened for it, or an existing one — its
 * budget lines, disbursement schedule, reporting calendar and conditions.
 */
final class GrantRepository extends Repository
{
    /** Why an active award without its agreement is refused (Config\Documents::$requireGrantAgreement). */
    public const NEEDS_AGREEMENT = 'Attach the signed grant agreement. An award goes live only with the agreement it is held to — the donor audit starts from it.';

    private const TRANCHE_DUE_DAYS = 30;

    /** Donor reports and next reports are flagged this far out. */
    public const REPORT_WARNING_DAYS = 45;

    /**
     * Budget lines in the administration and governance group — the indirect and
     * support costs an agreement's cap is measured against.
     */
    public const INDIRECT_PREFIX = '53';

    /** The class a fund opened for an award may be, and the ledger column each rolls up to. */
    private const FUND_CLASSES = ['Restricted' => 'restricted', 'Designated' => 'designated', 'Endowment' => 'endowment'];

    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    public function all(): array
    {
        return $this->cached('all', function () {
            $spent = array_column($this->rows(
                "SELECT l.grant_id, SUM(l.debit - l.credit) AS spent FROM {journal_lines} l JOIN {journals} j ON j.id = l.journal_id
                 JOIN {accounts} a ON a.id = l.account_id WHERE j.status IN ('posted', 'reversed') AND a.type = 'expense' AND l.grant_id IS NOT NULL
                 GROUP BY l.grant_id"
            ), 'spent', 'grant_id');

            $lineActuals = [];
            foreach ($this->rows(
                "SELECT l.grant_id, a.code, SUM(l.debit - l.credit) AS actual FROM {journal_lines} l JOIN {journals} j ON j.id = l.journal_id
                 JOIN {accounts} a ON a.id = l.account_id WHERE j.status IN ('posted', 'reversed') AND l.grant_id IS NOT NULL GROUP BY l.grant_id, a.code"
            ) as $r) {
                $lineActuals[$r['grant_id'] . ':' . $r['code']] = (float) $r['actual'];
            }

            $children = fn (string $sql) => array_reduce($this->rows($sql), static function ($out, $r) {
                $out[(int) $r['grant_id']][] = $r;

                return $out;
            }, []);
            $budgets    = $children('SELECT b.*, a.code FROM {grant_budget_lines} b JOIN {accounts} a ON a.id = b.account_id ORDER BY b.id');
            $tranches   = $children('SELECT * FROM {grant_tranches} ORDER BY id');
            $conditions = $children('SELECT * FROM {grant_conditions} ORDER BY sort_order');
            $reports    = $children('SELECT * FROM {donor_reports} ORDER BY due_on');
            $documents  = (new AttachmentRepository())->byObject('grant');

            return array_map(function ($g) use ($spent, $lineActuals, $budgets, $tranches, $conditions, $reports, $documents) {
                $id    = (int) $g['id'];
                $next  = current(array_filter($reports[$id] ?? [], static fn ($r) => in_array($r['status'], ['draft', 'in_review'], true))) ?: null;
                $days  = $next === null ? null : Clock::daysUntil($next['due_on']);
                $grantTranches = $tranches[$id] ?? [];
                $lead = $g['programme_id'] === null ? null : (int) $g['programme_id'];

                return [
                    'id'         => $id,
                    'ref'        => $g['award_ref'],
                    'title'      => $g['title'],
                    'funder'     => $g['funder_name'],
                    'program'    => $this->lookups->programmeName($lead),
                    // The other programmes the agreement lets be charged, from the funds it is held in.
                    'alsoPrograms' => array_values(array_filter(array_map(
                        fn ($p) => $this->lookups->programmeName($p),
                        array_filter($this->lookups->grantProgrammes($id), static fn ($p) => $p !== $lead)
                    ))),
                    'fund'       => $g['fund_id'] === null ? 'Not yet assigned' : $this->lookups->funds()[$g['fund_id']]['name'],
                    'fundCode'   => $g['fund_id'] === null ? null : $this->lookups->funds()[$g['fund_id']]['code'],
                    'manager'    => $this->lookups->shortName($g['manager_user_id'] === null ? null : (int) $g['manager_user_id']),
                    'period'     => $g['starts_on'] ? date('M y', strtotime($g['starts_on'])) . ' – ' . date('M y', strtotime($g['ends_on'])) : 'Proposal',
                    'start'      => self::dmy($g['starts_on']),
                    'end'        => self::dmy($g['ends_on']),
                    'elapsed'    => self::elapsed($g['starts_on'], $g['ends_on']),
                    'status'     => ucfirst($g['status']),
                    'currency'   => $g['currency'],
                    'value'      => self::num($g['value']),
                    'valueFc'    => self::num($g['value_fc']),
                    'indirectCap' => $g['indirect_cap_pct'] === null ? null : (float) $g['indirect_cap_pct'],
                    'retention'  => (int) $g['retention_years'],
                    'received'   => self::num(array_sum(array_map(static fn ($t) => $t['status'] === 'received' ? (float) $t['amount'] : 0, $grantTranches))),
                    'spent'      => self::num($spent[$id] ?? 0),
                    'nextReport' => $next === null ? '—' : ($days < 0 ? 'overdue' : self::dm($next['due_on'])),
                    'reportDays' => $days ?? 9999,
                    'budget'     => array_map(static fn ($b) => [
                        'code' => $b['code'], 'name' => $b['name'], 'budget' => self::num($b['amount']), 'actual' => self::num($lineActuals[$id . ':' . $b['code']] ?? 0),
                    ], $budgets[$id] ?? []),
                    'tranches'   => array_map(static fn ($t) => [
                        'no'     => $t['label'],
                        'date'   => self::dmy($t['expected_on'], 'on hold'),
                        'amount' => self::num($t['amount']),
                        'status' => match (true) {
                            $t['status'] === 'received' => 'Received',
                            $t['status'] === 'cancelled' => 'Cancelled',
                            $t['expected_on'] !== null && Clock::daysUntil($t['expected_on']) <= self::TRANCHE_DUE_DAYS => 'Due',
                            default => 'Scheduled',
                        },
                    ], $grantTranches),
                    'reports'    => array_map(static fn ($r) => [
                        'ref'    => $r['reference'],
                        'name'   => $r['title'],
                        'period' => DonorReportRepository::periodLabel($r['period_starts_on'], $r['period_ends_on']),
                        'due'    => self::dmy($r['due_on']),
                        'days'   => Clock::daysUntil($r['due_on']),
                        'status' => DonorReportRepository::statusLabel($r),
                        'state'  => self::reportState($r),
                    ], $reports[$id] ?? []),
                    'conditions' => array_column($conditions[$id] ?? [], 'text'),
                    'documents'  => $documents[$id] ?? [],
                ];
            }, $this->rows('SELECT g.*, f.name AS funder_name FROM {grants} g JOIN {funders} f ON f.id = g.funder_id ORDER BY g.id'));
        });
    }

    /**
     * Where a report stands on the calendar: Submitted once it has gone to the
     * donor, Queried while the donor has questions, and otherwise by its due date —
     * Overdue, Due within the warning window, or Scheduled.
     */
    private static function reportState(array $r): string
    {
        if (in_array($r['status'], ['submitted', 'accepted'], true)) {
            return 'Submitted';
        }
        if ($r['status'] === 'queried') {
            return 'Queried';
        }
        $days = Clock::daysUntil($r['due_on']);

        return $days < 0 ? 'Overdue' : ($days <= self::REPORT_WARNING_DAYS ? 'Due' : 'Scheduled');
    }

    public function find(string $ref): ?array
    {
        foreach ($this->all() as $g) {
            if ($g['ref'] === $ref) {
                return $g;
            }
        }

        return null;
    }

    /** Percentage of the agreement period elapsed today, 0–100. */
    public static function elapsed(?string $start, ?string $end): int
    {
        if (!$start || !$end) {
            return 0;
        }

        $total = max(1, strtotime($end) - strtotime($start));
        $gone  = Clock::today()->getTimestamp() - strtotime($start);

        return (int) max(0, min(100, round($gone / $total * 100)));
    }

    /**
     * Every report still owed to a donor across the portfolio, soonest first: the
     * reporting calendar. Reports on closed and pipeline awards are left out.
     *
     * @return list<array{ref: string, award: string, funder: string, name: string, period: string, due: string, days: int, state: string}>
     */
    public function calendar(): array
    {
        $out = [];
        foreach ($this->all() as $g) {
            if (in_array($g['status'], ['Closed', 'Pipeline'], true)) {
                continue;
            }
            foreach ($g['reports'] as $r) {
                if (in_array($r['state'], ['Submitted'], true)) {
                    continue;
                }
                $out[] = ['award' => $g['ref'], 'funder' => $g['funder']] + $r;
            }
        }
        usort($out, static fn ($a, $b) => [$a['days'], $a['ref']] <=> [$b['days'], $b['ref']]);

        return $out;
    }

    // ---- Recording an award ----

    /** What the award form offers: every choice it makes from, as the API will check them. */
    public function formOptions(): array
    {
        $programmes = array_values(array_filter($this->lookups->programmes(), static fn ($p) => $p['status'] !== 'inactive'));
        $managers = [];
        foreach ($this->lookups->users() as $id => $u) {
            if ($u['status'] === 'active' && !str_starts_with($this->lookups->roleOf((int) $id), 'Auditor')) {
                $managers[] = $u['short_name'];
            }
        }

        return [
            'programmes' => array_column($programmes, 'name'),
            'managers'   => $managers,
            'funders'    => array_values(array_column($this->lookups->funders(), 'name')),
            'currencies' => array_map(static fn ($c) => ['code' => $c['code'], 'rate' => (float) $c['indicative_rate']],
                $this->rows('SELECT code, indicative_rate FROM {currencies} WHERE is_active = 1 ORDER BY id')),
            'funds'      => array_map(static fn ($f) => ['code' => $f['code'], 'name' => $f['name'], 'restriction' => $f['restriction']],
                array_values(array_filter($this->lookups->funds(), static fn ($f) => $f['status'] === 'active'))),
            'accounts'   => array_values(array_map(static fn ($a) => ['code' => $a['code'], 'name' => $a['name']], array_filter(
                $this->lookups->accounts(),
                static fn ($a) => (int) $a['is_leaf'] === 1 && $a['status'] === 'active' && in_array($a['type'], ['expense', 'asset'], true)
            ))),
            'indirectPrefix' => self::INDIRECT_PREFIX,
            'refs'       => array_column($this->all(), 'ref'),
        ];
    }

    /**
     * Records an award from its signed agreement.
     *
     * Everything is checked before anything is written, and it is written in one
     * transaction: a new fund (or the existing one the award is attached to), the
     * award, its budget lines, disbursement schedule, reporting calendar and
     * conditions. The budget lines must agree with the award value and keep
     * indirect costs within the agreed cap; the disbursements must agree with the
     * value too. A pipeline award is recorded the same way — the budget is visible
     * but nothing may be committed against it until it is converted on signature.
     *
     * @param array<string, mixed> $in the award form, as grants.js sends it
     * @return array{ref: string, fund: string, fundCode: string, fundOpened: bool}
     */
    public function record(array $in, int $actorId): array
    {
        $a = $this->checkAward($in);
        $attachments = new AttachmentRepository();
        $documents = $attachments->pending($in['documents'] ?? [], $actorId);
        if ($a['status'] === 'active' && $documents === [] && config(Documents::class)->requireGrantAgreement) {
            throw new RuleViolation(self::NEEDS_AGREEMENT);
        }

        return $this->transaction(function () use ($a, $actorId, $attachments, $documents) {
            $funderId = $this->lookups->funderId($a['funder']) ?? $this->insert('funders', [
                'name' => $a['funder'], 'short_name' => mb_substr($a['funder'], 0, 60), 'created_at' => Clock::timestamp(),
            ]);
            Repository::forget();

            if ($a['fund']['mode'] === 'new') {
                $fund = (new FundRepository())->create([
                    'code' => $a['fund']['code'], 'name' => $a['fund']['name'], 'restriction' => $a['fund']['restriction'],
                    'ledgerGroup' => $a['fund']['ledgerGroup'], 'funder' => $a['funder'], 'purpose' => $a['fund']['purpose'],
                    'startsOn' => $a['start'], 'spendBy' => $a['end'], 'conditions' => implode(' ', $a['conditions']),
                ], $actorId);
                $fundId = (int) $fund['id'];
                if ($a['fund']['override'] !== '') {
                    $this->audit('settings:segments', $fundId, $fund['code'], $fund['name'] . ' is restricted but presented in the General Fund: ' . $a['fund']['override'], $actorId, 'settings.changed', $this->lookups->entityId());
                }
            } else {
                $fundId = (int) $a['fund']['id'];
            }

            // The programmes the award may be charged to are the ones its fund carries.
            $held = array_map('intval', array_column($this->rows('SELECT programme_id FROM {fund_programmes} WHERE fund_id = ?', [$fundId]), 'programme_id'));
            foreach ($a['programmeIds'] as $programmeId) {
                if (!in_array($programmeId, $held, true)) {
                    $this->db->table('fund_programmes')->insert(['fund_id' => $fundId, 'programme_id' => $programmeId]);
                }
            }

            $now = Clock::timestamp();
            $id = $this->insert('grants', [
                'entity_id' => $this->lookups->entityId(), 'funder_id' => $funderId, 'award_ref' => $a['ref'],
                'short_name' => mb_substr($a['funder'] . ' ' . $a['ref'], 0, 60), 'title' => $a['title'],
                'programme_id' => $a['programmeIds'][0], 'fund_id' => $fundId, 'manager_user_id' => $a['managerId'],
                'currency' => $a['currency'], 'value_fc' => $a['valueFc'], 'value' => $a['value'],
                'starts_on' => $a['start'], 'ends_on' => $a['end'], 'status' => $a['status'],
                'indirect_cap_pct' => $a['cap'], 'created_at' => $now,
            ]);
            $this->db->table('grant_funds')->insert(['grant_id' => $id, 'fund_id' => $fundId]);

            foreach ($a['budget'] as $b) {
                $this->insert('grant_budget_lines', ['grant_id' => $id, 'account_id' => $b['accountId'], 'name' => $b['name'], 'amount' => $b['amount'], 'created_at' => $now]);
            }
            foreach ($a['tranches'] as $t) {
                $this->insert('grant_tranches', ['grant_id' => $id, 'label' => $t['label'], 'expected_on' => $t['date'], 'amount' => $t['amount'], 'status' => 'scheduled', 'created_at' => $now]);
            }
            foreach ($a['conditions'] as $i => $text) {
                $this->insert('grant_conditions', ['grant_id' => $id, 'sort_order' => $i + 1, 'text' => $text, 'created_at' => $now]);
            }
            foreach ($a['reports'] as $r) {
                $this->insert('donor_reports', [
                    'entity_id' => $this->lookups->entityId(), 'reference' => $this->nextReportReference($r['due']), 'grant_id' => $id,
                    'title' => $r['name'], 'type' => $r['type'], 'period_starts_on' => $r['from'], 'period_ends_on' => $r['to'],
                    'due_on' => $r['due'], 'status' => 'draft', 'funds_received' => 0, 'reported_adjustment' => 0,
                    'prepared_by' => $a['managerId'], 'created_at' => $now,
                ]);
            }

            $attachments->claim($documents, 'grant', $id);
            $fund = $this->lookups->funds()[$fundId] ?? $this->row('SELECT code, name FROM {funds} WHERE id = ?', [$fundId]);
            $this->audit('grant', $id, $a['ref'], 'Award recorded as ' . strtolower(ucfirst($a['status'])) . ' from the signed agreement: KES '
                . Prototype::fmt($a['value']) . ', ' . self::dmy($a['start']) . ' – ' . self::dmy($a['end']) . ', held in ' . $fund['name'], $actorId, 'grant.recorded', $this->lookups->entityId());
            Repository::forget();

            return ['ref' => $a['ref'], 'fund' => $fund['name'], 'fundCode' => $fund['code'], 'fundOpened' => $a['fund']['mode'] === 'new'];
        });
    }

    /**
     * The award form's checks — the same the screen makes step by step — and the
     * award as it will be stored.
     */
    private function checkAward(array $in): array
    {
        $text = static fn ($k) => trim((string) ($in[$k] ?? ''));
        $num  = static fn ($v) => round((float) preg_replace('/[^0-9.]/', '', (string) $v), 2);
        $date = static function ($v): ?string {
            $v = trim((string) $v);
            $t = $v === '' ? false : strtotime($v);

            return $t === false || !preg_match('/\d{4}/', $v) ? null : date('Y-m-d', $t);
        };
        $fail = static fn (string $why) => throw new RuleViolation($why);

        // 1. The agreement
        $funder = $text('funder');
        $ref = $text('ref');
        $title = $text('title');
        $funder !== '' || $fail('Funder is required — an award cannot exist without one.');
        $ref !== '' || $fail('Award reference is required. It is the key the donor quotes in every query.');
        mb_strlen($ref) <= 40 || $fail('Keep the award reference to 40 characters.');
        $this->value('SELECT id FROM {grants} WHERE LOWER(award_ref) = LOWER(?)', [$ref]) === null || $fail('Award reference ' . $ref . ' is already in the portfolio.');
        $title !== '' || $fail('Give the award a title.');

        $programmeIds = [];
        foreach (array_merge([$text('program')], (array) ($in['alsoPrograms'] ?? [])) as $name) {
            $p = current(array_filter($this->lookups->programmes(), static fn ($p) => $p['name'] === $name && $p['status'] !== 'inactive'));
            $p !== false || $fail(($name === '' ? 'The lead programme' : $name) . ' is not a programme an award can fund.');
            $programmeIds[] = (int) $p['id'];
        }
        $programmeIds = array_values(array_unique($programmeIds));
        $managerId = $this->lookups->userId($text('manager')) ?? $fail('Choose the grant manager from the people who use the system.');

        $currency = strtoupper($text('currency') ?: 'KES');
        $this->value('SELECT id FROM {currencies} WHERE code = ? AND is_active = 1', [$currency]) !== null || $fail($currency . ' is not a currency this instance holds.');
        $value = $num($in['value'] ?? 0);
        $value > 0 || $fail('Award value must be greater than zero.');
        $rate = $currency === 'KES' ? 1.0 : (float) $num($in['rate'] ?? 0);
        $rate > 0 || $fail('Enter the agreement rate: what 1 ' . $currency . ' is worth in KES.');

        $start = $date($in['start'] ?? '');
        $end = $date($in['end'] ?? '');
        ($start !== null && $end !== null) || $fail('Enter start and end dates as day month year, e.g. 01 Oct 2026.');
        $end >= $start || $fail('The award ends before it starts.');
        $status = strtolower($text('status') ?: 'Pipeline');
        in_array($status, ['pipeline', 'active'], true) || $fail('An award is recorded as Pipeline or Active.');

        // 2. The fund
        $mode = $text('fundMode') === 'existing' ? 'existing' : 'new';
        $fund = ['mode' => $mode, 'override' => ''];
        if ($mode === 'new') {
            $cls = self::FUND_CLASSES[$text('fundCls') ?: 'Restricted'] ?? $fail('A fund opened for an award is restricted, designated or an endowment.');
            $override = !empty($in['ledgerOverride']) && $cls === 'restricted';
            $fund += [
                'name'        => $text('fundName') ?: self::suggestedFund($funder, $text('program')),
                'restriction' => $cls,
                'ledgerGroup' => $override ? 'general' : match ($cls) {
                    'endowment'  => 'endowment',
                    'designated' => 'general',
                    default      => !empty($in['capital']) ? 'capital' : 'grant',
                },
                'purpose'     => $text('purpose'),
                'code'        => $this->nextFundCode(),
            ];
            $fund['purpose'] !== '' || $fail('State the fund\'s purpose — it is what defines eligible spending.');
            if ($override) {
                $fund['override'] = $text('overrideReason');
                $fund['override'] !== '' || $fail('Presenting restricted money in the General Fund overstates free reserves. Record why the agreement carries no restriction before continuing.');
            }
        } else {
            $existing = current(array_filter($this->lookups->funds(), static fn ($f) => $f['code'] === ($in['fundExisting'] ?? '') && $f['status'] === 'active'));
            $existing !== false || $fail('Choose the fund the award is held in.');
            $fund['id'] = (int) $existing['id'];
        }

        // 3. Budget lines
        $budget = [];
        $accounts = $this->lookups->accounts();
        foreach ((array) ($in['budget'] ?? []) as $b) {
            $account = $accounts[(string) ($b['code'] ?? '')] ?? null;
            ($account !== null && (int) $account['is_leaf'] === 1 && in_array($account['type'], ['expense', 'asset'], true))
                || $fail(($b['code'] ?? '') . ' is not an expense or asset account an award can budget for.');
            !isset($budget[$account['code']]) || $fail($account['code'] . ' ' . $account['name'] . ' is budgeted twice. Put each account on one line.');
            $amount = $num($b['amount'] ?? 0);
            $amount > 0 || $fail('Every budget line needs an amount.');
            $budget[$account['code']] = ['accountId' => (int) $account['id'], 'name' => $account['name'], 'amount' => $amount];
        }
        $budget !== [] || $fail('Add at least one budget line.');
        $budgetTotal = array_sum(array_column($budget, 'amount'));
        abs($budgetTotal - $value) < 0.005 || $fail('Budget lines total ' . Prototype::fmt($budgetTotal) . ' against an award value of ' . Prototype::fmt($value) . '. They must agree before the award can be recorded.');
        $cap = trim((string) ($in['indirectCap'] ?? '')) === '' ? null : (float) $num($in['indirectCap']);
        ($cap === null || $cap <= 100) || $fail('The indirect cost cap is a percentage of the award, at most 100.');
        $indirect = array_sum(array_map(static fn ($code, $b) => str_starts_with((string) $code, self::INDIRECT_PREFIX) ? $b['amount'] : 0, array_keys($budget), $budget));
        if ($cap !== null && $cap > 0 && $indirect > round($value * $cap / 100) + 0.005) {
            $fail('Indirect cost recovery of ' . Prototype::fmt($indirect) . ' exceeds the agreed cap of ' . rtrim(rtrim(number_format($cap, 2), '0'), '.') . '% (' . Prototype::fmt(round($value * $cap / 100)) . ').');
        }

        // 4. Disbursements
        $tranches = [];
        foreach ((array) ($in['tranches'] ?? []) as $i => $t) {
            $when = $date($t['date'] ?? '') ?? $fail('Every disbursement needs an expected date, e.g. 15 Nov 2026.');
            $amount = $num($t['amount'] ?? 0);
            $amount > 0 || $fail('Every disbursement needs an amount.');
            $tranches[] = ['label' => mb_substr(trim((string) ($t['no'] ?? '')) ?: 'Tranche ' . ($i + 1), 0, 40), 'date' => $when, 'amount' => $amount];
        }
        $tranches !== [] || $fail('Add at least one disbursement.');
        $trancheTotal = array_sum(array_column($tranches, 'amount'));
        abs($trancheTotal - $value) < 0.005 || $fail('Disbursements total ' . Prototype::fmt($trancheTotal) . ' against an award value of ' . Prototype::fmt($value) . '. They must agree.');

        // 5. Reporting
        $reports = [];
        foreach ((array) ($in['reports'] ?? []) as $r) {
            $name = trim((string) ($r['name'] ?? ''));
            $due = $date($r['due'] ?? '');
            ($name !== '' && $due !== null) || $fail('Every report needs a name and a due date.');
            $from = $date($r['from'] ?? '') ?? $start;
            $to = $date($r['to'] ?? '') ?? min($due, $end);
            $to >= $from || $fail($name . ' covers a period that ends before it starts.');
            $reports[] = [
                'name' => mb_substr($name, 0, 255), 'from' => $from, 'to' => $to, 'due' => $due,
                'type' => match (true) {
                    (bool) preg_match('/close.?out/i', $name) => 'close_out',
                    (bool) preg_match('/narrative/i', $name)  => 'narrative',
                    default                                   => 'financial',
                },
            ];
        }
        $reports !== [] || $fail('An award with no reporting calendar gets missed. Add at least one report.');

        // 6. Conditions
        $conditions = array_values(array_filter(array_map(static fn ($c) => trim((string) $c), (array) ($in['conditions'] ?? []))));
        $conditions !== [] || $fail('Record at least one condition from the signed agreement.');

        return [
            'funder' => $funder, 'ref' => $ref, 'title' => $title, 'programmeIds' => $programmeIds, 'managerId' => $managerId,
            'currency' => $currency, 'value' => $value, 'valueFc' => round($value / $rate, 2), 'start' => $start, 'end' => $end,
            'status' => $status, 'fund' => $fund, 'budget' => array_values($budget), 'cap' => $cap, 'tranches' => $tranches,
            'reports' => $reports, 'conditions' => $conditions,
        ];
    }

    /** The fund name the form offers when none is typed: "Embassy of Sweden Civic Education Fund". */
    public static function suggestedFund(string $funder, string $programme): string
    {
        return trim(($funder !== '' ? $funder : 'New funder') . ' ' . $programme . ' Fund');
    }

    /** The next fund code in the FND series: ten past the highest in use. */
    public function nextFundCode(): string
    {
        $highest = 100;
        foreach ($this->rows('SELECT code FROM {funds}') as $f) {
            $highest = max($highest, (int) preg_replace('/\D/', '', $f['code']));
        }

        return 'FND-' . ($highest + 10);
    }

    /** DR-26-019: the donor report series for the year the report falls due. */
    private function nextReportReference(string $due): string
    {
        $prefix = 'DR-' . substr($due, 2, 2) . '-';
        $last = 0;
        foreach ($this->rows('SELECT reference FROM {donor_reports} WHERE reference LIKE ?', [$prefix . '%']) as $r) {
            $last = max($last, (int) substr($r['reference'], strlen($prefix)));
        }

        return $prefix . str_pad((string) ($last + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Converts a pipeline award on signature: it becomes active, and from then on
     * its budget may be committed against. It must have its period, and its budget
     * lines and disbursements must still agree with its value.
     */
    public function activate(string $ref, int $actorId, mixed $documentIds = []): array
    {
        $g = $this->find($ref) ?? throw new RuleViolation($ref . ' is not an award.');
        if ($g['status'] !== 'Pipeline') {
            throw new RuleViolation($ref . ' is ' . strtolower($g['status']) . '. Only a pipeline award is converted on signature.');
        }
        if ($g['start'] === '—' || $g['end'] === '—') {
            throw new RuleViolation('Record the agreement period before converting ' . $ref . ' — it is what burn is measured against.');
        }
        foreach (['budget' => ['budget', 'Budget lines'], 'tranches' => ['amount', 'Disbursements']] as $key => [$field, $label]) {
            $total = array_sum(array_column($g[$key], $field));
            if (abs($total - $g['value']) >= 0.005) {
                throw new RuleViolation($label . ' total ' . Prototype::fmt($total) . ' against an award value of ' . Prototype::fmt($g['value']) . '. They must agree before the award goes live.');
            }
        }
        $attachments = new AttachmentRepository();
        $documents = $attachments->pending($documentIds, $actorId);
        if ($g['documents'] === [] && $documents === [] && config(Documents::class)->requireGrantAgreement) {
            throw new RuleViolation(self::NEEDS_AGREEMENT);
        }

        $this->transaction(function () use ($g, $actorId, $attachments, $documents) {
            $attachments->claim($documents, 'grant', (int) $g['id']);
            $this->db->table('grants')->where('id', $g['id'])->update(['status' => 'active', 'updated_at' => Clock::timestamp()]);
            $this->audit('grant', $g['id'], $g['ref'], 'Converted from pipeline to active on signature of the agreement', $actorId, 'grant.activated', $this->lookups->entityId());
        });
        Repository::forget();

        return $this->find($ref);
    }
}
