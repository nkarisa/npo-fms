<?php

namespace App\Controllers\Api;

use App\Libraries\Brand;
use App\Libraries\I18n as I18nLib;
use App\Libraries\SettingsAccess;
use App\Libraries\Theme;
use App\Repositories\AssetClassRepository;
use App\Repositories\AuthRepository;
use App\Repositories\NotPermitted;
use App\Repositories\PayablesRepository;
use App\Repositories\PostingAccounts;
use App\Repositories\RoleRepository;
use App\Repositories\RuleViolation;
use App\Repositories\SettingsRepository;
use App\Repositories\TaxRepository;

/**
 * Settings (v5): organisation and its entities, ledger, segments, currencies,
 * approvals, bank statements, opening balances, integrations, payroll, appearance,
 * language, users and the audit log.
 *
 * The screen edits a draft and saves it in one go (save), so a change reaches the
 * ledger only when it is saved and every saved change is in the audit log. Bank
 * statement formats save as they are made (Api\StatementFormats), so does the
 * M-Pesa integration (Api\Mpesa) and the mail server (Api\Mail) — a credential cannot sit in a draft in the
 * browser. Carrying opening balances from a legacy system (Api\Conversion) reads a
 * file rather than a draft, and writes a journal for approval rather than settings.
 * "Language and translation" is served in detail by Api\I18n. Users and roles save
 * as they are made (Api\Users, Api\Roles).
 *
 * Who sees and who changes each section is App\Libraries\SettingsAccess: a
 * section someone cannot see is left out of what is served, data and all, and a
 * save that would change a section they cannot change is refused whole.
 */
class Settings extends BaseApiController
{
    /**
     * What each section of the screen is served, by the payload key. A key goes to
     * anyone who can see one of its sections; the rest (scope, language, who is
     * asking) every section reads.
     */
    private const DATA = [
        'organisation' => ['Organisation'], 'entities' => ['Organisation'], 'entityTypes' => ['Organisation'],
        'funds' => ['Segments'], 'fundOptions' => ['Segments'], 'segments' => ['Segments'],
        'ledger' => ['Ledger'], 'ledgerOptions' => ['Ledger'], 'periods' => ['Ledger'], 'toggles' => ['Ledger'],
        'postingAccounts' => ['Ledger'], 'postingAccountOptions' => ['Ledger'],
        'assetClasses' => ['Ledger'], 'assetClassOptions' => ['Ledger'],
        'currencies' => ['Currencies'], 'taxes' => ['Taxes'], 'days' => ['Terms and reminders'],
        'approvals' => ['Approvals'], 'ladders' => ['Approvals'], 'ownApprovals' => ['Approvals'], 'approverRoles' => ['Approvals'],
        'procurement' => ['Approvals'], 'sodRules' => ['Approvals'], 'maxLadder' => ['Approvals'],
        'payAccounts' => ['Payroll'], 'payAccountOptions' => ['Payroll'], 'benefits' => ['Payroll'], 'grades' => ['Payroll'],
        'appearance' => ['Appearance'], 'themes' => ['Appearance'],
        'users' => ['Users'], 'entityOptions' => ['Users'], 'passwordPolicy' => ['Users'], 'roles' => ['Users', 'Roles'],
        'roleDetail' => ['Roles'], 'permissionCatalogue' => ['Roles'],
        'audit' => ['Audit log'],
    ];

    public function index()
    {
        if (!$this->access()->any()) {
            return $this->denied($this->actor()['role'] . ' has no settings to look at. Seeing them needs a role with settings.view.');
        }

        return $this->json($this->payload(new SettingsRepository()));
    }

