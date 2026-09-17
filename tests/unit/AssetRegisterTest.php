<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\AssetRepository;
use App\Repositories\Lookups;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The asset register is the subsidiary record behind 1310, 1320 and 1390: it holds
 * the capitalised assets and agrees to the ledger, posts one depreciation run a
 * month, and takes an asset off only when a disposal proposed with its board
 * minute (and the donor's consent where title reverts) is approved by a second
 * person.
 */
final class AssetRegisterTest extends CIUnitTestCase
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

    public function testTheRegisterHoldsTheCapitalisedAssetsAndAgreesToTheLedger(): void
    {
        $list = $this->api('api/assets');

        $this->assertSame(9, $list['total']);
        $this->assertSame(['All (9)', 'In use (7)', 'Donor-funded (6)', 'Fully depreciated (1)', 'Disposed (1)'], array_column($list['tabs'], 'label'));
        $this->assertSame(
            ['Cost of assets held' => '21,085,000', 'Depreciation to date' => '7,412,000', 'Net book value' => '13,673,000', 'Charge for Aug 2026' => '373,625', 'Donor-funded book value' => '12,412,326'],
            array_column($list['stats'], 'value', 'label')
        );
        $this->assertSame('Depreciation for Aug 2026 not yet posted', $list['kicker']);
        $this->assertTrue($list['tie']['tied']);
        $this->assertSame(['nil', 'nil', 'nil'], array_column($list['tie']['rows'], 'diff'));

        // Items found in the count but never capitalised are counted, not registered.
        $this->assertNotContains('ELOG/VE/0009', array_column((new AssetRepository())->register(), 'tag'));
        $this->assertContains('ELOG/VE/0009', array_column((new AssetRepository())->countSheet(), 'tag'));
        $this->assertNull((new AssetRepository())->find('ELOG/VE/0009'));

        $this->assertSame(['VEH-001', 'IT-014'], array_column($this->api('api/assets?q=uraia')['rows'], 'tag'));
        $this->assertSame(['IT-008'], array_column($this->api('api/assets?filter=Fully%20depreciated')['rows'], 'tag'));

        $drawer = $this->api('api/assets/IT-030');
        $this->assertSame('29,167 a month · 3 years straight line', $drawer['scheduleHint']);
        $this->assertSame(['2026', '2027', '2028', '2029'], array_column($drawer['schedule'], 'year'));
        $this->assertTrue($drawer['dispose']['needsConsent']);
    }

    public function testTheMonthlyRunPostsDepreciationOnce(): void
    {
        $lookups = new Lookups();
        $accum = $lookups->balance('1390');
        $expense = $lookups->balance('5350');

        $this->actAs('audit@pkfea.com');
        $this->withBodyFormat('json')->post('api/assets/depreciation-run', [])->assertStatus(403);

        $this->actAs('s.njeri@elog.or.ke');
        $run = $this->withBodyFormat('json')->post('api/assets/depreciation-run', []);
        $run->assertStatus(200);
        $body = json_decode($run->getJSON(), true);
        $this->assertStringContainsString('Depreciation of 373,625 posted for Aug 2026', $body['message']);
        $this->assertSame('Depreciation posted for Aug 2026', $body['kicker']);
        $this->assertTrue($body['run']['done']);
        $this->assertTrue($body['tie']['tied']);

        Repository::forget();
        $lookups = new Lookups();
        $this->assertEqualsWithDelta($accum - 373625, $lookups->balance('1390'), 0.001);
        $this->assertEqualsWithDelta($expense + 373625, $lookups->balance('5350'), 0.001);
        $this->assertEquals(3500000, (new AssetRepository())->find('VEH-001')['accum']);
        $this->assertEquals(0, (new AssetRepository())->find('IT-008')['accum'] - 680000);

        $again = $this->withBodyFormat('json')->post('api/assets/depreciation-run', []);
        $again->assertStatus(422);
        $this->assertStringContainsString('already been posted', json_decode($again->getJSON(), true)['error']);
    }

    public function testADisposalNeedsItsMinuteTheDonorsConsentAndASecondPerson(): void
    {
        $this->actAs('s.njeri@elog.or.ke');
        $sale = ['tag' => 'VEH-001', 'method' => 'Public auction', 'date' => '2026-08-28', 'proceeds' => '5,500,000', 'buyer' => 'Garam Auctioneers',
            'minute' => 'BM/2026/08/14', 'consent' => '', 'reason' => 'High mileage; replaced under the new award'];

        $this->refusal(['minute' => ''] + $sale, 'board minute');
        $this->refusal($sale, 'reverts to USAID');
        $this->refusal(['method' => 'Write-off — beyond repair', 'consent' => 'USAID/KE/2026/311'] + $sale, 'cannot raise proceeds');
        $this->refusal(['date' => '2026-07-15', 'consent' => 'USAID/KE/2026/311'] + $sale, 'Jul 2026 is closed');

        $proposed = $this->withBodyFormat('json')->post('api/assets/disposals', ['consent' => 'USAID/KE/2026/311'] + $sale);
        $proposed->assertStatus(200);
        $body = json_decode($proposed->getJSON(), true);
        $this->assertSame('1 awaiting approval', $body['disposals']['hint']);
        $this->assertTrue($body['disposals']['rows'][0]['pending']);
        $this->assertSame('Gain', $body['disposals']['rows'][0]['resultLabel']);
        $this->assertSame('Disposal proposed, awaiting approval', $this->api('api/assets/VEH-001')['disposalNote']);
        $this->assertFalse($this->api('api/assets/VEH-001')['canDispose']);
        $this->refusal(['consent' => 'USAID/KE/2026/311'] + $sale, 'already waiting');

        // Nothing reaches the ledger until a second person approves it.
        $this->withBodyFormat('json')->post('api/assets/disposals/approve', ['tag' => 'VEH-001'])->assertStatus(403);
        $lookups = new Lookups();
        $before = ['1310' => $lookups->balance('1310'), '1390' => $lookups->balance('1390'), '1110' => $lookups->balance('1110'), '4250' => $lookups->balance('4250')];

        $this->actAs('w.kamau@elog.or.ke');
        $approved = $this->withBodyFormat('json')->post('api/assets/disposals/approve', ['tag' => 'VEH-001']);
        $approved->assertStatus(200);
        $body = json_decode($approved->getJSON(), true);
        $this->assertMatchesRegularExpression('/^JV-26-\d{4} posted — VEH-001 derecognised at cost of 8,400,000 with 3,360,000 of depreciation released, and a gain of 460,000 to 4250\.$/', $body['message']);
        $this->assertTrue($body['disposals']['rows'][0]['posted']);
        $this->assertTrue($body['tie']['tied']);
        $this->assertSame('Disposed', (new AssetRepository())->find('VEH-001')['status']);

        Repository::forget();
        $lookups = new Lookups();
        $this->assertEqualsWithDelta($before['1310'] - 8400000, $lookups->balance('1310'), 0.001);
        $this->assertEqualsWithDelta($before['1390'] + 3360000, $lookups->balance('1390'), 0.001);
        $this->assertEqualsWithDelta($before['1110'] + 5500000, $lookups->balance('1110'), 0.001);
        $this->assertEqualsWithDelta($before['4250'] + 460000, $lookups->balance('4250'), 0.001);
        $this->assertStringContainsString('Disposal approved by W. Kamau', implode(' ', array_column($this->api('api/assets/VEH-001')['trail'], 'what')));
    }

    public function testTheProposerCannotApproveAndAProposalCanBeWithdrawn(): void
    {
        $this->actAs('w.kamau@elog.or.ke');
        $writeOff = ['tag' => 'IT-021', 'method' => 'Write-off — beyond repair', 'date' => '2026-08-20', 'proceeds' => '0', 'buyer' => 'E-waste Kenya',
            'minute' => 'BM/2026/08/15', 'consent' => '', 'reason' => 'Motherboard failed; not economic to repair'];
        $this->withBodyFormat('json')->post('api/assets/disposals', $writeOff)->assertStatus(200);

        $own = $this->withBodyFormat('json')->post('api/assets/disposals/approve', ['tag' => 'IT-021']);
        $own->assertStatus(422);
        $this->assertStringContainsString('cannot approve it', json_decode($own->getJSON(), true)['error']);

        $withdrawn = $this->withBodyFormat('json')->post('api/assets/disposals/withdraw', ['tag' => 'IT-021']);
        $withdrawn->assertStatus(200);
        $this->assertSame([], json_decode($withdrawn->getJSON(), true)['disposals']['rows']);
        $this->assertTrue($this->api('api/assets/IT-021')['canDispose']);
        $this->assertStringContainsString('withdrawn by W. Kamau', implode(' ', array_column($this->api('api/assets/IT-021')['trail'], 'what')));
    }

    private function refusal(array $body, string $expected): void
    {
        $res = $this->withBodyFormat('json')->post('api/assets/disposals', $body);
        $res->assertStatus(422);
        $this->assertStringContainsString($expected, json_decode($res->getJSON(), true)['error']);
    }

    private function actAs(string $email): void
    {
        $_COOKIE['elog_actor'] = $email;
        service('superglobals')->setCookie('elog_actor', $email);
    }

    private function api(string $url): array
    {
        return json_decode($this->get($url)->getJSON(), true);
    }
}
