<?php

use App\Controllers\Api\Coa;
use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\ChartRepository;
use App\Repositories\Lookups;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The chart of accounts: how each account is presented (normal side, statement,
 * rolled-up headings, opening balance), the import dry run's rules, and the audit
 * behind "last edited".
 */
final class ChartOfAccountsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace   = 'App';
    protected $refresh     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $seed        = DatabaseSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['app.asOf'] = '2026-08-31';
        Repository::forget();
    }

    public function testNormalSideAndStatementFollowTypeAndContraAccounts(): void
    {
        $this->assertSame('Debit', ChartRepository::normal(['type' => 'Asset', 'name' => 'Bank — KCB Current (KES)']));
        $this->assertSame('Credit', ChartRepository::normal(['type' => 'Asset', 'name' => 'Accumulated depreciation']));
        $this->assertSame('Credit', ChartRepository::normal(['type' => 'Income', 'name' => 'Grant income']));
        $this->assertSame('Income statement', ChartRepository::statement(['type' => 'Expense']));
        $this->assertSame('Balance sheet', ChartRepository::statement(['type' => 'Equity']));
    }

    public function testHeadingsRollUpTheAccountsBeneathThem(): void
    {
        $list = (new ChartRepository())->accounts();
        $totals = Coa::rollup($list);
        $cash = array_sum(array_map(static fn ($a) => $a['balance'], array_filter($list, static fn ($a) => str_starts_with($a['code'], '11') && $a['level'] === 2)));

        $this->assertEquals($cash, $totals['1100']);
    }

    public function testMovementIsTheYearsPostingsAfterTheOpeningBalance(): void
    {
        $bank = (new ChartRepository())->find('1120');

        $this->assertEquals($bank['balance'], $bank['opening'] + $bank['movement']);
        // The USAID tranche received in July is part of the year's movement, not the balance brought forward.
        $this->assertGreaterThanOrEqual(28400000, $bank['movement']);
    }

    public function testTheDryRunClassifiesEveryRowAndChangesNothing(): void
    {
        $repo = new ChartRepository();
        $rows = $repo->importDryRun([
            ['code' => '5310', 'name' => 'Office rent and utilities', 'type' => 'Expense'],
            ['code' => '5430', 'name' => 'Observer accreditation fees', 'type' => 'Expense'],
            ['code' => '54A0', 'name' => 'Mis-coded', 'type' => 'Expense'],
            ['code' => '543', 'name' => 'Too short', 'type' => 'Expense'],
            ['code' => '5440', 'name' => 'No type', 'type' => ''],
            ['code' => '5430', 'name' => 'Duplicate', 'type' => 'Expense'],
            ['code' => '7110', 'name' => 'Orphan', 'type' => 'Income'],
            ['code' => '3110', 'name' => 'Under a posted account', 'type' => 'Equity'],
            ['code' => '5210', 'name' => 'Salaries', 'type' => 'Income'],
        ], 'update');

        $this->assertSame(['Update', 'New', 'Rejected', 'Rejected', 'Rejected', 'Rejected', 'Rejected', 'Rejected', 'Rejected'], array_column($rows, 'state'));
        $this->assertSame('Account code must be digits only', $rows[2]['reason']);
        $this->assertSame('Account code must be 4 digits', $rows[3]['reason']);
        $this->assertSame('Duplicate of line 3 in this file', $rows[5]['reason']);
        $this->assertSame('No parent account exists for 7100', $rows[6]['reason']);
        $this->assertSame('3100 carries postings, so no account can be added under it', $rows[7]['reason']);
        $this->assertSame('Type cannot change on an account that already carries postings', $rows[8]['reason']);
        $this->assertNull((new Lookups())->accounts()['5430'] ?? null);

        $this->assertSame('Skipped', $repo->importDryRun([['code' => '5310', 'name' => 'Rent', 'type' => 'Expense']], 'skip')[0]['state']);
    }

    public function testAnImportAddsUnderTheHeadingAtZeroAndIsRecorded(): void
    {
        $repo = new ChartRepository();
        $actor = (new Lookups())->userId('W. Kamau');
        $result = $repo->import([
            ['code' => '5430', 'name' => 'Observer accreditation fees', 'type' => 'Expense', 'restriction' => 'Restricted', 'fund' => 'Grant Fund', 'program' => 'Election Observation', 'funder' => 'USAID / Uraia'],
            ['code' => '5310', 'name' => 'Office rent, utilities and security', 'type' => 'Expense', 'fund' => 'General Fund', 'program' => 'Shared'],
            ['code' => '99', 'name' => 'Rejected', 'type' => 'Expense'],
        ], 'update', 'chart.csv', $actor);

        $this->assertSame(['added' => 1, 'updated' => 1], $result);
        $added = $repo->find('5430');
        $this->assertSame(2, $added['level']);
        $this->assertSame(0, $added['balance']);
        $this->assertSame('Grant Fund', $added['fund']);
        $this->assertSame('5400 · Grants to implementing partners', $added['parent']);
        $this->assertSame('Office rent, utilities and security', $repo->find('5310')['name']);
        $this->assertSame('W. Kamau', $repo->lastEdited()['who']);
    }

    public function testAnImportWithNothingAcceptableIsRefused(): void
    {
        $this->expectException(RuleViolation::class);
        $this->expectExceptionMessage('every row was rejected');

        (new ChartRepository())->import([['code' => 'ABCD', 'name' => 'x', 'type' => 'Expense']], 'update', 'bad.csv', null);
    }

    public function testANewAccountNeedsAFourDigitCode(): void
    {
        $this->expectException(RuleViolation::class);
        $this->expectExceptionMessage('4 digits');

        (new ChartRepository())->create(['code' => '537', 'name' => 'Short code', 'type' => 'Expense', 'parent' => '5300 · Administration and governance']);
    }

    public function testTheSeededChartWasLastEditedWhen1395WasArchived(): void
    {
        $events = db_connect()->table('audit_events')->where('object_type', 'account')->where('object_ref', '1395')->get()->getResultArray();

        $this->assertCount(1, $events);
        $this->assertSame('account.archived', $events[0]['action']);
    }
}
