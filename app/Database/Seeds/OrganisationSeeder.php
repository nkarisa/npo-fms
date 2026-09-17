<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use App\Libraries\Theme;
use App\Repositories\SettingsRepository;
use CodeIgniter\Database\Seeder;

/**
 * Locales, entities, roles and permissions, users and the approval policy.
 *
 * Sources: LOCALES, ST_ENTITIES, ROLES, ACTORS, ST_USERS, ST_APPROVALS, ST_TOGGLES,
 * ST_SEGMENTS, and the prototype's settings state (cfg, currencies,
 * i18nFormatsLocked), which it holds inline rather than as data.
 */
class OrganisationSeeder extends Seeder
{
    /** Entity codes, and the word each is referred to by in user access ("Secretariat, Coast"). */
    private const ENTITIES = [
        'ELOG National Secretariat'    => ['code' => 'ELOG-NS', 'word' => 'Secretariat'],
        'ELOG Coast Regional Office'   => ['code' => 'ELOG-CST', 'word' => 'Coast'],
        'ELOG Western Regional Office' => ['code' => 'ELOG-WST', 'word' => 'Western'],
        'ELOG Trust (Endowment)'       => ['code' => 'ELOG-TRUST', 'word' => 'Trust'],
        'ELOG Rift Valley Office'      => ['code' => 'ELOG-RV', 'word' => 'Rift Valley'],
    ];

    /** Permissions each role holds, from the rights described in ACTORS. */
    private const PERMISSIONS = [
        'ledger.view'       => 'View the ledger, reports and supporting records',
        'journal.prepare'   => 'Prepare and submit journals and documents',
        'journal.approve'   => 'Approve documents within the role\'s ceiling',
        'journal.post'      => 'Post approved journals to the ledger',
        'requisition.raise' => 'Raise purchase requisitions',
        'payroll.view'      => 'View payroll records (personal data; every read is logged)',
        'settings.manage'   => 'Change organisation, ledger and approval settings',
        'period.close'      => 'Confirm the management review and close a period',
        'period.authorise'  => 'Authorise a period close and reopen a closed period',
        'chart.manage'      => 'Add, change, import and archive accounts in the chart',
    ];

    private const ROLE_PERMISSIONS = [
        'Finance Manager'     => ['ledger.view', 'journal.prepare', 'journal.approve', 'journal.post', 'requisition.raise', 'payroll.view', 'settings.manage', 'period.close', 'chart.manage'],
        'Executive Director'  => ['ledger.view', 'journal.approve', 'journal.post', 'payroll.view', 'period.authorise'],
        'Senior Accountant'   => ['ledger.view', 'journal.prepare', 'requisition.raise'],
        'Accountant'          => ['ledger.view', 'journal.prepare', 'requisition.raise'],
        'Programme Officer'   => ['requisition.raise'],
        'Auditor (read only)' => ['ledger.view'],
    ];

    /** Approval ceilings for the roles that can approve (ACTORS "limit"). Null is no ceiling. */
    private const CEILINGS = ['Finance Manager' => 5000000, 'Executive Director' => null];

    /**
     * Approvals the prototype states in prose rather than in ST_APPROVALS:
     * [document type, label, threshold, approver role, escalation role].
     */
    private const UNLISTED_APPROVALS = [
        ['payroll_run', 'Payroll runs', 0, 'Executive Director', null],
        ['advance', 'Staff and observer advances', 200000, 'Finance Manager', 'Executive Director'],
    ];

    /** The organisation as registered, held on the head office (the prototype's cfg). */
    private const PROFILE = [
        'registered_name' => 'Elections Observation Group', 'short_name' => 'ELOG',
        'tax_pin' => 'P051290384H', 'registration_no' => 'OP/218/051/2010/0142',
    ];

    /** The reporting basis: [key, label, value]. The functional currency is the entity's own. */
    private const CHOICES = [
        ['framework', 'Framework', 'IFRS'],
        ['yearEnd', 'Financial year end', '31 December'],
        ['codeLength', 'Account code length', '4 digits'],
    ];

    /** [code, name, indicative rate to KES, active]. */
    private const CURRENCIES = [
        ['KES', 'Kenya Shilling', 1, true],
        ['USD', 'US Dollar', 129.40, true],
        ['EUR', 'Euro', 139.80, true],
        ['DKK', 'Danish Krone', 18.75, true],
        ['GBP', 'Pound Sterling', 163.20, false],
    ];

