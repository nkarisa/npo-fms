<?php

use App\Controllers\Api\Advances;
use App\Controllers\Api\Receivables;
use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\AdvancesRepository;
use App\Repositories\ReceivablesRepository;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The two balance-carrying registers. Both have to agree to a control account,
 * so what counts as "outstanding" is the assertion worth pinning down.
 */
final class AdvancesAndReceivablesTest extends CIUnitTestCase
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

    /**
     * The register must tie to the balance posted on 1220. If this breaks, either
     * the books changed or the definition of outstanding drifted — both are exactly
     * the drift the screen exists to report.
     */
    public function testAdvancesRegisterTiesToTheControlAccount(): void
    {
        $repo = new AdvancesRepository();
        $outstanding = array_sum(array_map(static fn ($a) => Advances::outstanding($a), $repo->all()));

        $this->assertSame(3184000.0, (float) $outstanding);
        $this->assertSame($repo->controlBalance(), (float) $outstanding);
    }

    public function testOnlyIssuedAdvancesAreOutstanding(): void
    {
        $base = ['amount' => 100000, 'accounted' => 0, 'recovered' => 0];

        $this->assertSame(100000.0, (float) Advances::outstanding($base + ['status' => 'Issued']));

        // Requested and approved money has not left the bank yet.
        $this->assertSame(0.0, (float) Advances::outstanding($base + ['status' => 'Requested']));
        $this->assertSame(0.0, (float) Advances::outstanding($base + ['status' => 'Approved']));

        // Cleared advances carry no balance however they were cleared.
        $this->assertSame(0.0, (float) Advances::outstanding($base + ['status' => 'Surrendered']));
        $this->assertSame(0.0, (float) Advances::outstanding($base + ['status' => 'Recovered']));
    }

    public function testPartlyAccountedAdvanceLeavesOnlyTheBalanceOutstanding(): void
    {
        $a = ['status' => 'Issued', 'amount' => 254000, 'accounted' => 231400, 'recovered' => 0];

        $this->assertSame(22600.0, (float) Advances::outstanding($a));
    }

    public function testReceivableOutstandingNeverGoesNegative(): void
    {
        $this->assertSame(4400000.0, (float) Receivables::outstanding(['amount' => 12400000, 'received' => 8000000]));
        $this->assertSame(0.0, (float) Receivables::outstanding(['amount' => 3150000, 'received' => 3150000]));

        // A donor overpayment is a credit to resolve, not a negative receivable.
        $this->assertSame(0.0, (float) Receivables::outstanding(['amount' => 1000, 'received' => 1500]));
    }

    /** Every claim reconciles to the sum of its own lines. */
    public function testReceivableLinesSumToTheClaimValue(): void
    {
        foreach ((new ReceivablesRepository())->all() as $i) {
            $lines = array_sum(array_map(static fn ($l) => $l['amount'], $i['lines']));

            $this->assertSame(
                (float) $i['amount'],
                (float) $lines,
                $i['no'] . ' lines do not sum to the claim value'
            );
        }
    }
}
