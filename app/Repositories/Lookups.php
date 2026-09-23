<?php

namespace App\Repositories;

use App\Libraries\EntityScope;

/**
 * Reference data every other repository needs: people, the chart, funds,
 * programmes, grants, periods and ledger balances, each loaded once per request.
 */
final class Lookups extends Repository
{
    /** The demonstration organisation's head office, and the fallback before one exists. */
    public const SECRETARIAT = 'ELOG-NS';

    /**
     * The entity records are written to and whose calendar is read: the one chosen
     * at the top of the page (App\Libraries\EntityScope) — the head office in the
     * consolidated view, and outside a signed-in request. Or the entity named.
     */
    public function entityId(?string $code = null): int
    {
        if ($code === null && ($active = EntityScope::activeId()) !== null) {
            return $active;
        }
        $code ??= $this->headOfficeCode();

        return (int) $this->cached("entity:{$code}", fn () => $this->value('SELECT id FROM {entities} WHERE code = ?', [$code]));
    }

    /**
     * The head office, which holds what belongs to the whole organisation rather
     * than to one entity's books: settings, approval rules, statement formats, the
     * mail server and M-Pesa.
     */
    public function headOfficeId(): int
    {
        return $this->entityId($this->headOfficeCode());
    }

    /**
     * The head office's code: the entity with no parent.
     *
     * The schema has exactly one — Settings refuses to change the head office's type
     * or add a second — so this is the reporting entity the group consolidates into,
     * whatever the organisation happens to call it. It is only a constant for the
     * demonstration database, and for an instance that has not been installed yet.
     */
    public function headOfficeCode(): string
    {
        return $this->cached('head-office-code', fn () => (string) ($this->value(
            'SELECT code FROM {entities} WHERE parent_id IS NULL ORDER BY id LIMIT 1'
        ) ?? self::SECRETARIAT));
    }

    /**
     * The names Settings → Organisation holds for the entity being worked in, for the
     * documents that carry them: the close pack, the board pack, a donor report. An
     * entity that is not a legal body of its own leaves them blank, and carries the
     * head office's.
     *
     * @return array{registered: string, short: string}
     */
    public function organisationNames(): array
    {
        return $this->cached('organisation-names:' . $this->entityId(), function () {
            $sql = 'SELECT registered_name, short_name FROM {entities} WHERE id = ?';
            $own  = $this->row($sql, [$this->entityId()]) ?? [];
            $head = $this->row($sql, [$this->headOfficeId()]) ?? [];
            // Its names together or the head office's together, never one of each.
            $e = trim(($own['registered_name'] ?? '') . ($own['short_name'] ?? '')) !== '' ? $own : $head;
            $registered = trim((string) ($e['registered_name'] ?? ''));
            $short      = trim((string) ($e['short_name'] ?? ''));

            return ['registered' => $registered !== '' ? $registered : $short, 'short' => $short !== '' ? $short : $registered];
        });
    }

    // ---- People ----

    /** @return array<int, array> users by id */
    public function users(): array
    {
        return $this->cached('users', fn () => array_column($this->rows('SELECT * FROM {users} ORDER BY id'), null, 'id'));
    }

    public function shortName(?int $userId, string $empty = '—'): string
    {
        return $userId === null ? $empty : ($this->users()[$userId]['short_name'] ?? $empty);
    }

    /** "M. Otieno", "Michael Otieno", "G. Wambui · Programme Officer" or an email → user id. */
    public function userId(?string $nameOrEmail): ?int
    {
        $key = mb_strtolower(trim(explode('·', (string) $nameOrEmail)[0]));
        if ($key === '') {
            return null;
        }

        foreach ($this->users() as $id => $u) {
            if (in_array($key, [mb_strtolower($u['short_name']), mb_strtolower($u['name']), mb_strtolower($u['email'])], true)) {
                return (int) $id;
            }
        }

        return null;
    }

    /** A user's role at the entity being worked in (else at the head office, else their first role). */
    public function roleOf(int $userId): string
    {
        return $this->cached("role:{$userId}", fn () => (string) $this->value(
            'SELECT r.name FROM {user_entity_roles} ur JOIN {roles} r ON r.id = ur.role_id JOIN {entities} e ON e.id = ur.entity_id
             WHERE ur.user_id = ? ORDER BY ur.entity_id = ? DESC, e.code = ? DESC, ur.id LIMIT 1',
            [$userId, $this->entityId(), $this->headOfficeCode()]
        ));
    }

