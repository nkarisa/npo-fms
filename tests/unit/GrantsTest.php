<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\FundRepository;
use App\Repositories\GrantRepository;
use App\Repositories\Lookups;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The v5 grants screen: the award portfolio with burn read against elapsed time,
 * one award's drawer, the portfolio's reporting calendar, recording an award from
 * its signed agreement, and converting a pipeline award on signature.
 */
final class GrantsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = true;
    protected $seed      = DatabaseSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['app.asOf'] = '2026-08-31';
        Repository::forget();
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['elog_actor']);
        service('superglobals')->unsetCookie('elog_actor');
        parent::tearDown();
    }

    public function testThePortfolioReadsBurnAgainstElapsedTime(): void
    {
        $screen = $this->api('api/grants');

        $this->assertSame(['All (8)', 'Active (4)', 'Closing (1)', 'Pipeline (1)', 'Suspended (1)', 'Closed (1)'], array_column($screen['tabs'], 'label'));
        $this->assertSame('8 of 8 awards · 5 live, portfolio burn 31%', $screen['footer']);
        $this->assertSame(['Live portfolio', 'Received to date', 'Spent to date', 'Unspent commitment', 'Reports due'], array_column($screen['stats'], 'label'));

        $danida = current(array_filter($screen['rows'], fn ($r) => $r['ref'] === 'DANIDA/CE-2025/27'));
        $this->assertSame(43, $danida['burnPct']);
        $this->assertSame(83, $danida['elapsed']);
        $this->assertSame('#B98A3C', $danida['burnColour'], 'Spending well behind time reads amber.');

        $this->assertCount(1, $this->api('api/grants?status=Suspended')['rows']);
        $this->assertCount(1, $this->api('api/grants?q=hivos')['rows']);
    }

    public function testTheDrawerOpensAnAwardWhoseReferenceCarriesSlashes(): void
    {
        $danida = $this->api('api/grants/DANIDA/CE-2025/27');

        $this->assertSame('Voter civic education across 14 counties', $danida['title']);
        $this->assertSame('8% of the award, 5,440,000', $danida['indirectCap'], 'The seeder reads the cap from the agreement conditions.');
        $this->assertSame('Civic Education and Shared services', $danida['program']);
        $this->assertStringStartsWith('Spending lags the period by 40 points.', $danida['burnNote']);
        $this->assertSame(['Received', 'Received', 'Due', 'Scheduled'], array_column($danida['tranches'], 'status'));
        $this->assertSame('Prepare donor report', $danida['reportAction']);

        $kas = $this->api('api/grants/KAS/2025-KE');
        $this->assertSame('Disbursements are suspended. The Financial report H2 2026 is 27 days overdue, and no new commitments may be made against this award.', $kas['alert']);
        $this->assertStringStartsWith('Award closes on 30 Sep 2026.', $this->api('api/grants/FORD/GA-24')['alert']);
        $this->assertSame('Convert to award', $this->api('api/grants/HIVOS/PROP-2026')['reportAction']);

        $this->get('api/grants/NOPE/1')->assertStatus(404);
    }

    public function testTheReportingCalendarListsWhatIsOwedSoonestFirst(): void
    {
        $calendar = $this->api('api/grants/calendar');

        $this->assertSame('1 overdue · 3 due within 45 days · 9 outstanding in all', $calendar['summary']);
        $days = array_column($calendar['reports'], 'days');
        $sorted = $days;
        sort($sorted);
        $this->assertSame($sorted, $days);
        $this->assertNotContains('Submitted', array_column($calendar['reports'], 'state'));
        $this->assertNotContains('HIVOS/PROP-2026', array_column($calendar['reports'], 'award'), 'A pipeline award owes no reports yet.');
    }

    public function testAnAwardIsRecordedFromItsSignedAgreement(): void
    {
        $this->actAs('w.kamau@elog.or.ke');
        $response = $this->withBodyFormat('json')->post('api/grants', $this->award());
        $response->assertStatus(200);
        $this->assertSame('SIDA/CE-2027 recorded and Embassy of Sweden Civic Education Fund opened as FND-410.', json_decode($response->getJSON(), true)['message']);
        Repository::forget();

        // The fund it is held in, opened for it: restricted, in the grant column, with both programmes.
        $fund = (new FundRepository())->find('FND-410');
        $this->assertSame('restricted', $fund['restriction']);
        $this->assertSame('grant', $fund['ledgerGroup']);
        $this->assertSame('Embassy of Sweden', $fund['funder']);
        $this->assertSame('30 Sep 2028', $fund['spendBy']);
        $this->assertEqualsCanonicalizing(['Civic Education', 'Election Observation'], $fund['programs']);

        $grant = (new GrantRepository())->find('SIDA/CE-2027');
        $this->assertSame('Active', $grant['status']);
        $this->assertSame('USD', $grant['currency']);
        $this->assertSame(12000000, $grant['value']);
        $this->assertEqualsWithDelta(12000000 / 129.4, $grant['valueFc'], 0.01);
        $this->assertSame(['Election Observation'], $grant['alsoPrograms']);
        $this->assertSame(20.0, $grant['indirectCap']);
        $this->assertSame([10000000, 2000000], array_column($grant['budget'], 'budget'));
        $this->assertSame(['Scheduled', 'Scheduled'], array_column($grant['tranches'], 'status'));
        $this->assertSame(['Financial report Q1', 'Audited close-out'], array_column($grant['reports'], 'name'));
        $this->assertMatchesRegularExpression('/^DR-27-\d{3}$/', $grant['reports'][0]['ref']);
        $this->seeInDatabase('donor_reports', ['title' => 'Audited close-out', 'type' => 'close_out', 'period_starts_on' => '2026-10-01', 'period_ends_on' => '2028-09-30']);
        $this->seeInDatabase('funders', ['name' => 'Embassy of Sweden']);
        $this->seeInDatabase('audit_events', ['object_type' => 'grant', 'object_ref' => 'SIDA/CE-2027', 'action' => 'grant.recorded']);

        // A line coded to the new fund names its award.
        $this->assertSame((int) db_connect()->table('grants')->where('award_ref', 'SIDA/CE-2027')->get()->getRow()->id, (new Lookups())->grantOfFund($fund['id']));
    }

    public function testAnAwardThatDoesNotAddUpIsRefusedBeforeAnythingIsWritten(): void
    {
        $kamau = $this->user('w.kamau@elog.or.ke');
        foreach ([
            [['ref' => 'DANIDA/CE-2025/27'], 'already in the portfolio'],
            [['title' => ''], 'Give the award a title'],
            [['end' => '2026-01-01'], 'ends before it starts'],
            [['purpose' => ''], "State the fund's purpose"],
            [['ledgerOverride' => true], 'Record why the agreement carries no restriction'],
            [['budget' => [['code' => '5110', 'amount' => 11000000]]], 'Budget lines total 11,000,000 against an award value of 12,000,000'],
            [['budget' => [['code' => '5110', 'amount' => 6000000], ['code' => '5110', 'amount' => 6000000]]], 'budgeted twice'],
            [['indirectCap' => '8'], 'Indirect cost recovery of 2,000,000 exceeds the agreed cap of 8% (960,000)'],
            [['tranches' => [['no' => 'Tranche 1', 'date' => '2026-11-15', 'amount' => 5000000]]], 'Disbursements total 5,000,000'],
            [['reports' => [['name' => 'Financial report', 'due' => '']]], 'Every report needs a name and a due date'],
            [['conditions' => ['  ']], 'Record at least one condition'],
            [['currency' => 'XYZ'], 'not a currency this instance holds'],
        ] as [$change, $expected]) {
            try {
                (new GrantRepository())->record(array_merge($this->award(), $change), $kamau);
                $this->fail(json_encode($change) . ' should be refused.');
            } catch (RuleViolation $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
        $this->dontSeeInDatabase('grants', ['award_ref' => 'SIDA/CE-2027']);
        $this->dontSeeInDatabase('funds', ['code' => 'FND-410']);

        // Opening a fund and setting a budget is the Finance Manager's.
        $this->actAs('m.otieno@elog.or.ke');
        $this->withBodyFormat('json')->post('api/grants', $this->award())->assertStatus(403);
    }

    public function testWhereTheMoneySitsFollowsWhatTheAgreementFunds(): void
    {
        $kamau = $this->user('w.kamau@elog.or.ke');

        (new GrantRepository())->record(array_merge($this->award(), ['ref' => 'CAP/1', 'capital' => true]), $kamau);
        (new GrantRepository())->record(array_merge($this->award(), ['ref' => 'GEN/1', 'fundName' => 'Institutional support', 'ledgerOverride' => true, 'overrideReason' => 'Clause 4: general institutional support']), $kamau);
        Repository::forget();

        $this->assertSame('capital', (new FundRepository())->find('FND-410')['ledgerGroup']);
        $general = (new FundRepository())->find('FND-420');
        $this->assertSame(['restricted', 'general'], [$general['restriction'], $general['ledgerGroup']]);
        $this->seeInDatabase('audit_events', ['object_ref' => 'FND-420', 'summary' => 'Institutional support is restricted but presented in the General Fund: Clause 4: general institutional support']);

        // Attached to an existing fund, no fund is opened and the award's programmes join it.
        (new GrantRepository())->record(array_merge($this->award(), ['ref' => 'EXIST/1', 'fundMode' => 'existing', 'fundExisting' => 'FND-220', 'program' => 'Youth and Gender Inclusion', 'alsoPrograms' => []]), $kamau);
        Repository::forget();
        $this->assertSame('DANIDA Civic Education Fund', (new GrantRepository())->find('EXIST/1')['fund']);
        $this->assertContains('Youth and Gender Inclusion', (new FundRepository())->find('FND-220')['programs']);
        $this->dontSeeInDatabase('funds', ['code' => 'FND-430']);
    }

    public function testAPipelineAwardIsConvertedOnSignature(): void
    {
        $kamau = $this->user('w.kamau@elog.or.ke');
        (new GrantRepository())->record(array_merge($this->award(), ['status' => 'Pipeline']), $kamau);

        $this->actAs('m.otieno@elog.or.ke');
        $this->post('api/grants/SIDA/CE-2027/activate')->assertStatus(403);

        $this->actAs('w.kamau@elog.or.ke');
        $done = $this->post('api/grants/SIDA/CE-2027/activate');
        $done->assertStatus(200);
        $this->assertSame('SIDA/CE-2027 is active. Its budget may be committed against from today.', json_decode($done->getJSON(), true)['message']);
        $this->seeInDatabase('grants', ['award_ref' => 'SIDA/CE-2027', 'status' => 'active']);
        $this->seeInDatabase('audit_events', ['object_ref' => 'SIDA/CE-2027', 'action' => 'grant.activated']);

        // The seeded proposal has no agreement period yet, and an active award is not converted twice.
        $this->assertStringContainsString('Record the agreement period', json_decode($this->post('api/grants/HIVOS/PROP-2026/activate')->getJSON(), true)['error']);
        $this->assertStringContainsString('Only a pipeline award', json_decode($this->post('api/grants/SIDA/CE-2027/activate')->getJSON(), true)['error']);
    }

    // ------------------------------------------------------------------

    /** A complete award as the form sends it: USD, a new restricted fund, two budget lines and tranches. */
    private function award(): array
    {
        return [
            'funder' => 'Embassy of Sweden', 'ref' => 'SIDA/CE-2027', 'title' => 'County civic education and voter information 2027',
            'program' => 'Civic Education', 'alsoPrograms' => ['Election Observation'], 'manager' => 'M. Otieno',
            'currency' => 'USD', 'rate' => 129.4, 'value' => 12000000, 'start' => '2026-10-01', 'end' => '2028-09-30', 'status' => 'Active',
            'fundMode' => 'new', 'fundName' => '', 'fundCls' => 'Restricted', 'capital' => false, 'ledgerOverride' => false, 'overrideReason' => '',
            'purpose' => 'Civic education and voter information in 14 counties', 'indirectCap' => '20',
            'budget' => [['code' => '5110', 'amount' => 10000000], ['code' => '5310', 'amount' => 2000000]],
            'tranches' => [['no' => 'Tranche 1', 'date' => '2026-11-15', 'amount' => 6000000], ['no' => 'Tranche 2', 'date' => '2027-10-15', 'amount' => 6000000]],
            'reports' => [
                ['name' => 'Financial report Q1', 'from' => '2026-10-01', 'to' => '2026-12-31', 'due' => '2027-01-30'],
                ['name' => 'Audited close-out', 'from' => '2026-10-01', 'to' => '2028-09-30', 'due' => '2028-12-29'],
            ],
            'conditions' => ['Costs must be incurred within the agreement period; no retroactive charges.'],
        ];
    }

    private function api(string $url): array
    {
        return json_decode($this->get($url)->getJSON(), true);
    }

    private function user(string $email): int
    {
        return (new Lookups())->userId($email);
    }

    /** The request reads cookies from the shared superglobals, which a test request does not refresh. */
    private function actAs(string $email): void
    {
        $_COOKIE['elog_actor'] = $email;
        service('superglobals')->setCookie('elog_actor', $email);
    }
}
