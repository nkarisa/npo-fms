<?php

use App\Database\Migrations\SplitSettingsPermissions;
use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Each section of Settings is seen and changed by the roles its permission is
 * given to (App\Libraries\SettingsAccess), not by everyone: a section someone
 * cannot see is not served, data and all, and a save that would change a section
 * they cannot change is refused whole.
 */
final class SettingsAccessTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use \Tests\Support\SignsIn;

    protected $namespace = 'App';
    protected $refresh   = true;
    protected $seed      = DatabaseSeeder::class;

    private const EVERYDAY = ['Organisation', 'Ledger', 'Segments', 'Currencies', 'Taxes', 'Terms and reminders', 'Approvals',
        'Bank statements', 'Opening balances', 'Appearance', 'Language and translation'];

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['app.asOf'] = '2026-08-31';
        Repository::forget();
    }

    public function testEachRoleSeesTheSectionsItsPermissionsShow(): void
    {
        $s = $this->api('get', 'api/settings');
        $this->assertCount(17, $s['sections'], 'The Finance Manager sees every section');
        $this->assertSame(['Audit log'], array_column(array_filter($s['sections'], static fn ($x) => !$x['canEdit']), 'key'), 'and changes all but the log');

        $this->signIn('s.njeri@elog.or.ke');
        $s = $this->api('get', 'api/settings');
        $this->assertSame(self::EVERYDAY, array_column($s['sections'], 'key'),
            'An accountant sees the everyday sections, and not the ones with a permission of their own');
        $this->assertSame([], array_filter(array_column($s['sections'], 'canEdit')), 'and changes none of them');
        foreach (['users', 'roleDetail', 'permissionCatalogue', 'audit', 'benefits', 'grades', 'payAccounts'] as $key) {
            $this->assertArrayNotHasKey($key, $s, $key . ' belongs to a section the accountant cannot see, so it is not served');
        }
        $this->assertFalse($s['canManage']);
        foreach (['api/mpesa', 'api/mail', 'api/roles'] as $url) {
            $this->api('get', $url, [], 403);
        }

        $this->signIn('d.kiptoo@elog.or.ke');
        $this->assertSame(['Organisation', 'Ledger', 'Segments', 'Currencies', 'Taxes', 'Terms and reminders', 'Approvals', 'Bank statements',
            'Opening balances', 'Payroll', 'Appearance', 'Language and translation', 'Audit log'], $this->keys($this->api('get', 'api/settings')['sections']),
            'The Executive Director also sees payroll, which they hold payroll.view for, and the audit log');

        $this->signIn('audit@pkfea.com');
        $s = $this->api('get', 'api/settings');
        $this->assertSame([...self::EVERYDAY, 'Audit log'], $this->keys($s['sections']), 'The auditor reads the log');
        $this->assertNotEmpty($s['audit']);
    }

    public function testSomeoneWithNoSettingsIsNotOfferedThePage(): void
    {
        $this->api('post', 'api/users/' . $this->userId('s.njeri@elog.or.ke') . '/access', ['access' => [['role' => 'Programme Officer', 'entities' => 'all']]]);
        $this->signIn('s.njeri@elog.or.ke');

        $this->assertStringContainsString('Seeing them needs a role with settings.view', $this->api('get', 'api/settings', [], 403)['error']);
        $this->get('/settings')->assertStatus(403);
        $this->assertStringNotContainsString('href="/settings"', (string) $this->get('/')->getBody());

        $this->signIn('w.kamau@elog.or.ke');
        $this->assertStringContainsString('href="/settings"', (string) $this->get('/')->getBody());
    }

    public function testADutyIsChangedOnlyByTheRoleItIsGivenTo(): void
    {
        $this->api('post', 'api/roles', ['name' => 'Controls Officer', 'description' => 'Sets the approval bands', 'permissions' => ['settings.view', 'settings.approvals']]);
        $this->api('post', 'api/users/' . $this->userId('s.njeri@elog.or.ke') . '/access', ['access' => [['role' => 'Controls Officer', 'entities' => 'all']]]);
        $this->signIn('s.njeri@elog.or.ke');

        $s = $this->api('get', 'api/settings');
        $this->assertSame(['Approvals'], array_column(array_filter($s['sections'], static fn ($x) => $x['canEdit']), 'key'));
        $this->assertTrue($s['canManage']);
        $journal = $this->threshold($s);

        // A ledger change alongside refuses the whole save: not even the approval band is applied.
        $toggle = $s['toggles'][0];
        $refused = $this->api('post', 'api/settings', ['approvals' => ['journal' => ['threshold' => 123000]], 'toggles' => [$toggle['key'] => !$toggle['on']]], 403);
        $this->assertStringContainsString('Changing Ledger needs a role with settings.ledger', $refused['error']);
        $this->assertSame($journal, $this->threshold($this->api('get', 'api/settings')));

        // The whole draft as the screen sends it, with only the band changed, is theirs to save.
        $saved = $this->api('post', 'api/settings', ['approvals' => ['journal' => ['threshold' => 123000]], 'toggles' => [$toggle['key'] => $toggle['on']]]);
        $this->assertCount(1, $saved['changes']);
        $this->assertNotSame($journal, $this->threshold($saved));

        // The logo is Appearance, which is not theirs.
        $this->api('post', 'api/settings/logo/remove', [], 403);
    }

    public function testPayrollCanBeSomeoneElsesWithoutTheRestOfSettings(): void
    {
        $this->api('post', 'api/roles', ['name' => 'Payroll Officer', 'description' => 'Keeps the grade scale', 'permissions' => ['settings.payroll']]);
        $this->api('post', 'api/users/' . $this->userId('s.njeri@elog.or.ke') . '/access', ['access' => [['role' => 'Payroll Officer', 'entities' => 'all']]]);
        $this->signIn('s.njeri@elog.or.ke');

        $s = $this->api('get', 'api/settings');
        $this->assertSame(['Payroll'], array_column($s['sections'], 'key'));
        $this->assertArrayNotHasKey('organisation', $s);
        $this->assertArrayHasKey('grades', $s);

        $grade = $s['grades'][0];
        $saved = $this->api('post', 'api/settings', ['payroll' => ['grades' => [['grade' => $grade['grade'], 'band' => $grade['band'] . ' (renamed)', 'ben' => $grade['ben'], 'active' => $grade['active']]]]]);
        $this->assertStringContainsString('band renamed', $saved['changes'][0]['what']);
    }

    public function testTheMigrationKeepsWhatEachRoleCouldDo(): void
    {
        $db = db_connect();
        // Back to settings.manage, held by the Finance Manager alone, as before the split.
        (new SplitSettingsPermissions())->down();
        $this->assertNull($db->table('permissions')->where('key', 'settings.ledger')->get()->getRow());

        (new SplitSettingsPermissions())->up();
        Repository::forget();
        $this->assertNull($db->table('permissions')->where('key', 'settings.manage')->get()->getRow());

        $held = fn (string $role) => array_column($db->query(
            'SELECT p.key FROM ' . $db->prefixTable('role_permissions') . ' rp JOIN ' . $db->prefixTable('permissions') . ' p ON p.id = rp.permission_id
             JOIN ' . $db->prefixTable('roles') . ' r ON r.id = rp.role_id WHERE r.name = ?', [$role]
        )->getResultArray(), 'key');

        $this->assertEmpty(array_diff(['settings.view', 'settings.organisation', 'settings.ledger', 'settings.approvals', 'settings.banking',
            'settings.integrations', 'settings.payroll', 'audit.view'], $held('Finance Manager')));
        // An approver kept the bank statement formats, and anyone who could look still can.
        $this->assertEqualsCanonicalizing(['ledger.view', 'journal.approve', 'journal.post', 'payroll.view', 'settings.view', 'settings.banking', 'period.authorise', 'audit.view'],
            $held('Executive Director'));
        $this->assertEqualsCanonicalizing(['ledger.view', 'journal.prepare', 'requisition.raise', 'settings.view'], $held('Accountant'));
        $this->assertEqualsCanonicalizing(['ledger.view', 'settings.view', 'audit.view'], $held('Auditor (read only)'));
    }

    // ------------------------------------------------------------------

    /** Section keys, in the order the API serves them — the screen's. */
    private function keys(array $sections): array
    {
        return array_column($sections, 'key');
    }

    private function threshold(array $settings): mixed
    {
        return current(array_filter($settings['approvals'], static fn ($a) => $a['key'] === 'journal'))['threshold'];
    }

    private function api(string $method, string $url, array $body = [], int $status = 200): array
    {
        Repository::forget();
        $result = $method === 'get' ? $this->get($url) : $this->withBodyFormat('json')->post($url, $body);
        $json = json_decode((string) $result->getJSON(), true) ?? [];
        $this->assertSame($status, $result->response()->getStatusCode(), $method . ' ' . $url . ': ' . ($json['error'] ?? ''));

        return $json;
    }

    private function userId(string $email): int
    {
        return (int) db_connect()->table('users')->where('email', $email)->get()->getRow()->id;
    }
}
