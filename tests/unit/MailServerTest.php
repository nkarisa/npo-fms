<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Libraries\Secret;
use App\Repositories\MailRepository;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Email;

/**
 * Settings → Integrations → Email: production's mail server is set on the screen,
 * its password held encrypted and never served; outside production an SMTP host
 * in .env (Mailpit) wins, so a copy of a production database never sends real mail.
 */
final class MailServerTest extends CIUnitTestCase
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
        config('Encryption')->key = 'hex2bin:0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a69788796a5b4c3d2e1f0';
        config(Email::class)->SMTPHost = '';
        config(Email::class)->protocol = 'mail';
        Repository::forget();
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['elog_actor']);
        service('superglobals')->unsetCookie('elog_actor');
        parent::tearDown();
    }

    public function testWithNothingSetMailGoesAsEnvSays(): void
    {
        $d = $this->api('api/mail');

        $this->assertTrue($d['canManage']);
        $this->assertSame(['', '587', 'tls'], [$d['mail']['host'], $d['mail']['port'], $d['mail']['crypto']]);
        $this->assertFalse($d['mail']['password']['set']);
        $this->assertSame('env', $d['mail']['inUse']['source']);
    }

    public function testTheServerSetHereCarriesTheMailAndItsPasswordIsNeverServed(): void
    {
        $saved = $this->save([
            'host' => 'SMTP.Office365.com', 'port' => '587', 'crypto' => 'tls', 'username' => 'no-reply@elog.or.ke',
            'password' => 'app-password-7f3a', 'fromEmail' => 'no-reply@elog.or.ke', 'fromName' => 'ELOG Finance',
        ]);

        $this->assertSame('smtp.office365.com', $saved['mail']['host']);
        $this->assertSame('settings', $saved['mail']['inUse']['source']);
        $this->assertSame(['set' => true, 'readable' => true, 'hint' => '•••• 7f3a'], $saved['mail']['password']);
        $this->assertStringNotContainsString('app-password-7f3a', json_encode($saved));

        $held = db_connect()->table('settings')->where('key', 'mailPassword')->get()->getRowArray();
        $this->assertNotSame('app-password-7f3a', $held['value']);
        $this->assertSame('app-password-7f3a', Secret::open($held['value']));

        $t = (new MailRepository())->transport();
        $this->assertSame(['settings', 'smtp.office365.com', 587, 'app-password-7f3a'], [$t['source'], $t['config']['SMTPHost'], $t['config']['SMTPPort'], $t['config']['SMTPPass']]);

        $logged = db_connect()->table('audit_events')->where('object_type', 'settings:integrations')->get()->getResultArray();
        $this->assertContains('SMTP server set to smtp.office365.com', array_column($logged, 'summary'));
        $this->assertContains('Mail server password set', array_column($logged, 'summary'));
        $this->assertStringNotContainsString('app-password-7f3a', json_encode($logged));

        // The test message goes to the person asking, through that server.
        $test = $this->json($this->withBodyFormat('json')->post('api/mail/test'));
        $this->assertTrue($test['ok']);
        $this->assertSame([$this->me()], service('email')->archive['recipients']);
    }

    public function testOutsideProductionAnSmtpHostInEnvWinsOverSettings(): void
    {
        $this->save(['host' => 'smtp.office365.com', 'fromEmail' => 'no-reply@elog.or.ke']);
        config(Email::class)->SMTPHost = 'localhost';
        config(Email::class)->SMTPPort = 1025;
        config(Email::class)->protocol = 'smtp';
        Repository::forget();

        $t = (new MailRepository())->transport();
        $this->assertSame(['env', 'localhost', 1025], [$t['source'], $t['config']['SMTPHost'], $t['config']['SMTPPort']]);
        $this->assertStringContainsString('localhost:1025', $this->api('api/mail')['mail']['inUse']['note']);
    }

    public function testWhatAProviderWouldRefuseIsRefusedWithNothingApplied(): void
    {
        $this->refused(['host' => 'smtp://mail.elog.or.ke:587'], 'is not a server name');
        $this->refused(['host' => 'mail.elog.or.ke', 'port' => '99999', 'fromEmail' => 'a@elog.or.ke'], 'port is a number');
        $this->refused(['host' => 'mail.elog.or.ke'], 'address messages come from');
        $this->refused(['fromName' => "ELOG\r\nBcc: x@evil.test"], 'one line');
        $this->assertSame('', $this->api('api/mail')['mail']['host']);
    }

    public function testOnlyTheFinanceManagerChangesTheMailServer(): void
    {
        $this->actAs('d.kiptoo@elog.or.ke');

        $this->assertFalse($this->api('api/mail')['canManage']);
        $this->withBodyFormat('json')->post('api/mail', ['host' => 'smtp.evil.test'])->assertStatus(403);
        $this->withBodyFormat('json')->post('api/mail/test')->assertStatus(403);
    }

    // ------------------------------------------------------------------

    private function refused(array $body, string $says): void
    {
        $this->assertStringContainsString($says, $this->json($this->withBodyFormat('json')->post('api/mail', $body), 422)['error']);
    }

    private function me(): string
    {
        return (string) db_connect()->table('users')->where('id', (new \App\Repositories\Lookups())->settingsManagerId())->get()->getRow()->email;
    }

    private function save(array $body): array
    {
        return $this->json($this->withBodyFormat('json')->post('api/mail', $body));
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
