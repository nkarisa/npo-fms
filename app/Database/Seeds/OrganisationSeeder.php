<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use App\Libraries\Brand;
use App\Libraries\I18n;
use App\Libraries\Theme;
use App\Repositories\SettingsRepository;
use CodeIgniter\Database\Seeder;

/**
 * Locales, entities, roles and permissions, users and the approval policy.
 *
 * Sources: LOCALES, ST_ENTITIES, ACTORS, ST_USERS, ST_APPROVALS, ST_TOGGLES,
 * ST_SEGMENTS, and the prototype's settings state (cfg, i18nFormatsLocked, i18nFallback),
 * which it holds inline rather than as data.
 *
 * The access model itself — roles, permissions, ceilings — and the currencies and
 * segments come from BaselineSeeder, which runs first; this seeder takes those rows
 * as they stand and adds ELOG's entities, people and approval policy around them.
 */
class OrganisationSeeder extends Seeder
{
    /**
     * Every active demonstration user signs in with this password, and has no
     * second step until they set one up — the demonstration instance runs with
     * auth.mfaRequired = optional. It is published in docs/setup.md: this data is
     * for looking at, never for real books.
     */
    public const DEMO_PASSWORD = 'elog-demo-password';

    private static ?string $demoHash = null;

    /**
     * The account the install hands over. It holds BaselineSeeder::ADMIN_ROLE —
     * every permission there is — at every entity, so it also reaches the
     * consolidated view, and it is written before the people in ST_USERS so it is
     * the first user: whoever has just installed a copy of the demonstration
     * organisation is told to sign in as this one rather than as somebody from the
     * demonstration staff. It signs in with DEMO_PASSWORD like everyone else.
     *
     * It is not an approver. Approval rules name the Finance Manager and the
     * Executive Director, and whoever prepares an entry cannot approve it whatever
     * permissions they hold, so the demonstration's own people still approve its work.
     */
    private const ADMINISTRATOR = ['name' => 'Administrator', 'email' => 'admin@elog.or.ke', 'initials' => 'AD'];

    /** Entity codes, and the word each is referred to by in user access ("Secretariat, Coast"). */
    private const ENTITIES = [
        'ELOG National Secretariat'    => ['code' => 'ELOG-NS', 'word' => 'Secretariat'],
        'ELOG Coast Regional Office'   => ['code' => 'ELOG-CST', 'word' => 'Coast'],
        'ELOG Western Regional Office' => ['code' => 'ELOG-WST', 'word' => 'Western'],
        'ELOG Trust (Endowment)'       => ['code' => 'ELOG-TRUST', 'word' => 'Trust'],
        'ELOG Rift Valley Office'      => ['code' => 'ELOG-RV', 'word' => 'Rift Valley'],
    ];

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

    private const DOCUMENT_TYPES = ['journal' => 'journal', 'bill' => 'bill', 'payment' => 'payment_run',
        'subgrant' => 'subgrant', 'transfer' => 'transfer', 'revision' => 'budget_revision'];

