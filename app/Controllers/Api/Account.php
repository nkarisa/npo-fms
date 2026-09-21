<?php

namespace App\Controllers\Api;

use App\Repositories\AuthRepository;
use App\Repositories\RoleRepository;
use App\Repositories\RuleViolation;

/**
 * My account: who I am, the roles I hold and where, my password, and my second
 * sign-in step.
 *
 * Always the person actually signed in — never whoever they are acting as on a
 * training instance. Anything that weakens or replaces the second step, or shows
 * new recovery codes, asks for the password again, so a session left open at a
 * desk cannot be used to take the account over.
 */
class Account extends BaseApiController
{
    public function index()
    {
        return $this->json($this->payload());
    }

    /** Body: {current, password}. */
    public function password()
    {
        $body = $this->request->getJSON(true) ?? [];
        try {
            (new AuthRepository())->changePassword($this->me()['id'], (string) ($body['current'] ?? ''), (string) ($body['password'] ?? ''));
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['message' => 'Password changed.'] + $this->payload());
    }

    /** Body: {method, password}. Starts setting up (or switching to) a second step. */
    public function mfaStart()
    {
        $body = $this->request->getJSON(true) ?? [];
        try {
            $this->recheck((string) ($body['password'] ?? ''));
            $out = Auth::startEnrolment($this->me()['id'], (string) ($body['method'] ?? ''));
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json($out + $this->payload());
    }

    /** Body: {code}. Saves the step being set up and returns the recovery codes. */
    public function mfaConfirm()
    {
        $id = $this->me()['id'];
        try {
            $codes = Auth::confirmEnrolment($id, (string) ($this->request->getJSON(true)['code'] ?? ''), $id);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['message' => 'Second sign-in step saved.', 'recoveryCodes' => $codes] + $this->payload());
    }

    /** Body: {password}. Turns the second step off, where the user's roles allow it. */
    public function mfaRemove()
    {
        try {
            $this->recheck((string) ($this->request->getJSON(true)['password'] ?? ''));
            (new AuthRepository())->removeFactor($this->me()['id'], $this->me()['id']);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['message' => 'Second sign-in step turned off.'] + $this->payload());
    }

    /** Body: {password}. New recovery codes; the old ones stop working. */
    public function recoveryCodes()
    {
        try {
            $this->recheck((string) ($this->request->getJSON(true)['password'] ?? ''));
            $codes = (new AuthRepository())->regenerateRecoveryCodes($this->me()['id']);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['message' => 'New recovery codes issued. The old ones no longer work.', 'recoveryCodes' => $codes] + $this->payload());
    }

    // ------------------------------------------------------------------

    private function me(): array
    {
        return $this->signedInUser() ?? throw new \RuntimeException('No one is signed in.');
    }

    private function recheck(string $password): void
    {
        $hash = (string) db_connect()->table('users')->select('password_hash')->where('id', $this->me()['id'])->get()->getRowArray()['password_hash'];
        if ($password === '' || !password_verify($password, $hash)) {
            throw new RuleViolation('Enter your current password to make this change.');
        }
    }

    private function payload(): array
    {
        $me = $this->me();
        $auth = new AuthRepository();
        $entities = array_column(db_connect()->table('entities')->select('code, name')->orderBy('id')->get()->getResultArray(), 'name', 'code');
        $events = db_connect()->table('audit_events')->select('occurred_at, summary, ip_address')
            ->where(['object_type' => 'user', 'object_id' => $me['id']])->like('action', 'auth.', 'after')
            ->orderBy('id', 'DESC')->limit(8)->get()->getResultArray();

        return [
            'me' => [
                'name' => $me['name'], 'email' => $me['email'], 'initials' => $me['initials'], 'role' => $me['role'],
                'lastSignIn' => $me['lastSignIn'], 'limit' => $me['limit'], 'rights' => $me['rights'],
            ],
            'access' => array_map(static fn ($a) => [
                'role' => $a['role'],
                'entities' => $a['entities'] === 'all' ? 'All entities' : implode(', ', array_map(static fn ($c) => $entities[$c] ?? $c, $a['entities'])),
            ], (new RoleRepository())->access($me['id'])),
            'permissions' => $me['permissions'],
            'mfa' => $auth->factorState($me['id']),
            'activity' => array_map(static fn ($e) => [
                'when' => date('d M Y H:i', strtotime($e['occurred_at'])), 'what' => $e['summary'], 'from' => (string) $e['ip_address'],
            ], $events),
            'minLength' => config(\Config\Auth::class)->minPasswordLength,
        ];
    }
}
