<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\ApprovalPolicy;
use App\Repositories\Lookups;
use App\Repositories\ReceivablesRepository;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use App\Repositories\SettingsRepository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Settings: one draft saved together, every change in the audit log in words, and
 * a change that would break a control refused with nothing applied.
 */
final class SettingsTest extends CIUnitTestCase
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

    public function testTheScreenIsServedAsThePrototypeSetsItOut(): void
    {
        $s = $this->api('api/settings');

        $this->assertSame(['Organisation', 'Ledger', 'Segments', 'Currencies', 'Approvals', 'Bank statements', 'Integrations', 'Payroll', 'Language and translation', 'Users', 'Audit log'], array_column($s['sections'], 'key'));
        $this->assertTrue($s['canManage']);
        $this->assertSame(['registeredName' => 'Elections Observation Group', 'shortName' => 'ELOG', 'taxPin' => 'P051290384H', 'ngoReg' => 'OP/218/051/2010/0142'], $s['organisation']);
        $this->assertSame(['framework' => 'IFRS', 'currency' => 'KES', 'yearEnd' => '31 December', 'codeLength' => '4 digits'], $s['ledger']);
        $this->assertCount(6, $s['toggles']);
        $this->assertSame(['KES', 'USD', 'EUR', 'DKK', 'GBP'], array_column($s['currencies'], 'code'));
        $this->assertTrue($s['currencies'][0]['base']);
        $this->assertFalse($s['currencies'][4]['active']);
        $this->assertSame(['house_allowance', 'transport_allowance'], array_column($s['benefits'], 'key'));
        $this->assertSame(['G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7'], array_column($s['grades'], 'grade'));
        $this->assertEquals(['house_allowance' => 30, 'transport_allowance' => 21000], $s['grades'][3]['ben']);
        $this->assertSame(['Finance Manager', 'Executive Director'], $s['approverRoles']);
        $this->assertSame('Payment run threshold raised from 1,500,000 to 2,000,000', $s['audit'][0]['what']);
        $this->assertTrue($s['language']['formatsLocked']);

        // Claims offer the active currencies at their indicative rates.
        $this->assertEquals(['KES' => 1.0, 'USD' => 129.4, 'EUR' => 139.8, 'DKK' => 18.75], ReceivablesRepository::currencies());
    }

    public function testASaveAppliesTheDraftAndLogsEachChangeInWords(): void
    {
        $s = $this->api('api/settings');
        $draft = [
            'organisation' => ['shortName' => 'ELOG Kenya'] + $s['organisation'],
            'toggles'      => ['budgetCheck' => true],
            'segments'     => ['funder' => true],
            'currencies'   => array_merge(
                array_map(static fn ($c) => ['code' => $c['code'], 'name' => $c['name'], 'rate' => $c['code'] === 'USD' ? '130.10' : $c['rate'], 'active' => $c['code'] === 'GBP' ? true : $c['active']], $s['currencies']),
                [['code' => 'SEK', 'name' => 'Swedish Krona', 'rate' => '12.40', 'active' => true]]
            ),
            'approvals'    => ['payment' => ['threshold' => '2,500,000', 'approver' => 'Executive Director'], 'journal' => ['threshold' => 500000, 'approver' => 'Executive Director']],
            'payroll'      => [
                'benefits' => array_merge(
                    array_map(static fn ($b) => ['key' => $b['key'], 'name' => $b['name'], 'basis' => $b['basis'], 'taxable' => $b['taxable'], 'active' => $b['active']], $s['benefits']),
                    [['key' => 'new-1', 'name' => 'Airtime allowance', 'basis' => 'flat', 'taxable' => true, 'active' => true]]
                ),
                'grades' => array_merge(
                    array_map(static fn ($g) => ['grade' => $g['grade'], 'band' => $g['band'], 'active' => $g['active'], 'ben' => $g['grade'] === 'G4' ? ['transport_allowance' => 23000] + $g['ben'] : $g['ben']], $s['grades']),
                    [['grade' => 'G8', 'band' => 'Intern', 'active' => true, 'ben' => ['house_allowance' => 0, 'transport_allowance' => 5000, 'new-1' => 1500]]]
                ),
            ],
            'users'        => ['s.njeri@elog.or.ke' => 'Senior Accountant'],
            'language'     => ['formatsLocked' => false],
        ];

        $saved = $this->json($this->withBodyFormat('json')->post('api/settings', $draft));

        $this->assertSame('Settings saved. 13 changes have been written to the audit log.', $saved['message']);
        $this->assertSame([
            'Short name changed from ELOG to ELOG Kenya',
            'Block postings that exceed the budget line — turned on',
            'Funder segment made mandatory on restricted funds only',
            'USD indicative rate changed from 129.40 to 130.10',
            'GBP enabled on new awards and donor claims',
            'SEK (Swedish Krona) added at 12.40 to the KES',
            'Journal entries approver changed from Finance Manager to Executive Director',
            'Payment runs threshold raised from 2,000,000 to 2,500,000',
            'Airtime allowance added as a flat taxable benefit',
            'G4 transport allowance changed from 21,000 to 23,000',
            'G8 · Intern added to the grade scale',
            'S. Njeri moved from Accountant to Senior Accountant',
            'Numbers, dates and currency released to each user\'s locale',
        ], array_column($saved['changes'], 'what'));

        $this->assertSame('ELOG Kenya', $saved['organisation']['shortName']);
        $this->assertTrue($saved['toggles'][5]['on']);
        $this->assertSame('SEK', end($saved['currencies'])['code']);
        $this->assertSame('Executive Director', current(array_filter($saved['approvals'], static fn ($a) => $a['key'] === 'journal'))['approver']);
        $this->assertSame('Airtime allowance', end($saved['benefits'])['name']);
        $g8 = end($saved['grades']);
        $this->assertEquals(['G8', 'Intern', 5000, 1500], [$g8['grade'], $g8['band'], $g8['ben']['transport_allowance'], $g8['ben']['airtime_allowance']]);
        $this->assertSame('Senior Accountant', current(array_filter($saved['users'], static fn ($u) => $u['email'] === 's.njeri@elog.or.ke'))['role']);
        $this->assertFalse($saved['language']['formatsLocked']);
        $this->assertSame('Language', $saved['audit'][0]['area']);
        $this->assertSame('W. Kamau', $saved['audit'][0]['who']);

        // The approval policy reads the rule as saved.
        Repository::forget();
        $this->assertSame('Executive Director', (new ApprovalPolicy())->rule('journal')['approver']);
        $this->assertArrayHasKey('SEK', ReceivablesRepository::currencies());

        // Saving the same draft again changes nothing.
        $again = $this->json($this->withBodyFormat('json')->post('api/settings', ['currencies' => array_map(static fn ($c) => ['code' => $c['code'], 'name' => $c['name'], 'rate' => $c['rate'], 'active' => $c['active']], $saved['currencies'])]));
        $this->assertSame('No changes to save.', $again['message']);
    }

    public function testChangesThatWouldBreakAControlAreRefusedWithNothingApplied(): void
    {
        $settings = new SettingsRepository();
        $kamau = (new Lookups())->userId('W. Kamau');
        $refused = function (array $draft, string $reason) use ($settings, $kamau) {
            try {
                $settings->save($draft + ['organisation' => ['shortName' => 'Changed']], $kamau);
                $this->fail('Expected a refusal: ' . $reason);
            } catch (RuleViolation $e) {
                $this->assertStringContainsString($reason, $e->getMessage());
            }
            Repository::forget();
            $this->assertSame('ELOG', $settings->organisation()['shortName']);
        };

        $currencies = static fn (callable $change) => array_map($change, (new SettingsRepository())->currencies());
        $refused(['currencies' => $currencies(static fn ($c) => array_merge($c, ['active' => $c['code'] === 'KES' ? false : $c['active']]))], 'KES is the reporting currency and cannot be disabled');
        $refused(['currencies' => [['code' => 'KSH', 'name' => '', 'rate' => '1']]], 'Name KSH');
        $refused(['currencies' => [['code' => 'usdollar', 'name' => 'x', 'rate' => '1']]], 'three-letter ISO code');
        $refused(['ledger' => ['currency' => 'USD']], 'cannot change once the ledger holds postings');
        $refused(['ledger' => ['codeLength' => '5 digits']], 'do not have 5-digit codes');
        $refused(['approvals' => ['journal' => ['approver' => 'Senior Accountant']]], 'has no approval rights');
        $refused(['organisation' => ['taxPin' => 'P0512']], 'is not a KRA PIN');
        $refused(['users' => ['w.kamau@elog.or.ke' => 'Accountant']], 'no active Finance Manager');
        $refused(['payroll' => ['grades' => [['grade' => 'G4', 'band' => 'Officer', 'active' => false, 'ben' => []]]]], 'G4 is held by');
        $refused(['payroll' => ['benefits' => [['key' => 'house_allowance', 'name' => 'House allowance', 'basis' => 'pct', 'taxable' => true, 'active' => false]]]], 'House allowance is paid to');
        $refused(['payroll' => ['grades' => [['grade' => 'G9', 'band' => 'Casual', 'ben' => ['house_allowance' => 120]]]]], 'percentage of basic pay');

        $this->assertSame(0, db_connect()->table('audit_events')->where('action', 'settings.changed')->like('summary', 'Changed')->countAllResults());
    }

    public function testOnlyTheFinanceManagerSavesAndInvitesUsers(): void
    {
        $this->actAs('d.kiptoo@elog.or.ke');
        $this->assertFalse($this->api('api/settings')['canManage']);
        $this->withBodyFormat('json')->post('api/settings', ['toggles' => ['budgetCheck' => true]])->assertStatus(403);
        $this->withBodyFormat('json')->post('api/settings/invite', ['name' => 'A B', 'email' => 'a@b.co', 'role' => 'Accountant'])->assertStatus(403);

        $this->actAs('w.kamau@elog.or.ke');
        $this->withBodyFormat('json')->post('api/settings/invite', ['name' => 'Joyce Achieng', 'email' => 'J.Achieng@elog.or.ke', 'role' => 'Accountant'])->assertStatus(422);
        $invited = $this->json($this->withBodyFormat('json')->post('api/settings/invite', [
            'name' => 'Amina Hassan', 'email' => 'a.hassan@elog.or.ke', 'role' => 'Programme Officer', 'entities' => ['ELOG-CST'],
        ]));
        $this->assertStringStartsWith('Invitation sent to a.hassan@elog.or.ke', $invited['message']);
        $user = current(array_filter($invited['users'], static fn ($u) => $u['email'] === 'a.hassan@elog.or.ke'));
        $this->assertSame(['Programme Officer', 'Coast', 'Invited', 'AH'], [$user['role'], $user['entities'], $user['status'], $user['initials']]);
        $this->assertSame('A. Hassan invited as Programme Officer — ELOG Coast Regional Office', $invited['audit'][0]['what']);
    }

    // ------------------------------------------------------------------

    private function api(string $url): array
    {
        return json_decode($this->get($url)->getJSON(), true);
    }

    private function json($response): array
    {
        $response->assertStatus(200);

        return json_decode($response->getJSON(), true);
    }

    private function actAs(string $email): void
    {
        $_COOKIE['elog_actor'] = $email;
        service('superglobals')->setCookie('elog_actor', $email);
    }
}