    /**
     * The first active user who can change settings. Until there is a sign-in, this
     * is who the application acts as when nobody has chosen otherwise; on a freshly
     * installed instance it is the only person there is.
     */
    public function settingsManagerId(): ?int
    {
        return $this->cached('settings-manager', function () {
            $id = $this->value(
                "SELECT u.id FROM {users} u JOIN {user_entity_roles} ur ON ur.user_id = u.id
                 JOIN {role_permissions} rp ON rp.role_id = ur.role_id JOIN {permissions} p ON p.id = rp.permission_id
                 WHERE u.status = 'active' AND p.key = 'settings.organisation' ORDER BY u.id LIMIT 1"
            );

            return $id === null ? null : (int) $id;
        });
    }

    /**
     * Whether a user holds a role, at the entity being worked in unless another is
     * named — or at any entity they hold one at when `$entityId` is null.
     *
     * A person can hold several roles (docs/authentication.md), so what they may
     * do is asked of the set, not of whichever one roleOf() happens to name first.
     */
    public function holdsRole(int $userId, string $role, ?int $entityId = null): bool
    {
        $key = "holds:{$userId}:{$role}:" . ($entityId ?? 'any');

        return $this->cached($key, fn () => (int) $this->value(
            'SELECT COUNT(*) FROM {user_entity_roles} ur JOIN {roles} r ON r.id = ur.role_id
             WHERE ur.user_id = ? AND r.name = ?' . ($entityId === null ? '' : ' AND ur.entity_id = ?'),
            $entityId === null ? [$userId, $role] : [$userId, $role, $entityId]
        ) > 0);
    }

    /**
     * Every active holder of a role — at the entity being worked in unless another
     * is named. Who a document waiting on that step is put in front of.
     *
     * @return list<int>
     */
    /** Who is looking: the signed-in user, or whoever they are acting as. */
    public function viewerId(): ?int
    {
        return EntityScope::viewerId();
    }

    public function holdersOf(string $role, ?int $entityId = null): array
    {
        $entityId ??= $this->entityId();

        return $this->cached("holders:{$role}:{$entityId}", fn () => array_map('intval', array_column($this->rows(
            "SELECT DISTINCT u.id FROM {users} u JOIN {user_entity_roles} ur ON ur.user_id = u.id JOIN {roles} r ON r.id = ur.role_id
             WHERE r.name = ? AND ur.entity_id = ? AND u.status = 'active' ORDER BY u.id",
            [$role, $entityId]
        ), 'id')));
    }

    /**
     * The first active user holding a role, e.g. who a journal is submitted to:
     * someone holding it at the entity being worked in, before anyone elsewhere.
     */
    public function holderOf(string $role): ?int
    {
        $id = $this->value(
            "SELECT u.id FROM {users} u JOIN {user_entity_roles} ur ON ur.user_id = u.id JOIN {roles} r ON r.id = ur.role_id
             WHERE r.name = ? AND u.status = 'active' ORDER BY ur.entity_id = ? DESC, u.id LIMIT 1",
            [$role, $this->entityId()]
        );

        return $id === null ? null : (int) $id;
    }

    // ---- Chart and balances ----

    /** @return array<string, array> accounts by code, in chart order */
    public function accounts(): array
    {
        return $this->cached('accounts', fn () => array_column($this->rows('SELECT * FROM {accounts} ORDER BY code'), null, 'code'));
    }

    public function accountById(int $id): ?array
    {
        foreach ($this->accounts() as $a) {
            if ((int) $a['id'] === $id) {
                return $a;
            }
        }

        return null;
    }

    /**
     * Each account's balance from the posted ledger, signed to its normal side:
     * asset and expense accounts debit-normal, the rest credit-normal. A contra
     * account such as accumulated depreciation therefore reads negative.
     *
     * @return array<string, float>
     */
    public function balances(): array
    {
        return $this->cached('balances', function () {
            $out = [];
            foreach ($this->rows(
                "SELECT a.code, a.type, SUM(l.debit) AS dr, SUM(l.credit) AS cr
                 FROM {journal_lines} l JOIN {journals} j ON j.id = l.journal_id JOIN {accounts} a ON a.id = l.account_id
                 WHERE j.status IN ('posted', 'reversed') GROUP BY a.code, a.type"
            ) as $r) {
                $out[$r['code']] = in_array($r['type'], ['asset', 'expense'], true) ? $r['dr'] - $r['cr'] : $r['cr'] - $r['dr'];
            }

            return $out;
        });
    }

    public function balance(string $code): float
    {
        return (float) ($this->balances()[$code] ?? 0);
    }

    // ---- Funds, programmes, grants ----

    /** @return array<int, array> funds by id */
    public function funds(): array
    {
        return $this->cached('funds', fn () => array_column($this->rows('SELECT * FROM {funds} ORDER BY code'), null, 'id'));
    }

    public function fundGroupLabel(?int $fundId): string
    {
        $fund = $fundId === null ? null : ($this->funds()[$fundId] ?? null);

        return $fund === null ? 'All funds' : self::FUND_GROUPS[$fund['ledger_group']];
    }

