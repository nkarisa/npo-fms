<?php

namespace App\Repositories;

/** Organisation, segment, approval, user, audit and control settings. */
final class SettingsRepository extends Repository
{
    private const APPROVAL_KEYS = ['journal' => 'journal', 'bill' => 'bill', 'payment_run' => 'payment',
        'subgrant' => 'subgrant', 'transfer' => 'transfer', 'budget_revision' => 'revision'];

    public function roles(): array
    {
        return array_column($this->rows('SELECT name FROM {roles} ORDER BY id'), 'name');
    }

    public function entities(): array
    {
        return array_map(static fn ($e) => [
            'name' => $e['name'], 'type' => $e['type'], 'currency' => $e['functional_currency'], 'status' => ucfirst($e['status']),
        ], $this->rows('SELECT * FROM {entities} ORDER BY id'));
    }

    /** Segments with how many values each currently has. */
    public function segments(): array
    {
        return $this->cached('segments', function () {
            $counts = [
                'fund'        => $this->value("SELECT COUNT(*) FROM {funds} WHERE status = 'active'"),
                'program'     => $this->value("SELECT COUNT(*) FROM {programmes} WHERE status <> 'inactive'"),
                'restriction' => $this->value('SELECT COUNT(DISTINCT restriction) FROM {funds}'),
                'grant'       => $this->value('SELECT COUNT(*) FROM {grants}'),
                'funder'      => $this->value('SELECT COUNT(DISTINCT funder_id) FROM {grants}'),
                'county'      => $this->value('SELECT COUNT(*) FROM {counties}'),
            ];

            return array_map(static fn ($s) => [
                'key' => $s['key'], 'name' => $s['name'], 'example' => $s['example'] ?? '', 'count' => (int) ($counts[$s['key']] ?? 0),
                'required' => (bool) $s['is_required'], 'reported' => (bool) $s['is_reported'], 'applies' => $s['applies_to'],
            ], $this->rows('SELECT * FROM {segments} ORDER BY id'));
        });
    }

    public function approvals(): array
    {
        return array_map(static fn ($a) => [
            'key' => self::APPROVAL_KEYS[$a['document_type']] ?? $a['document_type'], 'label' => $a['label'], 'threshold' => self::num($a['threshold']),
            'approver' => $a['approver'], 'escalation' => $a['escalation'] ?? $a['escalation_note'] ?? '—',
        ], $this->rows(
            'SELECT ar.*, r.name AS approver, e.name AS escalation FROM {approval_rules} ar JOIN {roles} r ON r.id = ar.approver_role_id
             LEFT JOIN {roles} e ON e.id = ar.escalation_role_id ORDER BY ar.id'
        ));
    }

    public function users(): array
    {
        $lookups = new Lookups();
        $entityCount = (int) $this->value('SELECT COUNT(*) FROM {entities}');

        return array_map(static function ($u) use ($lookups, $entityCount) {
            $entities = explode('|', (string) $u['entity_names']);
            $words = array_map(static fn ($n) => match (true) {
                str_contains($n, 'Secretariat') => 'Secretariat',
                str_contains($n, 'Trust')       => 'Trust',
                default                         => trim(preg_replace('/^ELOG | (Regional )?Office$/', '', $n)),
            }, $entities);
            $signIn = UserRepository::lastSignIn($u);

            return [
                'name' => $u['name'], 'email' => $u['email'], 'role' => $lookups->roleOf((int) $u['id']),
                'entities' => count($entities) === $entityCount ? 'All entities' : implode(', ', $words),
                'lastActive' => $signIn === '—' ? '—' : lcfirst(trim(explode('·', $signIn)[0])), 'status' => ucfirst($u['status']),
            ];
        }, $this->withEntityNames());
    }

    /** Users with their entity names joined by "|" (entity names contain commas). */
    private function withEntityNames(): array
    {
        $names = [];
        foreach ($this->rows('SELECT ur.user_id, e.name FROM {user_entity_roles} ur JOIN {entities} e ON e.id = ur.entity_id ORDER BY e.id') as $r) {
            $names[(int) $r['user_id']][$r['name']] = true;
        }

        return array_map(static fn ($u) => $u + ['entity_names' => implode('|', array_keys($names[(int) $u['id']] ?? []))], $this->rows(
            'SELECT u.* FROM {users} u WHERE EXISTS (SELECT 1 FROM {user_entity_roles} ur WHERE ur.user_id = u.id) ORDER BY u.id'
        ));
    }

    /** Changes to settings and controls, newest first. */
    public function auditLog(): array
    {
        return $this->cached('audit', function () {
            $lookups = new Lookups();

            return array_map(static fn ($e) => [
                'when' => date('d M H:i', strtotime($e['occurred_at'])), 'who' => $lookups->shortName($e['actor_user_id'] === null ? null : (int) $e['actor_user_id']),
                'what' => $e['summary'] ?? '', 'area' => ucfirst(substr((string) strstr($e['object_type'], ':'), 1)),
            ], $this->rows("SELECT * FROM {audit_events} WHERE action = 'settings.changed' ORDER BY occurred_at DESC, id DESC"));
        });
    }

    public function toggles(): array
    {
        return array_map(static fn ($s) => ['key' => $s['key'], 'label' => $s['label'], 'note' => $s['note'] ?? '', 'on' => $s['value'] === '1'], $this->rows(
            'SELECT s.* FROM {settings} s JOIN {entities} e ON e.id = s.entity_id WHERE e.code = ? ORDER BY s.id', [Lookups::SECRETARIAT]
        ));
    }
}
