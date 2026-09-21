<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\FundRepository;
use App\Repositories\JournalRepository;
use App\Repositories\Lookups;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The v5 funds screen: the statement of changes in funds read from the ledger, and
 * inter-fund transfers raised there and posted through the journal approval, under
 * the inter-fund transfer rule rather than the journal threshold.
 *
 * In the demonstration data nothing unrestricted has a balance to give — the
 * General Fund is overdrawn and the Board Designated Reserve holds nothing — so
 * the tests that move money first designate some to the reserve.
 */
final class FundsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

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

    public function testTheStatementOfChangesInFundsArticulatesWithTheLedger(): void
    {
        $funds = (new FundRepository())->all();
        $ledger = array_column(db_connect()->table('v_fund_balances')->get()->getResultArray(), 'balance', 'fund_code');

        foreach ($funds as $f) {
            $this->assertEqualsWithDelta((float) ($ledger[$f['code']] ?? 0), FundRepository::closing($f), 0.01, $f['code'] . ' closes at its ledger balance.');
        }
        // The year's balances brought forward are opening, not income or a transfer.
        $endowment = (new FundRepository())->find('FND-400');
        $this->assertSame(9500000, $endowment['opening']);
        $this->assertSame(0, $endowment['transfers']);

        $screen = $this->api('api/funds');
        $this->assertSame('FY2026', $screen['year']);
        $this->assertCount(9, $screen['rows']);
        $this->assertSame(['All funds (9)', 'Unrestricted (2)', 'Restricted (6)', 'Endowment (1)'], array_column($screen['tabs'], 'label'));
        $this->assertSame('—', $screen['totals']['transfers']);
        $this->assertSame(number_format(array_sum($ledger)), $screen['totals']['closing']);
        $this->assertSame('1 restricted fund closes within 90 days', $screen['hint']);

        $restricted = $this->api('api/funds?class=Restricted');
        $this->assertSame(['Restricted'], array_values(array_unique(array_column($restricted['rows'], 'cls'))));
    }

    public function testTheDrawerShowsTheFundsMovementTermsAndAccounts(): void
    {
        $capital = $this->api('api/funds/FND-300');

        $this->assertSame('DANIDA CE-2025/27', $capital['grant'], 'A capital fund names the award it holds.');
        $this->assertSame(['Shared services'], $capital['programs']);
        $this->assertSame('This fund is 100% utilised. Further commitments need a budget revision.', $capital['alert']);
        $depreciation = current(array_filter($capital['accounts'], fn ($a) => $a['code'] === '1390'));
        $this->assertStringNotContainsString('(', $depreciation['balance'], 'A contra account shows on its normal side.');

        $this->get('api/funds/FND-999')->assertStatus(404);
    }

    public function testMoneyThatIsNotFreeCannotBeTransferred(): void
    {
        $this->actAs('w.kamau@elog.or.ke');

        foreach ([
            [['from' => 'FND-210', 'to' => 'FND-100'], 'is donor-restricted'],
            [['from' => 'FND-400', 'to' => 'FND-100'], 'Endowment capital is permanently maintained'],
            [['from' => 'FND-100', 'to' => 'FND-300'], 'exceeds the available balance of (26,033,500) in General Fund'],
            [['from' => 'FND-110', 'to' => 'FND-110'], 'must differ'],
            [['from' => 'FND-110', 'to' => 'FND-100', 'amount' => 0], 'Enter an amount'],
        ] as [$body, $expected]) {
            $response = $this->withBodyFormat('json')->post('api/funds/transfers', $body + ['amount' => 1000, 'minute' => 'BM/2026/08/01']);
            $response->assertStatus(422);
            $this->assertStringContainsString($expected, json_decode($response->getJSON(), true)['error']);
        }
        $this->seeNumRecords(0, 'fund_transfers', []);

        // The approver does not prepare journals, so cannot raise one.
        $this->actAs('d.kiptoo@elog.or.ke');
        $this->withBodyFormat('json')->post('api/funds/transfers', ['from' => 'FND-110', 'to' => 'FND-100', 'amount' => 1, 'minute' => 'BM/1'])->assertStatus(403);
    }

    public function testATransferWaitsForTheExecutiveDirectorAndThenMovesTheBalances(): void
    {
        $this->designate(5000000);
        $before = $this->closings();

        $this->actAs('w.kamau@elog.or.ke');
        $raised = $this->withBodyFormat('json')->post('api/funds/transfers', [
            'from' => 'FND-110', 'to' => 'FND-300', 'amount' => '1,200,000', 'minute' => 'BM/2026/08/04', 'reason' => 'Vehicle replacement',
        ]);
        $raised->assertStatus(200);
        $ref = json_decode($raised->getJSON(), true)['ref'];
        Repository::forget();

        // Raised, not posted: nothing on the screen has moved yet, but what is on its way out is spoken for.
        $journal = (new JournalRepository())->find($ref);
        $this->assertSame('Pending approval', $journal['status']);
        $this->assertSame('BM/2026/08/04', $journal['doc']);
        $this->assertCount(4, $journal['lines']);
        $this->assertSame($before, $this->closings());
        $this->assertStringContainsString('1 transfer not yet posted', $this->api('api/funds')['footer']);
        try {
            (new FundRepository())->transfer(['from' => 'FND-110', 'to' => 'FND-100', 'amount' => 3900000, 'minute' => 'BM/2'], $this->user('w.kamau@elog.or.ke'));
            $this->fail('A second transfer should not spend what the first has already taken.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('after 1,200,000 already awaiting approval', $e->getMessage());
        }

        // Every line balances within its fund, and carries its programme and, in an award fund, its award.
        $lines = db_connect()->table('journal_lines')->where('journal_id', db_connect()->table('journals')->where('reference', $ref)->get()->getRow()->id)->get()->getResultArray();
        $byFund = [];
        foreach ($lines as $l) {
            $byFund[$l['fund_id']] = ($byFund[$l['fund_id']] ?? 0) + $l['debit'] - $l['credit'];
            $this->assertNotNull($l['programme_id']);
        }
        $this->assertSame([0.0, 0.0], array_values(array_map(static fn ($n) => round((float) $n, 2), $byFund)));
        $capitalId = (new FundRepository())->find('FND-300')['id'];
        $this->assertNotNull(current(array_filter($lines, fn ($l) => (int) $l['fund_id'] === $capitalId))['grant_id']);

        // Its lines are the transfer's: the journal editor cannot change them.
        try {
            (new JournalRepository())->update($ref, ['date' => '2026-08-31', 'type' => 'Adjustment', 'period' => 'Aug 2026', 'status' => 'Draft', 'docLink' => 'auto', 'memo' => '', 'narration' => 'x', 'lines' => []], $this->user('w.kamau@elog.or.ke'));
            $this->fail('A transfer\'s journal should not be editable.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('records an inter-fund transfer', $e->getMessage());
        }

        $this->actAs('d.kiptoo@elog.or.ke');
        $this->post('api/journals/' . $ref . '/approve')->assertStatus(200);
        Repository::forget();

        $after = $this->closings();
        $this->assertEqualsWithDelta($before['FND-110'] - 1200000, $after['FND-110'], 0.01);
        $this->assertEqualsWithDelta($before['FND-300'] + 1200000, $after['FND-300'], 0.01);
        $this->assertEqualsWithDelta(array_sum($before), array_sum($after), 0.01, 'A transfer moves money between funds, not in or out of them.');
        $reserve = (new FundRepository())->find('FND-110');
        $this->assertSame(5000000, $reserve['opening'], 'The designation is opening; the transfer is not.');
        $this->assertSame(-1200000, $reserve['transfers']);
        $this->assertStringNotContainsString('not yet posted', $this->api('api/funds')['footer']);

        // Reversing it is a transfer too: the funds are back where they were.
        $this->actAs('w.kamau@elog.or.ke');
        $reversal = (new JournalRepository())->reverse($ref, $this->user('w.kamau@elog.or.ke'));
        (new JournalRepository())->approve($reversal['ref'], $this->user('d.kiptoo@elog.or.ke'));
        Repository::forget();
        $this->assertSame(0, (new FundRepository())->find('FND-110')['transfers']);
        $this->assertEqualsWithDelta($before['FND-300'], $this->closings()['FND-300'], 0.01);
    }

    public function testTransfersAreApprovedUnderTheirOwnRuleNotTheJournalThreshold(): void
    {
        $this->designate(1000000);

        // 400,000 is within the Finance Manager's journal threshold; a transfer still needs the Executive Director.
        $done = (new FundRepository())->transfer(['from' => 'FND-110', 'to' => 'FND-100', 'amount' => 400000, 'minute' => 'BM/2026/08/05'], $this->user('m.otieno@elog.or.ke'));
        try {
            (new JournalRepository())->approve($done['ref'], $this->user('w.kamau@elog.or.ke'));
            $this->fail('The Finance Manager should not approve an inter-fund transfer.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('Inter-fund transfers are approved by the Executive Director', $e->getMessage());
        }

        // Returned and discarded, the transfer goes with its journal.
        $repo = new JournalRepository();
        $repo->reject($done['ref'], $this->user('d.kiptoo@elog.or.ke'), 'Minute not yet signed');
        $repo->discard($done['ref'], $this->user('m.otieno@elog.or.ke'));
        $this->seeNumRecords(0, 'fund_transfers', []);
    }

    public function testTheStatementOfFundsExportsTheYear(): void
    {
        $response = $this->get('api/funds/statement');
        $response->assertStatus(200);
        $this->assertStringContainsString('statement of funds FY2026.csv', $response->response()->getHeaderLine('Content-Disposition'));

        $rows = array_map('str_getcsv', explode("\n", trim(ltrim((string) $response->response()->getBody(), "\xEF\xBB\xBF"))));
        $this->assertSame('Statement of changes in funds — FY2026', $rows[0][0]);
        $this->assertSame(['Code', 'Fund', 'Class', 'Funder', 'Opening KES', 'Income KES', 'Expenditure KES', 'Transfers KES', 'Closing KES'], $rows[1]);
        $this->assertCount(9 + 3, $rows);
        $total = end($rows);
        $this->assertSame('Total funds carried forward', $total[1]);
        $this->assertEqualsWithDelta(array_sum($this->closings()), (float) $total[8], 0.01);
    }

    // ------------------------------------------------------------------

    /**
     * Gives the Board Designated Reserve a balance to transfer from: cash set aside
     * against it by the board, posted as the ledger's own sub-ledgers post.
     */
    private function designate(float $amount): void
    {
        $lookups = new Lookups();
        $reserve = (new FundRepository())->find('FND-110')['id'];
        $programme = $lookups->programmeId('Shared services');
        $line = static fn (string $code, float $dr, float $cr) => ['code' => $code, 'fund_id' => $reserve, 'programme_id' => $programme, 'grant_id' => null, 'desc' => 'Designation', 'dr' => $dr, 'cr' => $cr];

        (new JournalRepository())->postFromSource(
            ['date' => '2026-08-03', 'narration' => 'Board designation', 'memo' => '', 'sourceType' => 'test', 'sourceId' => 1, 'docRef' => 'BM/2026/08/01', 'series' => 'JV'],
            [$line('1110', $amount, 0), $line('3100', 0, $amount)],
            $this->user('w.kamau@elog.or.ke'), $this->user('d.kiptoo@elog.or.ke'), 'Designated by the board'
        );
        Repository::forget();
    }

    /** @return array<string, float> each fund's closing balance by code */
    private function closings(): array
    {
        $out = [];
        foreach ((new FundRepository())->all() as $f) {
            $out[$f['code']] = round(FundRepository::closing($f), 2);
        }

        return $out;
    }

    private function api(string $url): array
    {
        return json_decode($this->get($url)->getJSON(), true);
    }

    private function user(string $email): int
    {
        return (new Lookups())->userId($email);
    }

    /** The request reads cookies from the shared superglobals, which a test request does not refresh. */
    private function actAs(string $email): void
    {
        $_COOKIE['elog_actor'] = $email;
        service('superglobals')->setCookie('elog_actor', $email);
    }
}
