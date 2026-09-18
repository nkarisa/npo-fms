<?php

use App\Database\Seeds\BaselineSeeder;
use App\Libraries\Installer;
use App\Repositories\ChartRepository;
use App\Repositories\ConversionRepository;
use App\Repositories\FundRepository;
use App\Repositories\JournalRepository;
use App\Repositories\Lookups;
use App\Repositories\ProgrammeRepository;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use App\Repositories\StatementFormatRepository;
use App\Repositories\UserRepository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Standing up a new instance: migrate, seed the baseline, install, and then get a
 * journal onto the ledger without any of the demonstration data being present.
 *
 * The suite's other tests run against the seeded demonstration organisation; this
 * one deliberately does not seed it, so what is asserted here is what a real
 * installation actually has.
 */
final class InstallTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = true;
    protected $seed      = BaselineSeeder::class;

    private const ANSWERS = [
        'registeredName' => 'Coast Community Trust',
        'shortName'      => 'CCT',
        'taxPin'         => 'P051999888X',
        'registrationNo' => 'OP/218/051/2019/0777',
        'entityCode'     => 'CCT-HQ',
        'entityName'     => 'Coast Community Trust — Head Office',
        'currency'       => 'KES',
        'framework'      => 'IFRS',
        'yearEnd'        => '31 December',
        'codeLength'     => '4 digits',
        'firstYear'      => '2026',
        'userName'       => 'Amina Salim',
        'userEmail'      => 'a.salim@cct.or.ke',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['app.asOf'] = '2026-09-18';
        Repository::forget();
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['elog_actor']);
        service('superglobals')->unsetCookie('elog_actor');
        parent::tearDown();
    }

    public function testTheBaselineCarriesTheReferenceDataAndNothingThatNamesAnOrganisation(): void
    {
        // What any instance needs.
        $this->seeNumRecords(count(BaselineSeeder::PERMISSIONS), 'permissions', []);
        $this->seeNumRecords(count(BaselineSeeder::ROLES), 'roles', []);
        $this->seeNumRecords(47, 'counties', []);
        $this->seeNumRecords(count(BaselineSeeder::DOCUMENT_TYPES), 'document_types', []);
        $this->seeNumRecords(count(BaselineSeeder::CLOSE_CHECKS), 'period_close_checks', []);
        $this->seeInDatabase('document_types', ['prefix' => 'OB']);
        $this->seeInDatabase('locales', ['code' => 'en-GB', 'is_source' => 1]);

        // What belongs to whoever the instance is for.
        foreach (['entities', 'users', 'accounts', 'funds', 'programmes', 'fiscal_years', 'periods', 'journals', 'settings', 'approval_rules'] as $table) {
            $this->seeNumRecords(0, $table, []);
        }
    }

    public function testSeedingTheBaselineTwiceChangesNothing(): void
    {
        $before = $this->counts();
        (new BaselineSeeder(config('Database')))->run();
        $this->assertSame($before, $this->counts());
    }

    public function testInstallingCreatesTheOrganisationItsYearAndItsFirstUser(): void
    {
        $done = (new Installer())->install(self::ANSWERS);
        Repository::forget();

        $this->assertStringContainsString('CCT-HQ', $done['entity']);
        $this->assertSame(12, $done['periods']);
        $this->assertSame('Finance Manager', $done['role']);

        $this->seeInDatabase('entities', ['code' => 'CCT-HQ', 'type' => 'Head office', 'parent_id' => null,
            'registered_name' => 'Coast Community Trust', 'short_name' => 'CCT', 'functional_currency' => 'KES']);
        $this->seeInDatabase('fiscal_years', ['code' => 'FY2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        $this->seeNumRecords(12, 'periods', ['status' => 'open']);
        $this->seeInDatabase('periods', ['name' => 'Jan 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31']);
        $this->seeInDatabase('periods', ['name' => 'Dec 2026', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']);
        $this->seeNumRecords(count(BaselineSeeder::APPROVAL_RULES), 'approval_rules', []);

        // The first user can change settings; the system user can never sign in.
        $this->seeInDatabase('users', ['email' => 'a.salim@cct.or.ke', 'short_name' => 'A. Salim', 'initials' => 'AS', 'status' => 'active']);
        $this->seeInDatabase('users', ['email' => Installer::SYSTEM_EMAIL, 'status' => 'suspended']);
        $this->assertContains('settings.manage', (new UserRepository())->actor('a.salim@cct.or.ke')['permissions']);
        $this->assertSame('Finance Manager', (new Lookups())->roleOf((int) (new Lookups())->userId('a.salim@cct.or.ke')));

        // The installation is on the record.
        $this->seeInDatabase('audit_events', ['action' => 'installed', 'object_type' => 'entity', 'object_ref' => 'CCT-HQ']);
    }

    public function testTheApplicationNamesItselfAfterTheOrganisationAndServesItsScreens(): void
    {
        (new Installer())->install(self::ANSWERS);
        Repository::forget();

        $settings = $this->api('api/settings');
        $this->assertSame('Coast Community Trust', $settings['organisation']['registeredName']);
        $this->assertSame('IFRS', $settings['ledger']['framework']);
        $this->assertSame('31 December', $settings['ledger']['yearEnd']);
        $this->assertCount(count(BaselineSeeder::TOGGLES), $settings['toggles']);
        $this->assertSame(['CCT-HQ'], array_column($settings['entities'], 'code'));

        // The shell draws the new organisation's name, with no logo yet.
        $this->assertStringContainsString('CCT', $this->page('/settings'));
    }

    public function testAJournalCanBePostedOnAFreshInstanceOnceTheChartIsImported(): void
    {
        (new Installer())->install(self::ANSWERS);
        Repository::forget();

        $amina = (int) (new Lookups())->userId('a.salim@cct.or.ke');
        $chart = new ChartRepository();
        foreach ([['1000', 'Assets', 'Asset', null], ['1100', 'Cash and cash equivalents', 'Asset', '1000'],
            ['3000', 'Funds and reserves', 'Equity', null]] as [$code, $name, $type, $parent]) {
            $chart->create(['code' => $code, 'name' => $name, 'type' => $type, 'parent' => $parent], $amina);
        }
        Repository::forget();

        $imported = (new ChartRepository())->import([
            ['code' => '1110', 'name' => 'Bank — current account', 'type' => 'Asset'],
            ['code' => '3100', 'name' => 'Accumulated fund', 'type' => 'Equity'],
        ], 'update', 'chart.csv', $amina);
        Repository::forget();

        $this->assertSame(2, $imported['added']);
        $this->assertSame(0.0, (new Lookups())->balance('1110'), 'Imported accounts open at zero.');

        // A fund and a programme are all the coding a first journal needs.
        $this->fundAndProgramme($amina);

        $journal = (new JournalRepository())->create([
            'date' => '15 Jan 2026', 'type' => 'Standard', 'period' => 'Jan 2026', 'status' => 'Draft',
            'docLink' => 'auto', 'memo' => 'First entry', 'narration' => 'Opening float placed in the bank account',
            'lines' => [
                ['code' => '1110', 'desc' => 'Bank', 'fund' => 'General Fund', 'program' => 'Core', 'grantRef' => '', 'dr' => 50000, 'cr' => 0],
                ['code' => '3100', 'desc' => 'Accumulated fund', 'fund' => 'General Fund', 'program' => 'Core', 'grantRef' => '', 'dr' => 0, 'cr' => 50000],
            ],
        ], $amina);

        $this->assertSame('JV-26-0001', $journal['ref']);
        $this->assertSame('Draft', $journal['status']);
        $this->seeInDatabase('journals', ['reference' => 'JV-26-0001', 'status' => 'draft']);
    }

    public function testAFundIsOpenedAndHeldToWhatItCanBe(): void
    {
        (new Installer())->install(self::ANSWERS);
        Repository::forget();
        $amina = (int) (new Lookups())->userId('a.salim@cct.or.ke');
        $funds = new FundRepository();

        $funds->create(['code' => 'FND-100', 'name' => 'General Fund', 'restriction' => 'unrestricted', 'ledgerGroup' => 'general'], $amina);
        Repository::forget();
        $this->seeInDatabase('funds', ['code' => 'FND-100', 'restriction' => 'unrestricted', 'ledger_group' => 'general', 'status' => 'active']);
        $this->seeInDatabase('audit_events', ['object_ref' => 'FND-100', 'action' => 'settings.changed']);

        foreach ([
            [['code' => 'FND-100', 'name' => 'Another', 'restriction' => 'unrestricted', 'ledgerGroup' => 'general'], 'already exists'],
            [['code' => 'x', 'name' => 'Short code', 'restriction' => 'unrestricted', 'ledgerGroup' => 'general'], '2 to 20 letters'],
            [['code' => 'FND-200', 'name' => 'Mixed', 'restriction' => 'endowment', 'ledgerGroup' => 'general'], 'Set both, or neither'],
            [['code' => 'FND-200', 'name' => 'Free grant', 'restriction' => 'unrestricted', 'ledgerGroup' => 'grant'], 'cannot be unrestricted'],
            [['code' => 'FND-200', 'name' => 'No class', 'restriction' => 'vague', 'ledgerGroup' => 'general'], 'A fund is unrestricted'],
        ] as [$input, $expected]) {
            try {
                (new FundRepository())->create($input, $amina);
                $this->fail(json_encode($input) . ' should be refused.');
            } catch (RuleViolation $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
        $this->seeNumRecords(1, 'funds', []);
    }

    public function testACashAccountIsOpenedOnALedgerAccountAndOnlyOnce(): void
    {
        (new Installer())->install(self::ANSWERS);
        Repository::forget();
        $amina = (int) (new Lookups())->userId('a.salim@cct.or.ke');

        $chart = new ChartRepository();
        $chart->create(['code' => '1000', 'name' => 'Assets', 'type' => 'Asset'], $amina);
        $chart->create(['code' => '1100', 'name' => 'Cash and cash equivalents', 'type' => 'Asset', 'parent' => '1000'], $amina);
        $chart->create(['code' => '1110', 'name' => 'Bank — current account', 'type' => 'Asset', 'parent' => '1100'], $amina);
        $chart->create(['code' => '4000', 'name' => 'Income', 'type' => 'Income'], $amina);
        Repository::forget();

        $opened = (new StatementFormatRepository())->createAccount([
            'code' => '1110', 'name' => 'KCB Current Account', 'shortName' => 'KCB Current',
            'kind' => 'bank', 'bankName' => 'KCB', 'accountNumber' => '1104578921', 'currency' => 'KES',
        ], $amina);
        Repository::forget();

        $this->assertSame('KCB Current', $opened['short']);
        $this->seeInDatabase('bank_accounts', ['name' => 'KCB Current Account', 'kind' => 'bank', 'currency' => 'KES', 'status' => 'active']);
        $this->assertSame(['1110'], array_column((new StatementFormatRepository())->accounts(), 'code'));

        foreach ([
            [['code' => '1110', 'name' => 'Second account on the same code'], 'already carries'],
            [['code' => '4000', 'name' => 'Income is not cash'], 'Cash is held on an asset account'],
            [['code' => '1100', 'name' => 'A heading is not cash'], 'is a heading'],
            [['code' => '9999', 'name' => 'Nothing there'], 'not in the chart of accounts'],
            [['code' => '1110', 'name' => ''], 'already carries'],
        ] as [$input, $expected]) {
            try {
                (new StatementFormatRepository())->createAccount($input, $amina);
                $this->fail(json_encode($input) . ' should be refused.');
            } catch (RuleViolation $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
        $this->seeNumRecords(1, 'bank_accounts', []);
    }

    public function testTheSettingsScreensOfferTheFundAndCashAccountPanels(): void
    {
        (new Installer())->install(self::ANSWERS);
        Repository::forget();

        // Segments lists the funds and what a new one may be.
        $settings = $this->api('api/settings');
        $this->assertSame([], $settings['funds'], 'A fresh instance has no funds to post to yet.');
        $this->assertSame(['Unrestricted', 'Restricted', 'Designated', 'Endowment'], $settings['fundOptions']['restrictions']);
        $this->assertSame(['General Fund', 'Grant Fund', 'Capital Fund', 'Endowment Fund'], $settings['fundOptions']['groups']);
        $this->assertSame([], $settings['fundOptions']['funders']);

        $opened = $this->post('api/funds', [
            'code' => 'FND-100', 'name' => 'General Fund', 'restriction' => 'unrestricted', 'ledgerGroup' => 'general',
        ]);
        $opened->assertStatus(200);
        $body = json_decode($opened->getJSON(), true);
        $this->assertStringContainsString('FND-100 General Fund is open', $body['message']);
        // The panel redraws from the write, so the register comes back with it.
        $this->assertSame([['code' => 'FND-100', 'restriction' => 'Unrestricted', 'group' => 'General Fund', 'postings' => 0]],
            array_map(static fn ($f) => ['code' => $f['code'], 'restriction' => $f['restriction'], 'group' => $f['group'], 'postings' => $f['postings']], $body['funds']));

        $refused = $this->post('api/funds', ['code' => 'FND-200', 'name' => 'Loose grant', 'restriction' => 'unrestricted', 'ledgerGroup' => 'grant']);
        $refused->assertStatus(422);
        $this->assertStringContainsString('cannot be unrestricted', json_decode($refused->getJSON(), true)['error']);
    }

    public function testTheBankStatementsPanelOffersTheAccountsACashAccountCouldSitOn(): void
    {
        (new Installer())->install(self::ANSWERS);
        Repository::forget();
        $amina = (int) (new Lookups())->userId('a.salim@cct.or.ke');

        $chart = new ChartRepository();
        $chart->create(['code' => '1000', 'name' => 'Assets', 'type' => 'Asset'], $amina);
        $chart->create(['code' => '1100', 'name' => 'Cash and cash equivalents', 'type' => 'Asset', 'parent' => '1000'], $amina);
        $chart->create(['code' => '1110', 'name' => 'Bank — current account', 'type' => 'Asset', 'parent' => '1100'], $amina);
        Repository::forget();

        $panel = $this->api('api/statement-formats');
        $this->assertSame([], $panel['accounts'], 'No cash account has been opened yet.');
        $this->assertSame(['1110'], array_column($panel['candidates'], 'code'), 'Only the postable asset account is offered.');
        $this->assertArrayHasKey('petty_cash', $panel['kinds']);

        $opened = $this->post('api/statement-formats/account', [
            'code' => '1110', 'name' => 'KCB Current Account', 'shortName' => 'KCB Current', 'kind' => 'bank',
            'bankName' => 'KCB', 'accountNumber' => '1104578921', 'currency' => 'KES',
        ]);
        $opened->assertStatus(200);
        $body = json_decode($opened->getJSON(), true);
        $this->assertStringContainsString('KCB Current Account opened on 1110', $body['message']);
        $this->assertSame(['1110'], array_column($body['accounts'], 'code'));
        $this->assertSame([], $body['candidates'], 'The account it sits on is no longer offered.');

        $again = $this->post('api/statement-formats/account', ['code' => '1110', 'name' => 'Second account']);
        $again->assertStatus(422);
        $this->assertStringContainsString('already carries', json_decode($again->getJSON(), true)['error']);
    }

    public function testAConversionCanBeLoadedOnAFreshInstance(): void
    {
        (new Installer())->install(self::ANSWERS);
        Repository::forget();

        $options = (new ConversionRepository())->options();
        $january = current(array_filter($options['periods'], static fn ($p) => $p['name'] === 'Jan 2026'));

        $this->assertTrue($january['available'], 'Nothing is posted, so the first month can take opening balances.');
        $this->assertTrue($january['yearStart']);
        $this->assertSame('31 Dec 2025', $january['cutOff']);
        $this->assertSame(0, $january['postedBefore']);
        $this->assertNull($options['batch']);
    }

    public function testAnInstanceIsInstalledOnceAndTheAnswersAreChecked(): void
    {
        (new Installer())->install(self::ANSWERS);
        Repository::forget();

        try {
            (new Installer())->install(self::ANSWERS);
            $this->fail('A second install should be refused.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('already belongs to an organisation', $e->getMessage());
        }
    }

    public function testAnAnswerThatWouldNotWorkIsRefusedBeforeAnythingIsWritten(): void
    {
        foreach ([
            [['entityCode' => 'a b'], 'letters, digits and hyphens'],
            [['userEmail' => 'not-an-address'], 'is not an email address'],
            [['userName' => 'Amina'], 'full name'],
            [['framework' => 'Martian GAAP'], 'Reporting framework is one of'],
            [['yearEnd' => '5 April'], 'Financial year end is one of'],
            [['currency' => 'XYZ'], 'not a currency this instance holds'],
            [['firstYear' => 'next year'], 'four-digit year'],
            [['registeredName' => ''], 'Registered name is needed'],
        ] as [$change, $expected]) {
            try {
                (new Installer())->install(array_merge(self::ANSWERS, $change));
                $this->fail(json_encode($change) . ' should be refused.');
            } catch (RuleViolation $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
            $this->seeNumRecords(0, 'entities', []);
        }
    }

    public function testAYearEndingMidYearOpensTheYearBeforeIt(): void
    {
        (new Installer())->install(['yearEnd' => '30 June'] + self::ANSWERS);

        $this->seeInDatabase('fiscal_years', ['code' => 'FY2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
        $this->seeInDatabase('periods', ['name' => 'Jul 2025']);
        $this->seeInDatabase('periods', ['name' => 'Jun 2026']);
    }

    public function testInstallingIsRefusedWithoutTheBaseline(): void
    {
        db_connect()->table('role_permissions')->truncate();
        db_connect()->table('roles')->truncate();

        $this->assertStringContainsString('BaselineSeeder', (string) (new Installer())->refusal());
    }

    // ------------------------------------------------------------------

    private function api(string $url): array
    {
        return json_decode($this->get($url)->getJSON(), true);
    }

    private function post(string $url, array $body)
    {
        return $this->withBodyFormat('json')->call('post', $url, $body);
    }

    private function page(string $url): string
    {
        $response = $this->get($url);
        $response->assertStatus(200);

        return (string) $response->getBody();
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $db = db_connect();
        $out = [];
        foreach (['roles', 'permissions', 'role_permissions', 'currencies', 'segments', 'document_types', 'counties',
            'phasing_profiles', 'budget_rules', 'pay_components', 'pay_grades', 'pay_grade_benefits', 'statutory_rates',
            'period_close_checks', 'approval_limits', 'locales'] as $table) {
            $out[$table] = $db->table($table)->countAllResults();
        }

        return $out;
    }

    /** The least coding a posting needs: one fund and one programme, both added as anyone would. */
    private function fundAndProgramme(int $actorId): void
    {
        (new FundRepository())->create([
            'code' => 'FND-100', 'name' => 'General Fund', 'restriction' => 'unrestricted',
            'ledgerGroup' => 'general', 'purpose' => 'Core costs, free of donor conditions',
        ], $actorId);
        // The first programme carries the whole shared-cost allocation; there is
        // nothing else for support costs to be recovered against yet.
        (new ProgrammeRepository())->create([
            'name' => 'Core', 'share' => 100,
            'purpose' => 'Running the organisation, and the costs shared across everything it does',
        ], 'prorate', [], $actorId);
        Repository::forget();
    }
}
