<?php

namespace App\Repositories;

use App\Database\Seeds\BaselineSeeder;
use App\Libraries\Clock;

/**
 * Roles, what each one permits, and who holds which.
 *
 * Permissions are never given to a person directly: a person holds roles, each
 * at the entities named, and may do whatever any of their roles permits. There is
 * no limit to how many roles an organisation defines or a person holds.
 *
 * The permissions themselves are fixed by the application — each one is checked
 * somewhere in the code — so a role is a named set of them. The roles an instance
 * starts with (BaselineSeeder::ROLES) are named in the approval policy and the
 * close checklist, so they can be given different permissions but cannot be
 * renamed or deleted. Roles added here can be all three.
 *
 * Every change is refused if it would leave nobody active who can manage users —
 * the organisation could not undo it. Nobody changes or deletes a role they hold
 * themselves: whoever manages roles could otherwise widen their own permissions,
 * so a change to theirs is made by someone else who manages users.
 */
final class RoleRepository extends Repository
{
    /** How the permissions are grouped on screen: key prefix → heading. */
    public const GROUPS = [
        'ledger' => 'Ledger', 'journal' => 'Journals and documents', 'requisition' => 'Procurement', 'payroll' => 'Payroll',
        'period' => 'Period close', 'chart' => 'Chart of accounts', 'settings' => 'Administration', 'users' => 'Administration',
        'audit' => 'Administration',
    ];

    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        parent::__construct($db);
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /** @return list<array{key: string, description: string, group: string}> */
    public function catalogue(): array
    {
        return array_map(static fn ($p) => [
            'key' => $p['key'], 'description' => $p['description'],
            'group' => self::GROUPS[explode('.', $p['key'])[0]] ?? 'Other',
        ], $this->rows('SELECT * FROM {permissions} ORDER BY id'));
    }

    public function roles(): array
    {
        return $this->cached('roles', function () {
            $perms = [];
            foreach ($this->rows('SELECT rp.role_id, p.key FROM {role_permissions} rp JOIN {permissions} p ON p.id = rp.permission_id ORDER BY p.id') as $r) {
                $perms[(int) $r['role_id']][] = $r['key'];
            }
            $holders = [];
            foreach ($this->rows(
                "SELECT DISTINCT ur.role_id, u.id, u.short_name, u.status FROM {user_entity_roles} ur JOIN {users} u ON u.id = ur.user_id ORDER BY u.id"
            ) as $r) {
                $holders[(int) $r['role_id']][] = ['name' => $r['short_name'], 'active' => $r['status'] === 'active'];
            }

            return array_map(static fn ($r) => [
                'id' => (int) $r['id'], 'name' => $r['name'], 'description' => (string) $r['description'],
                'builtIn' => in_array($r['name'], BaselineSeeder::ROLES, true), 'readOnly' => (bool) $r['is_read_only'],
                'permissions' => $perms[(int) $r['id']] ?? [],
                'holders' => array_column($holders[(int) $r['id']] ?? [], 'name'),
                'activeHolders' => count(array_filter($holders[(int) $r['id']] ?? [], static fn ($h) => $h['active'])),
            ], $this->rows('SELECT * FROM {roles} ORDER BY id'));
        });
    }

    /**
     * Every role, each marked with whether $userId holds it — the ones that person
     * may not change.
     */
    public function rolesFor(int $userId): array
    {
        $held = $this->heldBy($userId);

        return array_map(static fn ($r) => $r + ['yours' => in_array($r['id'], $held, true)], $this->roles());
    }

