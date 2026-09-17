<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\AdvancesRepository;
use App\Repositories\Lookups;
use App\Repositories\PayrollRepository;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * An advance from request to clearance.
 *
 * The thread worth holding: issuing makes it a receivable on 1220 and not
 * expenditure, only a surrender against receipts moves it onto the programme
 * lines, and what is never accounted for comes off the holder's pay — so the
 * register and the control account have to keep agreeing at every step.
 */
final class AdvancesTest extends CIUnitTestCase
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

    public function testAnAdvanceBecomesAReceivableOnlyWhenTheFundsAreIssued(): void
    {
        $this->actAs('m.otieno@elog.or.ke');
        $ref = $this->create(['amount' => 90000]);

        // A request is a claim on the budget, not a posting.
        $journals = $this->journalCount();
        $this->assertSame('Requested', $this->show($ref)['status']);
        $this->assertSame('—', $this->show($ref)['outstanding']);
        $this->assertSame($journals, $this->journalCount());

        $this->refusal('api/advances/' . $ref . '/issue', ['method' => 'M-Pesa'], 'Only an approved advance can be issued');

        // The requester cannot approve their own request.
        $this->refusal('api/advances/' . $ref . '/approve', [], 'cannot approve it');
        $this->actAs('w.kamau@elog.or.ke');
        $this->send('api/advances/' . $ref . '/approve')->assertOK();
        $this->assertSame($journals, $this->journalCount(), 'approval alone posts nothing');

        $control = (new AdvancesRepository())->controlBalance();
        $this->send('api/advances/' . $ref . '/issue', ['method' => 'M-Pesa'])->assertOK();

        $issued = $this->show($ref);
        $this->assertSame('Issued', $issued['status']);
        $this->assertSame('90,000', $issued['outstanding']);
        $this->assertSame('M-Pesa', $issued['method']);

        // 1220 carries it, the paybill float pays it, and nothing is expenditure.
        Repository::forget();
        $lookups = new Lookups();
        $this->assertSame(round($control + 90000, 2), round($lookups->balance('1220'), 2));
        $this->assertSame(-90000.0, round($this->movement($issued['journal'], '1130'), 2));
    }

    public function testAnAdvanceAboveTheManagersLimitNeedsTheExecutiveDirector(): void
    {
        $this->actAs('m.otieno@elog.or.ke');
        $ref = $this->create(['amount' => 260000]);

        $this->assertStringContainsString('advance limit', $this->show($ref)['limitNote']);

        $this->actAs('w.kamau@elog.or.ke');
        $this->refusal('api/advances/' . $ref . '/approve', [], "Executive Director's approval");

        $this->actAs('d.kiptoo@elog.or.ke');
        $this->send('api/advances/' . $ref . '/approve')->assertOK();
        $this->assertSame('Approved', $this->show($ref)['status']);
    }

    public function testAReceiptedSurrenderMovesTheMoneyOutOfTheControlAccount(): void
    {
        // ADV-26-0047: 254,000 issued to Samuel Njoroge and not yet accounted for.
        $before = (new AdvancesRepository())->controlBalance();

        $this->actAs('s.njeri@elog.or.ke');
        $this->refusal('api/advances/ADV-26-0047/surrender', ['receipts' => []], 'Code at least one receipt line');
        $this->refusal('api/advances/ADV-26-0047/surrender', [
            'receipts' => [['code' => '5140', 'desc' => '', 'amount' => 1000]],
        ], 'needs a description');

        $res = $this->send('api/advances/ADV-26-0047/surrender', [
            'mode' => 'refund',
            'receipts' => [
                ['code' => '5140', 'desc' => 'Fuel, Nakuru to Naivasha, three vehicles', 'amount' => 148000],
                ['code' => '5110', 'desc' => 'Field kit handling and porterage', 'amount' => 96000],
            ],
        ]);
        $res->assertOK();
        $out = json_decode($res->getJSON(), true);

        $this->assertSame(10000.0, (float) $out['balance'], 'unspent balance');
        $this->assertSame('10,000 unspent', $out['verdict']);

        $advance = $this->show('ADV-26-0047');
        $this->assertSame('Surrendered', $advance['status']);
        $this->assertSame('—', $advance['outstanding']);

        // The expenditure lands on the programme lines and 1220 comes down by the
        // whole advance — the receipts plus the cash handed back.
        Repository::forget();
        $this->assertSame(148000.0, round($this->movement($out['journal'], '5140'), 2));
        $this->assertSame(-254000.0, round($this->movement($out['journal'], '1220'), 2));
        $this->assertSame(10000.0, round($this->movement($out['journal'], '1110'), 2));
        $this->assertSame(round($before - 254000, 2), round((new AdvancesRepository())->controlBalance(), 2));
    }

    public function testAPartSurrenderLeavesTheRestOutstandingAndCanBeFinishedLater(): void
    {
        $this->actAs('s.njeri@elog.or.ke');
        $this->send('api/advances/ADV-26-0047/surrender', [
            'mode' => 'outstanding',
            'receipts' => [['code' => '5140', 'desc' => 'Fuel and tolls, first leg', 'amount' => 100000]],
        ])->assertOK();

        $part = $this->show('ADV-26-0047');
        $this->assertSame('Issued', $part['status'], 'still open against the holder');
        $this->assertSame('154,000', $part['outstanding']);

        // A second surrender picks up from what is left, not the whole advance.
        $res = $this->send('api/advances/ADV-26-0047/surrender', [
            'mode' => 'refund',
            'receipts' => [['code' => '5110', 'desc' => 'Field kit handling, remaining counties', 'amount' => 154000]],
        ]);
        $res->assertOK();

        $this->assertSame(0.0, (float) json_decode($res->getJSON(), true)['balance']);
        $this->assertSame('Surrendered', $this->show('ADV-26-0047')['status']);
        $this->assertSame('254,000', $this->show('ADV-26-0047')['facts'][5]['value'], 'accounted for in full');
    }

    public function testAnOverspendIsOwedBackToTheHolder(): void
    {
        $this->actAs('s.njeri@elog.or.ke');
        $res = $this->send('api/advances/ADV-26-0039/surrender', [
            'mode' => 'refund',
            'receipts' => [['code' => '5140', 'desc' => 'Deployment transport and lodging', 'amount' => 92000]],
        ]);
        $res->assertOK();
        $out = json_decode($res->getJSON(), true);

        // 85,000 advanced against 92,000 of receipts.
        $this->assertSame(-7000.0, (float) $out['balance']);
        $this->assertSame('7,000 overspent', $out['verdict']);
        Repository::forget();
        $this->assertSame(-7000.0, round($this->movement($out['journal'], '2110'), 2), 'owed back with the other payables');
    }

    public function testChasingAnUnsurrenderedAdvanceEscalates(): void
    {
        $this->actAs('s.njeri@elog.or.ke');

        // ADV-26-0044 is overdue and has never been chased.
        $this->assertSame([], $this->show('ADV-26-0044')['reminders']);
        $first = json_decode($this->send('api/advances/ADV-26-0044/remind')->getJSON(), true);
        $this->assertSame(1, $first['level']);
        $this->assertSame('Alice Wairimu', $first['to']);

        $second = json_decode($this->send('api/advances/ADV-26-0044/remind')->getJSON(), true);
        $this->assertSame('the programme director', $second['to']);

        $third = json_decode($this->send('api/advances/ADV-26-0044/remind')->getJSON(), true);
        $this->assertSame('the Executive Director', $third['to']);
        $this->assertCount(3, $this->show('ADV-26-0044')['reminders']);

        // Nothing outstanding, nothing to chase.
        $this->refusal('api/advances/ADV-26-0028/remind', [], 'nothing outstanding');
    }

    public function testAnUnrecoveredBalanceComesOffTheHoldersPay(): void
    {
        $this->actAs('w.kamau@elog.or.ke');

        // Recovery is a last resort, so the holder gets the grace period first.
        // ADV-26-0044 is three days past its surrender date.
        $this->refusal('api/advances/ADV-26-0044/recover', [], 'not yet 14 days past its surrender date');

        // An observer is not on the payroll, so there is no pay to take it from.
        $this->refusal('api/advances/ADV-26-0035/recover', [], 'not on the payroll register');

        // ADV-26-0031's holder has left and their final pay is settled.
        $this->refusal('api/advances/ADV-26-0031/recover', [], 'has left and final pay is already settled');

        // Alice Wairimu's 130,000 goes unanswered for another fortnight.
        $db = db_connect();
        $db->table('advances')->where('reference', 'ADV-26-0044')->update(['due_on' => '2026-08-10']);
        Repository::forget();

        $before = $this->payrollDeduction('ELG-008');
        $recovered = json_decode($this->send('api/advances/ADV-26-0044/recover')->getJSON(), true);

        $this->assertSame('Recovered', $this->show('ADV-26-0044')['status']);
        // 130,000 wants six runs, but only five are left in the year.
        $this->assertSame(6, $recovered['wanted'], 'spread so the deduction does not take the whole pay');
        $this->assertSame(5, $recovered['runs']);
        $this->assertTrue($recovered['shortened']);
        $this->assertSame('—', $this->show('ADV-26-0044')['outstanding']);

        // The deduction is now on the holder's pay, and that is what clears 1220.
        Repository::forget();
        $this->assertSame(round($before + 130000 / 5, 2), round($this->payrollDeduction('ELG-008'), 2));

        // The schedule says which run takes each instalment.
        $scheduled = $db->table('advance_recoveries')->where('status', 'scheduled')->get()->getResultArray();
        $this->assertGreaterThanOrEqual(5, count($scheduled));
    }

    public function testARejectedRequestKeepsTheReasonAndTheRejecterApart(): void
    {
        $this->actAs('m.otieno@elog.or.ke');
        $ref = $this->create(['amount' => 40000]);

        $this->actAs('w.kamau@elog.or.ke');
        $this->refusal('api/advances/' . $ref . '/reject', ['reason' => ''], 'Say why the request is refused');

        $this->send('api/advances/' . $ref . '/reject', ['reason' => 'No budget line; raise a requisition against 5150 instead'])->assertOK();
        $rejected = $this->show($ref);
        $this->assertSame('Rejected', $rejected['status']);
        $this->assertStringContainsString('raise a requisition', $rejected['rejectedNote']);

        $row = db_connect()->table('advances')->where('reference', $ref)->get()->getRowArray();
        $this->assertNotNull($row['rejected_by']);
        $this->assertNull($row['approved_by'], 'a rejecter is not an approver');
    }

    public function testTheRegisterTiesToTheControlAccountAndSaysSoWhenItDoesNot(): void
    {
        $index = $this->api('api/advances');
        $this->assertTrue($index['control']['reconciled']);
        $this->assertStringContainsString('agree to the balance on 1220', $index['control']['note']);

        // Coding expenditure straight out of 1220 without a surrender is exactly
        // what the tie exists to catch.
        $this->actAs('s.njeri@elog.or.ke');
        $this->send('api/advances/ADV-26-0039/surrender', [
            'mode' => 'outstanding',
            'receipts' => [['code' => '5140', 'desc' => 'Deployment transport', 'amount' => 40000]],
        ])->assertOK();

        $after = $this->api('api/advances');
        $this->assertTrue($after['control']['reconciled'], 'a surrender moves the register and the ledger together');
    }

    // ---- Helpers ----

    private function create(array $over = []): string
    {
        $res = $this->send('api/advances', $over + [
            'holder' => 'Grace Muthoni', 'kind' => 'Staff', 'role' => 'Observer Coordinator',
            'purpose' => 'Kajiado county observer briefing and accreditation',
            'grant' => 'USAID/URAIA/2026', 'program' => 'Election Observation',
            'amount' => 90000, 'dueDate' => '2026-09-20',
        ]);
        $res->assertOK();

        return json_decode($res->getJSON(), true)['ref'];
    }

    private function show(string $ref): array
    {
        return $this->api('api/advances/' . $ref);
    }

    private function journalCount(): int
    {
        return (int) db_connect()->table('journals')->countAllResults();
    }

    /** Debit less credit on one account in one journal: negative where it was credited. */
    private function movement(string $journalRef, string $code): float
    {
        $db = db_connect();
        $t  = static fn (string $table) => $db->prefixTable($table);

        return (float) $db->query(
            'SELECT COALESCE(SUM(l.debit - l.credit), 0) AS n FROM ' . $t('journal_lines') . ' l'
            . ' JOIN ' . $t('journals') . ' j ON j.id = l.journal_id'
            . ' JOIN ' . $t('accounts') . ' a ON a.id = l.account_id'
            . ' WHERE j.reference = ? AND a.code = ?',
            [$journalRef, $code]
        )->getRowArray()['n'];
    }

    /** What the holder's pay currently deducts for advance recovery. */
    private function payrollDeduction(string $staffNo): float
    {
        $repo   = new PayrollRepository();
        $roster = $repo->rosterFor($repo->periodNamed('Aug 2026'));
        $at     = array_search($staffNo, array_column($roster, 'no'), true);

        return $at === false ? 0.0 : (float) $roster[$at]['advance'];
    }

    private function refusal(string $url, array $body, string $expected): void
    {
        $res = $this->withBodyFormat('json')->post($url, $body);
        $res->assertStatus(422);
        $this->assertStringContainsString($expected, json_decode($res->getJSON(), true)['error']);
    }

    private function send(string $url, array $body = [])
    {
        return $this->withBodyFormat('json')->post($url, $body);
    }

    private function actAs(string $email): void
    {
        $_COOKIE['elog_actor'] = $email;
        service('superglobals')->setCookie('elog_actor', $email);
        Repository::forget();
    }

    private function api(string $url): array
    {
        Repository::forget();

        return json_decode($this->get($url)->getJSON(), true);
    }
}
