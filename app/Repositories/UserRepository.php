<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\EntityScope;
use App\Libraries\Prototype;

/**
 * The people who can act in the books, as the user menu and "Act as" show them:
 * role, the entities they reach, what they may do and the most they may approve.
 */
final class UserRepository extends Repository
{
    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    /**
     * Active users with a role, in the order they were set up.
     *
     * Role, permissions and approval limit are those held at the entity being
     * worked in (App\Libraries\EntityScope): an Accountant at one branch and a
     * Programme Officer at another can do at each only what that role allows. In
     * the consolidated view, which only reads, they are everything held anywhere.
     */
    public function actors(): array
    {
        return $this->cached('actors', function () {
            $entityCount = (int) $this->value('SELECT COUNT(*) FROM {entities}');
            $at = EntityScope::consolidated() ? null : EntityScope::activeId();
            [$here, $hereBind] = $at === null ? ['', []] : [' AND ur.entity_id = ?', [$at]];
            $out = [];

            foreach ($this->rows(
                "SELECT u.* FROM {users} u WHERE u.status = 'active'
                 AND EXISTS (SELECT 1 FROM {user_entity_roles} ur WHERE ur.user_id = u.id) ORDER BY u.id"
            ) as $u) {
                $id = (int) $u['id'];
                $role = $this->lookups->roleOf($id);
                $permissions = array_column($this->rows(
                    'SELECT DISTINCT p.key FROM {user_entity_roles} ur JOIN {role_permissions} rp ON rp.role_id = ur.role_id
                     JOIN {permissions} p ON p.id = rp.permission_id WHERE ur.user_id = ?' . $here,
                    [$id, ...$hereBind]
                ), 'key');
                $entities = array_column($this->rows(
                    'SELECT DISTINCT e.name, e.code FROM {user_entity_roles} ur JOIN {entities} e ON e.id = ur.entity_id WHERE ur.user_id = ? ORDER BY e.code',
                    [$id]
                ), 'name');

                $canPrepare = in_array('journal.prepare', $permissions, true);
                $canApprove = in_array('journal.approve', $permissions, true);

                $out[] = [
                    'id'         => $id,
                    'email'      => $u['email'],
                    'name'       => $u['name'],
                    'short'      => $u['short_name'],
                    'initials'   => $u['initials'],
                    'role'       => $role,
                    // Every role held; `role` is the one named on approvals and in messages.
                    'roles'      => array_column($this->rows(
                        'SELECT DISTINCT r.id, r.name FROM {user_entity_roles} ur JOIN {roles} r ON r.id = ur.role_id WHERE ur.user_id = ?' . $here . ' ORDER BY r.id',
                        [$id, ...$hereBind]
                    ), 'name'),
                    'entities'   => count($entities) === $entityCount ? 'All entities (' . $entityCount . ')' : implode(', ', array_map([self::class, 'entityWord'], $entities)),
                    'rights'     => match (true) {
                        $canPrepare && $canApprove => 'Prepare, approve and post',
                        $canApprove                => 'Approve and post — no preparation rights',
                        $canPrepare                => 'Prepare and submit — cannot post',
                        in_array('ledger.view', $permissions, true) => 'Read only — no preparation or approval',
                        default                    => 'Raise requisitions only',
                    },
                    'limit'      => $this->limitOf($id, $at),
                    'lastSignIn' => $this->lastSignIn($u),
                    // When someone joined is not recorded.
                    'since'      => '—',
                    'canPrepare' => $canPrepare,
                    'canApprove' => $canApprove,
                    'permissions' => $permissions,
                ];
            }

            return $out;
        });
    }

    /** An active user with a role, by id — who a signed-in session is. */
    public function actorById(int $id): ?array
    {
        foreach ($this->actors() as $a) {
            if ($a['id'] === $id) {
                return $a;
            }
        }

        return null;
    }

    public function actor(string $email): ?array
    {
        foreach ($this->actors() as $a) {
            if ($a['email'] === $email) {
                return $a;
            }
        }

        return null;
    }

    private function limitOf(int $userId, ?int $entityId): string
    {
        $limit = $this->row(
            'SELECT al.ceiling FROM {user_entity_roles} ur JOIN {approval_limits} al ON al.role_id = ur.role_id
             WHERE ur.user_id = ? AND al.document_type IS NULL' . ($entityId === null ? '' : ' AND ur.entity_id = ?')
            . ' ORDER BY al.ceiling IS NULL DESC, al.ceiling DESC LIMIT 1',
            $entityId === null ? [$userId] : [$userId, $entityId]
        );

        return match (true) {
            $limit === null            => '—',
            $limit['ceiling'] === null => 'No ceiling',
            default                    => 'KES ' . Prototype::fmt((float) $limit['ceiling']) . ' per transaction',
        };
    }

    /** "Today, 09:12 · Nairobi", "Yesterday, 17:20", "2 days ago", "18 Aug". */
    public static function lastSignIn(array $u): string
    {
        if (!$u['last_sign_in_at']) {
            return '—';
        }

        $at   = strtotime($u['last_sign_in_at']);
        $days = -(int) Clock::daysUntil(date('Y-m-d', $at));
        $time = date('H:i', $at);
        $when = match (true) {
            $days === 0 => 'Today' . ($time !== '00:00' ? ', ' . $time : ''),
            $days === 1 => 'Yesterday' . ($time !== '00:00' ? ', ' . $time : ''),
            $days < 7   => $days . ' days ago',
            default     => date('d M', $at),
        };

        return $when . ($u['last_sign_in_from'] ? ' · ' . $u['last_sign_in_from'] : '');
    }

    /** "ELOG Coast Regional Office" → "Coast". */
    private static function entityWord(string $name): string
    {
        return match (true) {
            str_contains($name, 'Secretariat') => 'Secretariat',
            str_contains($name, 'Trust')       => 'Trust',
            default                            => trim(preg_replace('/^ELOG | (Regional )?Office$/', '', $name)),
        };
    }
}
