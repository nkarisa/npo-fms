<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Prototype;

/**
 * The fund register with each fund's movement for the working year from the
 * posted ledger — the statement of changes in funds, one row a fund:
 *
 *     opening + income − expenditure + transfers = closing
 *
 * Opening is everything a fund held before the year began, plus the year's
 * balances brought forward (the opening-balance journal posts to the fund balance
 * accounts inside the first month). Transfers are the fund balance movements of
 * the entries raised from `fund_transfers`, and of their reversals. The closing
 * balance is the fund's net assets.
 */
final class FundRepository extends Repository
{
    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    /** What a fund may be, and the column of the ledger each rolls up to. */
    public const RESTRICTIONS = ['unrestricted', 'restricted', 'designated', 'endowment'];

    public const LEDGER_GROUPS = ['general', 'grant', 'capital', 'endowment'];

    /**
     * The fund balance account each restriction's balance is carried in. A board
     * designation is not a donor restriction: it is unrestricted money set aside.
     */
    private const BALANCE_RESTRICTION = ['unrestricted' => 'unrestricted', 'designated' => 'unrestricted', 'restricted' => 'restricted', 'endowment' => 'endowment'];

    /**
     * Opens a fund.
     *
     * A fund is the first coding every posting carries, so an instance cannot post
     * anything until it has at least one. What it may be charged with follows from
     * its restriction and the ledger column it rolls up to, and the two have to
     * agree: an endowment is reported as one and rolls up as one, and money held for
     * a donor belongs in the grant or capital column where awards are reported.
     *
     * @param array{code: string, name: string, restriction: string, ledgerGroup: string,
     *              funder?: string, purpose?: string, deedRef?: string, startsOn?: string,
     *              spendBy?: string, conditions?: string} $input
     */
    public function create(array $input, int $actorId): array
    {
        $code = mb_strtoupper(trim((string) ($input['code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $restriction = mb_strtolower(trim((string) ($input['restriction'] ?? '')));
        $group = mb_strtolower(trim((string) ($input['ledgerGroup'] ?? '')));

        if (preg_match('/^[A-Z0-9][A-Z0-9-]{1,19}$/', $code) !== 1) {
            throw new RuleViolation('A fund code is 2 to 20 letters, digits and hyphens, e.g. FND-100.');
        }
        if ($name === '') {
            throw new RuleViolation('Give the fund a name. It is what the statements and every donor report call it.');
        }
        if (!in_array($restriction, self::RESTRICTIONS, true)) {
            throw new RuleViolation('A fund is ' . self::list(self::RESTRICTIONS) . ', not "' . $restriction . '".');
        }
        if (!in_array($group, self::LEDGER_GROUPS, true)) {
            throw new RuleViolation('A fund rolls up to the ' . self::list(self::LEDGER_GROUPS) . ' column, not "' . $group . '".');
        }
        if (($restriction === 'endowment') !== ($group === 'endowment')) {
            throw new RuleViolation('An endowment is reported as one and rolls up to the endowment column. Set both, or neither.');
        }
        if ($restriction === 'unrestricted' && in_array($group, ['grant', 'capital'], true)) {
            throw new RuleViolation('The grant and capital columns report money held for a donor, so a fund in them cannot be unrestricted.');
        }
        foreach (['code' => $code, 'name' => $name] as $column => $value) {
            if ($this->value('SELECT id FROM {funds} WHERE LOWER(' . $column . ') = LOWER(?)', [$value]) !== null) {
                throw new RuleViolation('A fund with that ' . $column . ' already exists. Every fund is named once.');
            }
        }

        $funderId = null;
        if (trim((string) ($input['funder'] ?? '')) !== '') {
            $funderId = $this->lookups->funderId(trim((string) $input['funder']))
                ?? throw new RuleViolation(trim((string) $input['funder']) . ' is not on the funder register.');
        }

        $this->transaction(function () use ($code, $name, $restriction, $group, $funderId, $input, $actorId) {
            $id = $this->insert('funds', [
                'code' => $code, 'name' => $name, 'restriction' => $restriction, 'ledger_group' => $group,
                'funder_id' => $funderId, 'purpose' => trim((string) ($input['purpose'] ?? '')) ?: null,
                'deed_ref' => trim((string) ($input['deedRef'] ?? '')) ?: null,
                'starts_on' => self::dateOrNull($input['startsOn'] ?? null),
                'spend_by' => self::dateOrNull($input['spendBy'] ?? null),
                'conditions' => trim((string) ($input['conditions'] ?? '')) ?: null,
                'status' => 'active', 'created_at' => Clock::timestamp(),
            ]);
            $this->audit('settings:segments', $id, $code, $code . ' ' . $name . ' opened as a ' . $restriction
                . ' fund in the ' . $group . ' column', $actorId, 'settings.changed', $this->lookups->headOfficeId());
        });

        return $this->find($code) ?? ['code' => $code, 'name' => $name];
    }

    private static function dateOrNull(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : date('Y-m-d', strtotime($text) ?: time());
    }

    private static function list(array $items): string
    {
        return implode(', ', array_slice($items, 0, -1)) . ' or ' . end($items);
    }

    /** The working year the movement is measured over: its code and first day, or null before one exists. */
    public function year(): ?array
    {
        return $this->cached('year', function () {
            $periods = $this->lookups->yearPeriods();
            if ($periods === []) {
                return null;
            }
            $year = $this->row('SELECT code, starts_on FROM {fiscal_years} WHERE id = ?', [$periods[0]['fiscal_year_id']]);

            return ['id' => (int) $periods[0]['fiscal_year_id'], 'code' => $year['code'], 'startsOn' => $year['starts_on']];
        });
    }

    public function all(): array
    {
        return $this->cached('all', function () {
            $movements = $this->movements();
            $programmes = [];
            foreach ($this->rows('SELECT fp.fund_id, p.name FROM {fund_programmes} fp JOIN {programmes} p ON p.id = fp.programme_id ORDER BY p.code') as $r) {
                $programmes[(int) $r['fund_id']][] = $r['name'];
            }

            return array_map(function ($f) use ($movements, $programmes) {
                $id    = (int) $f['id'];
                $m     = $movements[$id] ?? ['opening' => 0, 'income' => 0, 'expenditure' => 0, 'transfers' => 0];
                $grant = $this->grantHeld($id);

                return [
                    'id'         => $id,
                    'code'       => $f['code'],
                    'name'       => $f['name'],
                    // The screen's three classes: a board designation is unrestricted money.
                    'cls'        => $f['restriction'] === 'designated' ? 'Unrestricted' : ucfirst($f['restriction']),
                    'restriction' => $f['restriction'],
                    'ledgerGroup' => $f['ledger_group'],
                    'funder'     => $f['funder_name'] ?? 'Own income',
                    'grant'      => $f['deed_ref'] ?? ($grant === null ? '—' : $this->lookups->grants()[$grant]['short_name']),
                    'purpose'    => $f['purpose'] ?? '',
                    'period'     => $f['starts_on'] && $f['spend_by'] ? self::dmy($f['starts_on']) . ' – ' . self::dmy($f['spend_by']) : 'Perpetual',
                    'spendBy'    => self::dmy($f['spend_by']),
                    'daysLeft'   => Clock::daysUntil($f['spend_by']) ?? 9999,
                    'conditions' => $f['conditions'] ?? '',
                    'programs'   => $programmes[$id] ?? [],
                    'ledgerFund' => self::FUND_GROUPS[$f['ledger_group']],
                    'opening'    => self::num($m['opening']),
                    'income'     => self::num($m['income']),
                    'spend'      => self::num($m['expenditure']),
                    'transfers'  => self::num($m['transfers']),
                ];
            }, $this->rows('SELECT f.*, fu.name AS funder_name FROM {funds} f LEFT JOIN {funders} fu ON fu.id = f.funder_id ORDER BY f.code'));
        });
    }

    /**
     * Each fund's movement for the working year, by fund id.
     *
     * A line counts once: before the year it is opening whatever its account; in
     * the year a fund balance line is a transfer when its entry was raised from a
     * transfer (or reverses one) and opening otherwise, and income and expenditure
     * lines are the year's. Later years are not the working year's business.
     *
     * @return array<int, array{opening: float, income: float, expenditure: float, transfers: float}>
     */
    private function movements(): array
    {
        $year = $this->year();
        if ($year === null) {
            return [];
        }

        // COALESCE: most entries reverse nothing, and NOT (false OR NULL) is NULL, not true.
        $transfer = "(COALESCE(j.source_type, '') = 'fund_transfer' OR COALESCE(r.source_type, '') = 'fund_transfer')";
        $net = "CASE WHEN a.type IN ('equity', 'income', 'expense') THEN l.credit - l.debit ELSE 0 END";
        $rows = $this->rows(
            'SELECT l.fund_id,'
            . "  SUM(CASE WHEN p.starts_on < ? THEN {$net}"
            . "           WHEN fy.code = ? AND a.type = 'equity' AND NOT {$transfer} THEN l.credit - l.debit ELSE 0 END) AS opening,"
            . "  SUM(CASE WHEN fy.code = ? AND a.type = 'income' THEN l.credit - l.debit ELSE 0 END) AS income,"
            . "  SUM(CASE WHEN fy.code = ? AND a.type = 'expense' THEN l.debit - l.credit ELSE 0 END) AS expenditure,"
            . "  SUM(CASE WHEN fy.code = ? AND a.type = 'equity' AND {$transfer} THEN l.credit - l.debit ELSE 0 END) AS transfers"
            . ' FROM {journal_lines} l'
            . ' JOIN {journals} j ON j.id = l.journal_id'
            . ' LEFT JOIN {journals} r ON r.id = j.reverses_journal_id'
            . ' JOIN {periods} p ON p.id = j.period_id'
            // By the year's code, not its id: in the consolidated view each entity's journals sit in its own copy of the year.
            . ' JOIN {fiscal_years} fy ON fy.id = p.fiscal_year_id'
            . ' JOIN {accounts} a ON a.id = l.account_id'
            . " WHERE j.status IN ('posted', 'reversed')"
            . ' GROUP BY l.fund_id',
            [$year['startsOn'], $year['code'], $year['code'], $year['code'], $year['code']]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['fund_id']] = [
                'opening' => (float) $r['opening'], 'income' => (float) $r['income'],
                'expenditure' => (float) $r['expenditure'], 'transfers' => (float) $r['transfers'],
            ];
        }

        return $out;
    }

    public function find(string $code): ?array
    {
        foreach ($this->all() as $f) {
            if ($f['code'] === $code) {
                return $f;
            }
        }

        return null;
    }

    public static function closing(array $f): float
    {
        return $f['opening'] + $f['income'] - $f['spend'] + $f['transfers'];
    }

    /** What the fund has had to spend this year: its opening balance, income and any transfer in. */
    public static function available(array $f): float
    {
        return $f['opening'] + $f['income'] + max(0.0, $f['transfers']);
    }

    public static function utilisation(array $f): int
    {
        $a = self::available($f);

        return $a > 0 ? (int) min(100, round($f['spend'] / $a * 100)) : 0;
    }

    /**
     * The ledger accounts carrying this fund's movement in the working year, the
     * largest first, each at its balance on its normal side.
     *
     * @return list<array{code: string, name: string, balance: float}>
     */
    public function accounts(string $code, int $limit = 6): array
    {
        $fund = $this->find($code);
        $year = $this->year();
        if ($fund === null || $year === null) {
            return [];
        }

        $rows = $this->rows(
            'SELECT a.code, a.name, a.type, SUM(l.debit - l.credit) AS net'
            . ' FROM {journal_lines} l'
            . ' JOIN {journals} j ON j.id = l.journal_id'
            . ' JOIN {periods} p ON p.id = j.period_id'
            . ' JOIN {fiscal_years} fy ON fy.id = p.fiscal_year_id'
            . ' JOIN {accounts} a ON a.id = l.account_id'
            . " WHERE j.status IN ('posted', 'reversed') AND l.fund_id = ? AND fy.code = ?"
            . ' GROUP BY a.code, a.name, a.type',
            [$fund['id'], $year['code']]
        );

        $out = [];
        foreach ($rows as $r) {
            $net = (float) $r['net'];
            if (round($net, 2) == 0) {
                continue;
            }
            // On its normal side, as the chart shows it — a contra account's included.
            $out[] = ['code' => $r['code'], 'name' => $r['name'], 'balance' => ChartRepository::normal($r) === 'Debit' ? $net : -$net];
        }
        usort($out, static fn ($a, $b) => [abs($b['balance']), $a['code']] <=> [abs($a['balance']), $b['code']]);

        return array_slice($out, 0, $limit);
    }

    // ---- Inter-fund transfers ----

    /**
     * Transfers raised and not yet posted — awaiting approval, or returned to the
     * preparer — oldest first.
     *
     * @return list<array{ref: string, status: string, from: string, to: string, amount: float, minute: string}>
     */
    public function openTransfers(): array
    {
        return array_map(static fn ($t) => [
            'ref' => $t['reference'], 'status' => self::label($t['status']), 'from' => $t['from_name'], 'to' => $t['to_name'],
            'amount' => (float) $t['amount'], 'minute' => $t['board_minute'],
        ], $this->rows(
            'SELECT j.reference, j.status, ff.name AS from_name, tf.name AS to_name, t.amount, t.board_minute'
            . ' FROM {fund_transfers} t JOIN {journals} j ON j.id = t.journal_id'
            . ' JOIN {funds} ff ON ff.id = t.from_fund_id JOIN {funds} tf ON tf.id = t.to_fund_id'
            . " WHERE j.status IN ('draft', 'pending_approval') ORDER BY t.id"
        ));
    }

    /**
     * Why a transfer cannot be made, or null when it can. The same rules the
     * screen shows before the transfer is raised.
     *
     * A donor-restricted fund can never be transferred out of — that is spending a
     * donor's money on something they did not agree to — and an endowment's capital
     * is permanently maintained. Nothing may take a fund below zero, counting what
     * is already on its way out and awaiting approval.
     */
    public function transferRefusal(?array $from, ?array $to, float $amount): ?string
    {
        if ($from === null || $to === null) {
            return 'Choose the fund the money leaves and the fund it goes to.';
        }
        if ($from['code'] === $to['code']) {
            return 'Source and destination funds must differ.';
        }
        if ($from['restriction'] === 'restricted') {
            return $from['name'] . ' is donor-restricted. Funds cannot be transferred out without written consent from ' . $from['funder']
                . ' — record the consent as a grant amendment first.';
        }
        if ($from['restriction'] === 'endowment') {
            return 'Endowment capital is permanently maintained. Only realised investment income may be released, through the General Fund.';
        }
        if ($amount > 0 && $amount > $this->transferable($from) + 0.005) {
            $pending = self::closing($from) - $this->transferable($from);

            return 'Transfer exceeds the available balance of ' . self::fmt($this->transferable($from)) . ' in ' . $from['name']
                . ($pending > 0.005 ? ', after ' . self::fmt($pending) . ' already awaiting approval' : '') . '.';
        }

        return null;
    }

    /** A fund's closing balance less transfers out of it that are raised but not yet posted. */
    public function transferable(array $fund): float
    {
        $pending = (float) $this->value(
            "SELECT COALESCE(SUM(t.amount), 0) FROM {fund_transfers} t JOIN {journals} j ON j.id = t.journal_id"
            . " WHERE t.from_fund_id = ? AND j.status IN ('draft', 'pending_approval')",
            [$fund['id']]
        );

        return self::closing($fund) - $pending;
    }

    /**
     * Raises an inter-fund transfer and sends its journal for approval.
     *
     * The journal moves the balance and the money behind it: each fund's balance
     * account (Dr the giving fund, Cr the receiving one) and the bank the cash is
     * held in (Cr the giving fund, Dr the receiving one) — the bank's balance does
     * not move, only whose it is. Every line carries its own fund's programme and,
     * in a fund awards are held in, its award. Nothing reaches the ledger until the
     * approver named by the inter-fund transfer rule posts it.
     *
     * @param array{from: string, to: string, amount: float|string, minute: string, reason?: string} $input fund codes
     * @return array{ref: string, approver: string}
     */
    public function transfer(array $input, int $actorId): array
    {
        $from   = $this->find((string) ($input['from'] ?? ''));
        $to     = $this->find((string) ($input['to'] ?? ''));
        $amount = round((float) preg_replace('/[^0-9.]/', '', (string) ($input['amount'] ?? '')), 2);
        $minute = trim((string) ($input['minute'] ?? ''));
        $reason = trim((string) ($input['reason'] ?? ''));

        if ($refusal = $this->transferRefusal($from, $to, $amount)) {
            throw new RuleViolation($refusal);
        }
        if ($amount <= 0) {
            throw new RuleViolation('Enter an amount to transfer.');
        }
        if ($minute === '') {
            throw new RuleViolation('A board minute reference is required for inter-fund transfers.');
        }
        if (mb_strlen($minute) > 40) {
            throw new RuleViolation('Keep the board minute reference to 40 characters, e.g. BM/2026/08/04.');
        }

        $bank = $this->transferBank();
        $lines = [
            $this->line($this->balanceAccount($from), $from, 'Transfer out — to ' . $to['name'], $amount, 0),
            $this->line($bank, $from, 'Transfer out — ' . $to['name'], 0, $amount),
            $this->line($bank, $to, 'Transfer in — ' . $from['name'], $amount, 0),
            $this->line($this->balanceAccount($to), $to, 'Transfer in — from ' . $from['name'], 0, $amount),
        ];

        return $this->transaction(function () use ($from, $to, $amount, $minute, $reason, $lines, $actorId) {
            $id = $this->insert('fund_transfers', [
                'entity_id' => $this->lookups->entityId(), 'from_fund_id' => $from['id'], 'to_fund_id' => $to['id'],
                'amount' => $amount, 'board_minute' => $minute, 'reason' => $reason !== '' ? $reason : null,
                'requested_by' => $actorId, 'created_at' => Clock::timestamp(),
            ]);

            [$journalId, $ref] = (new JournalRepository())->submitFromSource([
                'date' => Clock::date(), 'type' => 'adjustment',
                'narration' => 'Transfer from ' . $from['name'] . ' to ' . $to['name'],
                'memo' => 'Inter-fund transfer authorised by board minute ' . $minute . ($reason !== '' ? ' — ' . $reason : '') . '.',
                'sourceType' => 'fund_transfer', 'sourceId' => $id, 'docRef' => $minute, 'series' => 'JV',
            ], $lines, $actorId, 'Raised from Funds: KES ' . self::fmt($amount) . ' from ' . $from['name'] . ' to ' . $to['name'] . ' on board minute ' . $minute);

            $this->db->table('fund_transfers')->where('id', $id)->update(['journal_id' => $journalId]);

            return ['ref' => $ref, 'approver' => (new ApprovalPolicy())->rule('transfer')['approver'] ?? 'approver'];
        });
    }

    /** The fund balance account a fund's balance is carried in, by its restriction. */
    private function balanceAccount(array $fund): string
    {
        $restriction = self::BALANCE_RESTRICTION[$fund['restriction']];
        $code = $this->value(
            "SELECT code FROM {accounts} WHERE type = 'equity' AND is_leaf = 1 AND status = 'active' AND restriction = ? AND code <> ? ORDER BY code",
            [$restriction, ChartRepository::DERIVED_SURPLUS]
        );

        return $code ?? throw new RuleViolation('The chart has no postable ' . $restriction . ' fund balance account to carry ' . $fund['name']
            . ' in. Add one under the funds and reserves heading in Chart of accounts first.');
    }

    /** The head office's operating bank account, where a fund's cash is held. */
    private function transferBank(): string
    {
        foreach ($this->lookups->bankAccounts() as $b) {
            if ($b['kind'] === 'bank' && $b['currency'] === 'KES' && (int) $b['entity_id'] === $this->lookups->entityId()) {
                return (string) $b['code'];
            }
        }

        throw new RuleViolation('A transfer moves the cash a fund holds as well as its balance, and there is no KES bank account to hold it in. '
            . 'Open one under Settings → Bank statements first.');
    }

    /** One transfer line, coded to the fund's first programme and, where the fund holds one, its award. */
    private function line(string $code, array $fund, string $desc, float $dr, float $cr): array
    {
        $grant = $this->grantHeld($fund['id']);
        $allowed = $grant === null ? [] : array_map(fn ($id) => $this->lookups->programmeName($id), $this->lookups->grantProgrammes($grant));
        $programme = array_values(array_intersect($fund['programs'], $allowed))[0] ?? $fund['programs'][0] ?? null;
        $programmeId = $programme === null ? null : $this->lookups->programmeId($programme);
        if ($programmeId === null) {
            throw new RuleViolation($fund['name'] . ' has no programme to code the transfer to. Every posting carries one: link the fund to a programme first.');
        }

        return ['code' => $code, 'fund_id' => $fund['id'], 'programme_id' => $programmeId, 'grant_id' => $grant, 'desc' => $desc, 'dr' => $dr, 'cr' => $cr];
    }

    /** The award a fund holds, where exactly one award is charged to it. */
    private function grantHeld(int $fundId): ?int
    {
        if ($grant = $this->lookups->grantOfFund($fundId)) {
            return $grant;
        }
        $held = array_keys(array_filter($this->lookups->grantFunds(), static fn ($funds) => in_array($fundId, $funds, true)));

        return count($held) === 1 ? (int) $held[0] : null;
    }

    /** An amount as the screens show it, in the functional currency: brackets for negative. */
    private static function fmt(float $n): string
    {
        return Prototype::fmt($n);
    }
}
