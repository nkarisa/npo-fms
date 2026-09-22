<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Libraries\Ledger;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Budgets: versions, revisions and next year's budget.
 *
 * What has to hold: variance is read against each line's own phasing, not a
 * straight twelfth; only one version of a year is approved, and it is the one
 * the procurement check and the programme register read; a revision nets to zero
 * within a fund and an agreement, is approved by someone other than whoever moved
 * the money, and stays inside the funder-consent limit; and next year's grant
 * lines come from what each award still allows in the months that fall inside it.
 */
final class BudgetsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use \Tests\Support\SignsIn;

    protected $namespace = 'App';
    protected $refresh   = true;
    protected $seed      = DatabaseSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['app.asOf'] = '2026-08-31';
        Repository::forget();
    }

    public function testTheScreenOpensOnTheApprovedVersionPhasedByEachLinesProfile(): void
    {
        $index = $this->api('api/budgets');

        $this->assertSame('FY2026 Revision 1', $index['version']);
        $this->assertSame(['FY2026 Original', 'FY2026 Revision 1 (approved)'], array_column($index['versions'], 'label'));
        $this->assertSame('Phasing to Aug 2026', $index['basisOptions'][0]['label']);
        $this->assertSame('8 of 12 months', $this->stat($index, 'Phased to Aug')['note']);

        // 5110 runs on the election-peak profile: eight months of it, not 8/12 of the year.
        $peak = [0.5, 0.6, 0.8, 1, 1.4, 1.8, 2.2, 2, 1.4, 0.9, 0.6, 0.5];
        $expected = 0;
        foreach (array_slice($peak, 0, 8) as $w) {
            $expected += round(62000000 * $w / array_sum($peak), 2);
        }
        $line = $this->line($index, '5110');
        $this->assertSame(number_format(round($expected)), $line['basis']);
        $this->assertNotSame(number_format(62000000 * 8 / 12), $line['basis']);

        $full = $this->api('api/budgets?basis=full');
        $this->assertSame('62,000,000', $this->line($full, '5110')['basis'], 'full-year compares against the annual figure');
    }

    public function testTheDashboardMeasuresLinesTheSameWayAsTheBudgetsScreen(): void
    {
        $index = $this->api('api/budgets');
        foreach (Ledger::budgetLines() as $l) {
            $this->assertSame($this->line($index, $l['code'])['status'], $l['status'], $l['code']);
        }
    }

    public function testARevisionMustStayInsideOneFundOneAgreementAndTheConsentLimit(): void
    {
        $this->signIn('m.otieno@elog.or.ke');
        $keys = $this->keys();

        $this->refusal(['from' => $keys['5140'], 'to' => $keys['5210'], 'amount' => 100], 'different funds');
        $this->refusal(['from' => $keys['5120'], 'to' => $keys['5110'], 'amount' => 100], 'different agreements');
        $this->refusal(['from' => $keys['5140'], 'to' => $keys['5110'], 'amount' => 3000000], 'more than 10% of 5140');
        $this->refusal(['from' => $keys['5140'], 'to' => $keys['5110'], 'amount' => 100, 'ref' => ''], 'authority reference');

        $this->send('api/budgets/revisions', $this->move($keys['5140'], $keys['5110'], 2000000))->assertOK();
        // A second movement cannot slip past the limit the first one used most of.
        $this->refusal(['from' => $keys['5140'], 'to' => $keys['5110'], 'amount' => 900000], 'counting the 2,000,000 already moved');
    }

    public function testARevisionIsApprovedBySomeoneElseAndThenReplacesTheApprovedBudget(): void
    {
        $this->signIn('m.otieno@elog.or.ke');
        $keys = $this->keys();
        $res = $this->send('api/budgets/revisions', $this->move($keys['5140'], $keys['5110'], 2000000));
        $res->assertOK();
        $this->assertSame('FY2026 Revision 2', json_decode($res->getJSON(), true)['version']);

        $working = $this->api('api/budgets?version=' . rawurlencode('FY2026 Revision 2'));
        $this->assertSame('64,000,000', $this->line($working, '5110')['annual']);
        $this->assertSame('26,000,000', $this->line($working, '5140')['annual']);
        $this->assertSame('278,840,000', $this->stat($working, 'Annual budget')['value'], 'a revision nets to zero');
        $this->assertSame('62,000,000', $this->line($this->api('api/budgets'), '5110')['annual'], 'the approved budget is untouched until approval');

        $this->send('api/budgets/versions/submit', ['version' => 'FY2026 Revision 2'])->assertOK();
        $res = $this->send('api/budgets/versions/approve', ['version' => 'FY2026 Revision 2']);
        $res->assertStatus(422);
        $this->assertStringContainsString('cannot also approve', json_decode($res->getJSON(), true)['error']);

        $this->signIn('w.kamau@elog.or.ke');
        $this->send('api/budgets/versions/approve', ['version' => 'FY2026 Revision 2'])->assertOK();

        $index = $this->api('api/budgets');
        $this->assertSame('FY2026 Revision 2', $index['version']);
        $this->assertSame(['FY2026 Original', 'FY2026 Revision 1', 'FY2026 Revision 2 (approved)'], array_column($index['versions'], 'label'));
        $this->assertSame('64,000,000', $this->line($index, '5110')['annual']);

        $this->assertSame(1, $this->db->table('budget_versions')->where('status', 'approved')->countAllResults());
        $this->assertSame('approved', $this->db->table('budget_reallocations')->get()->getRow()->status);

        $history = $this->api('api/budgets/line?key=' . rawurlencode($keys['5110']))['history'];
        $this->assertSame(['58,000,000', '62,000,000', '64,000,000'], array_column($history, 'amount'));
        $this->assertSame('+2,000,000', $history[2]['note']);
    }

    public function testASentBackRevisionReturnsToDraftAndCanBeDiscarded(): void
    {
        $this->signIn('m.otieno@elog.or.ke');
        $keys = $this->keys();
        $this->send('api/budgets/revisions', $this->move($keys['5140'], $keys['5110'], 1000000))->assertOK();
        $this->send('api/budgets/versions/submit', ['version' => 'FY2026 Revision 2'])->assertOK();

        $this->signIn('w.kamau@elog.or.ke');
        $this->send('api/budgets/versions/send-back', ['version' => 'FY2026 Revision 2', 'note' => ''])->assertStatus(422);
        $this->send('api/budgets/versions/send-back', ['version' => 'FY2026 Revision 2', 'note' => 'Attach the USAID consent'])->assertOK();

        $state = $this->api('api/budgets?version=' . rawurlencode('FY2026 Revision 2'))['state'];
        $this->assertSame('draft', $state['status']);
        $this->assertSame('Attach the USAID consent', $state['returnedNote']);

        $this->send('api/budgets/versions/discard', ['version' => 'FY2026 Revision 2'])->assertOK();
        $this->assertSame(0, $this->db->table('budget_reallocations')->countAllResults());
        $this->assertSame(['FY2026 Original', 'FY2026 Revision 1 (approved)'], array_column($this->api('api/budgets')['versions'], 'label'));
    }

    /**
     * USAID's award ends in March 2027. From September 2026 that leaves seven
     * months, three of them in FY2027, so 5110 gets three sevenths of what is
     * left of its ceiling. DANIDA's ends in December 2026, so nothing of it
     * falls in the year. Core lines roll forward with the uplift.
     */
    public function testNextYearsGrantLinesComeFromWhatEachAwardStillAllows(): void
    {
        $this->signIn('m.otieno@elog.or.ke');
        $d = $this->api('api/budgets/new-year?year=2027&uplift=5&keep=1');

        $rows = array_column($d['rows'], null, 'code');
        $this->assertSame('Award', $rows['5110']['basis']);
        $this->assertStringContainsString('3 of 7 remaining months fall in FY2027', $rows['5110']['note']);
        $remaining = 62000000 - $this->grantLineActual('USAID/URAIA/2026', '5110');
        $this->assertSame(number_format(round($remaining * 3 / 7, -3)), $rows['5110']['amount']);
        $this->assertSame('—', $rows['5120']['amount']);
        $this->assertStringContainsString('Award closes 31 Dec 2026', $rows['5120']['note']);
        $this->assertSame('Core', $rows['5210']['basis']);
        $this->assertSame('46,200,000', $rows['5210']['amount']);

        $dropped = $this->api('api/budgets/new-year?year=2027&uplift=5&keep=0');
        $this->assertCount(count($d['rows']) - 3, $dropped['rows'], 'the three lines with nothing left are left out');

        $this->send('api/budgets/new-year', ['year' => 2027, 'uplift' => 5, 'keepEmpty' => true])->assertOK();
        $draft = $this->api('api/budgets?version=' . rawurlencode('FY2027 Original'));
        $this->assertSame('FY2027 Original (draft)', $draft['versionLabel']);
        $this->assertSame('0 of 12 months', $this->stat($draft, 'Phased to date')['note']);
        $this->assertSame('FY2026 Revision 1', $this->api('api/budgets')['version'], 'the screen still opens on the working year');
    }

    public function testNextYearsBudgetIsApprovedByTheExecutiveDirectorAndLeavesThisYearsChecksAlone(): void
    {
        $this->signIn('m.otieno@elog.or.ke');
        $this->send('api/budgets/new-year', ['year' => 2027, 'uplift' => 5, 'keepEmpty' => true])->assertOK();
        $this->send('api/budgets/versions/submit', ['version' => 'FY2027 Original'])->assertOK();

        $this->signIn('w.kamau@elog.or.ke');
        $res = $this->send('api/budgets/versions/approve', ['version' => 'FY2027 Original']);
        $res->assertStatus(422);
        $this->assertStringContainsString('Executive Director', json_decode($res->getJSON(), true)['error']);

        $before = $this->programmeBudget();
        $this->signIn('d.kiptoo@elog.or.ke');
        $this->send('api/budgets/versions/approve', ['version' => 'FY2027 Original'])->assertOK();

        $this->assertSame(2, $this->db->table('budget_versions')->where('status', 'approved')->countAllResults(), 'one approved version per year');
        $this->assertSame($before, $this->programmeBudget(), "next year's budget does not count against this year's lines");
        $this->assertSame('278,840,000', $this->stat($this->api('api/budgets'), 'Annual budget')['value']);
    }

    public function testTheVarianceReportExportsWhatTheScreenShows(): void
    {
        $res = $this->get('api/budgets/export?group=Programme&basis=full');
        $res->assertOK();
        $this->assertStringContainsString('budget variance FY2026 Revision 1.csv', $res->response()->getHeaderLine('Content-Disposition'));
        $csv = (string) $res->response()->getBody();

        $this->assertStringContainsString('FY2026 Revision 1 (approved) · variance against full-year budget', $csv);
        $this->assertStringContainsString('"Election Observation",5110,"Observer recruitment and deployment"', $csv);
        $this->assertStringContainsString('"Total expenditure budget",,,,278840000', $csv);
    }

    // ------------------------------------------------------------------

    private function move(string $from, string $to, float $amount, string $ref = 'FIN/BR/2026/07'): array
    {
        return ['from' => $from, 'to' => $to, 'amount' => $amount, 'ref' => $ref, 'reason' => 'Observer deployment expanded'];
    }

    private function refusal(array $body, string $expected): void
    {
        $res = $this->send('api/budgets/revisions', $body + ['ref' => 'FIN/BR/2026/07', 'reason' => 'x']);
        $res->assertStatus(422);
        $this->assertStringContainsString($expected, json_decode($res->getJSON(), true)['error']);
    }

    /** @return array<string, string> line key by account code */
    private function keys(): array
    {
        return array_column($this->api('api/budgets')['revision']['lines'], 'key', 'code');
    }

    private function line(array $index, string $code): array
    {
        foreach ($index['groups'] as $g) {
            foreach ($g['lines'] as $l) {
                if ($l['code'] === $code) {
                    return $l;
                }
            }
        }

        $this->fail($code . ' is not on the page.');
    }

    private function stat(array $index, string $label): array
    {
        foreach ($index['stats'] as $stat) {
            if ($stat['label'] === $label) {
                return $stat;
            }
        }

        $this->fail($label . ' is not one of the figures on the page.');
    }

    private function grantLineActual(string $ref, string $code): float
    {
        foreach ((new \App\Repositories\GrantRepository())->all() as $g) {
            if ($g['ref'] === $ref) {
                foreach ($g['budget'] as $b) {
                    if ($b['code'] === $code) {
                        return (float) $b['actual'];
                    }
                }
            }
        }

        $this->fail($ref . ' has no ' . $code . ' line.');
    }

    private function programmeBudget(): string
    {
        Repository::forget();

        return $this->api('api/programmes')['stats'][1]['value'];
    }

    private function send(string $url, array $body = [])
    {
        Repository::forget();

        return $this->withBodyFormat('json')->post($url, $body);
    }

    private function api(string $url): array
    {
        Repository::forget();

        return json_decode($this->get($url)->getJSON(), true);
    }
}
