<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\BankRepository;
use App\Repositories\JournalRepository;
use App\Repositories\Lookups;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Bank reconciliation: a statement is agreed to the cash book line by line, the
 * bank's own entries are journalised and cleared once approved, and only a
 * reconciliation with every line explained and no difference can be signed off.
 */
final class BankReconciliationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use \Tests\Support\SignsIn;

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

    public function testAugustStartsWithTheStatementUnmatchedAgainstTheCashBook(): void
    {
        $view = $this->api('api/bank-rec?account=1110');

        $this->assertSame('Aug 2026', $view['period']);
        $this->assertSame(['Aug 2026', 'Jul 2026', 'Jun 2026'], $view['periodOptions']);
        $this->assertSame(['1110', '1120', '1130'], array_column($view['accountOptions'], 'code'));
        $this->assertSame('KCB current a/c 1104578921 · statement 08/2026 received 27 Aug', $view['statementRef']);
        $this->assertSame('11 statement lines to explain', $view['kicker']);
        $this->assertSame(
            ['18,047,500', '19,645,900', '14,160,000', '11', '8,641,600'],
            array_column($view['stats'], 'value')
        );
        $this->assertCount(11, $view['statement']['rows']);
        // The ten vouchers of the prototype's cash book, and the transfer in from Equity USD.
        $this->assertSame(
            ['RC-26-0301', 'PV-26-0455', 'PV-26-0448', 'JV-26-0291', 'PV-26-0461', 'JV-26-0288', 'PV-26-0470', 'PV-26-0475', 'PV-26-0479', 'PV-26-0481', 'PV-26-0483'],
            array_column($view['book']['rows'], 'ref')
        );
        $this->assertSame(
            ['CHG 0818' => 'Post bank charges', 'INT 0822' => 'Post interest received', 'CR 771990' => 'Post to suspense'],
            array_column(array_filter($view['statement']['rows'], static fn ($r) => $r['journalLabel'] !== ''), 'journalLabel', 'ref')
        );
        $this->assertFalse($view['can']['complete']);
        $this->assertSame('Cannot complete · 8,641,600', $view['completeLabel']);

        // The M-Pesa float names its charges as the settlement report does.
        $mpesa = $this->api('api/bank-rec?account=1130');
        $this->assertSame(['Post transaction charges'], array_values(array_filter(array_column($mpesa['statement']['rows'], 'journalLabel'))));
    }

    public function testTheCashBookVouchersLeaveTheChartAndTheRegisterAsTheyWere(): void
    {
        $lookups = new Lookups();

        $this->assertEquals(18420500, $lookups->balance('1110'));
        $this->assertEquals(41985300, $lookups->balance('1120'));
        $this->assertEquals(862400, $lookups->balance('1130'));
        $this->assertEquals(4260900, $lookups->balance('2110'));
        $this->assertNull((new JournalRepository())->find('PV-26-0455'));
    }

    public function testEarlierMonthsAreSignedOffAndLocked(): void
    {
        $july = $this->api('api/bank-rec?account=1110&period=' . rawurlencode('Jul 2026'));

        $this->assertTrue($july['signedOff']);
        $this->assertSame('Reconciled and signed off', $july['kicker']);
        $this->assertSame('0', $july['stats'][4]['value']);
        $this->assertNotEmpty($july['statement']['rows']);
        $this->assertSame([], array_filter($july['statement']['rows'], static fn ($r) => !$r['matched']));
        // July's closing balance is August's opening.
        $this->assertSame('9,405,900', $july['stats'][0]['value']);
        $this->assertFalse($july['can']['reopen']);

        $this->expectException(RuleViolation::class);
        (new BankRepository())->reopen('1110', 'Jul 2026', $this->user('W. Kamau'));
    }

    public function testAMatchMustAgreeExactlyAndCanBeUndone(): void
    {
        $view = $this->api('api/bank-rec?account=1110');
        $stmt = array_column($view['statement']['rows'], 'id', 'ref');
        $book = array_column($view['book']['rows'], 'id', 'ref');

        $refused = $this->withBodyFormat('json')->post('api/bank-rec/match', [
            'account' => '1110', 'period' => 'Aug 2026', 'statement' => [$stmt['EFT 884120']], 'book' => [$book['PV-26-0448']],
        ]);
        $refused->assertStatus(422);
        $this->assertStringContainsString('differ by (854,000)', $refused->getJSON());

        $matched = json_decode($this->withBodyFormat('json')->post('api/bank-rec/match', [
            'account' => '1110', 'period' => 'Aug 2026', 'statement' => [$stmt['EFT 884120']], 'book' => [$book['PV-26-0455']],
        ])->getJSON(), true);
        $this->assertSame('1 statement line matched to 1 posting.', $matched['message']);
        $row = current(array_filter($matched['statement']['rows'], static fn ($r) => $r['ref'] === 'EFT 884120'));
        $this->assertSame('PV-26-0455', $row['matchRef']);

        $this->withBodyFormat('json')->post('api/bank-rec/match', [
            'account' => '1110', 'period' => 'Aug 2026', 'statement' => [$stmt['EFT 884120']], 'book' => [$book['PV-26-0455']],
        ])->assertStatus(422);

        $undone = json_decode($this->withBodyFormat('json')->post('api/bank-rec/unmatch', ['account' => '1110', 'period' => 'Aug 2026', 'line' => $stmt['EFT 884120']])->getJSON(), true);
        $this->assertSame('11', $undone['stats'][3]['value']);
    }

    public function testTheBanksOwnEntriesAreJournalisedThenTheReconciliationIsSignedOff(): void
    {
        $auto = json_decode($this->withBodyFormat('json')->post('api/bank-rec/auto-match', ['account' => '1110', 'period' => 'Aug 2026'])->getJSON(), true);
        $this->assertStringStartsWith('8 lines matched on exact amount', $auto['message']);
        $this->assertSame('271,600', $auto['stats'][4]['value']);
        // What the bank has not cleared: two payments out, the transfer in.
        $this->assertSame(['JV-26-0291', 'PV-26-0479', 'PV-26-0483'], array_column(array_filter($auto['book']['rows'], static fn ($r) => !$r['matched']), 'ref'));
        $this->assertSame('4,130,000', $auto['recon'][1]['value']);
        $this->assertSame('(6,000,000)', $auto['recon'][2]['value']);

        $this->withBodyFormat('json')->post('api/bank-rec/complete', ['account' => '1110', 'period' => 'Aug 2026'])->assertStatus(422);

        // An accountant raises the three entries; the Finance Manager approves them.
        $this->actAs('j.achieng@elog.or.ke');
        $refs = [];
        foreach ($auto['statement']['rows'] as $row) {
            if ($row['journalLabel'] === '') {
                continue;
            }
            $raised = json_decode($this->withBodyFormat('json')->post('api/bank-rec/journalise', ['account' => '1110', 'period' => 'Aug 2026', 'line' => $row['id']])->getJSON(), true);
            $this->assertStringContainsString('sent for approval', $raised['message']);
            $pending = current(array_filter($raised['statement']['rows'], static fn ($r) => $r['id'] === $row['id']));
            $this->assertSame('', $pending['journalLabel']);
            $refs[] = $pending['pendingRef'];
        }
        $this->withBodyFormat('json')->post('api/bank-rec/journalise', ['account' => '1110', 'period' => 'Aug 2026', 'line' => $row['id']])->assertStatus(422);

        $charges = (new JournalRepository())->find($refs[0]);
        $this->assertSame('Pending approval', $charges['status']);
        $this->assertSame([['5340', 14800, 0], ['1110', 0, 14800]], array_map(static fn ($l) => [$l['code'], $l['dr'], $l['cr']], $charges['lines']));
        $this->assertSame('bankline:' . current(array_filter($auto['statement']['rows'], static fn ($r) => $r['ref'] === 'CHG 0818'))['id'], $charges['docLink']);

        $this->actAs('w.kamau@elog.or.ke');
        foreach ($refs as $ref) {
            $this->post('api/journals/' . $ref . '/approve')->assertStatus(200);
        }

        $agreed = $this->api('api/bank-rec?account=1110');
        $this->assertSame('Agreed · awaiting sign-off', $agreed['kicker']);
        $this->assertSame('0', $agreed['stats'][4]['value']);
        $this->assertSame('3 journals raised from this statement', substr($agreed['book']['footer'], strlen('Postings against 1110 · ')));
        $this->assertTrue($agreed['can']['complete']);

        $done = json_decode($this->withBodyFormat('json')->post('api/bank-rec/complete', ['account' => '1110', 'period' => 'Aug 2026'])->getJSON(), true);
        $this->assertTrue($done['signedOff']);
        $this->assertSame('Reconciled ✓', $done['completeLabel']);
        $this->assertSame('completed', db_connect()->table('reconciliations')->where('id', (new BankRepository())->reconciliation('1110', 'Aug 2026')['id'])->get()->getRowArray()['status']);

        // Signed off, nothing moves until it is reopened.
        $this->withBodyFormat('json')->post('api/bank-rec/unmatch', ['account' => '1110', 'period' => 'Aug 2026', 'line' => $row['id']])->assertStatus(422);
        $reopened = json_decode($this->withBodyFormat('json')->post('api/bank-rec/reopen', ['account' => '1110', 'period' => 'Aug 2026'])->getJSON(), true);
        $this->assertFalse($reopened['signedOff']);
        $this->assertSame(['1110', '1120', '1130'], array_column((new BankRepository())->unreconciled(), 'code'));
    }

    public function testRolesDecideWhoMatchesRaisesAndSignsOff(): void
    {
        $this->actAs('audit@pkfea.com');
        $this->withBodyFormat('json')->post('api/bank-rec/auto-match', ['account' => '1130', 'period' => 'Aug 2026'])->assertStatus(403);

        $this->actAs('j.achieng@elog.or.ke');
        $this->withBodyFormat('json')->post('api/bank-rec/complete', ['account' => '1130', 'period' => 'Aug 2026'])->assertStatus(403);

        // The person who prepared a reconciliation does not sign it off.
        $this->expectExceptionMessage('cannot also sign it off');
        $repo = new BankRepository();
        $repo->autoMatch('1130', 'Aug 2026', $this->user('W. Kamau'));
        $rec = $repo->reconciliation('1130', 'Aug 2026');
        $line = current(array_filter($rec['statement'], static fn ($l) => $l['entry'] !== null));
        $ref = $repo->journalise('1130', 'Aug 2026', $line['id'], $this->user('J. Achieng'))['ref'];
        (new JournalRepository())->approve($ref, $this->user('W. Kamau'));
        $repo->complete('1130', 'Aug 2026', $this->user('M. Otieno'));
    }

    private function api(string $url): array
    {
        return json_decode($this->get($url)->getJSON(), true);
    }

    private function user(string $name): int
    {
        return (new Lookups())->userId($name);
    }

    /** The request reads cookies from the shared superglobals, which a test request does not refresh. */
    private function actAs(string $email): void
    {
        $_COOKIE['elog_actor'] = $email;
        service('superglobals')->setCookie('elog_actor', $email);
    }
}
