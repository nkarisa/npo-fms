<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\Lookups;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Asset classes are chosen in Settings → Ledger: the cost account each is carried
 * in is a fixed-asset account, it is where a donated or found asset of the class is
 * debited on approval, and it cannot move while the class carries assets.
 */
final class AssetClassesTest extends CIUnitTestCase
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

    protected function tearDown(): void
    {
        unset($_COOKIE['elog_actor']);
        service('superglobals')->unsetCookie('elog_actor');
        parent::tearDown();
    }

    public function testAClassIsCarriedInAFixedAssetAccountThatHoldsWhileItCarriesAssets(): void
    {
        $settings = $this->api('api/settings');
        $classes = array_column($settings['assetClasses'], null, 'name');
        $this->assertSame(['1310', 'VEH', 5], [$classes['Motor vehicles']['cost'], $classes['Motor vehicles']['prefix'], $classes['Motor vehicles']['life']]);
        $this->assertGreaterThan(0, $classes['Motor vehicles']['carried']);
        // The accumulated depreciation account's group, less that account itself.
        $this->assertSame(['1310', '1320'], array_column($settings['assetClassOptions'], 'code'));

        $edit = static fn (array $change) => [$change + ['id' => $classes['Motor vehicles']['id'], 'name' => 'Motor vehicles', 'prefix' => 'VEH', 'life' => 5, 'cost' => '1310']];

        $this->refusal($edit(['cost' => '1320']), 'Motor vehicles carries ' . $classes['Motor vehicles']['carried'] . ' assets at cost in 1310, so its cost account is fixed');
        $this->refusal($edit(['cost' => '1110']), 'Motor vehicles has to be carried in a fixed-asset account — an asset account in the group of 1390 accumulated depreciation. 1110 is not one.');
        $this->refusal($edit(['prefix' => 'IT']), 'Computer equipment already numbers its tags under IT');
        $this->refusal($edit(['life' => 0]), 'Motor vehicles: enter a useful life of 1 to 50 years.');

        // Everything but the account can change under assets already registered, which keep their own.
        $saved = $this->save($edit(['name' => 'Vehicles', 'life' => 8]));
        $saved->assertStatus(200);
        $this->assertSame([
            'Asset class Motor vehicles renamed Vehicles',
            'Vehicles: useful life for new assets raised from 5 to 8 years — assets already registered keep theirs',
        ], array_column(json_decode($saved->getJSON(), true)['changes'], 'what'));

        // Only whoever holds settings.ledger changes them.
        $this->actAs('s.njeri@elog.or.ke');
        $this->save($edit(['life' => 6]))->assertStatus(403);
    }

    public function testADonationToANewClassIsDebitedToTheAccountChosenForIt(): void
    {
        $new = ['id' => null, 'name' => 'Furniture and fittings', 'prefix' => 'FUR', 'life' => 10, 'cost' => '1320'];

        $this->refusal([['cost' => '1110'] + $new], 'Furniture and fittings has to be carried in a fixed-asset account');
        $this->refusal([['cost' => '1390'] + $new], 'fixed-asset account');
        $this->refusal([['name' => 'furniture'] + $new], 'There is already an asset class called furniture.');

        $added = $this->save([$new]);
        $added->assertStatus(200);
        $this->assertSame(['Asset class Furniture and fittings added: tags FUR-001 on, 10-year life, carried in 1320 Office and ICT equipment — cost'],
            array_column(json_decode($added->getJSON(), true)['changes'], 'what'));

        // It carries nothing yet, so its account can still move.
        $id = array_column($this->api('api/settings')['assetClasses'], 'id', 'name')['Furniture and fittings'];
        $this->save([['id' => $id, 'cost' => '1310'] + $new])->assertStatus(200);

        $this->actAs('s.njeri@elog.or.ke');
        $proposed = json_decode($this->withBodyFormat('json')->post('api/assets/additions', [
            'basis' => 'donation', 'class' => 'Furniture and fittings', 'description' => 'Boardroom table and twelve chairs', 'amount' => '180,000',
            'date' => '2026-08-24', 'source' => 'Kenya Commercial Bank Foundation', 'reference' => 'KCBF/2026/19', 'reason' => 'Donated on the move; valued at the supplier quote',
            'life' => 10, 'location' => 'Boardroom', 'custodian' => 'M. Otieno', 'title' => '',
        ])->getJSON(), true);
        $this->assertSame('FUR-001', $proposed['additions'][0]['tag']);

        $before = (new Lookups())->balance('1310');
        $this->actAs('w.kamau@elog.or.ke');
        $this->withBodyFormat('json')->post('api/assets/additions/approve', ['tag' => 'FUR-001'])->assertStatus(200);
        Repository::forget();
        $this->assertEqualsWithDelta($before + 180000, (new Lookups())->balance('1310'), 0.001);

        // Now it carries one, the account is fixed.
        unset($_COOKIE['elog_actor']);
        service('superglobals')->unsetCookie('elog_actor');
        $this->refusal([['id' => $id, 'cost' => '1320'] + $new], 'Furniture and fittings carries 1 asset at cost in 1310');
    }

    private function save(array $classes)
    {
        return $this->withBodyFormat('json')->post('api/settings', ['assetClasses' => $classes]);
    }

    private function refusal(array $classes, string $expected): void
    {
        $res = $this->save($classes);
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
