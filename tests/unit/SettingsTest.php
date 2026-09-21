<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Libraries\Brand;
use App\Libraries\Navigation;
use App\Libraries\Theme;
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

    public function testTheScreenIsServedAsThePrototypeSetsItOut(): void
    {
        $s = $this->api('api/settings');

        $this->assertSame(['Organisation', 'Ledger', 'Segments', 'Currencies', 'Taxes', 'Terms and reminders', 'Approvals', 'Bank statements', 'Opening balances', 'Integrations', 'Payroll', 'Appearance', 'Language and translation', 'Users', 'Roles', 'Audit log'], array_column($s['sections'], 'key'));
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
        $this->assertSame('evergreen', $s['appearance']['theme']);
        $this->assertSame(['accent' => '#0f5c4a', 'rail' => '#0d1b18'], $s['appearance']['custom']);
        $this->assertSame(['evergreen', 'deep-blue', 'indigo', 'burgundy', 'graphite', 'custom'], array_column($s['themes'], 'key'));
        $this->assertSame(['ELOG', 'Finance Suite', ''], [$s['appearance']['appName'], $s['appearance']['appTagline'], $s['appearance']['logo']]);
        $this->assertSame(['Head office', 'Branch', 'Related trust'], $s['entityTypes']);
        $this->assertSame(['ELOG-NS', 'ELOG-CST', 'ELOG-WST', 'ELOG-TRUST', 'ELOG-RV'], array_column($s['entities'], 'code'));
        $this->assertTrue($s['entities'][0]['head']);
        $this->assertSame('Dormant', $s['entities'][4]['status']);

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
            'appearance'   => ['theme' => 'deep-blue'],
            'language'     => ['formatsLocked' => false],
        ];

        $saved = $this->json($this->withBodyFormat('json')->post('api/settings', $draft));

        $this->assertSame('Settings saved. 14 changes have been written to the audit log.', $saved['message']);
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
            'Interface theme changed from Evergreen to Deep blue for everyone',
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
        $this->assertSame('deep-blue', $saved['appearance']['theme']);
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
        $refused(['appearance' => ['theme' => 'neon']], 'is not one of the themes');

        $this->assertSame(0, db_connect()->table('audit_events')->where('action', 'settings.changed')->like('summary', 'Changed')->countAllResults());
    }

    /**
     * The theme is an organisation setting: the Finance Manager sets it, everyone
     * reads the shell in it, and the shell paints it server-side so no page flashes
     * the old palette first.
     */
    public function testTermsAndReminderWindowsAreSetInSettingsAndFollowedByEachModule(): void
    {
        $days = array_column($this->api('api/settings')['days'], 'value', 'key');
        $this->assertSame(['14, 30, 45, 60', '30', '14', '30', '45', '30'], array_values(array_map('strval', $days)));
        $this->assertSame([14, 30, 45, 60], $this->api('api/payables/form')['terms']);
        $save = fn (array $days) => $this->withBodyFormat('json')->post('api/settings', ['days' => $days]);

        $refused = static function ($response): string {
            $response->assertStatus(422);

            return json_decode($response->getJSON(), true)['error'];
        };
        $this->assertStringContainsString('whole number of days between 1 and 365', $refused($save(['advanceRecoveryDays' => '0'])));
        $this->assertStringContainsString('separated by commas', $refused($save(['supplierTerms' => '30 days or 60'])));

        $saved = $save(['supplierTerms' => '60, 7,30', 'claimTermsDays' => '45', 'reportWarningDays' => '60', 'trancheWarningDays' => '30']);
        $saved->assertStatus(200);
        $this->assertSame([
            'Supplier payment terms offered changed from 14, 30, 45, 60 to 7, 30, 60 days',
            'A donor claim falls due after changed from 30 to 45 days',
            'A donor report is flagged as due changed from 45 to 60 days',
        ], array_column($this->json($saved)['changes'], 'what'));

        $form = $this->api('api/payables/form');
        $this->assertSame([[7, 30, 60], 30], [$form['terms'], $form['defaultTerms']]);
        $this->assertSame(45, $this->api('api/receivables/form')['termsDays']);
        $this->assertSame(60, $this->api('api/grants/calendar')['warningDays']);
        $this->assertStringContainsString('due within 60 days', $this->api('api/grants/calendar')['summary']);

        $this->actAs('s.njeri@elog.or.ke');
        $save(['claimTermsDays' => '60'])->assertStatus(403);
    }

    public function testTheThemeIsHeldForTheOrganisationAndPaintedByTheShell(): void
    {
        $this->assertSame('evergreen', Theme::current());
        $this->assertStringContainsString('data-theme="evergreen"', $this->page('/settings'));

        $this->json($this->withBodyFormat('json')->post('api/settings', ['appearance' => ['theme' => 'indigo']]));
        Repository::forget();

        $this->assertSame('indigo', Theme::current());
        $this->assertStringContainsString('data-theme="indigo"', $this->page('/'));

        // An accountant reads the same shell, and cannot change it.
        $this->actAs('s.njeri@elog.or.ke');
        $this->assertSame('indigo', $this->api('api/settings')['appearance']['theme']);
        $this->withBodyFormat('json')->post('api/settings', ['appearance' => ['theme' => 'burgundy']])->assertStatus(403);
        $this->assertSame('indigo', Theme::current());
    }

    /**
     * The name in the sidebar is the application's, not the registered entity's:
     * the two are set separately and the shell carries the first.
     */
    public function testTheApplicationNameAndLogoAreSetFromSettings(): void
    {
        // The shell renders non-ASCII as HTML entities, so the tab title is asserted
        // on the part of it the brand actually supplies.
        $shell = $this->page('/settings');
        $this->assertStringContainsString('ELOG Finance Suite</title>', $shell);
        $this->assertStringContainsString('<div class="brand-mark">EL</div>', $shell);

        $saved = $this->json($this->withBodyFormat('json')->post('api/settings', [
            'appearance' => ['appName' => 'Coast Finance', 'appTagline' => 'Regional office'],
        ]));
        $this->assertSame([
            'Application name changed from ELOG to Coast Finance',
            'Line under the application name changed from Finance Suite to Regional office',
        ], array_column($saved['changes'], 'what'));
        $this->assertSame('Appearance', $saved['audit'][0]['area']);

        Repository::forget();
        $shell = $this->page('/');
        $this->assertStringContainsString('Coast Finance Regional office</title>', $shell);
        // The initials follow the name, and the registered name is untouched by it.
        $this->assertStringContainsString('<div class="brand-mark">CF</div>', $shell);
        $this->assertSame('Elections Observation Group', $saved['organisation']['registeredName']);

        $this->withBodyFormat('json')->post('api/settings', ['appearance' => ['appName' => '']])->assertStatus(422);
        // No logo is held, so there is nothing to serve or to remove.
        $this->get('api/settings/logo')->assertStatus(404);
        $this->withBodyFormat('json')->post('api/settings/logo/remove', [])->assertStatus(422);
    }

    /**
     * An uploaded logo is stored outside the document root and served back by the
     * API, so replacing it is a settings change rather than a deployment.
     */
    public function testALogoIsUploadedStoredAndServedBack(): void
    {
        $settings = new SettingsRepository();
        $kamau = (new Lookups())->userId('W. Kamau');
        $png = $this->pngFile();

        $settings->setLogo(['path' => $png, 'name' => 'logo.png', 'size' => filesize($png), 'mime' => 'image/png'], $kamau);
        Repository::forget();

        $held = (new SettingsRepository())->appearance()['logo'];
        $this->assertStringStartsWith('branding/', $held);
        $this->assertFileExists(WRITEPATH . 'uploads/' . $held);
        $this->assertStringContainsString('<img class="brand-logo"', $this->page('/'));

        $response = $this->get('api/settings/logo');
        $response->assertStatus(200);
        $this->assertSame('image/png', $response->response()->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $response->response()->getHeaderLine('X-Content-Type-Options'));

        // A replaced logo is not left behind, and the address changes with it.
        $before = Brand::current()['logo'];
        (new SettingsRepository())->setLogo(['path' => $this->pngFile(), 'name' => 'new.png', 'size' => filesize($png), 'mime' => 'image/png'], $kamau);
        Repository::forget();
        $this->assertFileDoesNotExist(WRITEPATH . 'uploads/' . $held);
        $this->assertNotSame($before, Brand::current()['logo']);

        // What a browser can be made to execute is not a logo.
        try {
            (new SettingsRepository())->setLogo(['path' => $png, 'name' => 'logo.svg', 'size' => 400, 'mime' => 'image/svg+xml'], $kamau);
            $this->fail('Expected an SVG to be refused.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('SVG can carry script', $e->getMessage());
        }

        (new SettingsRepository())->clearLogo($kamau);
        Repository::forget();
        $this->assertSame('', (new SettingsRepository())->appearance()['logo']);
        $this->assertStringContainsString('<div class="brand-mark">EL</div>', $this->page('/'));
    }

    /** Entities are added and amended in Settings, and the topbar picker follows. */
    public function testEntitiesAreAddedAndAmendedFromSettings(): void
    {
        $s = $this->api('api/settings');
        $entities = array_map(static fn ($e) => ['code' => $e['code'], 'name' => $e['name'], 'type' => $e['type'], 'currency' => $e['currency'], 'status' => $e['status']], $s['entities']);
        $draft = $entities;
        $draft[1]['name'] = 'ELOG Coast Office';
        $draft[4]['status'] = 'Live';
        $draft[] = ['code' => 'ELOG-NYZ', 'name' => 'ELOG Nyanza Regional Office', 'type' => 'Branch', 'currency' => 'USD', 'status' => 'Live'];

        $saved = $this->json($this->withBodyFormat('json')->post('api/settings', ['entities' => $draft]));
        $this->assertSame([
            'ELOG-CST renamed from ELOG Coast Regional Office to ELOG Coast Office',
            'ELOG-RV made live',
            'ELOG Nyanza Regional Office (ELOG-NYZ) added as a branch reporting in USD',
        ], array_column($saved['changes'], 'what'));

        $added = end($saved['entities']);
        $this->assertSame(['ELOG-NYZ', 'Branch', 'USD', 'Live', false], [$added['code'], $added['type'], $added['currency'], $added['status'], $added['head']]);

        // The topbar picker offers the live entities, so a new office appears without a deployment.
        Repository::forget();
        $picker = Navigation::entities();
        $this->assertContains('ELOG Nyanza Regional Office', $picker);
        $this->assertSame(Navigation::CONSOLIDATED, end($picker));

        $refused = function (array $entities, string $reason) {
            $response = $this->withBodyFormat('json')->post('api/settings', ['entities' => $entities]);
            $response->assertStatus(422);
            $this->assertStringContainsString($reason, json_decode($response->getJSON(), true)['error']);
        };
        $head = $entities[0];
        $refused([['code' => 'ELOG-NS'] + ['name' => $head['name'], 'type' => 'Branch', 'currency' => 'KES', 'status' => 'Live']], 'is the head office');
        $refused([['code' => 'ELOG-NS'] + ['name' => $head['name'], 'type' => 'Head office', 'currency' => 'KES', 'status' => 'Dormant']], 'cannot be made dormant');
        $refused([['code' => 'X', 'name' => 'Too short', 'type' => 'Branch', 'currency' => 'KES', 'status' => 'Live']], 'is not an entity code');
        $refused([['code' => 'ELOG-NEW', 'name' => 'ELOG Coast Office', 'type' => 'Branch', 'currency' => 'KES', 'status' => 'Live']], 'already the name of another entity');
        $refused([['code' => 'ELOG-NEW', 'name' => 'Second head', 'type' => 'Head office', 'currency' => 'KES', 'status' => 'Live']], 'already a head office');
        $refused([['code' => 'ELOG-NEW', 'name' => 'Sterling office', 'type' => 'Branch', 'currency' => 'GBP', 'status' => 'Live']], 'not an active currency');
        // The head office holds postings, so its functional currency is settled.
        $refused([['code' => 'ELOG-NS'] + ['name' => $head['name'], 'type' => 'Head office', 'currency' => 'USD', 'status' => 'Live']], 'once it holds postings');
    }

    /**
     * The custom theme is two colours; every other shade is mixed from them in the
     * stylesheet. Both carry light text, so both are held to a contrast ratio.
     */
    public function testACustomThemeIsTwoColoursHeldToTheirContrast(): void
    {
        $saved = $this->json($this->withBodyFormat('json')->post('api/settings', [
            'appearance' => ['theme' => 'custom', 'custom' => ['accent' => '#6A1B9A', 'rail' => '#1A0E24']],
        ]));
        $this->assertSame([
            'Custom accent colour changed from #0f5c4a to #6a1b9a',
            'Custom menu colour changed from #0d1b18 to #1a0e24',
            'Interface theme changed from Evergreen to Custom for everyone',
        ], array_column($saved['changes'], 'what'));
        $this->assertSame(['accent' => '#6a1b9a', 'rail' => '#1a0e24'], $saved['appearance']['custom']);

        // Only the two chosen colours are written onto the shell; the rest are derived in app.css.
        Repository::forget();
        $shell = $this->page('/');
        $this->assertStringContainsString('data-theme="custom"', $shell);
        $this->assertStringContainsString('--accent: #6a1b9a; --rail-bg: #1a0e24;', $shell);

        $refused = function (array $custom, string $reason) {
            $response = $this->withBodyFormat('json')->post('api/settings', ['appearance' => ['custom' => $custom]]);
            $response->assertStatus(422);
            $this->assertStringContainsString($reason, json_decode($response->getJSON(), true)['error']);
        };
        $refused(['accent' => '#8BD3C7'], 'too light to carry white text');
        $refused(['rail' => '#7A6FA0'], 'too light to carry white text');
        $refused(['accent' => 'purple'], 'is not a colour');

        // The palette that was refused was not written.
        Repository::forget();
        $this->assertSame(['accent' => '#6a1b9a', 'rail' => '#1a0e24'], (new SettingsRepository())->appearance()['custom']);
    }

    public function testOnlyTheFinanceManagerSavesAndInvitesUsers(): void
    {
        $this->actAs('d.kiptoo@elog.or.ke');
        $this->assertFalse($this->api('api/settings')['canManage']);
        $this->withBodyFormat('json')->post('api/settings', ['toggles' => ['budgetCheck' => true]])->assertStatus(403);
        $this->withBodyFormat('json')->post('api/settings/invite', ['name' => 'A B', 'email' => 'a@b.co', 'role' => 'Accountant'])->assertStatus(403);
        $this->withBodyFormat('json')->post('api/settings/logo/remove', [])->assertStatus(403);
        $this->withBodyFormat('json')->post('api/settings', ['entities' => [['code' => 'ELOG-X', 'name' => 'X', 'type' => 'Branch', 'currency' => 'KES', 'status' => 'Live']]])->assertStatus(403);

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

    /** A one-pixel PNG on disk, standing in for an uploaded logo. */
    private function pngFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'logo') . '.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        return $path;
    }

    /** The rendered shell of a page, as a browser receives it. */
    private function page(string $url): string
    {
        $response = $this->get($url);
        $response->assertStatus(200);

        return (string) $response->getBody();
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
