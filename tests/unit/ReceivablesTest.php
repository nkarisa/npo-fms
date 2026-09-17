<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\Lookups;
use App\Repositories\ReceivablesRepository;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Donor claims from draft to receipt: a claim is built from expenditure already in
 * the ledger, reaches the ledger only when a second person issues it, is cleared
 * by receipts in full or in part, and an overdue claim the donor will not pay is
 * written off with its reason on record.
 */
final class ReceivablesTest extends CIUnitTestCase
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

    public function testTheInvoiceListCarriesAgeingTabsAndStats(): void
    {
        $list = $this->api('api/receivables');

        $this->assertSame(9, $list['total']);
        $this->assertSame(['Total receivable', 'Overdue', 'Due within 30 days', 'Received this month', 'Unbilled entitlement'], array_column($list['stats'], 'label'));
        $this->assertSame(['All outstanding', 'Current', '1–30 days', '31–60 days', '61–90 days', 'Over 90 days'], array_column($list['aging'], 'label'));
        $this->assertSame(
            ['All' => 9, 'Draft' => 2, 'Issued' => 4, 'Part received' => 1, 'Overdue' => 3, 'Received' => 1, 'Written off' => 1],
            array_column($list['tabs'], 'count', 'label')
        );
        $this->assertGreaterThan(0, (new ReceivablesRepository())->unbilled());

        $overdue = $this->api('api/receivables?status=Overdue');
        $this->assertSame(['Overdue'], array_values(array_unique(array_column($overdue['rows'], 'status'))));

        $this->assertSame(['INV-26-0041'], array_column($this->api('api/receivables?q=uraia/2026')['rows'], 'no'));
    }

    public function testAClaimCannotExceedTheExpenditureBehindIt(): void
    {
        $this->actAs('m.otieno@elog.or.ke');
        $award = array_values(array_filter($this->api('api/receivables/form')['awards'], static fn ($a) => $a['ref'] === 'USAID/URAIA/2026'))[0];
        $line = array_values(array_filter($award['lines'], static fn ($l) => $l['actual'] > 100000))[0];

        $over = $this->withBodyFormat('json')->post('api/receivables', $this->claim($award['ref'], [$line['code'] => $line['actual'] + 1]));
        $over->assertStatus(422);
        $this->assertStringContainsString('above actual expenditure', json_decode($over->getJSON(), true)['error']);

        $amount = min($line['actual'], $award['unclaimed'] / 2, 1000000);
        $created = $this->withBodyFormat('json')->post('api/receivables', $this->claim($award['ref'], [$line['code'] => $amount]));
        $created->assertStatus(201);
        $invoice = json_decode($created->getJSON(), true)['invoice'];

        $recovery = round($amount * $award['indirect']['pct'] / 100);
        $this->assertSame('INV-26-0046', $invoice['no']);
        $this->assertSame('Draft', $invoice['status']);
        $this->assertEquals($amount + $recovery, $invoice['amount']);
        $this->assertCount(2, $invoice['lines']);
        $this->assertSame('USD', $invoice['ccy']);
        $this->assertNull($invoice['journal']);

        // Other income states its payer, basis, income account and amount.
        $other = $this->withBodyFormat('json')->post('api/receivables', [
            'award' => '—', 'type' => 'Other income', 'period' => 'Sep 2026', 'ccy' => 'KES', 'fx' => 1, 'basis' => 'Register verification support',
            'payer' => 'County Government of Nakuru', 'account' => '4220', 'amount' => '450,000', 'claims' => [],
        ]);
        $other->assertStatus(201);
        $this->assertSame(['General Fund', '4220'], [json_decode($other->getJSON(), true)['invoice']['fund'], json_decode($other->getJSON(), true)['invoice']['lines'][0]['code']]);
    }

    public function testIssuingPostsTheClaimAndNeedsASecondPerson(): void
    {
        $lookups = new Lookups();
        $repo = new ReceivablesRepository();
        $receivable = $lookups->balance('1210');
        $income = $lookups->balance('4110');

        // M. Otieno built INV-26-0044.
        try {
            $repo->issue(['INV-26-0044'], $lookups->userId('m.otieno@elog.or.ke'));
            $this->fail('The preparer issued their own claim.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('cannot also issue it', $e->getMessage());
        }

        $this->actAs('j.achieng@elog.or.ke');
        $this->withBodyFormat('json')->post('api/receivables/issue', ['nos' => ['INV-26-0044']])->assertStatus(403);

        $this->actAs('w.kamau@elog.or.ke');
        $response = $this->withBodyFormat('json')->post('api/receivables/issue', ['nos' => ['INV-26-0044', 'INV-26-0041']]);
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $invoice = $result['done'][0];

        $this->assertSame(['Issued', '31 Aug 2026'], [$invoice['status'], $invoice['issue']]);
        $this->assertSame('INV-26-0041', $result['skipped'][0]['no']);
        $this->assertNotNull($invoice['journal']);
        Repository::forget();
        $this->assertEqualsWithDelta($receivable + 9250000, $lookups->balance('1210'), 0.001);
        $this->assertEqualsWithDelta($income + 9250000, $lookups->balance('4110'), 0.001);
    }

    public function testReceiptsClearTheReceivableInPartOrInFull(): void
    {
        $lookups = new Lookups();
        $receivable = $lookups->balance('1210');
        $usd = $lookups->balance('1120');

        $this->actAs('s.njeri@elog.or.ke');
        $this->withBodyFormat('json')->post('api/receivables/INV-26-0044/receipt', ['amount' => 1000, 'account' => '1110'])->assertStatus(422);
        $this->withBodyFormat('json')->post('api/receivables/INV-26-0041/receipt', ['amount' => 30000000, 'account' => '1120'])->assertStatus(422);

        $part = $this->withBodyFormat('json')->post('api/receivables/INV-26-0041/receipt', ['amount' => '10,000,000', 'account' => '1120', 'ref' => 'EQB/USD/7781']);
        $part->assertStatus(200);
        $invoice = json_decode($part->getJSON(), true)['invoice'];
        $this->assertSame(['Part received', 18468000], [$invoice['status'], $invoice['outstanding']]);
        $this->assertSame('EQB/USD/7781', $invoice['receipts'][0]['ref']);

        $full = $this->withBodyFormat('json')->post('api/receivables/receive', ['nos' => ['INV-26-0041', 'INV-26-0044']]);
        $full->assertStatus(200);
        $result = json_decode($full->getJSON(), true);
        $this->assertSame('Received', $result['done'][0]['status']);
        $this->assertSame('INV-26-0044', $result['skipped'][0]['no']);
        $this->assertStringStartsWith('RV-26-', $result['done'][0]['receipts'][1]['ref']);

        Repository::forget();
        $this->assertEqualsWithDelta($receivable - 28468000, $lookups->balance('1210'), 0.001);
        $this->assertEqualsWithDelta($usd + 28468000, $lookups->balance('1120'), 0.001);
    }

    public function testRemindersAndWriteOffsGoOnTheTrail(): void
    {
        $lookups = new Lookups();
        $this->actAs('s.njeri@elog.or.ke');
        $reminded = json_decode($this->withBodyFormat('json')->post('api/receivables/remind', ['nos' => ['INV-26-0035']])->getJSON(), true);
        $this->assertStringStartsWith('Second reminder sent to County Government of Kisumu', end($reminded['done'][0]['trail'])['what']);

        $this->withBodyFormat('json')->post('api/receivables/INV-26-0031/write-off', ['reason' => 'Uncollectable'])->assertStatus(403);

        $this->actAs('w.kamau@elog.or.ke');
        $this->withBodyFormat('json')->post('api/receivables/INV-26-0031/write-off', ['reason' => ''])->assertStatus(422);
        $this->withBodyFormat('json')->post('api/receivables/INV-26-0041/write-off', ['reason' => 'Not due'])->assertStatus(422);

        $receivable = $lookups->balance('1210');
        $response = $this->withBodyFormat('json')->post('api/receivables/INV-26-0031/write-off', ['reason' => 'KAS suspended the award after finding Q-2026-04']);
        $response->assertStatus(200);
        $invoice = json_decode($response->getJSON(), true)['invoice'];

        $this->assertSame('Written off', $invoice['status']);
        $this->assertStringContainsString('finding Q-2026-04', end($invoice['trail'])['what']);
        Repository::forget();
        $this->assertEqualsWithDelta($receivable - 4800000, $lookups->balance('1210'), 0.001);
        $this->assertEqualsWithDelta(4800000, $lookups->balance('5370'), 0.001);

        $statement = $this->get('api/receivables/statement');
        $statement->assertStatus(200);
        $csv = $statement->getBody();
        $this->assertStringContainsString('Aged receivables statement as at 31 Aug 2026', $csv);
        $this->assertStringContainsString('INV-26-0035', $csv);
        $this->assertStringNotContainsString('INV-26-0031', $csv);
    }

    /** The request reads cookies from the shared superglobals, which a test request does not refresh. */
    private function actAs(string $email): void
    {
        $_COOKIE['elog_actor'] = $email;
        service('superglobals')->setCookie('elog_actor', $email);
    }

    private function api(string $url): array
    {
        return json_decode($this->get($url)->getJSON(), true);
    }

    private function claim(string $award, array $claims): array
    {
        return ['award' => $award, 'type' => 'Grant claim', 'period' => 'Jul – Sep 2026', 'ccy' => 'USD', 'fx' => '129.40', 'indirect' => true, 'basis' => '', 'claims' => $claims];
    }
}
