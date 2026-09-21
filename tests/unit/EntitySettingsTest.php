<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Libraries\SignIn;
use App\Repositories\Lookups;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Most settings are the organisation's, held on the head office. A few are each
 * entity's own, shown and changed for the entity being worked in: the bank and cash
 * accounts it pays from, its M-Pesa short code, its registered details, its approval
 * bands and its procurement threshold. An entity that has set none of its own
 * follows the head office, and can go back to following it.
 *
 * W. Kamau holds a role at every entity; the demonstration organisation's bank
 * accounts and M-Pesa short code are the head office's (ELOG-NS).
 */
final class EntitySettingsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use \Tests\Support\SignsIn;

    protected $namespace = 'App';
    protected $refresh   = true;
    protected $seed      = DatabaseSeeder::class;

    private const EVERYWHERE = 'w.kamau@elog.or.ke';

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['app.asOf'] = '2026-08-31';
        Repository::forget();
    }

    public function testAnEntityPaysFromItsOwnBankAndOtherwiseFollowsTheHeadOffice(): void
    {
        $this->workIn('ELOG-RV');
        $roles = $this->postingAccounts();
        $this->assertSame(['1110', true, false, 'ELOG National Secretariat'], [
            $roles['payrollBank']['code'], $roles['payrollBank']['entity'], $roles['payrollBank']['own'], $roles['payrollBank']['otherEntity'],
        ], 'A branch with no account of its own follows the head office, whose bank it cannot pay from');
        $this->assertFalse($roles['payables']['entity'], 'A control account is the organisation\'s');
        // Payroll says what to set rather than paying out of the head office's bank.
        $this->assertStringContainsString('Payroll bank is 1110, a bank account of ELOG National Secretariat',
            json_encode($this->api('get', 'api/payroll'), JSON_UNESCAPED_UNICODE));

        // Another entity's bank is refused; one of its own is taken.
        $this->assertStringContainsString('is a bank account of ELOG National Secretariat',
            $this->api('post', 'api/settings', ['postingAccounts' => ['payrollBank' => '1120']], 422)['error']);
        $this->branchBank('1111', 'ELOG-RV');
        $this->api('post', 'api/settings', ['postingAccounts' => ['payrollBank' => '1111']]);
        $roles = $this->postingAccounts();
        $this->assertSame(['1111', true, null], [$roles['payrollBank']['code'], $roles['payrollBank']['own'], $roles['payrollBank']['otherEntity']]);
        $this->assertContains('ELOG Rift Valley Office: Payroll bank posts to 1111 Bank — Rift Valley instead of 1110',
            array_column($this->api('get', 'api/settings')['audit'], 'what'));

        // The head office, and every other entity, still pay from 1110.
        $this->workIn('ELOG-NS');
        $this->assertSame(['1110', false], [$this->postingAccounts()['payrollBank']['code'], $this->postingAccounts()['payrollBank']['own']]);
        $this->workIn('ELOG-CST');
        $this->assertSame('1110', $this->postingAccounts()['payrollBank']['code']);

        // And the branch can go back to following the head office.
        $this->workIn('ELOG-RV');
        $this->api('post', 'api/settings', ['postingAccountsFollow' => ['payrollBank']]);
        $this->assertSame(['1110', false], [$this->postingAccounts()['payrollBank']['code'], $this->postingAccounts()['payrollBank']['own']]);
    }

    public function testEachEntityHasItsOwnMpesaShortCode(): void
    {
        $this->workIn('ELOG-RV');
        $mpesa = $this->api('get', 'api/mpesa')['mpesa'];
        $this->assertSame(['ELOG Rift Valley Office', '', ''], [$mpesa['entity'], $mpesa['shortcode'], $mpesa['account']]);
        $this->assertSame([], $this->api('get', 'api/mpesa')['options']['accounts'], 'The head office\'s M-Pesa account is not the branch\'s to settle onto');

        $this->api('post', 'api/mpesa', ['shortcode' => '600111']);
        $this->assertSame('600111', $this->api('get', 'api/mpesa')['mpesa']['shortcode']);

        $this->workIn('ELOG-NS');
        $mpesa = $this->api('get', 'api/mpesa')['mpesa'];
        $this->assertSame(['509118', '1130'], [$mpesa['shortcode'], $mpesa['account']], 'The head office\'s integration is untouched');
    }

    public function testAnEntityHoldsItsOwnRegisteredDetailsOrCarriesTheHeadOffices(): void
    {
        $this->workIn('ELOG-RV');
        $org = $this->api('get', 'api/settings')['organisation'];
        $this->assertSame(['', 'ELOG'], [$org['registeredName'], $org['headOffice']['shortName']]);

        $this->api('post', 'api/settings', ['organisation' => ['registeredName' => 'ELOG Rift Valley Trust', 'shortName' => 'ELOG RV', 'taxPin' => 'p000111222q']]);
        $org = $this->api('get', 'api/settings')['organisation'];
        $this->assertSame(['ELOG Rift Valley Trust', 'P000111222Q'], [$org['registeredName'], $org['taxPin']]);
        $this->assertSame(['registered' => 'ELOG Rift Valley Trust', 'short' => 'ELOG RV'], $this->organisationNames('ELOG-RV'));

        // Cleared, the branch carries the head office's again; the head office's cannot be cleared.
        $this->api('post', 'api/settings', ['organisation' => ['registeredName' => '', 'shortName' => '', 'taxPin' => '']]);
        $this->assertSame(['registered' => 'Elections Observation Group', 'short' => 'ELOG'], $this->organisationNames('ELOG-RV'));
        $this->workIn('ELOG-NS');
        $this->assertSame('P051290384H', $this->api('get', 'api/settings')['organisation']['taxPin']);
        $this->api('post', 'api/settings', ['organisation' => ['registeredName' => '']], 422);
    }

    public function testAnEntityMaySetItsOwnApprovalBandsAndProcurementThreshold(): void
    {
        $this->workIn('ELOG-NS');
        $head = $this->bands();

        $this->workIn('ELOG-RV');
        $settings = $this->api('get', 'api/settings');
        $this->assertFalse($settings['ownApprovals']);
        $this->assertSame($head, $this->bands());

        $this->api('post', 'api/settings', ['approvals' => ['journal' => ['threshold' => 50000]], 'procurement' => ['quoteThreshold' => 100000]]);
        $settings = $this->api('get', 'api/settings');
        $this->assertTrue($settings['ownApprovals']);
        $this->assertTrue($settings['procurement']['own']);
        $this->assertSame(50000, (int) $this->bands()['journal']);
        $this->assertSame(array_diff_key($head, ['journal' => 1]), array_diff_key($this->bands(), ['journal' => 1]), 'The other bands start from the head office\'s');
        $this->assertEquals(100000, $this->api('get', 'api/procurement')['threshold']);

        $this->workIn('ELOG-NS');
        $this->assertSame($head, $this->bands(), 'The organisation\'s bands are untouched');
        $this->assertEquals(500000, $this->api('get', 'api/procurement')['threshold']);

        $this->workIn('ELOG-RV');
        $this->api('post', 'api/settings', ['approvalsFollow' => true, 'procurement' => ['follow' => true]]);
        $this->assertSame($head, $this->bands());
        $this->assertFalse($this->api('get', 'api/settings')['ownApprovals']);
        $this->assertEquals(500000, $this->api('get', 'api/procurement')['threshold']);
    }

    public function testAnEntityBanksOnTheHeadOfficesBankLedgerBesideIt(): void
    {
        $this->workIn('ELOG-CST');
        $candidates = array_column($this->api('get', 'api/statement-formats')['candidates'], null, 'code');
        // 1110 carries the head office's KCB account; the Coast's can sit beside it, of the same kind and currency.
        $this->assertSame(['bank', 'KES', ['ELOG National Secretariat']], [$candidates['1110']['kind'], $candidates['1110']['currency'], $candidates['1110']['sharedWith']]);
        $this->assertSame('USD', $candidates['1120']['currency']);
        // 1210 Grants receivable is the head office's receivable, not a cash ledger.
        $this->assertArrayNotHasKey('1210', $candidates);
        $this->assertStringContainsString('1210 Grants receivable carries postings of ELOG National Secretariat and no cash account',
            $this->api('post', 'api/statement-formats/account', ['code' => '1210', 'name' => 'Ecobank'], 422)['error']);
        $this->assertStringContainsString('hold one currency: open this one in USD',
            $this->api('post', 'api/statement-formats/account', ['code' => '1120', 'name' => 'Ecobank USD', 'currency' => 'KES'], 422)['error']);
        $this->assertStringContainsString('are of one kind',
            $this->api('post', 'api/statement-formats/account', ['code' => '1110', 'name' => 'Ecobank', 'kind' => 'petty_cash'], 422)['error']);

        // Payroll follows the head office onto 1110, which the Coast banks on nowhere yet.
        $this->assertStringContainsString('holds no cash account on',
            json_encode($this->api('get', 'api/payroll'), JSON_UNESCAPED_UNICODE));

        $this->api('post', 'api/statement-formats/account', ['code' => '1110', 'name' => 'Ecobank Mombasa', 'bankName' => 'Ecobank', 'accountNumber' => '0020011']);
        $accounts = array_column($this->api('get', 'api/statement-formats')['accounts'], null, 'code');
        $this->assertSame(['Ecobank Mombasa'], array_column($accounts, 'name'), 'The Coast sees its own cash account, not the head office\'s');
        $this->assertSame([], $accounts['1110']['uses'], 'The head office\'s lines on 1110 are its own bank\'s, not the Coast\'s');
        $this->assertArrayNotHasKey('1110', array_column($this->api('get', 'api/statement-formats')['candidates'], null, 'code'));
        $this->assertStringContainsString('An entity holds one cash account on a ledger account',
            $this->api('post', 'api/statement-formats/account', ['code' => '1110', 'name' => 'Ecobank 2'], 422)['error']);
        $this->assertStringNotContainsString('holds no cash account on',
            json_encode($this->api('get', 'api/payroll'), JSON_UNESCAPED_UNICODE));

        // The head office's is untouched, and the Coast's can still be corrected.
        $this->api('post', 'api/statement-formats/account/1110', ['accountNumber' => '0020012'] + $accounts['1110']);
        $this->workIn('ELOG-NS');
        $head = array_column($this->api('get', 'api/statement-formats')['accounts'], null, 'code')['1110'];
        $this->assertSame(['Bank — KCB Current (KES)', true], [$head['name'], $head['uses'] !== []]);
    }

    public function testACashAccountIsInUseOnlyByItsOwnEntitysPostings(): void
    {
        // One opened on a receivable before that was checked: the head office's lines
        // are not the branch account's, so it is still unused, and can be moved.
        $this->workIn('ELOG-CST');
        $db = db_connect();
        $template = $db->table('bank_accounts')->get()->getRowArray();
        unset($template['id']);
        $db->table('bank_accounts')->insert(['entity_id' => $this->entityId('ELOG-CST'), 'name' => 'Ecobank', 'short_name' => 'Ecobank',
            'account_id' => $db->table('accounts')->where('code', '1210')->get()->getRowArray()['id']] + $template);
        Repository::forget();

        $ecobank = array_column($this->api('get', 'api/statement-formats')['accounts'], null, 'code')['1210'];
        $this->assertSame([], $ecobank['uses']);
        $this->assertStringContainsString('1220 Staff and observer advances carries postings of ELOG National Secretariat',
            $this->api('post', 'api/statement-formats/account/1210', ['code' => '1220'] + $ecobank, 422)['error']);
        $this->api('post', 'api/statement-formats/account/1210', ['code' => '1110'] + $ecobank);
        $this->assertArrayHasKey('1110', array_column($this->api('get', 'api/statement-formats')['accounts'], null, 'code'));
    }

    // ------------------------------------------------------------------

    /** Signs in as the person who works everywhere, in the entity named. */
    private function workIn(string $code): void
    {
        $this->signIn(self::EVERYWHERE);
        $this->withSession(SignIn::values((new Lookups())->userId(self::EVERYWHERE)) + [\App\Libraries\EntityScope::SESSION => $this->entityId($code)]);
        Repository::forget();
    }

    private function entityId(string $code): int
    {
        return (int) db_connect()->table('entities')->where('code', $code)->get()->getRowArray()['id'];
    }

    private function postingAccounts(): array
    {
        return array_column($this->api('get', 'api/settings')['postingAccounts'], null, 'role');
    }

    /** @return array<string, string> approval band => threshold */
    private function bands(): array
    {
        return array_column($this->api('get', 'api/settings')['approvals'], 'threshold', 'key');
    }

    /** The names a document of the entity carries. */
    private function organisationNames(string $code): array
    {
        $_SESSION = SignIn::values((new Lookups())->userId(self::EVERYWHERE)) + [\App\Libraries\EntityScope::SESSION => $this->entityId($code)];
        Repository::forget();
        try {
            return (new Lookups())->organisationNames();
        } finally {
            $_SESSION = [];
            Repository::forget();
        }
    }

    /** A bank account of the entity's own, under a code of its own in the shared chart. */
    private function branchBank(string $code, string $entity): void
    {
        $db = db_connect();
        $bank = $db->table('accounts')->where('code', '1110')->get()->getRowArray();
        unset($bank['id']);
        $accountId = $db->table('accounts')->insert(['code' => $code, 'name' => 'Bank — Rift Valley'] + $bank) ? (int) $db->insertID() : 0;
        $template = $db->table('bank_accounts')->where('account_id', $db->table('accounts')->where('code', '1110')->get()->getRowArray()['id'])->get()->getRowArray();
        unset($template['id']);
        $db->table('bank_accounts')->insert(['entity_id' => $this->entityId($entity), 'account_id' => $accountId, 'name' => 'Bank — Rift Valley',
            'account_number' => '1100000001', 'statement_format_id' => null] + $template);
        Repository::forget();
    }

    private function api(string $method, string $url, array $body = [], int $status = 200): array
    {
        $result = $method === 'get' ? $this->get($url) : $this->withBodyFormat('json')->post($url, $body);
        $json = json_decode((string) $result->getJSON(), true) ?? [];
        $this->assertSame($status, $result->response()->getStatusCode(), $method . ' ' . $url . ': ' . ($json['error'] ?? ''));

        return $json;
    }
}
