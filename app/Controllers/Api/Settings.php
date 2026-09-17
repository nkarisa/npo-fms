<?php

namespace App\Controllers\Api;

use App\Libraries\I18n as I18nLib;
use App\Repositories\RuleViolation;
use App\Repositories\SettingsRepository;

/**
 * Settings (v5): organisation, ledger, segments, currencies, approvals, bank
 * statements, payroll, language, users and the audit log.
 *
 * The screen edits a draft and saves it in one go (save), so a change reaches the
 * ledger only when it is saved and every saved change is in the audit log. Bank
 * statement formats save as they are made (Api\StatementFormats), and "Language and
 * translation" is served in detail by Api\I18n. Only a user holding
 * settings.manage (the Finance Manager) can save; everyone else can look.
 */
class Settings extends BaseApiController
{
    /** Sections in the order the screen lists them. "Bank statements" is the addition to the v5 prototype. */
    private const SECTIONS = [
        ['label' => 'Organisation', 'icon' => '◧'],
        ['label' => 'Ledger', 'icon' => '▤'],
        ['label' => 'Segments', 'icon' => '◈'],
        ['label' => 'Currencies', 'icon' => '⇄'],
        ['label' => 'Approvals', 'icon' => '✓'],
        ['label' => 'Bank statements', 'icon' => '⇅'],
        ['label' => 'Payroll', 'icon' => '◍'],
        ['label' => 'Language and translation', 'icon' => '⌾'],
        ['label' => 'Users', 'icon' => '◉'],
        ['label' => 'Audit log', 'icon' => '◷'],
    ];

    public function index()
    {
        return $this->json($this->payload(new SettingsRepository()));
    }

    /**
     * Saves the draft. Body: any of organisation, ledger, toggles, segments,
     * currencies, approvals, payroll, users, language — only what differs from what
     * is held is changed.
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

    /** Body: {name, email, role, entities: 'all' | [entity codes]}. */
    public function invite()
    {
        if ($refusal = $this->cannotManage()) {
            return $refusal;
        }

        $body = $this->request->getJSON(true) ?? [];
        try {
            $user = (new SettingsRepository())->invite(
                (string) ($body['name'] ?? ''), (string) ($body['email'] ?? ''), (string) ($body['role'] ?? ''),
                ($body['entities'] ?? 'all') === 'all' ? 'all' : array_map('strval', (array) $body['entities']), $this->actorId()
            );
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json([
            'message' => 'Invitation sent to ' . $user['email'] . '. It expires after seven days; they appear as Invited until they accept.',
        ] + $this->payload(new SettingsRepository()));
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

    private function payload(SettingsRepository $settings): array
    {
        return [
            // The key is what the screen switches on; the label is translated for display.
            'sections'     => array_map(static fn ($s) => ['key' => $s['label']] + $s, self::SECTIONS),
            'canManage'    => in_array('settings.manage', $this->actor()['permissions'] ?? [], true),
            'organisation' => $settings->organisation(),
            'entities'     => $settings->entities(),
            'ledger'       => $settings->ledger(),
            'ledgerOptions' => [
                'frameworks'  => SettingsRepository::FRAMEWORKS,
                'currencies'  => array_map(static fn ($c) => ['value' => $c['code'], 'text' => $c['code'] . ' — ' . $c['name']], $settings->currencies()),
                'yearEnds'    => SettingsRepository::YEAR_ENDS,
                'codeLengths' => SettingsRepository::CODE_LENGTHS,
            ],
            'toggles'      => $settings->toggles(),
            'periods'      => $settings->periods(),
            'segments'     => $settings->segments(),
            'currencies'   => $settings->currencies(),
            'approvals'    => $settings->approvals(),
            'approverRoles' => $settings->approverRoles(),
            'sodRules'     => SettingsRepository::SOD_RULES,
            'benefits'     => $settings->benefits(),
            'grades'       => $settings->grades(),
            'roles'        => $settings->roles(),
            'users'        => $settings->users(),
            'entityOptions' => $settings->entityOptions(),
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