    private const DOCUMENT_TYPES = ['journal' => 'journal', 'bill' => 'bill', 'payment' => 'payment_run',
        'subgrant' => 'subgrant', 'transfer' => 'transfer', 'revision' => 'budget_revision'];

    public function run(): void
    {
        $ctx = SeedContext::get();
        $now = $ctx->now();

        foreach ($ctx->data('LOCALES') as $l) {
            $ctx->remember('locales', $l['code'], $ctx->insert('locales', [
                'code' => $l['code'], 'label' => $l['label'], 'native_name' => $l['native'], 'direction' => $l['dir'],
                'is_source' => (int) ($l['source'] ?? false), 'status' => str_replace(' ', '_', strtolower($l['status'])),
                'reviewer' => $l['reviewer'], 'created_at' => $now,
            ]));
        }

        $secretariat = null;
        foreach ($ctx->data('ST_ENTITIES') as $e) {
            $meta = self::ENTITIES[$e['name']];
            $id   = $ctx->insert('entities', [
                'parent_id' => $e['type'] === 'Head office' ? null : $secretariat, 'code' => $meta['code'], 'name' => $e['name'],
                'type' => $e['type'], 'functional_currency' => $e['currency'], 'status' => strtolower($e['status']), 'created_at' => $now,
            ]);
            if ($secretariat === null) {
                $ctx->db()->table('entities')->where('id', $id)->update(self::PROFILE);
            }
            $secretariat ??= $id;
            $ctx->remember('entities', $meta['code'], $id);
            $ctx->remember('entity_words', $meta['word'], $id);
            $ctx->remember('entity_names', $e['name'], $id);
        }

        // Roles named only as the unlock authority for locked terminology.
        $roles = array_merge($ctx->data('ROLES'), ['Finance Director', 'Grants Lead']);
        foreach ($roles as $role) {
            $ctx->remember('roles', $role, $ctx->insert('roles', [
                'name' => $role, 'is_read_only' => (int) str_contains($role, 'read only'), 'created_at' => $now,
            ]));
        }

        foreach (self::PERMISSIONS as $key => $description) {
            $ctx->remember('permissions', $key, $ctx->insert('permissions', ['key' => $key, 'description' => $description]));
        }
        foreach (self::ROLE_PERMISSIONS as $role => $keys) {
            foreach ($keys as $key) {
                $ctx->db()->table('role_permissions')->insert(['role_id' => $ctx->require('roles', $role), 'permission_id' => $ctx->require('permissions', $key)]);
            }
        }

        $this->seedUsers($ctx, $now);

        foreach ($ctx->data('ST_APPROVALS') as $a) {
            $escalationRole = $ctx->lookup('roles', $a['escalation']);
            $ctx->insert('approval_rules', [
                'entity_id' => $secretariat, 'document_type' => self::DOCUMENT_TYPES[$a['key']], 'label' => $a['label'],
                'threshold' => $a['threshold'], 'approver_role_id' => $ctx->require('roles', $a['approver']),
                'escalation_role_id' => $escalationRole, 'escalation_note' => $escalationRole === null ? $a['escalation'] : null,
                'created_at' => $now,
            ]);
        }

        // Two approvals the prototype states in prose rather than in ST_APPROVALS:
        // every payroll run goes to the Executive Director whatever it comes to,
        // and an advance above the finance manager's 200,000 limit goes to them
        // too. Kept here as data, like the rest of the policy.
        foreach (self::UNLISTED_APPROVALS as [$type, $label, $threshold, $approver, $escalation]) {
            $ctx->insert('approval_rules', [
                'entity_id' => $secretariat, 'document_type' => $type, 'label' => $label, 'threshold' => $threshold,
                'approver_role_id' => $ctx->require('roles', $approver),
                'escalation_role_id' => $escalation === null ? null : $ctx->require('roles', $escalation),
                'escalation_note' => null, 'created_at' => $now,
            ]);
        }

        foreach (self::CEILINGS as $role => $ceiling) {
            $ctx->insert('approval_limits', ['role_id' => $ctx->require('roles', $role), 'document_type' => null, 'ceiling' => $ceiling, 'created_at' => $now]);
        }

        foreach ($ctx->data('ST_TOGGLES') as $t) {
            $ctx->insert('settings', [
                'entity_id' => $secretariat, 'key' => $t['key'], 'label' => $t['label'], 'note' => $t['note'],
                'value' => $t['on'] ? '1' : '0', 'kind' => 'toggle', 'created_at' => $now,
            ]);
        }
        foreach (self::CHOICES as [$key, $label, $value]) {
            $ctx->insert('settings', ['entity_id' => $secretariat, 'key' => $key, 'label' => $label, 'value' => $value, 'kind' => 'choice', 'created_at' => $now]);
        }
        $ctx->insert('settings', [
            'entity_id' => $secretariat, 'key' => Theme::KEY, 'kind' => 'appearance', 'value' => Theme::DEFAULT, 'created_at' => $now,
            'label' => 'Interface theme', 'note' => SettingsRepository::THEME_NOTE,
        ]);
        $ctx->insert('settings', [
            'entity_id' => $secretariat, 'key' => 'formatsLocked', 'kind' => 'language', 'value' => '1', 'created_at' => $now,
            'label' => "Hold numbers, dates and currency in the organisation's reporting locale (en-KE · KES)",
            'note' => 'Recommended. Finance staff, auditors and funders read the same figure the same way in every language, so a report cannot be misread as a different amount.',
        ]);

        foreach (self::CURRENCIES as [$code, $name, $rate, $active]) {
            $ctx->insert('currencies', ['code' => $code, 'name' => $name, 'indicative_rate' => $rate, 'is_active' => (int) $active, 'created_at' => $now]);
        }

        foreach ($ctx->data('ST_SEGMENTS') as $s) {
            $ctx->insert('segments', [
                'key' => $s['key'], 'name' => $s['name'], 'example' => $s['example'], 'is_required' => (int) $s['required'],
                'is_reported' => (int) $s['reported'], 'applies_to' => $s['applies'], 'created_at' => $now,
            ]);
        }
    }

