<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Database\Seeds\OrganisationSeeder;
use App\Libraries\SignIn;
use App\Libraries\Totp;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Auth;

/**
 * Signing in: nothing opens without it, a password and a second step (an
 * authenticator app or an emailed code) get in, recovery codes get in once each,
 * and invitations and resets are single-use links.
 *
 * Each request carries on the session the last one left, as a browser would.
 */
final class AuthTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh   = true;
    protected $seed      = DatabaseSeeder::class;

    private const FM = 'w.kamau@elog.or.ke';
    private const ACCOUNTANT = 'j.achieng@elog.or.ke';

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['app.asOf'] = '2026-08-31';
        Repository::forget();
        // One throttler serves the whole run; each test starts with its own count.
        \Config\Services::resetSingle('throttler');
        $this->withHeaders(['X-Requested-With' => 'phpunit']);
        $this->withSession([]);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['elog_actor']);
        service('superglobals')->unsetCookie('elog_actor');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Nothing without a sign-in
    // ------------------------------------------------------------------

    public function testNothingOpensWithoutASignIn(): void
    {
        $api = $this->get('api/journals');
        $api->assertStatus(401);
        $this->assertSame('/login', json_decode($api->getJSON(), true)['signIn']);

        $page = $this->get('journals');
        $page->assertStatus(302);
        $this->assertSame('/login?next=%2Fjournals', $page->response()->getHeaderLine('Location'));

        $login = $this->get('login');
        $login->assertStatus(200);
        $login->assertSee('assets/js/auth.js');
        $this->assertSame('signedOut', $this->send('get', 'api/auth')['stage']);
    }

    public function testAPasswordAloneIsNotASignInWhenASecondStepIsNeeded(): void
    {
        $in = $this->send('post', 'api/auth/login', ['email' => self::FM, 'password' => OrganisationSeeder::DEMO_PASSWORD]);

        $this->assertSame('enrol', $in['stage']);
        $this->assertTrue($in['user']['required']);
        $this->assertSame(['totp', 'email'], array_column($in['user']['methods'], 'key'));
        // Part-way is not signed in.
        $this->get('api/journals')->assertStatus(401);
    }

    public function testAWrongPasswordIsRefusedTheSameWayAsAnUnknownEmailAndLocksTheAccountInTheEnd(): void
    {
        $wrong = $this->send('post', 'api/auth/login', ['email' => self::FM, 'password' => 'not the password'], 422);
        $unknown = $this->send('post', 'api/auth/login', ['email' => 'nobody@elog.or.ke', 'password' => 'not the password'], 422);
        $this->assertSame($wrong['error'], $unknown['error']);

        for ($i = 2; $i < config(Auth::class)->maxFailedSignIns; $i++) {
            $this->send('post', 'api/auth/login', ['email' => self::FM, 'password' => 'still wrong'], 422);
        }
        $locked = $this->send('post', 'api/auth/login', ['email' => self::FM, 'password' => 'still wrong'], 422);
        $this->assertStringContainsString('locked for 15 minutes', $locked['error']);

        // Even the right password waits out the lock.
        $this->assertStringContainsString('locked', $this->send('post', 'api/auth/login', ['email' => self::FM, 'password' => OrganisationSeeder::DEMO_PASSWORD], 422)['error']);
        $this->seeInDatabase('audit_events', ['action' => 'auth.locked', 'object_type' => 'user']);
    }

    // ------------------------------------------------------------------
    // Authenticator app
    // ------------------------------------------------------------------

    public function testAnAuthenticatorAppIsSetUpThenAskedForAtEverySignIn(): void
    {
        $this->send('post', 'api/auth/login', ['email' => self::FM, 'password' => OrganisationSeeder::DEMO_PASSWORD]);
        $start = $this->send('post', 'api/auth/enrol/start', ['method' => 'totp']);
        $secret = $start['totp']['secret'];

        $this->assertStringStartsWith('otpauth://totp/ELOG:w.kamau%40elog.or.ke?secret=' . $secret, $start['totp']['uri']);
        $this->assertStringContainsString('does not match', $this->send('post', 'api/auth/enrol/confirm', ['code' => '000000'], 422)['error']);

        $done = $this->send('post', 'api/auth/enrol/confirm', ['code' => Totp::code($secret)]);
        $this->assertSame('done', $done['stage']);
        $this->assertCount(10, $done['recoveryCodes']);
        $this->assertMatchesRegularExpression('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $done['recoveryCodes'][0]);
        $this->get('api/journals')->assertStatus(200);

        // The secret is stored encrypted, never as it was shown.
        $user = db_connect()->table('users')->where('email', self::FM)->get()->getRowArray();
        $this->assertSame(['totp', '1'], [$user['mfa_method'], (string) $user['mfa_enabled']]);
        $this->assertStringNotContainsString($secret, (string) $user['mfa_secret']);

        // Next time: password, then the app's code. The code just used cannot be used again.
        $this->send('post', 'api/auth/logout');
        $this->assertSame('mfa', $this->send('post', 'api/auth/login', ['email' => self::FM, 'password' => OrganisationSeeder::DEMO_PASSWORD])['stage']);
        $this->send('post', 'api/auth/verify', ['code' => Totp::code($secret)], 422);
        $this->assertSame('done', $this->send('post', 'api/auth/verify', ['code' => Totp::code($secret, Totp::step() + 1)])['stage']);
        $this->seeInDatabase('audit_events', ['action' => 'auth.signed_in', 'summary' => 'Signed in with authenticator app']);
    }

    public function testARecoveryCodeGetsInOnceAndTooManyWrongCodesStartTheSignInAgain(): void
    {
        $codes = $this->enrolTotp(self::FM)['codes'];
        $this->send('post', 'api/auth/logout');

        $this->send('post', 'api/auth/login', ['email' => self::FM, 'password' => OrganisationSeeder::DEMO_PASSWORD]);
        $this->assertSame('done', $this->send('post', 'api/auth/verify', ['code' => strtoupper($codes[0])])['stage']);
        $this->send('post', 'api/auth/logout');

        $this->send('post', 'api/auth/login', ['email' => self::FM, 'password' => OrganisationSeeder::DEMO_PASSWORD]);
        $this->send('post', 'api/auth/verify', ['code' => $codes[0]], 422);
        for ($i = 2; $i < config(Auth::class)->maxCodeAttempts; $i++) {
            $this->send('post', 'api/auth/verify', ['code' => '123456'], 422);
        }
        $last = $this->send('post', 'api/auth/verify', ['code' => '123456'], 422);
        $this->assertSame('signedOut', $last['stage']);
        $this->assertStringContainsString('Start again', $last['error']);
    }

    // ------------------------------------------------------------------
    // Code by email
    // ------------------------------------------------------------------

    public function testACodeByEmailIsSetUpThenSentAtEverySignIn(): void
    {
        $this->send('post', 'api/auth/login', ['email' => self::FM, 'password' => OrganisationSeeder::DEMO_PASSWORD]);
        $this->send('post', 'api/auth/enrol/start', ['method' => 'email']);
        $this->assertSame([self::FM], $this->lastEmail()['recipients']);
        $done = $this->send('post', 'api/auth/enrol/confirm', ['code' => $this->emailedCode()]);
        $this->assertSame('done', $done['stage']);
        $this->send('post', 'api/auth/logout');

        $in = $this->send('post', 'api/auth/login', ['email' => self::FM, 'password' => OrganisationSeeder::DEMO_PASSWORD]);
        $this->assertSame(['mfa', 'email'], [$in['stage'], $in['user']['method']]);
        $this->assertStringContainsString('w••••••@elog.or.ke', $in['message']);
        $code = $this->emailedCode();

        // Asking again straight away is refused; the first code still works.
        $this->send('post', 'api/auth/code', [], 422);
        $this->assertSame('done', $this->send('post', 'api/auth/verify', ['code' => $code])['stage']);
    }

    // ------------------------------------------------------------------
    // Who must have a second step
    // ------------------------------------------------------------------

    public function testWhenOnlyPrivilegedRolesNeedASecondStepOthersGoStraightIn(): void
    {
        config(Auth::class)->mfaRequired = 'privileged';

        $this->assertSame('done', $this->send('post', 'api/auth/login', ['email' => self::ACCOUNTANT, 'password' => OrganisationSeeder::DEMO_PASSWORD])['stage']);
        $this->send('post', 'api/auth/logout');
        $this->assertSame('enrol', $this->send('post', 'api/auth/login', ['email' => self::FM, 'password' => OrganisationSeeder::DEMO_PASSWORD])['stage']);

        config(Auth::class)->mfaRequired = 'optional';
        $this->send('post', 'api/auth/logout');
        $this->assertSame('done', $this->send('post', 'api/auth/login', ['email' => self::FM, 'password' => OrganisationSeeder::DEMO_PASSWORD])['stage']);
    }

    // ------------------------------------------------------------------
    // Invitations and resets
    // ------------------------------------------------------------------

    public function testAnInvitationIsAOneTimeLinkToChooseAPasswordAndSetUpASecondStep(): void
    {
        $this->session = SignIn::values((int) db_connect()->table('users')->where('email', self::FM)->get()->getRow()->id);
        $invited = $this->send('post', 'api/settings/invite', ['name' => 'Amina Hassan', 'email' => 'a.hassan@elog.or.ke', 'roles' => ['Accountant', 'Grants Lead'], 'entities' => 'all']);
        $this->assertStringStartsWith('Invitation sent to a.hassan@elog.or.ke', $invited['message']);
        $user = current(array_filter($invited['users'], static fn ($u) => $u['email'] === 'a.hassan@elog.or.ke'));
        $this->assertSame(['Accountant', 'Grants Lead'], $user['roles']);

        $email = $this->lastEmail();
        $this->assertSame(['a.hassan@elog.or.ke'], $email['recipients']);
        preg_match('#accept-invite\?token=([A-Za-z0-9_-]+)#', $email['body'], $m);
        $token = $m[1];

        $this->session = [];
        $this->send('post', 'api/auth/login', ['email' => 'a.hassan@elog.or.ke', 'password' => 'anything at all'], 422);
        $this->assertSame(['name' => 'Amina Hassan', 'email' => 'a.hassan@elog.or.ke', 'purpose' => 'invite'], $this->send('get', 'api/auth/invite?token=' . $token)['link']);
        $this->assertStringContainsString('at least 12', $this->send('post', 'api/auth/invite', ['token' => $token, 'password' => 'short'], 422)['error']);

        $set = $this->send('post', 'api/auth/invite', ['token' => $token, 'password' => 'correct horse battery staple']);
        $this->assertSame('enrol', $set['stage']);
        $this->seeInDatabase('users', ['email' => 'a.hassan@elog.or.ke', 'status' => 'active']);

        // The link is used up.
        $this->session = [];
        $this->assertStringContainsString('already been used', $this->send('post', 'api/auth/invite', ['token' => $token, 'password' => 'another long password'], 422)['error']);
    }

    public function testSomeoneAddedBeforeSignInExistedIsSentALinkToChooseAPassword(): void
    {
        db_connect()->table('users')->where('email', self::ACCOUNTANT)->update(['password_hash' => null]);
        $this->session = SignIn::values($this->userId(self::FM));

        $row = current(array_filter($this->send('get', 'api/settings')['users'], static fn ($u) => $u['email'] === self::ACCOUNTANT));
        $this->assertSame('Active', $row['status']);
        $this->assertFalse($row['hasPassword']);

        $this->assertStringStartsWith('A link to choose a password', $this->send('post', 'api/users/' . $this->userId(self::ACCOUNTANT) . '/invite')['message']);
        $this->assertStringContainsString('accept-invite?token=', $this->lastEmail()['body']);
        $this->assertStringContainsString('already set a password', $this->send('post', 'api/users/' . $this->userId(self::FM) . '/invite', [], 422)['error']);
    }

    public function testAForgottenPasswordIsResetFromAnEmailedLinkWithoutSayingWhoHasAnAccount(): void
    {
        config(Auth::class)->mfaRequired = 'optional';
        $unknown = $this->send('post', 'api/auth/forgot', ['email' => 'nobody@elog.or.ke']);
        $known = $this->send('post', 'api/auth/forgot', ['email' => self::ACCOUNTANT]);
        $this->assertSame($unknown['message'], $known['message']);

        preg_match('#reset-password\?token=([A-Za-z0-9_-]+)#', $this->lastEmail()['body'], $m);
        $this->assertSame('done', $this->send('post', 'api/auth/reset', ['token' => $m[1], 'password' => 'a brand new pass phrase'])['stage']);

        $this->send('post', 'api/auth/logout');
        $this->send('post', 'api/auth/login', ['email' => self::ACCOUNTANT, 'password' => OrganisationSeeder::DEMO_PASSWORD], 422);
        $this->assertSame('done', $this->send('post', 'api/auth/login', ['email' => self::ACCOUNTANT, 'password' => 'a brand new pass phrase'])['stage']);
    }

    public function testAfterAResetTheAuthenticatorAppStillGivesTheSecondStep(): void
    {
        $secret = $this->enrolTotp(self::FM)['secret'];
        $this->send('post', 'api/auth/logout');

        $this->send('post', 'api/auth/forgot', ['email' => self::FM]);
        preg_match('#reset-password\?token=([A-Za-z0-9_-]+)#', $this->lastEmail()['body'], $m);
        $after = $this->send('post', 'api/auth/reset', ['token' => $m[1], 'password' => 'a brand new pass phrase']);
        $this->assertSame(['mfa', 'totp'], [$after['stage'], $after['user']['method']]);

        $this->assertSame('done', $this->send('post', 'api/auth/verify', ['code' => Totp::code($secret, Totp::step() + 1)])['stage']);
    }

    // ------------------------------------------------------------------
    // The session
    // ------------------------------------------------------------------

    public function testASuspendedUserIsSignedOutAndCannotSignInAgain(): void
    {
        config(Auth::class)->mfaRequired = 'optional';
        $this->send('post', 'api/auth/login', ['email' => self::ACCOUNTANT, 'password' => OrganisationSeeder::DEMO_PASSWORD]);
        $accountant = $this->session;

        $this->session = SignIn::values($this->userId(self::FM));
        $this->send('post', 'api/users/' . $this->userId(self::ACCOUNTANT) . '/suspend');

        $this->session = $accountant;
        $this->get('api/journals')->assertStatus(401);
        $this->session = [];
        $this->assertStringContainsString('suspended', $this->send('post', 'api/auth/login', ['email' => self::ACCOUNTANT, 'password' => OrganisationSeeder::DEMO_PASSWORD], 422)['error']);
    }

    public function testAnIdleSessionEndsAndAChangeMustComeFromTheApplicationsOwnPages(): void
    {
        $this->session = SignIn::values($this->userId(self::FM));
        $this->get('api/journals')->assertStatus(200);

        $this->withHeaders([]);
        $this->withBodyFormat('json')->post('api/notifications/read', [])->assertStatus(403);
        $this->withHeaders(['X-Requested-With' => 'phpunit']);

        $this->session[SignIn::SEEN] = time() - (config(Auth::class)->idleMinutes + 1) * 60;
        $this->get('api/journals')->assertStatus(401);
    }

    public function testActingAsSomeoneElseIsOnlyForATrainingInstance(): void
    {
        $this->session = SignIn::values($this->userId(self::FM));
        config(Auth::class)->actAs = false;

        $me = $this->send('get', 'api/me');
        $this->assertFalse($me['actAs']);
        $this->assertSame([], $me['actors']);
        $this->send('post', 'api/me/act-as', ['email' => self::ACCOUNTANT], 403);

        // A cookie set some other way is ignored.
        $_COOKIE['elog_actor'] = self::ACCOUNTANT;
        service('superglobals')->setCookie('elog_actor', self::ACCOUNTANT);
        $this->assertSame(self::FM, $this->send('get', 'api/me')['me']['email']);
    }

    public function testMyAccountChangesThePasswordAndTheSecondStepOnlyWithThePassword(): void
    {
        config(Auth::class)->mfaRequired = 'optional';
        $this->send('post', 'api/auth/login', ['email' => self::ACCOUNTANT, 'password' => OrganisationSeeder::DEMO_PASSWORD]);

        $account = $this->send('get', 'api/account');
        $this->assertSame(['Accountant'], array_column($account['access'], 'role'));
        $this->assertNull($account['mfa']['method']);

        $this->send('post', 'api/account/mfa/start', ['method' => 'totp', 'password' => 'wrong'], 422);
        $secret = $this->send('post', 'api/account/mfa/start', ['method' => 'totp', 'password' => OrganisationSeeder::DEMO_PASSWORD])['totp']['secret'];
        $saved = $this->send('post', 'api/account/mfa/confirm', ['code' => Totp::code($secret)]);
        $this->assertSame('totp', $saved['mfa']['method']);
        $this->assertCount(10, $saved['recoveryCodes']);

        $this->send('post', 'api/account/mfa/remove', ['password' => OrganisationSeeder::DEMO_PASSWORD]);
        $this->send('post', 'api/account/password', ['current' => 'wrong', 'password' => 'a long new password'], 422);
        $this->assertSame('Password changed.', $this->send('post', 'api/account/password', ['current' => OrganisationSeeder::DEMO_PASSWORD, 'password' => 'a long new password'])['message']);
    }

    // ------------------------------------------------------------------

    /** A request, carrying the session on from the last one. Returns the decoded body. */
    private function send(string $method, string $url, array $body = [], int $status = 200): array
    {
        $this->withSession($this->session);
        $result = $method === 'get' ? $this->get($url) : $this->withBodyFormat('json')->post($url, $body);
        $this->session = $_SESSION;
        $json = json_decode((string) $result->getJSON(), true) ?? [];
        $this->assertSame($status, $result->response()->getStatusCode(), $method . ' ' . $url . ': ' . ($json['error'] ?? ''));

        return $json;
    }

    /** @return array{secret: string, codes: list<string>} */
    private function enrolTotp(string $email): array
    {
        $this->send('post', 'api/auth/login', ['email' => $email, 'password' => OrganisationSeeder::DEMO_PASSWORD]);
        $secret = $this->send('post', 'api/auth/enrol/start', ['method' => 'totp'])['totp']['secret'];
        $codes = $this->send('post', 'api/auth/enrol/confirm', ['code' => Totp::code($secret)])['recoveryCodes'];

        return ['secret' => $secret, 'codes' => $codes];
    }

    private function lastEmail(): array
    {
        return service('email')->archive;
    }

    private function emailedCode(): string
    {
        preg_match('/^(\d{6})$/m', $this->lastEmail()['body'], $m);

        return $m[1];
    }

    private function userId(string $email): int
    {
        return (int) db_connect()->table('users')->where('email', $email)->get()->getRow()->id;
    }
}
