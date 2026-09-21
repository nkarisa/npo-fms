<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\Lookups;
use App\Repositories\PayablesRepository;
use App\Repositories\ProcurementRepository;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * A requisition from draft to closed: coded to a budget line, approved only within
 * what is available and by someone other than the requester, ordered under the
 * three-quote rule from a pre-qualified supplier, received with the cost accrued,
 * and billed on the three-way match so the approved bill clears the accrual.
 */
final class ProcurementTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use \Tests\Support\SignsIn;
    use \Tests\Support\StoresDocuments;

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

    public function testTheViewsCarryTheV5ListsAndStats(): void
    {
        $reqs = $this->api('api/procurement');
        $this->assertSame(['Open requisitions', 'Awaiting my approval', 'Value in progress', 'Committed on POs', 'Over available budget'], array_column($reqs['stats'], 'label'));
        $this->assertSame('5', $reqs['stats'][0]['value']);
        $this->assertSame(['REQ-26-0062', 'REQ-26-0061', 'REQ-26-0060', 'REQ-26-0059', 'REQ-26-0058'], array_column($reqs['rows'], 'no'));
        $this->assertSame(['Open', 'Awaiting approval', 'Approved', 'RFQ issued', 'PO raised', 'Goods received', 'Closed', 'All'], $reqs['tabs']);

        // The laptops are coded to the capital budget line procurement's data carries.
        $laptops = (new ProcurementRepository())->find('REQ-26-0061');
        $this->assertSame([5200000, 'USAID / Uraia 2026'], [$laptops['budget'], $laptops['grant']]);
        $this->assertFalse($reqs['rows'][1]['overBudget']);

        $pos = $this->api('api/procurement?view=Purchase+orders');
        $this->assertSame(['Awaiting delivery', 'Received', 'Closed'], array_values(array_unique(array_column($pos['rows'], 'state'))));
        $this->assertSame('PO · GRN · invoice matched', array_column($pos['rows'], 'match', 'po')['PO-26-0104']);

        $this->assertSame(['GRN-0088', 'GRN-0081'], array_column($this->api('api/procurement?view=Goods+received')['rows'], 'grn'));
        $suppliers = $this->api('api/procurement?view=Suppliers')['rows'];
        $this->assertCount($this->db->table('suppliers')->countAllResults(), $suppliers);
        $this->assertSame(['Colour Print Kenya', 'Computech Ltd', 'Copy Cat Group'], array_column(array_slice($suppliers, 0, 3), 'name'));
        $this->assertSame('Lapsed', array_column($suppliers, 'status', 'name')['Computech Ltd']);
    }

    public function testARequisitionIsRaisedWithQuotationDocumentsAndApprovedWithinBudget(): void
    {
        $lookups = new Lookups();
        $repo = new ProcurementRepository();
        $achieng = $lookups->userId('j.achieng@elog.or.ke');
        $line = array_values(array_filter($repo->formOptions()['budgetLines'], static fn ($l) => $l['code'] === '5320'))[0];

        $document = tempnam(sys_get_temp_dir(), 'quote');
        file_put_contents($document, '%PDF-1.4 quotation');
        $req = $repo->create([
            'title' => 'Router replacement, 3 county offices', 'budgetLine' => $line['id'], 'needBy' => '2026-09-20', 'justification' => 'Two routers failed in August',
            'lines' => [['desc' => 'Enterprise router', 'qty' => 3, 'unit' => '45,000'], ['desc' => '', 'qty' => 1, 'unit' => 0]],
            'quotes' => [['supplier' => 'Safaricom PLC', 'amount' => 135000, 'note' => 'Installed', 'chosen' => true, 'file' => 0], ['supplier' => 'Liquid Telecom', 'amount' => 142000]],
        ], $achieng, [['path' => $document, 'name' => 'safaricom-routers.pdf', 'size' => 18, 'mime' => 'application/pdf']]);

        $this->assertSame(['REQ-26-0063', 'Draft', 135000, 'Shared services'], [$req['no'], $req['status'], $req['amount'], $req['program']]);
        $this->assertSame('safaricom-routers.pdf', $req['quotes'][0]['file']);
        $this->seeInDatabase('suppliers', ['name' => 'Liquid Telecom', 'status' => 'not_prequalified']);
        $this->get('api/procurement/REQ-26-0063/documents/' . $req['quotes'][0]['document']['id'])->assertStatus(200);

        $this->actAs('j.achieng@elog.or.ke');
        $this->post('api/procurement/REQ-26-0063/submit')->assertStatus(200);
        $this->post('api/procurement/REQ-26-0063/approve')->assertStatus(403);

        $this->actAs('w.kamau@elog.or.ke');
        $approved = $this->post('api/procurement/REQ-26-0063/approve');
        $approved->assertStatus(200);
        $this->assertSame('Approved', json_decode($approved->getJSON(), true)['requisition']['status']);

        // Asking for more than the line has left is blocked, naming the shortfall.
        $big = $repo->create(['title' => 'Satellite link', 'budgetLine' => $line['id'], 'lines' => [['desc' => 'VSAT', 'qty' => 1, 'unit' => $line['available'] + 1]]], $achieng);
        $repo->submit($big['no'], $achieng);
        try {
            $repo->approve($big['no'], $lookups->userId('w.kamau@elog.or.ke'));
            $this->fail('A requisition over the available budget was approved.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('Blocked on budget', $e->getMessage());
        }

        $this->withBodyFormat('json')->post('api/procurement/' . $big['no'] . '/reject', ['reason' => ''])->assertStatus(422);
        $rejected = $this->withBodyFormat('json')->post('api/procurement/' . $big['no'] . '/reject', ['reason' => 'Use the county office connection instead']);
        $this->assertSame('Rejected', json_decode($rejected->getJSON(), true)['requisition']['status']);
    }

    public function testOrderReceiptAndBillMoveTheBudgetAndTheLedger(): void
    {
        $lookups = new Lookups();
        $repo = new ProcurementRepository();
        $accrued = $lookups->balance('2120');
        $before = $repo->find('REQ-26-0060');

        $this->actAs('s.njeri@elog.or.ke');
        // Two quotations on a 1,340,000 requisition, neither with its document: the order
        // needs a single-source justification.
        $refused = $this->withBodyFormat('json')->post('api/procurement/REQ-26-0060/purchase-order', []);
        $refused->assertStatus(422);
        $this->assertStringContainsString('0 of the 3 quotations required with their documents attached', json_decode($refused->getJSON(), true)['error']);
        $this->withBodyFormat('json')->post('api/procurement/REQ-26-0060/purchase-order', ['supplier' => 'Computech Ltd', 'waiver' => 'Framework'])->assertStatus(422);

        $ordered = $this->withBodyFormat('json')->post('api/procurement/REQ-26-0060/purchase-order', ['waiver' => 'Framework rates agreed under tender ELOG/T/2025/04']);
        $ordered->assertStatus(200);
        $req = json_decode($ordered->getJSON(), true)['requisition'];
        $this->assertSame(['PO raised', 'Rift Valley Car Hire', 'PO-26-0115'], [$req['status'], $req['supplier'], $req['po']]);
        $this->assertEqualsWithDelta($before['committed'] + 1340000, $req['committed'], 0.001);

        $this->withBodyFormat('json')->post('api/procurement/REQ-26-0060/receive', ['receivedBy' => ''])->assertStatus(422);
        $received = json_decode($this->withBodyFormat('json')->post('api/procurement/REQ-26-0060/receive', ['receivedBy' => 'Store · A. Kariuki'])->getJSON(), true)['requisition'];
        $this->assertSame(['Goods received', 'GRN-0089', 'Store · A. Kariuki'], [$received['status'], $received['grn'], $received['receivedBy']]);
        $this->assertEqualsWithDelta($before['committed'], $received['committed'], 0.001);
        $this->assertEqualsWithDelta($before['spent'] + 1340000, $received['spent'], 0.001);
        Repository::forget();
        $this->assertEqualsWithDelta($accrued + 1340000, $lookups->balance('2120'), 0.001);

        $this->withBodyFormat('json')->post('api/procurement/REQ-26-0060/bill', ['invoiceNo' => ''])->assertStatus(422);
        $this->assertStringContainsString("Attach the supplier's invoice", json_decode($this->withBodyFormat('json')->post('api/procurement/REQ-26-0060/bill', ['invoiceNo' => 'RVCH-2026-311'])->getJSON(), true)['error']);
        $billed = json_decode($this->withBodyFormat('json')->post('api/procurement/REQ-26-0060/bill', ['invoiceNo' => 'RVCH-2026-311', 'documents' => [$this->document('s.njeri@elog.or.ke')]])->getJSON(), true);
        $this->assertSame('Closed', $billed['requisition']['status']);
        $this->assertSame(['Awaiting approval', 1340000, 67000], [$billed['bill']['status'], $billed['bill']['taxable'], $billed['bill']['wht']]);

        // Approving the bill clears the accrual; only the VAT is new cost.
        $spent = $billed['requisition']['spent'];
        (new PayablesRepository())->approve([$billed['bill']['no']], $lookups->userId('d.kiptoo@elog.or.ke'));
        Repository::forget();
        $this->assertEqualsWithDelta($accrued, $lookups->balance('2120'), 0.001);
        $this->assertEqualsWithDelta($spent + 214400, $repo->find('REQ-26-0060')['spent'], 0.001);
    }

    public function testAnRfqAndAReceivedOrderWithoutAnAccrualStillBill(): void
    {
        $this->actAs('s.njeri@elog.or.ke');
        $this->post('api/procurement/REQ-26-0061/rfq')->assertStatus(422);

        // REQ-26-0055 was received before the ledger was migrated: its bill charges the cost.
        $billed = json_decode($this->withBodyFormat('json')->post('api/procurement/REQ-26-0055/bill', ['invoiceNo' => 'SAF-INV-88120', 'documents' => [$this->document('s.njeri@elog.or.ke')]])->getJSON(), true);
        $this->assertSame('Closed', $billed['requisition']['status']);
        $bill = (new PayablesRepository())->approve([$billed['bill']['no']], (new Lookups())->userId('w.kamau@elog.or.ke'))['done'][0];
        $this->assertSame('Approved', $bill['status']);

        $show = $this->api('api/procurement/REQ-26-0061');
        $this->assertFalse($show['can']['approve']);
        $this->actAs('w.kamau@elog.or.ke');
        $this->assertTrue($this->api('api/procurement/REQ-26-0061')['can']['approve']);
        // The prototype names quotation files; no documents came with it, so none are on file.
        $this->assertSame('0 of 3 quotations have a document on file', $this->api('api/procurement/REQ-26-0061')['requisition']['quoteDocs']);
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
}
