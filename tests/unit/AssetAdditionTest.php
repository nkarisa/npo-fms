<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\AssetAdditionRepository;
use App\Repositories\AssetRepository;
use App\Repositories\JournalRepository;
use App\Repositories\Lookups;
use App\Repositories\PeriodCloseRepository;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * How an asset comes onto the register: a purchase already in the ledger is
 * capitalised from it, posting nothing new; an asset with no purchase behind it —
 * donated, or found in a count and never recorded — posts when a second person
 * approves it; and nothing that was bought can be added the second way.
 */
final class AssetAdditionTest extends CIUnitTestCase
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

    public function testAPurchaseIsCapitalisedFromTheLedgerWithoutPostingAgain(): void
    {
        $ref = $this->purchase(1260000);
        $journals = (int) db_connect()->table('journals')->countAllResults();

        $list = $this->api('api/assets');
        $this->assertSame([$ref], array_column($list['capitalise']['rows'], 'ref'));
        $this->assertFalse($list['tie']['tied']);
        $this->assertStringContainsString('1,260,000 on 1 purchase is in the ledger but not yet capitalised', $list['tie']['awaiting']);
        $line = $list['capitalise']['rows'][0]['line'];

        $this->actAs('audit@pkfea.com');
        $this->withBodyFormat('json')->post('api/assets/capitalise', $this->form($line, 1260000))->assertStatus(403);

        $this->actAs('s.njeri@elog.or.ke');
        $this->refusal('api/assets/capitalise', ['class' => 'Motor vehicles'] + $this->form($line, 1260000), 'carried on 1310');
        $this->refusal('api/assets/capitalise', $this->form($line, 1300000), 'Only 1,260,000');

        $first = $this->withBodyFormat('json')->post('api/assets/capitalise', ['units' => 3] + $this->form($line, 900000));
        $first->assertStatus(200);
        $body = json_decode($first->getJSON(), true);
        $this->assertSame(['IT-031', 'IT-032', 'IT-033'], $body['tags']);
        $this->assertEquals(360000, $body['capitalise']['rows'][0]['remaining']);

        $rest = json_decode($this->withBodyFormat('json')->post('api/assets/capitalise', $this->form($line, 360000))->getJSON(), true);
        $this->assertSame(['IT-034'], $rest['tags']);
        $this->assertSame([], $rest['capitalise']['rows']);
        $this->assertTrue($rest['tie']['tied']);
        $this->assertSame(13, $rest['total']);
        $this->assertSame($journals, (int) db_connect()->table('journals')->countAllResults());

        $asset = (new AssetRepository())->find('IT-031');
        $this->assertEquals(300000, $asset['cost']);
        $this->assertSame('2026-08-20', $asset['acquiredOn']);
        $this->assertSame('In use', $asset['status']);
        // Bought in August, so August carries no charge.
        $this->assertEquals(0, (new AssetRepository())->charge($asset, (new AssetRepository())->period()));
        $this->assertStringContainsString('Capitalised from ' . $ref, implode(' ', array_column($asset['trail'], 'what')));

        try {
            (new JournalRepository())->reverse($ref, (new Lookups())->userId('w.kamau@elog.or.ke'));
            $this->fail('A capitalised purchase was reversed.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('capitalised on the asset register as IT-031, IT-032, IT-033 and 1 more', $e->getMessage());
        }
    }

    public function testADonatedAssetPostsToIncomeOnlyWhenASecondPersonApproves(): void
    {
        $this->actAs('s.njeri@elog.or.ke');
        $gift = ['basis' => 'donation', 'class' => 'Office equipment', 'description' => 'Konica Minolta bizhub C300i copier', 'amount' => '420,000',
            'date' => '2026-08-24', 'source' => 'Konrad Adenauer Stiftung', 'reference' => 'KAS/KE/2026/044', 'reason' => 'Donated for the call centre; valued at the supplier quote',
            'life' => 5, 'location' => 'Call centre, 2nd floor', 'custodian' => 'M. Otieno', 'title' => ''];

        $this->refusal('api/assets/additions', ['reference' => ''] + $gift, 'deed of gift');
        $this->refusal('api/assets/additions', ['date' => '2026-07-10'] + $gift, 'Jul 2026 is closed');

        $proposed = json_decode($this->withBodyFormat('json')->post('api/assets/additions', $gift)->getJSON(), true);
        $this->assertSame('EQP-010', $proposed['additions'][0]['tag']);
        $this->assertSame('4260', $proposed['additions'][0]['credit']);
        $this->assertSame(9, $proposed['total']);

        $before = [(new Lookups())->balance('1320'), (new Lookups())->balance('4260')];
        $this->withBodyFormat('json')->post('api/assets/additions/approve', ['tag' => 'EQP-010'])->assertStatus(403);

        $this->actAs('w.kamau@elog.or.ke');
        $approved = json_decode($this->withBodyFormat('json')->post('api/assets/additions/approve', ['tag' => 'EQP-010'])->getJSON(), true);
        $this->assertMatchesRegularExpression('/^JV-26-\d{4} posted — EQP-010 is on the register at 420,000, credited to 4260 donated assets\.$/', $approved['message']);
        $this->assertSame(10, $approved['total']);
        $this->assertSame([], $approved['additions']);
        $this->assertTrue($approved['tie']['tied']);

        Repository::forget();
        $this->assertEqualsWithDelta($before[0] + 420000, (new Lookups())->balance('1320'), 0.001);
        $this->assertEqualsWithDelta($before[1] + 420000, (new Lookups())->balance('4260'), 0.001);
        $this->assertSame('Capital Fund', (new AssetRepository())->find('EQP-010')['fund']);
    }

    public function testAnAssetFoundInTheCountCorrectsTheFundBalance(): void
    {
        $found = ['basis' => 'found', 'tag' => 'ELOG/VE/0009', 'amount' => '3,240,000', 'date' => '2026-08-31', 'source' => 'Asset count AV-2026-02',
            'reference' => '', 'reason' => 'On the fleet list but never capitalised; carried at the count valuation'];

        $this->actAs('w.kamau@elog.or.ke');
        $this->withBodyFormat('json')->post('api/assets/additions', $found)->assertStatus(200);
        $own = $this->withBodyFormat('json')->post('api/assets/additions/approve', ['tag' => 'ELOG/VE/0009']);
        $own->assertStatus(422);
        $this->assertStringContainsString('cannot approve it', json_decode($own->getJSON(), true)['error']);
        $withdrawn = json_decode($this->withBodyFormat('json')->post('api/assets/additions/withdraw', ['tag' => 'ELOG/VE/0009'])->getJSON(), true);
        $this->assertContains('ELOG/VE/0009', array_column($withdrawn['uncapitalised'], 'tag'));

        $this->actAs('s.njeri@elog.or.ke');
        $this->withBodyFormat('json')->post('api/assets/additions', $found)->assertStatus(200);
        $before = [(new Lookups())->balance('1310'), (new Lookups())->balance('3100')];

        $this->actAs('w.kamau@elog.or.ke');
        $approved = json_decode($this->withBodyFormat('json')->post('api/assets/additions/approve', ['tag' => 'ELOG/VE/0009'])->getJSON(), true);
        $this->assertStringContainsString('credited to 3100 fund balance as a correction of the earlier omission', $approved['message']);
        $this->assertSame(10, $approved['total']);
        $this->assertNotContains('ELOG/VE/0009', array_column($approved['uncapitalised'], 'tag'));
        $this->assertTrue($approved['tie']['tied']);

        Repository::forget();
        $this->assertEqualsWithDelta($before[0] + 3240000, (new Lookups())->balance('1310'), 0.001);
        $this->assertEqualsWithDelta($before[1] + 3240000, (new Lookups())->balance('3100'), 0.001);
        $this->assertSame('2026-08-31', (new AssetRepository())->find('ELOG/VE/0009')['acquiredOn']);
    }

    public function testSomethingBoughtCannotBeAddedWithoutItsPurchase(): void
    {
        $this->purchase(500000);

        $this->actAs('s.njeri@elog.or.ke');
        $this->refusal('api/assets/additions', ['basis' => 'donation', 'class' => 'Computer equipment', 'description' => 'Laptops', 'amount' => '500000',
            'date' => '2026-08-24', 'source' => 'A donor', 'reference' => 'LTR/1', 'reason' => 'Given', 'life' => 3, 'location' => 'Stores', 'custodian' => '', 'title' => ''],
            'is waiting to be capitalised');

        $this->assertCount(1, (new AssetAdditionRepository())->awaiting());
        $close = new PeriodCloseRepository();
        $depn = array_column($close->checklist($close->period('Aug 2026')), null, 'key')['depn'];
        $this->assertFalse($depn['settled']);
        $this->assertSame('1 purchase on 1310 or 1320 is not yet capitalised, so the register does not depreciate them', $depn['note']);
    }

    /** Posts a supplier purchase of laptops charged to 1320, paid from KCB. */
    private function purchase(float $amount): string
    {
        $lookups = new Lookups();
        $fund = (int) array_values(array_filter($lookups->funds(), static fn ($f) => $f['name'] === 'General Fund'))[0]['id'];
        $shared = $lookups->programmeId('Shared services');
        $line = static fn ($code, $dr, $cr) => ['code' => $code, 'fund_id' => $fund, 'programme_id' => $shared, 'grant_id' => null, 'desc' => 'Dell Latitude laptops', 'dr' => $dr, 'cr' => $cr];

        $ref = (new JournalRepository())->postFromSource([
            'date' => '2026-08-20', 'narration' => 'Dell Latitude laptops — Copycat Ltd', 'memo' => '', 'sourceType' => 'bill', 'sourceId' => 1,
            'docRef' => 'INV-CC-7781', 'series' => 'JV',
        ], [$line('1320', $amount, 0), $line('1110', 0, $amount)], (int) $lookups->userId('s.njeri@elog.or.ke'), (int) $lookups->userId('w.kamau@elog.or.ke'), 'Test purchase');
        Repository::forget();

        return $ref;
    }

    private function form(int $line, float $amount): array
    {
        return ['line' => $line, 'class' => 'Computer equipment', 'description' => 'Dell Latitude 5550 laptop', 'units' => 1, 'amount' => $amount,
            'life' => 3, 'location' => 'Secretariat, Kilimani', 'custodian' => 'M. Otieno', 'title' => 'ELOG retains title', 'serial' => ''];
    }

    private function refusal(string $url, array $body, string $expected): void
    {
        $res = $this->withBodyFormat('json')->post($url, $body);
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