    /** @return array<int, array> programmes by id */
    public function programmes(): array
    {
        return $this->cached('programmes', fn () => array_column($this->rows('SELECT * FROM {programmes} ORDER BY code'), null, 'id'));
    }

    public function programmeName(?int $id): string
    {
        return $id === null ? '—' : ($this->programmes()[$id]['name'] ?? '—');
    }

    /** "Shared", "—" and blank all mean Shared services, as the prototype wrote them. */
    public function programmeId(?string $name): ?int
    {
        $name = in_array($name, [null, '', '—', 'Shared'], true) ? 'Shared services' : $name;

        foreach ($this->programmes() as $id => $p) {
            if ($p['name'] === $name || $p['code'] === $name) {
                return (int) $id;
            }
        }

        return null;
    }

    /** @return array<int, array> grants by id, with funder name */
    public function grants(): array
    {
        return $this->cached('grants', fn () => array_column($this->rows(
            'SELECT g.*, f.name AS funder_name FROM {grants} g JOIN {funders} f ON f.id = g.funder_id ORDER BY g.id'
        ), null, 'id'));
    }

    /** A grant by award reference or short name; "Unassigned" and the like → null. */
    public function grantId(?string $label): ?int
    {
        $label = trim((string) $label);

        foreach ($this->grants() as $id => $g) {
            if ($label !== '' && ($g['award_ref'] === $label || $g['short_name'] === $label)) {
                return (int) $id;
            }
        }

        return null;
    }

    /** The grant a fund was established for, if exactly one grant is held in it. */
    public function grantOfFund(int $fundId): ?int
    {
        $held = array_filter($this->grants(), static fn ($g) => (int) $g['fund_id'] === $fundId);

        return count($held) === 1 && self::FUND_GROUPS[$this->funds()[$fundId]['ledger_group']] === 'Grant Fund' ? (int) array_key_first($held) : null;
    }

    /** @return array<int, list<int>> the funds each award may be charged to, by grant id */
    public function grantFunds(): array
    {
        return $this->cached('grant-funds', function () {
            $out = [];
            // Through the grant, so only the awards of the entities in scope: the funds are shared, the awards are not.
            foreach ($this->rows('SELECT gf.grant_id, gf.fund_id FROM {grant_funds} gf JOIN {grants} g ON g.id = gf.grant_id JOIN {funds} f ON f.id = gf.fund_id ORDER BY gf.grant_id, f.code') as $r) {
                $out[(int) $r['grant_id']][] = (int) $r['fund_id'];
            }

            return $out;
        });
    }

    /** @return list<int> the programmes an award may be charged to: its own and its funds' */
    public function grantProgrammes(int $grantId): array
    {
        return $this->cached("grant-programmes:{$grantId}", function () use ($grantId) {
            $ids = array_map('intval', array_column($this->rows(
                'SELECT DISTINCT fp.programme_id FROM {grant_funds} gf JOIN {fund_programmes} fp ON fp.fund_id = gf.fund_id WHERE gf.grant_id = ?',
                [$grantId]
            ), 'programme_id'));
            $own = $this->grants()[$grantId]['programme_id'] ?? null;
            if ($own !== null && !in_array((int) $own, $ids, true)) {
                array_unshift($ids, (int) $own);
            }

            return $ids;
        });
    }

    /** @return list<string> the ledger funds (General Fund, Grant Fund, …) an award may be charged to */
    public function grantLedgerFunds(int $grantId): array
    {
        return array_values(array_unique(array_map(
            fn ($fundId) => self::FUND_GROUPS[$this->funds()[$fundId]['ledger_group']],
            $this->grantFunds()[$grantId] ?? []
        )));
    }

    /** @return array<int, array> funders by id */
    public function funders(): array
    {
        return $this->cached('funders', fn () => array_column($this->rows('SELECT * FROM {funders} ORDER BY name'), null, 'id'));
    }

