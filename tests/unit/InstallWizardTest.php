<?php

use App\Libraries\DatabaseProbe;
use App\Libraries\EnvFile;
use App\Libraries\InstallState;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database as DatabaseConfig;

/**
 * The installer in the browser (/api/install): the setup key, the steps in order,
 * choosing or creating a database, and both ways to fill it — a new organisation,
 * or a copy of the demonstration one.
 *
 * The suite's own database is migrated but holds no organisation, so the instance
 * counts as not installed and the installer answers. What the wizard installs goes
 * into an SQLite file of its own in writable/, created the way a person would
 * create it, and removed afterwards. The .env it writes, and the lock and token,
 * are kept in a scratch directory, never the instance's own.
 */
final class InstallWizardTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = true;

    private string $scratch;
    private string $name;
    protected $session = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir() . '/fms-wizard-' . bin2hex(random_bytes(4));
        mkdir($this->scratch);
        EnvFile::useFile($this->scratch . '/.env');
        InstallState::useDirectory($this->scratch);
        $this->name = 'wizard_' . bin2hex(random_bytes(4));
        $this->withHeaders(['X-Requested-With' => 'phpunit']);
    }

    protected function tearDown(): void
    {
        EnvFile::useFile(null);
        InstallState::useDirectory(null);
        @unlink(WRITEPATH . $this->name . '.sqlite');
        array_map('unlink', array_filter(glob($this->scratch . '/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
        @rmdir($this->scratch);
        parent::tearDown();
    }

    public function testAnInstanceWithNoOrganisationAnswersEverythingWithTheInstaller(): void
    {
        $this->assertSame(503, $this->get('api/journals')->response()->getStatusCode());
        $this->assertSame('/install', $this->get('journals')->response()->getHeaderLine('Location'));

        $state = $this->send('get', 'api/install');
        $this->assertSame('server', $state['step']);
        $this->assertSame(['server', 'database', 'data', 'organisation', 'entities', 'user', 'review', 'done'], array_keys($state['steps']));
    }

    public function testTheInstallerNeedsTheSetupKeyAndTakesTheStepsInOrder(): void
    {
        // Not from the server itself, so the key is asked for.
        $this->assertFalse($this->send('get', 'api/install')['authorised']);
        $this->assertStringContainsString('setup key', $this->send('post', 'api/install/server', ['driver' => 'SQLite3'], 403)['error']);
        $this->assertStringContainsString('not the setup key', $this->send('post', 'api/install/token', ['token' => 'guess'], 422)['error']);
        $this->unlock();

        $this->assertStringContainsString('comes after the database', $this->send('post', 'api/install/data', ['data' => 'new'], 409)['error']);
        $this->assertStringContainsString('comes after the server', $this->send('post', 'api/install/database', ['database' => 'x'], 409)['error']);
    }

    public function testADatabaseIsChosenFromTheListOrCreated(): void
    {
        $this->unlock();
        $server = $this->send('post', 'api/install/server', ['driver' => 'SQLite3']);
        $this->assertSame('database', $server['step']);
        $this->assertTrue($server['answers']['databases']['canCreate']);

        $this->assertStringContainsString('letters, digits', $this->send('post', 'api/install/database', ['create' => true, 'database' => 'no spaces'], 422)['error']);

        $made = $this->send('post', 'api/install/database', ['create' => true, 'database' => $this->name]);
        $this->assertSame('data', $made['step']);
        $this->assertStringContainsString('created', $made['message']);
        $this->assertFileExists(WRITEPATH . $this->name . '.sqlite');
        // The list now carries it, empty, and a second one of the same name is refused.
        $listed = current(array_filter($made['answers']['databases']['list'], fn ($d) => $d['name'] === 'writable/' . $this->name . '.sqlite'));
        $this->assertSame(['empty', true], [$listed['state'], $listed['usable']]);

        $this->send('post', 'api/install/back');
        $this->assertStringContainsString('already there', $this->send('post', 'api/install/database', ['create' => true, 'database' => $this->name], 422)['error']);
        // Chosen from the list instead.
        $this->assertSame('data', $this->send('post', 'api/install/database', ['database' => $listed['value']])['step']);
    }

    public function testANewOrganisationIsInstalledWithTheFirstUserHoldingEveryPermissionEverywhere(): void
    {
        $this->toData();
        $this->assertSame('organisation', $this->send('post', 'api/install/data', ['data' => 'new'])['step']);
        $this->send('post', 'api/install/organisation', [
            'registeredName' => 'Mara Conservation Trust', 'shortName' => 'Mara Trust', 'currency' => 'KES',
            'framework' => 'IFRS', 'yearEnd' => '31 December', 'codeLength' => '4 digits', 'firstYear' => '2026',
        ]);
        $this->assertStringContainsString('head office code', $this->send('post', 'api/install/entities', ['entityCode' => 'a b', 'entityName' => 'Head Office'], 422)['error']);
        $this->send('post', 'api/install/entities', ['entityCode' => 'MARA-HQ', 'entityName' => 'Mara Trust — Head Office',
            'entities' => [['code' => 'MARA-NRB', 'name' => 'Mara Trust Nairobi', 'type' => 'Branch', 'currency' => 'KES']]]);

        $user = $this->send('get', 'api/install');
        $this->assertSame('user', $user['step']);
        $this->assertSame('length', $user['options']['passwordRules'][0]['key']);
        $this->assertStringContainsString('at least 12', $this->send('post', 'api/install/user', ['userName' => 'Grace Wanjiru', 'userEmail' => 'g.wanjiru@mara.or.ke', 'userPassword' => 'short'], 422)['error']);
        $review = $this->send('post', 'api/install/user', ['userName' => 'Grace Wanjiru', 'userEmail' => 'g.wanjiru@mara.or.ke', 'userPassword' => '']);
        $this->assertSame('review', $review['step']);

        $done = $this->write();
        $this->assertSame('done', $done['step']);
        $this->assertSame('Finance Manager and Administrator at all 2 entities', $done['done']['role']);
        $this->assertStringContainsString('/accept-invite?token=', (string) $done['done']['link']);

        // The credentials went to .env, and the installer closed behind itself.
        $this->assertSame(WRITEPATH . $this->name . '.sqlite', EnvFile::value('database.default.database'));
        $this->assertFileExists($this->scratch . '/' . InstallState::LOCK_FILE);
        $this->assertFileDoesNotExist($this->scratch . '/' . InstallState::TOKEN_FILE);
        // And the credentials left the session once they were written.
        $this->assertSame([[], []], [$done['answers']['server'], $done['answers']['database']]);

        // Both roles at both entities; Finance Manager first, so the approval rules find it.
        $db = $this->installed();
        $roles = $db->query(
            'SELECT e.code, r.name FROM user_entity_roles ur JOIN users u ON u.id = ur.user_id JOIN roles r ON r.id = ur.role_id
             JOIN entities e ON e.id = ur.entity_id WHERE u.email = ? ORDER BY ur.id',
            ['g.wanjiru@mara.or.ke']
        )->getResultArray();
        $this->assertSame([['MARA-HQ', 'Finance Manager'], ['MARA-HQ', 'Administrator'], ['MARA-NRB', 'Finance Manager'], ['MARA-NRB', 'Administrator']],
            array_map(static fn ($r) => [$r['code'], $r['name']], $roles));
        $db->close();

        // Listed again, the database is in use and cannot be chosen.
        $listed = current(array_filter((new DatabaseProbe(DatabaseProbe::config(['driver' => 'SQLite3'], false)))->databases(),
            fn ($d) => $d['name'] === 'writable/' . $this->name . '.sqlite'));
        $this->assertSame(['installed', false, 'Mara Trust — Head Office'], [$listed['state'], $listed['usable'], $listed['organisation']]);
    }

    /**
     * Installed under a table prefix, which the wizard offers on its first step:
     * every table the install reads back is the prefixed one. The done screen names
     * the administrator to sign in as, which is read with a query of its own.
     */
    public function testACopyOfTheDemonstrationOrganisationSkipsTheOrganisationSteps(): void
    {
        $this->toData('fx_');
        $this->assertSame('review', $this->send('post', 'api/install/data', ['data' => 'demo'])['step']);
        // Back from its review is the choice, not the first user it never asked for.
        $this->assertSame('data', $this->send('post', 'api/install/back')['step']);
        $this->send('post', 'api/install/data', ['data' => 'demo']);

        $done = $this->write();
        $this->assertTrue($done['done']['demo']);
        $this->assertSame('optional', EnvFile::value('auth.mfaRequired'));
        $this->assertStringContainsString('admin@elog.or.ke', $done['done']['user']);
        $this->assertSame('fx_', EnvFile::value('database.default.DBPrefix'));
        $db = $this->installed('fx_');
        $this->assertGreaterThan(1, $db->table('entities')->countAllResults());
        $this->assertContains('fx_users', $db->listTables());
        $db->close();
    }

    public function testOnceInstalledTheInstallerIsNotThere(): void
    {
        db_connect()->table('entities')->insert([
            'code' => 'HQ', 'name' => 'Head Office', 'type' => 'Head office', 'functional_currency' => 'KES',
            'status' => 'live', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame(404, $this->get('api/install')->response()->getStatusCode());

        // Except to the session still writing the install: its seed is what put the
        // organisation there, and finish() has yet to run.
        $this->withSession([InstallState::STEP => 'review', InstallState::SESSION . '_authorised' => true]);
        $this->assertSame(200, $this->get('api/install')->response()->getStatusCode());
        $this->withSession([InstallState::STEP => 'user', InstallState::SESSION . '_authorised' => true]);
        $this->assertSame(404, $this->get('api/install')->response()->getStatusCode());
    }

    // ------------------------------------------------------------------

    private function unlock(): void
    {
        $this->send('post', 'api/install/token', ['token' => InstallState::token()]);
    }

    /** Unlocked, on SQLite, with a new database created and accepted. */
    private function toData(string $prefix = ''): void
    {
        $this->unlock();
        $this->send('post', 'api/install/server', ['driver' => 'SQLite3', 'prefix' => $prefix]);
        $this->send('post', 'api/install/database', ['create' => true, 'database' => $this->name]);
    }

    /** The four writing calls, in order, as the review step makes them. Returns the last answer. */
    private function write(): array
    {
        foreach (['env', 'migrate', 'seed'] as $step) {
            $this->send('post', 'api/install/' . $step);
        }

        return $this->send('post', 'api/install/finish');
    }

    private function installed(string $prefix = '')
    {
        return DatabaseConfig::connect(
            DatabaseProbe::config(['driver' => 'SQLite3', 'database' => WRITEPATH . $this->name . '.sqlite', 'prefix' => $prefix]),
            false
        );
    }

    /** A request, carrying the session on from the last one. Returns the decoded body. */
    private function send(string $method, string $url, array $body = [], int $status = 200): array
    {
        $this->withSession($this->session);
        $result = $method === 'get' ? $this->get($url) : $this->withBodyFormat('json')->post($url, $body);
        $this->session = $_SESSION;
        $json = json_decode((string) $result->getJSON(), true) ?? [];
        $this->assertSame($status, $result->response()->getStatusCode(), $method . ' ' . $url . ': ' . ($json['error'] ?? ''));

        return $json;
    }
}