    /**
     * Saves the draft. Body: any of organisation, entities, ledger, toggles, assetClasses, segments,
     * currencies, approvals, procurement, taxes, days, postingAccounts, payroll, appearance, users, passwordPolicy, language — only what differs
     * from what is held is changed, and only in the sections this user can change
     * (SettingsAccess). The logo is not in the draft; it has its own endpoints below.
     */
    public function save()
    {
        $access = $this->access();
        if (!$access->editsDraft()) {
            return $this->denied($this->actor()['role'] . ' cannot change settings. That needs a role with one of the settings permissions — every change is recorded in the audit log.');
        }

        try {
            $changes = (new SettingsRepository())->save($this->request->getJSON(true) ?? [], $this->actorId(), $access->canEdit(...));
        } catch (NotPermitted $e) {
            return $this->denied($this->actor()['role'] . ' cannot do that. ' . $e->getMessage());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        $n = count($changes);

        return $this->json([
            'message' => $n === 0 ? 'No changes to save.' : 'Settings saved. ' . ($n === 1 ? 'The change has' : $n . ' changes have') . ' been written to the audit log.',
            'changes' => $changes,
        ] + $this->payload(new SettingsRepository()));
    }

    /**
     * Body: {name, email, roles: [role names] (or role: one name), entities: 'all' | [entity codes]}.
     * Creates the account and emails the link to choose a password.
     */
    public function invite()
    {
        if (!$this->can('users.manage')) {
            return $this->denied($this->actor()['role'] . ' cannot invite users. That needs a role with users.manage — every change is recorded in the audit log.');
        }

        $body = $this->request->getJSON(true) ?? [];
        try {
            $user = (new SettingsRepository())->invite(
                (string) ($body['name'] ?? ''), (string) ($body['email'] ?? ''), array_map('strval', (array) ($body['roles'] ?? $body['role'] ?? [])),
                ($body['entities'] ?? 'all') === 'all' ? 'all' : array_map('strval', (array) $body['entities']), $this->actorId()
            );
            $sent = (new AuthRepository())->invite($user['id'], $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json([
            'message' => $sent
                ? 'Invitation sent to ' . $user['email'] . '. It expires after seven days; they appear as Invited until they accept.'
                : 'The account for ' . $user['email'] . ' is created, but the invitation could not be emailed. Send it again from their row once mail is set up'
                    . (ENVIRONMENT === 'production' ? '.' : ' — for now the link is in the application log, writable/logs.'),
        ] + $this->payload(new SettingsRepository()));
    }

    /**
     * Replaces the logo. Multipart, with the image in `logo`; saved as it is chosen
     * rather than drafted, because a file cannot sit in a draft in the browser.
     */
    public function logo()
    {
        if ($refusal = $this->cannotChange('Appearance')) {
            return $refusal;
        }

        $file = $this->request->getFile('logo');
        $settings = new SettingsRepository();
        try {
            if ($file === null || !$file->isValid()) {
                throw new RuleViolation($file === null || $file->getError() === UPLOAD_ERR_NO_FILE
                    ? 'Choose the image to use as the logo.'
                    : $file->getClientName() . ' did not upload: ' . $file->getErrorString());
            }
            $settings->setLogo([
                'path' => $file->getTempName(), 'name' => $file->getClientName(),
                'size' => (int) $file->getSize(), 'mime' => (string) $file->getMimeType(),
            ], $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['message' => 'Logo saved. It is on every page for everyone.'] + $this->payload(new SettingsRepository()));
    }

    /** Removes the logo, leaving the sidebar to draw the initials of the application name. */
    public function removeLogo()
    {
        if ($refusal = $this->cannotChange('Appearance')) {
            return $refusal;
        }

        try {
            (new SettingsRepository())->clearLogo($this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['message' => 'Logo removed.'] + $this->payload(new SettingsRepository()));
    }

    /**
     * The logo itself. Served from storage rather than from public/, so replacing it
     * is a settings change rather than a deployment, and with the headers that keep
     * an image an image: nothing else may load it, and the browser is told not to
     * second-guess its type.
     */
    public function logoFile()
    {
        $path = (new SettingsRepository())->logoPath();
        if ($path === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'No logo is set.']);
        }

        return $this->response
            ->setHeader('Content-Type', mime_content_type($path) ?: 'application/octet-stream')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox")
            ->setHeader('Cache-Control', 'public, max-age=86400')
            ->setBody((string) file_get_contents($path));
    }

    // ------------------------------------------------------------------

    private function access(): SettingsAccess
    {
        return SettingsAccess::of($this->actor());
    }

    private function cannotChange(string $section)
    {
        return $this->access()->canEdit($section) ? null : $this->denied($this->actor()['role'] . ' cannot do that. ' . SettingsAccess::refusal($section));
    }

    /** The brand and the palette, with the logo's address rather than its storage key. */
    private function appearance(SettingsRepository $settings): array
    {
        $held = $settings->appearance();

        return [
            'theme' => $held['theme'], 'custom' => $held['custom'],
            'appName' => $held['appName'], 'appTagline' => $held['appTagline'],
            'logo' => Brand::current()['logo'],
        ];
    }

    private function payload(SettingsRepository $settings): array
    {
        $access = $this->access();
        $sections = [];
        foreach ($access->visible() as $key) {
            $s = SettingsAccess::SECTIONS[$key];
            // The key is what the screen switches on; the label is translated for display.
            $sections[] = ['key' => $key, 'label' => $key, 'icon' => $s['icon'], 'canEdit' => $access->canEdit($key), 'edit' => $s['edit']];
        }

        // Each read only for a section that is shown: a hidden section's data is not served.
        $data = [
            'organisation' => fn () => $settings->organisation(),
            'entities'     => fn () => $settings->entities(),
            'funds'        => fn () => $settings->funds(),
            'payAccounts'  => fn () => $settings->payAccounts(),
            'payAccountOptions' => fn () => $settings->payAccountOptions(),
            'fundOptions'  => fn () => $settings->fundOptions(),
            'ledger'       => fn () => $settings->ledger(),
            'ledgerOptions' => fn () => [
                'frameworks'  => SettingsRepository::FRAMEWORKS,
                'currencies'  => array_map(static fn ($c) => ['value' => $c['code'], 'text' => $c['code'] . ' — ' . $c['name']], $settings->currencies()),
                'yearEnds'    => SettingsRepository::YEAR_ENDS,
                'codeLengths' => SettingsRepository::CODE_LENGTHS,
            ],
            'postingAccounts' => fn () => (new PostingAccounts())->all(),
            'postingAccountOptions' => fn () => (new PostingAccounts())->options(),
            'assetClasses' => fn () => (new AssetClassRepository())->all(),
            'assetClassOptions' => fn () => (new AssetClassRepository())->costOptions(),
            'toggles'      => fn () => $settings->toggles(),
            'periods'      => fn () => $settings->periods(),
            'segments'     => fn () => $settings->segments(),
            'currencies'   => fn () => $settings->currencies(),
            'approvals'    => fn () => $settings->approvals(),
            'ladders'      => fn () => $settings->ladders(),
            'maxLadder'    => fn () => SettingsRepository::MAX_LADDER_STEPS,
            'ownApprovals' => fn () => $settings->ownApprovals(),
            'approverRoles' => fn () => $settings->approverRoles(),
            'procurement'  => fn () => $settings->procurement(),
            'days'         => fn () => $settings->dayRules(),
            'taxes'        => fn () => (new TaxRepository())->schedule() + ['categories' => (new PayablesRepository())->categories()],
            'sodRules'     => fn () => SettingsRepository::SOD_RULES,
            'benefits'     => fn () => $settings->benefits(),
            'grades'       => fn () => $settings->grades(),
            'roles'        => fn () => $settings->roles(),
            'roleDetail'   => fn () => (new RoleRepository())->rolesFor($this->actorId()),
            'permissionCatalogue' => fn () => (new RoleRepository())->catalogue(),
            'users'        => fn () => $settings->users(),
            'entityOptions' => fn () => $settings->entityOptions(),
            'passwordPolicy' => fn () => $settings->passwordPolicy(),
            'appearance'   => fn () => $this->appearance($settings),
            'themes'       => fn () => Theme::THEMES,
            'entityTypes'  => fn () => SettingsRepository::ENTITY_TYPES,
            'audit'        => fn () => $settings->auditLog(),
        ];
        $shown = [];
        foreach ($data as $key => $read) {
            if (array_filter(self::DATA[$key], $access->canSee(...)) !== []) {
                $shown[$key] = $read();
            }
        }

        return [
            'sections'     => $sections,
            // The section each part of the draft belongs to, so the screen drafts only what it shows.
            'draftSections' => SettingsAccess::DRAFT,
            'canManage'    => $access->editsDraft(),
            'canManageUsers' => $this->can('users.manage'),
            'me'           => $this->actor()['email'],
            // The entity whose own settings are shown: its registered details, approval bands,
            // procurement threshold and the accounts it pays from. The rest are the organisation's.
            'scope'        => $settings->scope(),
            'language'     => $this->languageSummary($settings),
        ] + $shown;
    }

    /**
     * Enough for the section tab and the user menu to render without a second call;
     * the full panel (coverage, locked terms, the raise queue) comes from /api/i18n.
     */
    private function languageSummary(SettingsRepository $settings): array
    {
        return [
            'current'   => $this->i18n->code(),
            'dir'       => $this->i18n->dir(),
            'coverage'  => $this->i18n->coverage(),
            // The organisation's two language settings, as the section edits them —
            // the fallback as it now stands, not the one this response was rendered
            // under, which is in the locale block.
            'fallback'  => $settings->fallbackMode(),
            'formatsLocked' => $settings->formatsLocked(),
            'locales'   => array_map(static fn ($l) => [
                'code'     => $l['code'],
                'native'   => $l['native'],
                'label'    => $l['label'],
                'coverage' => $l['coverage'],
                'status'   => $l['status'],
            ], I18nLib::locales()),
            'reporting' => [
                'locale'   => I18nLib::REPORTING_LOCALE,
                'currency' => I18nLib::REPORTING_CURRENCY,
            ],
        ];
    }
}