    /**
     * ST_USERS is the user list; ACTORS adds sign-in detail for the people who can
     * act. The system user owns records whose author the prototype does not name;
     * it has no password and is suspended, so it can never sign in.
     */
    private function seedUsers(SeedContext $ctx, string $now): void
    {
        $actors = array_column($ctx->data('ACTORS'), null, 'email');
        $allEntities = array_values($ctx->all('entities'));

        foreach ($ctx->data('ST_USERS') as $u) {
            $actor = $actors[$u['email']] ?? null;
            [$first, $last] = explode(' ', $u['name'], 2) + [1 => ''];
            $short = $actor['short'] ?? (str_contains($u['name'], '(') ? trim(explode('(', $u['name'])[0]) : mb_substr($first, 0, 1) . '. ' . $last);
            $initials = $actor['initials'] ?? mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));

            [$signedInAt, $from] = $actor !== null
                ? [$ctx->datetime($actor['lastSignIn']), trim(explode('·', $actor['lastSignIn'])[1] ?? '') ?: null]
                : [$ctx->datetime($u['lastActive']), null];

            $id = $ctx->insert('users', [
                'email' => $u['email'], 'name' => $u['name'], 'short_name' => $short, 'initials' => $initials,
                'locale_id' => $ctx->require('locales', 'en-GB'), 'status' => strtolower($u['status']),
                'last_sign_in_at' => $signedInAt, 'last_sign_in_from' => $from, 'created_at' => $now,
            ]);

            $ctx->remember('users', mb_strtolower($short), $id);
            $ctx->remember('users', mb_strtolower($u['name']), $id);

            $entities = str_starts_with($u['entities'], 'All entities')
                ? $allEntities
                : array_map(static fn ($w) => $ctx->require('entity_words', trim($w)), explode(',', $u['entities']));

            foreach ($entities as $entityId) {
                $ctx->insert('user_entity_roles', [
                    'user_id' => $id, 'entity_id' => $entityId, 'role_id' => $ctx->require('roles', $u['role']), 'created_at' => $now,
                ]);
            }
        }

        $ctx->remember('users', SeedContext::SYSTEM_EMAIL, $ctx->insert('users', [
            'email' => SeedContext::SYSTEM_EMAIL, 'name' => 'Data migration (system)', 'short_name' => 'System',
            'initials' => 'SY', 'status' => 'suspended', 'created_at' => $now,
        ]));
    }
}