    public function funderId(?string $name): ?int
    {
        foreach ($this->funders() as $id => $f) {
            if ($f['name'] === $name) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * The fund a posting line belongs to, from how a screen names it.
     *
     * A specific fund name or code is that fund. A ledger group with one fund
     * (General, Capital, Endowment) is that fund. A named grant picks its own fund
     * in the group. Otherwise "Grant Fund" is one of several, chosen by, in order:
     * for income and expenditure lines the funder recorded on the account; the
     * programme's grant fund; the account's funder.
     */
    public function resolveFund(string $name, ?int $grantId = null, ?string $programme = null, ?string $accountCode = null, bool $accountFirst = false): int
    {
        foreach ($this->funds() as $id => $f) {
            if ($f['name'] === $name || $f['code'] === $name) {
                return (int) $id;
            }
        }

        $group = array_search($name, self::FUND_GROUPS, true);
        if ($group === false) {
            throw new RuleViolation('"' . $name . '" is not a fund.');
        }

        $inGroup = array_filter($this->funds(), static fn ($f) => $f['ledger_group'] === $group && $f['status'] === 'active');

        // The award's own fund in that group, when the award is named.
        foreach ($grantId === null ? [] : ($this->grantFunds()[$grantId] ?? []) as $fundId) {
            if (isset($inGroup[$fundId])) {
                return $fundId;
            }
        }

        if ($group !== 'grant') {
            // Board designated reserves share the general group; the general fund is the one postings default to.
            usort($inGroup, static fn ($a, $b) => strcmp($a['code'], $b['code']));

            return (int) $inGroup[0]['id'];
        }

        $byGrant = $grantId === null ? null : ($this->grants()[$grantId]['fund_id'] ?? null);
        $account = $accountCode === null ? null : ($this->accounts()[$accountCode] ?? null);
        $byAccount = $account !== null && isset($inGroup[(int) $account['default_fund_id']]) ? (int) $account['default_fund_id'] : null;
        $byProgramme = $this->programmeGrantFund($programme);

        $fund = $byGrant ?? ($accountFirst ? ($byAccount ?? $byProgramme) : ($byProgramme ?? $byAccount));
        if ($fund === null) {
            throw new RuleViolation('Choose the grant for the ' . ($programme ?: 'unassigned') . ' line on ' . ($accountCode ?? 'this account') . ' — its grant fund cannot be inferred.');
        }

        return (int) $fund;
    }

    /** A programme's grant fund: the lowest-coded active grant fund linked to it. */
    private function programmeGrantFund(?string $programme): ?int
    {
        $programmeId = $this->programmeId($programme);
        if ($programmeId === null) {
            return null;
        }

        $id = $this->cached("programme-grant-fund:{$programmeId}", fn () => $this->value(
            "SELECT f.id FROM {fund_programmes} fp JOIN {funds} f ON f.id = fp.fund_id
             WHERE fp.programme_id = ? AND f.ledger_group = 'grant' AND f.status = 'active' ORDER BY f.code LIMIT 1",
            [$programmeId]
        ));

        return $id === null ? null : (int) $id;
    }

    // ---- Periods ----

    /** @return list<array> periods in date order */
    public function periods(): array
    {
        return $this->cached('periods', fn () => $this->rows('SELECT * FROM {periods} WHERE entity_id = ? ORDER BY starts_on', [$this->entityId()]));
    }

    /**
     * The periods of the working year: the fiscal year holding the earliest open
     * period (the latest year when every period is closed).
     *
     * @return list<array>
     */
    public function yearPeriods(): array
    {
        return $this->cached('year-periods', function () {
            $periods = $this->periods();
            $open = array_values(array_filter($periods, static fn ($p) => $p['status'] === 'open'));
            $year = ($open[0] ?? end($periods) ?: [])['fiscal_year_id'] ?? null;

            return array_values(array_filter($periods, static fn ($p) => $p['fiscal_year_id'] === $year));
        });
    }

    public function periodByName(string $name): ?array
    {
        foreach ($this->periods() as $p) {
            if ($p['name'] === $name) {
                return $p;
            }
        }

        return null;
    }

    // ---- Bank accounts ----

    /**
     * The bank and cash accounts of the entities in scope, by id, each with the code
     * of its ledger account. A ledger account carries a cash account of each entity,
     * so a code is unique only within one entity: in the consolidated view, or read
     * across entities, two rows can share it.
     *
     * @return array<int, array>
     */
    public function bankAccounts(): array
    {
        return $this->cached('bank-accounts', fn () => array_column($this->rows(
            'SELECT b.*, a.code FROM {bank_accounts} b JOIN {accounts} a ON a.id = b.account_id ORDER BY a.code, b.entity_id'
        ), null, 'id'));
    }

    /**
     * The entity's cash account on a ledger account, or null: the entity being worked
     * in unless another is named (the head office in the consolidated view).
     */
    public function bankAccount(string $code, ?int $entityId = null): ?array
    {
        $entityId ??= $this->entityId();
        foreach ($this->bankAccounts() as $b) {
            if ((string) $b['code'] === $code && (int) $b['entity_id'] === $entityId) {
                return $b;
            }
        }

        return null;
    }

    /** How the payables screens name the account a payment leaves from. */
    public static function paymentMethod(array $bank): string
    {
        return match (true) {
            $bank['kind'] === 'mobile_money' => 'M-Pesa B2B paybill',
            $bank['currency'] !== 'KES'      => 'SWIFT — ' . $bank['short_name'] . ' account',
            default                          => 'EFT — ' . preg_replace('/^Bank — /', '', $bank['name']),
        };
    }
}
