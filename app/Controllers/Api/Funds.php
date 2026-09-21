<?php

namespace App\Controllers\Api;

use App\Libraries\Brand;
use App\Libraries\Ledger;
use App\Libraries\Prototype;
use App\Repositories\ApprovalPolicy;
use App\Repositories\FundRepository;
use App\Repositories\RuleViolation;
use App\Repositories\SettingsRepository;

/**
 * Funds (v5): the statement of changes in funds for the working year, one fund's
 * movement, restriction terms and ledger accounts, inter-fund transfers, and the
 * statement exported for the board.
 */
class Funds extends BaseApiController
{
    private const CLASSES = ['All', 'Unrestricted', 'Restricted', 'Endowment'];

    public static function closing(array $f): float
    {
        return FundRepository::closing($f);
    }

    public static function available(array $f): float
    {
        return FundRepository::available($f);
    }

    public static function pct(array $f): int
    {
        return FundRepository::utilisation($f);
    }

    public function index()
    {
        $repo = new FundRepository();
        $all  = $repo->all();
        $cls  = in_array($this->request->getGet('class'), self::CLASSES, true) ? $this->request->getGet('class') : 'All';
        $q    = strtolower(trim($this->request->getGet('q') ?? ''));

        $filtered = array_values(array_filter($all, function ($f) use ($cls, $q) {
            if ($cls !== 'All' && $f['cls'] !== $cls) {
                return false;
            }

            return $q === '' || str_contains(strtolower($f['name'] . ' ' . $f['funder'] . ' ' . $f['grant'] . ' ' . $f['code']), $q);
        }));

        $sum = static fn (array $funds, callable $of) => array_sum(array_map($of, $funds));
        $byCls = fn ($c) => $sum(array_filter($all, fn ($f) => $f['cls'] === $c), [self::class, 'closing']);
        $expiring = array_values(array_filter($all, fn ($f) => $f['daysLeft'] <= 90 && $f['cls'] === 'Restricted'));
        $restricted = array_filter($all, fn ($f) => $f['cls'] === 'Restricted');
        $restrictedPct = (int) round($sum($restricted, fn ($f) => $f['spend']) / max(1, $sum($restricted, [self::class, 'available'])) * 100);
        $open = $repo->openTransfers();
        $year = $repo->year();

        return $this->json([
            'year'  => $year['code'] ?? '',
            'rows'  => array_map(fn ($f) => $this->row($f), $filtered),
            'total' => count($all),
            'tabs'  => array_map(fn ($c) => [
                'key'   => $c,
                'label' => ($c === 'All' ? 'All funds' : $c) . ' (' . ($c === 'All' ? count($all) : count(array_filter($all, fn ($f) => $f['cls'] === $c))) . ')',
            ], self::CLASSES),
            'totals' => [
                'opening'   => Prototype::fmt($sum($filtered, fn ($f) => $f['opening'])),
                'income'    => Prototype::fmt($sum($filtered, fn ($f) => $f['income'])),
                'spend'     => Prototype::fmt($sum($filtered, fn ($f) => $f['spend'])),
                'transfers' => Prototype::fmt($sum($filtered, fn ($f) => $f['transfers'])),
                'closing'   => Prototype::fmt($sum($filtered, [self::class, 'closing'])),
            ],
            'hint'   => $expiring ? count($expiring) . (count($expiring) === 1 ? ' restricted fund closes' : ' restricted funds close') . ' within 90 days' : 'No funds closing soon',
            'footer' => count($filtered) . ' of ' . count($all) . ' funds · restricted utilisation ' . $restrictedPct . '%'
                . ($open ? ' · ' . count($open) . (count($open) === 1 ? ' transfer' : ' transfers') . ' not yet posted' : ''),
            'stats'  => [
                ['label' => 'Total fund balance', 'value' => Prototype::fmt($sum($all, [self::class, 'closing'])), 'note' => count($all) . ' funds'],
                ['label' => 'Unrestricted', 'value' => Prototype::fmt($byCls('Unrestricted')), 'note' => 'free for core costs'],
                ['label' => 'Restricted', 'value' => Prototype::fmt($byCls('Restricted')), 'note' => $restrictedPct . '% utilised'],
                ['label' => 'Endowment', 'value' => Prototype::fmt($byCls('Endowment')), 'note' => 'permanently maintained'],
                ['label' => 'Closing within 90 days', 'value' => Prototype::fmt($sum($expiring, fn ($f) => self::available($f) - $f['spend'])),
                    'note' => count($expiring) . (count($expiring) === 1 ? ' fund' : ' funds') . ' at risk of return'],
            ],
            'openTransfers' => array_map(static fn ($t) => $t + ['amountFmt' => Prototype::fmt($t['amount'])], $open),
            'transfer' => $this->transferForm($repo, $all),
        ]);
    }

