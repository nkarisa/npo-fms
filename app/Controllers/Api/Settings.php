<?php

namespace App\Controllers\Api;

use App\Libraries\I18n as I18nLib;
use App\Repositories\SettingsRepository;

class Settings extends BaseApiController
{
    /**
     * Sections in the order the v5 prototype presents them. Currencies and Payroll
     * sit between Segments and Users; "Language and translation" is the v5 addition
     * and is served in detail by Api\I18n rather than inline here.
     */
    private const SECTIONS = [
        ['label' => 'Organisation', 'icon' => '◧'],
        ['label' => 'Ledger', 'icon' => '▤'],
        ['label' => 'Segments', 'icon' => '◈'],
        ['label' => 'Currencies', 'icon' => '⇄'],
        ['label' => 'Approvals', 'icon' => '✓'],
        ['label' => 'Payroll', 'icon' => '◍'],
        ['label' => 'Language and translation', 'icon' => '⌾'],
        ['label' => 'Users', 'icon' => '◉'],
        ['label' => 'Audit log', 'icon' => '◷'],
    ];

    public function index()
    {
        $section  = $this->request->getGet('section') ?: self::SECTIONS[0]['label'];
        $settings = new SettingsRepository();

        return $this->json([
            'roles'      => $settings->roles(),
            'entities'   => $settings->entities(),
            'segments'   => $settings->segments(),
            'approvals'  => $settings->approvals(),
            'users'      => $settings->users(),
            'audit'      => $settings->auditLog(),
            'toggles'    => $settings->toggles(),
            'section'    => $section,
            'sections'   => array_map(
                static fn ($s) => $s + ['active' => $s['label'] === $section],
                self::SECTIONS
            ),
            'language'   => $this->languageSummary(),
        ]);
    }

    /**
     * Enough for the section tab and the user menu to render without a second call;
     * the full panel (coverage, locked terms, the raise queue) comes from /api/i18n.
     */
    private function languageSummary(): array
    {
        return [
            'current'   => $this->i18n->code(),
            'dir'       => $this->i18n->dir(),
            'coverage'  => $this->i18n->coverage(),
            'fallback'  => $this->i18n->fallbackMode(),
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
                'locked'   => I18nLib::formatsLocked(),
            ],
        ];
    }
}
