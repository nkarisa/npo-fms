<?php

use App\Database\Migrations\CreateMaintenance;
use App\Database\Seeds\DatabaseSeeder;
use App\Database\Seeds\OrganisationSeeder;
use App\Libraries\Clock;
use App\Libraries\Maintenance;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Maintenance mode: the application closed while it is worked on, by hand or to a
 * plan, and open to nobody but the people who can close it.
 *
 * The finance manager holds settings.maintenance; the accountant and the auditor
 * do not, and stand for everybody else.
 */
final class MaintenanceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use \Tests\Support\SignsIn;

    protected $namespace = 'App';
    protected $refresh   = true;
    protected $seed      = DatabaseSeeder::class;

    private const KEEPER = 'w.kamau@elog.or.ke';
    private const EVERYONE_ELSE = 's.njeri@elog.or.ke';

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['app.asOf'] = '2026-08-31';
        Repository::forget();
        \Config\Services::resetSingle('throttler');
    }

    // ------------------------------------------------------------------
    // Switching it by hand
    // ------------------------------------------------------------------

    public function testClosingItByHandLetsInOnlyThePeopleWhoCanOpenItAgain(): void
    {
        $open = $this->api('get', 'api/maintenance');
        $this->assertFalse($open['state']['on']);
        $this->assertTrue($open['canManage']);
        $this->assertNull($open['banner']);
        // Who to ask, for the message that turns everyone else away.
        $this->assertNotEmpty(array_filter($open['keepers'], static fn ($k) => str_contains($k, 'Kamau')), 'the keepers are named: ' . implode(', ', $open['keepers']));

        $closed = $this->api('post', 'api/maintenance', ['on' => true, 'message' => 'Upgrading the database. Approvals and payments are unavailable.']);
        $this->assertTrue($closed['state']['on']);
        $this->assertTrue($closed['state']['switched'], 'switched by hand, so it does not open by itself');
        $this->assertNull($closed['state']['until']);
        $this->assertSame('closed', $closed['banner']['tone']);

        // Everybody else is turned away, signed in already or not.
        $this->signIn(self::EVERYONE_ELSE);
        $refused = $this->get('api/journals');
        $refused->assertStatus(503);
        $this->assertSame('1', $refused->response()->getHeaderLine('X-Maintenance'));
        $this->assertStringContainsString('Upgrading the database', json_decode((string) $refused->getJSON(), true)['error']);

        $page = $this->get('/');
        $page->assertStatus(503);
        $page->assertSee('Closed for maintenance');
        $page->assertSee('Upgrading the database');

        // And whoever can open it again carries on working, told what everyone else sees.
        $this->signIn(self::KEEPER);
        $this->get('api/journals')->assertStatus(200);
        $this->assertStringContainsString('Maintenance mode', (string) $this->get('/')->getBody());

        $this->api('post', 'api/maintenance', ['on' => false]);
        $this->signIn(self::EVERYONE_ELSE);
        $this->get('api/journals')->assertStatus(200);

        // Both switches are in the settings audit log, in words.
        $this->signIn(self::KEEPER);
        $log = array_column(array_filter($this->api('get', 'api/settings')['audit'], static fn ($a) => $a['area'] === 'Maintenance'), 'what');
        $this->assertStringContainsString('Application closed for maintenance — Upgrading the database', $log[1]);
        $this->assertStringContainsString('opened again', $log[0]);
    }

    public function testClosingItIsForTheRoleTheKeyIsGivenTo(): void
    {
        $this->signIn(self::EVERYONE_ELSE);

        // The section belongs to the permission, not to settings.view: an accountant
        // is not offered it and cannot read it even by asking the API directly.
        $this->assertNotContains(Maintenance::SECTION, array_column($this->api('get', 'api/settings')['sections'], 'key'));
        $this->assertStringContainsString('settings.maintenance', $this->api('get', 'api/maintenance', [], 403)['error']);

        $this->assertStringContainsString('settings.maintenance', $this->api('post', 'api/maintenance', ['on' => true, 'message' => 'Because I say so.'], 403)['error']);
        $this->api('post', 'api/maintenance/windows', ['starts' => $this->at('+2 days 18:00'), 'ends' => $this->at('+2 days 20:00'), 'reason' => 'Because I say so.'], 403);
        $this->assertFalse(Maintenance::on());
    }

    public function testAClosedApplicationTurnsASignInAwayOnceThePasswordIsRight(): void
    {
        $this->api('post', 'api/maintenance', ['on' => true, 'message' => 'Moving the server. Back at six.']);
        $this->signOut();

        // The sign-in screen says so before anybody types anything.
        $status = $this->send('get', 'api/auth');
        $this->assertSame('Closed for maintenance', $status['maintenance']['title']);
        $this->assertStringContainsString('Moving the server', $status['maintenance']['note']);

        // The right password is still not a way in, and leaves no part-way session.
        $refused = $this->send('post', 'api/auth/login', ['email' => self::EVERYONE_ELSE, 'password' => OrganisationSeeder::DEMO_PASSWORD], 503);
        $this->assertStringContainsString('Moving the server', $refused['error']);
        $this->assertSame('signedOut', $refused['stage']);

        // A wrong password is refused as it always is, so a closed application
        // still gives nothing away about which addresses have accounts.
        $this->send('post', 'api/auth/login', ['email' => self::EVERYONE_ELSE, 'password' => 'not the password'], 422);

        // Whoever can open it again signs in as usual.
        $this->assertContains(
            $this->send('post', 'api/auth/login', ['email' => self::KEEPER, 'password' => OrganisationSeeder::DEMO_PASSWORD])['stage'],
            ['enrol', 'mfa', 'done']
        );
    }

    // ------------------------------------------------------------------
    // Planning one
    // ------------------------------------------------------------------

    public function testABookedWindowTellsEveryoneAndThenClosesTheApplicationItself(): void
    {
        $booked = $this->api('post', 'api/maintenance/windows', [
            'starts' => $this->at('+2 days 00:00'), 'ends' => $this->at('+2 days 23:59'),
            'reason' => 'Upgrading the database and the reporting views.',
        ]);
        $this->assertStringContainsString('Everyone has been notified', $booked['message']);
        $window = $booked['windows'][0];
        $this->assertSame('Scheduled', $window['state']);
        $this->assertSame('planned', $booked['banner']['tone'], 'and it is on every page from now until it runs');
        $this->assertFalse($booked['state']['on'], 'but nothing is closed yet');

        // Everybody was told, whichever entity they work in.
        $this->signIn(self::EVERYONE_ELSE);
        $bell = $this->api('get', 'api/notifications');
        $this->assertSame(1, $bell['unread']);
        $this->assertStringContainsString('Planned maintenance', $bell['rows'][0]['title']);
        $this->assertStringContainsString('Upgrading the database', $bell['rows'][0]['body']);
        $this->assertSame('planned', $this->api('get', 'api/dashboard')['maintenance']['tone'],
            'and it is on their dashboard and every page, though the section it was booked from is not theirs');

        // The day comes: nobody has to be at a keyboard for the application to close.
        $_ENV['app.asOf'] = date('Y-m-d', strtotime('2026-08-31 +2 days'));
        Repository::forget();
        $this->get('api/journals')->assertStatus(503);

        $this->signIn(self::KEEPER);
        $state = $this->api('get', 'api/maintenance')['state'];
        $this->assertTrue($state['on']);
        $this->assertFalse($state['switched'], 'a window closed it, so it opens again by itself');
        $this->assertNotNull($state['until']);
        $this->assertSame('Under way', $this->api('get', 'api/maintenance')['windows'][0]['state']);

        // Ended early: open at once, and everyone is told that too.
        $this->api('post', 'api/maintenance/windows/' . $window['id'] . '/cancel');
        $this->assertFalse(Maintenance::on());
        $this->signIn(self::EVERYONE_ELSE);
        $this->get('api/journals')->assertStatus(200);
        $this->assertStringContainsString('ended early', $this->api('get', 'api/notifications')['rows'][0]['title']);
    }

    public function testAWindowIsCheckedBeforeAnybodyIsTold(): void
    {
        $refuse = fn (array $body) => $this->api('post', 'api/maintenance/windows', $body, 422)['error'];

        $this->assertStringContainsString('starts in the future', $refuse([
            'starts' => $this->at('-1 day 18:00'), 'ends' => $this->at('+1 day 20:00'), 'reason' => 'Upgrading the database.',
        ]));
        $this->assertStringContainsString('at most ' . Maintenance::MAX_HOURS . ' hours', $refuse([
            'starts' => $this->at('+1 day 18:00'), 'ends' => $this->at('+9 days 18:00'), 'reason' => 'Upgrading the database.',
        ]));
        $this->assertStringContainsString('Say what the maintenance is for', $refuse([
            'starts' => $this->at('+1 day 18:00'), 'ends' => $this->at('+1 day 20:00'), 'reason' => 'x',
        ]));
        $this->assertStringContainsString('date and time', $refuse([
            'starts' => 'whenever', 'ends' => $this->at('+1 day 20:00'), 'reason' => 'Upgrading the database.',
        ]));

        $this->api('post', 'api/maintenance/windows', [
            'starts' => $this->at('+1 day 18:00'), 'ends' => $this->at('+1 day 22:00'), 'reason' => 'Upgrading the database.',
        ]);
        $this->assertStringContainsString('overlaps the window already booked', $refuse([
            'starts' => $this->at('+1 day 20:00'), 'ends' => $this->at('+1 day 23:00'), 'reason' => 'Something else at the same time.',
        ]));

        // Nothing was written and nobody was told for any of the refusals: one
        // booking went ahead, so there is one announcement and no other.
        $this->assertCount(1, $this->api('get', 'api/maintenance')['windows']);
        $announced = array_unique(array_column(db_connect()->table('notifications')->where('kind', 'Maintenance')->get()->getResultArray(), 'title'));
        $this->assertCount(1, $announced);
    }

    public function testAnInstanceAlreadyRunningGetsTheKeyToItsOwnDoor(): void
    {
        $db = db_connect();
        $held = fn (string $role) => array_column($db->query(
            'SELECT p.key FROM ' . $db->prefixTable('role_permissions') . ' rp JOIN ' . $db->prefixTable('permissions') . ' p ON p.id = rp.permission_id
             JOIN ' . $db->prefixTable('roles') . ' r ON r.id = rp.role_id WHERE r.name = ?', [$role]
        )->getResultArray(), 'key');

        // Back to before maintenance mode existed.
        (new CreateMaintenance())->down();
        $this->assertNotContains(Maintenance::PERMISSION, $held('Finance Manager'));

        // Upgrading gives it to whoever manages users, so nobody is left with an
        // application they cannot close and no way to give themselves the right to.
        (new CreateMaintenance())->up();
        Repository::forget();
        $this->assertContains(Maintenance::PERMISSION, $held('Finance Manager'));
        $this->assertNotContains(Maintenance::PERMISSION, $held('Accountant'), 'and to nobody else');

        $this->api('post', 'api/maintenance', ['on' => true, 'message' => 'Upgrading the application itself.']);
        $this->assertTrue(Maintenance::on());
    }

    // ------------------------------------------------------------------

    /** "+2 days 18:00" as the browser's datetime-local field sends it. */
    private function at(string $when): string
    {
        return date('Y-m-d\TH:i', strtotime($when, strtotime(Clock::date())));
    }

    private function api(string $method, string $url, array $body = [], int $status = 200): array
    {
        Repository::forget();
        $this->withHeaders(['X-Requested-With' => 'phpunit']);
        $result = $method === 'get' ? $this->get($url) : $this->withBodyFormat('json')->post($url, $body);
        $json = json_decode((string) $result->getJSON(), true) ?? [];
        $this->assertSame($status, $result->response()->getStatusCode(), $method . ' ' . $url . ': ' . ($json['error'] ?? ''));

        return $json;
    }

    /** As AuthTest does it: each request carries on the session the last one left. */
    private function send(string $method, string $url, array $body = [], int $status = 200): array
    {
        Repository::forget();
        $this->withHeaders(['X-Requested-With' => 'phpunit']);
        $this->withSession($this->session ?? []);
        $result = $method === 'get' ? $this->get($url) : $this->withBodyFormat('json')->post($url, $body);
        $this->session = $_SESSION;
        $json = json_decode((string) $result->getJSON(), true) ?? [];
        $this->assertSame($status, $result->response()->getStatusCode(), $method . ' ' . $url . ': ' . ($json['error'] ?? ''));

        return $json;
    }
}