    /** A register row: the figures formatted, the class and the utilisation bar. */
    private function row(array $f): array
    {
        $pct = self::pct($f);

        return [
            'code' => $f['code'], 'name' => $f['name'], 'purpose' => $f['purpose'], 'cls' => $f['cls'], 'funder' => $f['funder'],
            'opening' => Prototype::fmt($f['opening']), 'income' => Prototype::fmt($f['income']), 'spend' => Prototype::fmt($f['spend']),
            'transfers' => round($f['transfers'], 2) == 0 ? '—' : Prototype::fmt($f['transfers']),
            'closing' => Prototype::fmt(self::closing($f)), 'overdrawn' => self::closing($f) < -0.005,
            'pct' => $pct, 'barColour' => Ledger::barColour($pct),
            'spendBy' => $f['spendBy'], 'expiringSoon' => $f['daysLeft'] <= 90,
        ];
    }

    /**
     * What the transfer form offers and checks as the user types: every fund with
     * what may leave it, and who may raise a transfer. The API applies the same
     * rules again when the transfer is posted.
     */
    private function transferForm(FundRepository $repo, array $all): array
    {
        $actor = $this->actor();
        $general = current(array_filter($all, fn ($f) => $f['ledgerGroup'] === 'general' && $f['restriction'] === 'unrestricted'));

        return [
            'canRaise' => in_array('journal.prepare', $actor['permissions'] ?? [], true),
            'role'     => $actor['role'] ?? '',
            'approver' => (new ApprovalPolicy())->rule('transfer')['approver'] ?? 'approver',
            'from'     => $general['code'] ?? ($all[0]['code'] ?? ''),
            'to'       => current(array_filter($all, fn ($f) => $f['ledgerGroup'] === 'capital'))['code'] ?? ($all[1]['code'] ?? ''),
            'funds'    => array_map(fn ($f) => [
                'code' => $f['code'], 'name' => $f['name'], 'restriction' => $f['restriction'], 'funder' => $f['funder'],
                'transferable' => round($repo->transferable($f), 2), 'pending' => round(self::closing($f) - $repo->transferable($f), 2),
            ], $all),
        ];
    }