    public function run(): void
    {
        $ctx = SeedContext::get();
        $now = $ctx->now();

        // BaselineSeeder has already written the source language, the roles, the
        // permissions each holds, the approval ceilings, the currencies and the
        // segments. Those rows are taken as they stand; the prototype adds the
        // languages and the demonstration organisation around them.
        foreach (['locales' => 'code', 'roles' => 'name', 'permissions' => 'key'] as $table => $column) {
            $ctx->adopt($table, $table, $column);
        }

        foreach ($ctx->data('LOCALES') as $l) {
            if ($ctx->lookup('locales', $l['code']) !== null) {
                continue;
            }
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

        foreach ($ctx->data('ST_TOGGLES') as $t) {
            $ctx->insert('settings', [
                'entity_id' => $secretariat, 'key' => $t['key'], 'label' => $t['label'], 'note' => $t['note'],
                'value' => $t['on'] ? '1' : '0', 'kind' => 'toggle', 'created_at' => $now,
            ]);
        }
        $ctx->insert('settings', [
            'entity_id' => $secretariat, 'key' => SettingsRepository::QUOTE_THRESHOLD_KEY, 'kind' => 'approvals', 'created_at' => $now,
            'value' => (string) SettingsRepository::QUOTE_THRESHOLD_DEFAULT, 'label' => SettingsRepository::QUOTE_THRESHOLD_LABEL,
        ]);
        foreach (self::CHOICES as [$key, $label, $value]) {
            $ctx->insert('settings', ['entity_id' => $secretariat, 'key' => $key, 'label' => $label, 'value' => $value, 'kind' => 'choice', 'created_at' => $now]);
        }
        $ctx->insert('settings', [
            'entity_id' => $secretariat, 'key' => Theme::KEY, 'kind' => 'appearance', 'value' => Theme::DEFAULT, 'created_at' => $now,
            'label' => 'Interface theme', 'note' => SettingsRepository::THEME_NOTE,
        ]);
        foreach ([[Brand::NAME_KEY, 'Application name', Brand::DEFAULT_NAME], [Brand::TAGLINE_KEY, 'Line under the application name', Brand::DEFAULT_TAGLINE],
            [Brand::LOGO_KEY, 'Logo', ''], [Theme::CUSTOM_KEY, 'Custom theme colours', json_encode(Theme::CUSTOM_DEFAULT)]] as [$key, $label, $value]) {
            $ctx->insert('settings', [
                'entity_id' => $secretariat, 'key' => $key, 'kind' => 'appearance', 'value' => $value, 'label' => $label, 'created_at' => $now,
            ]);
        }
        $ctx->insert('settings', [
            'entity_id' => $secretariat, 'key' => 'formatsLocked', 'kind' => 'language', 'value' => '1', 'created_at' => $now,
            'label' => "Hold numbers, dates and currency in the organisation's reporting locale (en-KE · KES)",
            'note' => 'Recommended. Finance staff, auditors and funders read the same figure the same way in every language, so a report cannot be misread as a different amount.',
        ]);
        $ctx->insert('settings', [
            'entity_id' => $secretariat, 'key' => I18n::FALLBACK_KEY, 'kind' => I18n::FALLBACK_KIND, 'created_at' => $now,
            'value' => I18n::DEFAULT_FALLBACK, 'label' => I18n::FALLBACK_LABEL, 'note' => I18n::FALLBACK_NOTE,
        ]);

        // The prototype's own wording for the segments, over the baseline's.
        foreach ($ctx->data('ST_SEGMENTS') as $s) {
            $ctx->db()->table('segments')->where('key', $s['key'])->update([
                'name' => $s['name'], 'example' => $s['example'], 'is_required' => (int) $s['required'],
                'is_reported' => (int) $s['reported'], 'applies_to' => $s['applies'], 'updated_at' => $now,
            ]);
        }
    }

    /**
     * The administrator, then ST_USERS as the user list, with ACTORS adding sign-in
     * detail for the people who can act. The system user owns records whose author
     * the prototype does not name; it has no password and is suspended, so it can
     * never sign in.
     */
    private function seedUsers(SeedContext $ctx, string $now): void
    {
        $actors = array_column($ctx->data('ACTORS'), null, 'email');
        $allEntities = array_values($ctx->all('entities'));

        $admin = $ctx->insert('users', [
            'email' => self::ADMINISTRATOR['email'], 'name' => self::ADMINISTRATOR['name'],
            'short_name' => self::ADMINISTRATOR['name'], 'initials' => self::ADMINISTRATOR['initials'],
            // No locale_id: the interface language is each reader's own, recorded only
            // once they pick one in the top bar. Until then the browser's language answers.
            'status' => 'active', 'created_at' => $now,
            'password_hash' => self::$demoHash ??= password_hash(self::DEMO_PASSWORD, PASSWORD_DEFAULT),
        ]);
        $ctx->remember('users', mb_strtolower(self::ADMINISTRATOR['name']), $admin);
        foreach ($allEntities as $entityId) {
            $ctx->insert('user_entity_roles', [
                'user_id' => $admin, 'entity_id' => $entityId,
                'role_id' => $ctx->require('roles', BaselineSeeder::ADMIN_ROLE), 'created_at' => $now,
            ]);
        }

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
                'status' => strtolower($u['status']),
                'last_sign_in_at' => $signedInAt, 'last_sign_in_from' => $from, 'created_at' => $now,
                // Invited people have not chosen a password yet. Hashed once: the tests seed for every test.
                'password_hash' => strtolower($u['status']) === 'invited' ? null : (self::$demoHash ??= password_hash(self::DEMO_PASSWORD, PASSWORD_DEFAULT)),
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