    /**
     * A user's roles and where each one applies.
     *
     * @return list<array{role: string, entities: string|list<string>}> entities is 'all' or entity codes
     */
    public function access(int $userId): array
    {
        $all = (int) $this->value('SELECT COUNT(*) FROM {entities}');
        $by = [];
        foreach ($this->rows(
            'SELECT r.name, e.code FROM {user_entity_roles} ur JOIN {roles} r ON r.id = ur.role_id JOIN {entities} e ON e.id = ur.entity_id
             WHERE ur.user_id = ? ORDER BY r.id, e.id', [$userId]
        ) as $row) {
            $by[$row['name']][] = $row['code'];
        }

        $out = [];
        foreach ($by as $role => $codes) {
            $out[] = ['role' => $role, 'entities' => count($codes) === $all ? 'all' : $codes];
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Roles
    // ------------------------------------------------------------------

    /** @param list<string> $permissions */
    public function create(string $name, string $description, array $permissions, int $actorId): array
    {
        $name = $this->validName($name);
        $keys = $this->validPermissions($permissions);

        $this->transaction(function () use ($name, $description, $keys, $actorId) {
            $id = $this->insert('roles', [
                'name' => $name, 'description' => trim($description) === '' ? null : trim($description),
                'is_read_only' => (int) $this->readOnly($keys), 'created_at' => Clock::timestamp(),
            ]);
            $this->setPermissions($id, $keys);
            $this->logChange('Roles', $name . ' role added' . ($keys === [] ? ' with no permissions yet' : ' — ' . implode(', ', $keys)), $actorId);
        });

        return $this->role($name);
    }

    /** @param list<string> $permissions */
    public function update(int $roleId, string $name, string $description, array $permissions, int $actorId): array
    {
        $role = $this->roleById($roleId);
        $this->assertNotOwn($role, $actorId);
        $name = trim($name) === '' ? $role['name'] : $this->validName($name, $roleId);
        if ($name !== $role['name'] && $role['builtIn']) {
            throw new RuleViolation($role['name'] . ' is one of the roles the application starts with. The approval policy and the close checklist name it, so it keeps its name — add a new role instead.');
        }
        $keys = $this->validPermissions($permissions);
        $added = array_values(array_diff($keys, $role['permissions']));
        $removed = array_values(array_diff($role['permissions'], $keys));
        $description = trim($description);

        if ($name === $role['name'] && $added === [] && $removed === [] && $description === $role['description']) {
            return $role;
        }

        $this->transaction(function () use ($roleId, $role, $name, $description, $keys, $added, $removed, $actorId) {
            $this->db->table('roles')->where('id', $roleId)->update([
                'name' => $name, 'description' => $description === '' ? null : $description,
                'is_read_only' => (int) $this->readOnly($keys), 'updated_at' => Clock::timestamp(),
            ]);
            $this->setPermissions($roleId, $keys);
            $this->assertManaged();

            $what = array_filter([
                $name !== $role['name'] ? 'renamed ' . $name : null,
                $added !== [] ? 'given ' . implode(', ', $added) : null,
                $removed !== [] ? 'no longer ' . implode(', ', $removed) : null,
                $description !== $role['description'] && $added === [] && $removed === [] && $name === $role['name'] ? 'description changed' : null,
            ]);
            $this->logChange('Roles', $role['name'] . ' role ' . implode('; ', $what), $actorId);
        });

        return $this->role($name);
    }

    public function delete(int $roleId, int $actorId): void
    {
        $role = $this->roleById($roleId);
        $this->assertNotOwn($role, $actorId);
        if ($role['builtIn']) {
            throw new RuleViolation($role['name'] . ' is one of the roles the application starts with and cannot be deleted. Take its permissions away instead if it is not used.');
        }
        if ($role['holders'] !== []) {
            throw new RuleViolation(count($role['holders']) . (count($role['holders']) === 1 ? ' person holds ' : ' people hold ') . $role['name'] . ' (' . implode(', ', $role['holders']) . '). Give them another role first.');
        }
        $rules = (int) $this->value('SELECT COUNT(*) FROM {approval_rules} WHERE approver_role_id = ? OR escalation_role_id = ?', [$roleId, $roleId]);
        if ($rules > 0) {
            throw new RuleViolation($role['name'] . ' approves or is escalated to in Settings → Approvals. Name another role there first.');
        }
        if ((int) $this->value('SELECT COUNT(*) FROM {translation_strings} WHERE unlock_role_id = ?', [$roleId]) > 0) {
            throw new RuleViolation($role['name'] . ' unlocks protected translations. Hand that to another role first.');
        }

        $this->transaction(function () use ($roleId, $role, $actorId) {
            // Permissions and approval ceilings go with the role (ON DELETE CASCADE).
            $this->db->table('roles')->where('id', $roleId)->delete();
            $this->logChange('Roles', $role['name'] . ' role deleted', $actorId);
        });
    }

    // ------------------------------------------------------------------
    // Who holds what
    // ------------------------------------------------------------------

    /**
     * Replaces a user's roles. Each assignment is a role and where it applies; a
     * person can hold any number of roles, and different roles at different entities.
     *
     * @param list<array{role: string, entities: string|list<string>}> $assignments
     */
    public function setAccess(int $userId, array $assignments, int $actorId): array
    {
        $user = $this->user($userId);
        $plan = $this->planAccess($assignments);

        $before = $this->describe($this->access($userId));
        $after = $this->describe(array_map(static fn ($p) => ['role' => $p['role'], 'entities' => $p['all'] ? 'all' : array_column($p['entities'], 'code')], $plan));
        if ($before === $after) {
            return $this->access($userId);
        }

        $this->transaction(function () use ($userId, $user, $plan, $after, $actorId) {
            $this->db->table('user_entity_roles')->where('user_id', $userId)->delete();
            $this->writeAccess($userId, $plan);
            $this->assertManaged();
            $this->logChange('Users', $user['short_name'] . ' now holds ' . $after, $actorId);
        });

        return $this->access($userId);
    }

    /**
     * Checks assignments and resolves them to role and entity ids.
     *
     * @return list<array{role: string, roleId: int, all: bool, entities: list<array{id: int, code: string, name: string}>}>
     */
    public function planAccess(array $assignments): array
    {
        $roles = array_column($this->rows('SELECT id, name FROM {roles}'), 'id', 'name');
        $entities = $this->rows('SELECT id, code, name FROM {entities} ORDER BY id');
        $plan = [];

        foreach ($assignments as $a) {
            $role = trim((string) ($a['role'] ?? ''));
            if ($role === '') {
                continue;
            }
            if (!isset($roles[$role])) {
                throw new RuleViolation($role . ' is not a role. Add it under Settings → Roles first.');
            }
            if (isset($plan[$role])) {
                throw new RuleViolation($role . ' is listed twice. Give it once, with every entity it applies to.');
            }
            $want = $a['entities'] ?? 'all';
            $chosen = $want === 'all' ? $entities
                : array_values(array_filter($entities, static fn ($e) => in_array($e['code'], array_map('strval', (array) $want), true)));
            if ($chosen === []) {
                throw new RuleViolation('Choose at least one entity where ' . $role . ' applies.');
            }
            $plan[$role] = ['role' => $role, 'roleId' => (int) $roles[$role], 'all' => $want === 'all' || count($chosen) === count($entities), 'entities' => $chosen];
        }

        if ($plan === []) {
            throw new RuleViolation('Give the person at least one role. To stop someone signing in, suspend them instead.');
        }

        return array_values($plan);
    }

    /** Writes planned assignments for a user who holds none. */
    public function writeAccess(int $userId, array $plan): void
    {
        $now = Clock::timestamp();
        foreach ($plan as $p) {
            foreach ($p['entities'] as $e) {
                $this->db->table('user_entity_roles')->insert(['user_id' => $userId, 'entity_id' => (int) $e['id'], 'role_id' => $p['roleId'], 'created_at' => $now]);
            }
        }
    }

    /** Suspends a user, or reinstates one. A suspended user cannot sign in. */
    public function setStatus(int $userId, bool $suspended, int $actorId): void
    {
        $user = $this->user($userId);
        if ($suspended && $userId === $actorId) {
            throw new RuleViolation('You cannot suspend yourself. Ask someone else who manages users.');
        }
        $status = $suspended ? 'suspended' : ($user['password_hash'] === null || $user['password_hash'] === '' ? 'invited' : 'active');
        if ($status === $user['status']) {
            return;
        }
        if (!$suspended && $user['status'] !== 'suspended') {
            return;
        }

        $this->transaction(function () use ($userId, $user, $status, $suspended, $actorId) {
            $this->db->table('users')->where('id', $userId)->update(['status' => $status, 'updated_at' => Clock::timestamp()]);
            $this->assertManaged();
            $this->logChange('Users', $user['short_name'] . ($suspended ? ' suspended — can no longer sign in' : ' reinstated'), $actorId);
        });
    }

    /**
     * Someone active must still be able to manage users — otherwise nobody could
     * undo what was just done, or give anyone back a permission taken away.
     */
    public function assertManaged(): void
    {
        if ($this->value('SELECT id FROM {permissions} WHERE ' . $this->db->escapeIdentifiers('key') . " = 'users.manage'") === null) {
            return;
        }
        $holders = (int) $this->value(
            "SELECT COUNT(DISTINCT u.id) FROM {users} u JOIN {user_entity_roles} ur ON ur.user_id = u.id JOIN {role_permissions} rp ON rp.role_id = ur.role_id
             JOIN {permissions} p ON p.id = rp.permission_id WHERE u.status = 'active' AND p.key = 'users.manage'"
        );
        if ($holders === 0) {
            throw new RuleViolation('That would leave nobody active who can manage users and roles, so it could never be undone. Make sure someone else keeps a role with users.manage first.');
        }
    }

    // ------------------------------------------------------------------

    /** Refuses a change to a role the person making it holds, at any entity. */
    private function assertNotOwn(array $role, int $actorId): void
    {
        if (in_array($role['id'], $this->heldBy($actorId), true)) {
            throw new RuleViolation('You hold ' . $role['name'] . ', so you cannot change it — that would let you widen your own permissions. Ask someone else who manages users.');
        }
    }

    /** @return list<int> the ids of the roles a user holds, at any entity */
    private function heldBy(int $userId): array
    {
        return array_map('intval', array_column($this->rows('SELECT DISTINCT role_id FROM {user_entity_roles} WHERE user_id = ?', [$userId]), 'role_id'));
    }

    /** "Senior Accountant (all entities), Grants Lead (ELOG-CST)" */
    private function describe(array $access): string
    {
        $parts = array_map(static fn ($a) => $a['role'] . ' (' . ($a['entities'] === 'all' ? 'all entities' : implode(', ', $a['entities'])) . ')', $access);
        sort($parts);

        return implode(', ', $parts);
    }

    private function validName(string $name, ?int $except = null): string
    {
        $name = preg_replace('/\s+/', ' ', trim($name));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            throw new RuleViolation('Name the role in 2 to 60 characters, as people would say it — "Grants Accountant", "Board Treasurer".');
        }
        $clash = $this->value('SELECT id FROM {roles} WHERE LOWER(name) = ?', [mb_strtolower($name)]);
        if ($clash !== null && (int) $clash !== $except) {
            throw new RuleViolation('There is already a role called ' . $name . '.');
        }

        return $name;
    }

    /** @return list<string> */
    private function validPermissions(array $permissions): array
    {
        $known = array_column($this->catalogue(), 'key');
        $keys = array_values(array_unique(array_map('strval', $permissions)));
        $unknown = array_diff($keys, $known);
        if ($unknown !== []) {
            throw new RuleViolation(implode(', ', $unknown) . (count($unknown) === 1 ? ' is not a permission' : ' are not permissions') . ' the application knows.');
        }

        // In the catalogue's order, so the audit log reads the same way each time.
        return array_values(array_intersect($known, $keys));
    }

    /** A role that can only look. */
    private function readOnly(array $keys): bool
    {
        return $keys !== [] && array_diff($keys, ['ledger.view', 'payroll.view', 'settings.view', 'audit.view']) === [];
    }

    private function setPermissions(int $roleId, array $keys): void
    {
        $this->db->table('role_permissions')->where('role_id', $roleId)->delete();
        if ($keys === []) {
            return;
        }
        $ids = array_column($this->db->table('permissions')->select('id')->whereIn('key', $keys)->get()->getResultArray(), 'id');
        foreach ($ids as $id) {
            $this->db->table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => (int) $id]);
        }
    }

    private function role(string $name): array
    {
        self::forget();
        foreach ($this->roles() as $r) {
            if ($r['name'] === $name) {
                return $r;
            }
        }

        throw new RuleViolation($name . ' is not a role.');
    }

    private function roleById(int $id): array
    {
        foreach ($this->roles() as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        throw new RuleViolation('That role does not exist.');
    }

    private function user(int $userId): array
    {
        return $this->row('SELECT * FROM {users} WHERE id = ?', [$userId]) ?? throw new RuleViolation('That user does not exist.');
    }

    private function logChange(string $area, string $what, int $actorId): void
    {
        $this->audit('settings:' . strtolower($area), null, null, $what, $actorId, 'settings.changed',
            (int) $this->value('SELECT id FROM {entities} WHERE parent_id IS NULL ORDER BY id LIMIT 1') ?: null);
    }
}