    /**
     * Opens a fund. Body: {code, name, restriction, ledgerGroup, funder, purpose,
     * deedRef, startsOn, spendBy, conditions}. Finance Manager only — a fund is the
     * first coding every posting carries.
     */
    public function create()
    {
        $actor = $this->actor();
        if (!in_array('settings.manage', $actor['permissions'] ?? [], true)) {
            return $this->response->setStatusCode(403)->setJSON([
                'error' => $actor['role'] . ' cannot open a fund. Only the Finance Manager can — every posting is coded to one.',
            ]);
        }

        try {
            $fund = (new FundRepository())->create($this->request->getJSON(true) ?? [], $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        // The register as Settings lists it, so the screen has no second call to make.
        return $this->json([
            'message' => $fund['code'] . ' ' . $fund['name'] . ' is open. Postings can be coded to it now.',
            'fund'    => $fund,
            'funds'   => (new SettingsRepository())->funds(),
        ]);
    }

    /** One fund for the drawer: its movement, utilisation, restriction terms and ledger accounts. */
    public function show($code)
    {
        $repo = new FundRepository();
        $f = $repo->find((string) $code);
        if ($f === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => $code . ' is not a fund.']);
        }

        $pct = self::pct($f);
        $remaining = self::available($f) - $f['spend'];
        $alert = match (true) {
            $f['daysLeft'] <= 90 && $f['cls'] === 'Restricted' => 'Spending window closes in ' . max(0, $f['daysLeft']) . ' days. '
                . Prototype::fmt($remaining) . ' is unspent and would be returnable to ' . $f['funder'] . '.',
            $pct >= 90 => 'This fund is ' . $pct . '% utilised. Further commitments need a budget revision.',
            self::closing($f) < -0.005 => $f['name'] . ' is overdrawn: it has spent ' . Prototype::fmt(-self::closing($f)) . ' more than it has received.',
            default => '',
        };

        return $this->json([
            'code' => $f['code'], 'name' => $f['name'], 'purpose' => $f['purpose'], 'cls' => $f['cls'],
            'funder' => $f['funder'], 'grant' => $f['grant'], 'period' => $f['period'], 'spendBy' => $f['spendBy'],
            'conditions' => $f['conditions'] !== '' ? $f['conditions'] : '—', 'programs' => $f['programs'],
            'year' => $repo->year()['code'] ?? '',
            'opening' => Prototype::fmt($f['opening']), 'income' => Prototype::fmt($f['income']), 'spend' => Prototype::fmt($f['spend']),
            'transfers' => round($f['transfers'], 2) == 0 ? '—' : Prototype::fmt($f['transfers']),
            'closing' => Prototype::fmt(self::closing($f)),
            'pct' => $pct, 'barColour' => Ledger::barColour($pct),
            'available' => Prototype::fmt(self::available($f)), 'remaining' => Prototype::fmt($remaining),
            'alert' => $alert,
            'accounts' => array_map(static fn ($a) => ['code' => $a['code'], 'name' => $a['name'], 'balance' => Prototype::fmt($a['balance'])], $repo->accounts($f['code'])),
            'openTransfers' => array_values(array_filter($repo->openTransfers(), fn ($t) => $t['from'] === $f['name'] || $t['to'] === $f['name'])),
        ]);
    }

    /**
     * Raises an inter-fund transfer. Body: {from, to, amount, minute, reason} with
     * fund codes. Its journal waits for the approver the inter-fund transfer rule
     * names; the balances move when it posts.
     */
    public function transfer()
    {
        $actor = $this->actor();
        if (!in_array('journal.prepare', $actor['permissions'] ?? [], true)) {
            return $this->response->setStatusCode(403)->setJSON([
                'error' => 'The ' . $actor['role'] . ' cannot raise a transfer. It is a journal, so it is prepared by someone who prepares journals.',
            ]);
        }

        try {
            $done = (new FundRepository())->transfer($this->request->getJSON(true) ?? [], $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json([
            'message' => $done['ref'] . ' raised and sent to the ' . $done['approver'] . ' for approval. The balances move when it posts — the fund dimension moves, the bank balance does not.',
            'ref'     => $done['ref'],
        ]);
    }

    /** The statement of changes in funds for the working year, as CSV for the board pack. */
    public function statement()
    {
        $repo = new FundRepository();
        $all  = $repo->all();
        $year = $repo->year()['code'] ?? '';

        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['Statement of changes in funds' . ($year !== '' ? ' — ' . $year : '')]);
        fputcsv($out, ['Code', 'Fund', 'Class', 'Funder', 'Opening KES', 'Income KES', 'Expenditure KES', 'Transfers KES', 'Closing KES']);
        $totals = array_fill_keys(['opening', 'income', 'spend', 'transfers', 'closing'], 0.0);
        foreach ($all as $f) {
            $closing = self::closing($f);
            fputcsv($out, [$f['code'], $f['name'], $f['cls'], $f['funder'], ...array_map(static fn ($n) => round($n, 2), [$f['opening'], $f['income'], $f['spend'], $f['transfers'], $closing])]);
            foreach (['opening', 'income', 'spend', 'transfers'] as $k) {
                $totals[$k] += $f[$k];
            }
            $totals['closing'] += $closing;
        }
        fputcsv($out, ['', 'Total funds carried forward', '', '', ...array_map(static fn ($n) => round($n, 2), array_values($totals))]);
        rewind($out);
        // UTF-8 BOM so spreadsheet apps read "—" correctly.
        $csv = "\xEF\xBB\xBF" . stream_get_contents($out);
        fclose($out);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . Brand::current()['name'] . ' statement of funds' . ($year !== '' ? ' ' . $year : '') . '.csv"')
            ->setBody($csv);
    }
}
