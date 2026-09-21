<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Roles: people get permissions only by holding roles, can hold any number of
 * them at the entities named, and an organisation can define as many as it needs.
 * Nothing may leave the organisation without someone who can undo it.
 */
final class RolesTest extends CIUnitTestCase
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

    public function testTheRolesAreListedWithTheirPermissionsAndWhoHoldsThem(): void
    {
        $r = $this->api('get', 'api/roles');

        $this->assertTrue($r['canManage']);
        $this->assertContains('users.manage', array_column($r['catalogue'], 'key'));
        $fm = $this->role($r, 'Finance Manager');
        $this->assertTrue($fm['builtIn']);
        $this->assertContains('users.manage', $fm['permissions']);
        $this->assertContains('W. Kamau', $fm['holders']);
    }

    public function testAnOrganisationDefinesAsManyRolesAsItNeeds(): void
    {
        foreach (['Grants Accountant', 'Board Treasurer', 'Payroll Clerk', 'Internal Auditor', 'Procurement Officer'] as $i => $name) {
            $this->api('post', 'api/roles', ['name' => $name, 'description' => 'Role ' . $i, 'permissions' => ['ledger.view']]);
        }
        $r = $this->api('get', 'api/roles');
        $this->assertCount(13, $r['roles']);

        $created = $this->role($r, 'Payroll Clerk');
        $this->assertSame(['ledger.view'], $created['permissions']);
        $this->assertTrue($created['readOnly']);
        $this->assertFalse($created['builtIn']);

        $this->assertStringContainsString('already a role', $this->api('post', 'api/roles', ['name' => 'payroll clerk', 'permissions' => []], 422)['error']);
        $this->assertStringContainsString('not a permission', $this->api('post', 'api/roles', ['name' => 'Oddity', 'permissions' => ['ledger.delete']], 422)['error']);
        $this->assertSame('Payroll Clerk role added — ledger.view', $this->api('get', 'api/settings')['audit'][2]['what']);
    }

    public function testAPersonHoldsSeveralRolesAndMayDoWhatAnyOfThemPermits(): void
    {
        $this->api('post', 'api/roles', ['name' => 'Payroll Reviewer', 'permissions' => ['payroll.view']]);
        $auditor = $this->userId('audit@pkfea.com');

        // The auditor can only look at the ledger…
        $this->assertSame(['ledger.view'], $this->actor('audit@pkfea.com')['permissions']);

        // …until they hold a second role, at one entity only.
        $access = $this->api('post', "api/users/{$auditor}/access", ['access' => [
            ['role' => 'Auditor (read only)', 'entities' => 'all'],
            ['role' => 'Payroll Reviewer', 'entities' => ['ELOG-NS']],
        ]]);
        $row = current(array_filter($access['users'], static fn ($u) => $u['email'] === 'audit@pkfea.com'));
        $this->assertSame(['Auditor (read only)', 'Payroll Reviewer'], $row['roles']);
        $this->assertSame(['role' => 'Payroll Reviewer', 'entities' => ['ELOG-NS']], $row['access'][1]);

        $me = $this->actor('audit@pkfea.com');
        $this->assertEqualsCanonicalizing(['ledger.view', 'payroll.view'], $me['permissions']);
        $this->assertSame(['Auditor (read only)', 'Payroll Reviewer'], $me['roles']);
        $this->assertSame('PKF Kenya now holds Auditor (read only) (all entities), Payroll Reviewer (ELOG-NS)', $access['audit'][0]['what']);

        // A role's permissions change for everyone holding it.
        $role = $this->role($this->api('get', 'api/roles'), 'Payroll Reviewer');
        $this->api('post', 'api/roles/' . $role['id'], ['name' => 'Payroll Reviewer', 'permissions' => ['payroll.view', 'requisition.raise']]);
        $this->assertContains('requisition.raise', $this->actor('audit@pkfea.com')['permissions']);
    }

    public function testAnInvitationCanCarrySeveralRoles(): void
    {
        $res = $this->api('post', 'api/settings/invite', ['name' => 'Amina Hassan', 'email' => 'a.hassan@elog.or.ke', 'roles' => ['Accountant', 'Grants Lead'], 'entities' => ['ELOG-CST']]);
        $user = current(array_filter($res['users'], static fn ($u) => $u['email'] === 'a.hassan@elog.or.ke'));

        $this->assertSame(['Accountant', 'Grants Lead'], $user['roles']);
        $this->assertSame([['role' => 'Accountant', 'entities' => ['ELOG-CST']], ['role' => 'Grants Lead', 'entities' => ['ELOG-CST']]], $user['access']);
        $this->assertSame('Invited', $user['status']);
    }

    public function testTheBuiltInRolesKeepTheirNamesAndARoleInUseIsNotDeleted(): void
    {
        $roles = $this->api('get', 'api/roles');
        $fm = $this->role($roles, 'Finance Manager');
        $this->assertStringContainsString('keeps its name', $this->api('post', 'api/roles/' . $fm['id'], ['name' => 'Head of Finance', 'permissions' => $fm['permissions']], 422)['error']);
        $this->assertStringContainsString('cannot be deleted', $this->api('post', 'api/roles/' . $fm['id'] . '/delete', [], 422)['error']);

        $this->api('post', 'api/roles', ['name' => 'Budget Holder', 'permissions' => ['requisition.raise']]);
        $holder = $this->role($this->api('get', 'api/roles'), 'Budget Holder');
        $this->api('post', 'api/users/' . $this->userId('j.achieng@elog.or.ke') . '/access', ['access' => [['role' => 'Accountant'], ['role' => 'Budget Holder']]]);
        $this->assertStringContainsString('J. Achieng', $this->api('post', 'api/roles/' . $holder['id'] . '/delete', [], 422)['error']);

        // Renamed freely while it is a custom role; deleted once nobody holds it.
        $this->api('post', 'api/roles/' . $holder['id'], ['name' => 'Cost Centre Holder', 'permissions' => ['requisition.raise']]);
        $this->api('post', 'api/users/' . $this->userId('j.achieng@elog.or.ke') . '/access', ['access' => [['role' => 'Accountant']]]);
        $this->api('post', 'api/roles/' . $holder['id'] . '/delete');
        $this->assertNull($this->role($this->api('get', 'api/roles'), 'Cost Centre Holder', false));
    }

    public function testNothingLeavesTheOrganisationWithoutSomeoneWhoCanUndoIt(): void
    {
        $fm = $this->role($this->api('get', 'api/roles'), 'Finance Manager');
        $kamau = $this->userId('w.kamau@elog.or.ke');

        $taken = array_values(array_diff($fm['permissions'], ['users.manage']));
        $this->assertStringContainsString('nobody active who can manage users', $this->api('post', 'api/roles/' . $fm['id'], ['permissions' => $taken], 422)['error']);
        $this->assertStringContainsString('nobody active who can', $this->api('post', "api/users/{$kamau}/access", ['access' => [['role' => 'Accountant']]], 422)['error']);
        $this->assertStringContainsString('cannot suspend yourself', $this->api('post', "api/users/{$kamau}/suspend", [], 422)['error']);
        $this->assertStringContainsString('at least one role', $this->api('post', "api/users/{$kamau}/access", ['access' => []], 422)['error']);
    }

    public function testOnlyARoleWithUsersManageChangesRolesOrAccess(): void
    {
        $this->signIn('j.achieng@elog.or.ke');

        $this->assertFalse($this->api('get', 'api/roles')['canManage']);
        $this->api('post', 'api/roles', ['name' => 'Sneaky', 'permissions' => ['settings.manage']], 403);
        $this->api('post', 'api/users/' . $this->userId('j.achieng@elog.or.ke') . '/access', ['access' => [['role' => 'Finance Manager']]], 403);
        $this->api('post', 'api/settings/invite', ['name' => 'A B', 'email' => 'a@b.co', 'roles' => ['Accountant']], 403);
    }

    // ------------------------------------------------------------------

    private function api(string $method, string $url, array $body = [], int $status = 200): array
    {
        $result = $method === 'get' ? $this->get($url) : $this->withBodyFormat('json')->post($url, $body);
        $json = json_decode((string) $result->getJSON(), true) ?? [];
        $this->assertSame($status, $result->response()->getStatusCode(), $method . ' ' . $url . ': ' . ($json['error'] ?? ''));

        return $json;
    }

    private function actor(string $email): array
    {
        Repository::forget();

        return (new \App\Repositories\UserRepository())->actor($email);
    }

    private function role(array $payload, string $name, bool $required = true): ?array
    {
        foreach ($payload['roles'] as $r) {
            if ($r['name'] === $name) {
                return $r;
            }
        }
        if ($required) {
            $this->fail($name . ' is not listed.');
        }

        return null;
    }

    private function userId(string $email): int
    {
        return (int) db_connect()->table('users')->where('email', $email)->get()->getRow()->id;
    }
}
