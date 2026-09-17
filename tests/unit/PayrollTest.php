<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\Lookups;
use App\Repositories\PayrollRepository;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * One run a month, against a register that remembers what it was.
 *
 * The three things worth holding to: PAYE is charged on pay after the statutory
 * deductions, not on gross; a run goes to a second person before anything
 * reaches the ledger; and a change to the register carries the run it takes
 * effect from, so a month already run still recomputes to what it paid.
 */
final class PayrollTest extends CIUnitTestCase
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

    public function testPayeIsChargedOnPayAfterTheStatutoryDeductionsNotOnGross(): void
    {
        $repo = new PayrollRepository();
        // The Executive Director: 430,000 basic, 130,000 house, 60,000 transport.
        $staff = $this->staff('ELG-001');
        $slip  = $repo->engine()->payslip($staff);

        $this->assertSame(620000.0, $slip['gross']);
        $this->assertSame(4320.0, $slip['nssf'], 'NSSF is 6% of the 72,000 ceiling');
        $this->assertSame(17050.0, $slip['shif'], 'SHIF is 2.75% of gross');
        $this->assertSame(9300.0, $slip['housingLevy'], 'the levy is 1.5% of gross');

        // Gross less the three statutory deductions — nothing else is allowable.
        $this->assertSame(589330.0, $slip['taxable']);
        $this->assertGreaterThan(0, $slip['paye']);
        $this->assertSame($slip['gross'] - $slip['deductions'], $slip['net']);
        $this->assertSame($slip['gross'] + $slip['employerCost'], $slip['cost']);

        // Charging the bands on gross instead would overstate PAYE by the tax on
        // the deductions, so the two must not agree.
        $this->assertNotSame($slip['paye'], $repo->engine()->payslip(['basic' => 620000] + $staff)['paye']);
    }

    public function testALeaverIsPaidForTheDaysWorkedAndIsOffTheRosterAfterwards(): void
    {
        $august = $this->api('api/payroll?period=Aug 2026&q=ELG-015');
        $this->assertSame('Daniel Mutua', $august['rows'][0]['name']);
        $this->assertStringContainsString('leaver, 15 of 31 days', $august['rows'][0]['sub']);

        // 164,000 + 48,000 + 23,000 over 15 of 31 days.
        $full = 235000;
        $this->assertSame(round($full * 15 / 31), (float) str_replace(',', '', $august['rows'][0]['gross']));

        // There is no September run yet, but the roster for it is already without them.
        $repo = new PayrollRepository();
        $september = array_column($repo->rosterFor((new Lookups())->periodByName('Sep 2026')), 'no');
        $this->assertNotContains('ELG-015', $september);
        $this->assertContains('ELG-014', $september);
    }

    public function testARunIsApprovedByASecondPersonAndOnlyThenReachesTheLedger(): void
    {
        $this->actAs('s.njeri@elog.or.ke');
        $this->assertSame('draft', $this->api('api/payroll?period=Aug 2026')['actions']['status']);

        $this->refusal('api/payroll/post', ['period' => 'Aug 2026'], 'Only an approved run can be posted');

        $this->withBodyFormat('json')->post('api/payroll/submit', ['period' => 'Aug 2026'])->assertOK();
        $index = $this->api('api/payroll?period=Aug 2026');
        $this->assertSame('pending_approval', $index['actions']['status']);
        $this->assertStringStartsWith('PR-26-', $index['actions']['reference']);

        // The preparer cannot approve their own run, and neither can a role
        // without the authority for one.
        $this->refusal('api/payroll/approve', ['period' => 'Aug 2026'], 'cannot approve it');
        $this->actAs('m.otieno@elog.or.ke');
        $this->refusal('api/payroll/approve', ['period' => 'Aug 2026'], 'Payroll runs are approved by the Executive Director');

        $journals = (int) db_connect()->table('journals')->countAllResults();
        $this->actAs('d.kiptoo@elog.or.ke');
        $this->withBodyFormat('json')->post('api/payroll/approve', ['period' => 'Aug 2026'])->assertOK();
        $this->assertSame($journals, (int) db_connect()->table('journals')->countAllResults(), 'approval alone posts nothing');

        $this->withBodyFormat('json')->post('api/payroll/post', ['period' => 'Aug 2026'])->assertOK();
        $posted = $this->api('api/payroll?period=Aug 2026');
        $this->assertTrue($posted['actions']['posted']);
        $this->assertNotNull($posted['actions']['journal']);
        $this->assertStringContainsString('Balanced', $posted['journal']['check']);

        // The run agrees to what it charged and to what it withheld.
        $run = (new PayrollRepository())->runFor((int) (new Lookups())->periodByName('Aug 2026')['id']);
        $this->assertSame('posted', $run['status']);
        $this->assertSame((float) $run['net'], $this->sum($run['journal_ref'], PayrollRepository::BANK));
        $this->assertSame(15, (int) db_connect()->table('payslips')->where('payroll_run_id', $run['id'])->countAllResults());

        // Remittance is a second journal, and it clears the liabilities.
        $this->withBodyFormat('json')->post('api/payroll/remit', ['period' => 'Aug 2026'])->assertOK();
        $remitted = $this->api('api/payroll?period=Aug 2026');
        $this->assertTrue($remitted['remit']['done']);

        // What the run raised against each statutory account is what the
        // remittance clears, so the pair leaves nothing of this run behind.
        $run = (new PayrollRepository())->runFor((int) (new Lookups())->periodByName('Aug 2026')['id']);
        foreach (['2210', '2220', '2230', '2250'] as $code) {
            $raised = $this->sum($run['journal_ref'], $code);
            $this->assertGreaterThan(0, $raised, $code . ' is raised by the run');
            $this->assertSame(0.0, round($raised + $this->sum($run['remittance_ref'], $code), 2), $code . ' is cleared by the remittance');
        }
        $this->refusal('api/payroll/remit', ['period' => 'Aug 2026'], 'already been remitted');
    }

    public function testStatutoryDeductionsCannotBeRemittedBeforeTheRunIsInTheLedger(): void
    {
        $this->actAs('d.kiptoo@elog.or.ke');
        $this->refusal('api/payroll/remit', ['period' => 'Aug 2026'], 'Post the Aug 2026 payroll first');
    }

    public function testAPayAwardAppliesFromItsRunAndLeavesEarlierRunsAsTheyWere(): void
    {
        $before = $this->grossOf('Jul 2026', 'ELG-009');

        $this->actAs('w.kamau@elog.or.ke');
        $this->withBodyFormat('json')->post('api/payroll/staff/pay', [
            'staffNo' => 'ELG-009', 'from' => 'Aug 2026', 'basic' => 150000,
            'ben' => ['house_allowance' => 44000, 'transport_allowance' => 17000],
        ])->assertOK();

        $this->assertSame($before, $this->grossOf('Jul 2026', 'ELG-009'), 'July still pays what July paid');
        $this->assertSame(211000.0, $this->grossOf('Aug 2026', 'ELG-009'));
        $this->assertStringContainsString('pay award this month', $this->row('Aug 2026', 'ELG-009')['sub']);
    }

    public function testAnAdvanceIsRecoveredOnlyFromTheRunItsRecoveryBeginsIn(): void
    {
        // Sarah Njeri deducts a 9,000 sacco every month, and a 15,000 advance
        // recovery on top of it from the June run onward.
        $this->assertSame('9,000', $this->row('May 2026', 'ELG-003')['other'], 'the sacco alone');
        $this->assertSame('24,000', $this->row('Jun 2026', 'ELG-003')['other'], 'the sacco and the recovery');
        $this->assertSame('24,000', $this->row('Aug 2026', 'ELG-003')['other']);
    }

    public function testACostAllocationThatDoesNotComeToAHundredPerCentIsRefused(): void
    {
        $this->actAs('w.kamau@elog.or.ke');
        $this->refusal('api/payroll/staff/allocation', [
            'staffNo' => 'ELG-003', 'from' => 'Aug 2026',
            'alloc' => [['grant' => 'Unassigned', 'program' => 'Shared services', 'pct' => 60]],
        ], 'must total 100% — it currently comes to 60%');

        $this->withBodyFormat('json')->post('api/payroll/staff/allocation', [
            'staffNo' => 'ELG-003', 'from' => 'Aug 2026',
            'alloc' => [
                ['grant' => 'Unassigned', 'program' => 'Shared services', 'pct' => 60],
                ['grant' => 'USAID/URAIA/2026', 'program' => 'Election Observation', 'pct' => 40],
            ],
        ])->assertOK();

        $this->assertSame('Core 60% · USAID 40%', $this->row('Aug 2026', 'ELG-003')['alloc']);
        $this->assertSame('Shared services 100%', str_replace('Core ', 'Shared services ', $this->row('Jul 2026', 'ELG-003')['alloc']));
    }

    public function testAStarterFirstAppearsInTheRunForTheMonthTheyJoin(): void
    {
        $this->actAs('w.kamau@elog.or.ke');
        $before = $this->api('api/payroll?period=Aug 2026')['total'];

        $this->refusal('api/payroll/staff', ['name' => '', 'basic' => 90000], 'needs a name and a basic salary');

        $res = $this->withBodyFormat('json')->post('api/payroll/staff', [
            'name' => 'Amina Yusuf', 'role' => 'Field Coordinator', 'grade' => 'G4', 'basic' => 120000,
            'joined' => '2026-08-01', 'bankName' => 'KCB', 'bankAccount' => '1104882037',
            'kra' => 'A020 1188 33X', 'nssfNo' => '9012 4471', 'sacco' => 5000,
            'alloc' => [['grant' => 'FORD/GA-24', 'program' => 'Governance Advocacy', 'pct' => 100]],
        ]);
        $res->assertOK();

        $this->assertSame($before + 1, $this->api('api/payroll?period=Aug 2026')['total']);
        $this->assertSame([], $this->api('api/payroll?period=Jul 2026&q=Amina')['rows']);

        // The grade awards house at a percentage of basic and transport at a flat rate.
        $row = $this->row('Aug 2026', 'Amina');
        $this->assertSame(177000.0, (float) str_replace(',', '', $row['gross']), '120,000 + 30% + 21,000');
        $this->assertStringContainsString('joined this month', $row['sub']);
    }

    public function testAPostedRunIsLockedAgainstChangesToWhoItPaid(): void
    {
        $this->actAs('s.njeri@elog.or.ke');
        $this->withBodyFormat('json')->post('api/payroll/submit', ['period' => 'Aug 2026'])->assertOK();
        $this->actAs('d.kiptoo@elog.or.ke');
        $this->withBodyFormat('json')->post('api/payroll/approve', ['period' => 'Aug 2026'])->assertOK();
        $this->withBodyFormat('json')->post('api/payroll/post', ['period' => 'Aug 2026'])->assertOK();

        $this->refusal('api/payroll/staff/pay', ['staffNo' => 'ELG-009', 'from' => 'Aug 2026', 'basic' => 150000],
            'already posted');
        $this->refusal('api/payroll/staff/leaver', ['staffNo' => 'ELG-009', 'lastDay' => '2026-08-20'],
            'already posted');
        $this->refusal('api/payroll/staff', [
            'name' => 'Amina Yusuf', 'grade' => 'G4', 'basic' => 120000, 'joined' => '2026-08-01',
            'alloc' => [['grant' => 'Unassigned', 'program' => 'Shared services', 'pct' => 100]],
        ], 'already posted');
        $this->refusal('api/payroll/submit', ['period' => 'Aug 2026'], 'is Posted, not a draft');
    }

    /**
     * A posted run is evidence, not a calculation. Whatever the register says
     * afterwards, the screen has to keep agreeing with the journal in the ledger
     * and the payslips the run wrote.
     */
    public function testAPostedRunKeepsShowingWhatItPaidNotWhatTheRegisterWouldPayNow(): void
    {
        $this->actAs('s.njeri@elog.or.ke');
        $this->withBodyFormat('json')->post('api/payroll/submit', ['period' => 'Aug 2026'])->assertOK();
        $this->actAs('d.kiptoo@elog.or.ke');
        $this->withBodyFormat('json')->post('api/payroll/approve', ['period' => 'Aug 2026'])->assertOK();
        $this->withBodyFormat('json')->post('api/payroll/post', ['period' => 'Aug 2026'])->assertOK();

        $posted = $this->api('api/payroll?period=Aug 2026');
        $paid   = $this->row('Aug 2026', 'ELG-001')['gross'];

        // Whatever happens to the register behind the screen — a correction, a
        // benefit withdrawn in Settings — the run that paid does not move.
        $db = db_connect();
        $db->table('staff_pay_items')
            ->where('staff_id', $db->table('staff')->select('id')->where('staff_no', 'ELG-001')->get()->getRow()->id)
            ->update(['amount' => 900000]);
        Repository::forget();

        $after = $this->api('api/payroll?period=Aug 2026');
        $this->assertSame($paid, $this->row('Aug 2026', 'ELG-001')['gross']);
        $this->assertSame($posted['stats'][0]['value'], $after['stats'][0]['value'], 'gross pay is what was paid');
        $this->assertSame($posted['journal']['check'], $after['journal']['check']);
        $this->assertSame($posted['remit']['total'], $after['remit']['total']);
        $this->assertSame($posted['allocRows'], $after['allocRows']);

        // The journal on screen is the one in the ledger, line for line.
        $run = (new PayrollRepository())->runFor((int) (new Lookups())->periodByName('Aug 2026')['id']);
        $this->assertSame(
            (int) $db->table('journal_lines')
                ->where('journal_id', $db->table('journals')->select('id')->where('reference', $run['journal_ref'])->get()->getRow()->id)
                ->countAllResults(),
            count($after['journal']['lines']),
        );

        // An open month, by contrast, moves with the register.
        $this->assertNotSame($paid, $this->row('Jul 2026', 'ELG-001')['gross']);
    }

    public function testThePayslipShowsWhatWasWithheldAndWhatThePostCostsOnTopOfPay(): void
    {
        $slip = $this->api('api/payroll/payslip/ELG-006?period=Aug 2026');

        $this->assertSame('Grace Muthoni', $slip['name']);
        $this->assertStringContainsString('Acting allowance', $slip['note']);
        $this->assertContains('Acting allowance', array_column($slip['earnings'], 'label'));
        $this->assertSame(['PAYE', 'NSSF', 'SHIF', 'Housing levy', 'Staff sacco'], array_column($slip['deductions'], 'label'));
        $this->assertStringContainsString('on taxable pay of', $slip['deductions'][0]['note']);
        $this->assertSame(['NSSF employer match', 'Housing levy employer', 'NITA training levy'], array_column($slip['employer'], 'label'));
        $this->assertSame('USAID/URAIA/2026', $slip['alloc'][0]['label']);
        $this->assertSame('100%', $slip['alloc'][0]['pct']);

        // Reading a payslip is a read of personal data and is logged as one.
        $this->assertGreaterThan(0, (int) db_connect()->table('personal_data_access_log')
            ->where('object_type', 'payslip')->countAllResults());
    }

    // ---- Helpers ----

    private function staff(string $no): array
    {
        $repo = new PayrollRepository();
        $roster = $repo->rosterFor($repo->periodNamed('Aug 2026'));

        return $roster[array_search($no, array_column($roster, 'no'), true)];
    }

    private function row(string $period, string $q): array
    {
        return $this->api('api/payroll?period=' . rawurlencode($period) . '&q=' . rawurlencode($q))['rows'][0];
    }

    private function grossOf(string $period, string $no): float
    {
        return (float) str_replace(',', '', $this->row($period, $no)['gross']);
    }

    private function sum(string $journalRef, string $code): float
    {
        $db = db_connect();
        $t  = static fn (string $table) => $db->prefixTable($table);

        return (float) $db->query(
            'SELECT COALESCE(SUM(l.credit - l.debit), 0) AS n FROM ' . $t('journal_lines') . ' l'
            . ' JOIN ' . $t('journals') . ' j ON j.id = l.journal_id'
            . ' JOIN ' . $t('accounts') . ' a ON a.id = l.account_id'
            . ' WHERE j.reference = ? AND a.code = ?',
            [$journalRef, $code]
        )->getRowArray()['n'];
    }

    private function refusal(string $url, array $body, string $expected): void
    {
        $res = $this->withBodyFormat('json')->post($url, $body);
        $res->assertStatus(422);
        $this->assertStringContainsString($expected, json_decode($res->getJSON(), true)['error']);
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
