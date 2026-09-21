<?php

namespace App\Controllers\Api;

use App\Libraries\Brand;
use App\Libraries\I18n as I18nLib;
use App\Libraries\Theme;
use App\Repositories\AuthRepository;
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
 * "Language and translation" is served in detail by Api\I18n. Only a user holding
 * settings.manage (the Finance Manager) can save; everyone else can look. Users and
 * roles are users.manage, and save as they are made (Api\Users, Api\Roles).
 */
class Settings extends BaseApiController
{
    /** Sections in the order the screen lists them. "Taxes", "Terms and reminders", "Bank statements", "Opening balances", "Integrations" and "Appearance" are the additions to the v5 prototype. */
    private const SECTIONS = [
        ['label' => 'Organisation', 'icon' => '◧'],
        ['label' => 'Ledger', 'icon' => '▤'],
        ['label' => 'Segments', 'icon' => '◈'],
        ['label' => 'Currencies', 'icon' => '⇄'],
        ['label' => 'Taxes', 'icon' => '%'],
        ['label' => 'Terms and reminders', 'icon' => '◔'],
        ['label' => 'Approvals', 'icon' => '✓'],
        ['label' => 'Bank statements', 'icon' => '⇅'],
        ['label' => 'Opening balances', 'icon' => '⇥'],
        ['label' => 'Integrations', 'icon' => '⇌'],
        ['label' => 'Payroll', 'icon' => '◍'],
        ['label' => 'Appearance', 'icon' => '◐'],
        ['label' => 'Language and translation', 'icon' => '⌾'],
        ['label' => 'Users', 'icon' => '◉'],
        ['label' => 'Roles', 'icon' => '◎'],
        ['label' => 'Audit log', 'icon' => '◷'],
    ];

    public function index()
    {
        return $this->json($this->payload(new SettingsRepository()));
    }

    /**
     * Saves the draft. Body: any of organisation, entities, ledger, toggles, segments,
     * currencies, approvals, procurement, taxes, days, postingAccounts, payroll, appearance, users, language — only what differs
     * from what is held is changed. The logo is not in the draft; it has its own
     * endpoints below.
     */
    public function save()
    {
        if ($refusal = $this->cannotManage()) {
            return $refusal;
        }

        try {
            $changes = (new SettingsRepository())->save($this->request->getJSON(true) ?? [], $this->actorId());
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
        if ($refusal = $this->cannotManage()) {
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
        if ($refusal = $this->cannotManage()) {
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

    private function cannotManage()
    {
        $actor = $this->actor();
        if (in_array('settings.manage', $actor['permissions'] ?? [], true)) {
            return null;
        }

        return $this->response->setStatusCode(403)->setJSON(['error' => $actor['role'] . ' cannot change settings. Only the Finance Manager can — every change is recorded in the audit log.']);
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
        return [
            // The key is what the screen switches on; the label is translated for display.
            'sections'     => array_map(static fn ($s) => ['key' => $s['label']] + $s, self::SECTIONS),
            'canManage'    => in_array('settings.manage', $this->actor()['permissions'] ?? [], true),
            'canManageUsers' => $this->can('users.manage'),
            'me'           => $this->actor()['email'],
            'organisation' => $settings->organisation(),
            'entities'     => $settings->entities(),
            'funds'        => $settings->funds(),
            'payAccounts'  => $settings->payAccounts(),
            'payAccountOptions' => $settings->payAccountOptions(),
            'fundOptions'  => $settings->fundOptions(),
            'ledger'       => $settings->ledger(),
            'ledgerOptions' => [
                'frameworks'  => SettingsRepository::FRAMEWORKS,
                'currencies'  => array_map(static fn ($c) => ['value' => $c['code'], 'text' => $c['code'] . ' — ' . $c['name']], $settings->currencies()),
                'yearEnds'    => SettingsRepository::YEAR_ENDS,
                'codeLengths' => SettingsRepository::CODE_LENGTHS,
            ],
            'postingAccounts' => (new PostingAccounts())->all(),
            'postingAccountOptions' => (new PostingAccounts())->options(),
            'toggles'      => $settings->toggles(),
            'periods'      => $settings->periods(),
            'segments'     => $settings->segments(),
            'currencies'   => $settings->currencies(),
            'approvals'    => $settings->approvals(),
            'approverRoles' => $settings->approverRoles(),
            'procurement'  => $settings->procurement(),
            'days'         => $settings->dayRules(),
            'taxes'        => (new TaxRepository())->schedule() + ['categories' => (new PayablesRepository())->categories()],
            'sodRules'     => SettingsRepository::SOD_RULES,
            'benefits'     => $settings->benefits(),
            'grades'       => $settings->grades(),
            'roles'        => $settings->roles(),
            'roleDetail'   => (new RoleRepository())->roles(),
            'permissionCatalogue' => (new RoleRepository())->catalogue(),
            'users'        => $settings->users(),
            'entityOptions' => $settings->entityOptions(),
            'appearance'   => $this->appearance($settings),
            'themes'       => Theme::THEMES,
            'entityTypes'  => SettingsRepository::ENTITY_TYPES,
            'audit'        => $settings->auditLog(),
            'language'     => $this->languageSummary($settings),
        ];
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
            'fallback'  => $this->i18n->fallbackMode(),
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
