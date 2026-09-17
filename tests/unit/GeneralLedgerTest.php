<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\JournalRepository;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The general ledger: each account's year as its balance brought forward and a run
 * of postings, the history seeded without moving any balance, and the ledger
 * arithmetic the screen shows.
 */
final class GeneralLedgerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

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

    public function testTheHistoryIsInTheLedgerButNotTheJournalRegister(): void
    {
        $archived = (int) db_connect()->table('journals')->where('source_type', 'archive')->countAllResults();

        $this->assertGreaterThan(400, $archived);
        // The register is the opening balance journal, the prototype's 12 JOURNALS entries, the issue
        // entries of the 7 claims issued and the receipt from Ford, and the allowance and write-off
        // entries that bring INV-26-0020's recorded write-off into the ledger.
        $this->assertCount(23, (new JournalRepository())->all());
        $this->assertSame([], array_filter((new JournalRepository())->all(), static fn ($j) => str_starts_with($j['doc'], 'ELOG/5')));
    }

    public function testHistoryIsDatedInTheClosedMonthsOnly(): void
    {
        $row = db_connect()->table('journals')->selectMin('journal_date', 'first')->selectMax('journal_date', 'last')->where('source_type', 'archive')->get()->getRowArray();

        $this->assertGreaterThanOrEqual('2026-01-01', $row['first']);
        $this->assertLessThanOrEqual('2026-07-31', $row['last']);
    }

    public function testTheSeededLedgerStillAgreesWithTheChart(): void
    {
        // Seeding the history must not move a single balance: 5110 is the prototype's 38,260,000.
        $postings = (new JournalRepository())->postings('5110');
        $balance = array_sum(array_map(static fn ($e) => $e['debit'] - $e['credit'], $postings));

        $this->assertEquals(38260000, $balance);
        $this->assertGreaterThan(10, count(array_filter($postings, static fn ($e) => $e['archived'])));
        $this->assertCount(1, array_filter($postings, static fn ($e) => $e['opening']));
    }

    public function testOpeningPlusMovementIsTheClosingBalance(): void
    {
        $ledger = json_decode($this->get('api/gl?account=5110')->getJSON(), true);
        $num = static fn (string $v) => $v === '—' ? 0.0 : (float) str_replace([',', '(', ')'], ['', '-', ''], $v);

        $this->assertSame('38,260,000', $ledger['closing']);
        $this->assertEquals($num($ledger['closing']), $num($ledger['opening']) + $num($ledger['totalDebit']) - $num($ledger['totalCredit']));
        $this->assertSame('Opening balance', $ledger['summary'][0]['label']);
        $this->assertSame('Net movement', $ledger['summary'][3]['label']);
    }

    public function testFiltersNarrowThePostingsAndCarryEarlierMonthsIntoTheOpeningBalance(): void
    {
        $all = json_decode($this->get('api/gl?account=1110')->getJSON(), true);
        $july = json_decode($this->get('api/gl?account=1110&period=' . rawurlencode('Jul 2026'))->getJSON(), true);
        $general = json_decode($this->get('api/gl?account=1110&fund=' . rawurlencode('General Fund'))->getJSON(), true);

        $this->assertLessThan(count($all['rows']), count($july['rows']));
        $this->assertNotSame($all['opening'], $july['opening']);
        // The year closes on the chart's balance; July closes before August's cash book posts.
        $this->assertSame('18,420,500', $all['closing']);
        $this->assertSame('8,180,500', $july['closing']);
        $this->assertSame([], array_filter($general['rows'], static fn ($r) => $r['fund'] !== 'General Fund'));
        $this->assertNotEmpty($all['awards']);
    }

    public function testAnEntryShowsItsBalancedLines(): void
    {
        $entry = json_decode($this->get('api/gl/entry/MP-26-0274')->getJSON(), true);

        $this->assertCount(2, $entry['lines']);
        $this->assertSame('4,280,000', $entry['total']);
    }
}
