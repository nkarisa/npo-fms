<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Libraries\Ledger;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The statements articulate: assets less liabilities equal the fund balances.
 * The handoff README makes this non-negotiable, so what gets pinned here is the
 * way account membership is decided — reading the chart, not a list of codes.
 */
final class StatementArticulationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    // The books are loaded into the test database once for this class.
    protected $namespace   = 'App';
    protected $refresh     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $seed        = DatabaseSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        // Figures that depend on "today" are measured from the date the data describes.
        $_ENV['app.asOf'] = '2026-08-31';
        Repository::forget();
    }

    public function testTheStatementOfFinancialPositionBalances(): void
    {
        $this->assertTrue(Ledger::positionBalanced());
    }

    /**
     * 2250 and 2260 were added to the chart after the statement was first built
     * from a hardcoded list, and fell out of it. They must be found because they
     * sit under a liability heading, not because someone remembered to list them.
     */
    public function testAccountsAddedUnderAnExistingHeadingAreIncluded(): void
    {
        $liabilities = Ledger::statementCodes('Liability');

        $this->assertContains('2250', $liabilities);
        $this->assertContains('2260', $liabilities);
    }

    public function testGroupHeadingsAreNeverTreatedAsPostableAccounts(): void
    {
        foreach (['Asset', 'Liability', 'Equity'] as $type) {
            $codes = Ledger::statementCodes($type);

            foreach (['1000', '1100', '1200', '1300', '2000', '2100', '2200', '3000'] as $heading) {
                $this->assertNotContains($heading, $codes, $heading . ' is a heading, not an account');
            }
        }
    }

    /** The fund balances are level-1 accounts with nothing beneath them. */
    public function testFundBalancesAreFoundAtLevelOne(): void
    {
        $this->assertSame(['3100', '3200', '3300', '3900'], Ledger::statementCodes('Equity'));
    }

    /**
     * Archiving an account does not make its balance disappear, so an archived
     * account is dropped only when there is nothing on it.
     */
    public function testAnArchivedAccountIsOmittedOnlyWhenItCarriesNoBalance(): void
    {
        // 1395 Leasehold improvements is archived at zero.
        $this->assertNotContains('1395', Ledger::statementCodes('Asset'));
    }

    public function testNonCurrentAssetsAreSplitByTheirHeading(): void
    {
        $nonCurrent = Ledger::statementCodes(
            'Asset',
            static fn ($l) => in_array($l['group'], Ledger::NON_CURRENT_ASSET_GROUPS, true)
        );

        // Accumulated depreciation is a contra account, but it still belongs
        // with the property and equipment it writes down.
        $this->assertSame(['1310', '1320', '1390'], $nonCurrent);
    }
}
