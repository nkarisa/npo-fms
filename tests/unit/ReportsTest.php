<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\ReportRepository;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Reports: the four statements, for any period, against the prior year or the
 * approved budget.
 *
 * What has to hold: every statement articulates for every period on offer — the
 * trial balance balances, net assets equal the funds, the surplus on the statement
 * of activities is the surplus in the funds, and the cash flow closes on the cash
 * accounts; the comparative is the same months a year earlier, read from the
 * previous system for the year before the ledger started, and that year closes on
 * exactly the balances the ledger brought forward.
 */
final class ReportsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use \Tests\Support\SignsIn;

    protected $namespace   = 'App';
    protected $refresh     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $seed        = DatabaseSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['app.asOf'] = '2026-08-31';
        Repository::forget();
    }

    public function testThePeriodsAreTheYearToDateItsQuartersAndMonthsAndLastYear(): void
    {
        $view = $this->api('api/reports');

        $this->assertSame('Statement of financial position', $view['report']);
        $this->assertSame('Aug 2026 YTD', $view['period']);
        $this->assertSame(['Aug 2026 YTD', 'Q2 2026', 'Q1 2026', 'Aug 2026', 'Jul 2026'], array_slice($view['periods'], 0, 5));
        $this->assertSame('FY2025 (final)', end($view['periods']));
        $this->assertSame(['Aug 2026 YTD', 'Prior year'], $view['columns']);
        $this->assertSame(['Prior year', 'None'], $view['compares'], 'there is no budget for a balance sheet');
        $this->assertStringContainsString('Aug 2026 is open', $view['basis']);
    }

    public function testEveryStatementArticulatesForEveryPeriod(): void
    {
        $periods = $this->api('api/reports')['periods'];
        foreach (ReportRepository::REPORTS as $report) {
            foreach ($periods as $period) {
                $view = $this->api('api/reports?' . http_build_query(['report' => $report, 'period' => $period]));
                $this->assertTrue($view['balanced'], "{$report} for {$period}: {$view['hint']}");
            }
        }
    }

    public function testTheSurplusOnTheStatementOfActivitiesIsTheSurplusInTheFunds(): void
    {
        foreach (['Aug 2026 YTD', 'FY2025 (final)'] as $period) {
            $position = $this->api('api/reports?' . http_build_query(['period' => $period]));
            $activities = $this->api('api/reports?' . http_build_query(['report' => 'Statement of activities', 'period' => $period, 'split' => '1']));

            $inFunds = $this->row($position, '3900')['cells'];
            $surplus = $this->labelled($activities, 'Surplus / (deficit) for the period')['cells'];
            $this->assertEquals($inFunds[0]['v'], $surplus[2]['v'], $period);
            $this->assertEquals($inFunds[1]['v'], $surplus[3]['v'], "{$period}, prior year");
            // Unrestricted and restricted make up the total, line by line.
            foreach ($activities['sections'] as $section) {
                foreach ($section['rows'] as $r) {
                    $this->assertEqualsWithDelta($r['cells'][2]['v'], $r['cells'][0]['v'] + $r['cells'][1]['v'], 0.01, $r['label']);
                }
            }
        }
    }

    public function testTheCashFlowClosesOnTheCashAccounts(): void
    {
        $position = $this->api('api/reports');
        $flows = $this->api('api/reports?report=' . rawurlencode('Statement of cash flows'));

        $cash = array_sum(array_map(fn ($c) => $this->row($position, $c)['cells'][0]['v'], ['1110', '1120', '1130', '1140']));
        $this->assertEquals($cash, $this->labelled($flows, 'Balance at the end of the period')['cells'][0]['v']);
        // The year opens on the cash the ledger brought forward.
        $this->assertEquals(12463800, $this->labelled($flows, 'Balance at the beginning of the period')['cells'][0]['v']);
    }

    public function testLastYearClosesOnTheBalancesTheLedgerBroughtForward(): void
    {
        $db = db_connect();
        $t = static fn (string $table) => $db->prefixTable($table);
        $brought = array_column($db->query(
            "SELECT a.code, SUM(l.debit - l.credit) AS net FROM {$t('journal_lines')} l JOIN {$t('journals')} j ON j.id = l.journal_id
             JOIN {$t('accounts')} a ON a.id = l.account_id WHERE j.reference = 'OB-26-0001' AND a.type IN ('asset', 'liability') GROUP BY a.code"
        )->getResultArray(), 'net', 'code');
        $last = $this->api('api/reports?' . http_build_query(['period' => 'FY2025 (final)']));

        foreach ($brought as $code => $net) {
            $presented = str_starts_with((string) $code, '1') ? (float) $net : -(float) $net;
            $this->assertEquals($presented, $this->row($last, (string) $code)['cells'][0]['v'], "{$code} at 31 Dec 2025");
        }
        $this->assertEquals(28308800, $this->labelled($last, 'Total funds and reserves')['cells'][0]['v'], 'the funds FY2026 opened with');
        $this->assertSame('legacy', $last['source']);
        $this->assertNull($last['drillPeriod'], 'a year kept elsewhere has no postings to open');
        $this->assertStringContainsString('not held', $last['sub'], 'nothing is held for FY2024');
    }

    public function testTheComparativeIsTheSameMonthsAYearEarlier(): void
    {
        $repo = new ReportRepository();
        $labels = [];
        foreach ($repo->periods() as $p) {
            $labels[$p['label']] = $repo->priorYear($p)['label'];
        }

        $this->assertSame('Aug 2025 YTD', $labels['Aug 2026 YTD']);
        $this->assertSame('Q2 2025', $labels['Q2 2026']);
        $this->assertSame('Jul 2025', $labels['Jul 2026']);
        $this->assertSame('FY2024 (final)', $labels['FY2025 (final)']);

        $view = $this->api('api/reports?' . http_build_query(['period' => 'Jul 2026']));
        $this->assertStringContainsString('comparative: Jul 2025 (legacy system)', $view['sub']);
    }

    public function testTheBudgetComparativeIsTheApprovedBudgetPhasedToTheSameMonths(): void
    {
        $view = $this->api('api/reports?' . http_build_query(['report' => 'Statement of activities', 'period' => 'Q2 2026', 'compare' => 'Approved budget', 'split' => '0']));

        $this->assertSame(['Q2 2026', 'Approved budget'], $view['columns']);
        $this->assertNull($this->row($view, '4110')['cells'][1]['v'], 'the budget sets expenditure only');
        $this->assertGreaterThan(0, $this->row($view, '5110')['cells'][1]['v']);
        $this->assertStringContainsString('Revision 1', implode(' ', $view['notes']));

        // A balance sheet has no budget: the comparative falls back to the prior year.
        $position = $this->api('api/reports?compare=' . rawurlencode('Approved budget'));
        $this->assertSame('Prior year', $position['compare']);
    }

    public function testTheTrialBalanceListsEveryAccountOnTheSideItFalls(): void
    {
        $view = $this->api('api/reports?report=' . rawurlencode('Trial balance'));
        $totals = $this->labelled($view, 'Totals')['cells'];

        $this->assertSame(['Debit', 'Credit', 'Prior'], $view['columns']);
        $this->assertEquals($totals[0]['v'], $totals[1]['v']);
        $this->assertNull($this->row($view, '1390')['cells'][0]['v'], 'accumulated depreciation is a credit');
        $this->assertEquals(7412000, $this->row($view, '1390')['cells'][1]['v']);
        $this->assertNull($this->row($view, '3900') ?? null, 'the surplus is derived, not a balance');
        $this->assertSame('FY2026 · Jan – Aug', $view['drillPeriod'], 'a line opens its postings for the same months');
    }

    public function testTheExportWritesTheStatementUnformatted(): void
    {
        $result = $this->get('api/reports/export?' . http_build_query(['report' => 'Trial balance', 'period' => 'Jul 2026']));
        $result->assertOK();
        $this->assertStringContainsString('attachment; filename="', $result->response()->getHeaderLine('Content-Disposition'));
        $rows = array_map('str_getcsv', explode("\n", trim(substr($result->response()->getBody(), 3))));

        $this->assertSame(['Code', 'Account', 'Debit', 'Credit', 'Prior'], $rows[4]);
        $this->assertSame('1390', current(array_filter($rows, static fn ($r) => $r[0] === '1390'))[0]);
        $this->assertTrue(is_numeric(current(array_filter($rows, static fn ($r) => $r[0] === '1110'))[2]));
    }

    private function api(string $url): array
    {
        Repository::forget();

        return json_decode($this->get($url)->getJSON(), true);
    }

    private function row(array $view, string $code): ?array
    {
        foreach ($view['sections'] as $section) {
            foreach ($section['rows'] as $r) {
                if ($r['code'] === $code) {
                    return $r;
                }
            }
        }

        return null;
    }

    private function labelled(array $view, string $label): array
    {
        foreach ($view['sections'] as $section) {
            foreach ($section['rows'] as $r) {
                if ($r['label'] === $label) {
                    return $r;
                }
            }
        }
        $this->fail("No row labelled {$label}.");
    }
}
