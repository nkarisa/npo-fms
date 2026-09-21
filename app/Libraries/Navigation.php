<?php

namespace App\Libraries;

use Throwable;

/**
 * The application's page map: sidebar groups, and the URL each page lives at.
 *
 * One source of truth, read by both the shell (to draw the sidebar) and the API
 * (to link a notification or a search hit to its page). Keys are the prototype's
 * page identifiers, so data lifted from the prototype that refers to a page by
 * key — a notification's `page`, say — resolves without a translation table.
 */
class Navigation
{
    public const GROUPS = [
        'Overview' => [
            ['label' => 'Dashboard', 'icon' => '◧', 'page' => 'dash', 'url' => '/'],
            ['label' => 'Period close', 'icon' => '◷', 'page' => 'period', 'url' => '/period-close'],
        ],
        'Accounting' => [
            ['label' => 'Chart of accounts', 'icon' => '☰', 'page' => 'coa', 'url' => '/coa'],
            ['label' => 'Programmes', 'icon' => '◭', 'page' => 'programmes', 'url' => '/programmes'],
            ['label' => 'General ledger', 'icon' => '▤', 'page' => 'gl', 'url' => '/gl'],
            ['label' => 'Journals', 'icon' => '✎', 'page' => 'journals', 'url' => '/journals'],
            ['label' => 'Payables', 'icon' => '◇', 'page' => 'payables', 'url' => '/payables'],
            ['label' => 'Receivables', 'icon' => '◆', 'page' => 'receivables', 'url' => '/receivables'],
            ['label' => 'Procurement', 'icon' => '⊞', 'page' => 'procure', 'url' => '/procurement'],
            ['label' => 'Bank reconciliation', 'icon' => '⇄', 'page' => 'bankrec', 'url' => '/bank-rec'],
            ['label' => 'Asset register', 'icon' => '▣', 'page' => 'assets', 'url' => '/asset-register'],
            ['label' => 'Asset verification', 'icon' => '◫', 'page' => 'verify', 'url' => '/asset-verification'],
            ['label' => 'Payroll', 'icon' => '◔', 'page' => 'payroll', 'url' => '/payroll'],
            ['label' => 'Staff advances', 'icon' => '◎', 'page' => 'advances', 'url' => '/advances'],
        ],
        'Funds and grants' => [
            ['label' => 'Funds', 'icon' => '◈', 'page' => 'funds', 'url' => '/funds'],
            ['label' => 'Grants and awards', 'icon' => '◉', 'page' => 'grants', 'url' => '/grants'],
            ['label' => 'Budgets', 'icon' => '▦', 'page' => 'budgets', 'url' => '/budgets'],
            ['label' => 'Donor reports', 'icon' => '◐', 'page' => 'donor', 'url' => '/donor-reports'],
        ],
        'Insight' => [
            ['label' => 'Cashflow forecast', 'icon' => '⌇', 'page' => 'cashflow', 'url' => '/cashflow'],
            ['label' => 'Reports', 'icon' => '◍', 'page' => 'reports', 'url' => '/reports'],
            ['label' => 'Settings', 'icon' => '⚙', 'page' => 'settings', 'url' => '/settings'],
            ['label' => 'User manual', 'icon' => '?', 'page' => 'manual', 'url' => '/user-manual'],
        ],
    ];

    /**
     * What the topbar picker offers the person signed in: the entities they hold a
     * role at, then the consolidated view when they hold one at every entity
     * (App\Libraries\EntityScope). The one being worked in is marked current.
     *
     * @return list<array{value: string, code: string, name: string, current: bool}>
     */
    public static function entities(): array
    {
        try {
            return EntityScope::picker()['options'];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** URL for a page key, falling back to the dashboard for an unknown key. */
    public static function url(string $page): string
    {
        foreach (self::GROUPS as $items) {
            foreach ($items as $item) {
                if ($item['page'] === $page) {
                    return $item['url'];
                }
            }
        }

        return '/';
    }
}
