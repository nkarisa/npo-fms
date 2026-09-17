<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Libraries\StatementCsv;
use App\Repositories\BankRepository;
use App\Repositories\Lookups;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use App\Repositories\StatementImportRepository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Bank statements loaded from CSV: each bank's format maps its columns onto the
 * reconciliation's statement lines, and an upload adds only what is new, once
 * the balances agree.
 */
final class StatementImportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = true;
    protected $seed      = DatabaseSeeder::class;

    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['app.asOf'] = '2026-08-31';
        Repository::forget();
    }

    protected function tearDown(): void
    {
        foreach (db_connect()->table('attachments')->where('object_type', 'bank_statement_import')->get()->getResultArray() as $a) {
            @unlink(WRITEPATH . 'uploads/' . $a['storage_key']);
        }
        foreach ($this->temp as $path) {
            @unlink($path);
        }
        unset($_COOKIE['elog_actor']);
        service('superglobals')->unsetCookie('elog_actor');
        parent::tearDown();
    }

    public function testTheReaderMapsEachAmountLayoutOntoSignedLines(): void
    {
        $signed = "Date;Narrative;Amount\n03.08.2026;Grant receipt;1.250.000,00\n04.08.2026;Supplier;(48.500,50)\n05.08.2026;Fee;120,00-\n;Total;1.201.379,50\n";
        $read = StatementCsv::read($signed, [
            'delimiter' => 'semicolon', 'dateColumn' => 'Date', 'dateFormat' => 'dd.mm.yyyy', 'descriptionColumns' => ['Narrative'],
            'amountLayout' => 'signed', 'amountColumn' => 'Amount', 'decimalMark' => ',', 'rules' => [['match' => 'fee', 'entry' => 'charges']],
        ]);
        $this->assertNull($read['error']);
        $this->assertSame([1250000.0, -48500.5, -120.0], array_column($read['rows'], 'amount'));
        $this->assertSame(['2026-08-03', '2026-08-04', '2026-08-05'], array_column($read['rows'], 'date'));
        $this->assertSame([null, null, 'charges'], array_column($read['rows'], 'entry'));

        $indicator = "Statement of account\nValue date,Details,Amt,D/C\n14-Aug-26,Transfer in,\"2,000,000.00\",CR\n15-Aug-26,Cheque 1102,\"45,000.00\",DR\n16-Aug-26,Bad,abc,DR\n";
        $read = StatementCsv::read($indicator, [
            'dateColumn' => 'Value date', 'dateFormat' => 'dd-MMM-yy', 'descriptionColumns' => ['Details'],
            'amountLayout' => 'indicator', 'amountColumn' => 'Amt', 'indicatorColumn' => 'D/C', 'creditIndicator' => 'CR',
        ]);
        $this->assertSame(2, $read['headerLine']);
        $this->assertSame([2000000.0, -45000.0, null], array_column($read['rows'], 'amount'));
        $this->assertSame(['the amount "abc" is not a number'], $read['rows'][2]['errors']);

        $missing = StatementCsv::read($indicator, ['dateColumn' => 'Posting date', 'dateFormat' => 'dd-MMM-yy', 'descriptionColumns' => ['Details'], 'amountLayout' => 'signed', 'amountColumn' => 'Amt']);
        $this->assertStringContainsString('"posting date"', mb_strtolower($missing['error']));
        $this->assertSame(['Value date', 'Details', 'Amt', 'D/C'], $missing['headers']);

        // M-Pesa lists the latest transaction first; the balances are checked either way.
        $rows = [
            ['line' => 2, 'date' => '2026-08-25', 'amount' => -8600.0, 'balance' => 651400.0, 'skip' => null, 'errors' => []],
            ['line' => 3, 'date' => '2026-08-21', 'amount' => -420000.0, 'balance' => 660000.0, 'skip' => null, 'errors' => []],
            ['line' => 4, 'date' => '2026-08-17', 'amount' => -1540000.0, 'balance' => 1080000.0, 'skip' => null, 'errors' => []],
        ];
        $this->assertSame(['newestFirst' => true, 'breaks' => []], StatementCsv::balanceBreaks($rows));
        $rows[1]['balance'] = 661000.0;
        $this->assertTrue(StatementCsv::balanceBreaks($rows)['newestFirst']);
        $this->assertNotSame([], StatementCsv::balanceBreaks($rows)['breaks']);
    }

    public function testFormatsAreListedAndOnlyAnApproverChangesThem(): void
    {
        $list = $this->api('api/statement-formats');
        $this->assertSame(['M-Pesa organisation portal (CSV)', 'Equity Bank online banking (CSV)', 'KCB internet banking (CSV)'], array_column($list['formats'], 'name'));
        $this->assertTrue($list['formats'][0]['builtin']);
        $this->assertSame(['1110' => 'KCB internet banking (CSV)', '1120' => 'Equity Bank online banking (CSV)', '1130' => 'M-Pesa organisation portal (CSV)'], array_column($list['accounts'], 'format', 'code'));
        $this->assertTrue($list['canManage']);

        $coop = [
            'name' => 'Co-operative Bank (CSV)', 'delimiter' => 'comma', 'dateColumn' => 'Date', 'dateFormat' => 'dd/mm/yyyy',
            'referenceColumn' => 'Ref', 'descriptionColumns' => ['Narration'], 'amountLayout' => 'signed', 'amountColumn' => 'Amount',
            'rules' => [['match' => 'COMMISSION', 'entry' => 'charges']],
        ];

        $this->actAs('j.achieng@elog.or.ke');
        $this->withBodyFormat('json')->post('api/statement-formats', $coop)->assertStatus(403);

        $this->actAs('w.kamau@elog.or.ke');
        $this->withBodyFormat('json')->post('api/statement-formats', ['amountColumn' => ''] + $coop)->assertStatus(422);
        $saved = $this->json($this->withBodyFormat('json')->post('api/statement-formats', $coop));
        $this->assertSame('Statement format Co-operative Bank (CSV) saved.', $saved['message']);
        $this->assertSame('Signed amount "Amount" · dates dd/mm/yyyy', $saved['format']['summary']);
        $this->withBodyFormat('json')->post('api/statement-formats', $coop)->assertStatus(422);

        $mpesa = $list['formats'][0];
        $refused = $this->withBodyFormat('json')->post('api/statement-formats/' . $mpesa['id'], ['name' => 'Mine'] + $mpesa);
        $refused->assertStatus(422);
        $this->assertStringContainsString('built in', $refused->getJSON());

        $kcb = $list['formats'][2];
        $this->post('api/statement-formats/' . $kcb['id'] . '/delete')->assertStatus(422);
        $assigned = $this->json($this->withBodyFormat('json')->post('api/statement-formats/assign', ['account' => '1110', 'format' => $saved['format']['id']]));
        $this->assertSame('KCB Current statements will be read as Co-operative Bank (CSV).', $assigned['message']);
        $this->post('api/statement-formats/' . $kcb['id'] . '/delete')->assertStatus(200);
        $this->withBodyFormat('json')->post('api/statement-formats/assign', ['account' => '1140', 'format' => $saved['format']['id']])->assertStatus(422);
    }

    public function testAnUploadAddsOnlyNewLinesOnceTheBalancesAgree(): void
    {
        $repo = new StatementImportRepository();
        $file = $this->file('kcb-aug-2026.csv', $this->kcbCsv([['28/08/2026', 'CHG 0828', 'Ledger fee — August', '2,500.00', '']]));
        $achieng = (new Lookups())->userId('J. Achieng');

        $wrong = $repo->run('1110', 'Aug 2026', $file, ['closing' => '18,047,500'], $achieng, false);
        $this->assertFalse($wrong['ok']);
        $this->assertSame(['new' => 1, 'duplicate' => 11, 'outside' => 1, 'skipped' => 0, 'error' => 0], array_diff_key($wrong['summary'], ['read' => 0]));
        $this->assertStringContainsString('out by 2,500', end($wrong['checks'])['label']);

        try {
            $repo->run('1110', 'Aug 2026', $file, ['closing' => '18,047,500'], $achieng, true);
            $this->fail('A statement that does not agree to its closing balance must not load.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('out by 2,500', $e->getMessage());
        }

        $preview = $repo->run('1110', 'Aug 2026', $file, ['closing' => '18,045,000'], $achieng, false);
        $this->assertTrue($preview['ok'], json_encode($preview['checks']));
        $new = current(array_filter($preview['rows'], static fn ($r) => $r['status'] === 'new'));
        $this->assertSame(['CHG 0828', '(2,500)', 'charges', 'Post bank charges'], [$new['ref'], $new['amount'], $new['entry'], $new['entryLabel']]);

        $repo->run('1110', 'Aug 2026', $file, ['closing' => '18,045,000'], $achieng, true);
        Repository::forget();

        $rec = (new BankRepository())->reconciliation('1110', 'Aug 2026');
        $this->assertCount(12, $rec['statement']);
        $this->assertSame('CHG 0828', end($rec['statement'])['ref']);
        $this->assertSame('charges', end($rec['statement'])['entry']);
        $this->assertEquals(18045000, $rec['statementClose']);
        $this->assertEquals(18045000, $rec['printedClose']);
        $this->assertSame(['file' => 'kcb-aug-2026.csv', 'by' => 'J. Achieng', 'on' => '31 Aug 2026', 'uploads' => 1], $rec['lastUpload']);
        $this->assertSame(1, db_connect()->table('attachments')->where('object_type', 'bank_statement_import')->countAllResults());

        $view = $this->api('api/bank-rec?account=1110');
        $this->assertSame('Loaded from kcb-aug-2026.csv by J. Achieng on 31 Aug 2026', $view['uploadNote']);

        $this->expectExceptionMessage('Nothing new');
        $repo->run('1110', 'Aug 2026', $this->file('kcb-aug-2026.csv', $this->kcbCsv([['28/08/2026', 'CHG 0828', 'Ledger fee — August', '2,500.00', '']])), ['closing' => '18,045,000'], $achieng, true);
    }

    public function testAnMpesaStatementForANewMonthOpensWhereAugustClosed(): void
    {
        $repo = new StatementImportRepository();
        $options = $repo->options();
        $mpesa = current(array_filter($options['accounts'], static fn ($a) => $a['code'] === '1130'));
        $this->assertSame('M-Pesa organisation portal (CSV)', $mpesa['format']);
        $this->assertEquals(651400, $mpesa['statements']['Sep 2026']['opening']);
        $this->assertTrue($mpesa['statements']['Sep 2026']['new']);
        $this->assertSame(0, $mpesa['statements']['Aug 2026']['lines'] - 4);

        // Latest first, as the portal exports it, with a failed transaction that is passed over.
        $csv = "Organisation Name,ELOG\nPaybill,556677\n\n"
            . "Receipt No.,Completion Time,Initiation Time,Details,Transaction Status,Paid In,Withdrawn,Balance,Balance Confirmed,Reason Type,Other Party Info,Linked Transaction ID,A/C No.\n"
            . "SJ3K9QX1,2026-09-04 16:02:11,2026-09-04 16:02:10,Business Pay Bill Charge,Completed,,-60.00,\"1,131,340.00\",true,Business Pay Bill Charge,,,\n"
            . "SJ3K9QX0,2026-09-04 16:02:10,2026-09-04 16:02:10,Observer stipend — batch MP/0322,Completed,,\"-120,000.00\",\"1,131,400.00\",true,Business Payment to Customer,,,\n"
            . "SJ2A1BB9,2026-09-03 09:15:00,2026-09-03 09:15:00,Observer stipend — batch MP/0321,Failed,,\"-50,000.00\",\"1,251,400.00\",true,Business Payment to Customer,,,\n"
            . "SJ2A1BB8,2026-09-02 11:00:00,2026-09-02 11:00:00,Funds received from KCB current,Completed,\"600,000.00\",,\"1,251,400.00\",true,Organization Settlement,,,\n";
        $achieng = (new Lookups())->userId('J. Achieng');

        $preview = $repo->run('1130', 'Sep 2026', $this->file('ORG_556677_Statement.csv', $csv), ['closing' => '1,131,340'], $achieng, false);
        $this->assertTrue($preview['ok'], json_encode($preview['checks']));
        $this->assertSame(['new' => 3, 'skipped' => 1], array_intersect_key($preview['summary'], ['new' => 0, 'skipped' => 0]));
        $this->assertContains('The first line follows from the opening balance of 651,400', array_column($preview['checks'], 'label'));
        $this->assertSame(['charges', null, null, null], array_column($preview['rows'], 'entry'));

        $broken = str_replace('"1,131,400.00"', '"1,131,500.00"', $csv);
        $refused = $repo->run('1130', 'Sep 2026', $this->file('ORG_556677_Statement.csv', $broken), ['closing' => '1,131,340'], $achieng, false);
        $this->assertFalse($refused['ok']);
        $this->assertContains('The running balance does not follow from the amount on line 5, 6', array_column($refused['checks'], 'label'));

        $repo->run('1130', 'Sep 2026', $this->file('ORG_556677_Statement.csv', $csv), ['closing' => '1,131,340', 'entries' => ['5' => '']], $achieng, true);
        Repository::forget();

        $bank = new BankRepository();
        $this->assertSame(['Sep 2026', 'Aug 2026', 'Jul 2026', 'Jun 2026'], current(array_filter($bank->accounts(), static fn ($a) => $a['code'] === '1130'))['periods']);
        $rec = $bank->reconciliation('1130', 'Sep 2026');
        $this->assertSame(['SJ2A1BB8', 'SJ3K9QX0', 'SJ3K9QX1'], array_column($rec['statement'], 'ref'));
        // The person cleared the kind the rule suggested for the charge (file line 5).
        $this->assertSame([null, null, null], array_column($rec['statement'], 'entry'));
        $this->assertEquals(651400, $rec['opening']);
        $this->assertEquals(1131340, $rec['statementClose']);
        $this->assertSame('in_progress', $rec['status']);
        $this->assertEquals($achieng, db_connect()->table('reconciliations')->where('id', $rec['id'])->get()->getRowArray()['prepared_by']);
    }

    public function testLockedMonthsAndNonPreparersCannotLoadStatements(): void
    {
        $repo = new StatementImportRepository();
        $achieng = (new Lookups())->userId('J. Achieng');

        try {
            $repo->run('1110', 'Jul 2026', $this->file('kcb.csv', $this->kcbCsv([])), ['closing' => '1'], $achieng, false);
            $this->fail('July is closed.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('Jul 2026 is closed', $e->getMessage());
        }

        try {
            $repo->run('1140', 'Aug 2026', $this->file('petty.csv', "a\n"), ['closing' => '1'], $achieng, false);
            $this->fail('Petty cash has no bank statement.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('does not take bank statements', $e->getMessage());
        }

        $this->actAs('audit@pkfea.com');
        $this->post('api/bank-rec/import')->assertStatus(403);
        $this->assertFalse($this->api('api/bank-rec/import')['canUpload']);
    }

    // ------------------------------------------------------------------

    /** The KCB current account's August statement as internet banking downloads it, with extra rows appended. */
    private function kcbCsv(array $extra): string
    {
        $data = json_decode(file_get_contents(APPPATH . 'Data/OLD/BR_ACCOUNTS.json'), true);
        $kcb = current(array_filter($data, static fn ($a) => $a['code'] === '1110'));
        $balance = (float) $kcb['opening'];
        $money = static fn (float $n) => number_format($n, 2);

        $out = "KCB Bank Kenya,Statement of account\nAccount,1104578921\n\n"
            . "Transaction Date,Value Date,Transaction Details,Reference,Money Out,Money In,Ledger Balance\n"
            . "01/08/2026,01/08/2026,Balance brought forward,,,,\"" . $money($balance) . "\"\n";
        $rows = array_map(static fn ($l) => [date('d/m/Y', strtotime($l['date'] . ' 2026')), $l['ref'], $l['desc'], $l['amt'] < 0 ? $money(-$l['amt']) : '', $l['amt'] > 0 ? $money($l['amt']) : ''], $kcb['stmt']);
        foreach (array_merge($rows, $extra, [['01/09/2026', 'CHG 0901', 'Ledger fee — September', '2,500.00', '']]) as [$date, $ref, $desc, $out_, $in]) {
            $balance += StatementCsv::number($in !== '' ? $in : '0') - StatementCsv::number($out_ !== '' ? $out_ : '0');
            $out .= implode(',', [$date, $date, '"' . $desc . '"', $ref, $out_ === '' ? '' : '"' . $out_ . '"', $in === '' ? '' : '"' . $in . '"', '"' . $money($balance) . '"']) . "\n";
        }

        return $out . ",,Total,,,,\n";
    }

    private function file(string $name, string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'stmt');
        file_put_contents($path, $contents);
        $this->temp[] = $path;

        return ['path' => $path, 'name' => $name, 'size' => strlen($contents), 'mime' => 'text/csv'];
    }

    private function api(string $url): array
    {
        return json_decode($this->get($url)->getJSON(), true);
    }

    private function json($response): array
    {
        $response->assertStatus(200);

        return json_decode($response->getJSON(), true);
    }

    private function actAs(string $email): void
    {
        $_COOKIE['elog_actor'] = $email;
        service('superglobals')->setCookie('elog_actor', $email);
    }
}
