<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Libraries\Secret;
use App\Repositories\Lookups;
use App\Repositories\MpesaRepository;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Settings → Integrations → M-Pesa: the short code is configured but connected
 * only once everything a service needs is in place, credentials are held
 * encrypted and never served, and every change is in the settings audit log.
 */
final class MpesaTest extends CIUnitTestCase
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
        // The credentials are encrypted at rest; an installation without a key is
        // refused when one is entered, which testCredentialsAreHeldEncrypted covers.
        config('Encryption')->key = 'hex2bin:0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a69788796a5b4c3d2e1f0';
        Repository::forget();
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['elog_actor']);
        service('superglobals')->unsetCookie('elog_actor');
        parent::tearDown();
    }

    public function testTheOrganisationsPaybillIsConfiguredButNotConnected(): void
    {
        $this->assertContains('Integrations', array_column($this->api('api/settings')['sections'], 'key'));

        $d = $this->api('api/mpesa');
        $m = $d['mpesa'];

        $this->assertTrue($d['canManage']);
        $this->assertSame(['509118', 'paybill', 'ELOG', 'sandbox', '1130'], [$m['shortcode'], $m['shortcodeKind'], $m['accountReference'], $m['environment'], $m['account']]);
        $this->assertSame('M-Pesa organisation portal (CSV)', $m['statementFormat']);
        $this->assertSame('150000.00', $m['ceiling']);
        $this->assertFalse($m['collections']);
        $this->assertFalse($m['disbursements']);
        $this->assertSame('off', $m['status']['state']);
        $this->assertSame('Not connected', $m['status']['label']);

        // Nothing is set, so both services say what they are short of.
        $this->assertSame([false, false, false, false], array_column($m['credentials'], 'set'));
        $this->assertSame('the callback address, the consumer key, the consumer secret and the passkey', $m['outstanding']['collections']);
        $this->assertSame('the callback address, the consumer key, the consumer secret, the security credential and the initiator', $m['outstanding']['disbursements']);
        $this->assertSame(['', '', '', ''], array_column($m['callbacks'], 'url'));
        $this->assertSame(['1130 · M-Pesa paybill float'], array_column($d['options']['accounts'], 'text'));
    }

    public function testAServiceIsSwitchedOnOnlyOnceEverythingItNeedsIsSet(): void
    {
        // Enabling before the credentials are in is refused, with what is missing.
        $refused = $this->json($this->withBodyFormat('json')->post('api/mpesa', ['collections' => true]), 422);
        $this->assertSame('Collections cannot be switched on until the callback address, the consumer key, the consumer secret and the passkey are set.', $refused['error']);

        $saved = $this->save([
            'callbackBase'   => 'https://finance.elog.or.ke/',
            'consumerKey'    => 'dAr4j4C0nsum3rK3y1234',
            'consumerSecret' => 'sh6RetV4lue9f2a',
            'passkey'        => 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919',
            'collections'    => true,
        ]);

        $this->assertSame('M-Pesa settings saved. All 5 changes are in the audit log.', $saved['message']);
        $this->assertSame([
            'M-Pesa callback address set to https://finance.elog.or.ke',
            'M-Pesa consumer key set',
            'M-Pesa consumer secret set',
            'M-Pesa passkey set',
            'M-Pesa collections turned on — payments to the short code are received into the ledger',
        ], array_column($saved['changes'], 'what'));

        $m = $saved['mpesa'];
        $this->assertTrue($m['collections']);
        $this->assertSame('sandbox', $m['status']['state']);
        $this->assertSame('https://finance.elog.or.ke/api/mpesa/confirmation', $m['callbacks']['confirmation']['url']);
        $this->assertSame('', $m['outstanding']['collections']);

        // Payments need more than collections do, and say so.
        $this->assertSame('the security credential and the initiator', $m['outstanding']['disbursements']);
        $refused = $this->json($this->withBodyFormat('json')->post('api/mpesa', ['disbursements' => true]), 422);
        $this->assertSame('M-Pesa payments cannot be switched on until the security credential and the initiator are set.', $refused['error']);

        $live = $this->save([
            'initiatorName'      => 'elog_api',
            'securityCredential' => 'Kj8s+encrypted+initiator+password==',
            'disbursements'      => true,
            'environment'        => 'production',
        ]);
        $this->assertSame([
            'M-Pesa environment moved from Sandbox to Production — requests now move real money',
            'M-Pesa payments initiated as elog_api',
            'M-Pesa security credential set',
            'M-Pesa payments turned on — bills and advances can be paid by M-Pesa',
        ], array_column($live['changes'], 'what'));
        $this->assertSame('live', $live['mpesa']['status']['state']);
        $this->assertSame('Paybill 509118 · Collections and payments settle to 1130.', $live['mpesa']['status']['note']);

        // The change reads on the settings audit log, under Integrations.
        $audit = $this->api('api/settings')['audit'];
        $this->assertSame('Integrations', $audit[0]['area']);
        $this->assertSame('W. Kamau', $audit[0]['who']);

        // Saving what is already held changes nothing.
        $this->assertSame('No changes to save.', $this->save(['shortcode' => '509118', 'environment' => 'production'])['message']);
    }

    public function testCredentialsAreHeldEncryptedAndNeverServed(): void
    {
        $secret = 'sh6RetV4lue9f2a';
        $this->save(['consumerKey' => 'dAr4j4C0nsum3rK3y1234', 'consumerSecret' => $secret]);

        $stored = db_connect()->table('mpesa_integrations')->get()->getRowArray();
        $this->assertNotSame($secret, $stored['consumer_secret']);
        $this->assertStringNotContainsString($secret, (string) $stored['consumer_secret']);
        $this->assertSame($secret, Secret::open($stored['consumer_secret']));

        // The screen learns which are set and their last four characters, never more.
        $body = (string) $this->get('api/mpesa')->getJSON();
        $this->assertStringNotContainsString($secret, $body);
        $credentials = array_column(json_decode($body, true)['mpesa']['credentials'], null, 'key');
        $this->assertSame([true, '•••• 9f2a'], [$credentials['consumerSecret']['set'], $credentials['consumerSecret']['hint']]);
        $this->assertSame([false, ''], [$credentials['passkey']['set'], $credentials['passkey']['hint']]);

        // The audit log records that it changed, not what it changed to.
        $this->assertSame(0, db_connect()->table('audit_events')->like('summary', $secret)->countAllResults());

        // Only the server reads them back, to sign a Daraja request with.
        $this->assertSame($secret, (new MpesaRepository())->credentials()['consumerSecret']);

        // Clearing one is a change of its own; replacing one is recorded as replaced.
        $changes = array_column($this->save(['consumerSecret' => 'aN0th3rS3cret', 'clear' => ['consumerKey']])['changes'], 'what');
        $this->assertSame(['M-Pesa consumer key cleared', 'M-Pesa consumer secret replaced'], $changes);
    }

    public function testSettingsSafaricomWouldRejectAreRefusedWithNothingApplied(): void
    {
        $mpesa = new MpesaRepository();
        $kamau = (new Lookups())->userId('W. Kamau');
        $refused = function (array $in, string $reason) use ($mpesa, $kamau) {
            try {
                $mpesa->save($in, $kamau);
                $this->fail('Expected a refusal: ' . $reason);
            } catch (RuleViolation $e) {
                $this->assertStringContainsString($reason, $e->getMessage());
            }
            Repository::forget();
        };

        $refused(['shortcode' => '12'], 'is not a Safaricom short code');
        $refused(['shortcode' => '5091180000'], 'five to seven digits');
        $refused(['shortcodeKind' => 'till', 'accountReference' => 'ELOG'], 'Buy Goods till takes no account number');
        $refused(['accountReference' => 'ELOG/2026; DROP'], 'typed on a phone keypad');
        $refused(['account' => '1110'], 'M-Pesa settles onto a mobile-money account, and 1110 is not one');
        $refused(['ceiling' => '400000'], 'will not pass a single M-Pesa payment above 250,000');
        $refused(['callbackBase' => 'finance.elog.or.ke'], 'the https address Safaricom posts results to');
        $refused(['environment' => 'production', 'callbackBase' => 'http://finance.elog.or.ke'], 'posts production results over https only');
        $refused(['environment' => 'production', 'callbackBase' => 'https://localhost:8075'], 'cannot be reached from Safaricom\'s network');
        $refused(['environment' => 'staging'], 'sandbox or production');
        $refused(['initiatorName' => 'elog api user'], 'letters, digits and . _ - only');

        // Nothing from a refused save reaches the row or the audit log.
        $this->assertSame(['509118', 'paybill', 'sandbox'], [$mpesa->integration()['shortcode'], $mpesa->integration()['shortcodeKind'], $mpesa->integration()['environment']]);
        $this->assertSame(0, db_connect()->table('audit_events')->where('object_type', 'settings:integrations')->countAllResults());
    }

    public function testWhatIsAlreadyCommittedToMPesaCannotBeSwitchedOffUnderIt(): void
    {
        $this->connect();

        // A bill already scheduled to be paid by M-Pesa is a promise made.
        $refused = $this->json($this->withBodyFormat('json')->post('api/mpesa', ['disbursements' => false]), 422);
        $this->assertSame('M-Pesa payments cannot be switched off while 1 bill is scheduled to be paid by M-Pesa. Pay or reschedule them to another method first.', $refused['error']);
        $this->assertTrue($this->api('api/mpesa')['mpesa']['disbursements']);

        // The settlement account and short code cannot be pulled out from under a live service either.
        $this->assertStringContainsString('cannot be cleared while M-Pesa is collecting or paying', $this->json($this->withBodyFormat('json')->post('api/mpesa', ['account' => '']), 422)['error']);
        $this->assertStringContainsString('cannot be cleared while collections are switched on', $this->json($this->withBodyFormat('json')->post('api/mpesa', ['shortcode' => '']), 422)['error']);

        // Nor can a credential a running service signs with be taken away under it.
        $this->assertSame(
            'Collections are switched on and cannot run without the passkey. Set it again, or switch collections off first.',
            $this->json($this->withBodyFormat('json')->post('api/mpesa', ['clear' => ['passkey']]), 422)['error']
        );

        db_connect()->table('bills')->where('payment_method', 'mpesa')->update(['payment_method' => 'eft']);
        Repository::forget();
        $off = $this->save(['disbursements' => false]);
        $this->assertSame('M-Pesa payments turned off — M-Pesa is no longer offered as a payment method', $off['changes'][0]['what']);
    }

    public function testACheckRecordsWhatSafaricomAnswered(): void
    {
        // With no credentials held nothing is sent, and the answer is recorded as it stands.
        $checked = $this->json($this->withBodyFormat('json')->post('api/mpesa/check'));

        $this->assertFalse($checked['ok']);
        $this->assertSame('The consumer key and secret are not both set.', $checked['message']);
        $this->assertSame('The consumer key and secret are not both set.', $checked['mpesa']['checked']['result']);
        $this->assertSame('M-Pesa connection checked against Sandbox — The consumer key and secret are not both set.', $this->api('api/settings')['audit'][0]['what']);
    }

    public function testOnlyTheFinanceManagerChangesTheIntegration(): void
    {
        $this->actAs('d.kiptoo@elog.or.ke');

        $looking = $this->api('api/mpesa');
        $this->assertFalse($looking['canManage']);
        $this->assertSame('509118', $looking['mpesa']['shortcode']);

        $this->withBodyFormat('json')->post('api/mpesa', ['shortcode' => '4123456'])->assertStatus(403);
        $this->withBodyFormat('json')->post('api/mpesa/check')->assertStatus(403);
        $this->assertSame('509118', $this->api('api/mpesa')['mpesa']['shortcode']);
    }

    // ------------------------------------------------------------------

    /** Everything set and both services on, as a connected organisation stands. */
    private function connect(): void
    {
        $this->save([
            'callbackBase'       => 'https://finance.elog.or.ke',
            'consumerKey'        => 'dAr4j4C0nsum3rK3y1234',
            'consumerSecret'     => 'sh6RetV4lue9f2a',
            'passkey'            => 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919',
            'initiatorName'      => 'elog_api',
            'securityCredential' => 'Kj8s+encrypted+initiator+password==',
            'collections'        => true,
            'disbursements'      => true,
        ]);
    }

    private function save(array $body): array
    {
        return $this->json($this->withBodyFormat('json')->post('api/mpesa', $body));
    }

    private function api(string $url): array
    {
        return json_decode($this->get($url)->getJSON(), true);
    }

    private function json($response, int $status = 200): array
    {
        $response->assertStatus($status);

        return json_decode($response->getJSON(), true);
    }

    private function actAs(string $email): void
    {
        $_COOKIE['elog_actor'] = $email;
        service('superglobals')->setCookie('elog_actor', $email);
    }
}
