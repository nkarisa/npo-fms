<?php

use App\Commands\Install;
use App\Database\Seeds\BaselineSeeder;
use App\Libraries\Installer;
use App\Repositories\ChartRepository;
use App\Repositories\ConversionRepository;
use App\Repositories\FundRepository;
use App\Repositories\JournalRepository;
use App\Repositories\Lookups;
use App\Repositories\PayrollRepository;
use App\Repositories\ProgrammeRepository;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use App\Repositories\SettingsRepository;
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
    use \Tests\Support\SignsIn;

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
        $this->signIn();
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
        $this->assertEmpty(array_diff(['settings.organisation', 'settings.ledger', 'settings.approvals', 'settings.integrations', 'users.manage'],
            (new UserRepository())->actor('a.salim@cct.or.ke')['permissions']));
        $this->assertSame('Finance Manager', (new Lookups())->roleOf((int) (new Lookups())->userId('a.salim@cct.or.ke')));

        // The installation is on the record.
        $this->seeInDatabase('audit_events', ['action' => 'installed', 'object_type' => 'entity', 'object_ref' => 'CCT-HQ']);
    }

    public function testTheFirstUserChoosesAPasswordFromAOneTimeLinkUnlessTheAnswersGiveOne(): void
    {
        // No password in the answers: a link to choose one, and no way in until it is used.
        $done = (new Installer())->install(self::ANSWERS);
        $this->assertMatchesRegularExpression('#/accept-invite\?token=[A-Za-z0-9_-]{40,}$#', (string) $done['link']);
        $this->assertNull(db_connect()->table('users')->where('email', 'a.salim@cct.or.ke')->get()->getRow()->password_hash);
        $this->assertSame('Amina Salim', json_decode($this->get('api/auth/invite?token=' . substr(strrchr($done['link'], '='), 1))->getJSON(), true)['link']['name']);
        $this->assertContains('users.manage', (new UserRepository())->actor('a.salim@cct.or.ke')['permissions']);
    }

    public function testAnUnattendedInstallCanSetTheFirstPassword(): void
    {
        $done = (new Installer())->install(['userPassword' => 'coast trust ledger 2026'] + self::ANSWERS);
        $this->assertNull($done['link']);
        $hash = db_connect()->table('users')->where('email', 'a.salim@cct.or.ke')->get()->getRow()->password_hash;
        $this->assertTrue(password_verify('coast trust ledger 2026', $hash));
    }

    public function testTheApplicationNamesItselfAfterTheOrganisationAndServesItsScreens(): void
    {
        (new Installer())->install(self::ANSWERS);
        $this->signIn();
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
        $this->signIn();
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
        $this->signIn();
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
        $this->signIn();
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
        $this->signIn();
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
        $this->signIn();
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
        $this->signIn();
        Repository::forget();

        $options = (new ConversionRepository())->options();
        $january = current(array_filter($options['periods'], static fn ($p) => $p['name'] === 'Jan 2026'));

        $this->assertTrue($january['available'], 'Nothing is posted, so the first month can take opening balances.');
        $this->assertTrue($january['yearStart']);
        $this->assertSame('31 Dec 2025', $january['cutOff']);
        $this->assertSame(0, $january['postedBefore']);
        $this->assertNull($options['batch']);
    }

    public function testTheTrialBalanceTemplateIsJustItsColumnsUntilThereIsAChart(): void
    {
        (new Installer())->install(self::ANSWERS);
        $this->signIn();
        Repository::forget();

        $template = (new ConversionRepository())->template('Jan 2026');
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim(substr($template['csv'], 3)))));

        $this->assertCount(1, $rows, 'With no chart there is nothing to list — only the columns to fill in.');
        $this->assertSame(['Account code', 'Account name', 'Fund code', 'Programme code', 'Award ref', 'County code', 'Debit', 'Credit'], $rows[0]);
        $this->assertSame('opening-balances-2025-12-31.csv', $template['filename']);
    }

    public function testPayrollDrawsOnAFreshInstanceAndSaysWhereItCannotPostYet(): void
    {
        (new Installer())->install(self::ANSWERS);
        $this->signIn();
        Repository::forget();

        // The chart is imported after the reference data, so nothing is mapped yet.
        $payroll = $this->api('api/payroll');
        $this->assertSame('Sep 2026', $payroll['period']);
        $this->assertSame([], $payroll['journal']['lines'], 'There is nowhere to post, so no journal is drawn.');
        $this->assertStringContainsString('nowhere to post yet', $payroll['journal']['check']);
        $this->assertContains('PAYE has no account to post to', $payroll['journal']['unmapped']);
        $this->assertContains('Net pay is paid from account 1110, which is not in the chart of accounts', $payroll['journal']['unmapped']);
        $this->assertContains('There is no active general fund for the statutory liabilities to be held against', $payroll['journal']['unmapped']);

        // The page itself renders; it is the fetch behind it that used to fail.
        $this->assertStringContainsString('payroll.js', $this->page('/payroll'));

        // Settings → Payroll is where it is set, and says what is still missing.
        $settings = $this->api('api/settings');
        $this->assertSame([], $settings['payAccountOptions'], 'Nothing to map to until the chart is imported.');
        $mapping = array_column($settings['payAccounts'], null, 'key');
        $this->assertSame('', $mapping['paye']['code']);
        $this->assertTrue($mapping['paye']['required']);
        $this->assertFalse($mapping['acting_allowance']['required']);
    }

    public function testOnceTheChartIsImportedPayrollIsMappedAndPosts(): void
    {
        (new Installer())->install(self::ANSWERS);
        $this->signIn();
        Repository::forget();
        $amina = (int) (new Lookups())->userId('a.salim@cct.or.ke');

        $chart = new ChartRepository();
        foreach ([['1000', 'Assets', 'Asset', null], ['1100', 'Cash', 'Asset', '1000'], ['1110', 'Bank', 'Asset', '1100'],
            ['2000', 'Liabilities', 'Liability', null], ['2200', 'Statutory', 'Liability', '2000'], ['2210', 'PAYE payable', 'Liability', '2200'],
            ['5000', 'Expenditure', 'Expense', null], ['5200', 'Staff costs', 'Expense', '5000'], ['5210', 'Salaries', 'Expense', '5200']] as [$code, $name, $type, $parent]) {
            $chart->create(['code' => $code, 'name' => $name, 'type' => $type, 'parent' => $parent], $amina);
        }
        $this->fundAndProgramme($amina);

        $options = array_column((new SettingsRepository())->payAccountOptions(), 'code');
        $this->assertSame(['1110', '2210', '5210'], $options, 'Only postable accounts in the right ranges are offered.');

        // Mapping the components saves with the rest of the settings draft.
        (new SettingsRepository())->save(['payAccounts' => [
            'basic_salary' => '5210', 'nssf_employer' => '5210', 'paye' => '2210', 'nssf_employee' => '2210',
            'shif' => '2210', 'housing_levy_employee' => '2210', 'sacco' => '2210', 'advance_recovery' => '2210',
        ]], $amina);
        Repository::forget();

        $this->assertSame([], (new PayrollRepository())->unmapped(), 'Nothing is left unmapped.');
        $payroll = $this->api('api/payroll');
        $this->assertSame([], $payroll['journal']['unmapped']);
        // No staff yet, so the run is nil — but it is a run, and the panel draws it.
        $this->assertStringContainsString('Balanced', $payroll['journal']['check']);
        $this->seeInDatabase('audit_events', ['action' => 'settings.changed', 'object_type' => 'settings:payroll']);
    }

    /**
     * Nothing in the application may fail to draw just because the instance is new.
     *
     * A screen with nothing behind it yet either serves its empty state or refuses
     * with a sentence saying what to do — never a 500, which is what a blank page is.
     */
    public function testEveryReadEitherWorksOrRefusesWithAReasonOnAFreshInstance(): void
    {
        (new Installer())->install(self::ANSWERS);
        $this->signIn();
        Repository::forget();

        $broken = [];
        $refused = [];
        foreach ($this->reads() as $url) {
            Repository::forget();
            try {
                $response = $this->get($url);
                $status = $response->response()->getStatusCode();
                if ($status === 404) {
                    // A controlled refusal. Anything the browser reads as JSON has to say
                    // why, so the screen can print it; the logo is an image, and its
                    // absence is the sidebar drawing the organisation's initials instead.
                    $error = json_decode($response->getJSON() ?: '{}', true)['error'] ?? '';
                    $refused[$url] = $error;
                    if ($url !== 'api/settings/logo' && strlen($error) < 20) {
                        $broken[] = $url . ' refuses without explaining why';
                    }
                } elseif ($status >= 400) {
                    $broken[] = $url . ' → HTTP ' . $status;
                }
            } catch (\Throwable $e) {
                $broken[] = $url . ' → ' . get_class($e) . ': ' . $e->getMessage();
            }
        }

        $this->assertSame([], $broken, 'Reads that fail on a freshly installed instance');

        // The ones that do refuse are the screens with nothing behind them yet.
        $this->assertSame(['api/gl', 'api/gl/export', 'api/bank-rec', 'api/cashflow/export', 'api/budgets/line', 'api/budgets/export', 'api/donor-reports/export', 'api/settings/logo'], array_keys($refused));
        $this->assertStringContainsString('Import the chart of accounts first', $refused['api/gl']);
    }

    public function testAChartTemplateIsClonedIntoAnEmptyChartAndPayrollFollowsIt(): void
    {
        (new Installer())->install(self::ANSWERS);
        $this->signIn();
        Repository::forget();
        $amina = (int) (new Lookups())->userId('a.salim@cct.or.ke');

        $offered = $this->api('api/coa/templates');
        $this->assertSame(['nfp', 'nfp-compact'], array_column($offered['templates'], 'key'));
        $this->assertTrue($offered['canManage']);

        $plan = $this->api('api/coa/templates?template=nfp')['plan'];
        $this->assertSame(64, $plan['adds'], 'Nothing is held yet, so every account is new.');
        $this->assertSame(0, $plan['existing']);
        $this->assertSame(['New'], array_unique(array_column($plan['payroll'], 'state')));

        $done = $this->post('api/coa/templates', ['template' => 'nfp']);
        $done->assertStatus(200);
        $body = json_decode($done->getJSON(), true);
        $this->assertSame(64, $body['added']);
        $this->assertStringContainsString('64 accounts opened at zero', $body['message']);
        $this->assertStringContainsString('Only journals move a balance', $body['message']);
        Repository::forget();

        // The tree is built, not flat: headings carry what sits under them.
        $chart = array_column((new ChartRepository())->accounts(), null, 'code');
        $this->assertSame(0, $chart['1000']['level']);
        $this->assertSame(1, $chart['1100']['level']);
        $this->assertSame(2, $chart['1110']['level']);
        $this->assertSame('1100 · Cash and cash equivalents', $chart['1110']['parent']);
        $this->assertSame('— (top level)', $chart['1000']['parent']);
        $this->assertSame(0.0, (new Lookups())->balance('1110'), 'Every account opens at zero.');

        // Only the leaves take postings.
        $this->seeInDatabase('accounts', ['code' => '1110', 'is_leaf' => 1]);
        $this->seeInDatabase('accounts', ['code' => '1100', 'is_leaf' => 0]);
        $this->seeInDatabase('accounts', ['code' => '1000', 'is_leaf' => 0]);

        // Every pay component now has an account. A template carries accounts, not
        // funds and programmes, so those two are still the organisation's to open.
        $left = (new PayrollRepository())->unmapped();
        $this->assertSame([], array_values(array_filter($left, static fn ($m) => str_contains($m, 'account'))));
        $this->assertSame([
            'There is no active general fund for the statutory liabilities to be held against',
            'There is no programme for the statutory liabilities to be charged to',
        ], $left);

        $this->fundAndProgramme($amina);
        $this->assertSame([], (new PayrollRepository())->unmapped(), 'With a fund and a programme, payroll can post.');
        $this->seeInDatabase('audit_events', ['action' => 'chart.template']);

        // And the general ledger draws instead of refusing.
        $this->assertArrayHasKey('rows', $this->api('api/gl'));
    }

    public function testCloningOntoAPartBuiltChartFillsTheGapsAndLeavesWhatIsThere(): void
    {
        (new Installer())->install(self::ANSWERS);
        $this->signIn();
        Repository::forget();
        $amina = (int) (new Lookups())->userId('a.salim@cct.or.ke');

        // An account the organisation has already opened, named its own way.
        $chart = new ChartRepository();
        $chart->create(['code' => '1000', 'name' => 'What we own', 'type' => 'Asset'], $amina);
        $chart->create(['code' => '1100', 'name' => 'Money', 'type' => 'Asset', 'parent' => '1000'], $amina);
        $chart->create(['code' => '1110', 'name' => 'Co-op Bank current', 'type' => 'Asset', 'parent' => '1100'], $amina);
        Repository::forget();

        $plan = $this->api('api/coa/templates?template=nfp-compact')['plan'];
        $this->assertSame(3, $plan['existing']);
        $this->assertSame(41, $plan['adds']);

        $body = json_decode($this->post('api/coa/templates', ['template' => 'nfp-compact'])->getJSON(), true);
        $this->assertSame(41, $body['added']);
        $this->assertSame(3, $body['skipped']);
        $this->assertStringContainsString('3 already held and left as they are', $body['message']);
        Repository::forget();

        // Their names stand; the template does not rewrite what is already there.
        $held = array_column((new ChartRepository())->accounts(), null, 'code');
        $this->assertSame('Co-op Bank current', $held['1110']['name']);
        $this->assertSame('What we own', $held['1000']['name']);
        $this->assertSame('Petty cash', $held['1140']['name'] ?? '', 'The compact template has no petty cash account.');
    }

    public function testATemplateCanBeAdjustedBeforeItIsAdopted(): void
    {
        (new Installer())->install(self::ANSWERS);
        $this->signIn();
        Repository::forget();

        // The organisation renames one account, retypes and re-restricts another, and
        // leaves out a whole heading — everything under it goes with it.
        $edits = [
            ['code' => '1110', 'name' => 'Co-op Bank current'],
            ['code' => '4130', 'type' => 'Income', 'restriction' => 'Restricted'],
            ['code' => '5400', 'include' => false], // "Grants to partners" and its two children
        ];
        $done = $this->post('api/coa/templates/adopt', ['template' => 'nfp', 'rows' => $edits]);
        $done->assertStatus(200);
        $body = json_decode($done->getJSON(), true);

        // 64 in the full template, less the excluded heading and its two children.
        $this->assertSame(61, $body['added']);
        $this->assertStringContainsString('adopted', $body['message']);
        Repository::forget();

        $chart = array_column((new ChartRepository())->accounts(), null, 'code');
        $this->assertSame('Co-op Bank current', $chart['1110']['name'], 'The edited name is the one opened.');
        $this->assertSame('Restricted', $chart['4130']['restriction']);
        $this->assertArrayNotHasKey('5400', $chart, 'An excluded heading is not opened.');
        $this->assertArrayNotHasKey('5410', $chart, 'Nor are the accounts that sat under it.');
        $this->assertArrayNotHasKey('5420', $chart);

        // Untouched accounts still take the template's own names and coding.
        $this->assertSame('Petty cash', $chart['1140']['name']);
        $this->assertSame(0.0, (new Lookups())->balance('1110'), 'An adopted account still opens at zero.');
        $this->seeInDatabase('audit_events', ['action' => 'chart.template']);
    }

    public function testAnInstanceIsInstalledOnceAndTheAnswersAreChecked(): void
    {
        (new Installer())->install(self::ANSWERS);
        $this->signIn();
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
            [['userPassword' => 'short'], 'at least 12 characters'],
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
        $this->signIn();

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

    public function testTheAnswersFileIsReadWithOrWithoutAnEqualsSignAndFromWhereSparkWasRun(): void
    {
        // What CodeIgniter's parser makes of `--config=install.json` and `--config install.json`.
        $cwd = WRITEPATH;
        $this->assertSame(WRITEPATH . 'install.json', Install::answersFile(['config=install.json' => null], $cwd));
        $this->assertSame(WRITEPATH . 'install.json', Install::answersFile(['config' => 'install.json'], $cwd));
        $this->assertSame('/etc/answers.json', Install::answersFile(['config=/etc/answers.json' => null], $cwd));
        $this->assertSame(ROOTPATH . 'install.json', Install::answersFile(['config' => 'install.json'], ''));

        $this->assertNull(Install::answersFile(['yes' => null], $cwd), 'No --config: the answers are asked for.');
        $this->assertSame('', Install::answersFile(['config' => null], $cwd), '--config with no file is refused, not asked for.');
    }

    // ------------------------------------------------------------------

    /** Every GET the application serves, from the routes themselves. */
    private function reads(): array
    {
        $urls = [];
        foreach (file(ROOTPATH . 'app/Config/Routes.php') as $line) {
            // Not the sign-in link lookups: without the token from an email they refuse, as they should.
            if (preg_match("/\\\$routes->get\('([^'(]+)',/", $line, $m) === 1 && !str_contains($m[1], '(') && !str_starts_with($m[1], 'auth/')) {
                $urls[] = str_starts_with($line, '    ') ? 'api/' . $m[1] : $m[1];
            }
        }

        return array_values(array_unique($urls));
    }

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
