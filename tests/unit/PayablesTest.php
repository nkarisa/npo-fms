<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\Lookups;
use App\Repositories\PayablesRepository;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Supplier bills from capture to payment: nothing reaches the ledger until a second
 * person approves a bill, a payment run clears trade payables against the account
 * the money leaves from, and withholding tax is paid over to KRA once a month.
 */
final class PayablesTest extends CIUnitTestCase
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

    public function testTheBillListCarriesAgeingTabsAndStatsOverEveryBill(): void
    {
        $list = $this->api('api/payables');

        $this->assertSame(15, $list['total']);
        $this->assertCount(10, $list['rows']);
        $this->assertSame(2, $list['pages']);
        $this->assertSame(['Total outstanding', 'Overdue', 'Due within 7 days', 'Awaiting approval', 'WHT to remit'], array_column($list['stats'], 'label'));
        $this->assertSame(['Current', '1–30 days', '31–60 days', '61–90 days', 'Over 90 days'], array_column($list['aging'], 'label'));
        $this->assertSame(
            ['All' => 15, 'Awaiting approval' => 5, 'Approved' => 5, 'Scheduled' => 2, 'Paid' => 2, 'Overdue' => 5],
            array_column($list['tabs'], 'count', 'label')
        );

        // VAT at 16% is on the bill; withholding tax comes off the taxable amount.
        $bill = (new PayablesRepository())->find('BILL-0421');
        $this->assertSame([1450000, 62500, 1387500], [$bill['gross'], $bill['wht'], $bill['net']]);

        $overdue = $this->api('api/payables?status=Overdue');
        $this->assertSame(5, $overdue['filtered']);
        $this->assertTrue(array_reduce($overdue['rows'], static fn ($ok, $r) => $ok && $r['overdue'], true));

        $search = $this->api('api/payables?q=P051776220M');
        $this->assertSame(['BILL-0421'], array_column($search['rows'], 'no'));
    }

    public function testCaptureChecksTheInvoiceAndWaitsForApproval(): void
    {
        $this->actAs('s.njeri@elog.or.ke');

        $this->withBodyFormat('json')->post('api/payables', $this->invoice(['pin' => 'P05X']))->assertStatus(422);

        $created = $this->withBodyFormat('json')->post('api/payables', $this->invoice());
        $created->assertStatus(201);
        $bill = json_decode($created->getJSON(), true)['bill'];

        $this->assertSame('BILL-0453', $bill['no']);
        $this->assertSame('Awaiting approval', $bill['status']);
        $this->assertSame([200000, 32000, 232000, 10000, 222000], [$bill['taxable'], $bill['vat'], $bill['gross'], $bill['wht'], $bill['net']]);
        $this->assertSame('2026-09-29', $this->db->table('bills')->where('reference', 'BILL-0453')->get()->getRowArray()['due_date']);
        $this->assertNull($bill['journal']);
        $this->seeInDatabase('suppliers', ['name' => 'Mwangaza Consultants', 'kra_pin' => 'P051999888Q']);

        $dupe = $this->withBodyFormat('json')->post('api/payables', $this->invoice());
        $dupe->assertStatus(422);
        $this->assertStringContainsString('already on file as BILL-0453', json_decode($dupe->getJSON(), true)['error']);

        $override = $this->withBodyFormat('json')->post('api/payables', $this->invoice(['invoiceNo' => 'MC-2', 'wht' => '0']));
        $override->assertStatus(422);
        $this->assertStringContainsString('needs a reason', json_decode($override->getJSON(), true)['error']);

        // The auditor holds no preparation rights.
        $this->actAs('audit@pkfea.com');
        $this->withBodyFormat('json')->post('api/payables', $this->invoice(['invoiceNo' => 'MC-3']))->assertStatus(403);
    }

    public function testApprovalPostsTheBillAndNeedsASecondPerson(): void
    {
        $repo = new PayablesRepository();
        $lookups = new Lookups();
        $njeri = $lookups->userId('s.njeri@elog.or.ke');
        $kamau = $lookups->userId('w.kamau@elog.or.ke');
        $bill = $repo->capture($this->invoice(), $njeri);

        try {
            $repo->approve([$bill['no']], $njeri);
            $this->fail('The preparer approved their own bill.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('cannot also approve it', $e->getMessage());
        }

        $payable = $lookups->balance('2110');
        $wht = $lookups->balance('2240');

        $this->actAs('w.kamau@elog.or.ke');
        $response = $this->withBodyFormat('json')->post('api/payables/approve', ['nos' => [$bill['no'], 'BILL-0418']]);
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertSame('Approved', $result['done'][0]['status']);
        $this->assertSame('BILL-0418', $result['skipped'][0]['no']);

        $journal = $this->db->table('journals')->where('reference', $result['done'][0]['journal'])->get()->getRowArray();
        $this->assertSame(['posted', 'bill', $njeri, $kamau], [$journal['status'], $journal['source_type'], (int) $journal['prepared_by'], (int) $journal['approved_by']]);

        Repository::forget();
        $this->assertEqualsWithDelta($payable + 222000, $lookups->balance('2110'), 0.001);
        $this->assertEqualsWithDelta($wht + 10000, $lookups->balance('2240'), 0.001);
        $lines = $this->db->query('SELECT a.code, l.debit, l.credit FROM ' . $this->db->prefixTable('journal_lines') . ' l JOIN ' . $this->db->prefixTable('accounts') . ' a ON a.id = l.account_id WHERE l.journal_id = ? ORDER BY l.line_no', [$journal['id']])->getResultArray();
        $this->assertSame(['5150', '5150', '2110', '2240'], array_column($lines, 'code'));
        $this->assertEqualsWithDelta(232000, array_sum(array_column($lines, 'debit')), 0.001);

        // A bill the approver captured waits for someone else.
        $mine = $repo->capture($this->invoice(['invoiceNo' => 'MC-9']), $kamau);
        $refused = $this->withBodyFormat('json')->post('api/payables/approve', ['nos' => [$mine['no']]]);
        $refused->assertStatus(422);
        $show = $this->api('api/payables/' . $mine['no']);
        $this->assertFalse($show['can']['approve']);
        $this->assertStringContainsString('second person', $show['can']['sodNote']);
    }

    public function testARejectedBillNeedsAReason(): void
    {
        $this->actAs('w.kamau@elog.or.ke');

        $this->withBodyFormat('json')->post('api/payables/BILL-0412/reject', ['reason' => ''])->assertStatus(422);
        $response = $this->withBodyFormat('json')->post('api/payables/BILL-0412/reject', ['reason' => 'Invoice is for 52 participants; the register shows 48']);
        $response->assertStatus(200);

        $bill = json_decode($response->getJSON(), true)['bill'];
        $this->assertSame('Rejected', $bill['status']);
        $this->assertStringContainsString('the register shows 48', end($bill['trail'])['what']);
    }

    public function testSchedulingAddsApprovedBillsToTheNextRun(): void
    {
        $this->actAs('s.njeri@elog.or.ke');
        $response = $this->withBodyFormat('json')->post('api/payables/schedule', ['nos' => ['BILL-0418', 'BILL-0412']]);
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);

        $this->assertSame(['ref' => 'RUN-2026-09-05', 'date' => '05 Sep'], $result['run']);
        $this->assertSame('Scheduled', $result['done'][0]['status']);
        $this->assertSame('BILL-0412', $result['skipped'][0]['no']);

        // 0433 and 0437 were already in the run; 0418 adds 997,600.
        $run = $this->db->table('payment_runs')->where('reference', 'RUN-2026-09-05')->get()->getRowArray();
        $this->assertEqualsWithDelta(1554400 + 445440 + 997600, (float) $run['total'], 0.001);
    }

    public function testReleasingPaymentClearsPayablesAgainstEachAccount(): void
    {
        $lookups = new Lookups();
        $payable = $lookups->balance('2110');
        $kcb = $lookups->balance('1110');
        $mpesa = $lookups->balance('1130');

        $this->actAs('s.njeri@elog.or.ke');
        $this->withBodyFormat('json')->post('api/payables/pay', ['nos' => ['BILL-0433']])->assertStatus(403);

        // Payment runs are released by the Executive Director.
        $this->actAs('w.kamau@elog.or.ke');
        $this->withBodyFormat('json')->post('api/payables/pay', ['nos' => ['BILL-0433']])->assertStatus(422);

        $this->actAs('d.kiptoo@elog.or.ke');
        $this->withBodyFormat('json')->post('api/payables/pay', ['nos' => ['BILL-0412']])->assertStatus(422);

        $response = $this->withBodyFormat('json')->post('api/payables/pay', ['nos' => ['BILL-0433', 'BILL-0429']]);
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);

        $this->assertSame(['PR-26-0088', 'PR-26-0089'], array_column($result['runs'], 'ref'));
        $this->assertSame(['Paid', 'Paid'], array_column($result['done'], 'status'));

        // 0433: 1,340,000 + VAT, no WHT, from KCB. 0429: 486,000 + VAT, from M-Pesa.
        Repository::forget();
        $this->assertEqualsWithDelta($payable - 1554400 - 563760, $lookups->balance('2110'), 0.001);
        $this->assertEqualsWithDelta($kcb - 1554400, $lookups->balance('1110'), 0.001);
        $this->assertEqualsWithDelta($mpesa - 563760, $lookups->balance('1130'), 0.001);
        $this->seeInDatabase('payments', ['reference' => 'PR-26-0089', 'method' => 'mpesa']);

        // 0437 is left in the scheduled run.
        $run = $this->db->table('payment_runs')->where('reference', 'RUN-2026-09-05')->get()->getRowArray();
        $this->assertEqualsWithDelta(445440, (float) $run['total'], 0.001);
    }

    public function testWithholdingTaxIsRemittedOnceAMonth(): void
    {
        $lookups = new Lookups();
        $repo = new PayablesRepository();
        $kiptoo = $lookups->userId('d.kiptoo@elog.or.ke');

        // Approved 0421 holds 62,500; 0444 (5%) is approved now and adds its tax.
        $repo->approve(['BILL-0444'], $kiptoo);
        $held = $repo->whtHeld()['held'];
        $wht = $lookups->balance('2240');

        $this->actAs('d.kiptoo@elog.or.ke');
        $response = $this->post('api/payables/wht-remittance');
        $response->assertStatus(201);
        $remittance = json_decode($response->getJSON(), true)['remittance'];

        $this->assertSame('WHT-2026-08', $remittance['ref']);
        $this->assertEquals($held, $remittance['amount']);
        Repository::forget();
        $this->assertEqualsWithDelta($wht - $held, $lookups->balance('2240'), 0.001);
        $this->assertSame(0, $repo->whtHeld()['held']);
        $this->assertSame('WHT-2026-08', $repo->find('BILL-0421')['whtRemittance']);

        $again = $this->post('api/payables/wht-remittance');
        $again->assertStatus(422);
        $this->assertStringContainsString('already been remitted', json_decode($again->getJSON(), true)['error']);
    }

    public function testTheApprovalRulesDecideWhoApprovesAndReleases(): void
    {
        $lookups = new Lookups();
        $repo = new PayablesRepository();
        $kamau = $lookups->userId('w.kamau@elog.or.ke');
        $kiptoo = $lookups->userId('d.kiptoo@elog.or.ke');

        // Supplier bills: the Finance Manager up to 1,000,000, the Executive Director above.
        try {
            $repo->approve(['BILL-0444'], $kamau);
            $this->fail('The Finance Manager approved a bill above the threshold.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString("need the Executive Director's approval", $e->getMessage());
        }
        $this->assertSame('Approved', $repo->approve(['BILL-0444'], $kiptoo)['done'][0]['status']);

        $small = $repo->capture($this->invoice(), $lookups->userId('s.njeri@elog.or.ke'));
        $this->assertSame('Approved', $repo->approve([$small['no']], $kamau)['done'][0]['status']);

        $this->actAs('w.kamau@elog.or.ke');
        $show = $this->api('api/payables/BILL-0412');
        $this->assertFalse($show['can']['approve']);
        $this->assertStringContainsString('Executive Director', $show['can']['sodNote']);

        // Payment runs above 2,000,000 go to the Board Treasurer: the Executive Director
        // releases them only with the authority's reference.
        $this->actAs('d.kiptoo@elog.or.ke');
        $this->assertTrue($this->api('api/payables/BILL-0447')['can']['payNeedsAuthority']);
        $refused = $this->withBodyFormat('json')->post('api/payables/pay', ['nos' => ['BILL-0447']]);
        $refused->assertStatus(422);
        $this->assertTrue(json_decode($refused->getJSON(), true)['needsAuthority']);

        $paid = $this->withBodyFormat('json')->post('api/payables/pay', ['nos' => ['BILL-0447'], 'authorityRef' => 'BM/2026/09']);
        $paid->assertStatus(200);
        $run = json_decode($paid->getJSON(), true)['runs'][0];
        $this->seeInDatabase('payment_runs', ['reference' => $run['ref'], 'authority_ref' => 'BM/2026/09']);
        $this->assertStringContainsString('on authority BM/2026/09', end($repo->find('BILL-0447')['trail'])['what']);

        // Journals: the Finance Manager up to 500,000.
        $this->assertNotNull((new App\Repositories\ApprovalPolicy())->refusal('journal', 750000, $kamau, 'JV-X'));
        $this->assertNull((new App\Repositories\ApprovalPolicy())->refusal('journal', 750000, $kiptoo, 'JV-X'));
    }

    public function testThePaymentMethodChangesUntilTheBillIsPaid(): void
    {
        $this->actAs('s.njeri@elog.or.ke');

        $response = $this->withBodyFormat('json')->post('api/payables/BILL-0418/method', ['method' => 'Cheque']);
        $response->assertStatus(200);
        $this->assertSame('Cheque', json_decode($response->getJSON(), true)['bill']['method']);

        $this->withBodyFormat('json')->post('api/payables/BILL-0398/method', ['method' => 'Cheque'])->assertStatus(422);

        $form = $this->api('api/payables/form');
        $this->assertContains('M-Pesa B2B paybill', $form['methods']);
        $this->assertSame(['name' => 'Rent', 'wht' => 10], $form['categories'][3]);
        $this->assertNotEmpty($form['budgetLines']);
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

    private function invoice(array $overrides = []): array
    {
        $line = array_values(array_filter((new PayablesRepository())->budgetLines(), static fn ($l) => $l['code'] === '5150'))[0];

        return $overrides + [
            'supplier' => 'Mwangaza Consultants', 'pin' => 'P051999888Q', 'category' => 'Professional fees', 'invoiceNo' => 'MC-2026-014',
            'invoiceDate' => '2026-08-30', 'terms' => 30, 'budgetLine' => $line['id'], 'method' => 'EFT — KCB Current (KES)',
            'wht' => 'auto', 'whtReason' => '', 'overReason' => '',
            'lines' => [['desc' => 'Observer data analysis', 'amount' => '150000'], ['desc' => 'Report drafting', 'amount' => '50,000'], ['desc' => '', 'amount' => '']],
        ];
    }
}
