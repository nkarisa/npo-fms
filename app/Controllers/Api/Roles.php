<?php

namespace App\Controllers\Api;

use App\Repositories\RoleRepository;
use App\Repositories\RuleViolation;

/**
 * Settings → Roles: define roles as named sets of permissions.
 *
 * People get permissions only by holding roles (Api\Users sets who holds what).
 * Seeing and changing roles both need users.manage, and nobody changes a role
 * they hold themselves (see RoleRepository). Changes save as they are
 * made — not in the settings draft — because a half-saved role would leave its
 * holders with permissions nobody chose.
 */
class Roles extends BaseApiController
{
    public function index()
    {
        if ($refusal = $this->cannotManage()) {
            return $refusal;
        }

        return $this->json($this->payload());
    }

    /** Body: {name, description, permissions: [keys]}. */
    public function create()
    {
        if ($refusal = $this->cannotManage()) {
            return $refusal;
        }
        $body = $this->request->getJSON(true) ?? [];
        try {
            $role = (new RoleRepository())->create((string) ($body['name'] ?? ''), (string) ($body['description'] ?? ''), (array) ($body['permissions'] ?? []), $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['message' => $role['name'] . ' added. Give it to people under Users.'] + $this->payload());
    }

    /** Body: {name, description, permissions: [keys]}. */
    public function update(int $id)
    {
        if ($refusal = $this->cannotManage()) {
            return $refusal;
        }
        $body = $this->request->getJSON(true) ?? [];
        try {
            $role = (new RoleRepository())->update($id, (string) ($body['name'] ?? ''), (string) ($body['description'] ?? ''), (array) ($body['permissions'] ?? []), $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['message' => $role['name'] . ' saved. It applies to everyone holding it from their next request.'] + $this->payload());
    }

    public function delete(int $id)
    {
        if ($refusal = $this->cannotManage()) {
            return $refusal;
        }
        try {
            (new RoleRepository())->delete($id, $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['message' => 'Role deleted.'] + $this->payload());
    }

    // ------------------------------------------------------------------

    private function cannotManage()
    {
        return $this->can('users.manage') ? null
            : $this->denied($this->actor()['role'] . ' cannot change roles. That needs a role with users.manage — every change is recorded in the audit log.');
    }

    private function payload(): array
    {
        $roles = new RoleRepository();

        return ['roles' => $roles->rolesFor($this->actorId()), 'catalogue' => $roles->catalogue(), 'canManage' => $this->can('users.manage')];
    }
}
