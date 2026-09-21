<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\Lookups;
use App\Repositories\PostingAccounts;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The accounts the system posts to itself are chosen in Settings → Ledger: each
 * role starts at its standard code, a change applies to postings from then on,
 * and a control account cannot move while it still holds a balance to clear.
 */
final class PostingAccountsTest extends CIUnitTestCase
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

    public function testEachPostingGoesToTheAccountChosenForItsRole(): void
    {
        $settings = $this->api('api/settings');
        $roles = array_column($settings['postingAccounts'], null, 'role');
        $this->assertSame(array_keys(PostingAccounts::ROLES), array_keys($roles));
        $this->assertSame(['2110', 'Trade payables', 'Payables'], [$roles['payables']['code'], $roles['payables']['label'], $roles['payables']['module']]);
        $this->assertNotEquals(0, $roles['payables']['balance']);
        $save = fn (array $accounts) => $this->withBodyFormat('json')->post('api/settings', ['postingAccounts' => $accounts]);
        $error = static fn ($response) => json_decode($response->getJSON(), true)['error'];

        // Unpaid bills are cleared from 2110, so it cannot move under them.
        $held = $save(['payables' => '2120']);
        $held->assertStatus(422);
        $this->assertStringContainsString('2110 still holds KES', $error($held));
        // The account has to be of the kind the posting needs.
        $income = $save(['depreciation' => '4230']);
        $income->assertStatus(422);
        $this->assertStringContainsString('Depreciation charge has to be an expense account. 4230', $error($income));
        $this->assertStringContainsString('is not an active account that can be posted to', $error($save(['depreciation' => '5300'])));

        $moved = $save(['depreciation' => '5340']);
        $moved->assertStatus(200);
        $this->assertSame(['Depreciation charge posts to 5340 Bank charges instead of 5350'], array_column(json_decode($moved->getJSON(), true)['changes'], 'what'));
        $this->assertSame('5340', PostingAccounts::of('depreciation'));

        // The next run charges the new account; the old one keeps what it already holds.
        $lookups = new Lookups();
        [$old, $new] = [$lookups->balance('5350'), $lookups->balance('5340')];
        $this->actAs('s.njeri@elog.or.ke');
        $this->withBodyFormat('json')->post('api/assets/depreciation-run', [])->assertStatus(200);
        Repository::forget();
        $lookups = new Lookups();
        $this->assertEqualsWithDelta($old, $lookups->balance('5350'), 0.001);
        $this->assertEqualsWithDelta($new + 373625, $lookups->balance('5340'), 0.001);

        // Only the settings manager chooses them.
        $save(['depreciation' => '5350'])->assertStatus(403);
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
