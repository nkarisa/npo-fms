<?php

namespace App\Libraries;

use App\Repositories\UserRepository;
use Config\Auth;

/**
 * Who may see, and who may change, each section of Settings.
 *
 * The permissions follow the duties of a finance office rather than the screen, so
 * that the ones an auditor asks about can be held apart: whoever approves payments
 * need not set approval bands, and the M-Pesa credentials, payroll and users each
 * have a permission of their own. A section is shown to anyone holding one of its
 * `see` permissions or the one that changes it; settings.view shows the everyday
 * sections read only. Payroll, Integrations, Users, Roles and the audit log are
 * shown to nobody else.
 *
 * Everything that serves or changes a section asks here — Api\Settings and the
 * endpoints the sections save through, the sidebar and the Settings page itself —
 * so there is one statement of the rules. As everywhere, the permissions are those
 * held at the entity being worked in.
 */
final class SettingsAccess
{
    /** Shows the everyday sections, read only. */
    public const VIEW = 'settings.view';

    /**
     * Sections in the order the screen lists them. `see`: any one of these shows it
     * (holding `edit` always does). `edit`: the permission that changes it — null
     * for a section nobody changes.
     */
    public const SECTIONS = [
        'Organisation'             => ['icon' => '◧', 'see' => [self::VIEW], 'edit' => 'settings.organisation'],
        'Ledger'                   => ['icon' => '▤', 'see' => [self::VIEW], 'edit' => 'settings.ledger'],
        'Segments'                 => ['icon' => '◈', 'see' => [self::VIEW], 'edit' => 'settings.ledger'],
        'Currencies'               => ['icon' => '⇄', 'see' => [self::VIEW], 'edit' => 'settings.ledger'],
        'Taxes'                    => ['icon' => '%', 'see' => [self::VIEW], 'edit' => 'settings.ledger'],
        'Terms and reminders'      => ['icon' => '◔', 'see' => [self::VIEW], 'edit' => 'settings.ledger'],
        'Approvals'                => ['icon' => '✓', 'see' => [self::VIEW], 'edit' => 'settings.approvals'],
        'Bank statements'          => ['icon' => '⇅', 'see' => [self::VIEW], 'edit' => 'settings.banking'],
        'Opening balances'         => ['icon' => '⇥', 'see' => [self::VIEW], 'edit' => 'settings.ledger'],
        'Integrations'             => ['icon' => '⇌', 'see' => [], 'edit' => 'settings.integrations'],
        'Payroll'                  => ['icon' => '◍', 'see' => ['payroll.view'], 'edit' => 'settings.payroll'],
        'Appearance'               => ['icon' => '◐', 'see' => [self::VIEW], 'edit' => 'settings.organisation'],
        'Language and translation' => ['icon' => '⌾', 'see' => [self::VIEW], 'edit' => 'settings.organisation'],
        'Users'                    => ['icon' => '◉', 'see' => [], 'edit' => 'users.manage'],
        'Roles'                    => ['icon' => '◎', 'see' => [], 'edit' => 'users.manage'],
        'Audit log'                => ['icon' => '◷', 'see' => ['audit.view'], 'edit' => null],
    ];

    /**
     * The section each part of the settings draft belongs to. A save may change
     * only the sections its author can change; a part not named here is ignored.
     */
    public const DRAFT = [
        'organisation' => 'Organisation', 'entities' => 'Organisation',
        'ledger' => 'Ledger', 'toggles' => 'Ledger', 'postingAccounts' => 'Ledger', 'postingAccountsFollow' => 'Ledger', 'assetClasses' => 'Ledger',
        'segments' => 'Segments', 'currencies' => 'Currencies', 'taxes' => 'Taxes', 'days' => 'Terms and reminders',
        'approvals' => 'Approvals', 'approvalsFollow' => 'Approvals', 'procurement' => 'Approvals',
        'payroll' => 'Payroll', 'payAccounts' => 'Payroll',
        'appearance' => 'Appearance', 'language' => 'Language and translation', 'users' => 'Users',
    ];

    /** @param list<string> $permissions held at the entity being worked in */
    public function __construct(private readonly array $permissions)
    {
    }

    /** For an actor as UserRepository describes one. */
    public static function of(?array $actor): self
    {
        return new self($actor['permissions'] ?? []);
    }

    /**
     * For whoever the request acts as, the way Api\BaseApiController::actor() works
     * it out — for the pages, which have no actor of their own. Nobody signed in
     * sees nothing.
     */
    public static function current(): self
    {
        $users = new UserRepository();
        $email = config(Auth::class)->actAs ? service('request')->getCookie('elog_actor') : null;
        $id = SignIn::userId();

        return self::of(($email ? $users->actor((string) $email) : null) ?? ($id === null ? null : $users->actorById($id)));
    }

    public function canSee(string $section): bool
    {
        $s = self::SECTIONS[$section] ?? null;

        return $s !== null && ($this->canEdit($section) || array_intersect($s['see'], $this->permissions) !== []);
    }

    public function canEdit(string $section): bool
    {
        $edit = self::SECTIONS[$section]['edit'] ?? null;

        return $edit !== null && in_array($edit, $this->permissions, true);
    }

    /** @return list<string> the sections shown, in the screen's order */
    public function visible(): array
    {
        return array_values(array_filter(array_keys(self::SECTIONS), fn ($s) => $this->canSee($s)));
    }

    /** Whether Settings is shown at all. */
    public function any(): bool
    {
        return $this->visible() !== [];
    }

    /** Whether any section is changed through the settings draft. */
    public function editsDraft(): bool
    {
        foreach (array_unique(self::DRAFT) as $section) {
            if ($this->canEdit($section)) {
                return true;
            }
        }

        return false;
    }

    /** "Changing Payroll needs a role with settings.payroll." */
    public static function refusal(string $section): string
    {
        $edit = self::SECTIONS[$section]['edit'] ?? null;

        return $edit === null
            ? $section . ' cannot be changed.'
            : 'Changing ' . $section . ' needs a role with ' . $edit . ' — every change is recorded in the audit log.';
    }
}
