<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Cashflow forecast: thirteen weeks from 31 August, run under a scenario and with
 * or without the discretionary hold.
 *
 * What has to hold: closing cash rolls forward from the opening balance week by
 * week; the unrestricted column is closing cash less restricted balances, and the
 * page warns when it goes negative; a scenario moves money rather than losing it
 * (a delayed tranche still arrives within the horizon); and the export for the
 * Board is the projection the page shows.
 */
final class CashflowTest extends CIUnitTestCase
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

        Repository::forget();
    }

    public function testTheBaseCaseRollsForwardFromTheOpeningBalance(): void
    {
        $view = $this->api('api/cashflow');

        $this->assertSame('Base case', $view['scenario']);
        $this->assertSame(13, $view['weeks']);
        $this->assertStringStartsWith('Thirteen weeks from 31 August,', $view['blurb']);
        $this->assertSame(['Cash on hand', '24,850,000', 'KCB Current, Equity USD and M-Pesa float'], array_values($view['stats'][0]));
        $this->assertSame('15,400,000', $view['stats'][1]['value']);
        $this->assertSame('1.3 weeks', $view['stats'][2]['value']);
        $this->assertSame(['Closing position, week 13', '12,668,800', '23 Nov 2026'], array_values($view['stats'][4]));

        $first = $view['rows'][0];
        $this->assertSame(['31 Aug', '6,400,000', '9,850,000', '(3,450,000)', '21,400,000', '6,000,000'],
            [$first['wc'], $first['inflow'], $first['outflow'], $first['net'], $first['closing'], $first['unrestricted']]);
        $this->assertFalse($first['positive']);
        // The largest movement in the horizon draws the full bar.
        $this->assertSame(100, $view['rows'][1]['inflowPct']);
    }

    public function testNegativeUnrestrictedCashIsWarnedOfEvenWhenClosingCashIsPositive(): void
    {
        $view = $this->api('api/cashflow');
        $last = end($view['rows']);

        $this->assertSame('12,668,800', $last['closing']);
        $this->assertSame('(2,731,200)', $last['unrestricted']);
        $this->assertTrue($last['tight']);
        $this->assertStringContainsString('week commencing 23 Nov at (2,731,200)', $view['warning']);
    }

    public function testADelayedTrancheMovesTheLowPointButStillArrivesInTheHorizon(): void
    {
        $view = $this->api('api/cashflow?scenario=' . rawurlencode('Donor delay'));

        $this->assertSame(['Lowest unrestricted point', '(15,871,420)', 'Week commencing 12 Oct'], array_values($view['stats'][3]));
        $this->assertSame('12,668,800', end($view['rows'])['closing'], 'the same money arrives, later');
    }

    public function testHoldingDiscretionarySpendClearsTheShortfall(): void
    {
        $view = $this->api('api/cashflow?hold=true');

        $this->assertTrue($view['hold']);
        $this->assertSame('Discretionary spend held from week 5', $view['holdLabel']);
        $this->assertSame('', $view['warning']);
        $this->assertSame('6,853,800', end($view['rows'])['unrestricted']);
        $this->assertStringEndsWith('with discretionary hold', $view['hint']);
    }

    public function testAnUnknownScenarioFallsBackToTheBaseCase(): void
    {
        $this->assertSame('Base case', $this->api('api/cashflow?scenario=Asteroid')['scenario']);
    }

    public function testTheBoardExportIsTheProjectionOnScreen(): void
    {
        $res = $this->get('api/cashflow/export?' . http_build_query(['scenario' => 'By-election surge', 'hold' => 'true']));

        $res->assertOK();
        $this->assertStringContainsString('cashflow forecast 2026-08-31 by-election surge held.csv', $res->response()->getHeaderLine('Content-Disposition'));
        $lines = array_map('str_getcsv', explode("\n", trim(substr((string) $res->response()->getBody(), 3))));
        $this->assertSame('13-week cashflow forecast from 31 Aug 2026 · scenario: By-election surge with discretionary spend held from week 5', $lines[0][0]);
        $this->assertSame(['Opening cash', '24850000', 'Of which restricted', '15400000'], $lines[1]);
        $this->assertCount(3 + 13, $lines);
        $last = end($lines);
        $this->assertSame(['2026-11-23', '10792400', '-4607600'], [$last[0], $last[6], $last[7]]);
    }

    private function api(string $url): array
    {
        return json_decode($this->get($url)->getJSON(), true);
    }
}
