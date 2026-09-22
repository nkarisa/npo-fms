<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\GrantRepository;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Donor reports: the register, and a report's path to the funder.
 *
 * What has to hold: a report's figures are the ledger's, taken for its award and
 * period; a report whose adjustment leaves it not tying to the ledger cannot go
 * for review or be submitted; whoever prepared a report never submits it; a
 * query goes back with an answer before the report can be accepted; and nothing
 * but a draft ever has its figures retaken.
 */
final class DonorReportsTest extends CIUnitTestCase
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

    public function testTheRegisterPutsWhatNeedsDoingFirstAndFlagsTheReportThatDoesNotTie(): void
    {
        $index = $this->api('api/donor-reports');

        $this->assertSame(19, $index['total']);
        $this->assertSame(['All (19)', 'Draft (5)', 'In review (2)', 'Submitted (8)', 'Queried (1)', 'Overdue (1)', 'Accepted (2)'], array_column($index['tabs'], 'label'));
        $this->assertSame('1 report does not tie', $index['hint']);
        $this->assertSame(['8', 'earliest 14 Sep'], [$this->stat($index, 'Open reports')['value'], $this->stat($index, 'Due within 45 days')['note']]);

        // Open and queried reports first, soonest due at the top.
        $this->assertSame(['DR-26-006', 'DR-26-012', 'DR-26-016', 'DR-26-018', 'DR-26-017'], array_slice(array_column($index['rows'], 'ref'), 0, 5));
        $danida = $this->row($index, 'DR-26-017');
        $this->assertFalse($danida['tied']);
        $this->assertSame(['27,490,000', '26,250,000', '+1,240,000'], [$danida['reported'], $danida['actual'], $danida['diff']]);

        $this->assertSame(['DR-26-012'], array_column($this->api('api/donor-reports?status=Overdue')['rows'], 'ref'));
        $this->assertSame(['DR-26-015'], array_column($this->api('api/donor-reports?q=UNDP%20Basket&status=In%20review')['rows'], 'ref'));
        $this->assertCount(9, $this->api('api/donor-reports?page=2')['rows']);
    }

    public function testAReportThatDoesNotTieGoesNowhereUntilTheAdjustmentIsRemoved(): void
    {
        $this->signIn('s.njeri@elog.or.ke');

        $refused = $this->send('api/donor-reports/review', ['ref' => 'DR-26-017']);
        $refused->assertStatus(422);
        $this->assertStringContainsString('does not tie to the ledger: 27,490,000 reported against 26,250,000 posted', $this->error($refused));

        $this->send('api/donor-reports/remove-adjustment', ['ref' => 'DR-26-017'])->assertOK();
        $this->send('api/donor-reports/review', ['ref' => 'DR-26-017'])->assertOK();

        $report = $this->api('api/donor-reports/DR-26-017');
        $this->assertSame(['In review', '26,250,000', true], [$report['status'], $report['cumulativeTotal'], $report['tied']]);
        $this->assertSame('Sent for review by S. Njeri', end($report['trail'])['what']);
        $this->assertSame('All reports tie to the ledger', $this->api('api/donor-reports')['hint']);
    }

    public function testWhoeverPreparedAReportNeverSubmitsIt(): void
    {
        // An accountant prepares but does not approve.
        $this->signIn('m.otieno@elog.or.ke');
        $this->send('api/donor-reports/submit', ['ref' => 'DR-26-016'])->assertStatus(403);

        // The Finance Manager raises one of their own and cannot submit it either.
        $this->signIn('w.kamau@elog.or.ke');
        $ref = $this->json($this->send('api/donor-reports', $this->newReport()))['ref'];
        $this->send('api/donor-reports/review', ['ref' => $ref])->assertOK();
        $this->assertFalse($this->api('api/donor-reports/' . $ref)['can']['submit']);
        $own = $this->send('api/donor-reports/submit', ['ref' => $ref]);
        $own->assertStatus(422);
        $this->assertSame('W. Kamau prepared ' . $ref . '. Someone else reviews it and submits it to USAID / Uraia.', $this->error($own));

        // Someone else does, and the compliance statements are confirmed as it goes.
        $this->send('api/donor-reports/submit', ['ref' => 'DR-26-016'])->assertOK();
        $this->seeInDatabase('donor_reports', ['reference' => 'DR-26-016', 'status' => 'submitted', 'reviewed_by' => $this->userId('w.kamau@elog.or.ke')]);
        $this->dontSeeInDatabase('donor_report_checks', ['donor_report_id' => $this->reportId('DR-26-016'), 'is_confirmed' => 0]);
        $this->assertSame('Approved by W. Kamau and submitted to Ford Foundation', end($this->api('api/donor-reports/DR-26-016')['trail'])['what']);
    }

    public function testAQueryIsAnsweredBeforeTheReportIsAccepted(): void
    {
        $this->signIn('m.otieno@elog.or.ke');

        $this->send('api/donor-reports/query', ['ref' => 'DR-26-009', 'text' => ''])->assertStatus(422);
        $this->send('api/donor-reports/query', ['ref' => 'DR-26-009', 'text' => 'Explain the fall in 5140.'])->assertOK();
        $this->seeInDatabase('donor_report_queries', ['reference' => 'Q-2026-08', 'text' => 'Explain the fall in 5140.']);

        $early = $this->send('api/donor-reports/accept', ['ref' => 'DR-26-009']);
        $early->assertStatus(422);
        $this->assertSame('DR-26-009 has a query outstanding. Answer it before filing acceptance.', $this->error($early));

        $this->send('api/donor-reports/respond', ['ref' => 'DR-26-009'])->assertStatus(422);
        $this->send('api/donor-reports/respond', ['ref' => 'DR-26-009', 'responses' => ['Q-2026-08' => 'Field transport moved to the county partners.']])->assertOK();
        $this->seeInDatabase('donor_report_queries', ['reference' => 'Q-2026-08', 'responded_by' => $this->userId('m.otieno@elog.or.ke'), 'responded_on' => '2026-08-31']);

        $this->send('api/donor-reports/accept', ['ref' => 'DR-26-009'])->assertOK();
        $this->assertSame('Accepted', $this->api('api/donor-reports/DR-26-009')['status']);

        // A drafted answer already on the query is the one that goes.
        $this->send('api/donor-reports/respond', ['ref' => 'DR-26-006'])->assertOK();
        $this->seeInDatabase('donor_reports', ['reference' => 'DR-26-006', 'status' => 'submitted']);
    }

    public function testANewReportTakesItsFiguresFromTheLedger(): void
    {
        $this->signIn('m.otieno@elog.or.ke');

        $outside = $this->send('api/donor-reports', ['from' => '2024-07-01'] + $this->newReport());
        $outside->assertStatus(422);
        $this->assertSame('The period falls outside the award, which runs 01 Oct 2025 – 31 Mar 2027.', $this->error($outside));
        $this->send('api/donor-reports', ['due' => '2026-08-01'] + $this->newReport())->assertStatus(422);

        $created = $this->json($this->send('api/donor-reports', $this->newReport()));
        $this->assertSame('DR-26-019', $created['ref']);
        $this->send('api/donor-reports', $this->newReport())->assertStatus(422);

        // To the end of August the award's spend is everything posted to it.
        $report = $this->api('api/donor-reports/DR-26-019');
        $usaid = (new GrantRepository())->find('USAID/URAIA/2026');
        $this->assertSame(number_format($usaid['spent']), $report['cumulativeTotal']);
        $this->assertSame(['5110', '5140', '5150', '5210', '5310'], array_column($report['lines'], 'code'));
        $this->assertSame('62,000,000', $report['lines'][0]['budget']);
        $this->assertSame(number_format($usaid['received']), $report['received']);
        $this->assertSame('Figures taken from posted ledger actuals on 31 Aug 2026', $report['figuresNote']);
        $this->assertTrue($report['tied']);
    }

    public function testOnlyADraftHasItsFiguresRetaken(): void
    {
        $this->signIn('m.otieno@elog.or.ke');

        $this->send('api/donor-reports/refresh', ['ref' => 'DR-26-016'])->assertStatus(422);

        // A scheduled report starts without figures; taking them fills it and ties it.
        $this->assertSame([], $this->api('api/donor-reports/FORD/GA-24/R4')['lines']);
        $this->send('api/donor-reports/refresh', ['ref' => 'FORD/GA-24/R4'])->assertOK();
        $ford = $this->api('api/donor-reports/FORD/GA-24/R4');
        $this->assertSame(number_format((new GrantRepository())->find('FORD/GA-24')['spent']), $ford['cumulativeTotal']);

        // And retaking a draft's figures takes its manual adjustment away.
        $this->send('api/donor-reports/refresh', ['ref' => 'DR-26-017'])->assertOK();
        $this->assertTrue($this->api('api/donor-reports/DR-26-017')['tied']);
    }

    public function testTheReportPackExportsTheScheduleAndReconciliation(): void
    {
        $res = $this->get('api/donor-reports/export?ref=DR-26-017');
        $res->assertOK();
        $this->assertStringContainsString('donor report DR-26-017.csv', $res->response()->getHeaderLine('Content-Disposition'));

        $csv = (string) $res->response()->getBody();
        $this->assertStringContainsString('5120,"Training and workshops",24000000,5820000,16940000,71', $csv);
        $this->assertStringContainsString('Difference,"Manual adjustment not supported by a posted journal",1240000', $csv);
        $this->get('api/donor-reports/export?ref=DR-99-999')->assertStatus(404);
    }

    public function testTheAuditorReadsReportsButChangesNothing(): void
    {
        $this->signIn('audit@pkfea.com');

        $index = $this->api('api/donor-reports');
        $this->assertFalse($index['canPrepare']);
        $this->assertNull($index['newReport']);
        $this->assertSame([], array_keys(array_filter($this->api('api/donor-reports/DR-26-018')['can'])));
        $this->assertFalse($this->api('api/donor-reports/languages')['canChange']);

        $this->send('api/donor-reports/review', ['ref' => 'DR-26-018'])->assertStatus(403);
        $this->send('api/donor-reports/languages', ['funder' => 'European Union', 'locale' => 'es'])->assertStatus(403);
    }

    private function newReport(): array
    {
        return ['grant' => 'USAID/URAIA/2026', 'title' => 'Financial report Q4 2026', 'type' => 'financial', 'from' => '2026-07-01', 'to' => '2026-08-31', 'due' => '2026-10-31'];
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

    private function row(array $index, string $ref): array
    {
        foreach ($index['rows'] as $row) {
            if ($row['ref'] === $ref) {
                return $row;
            }
        }

        $this->fail($ref . ' is not on the page.');
    }

    private function userId(string $email): int
    {
        return (int) $this->db->table('users')->where('email', $email)->get()->getRow()->id;
    }

    private function reportId(string $ref): int
    {
        return (int) $this->db->table('donor_reports')->where('reference', $ref)->get()->getRow()->id;
    }

    private function error($res): string
    {
        return $this->json($res)['error'] ?? '';
    }

    private function json($res): array
    {
        return json_decode($res->getJSON(), true);
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
