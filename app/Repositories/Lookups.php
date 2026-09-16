<?php

namespace App\Repositories;

/**
 * Reference data every other repository needs: people, the chart, funds,
 * programmes, grants, periods and ledger balances, each loaded once per request.
 */
final class Lookups extends Repository
{
    public const SECRETARIAT = 'ELOG-NS';

    public function entityId(string $code = self::SECRETARIAT): int
    {
        return (int) $this->cached("entity:{$code}", fn () => $this->value('SELECT id FROM {entities} WHERE code = ?', [$code]));
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

    /** A user's role at the secretariat (or their first role elsewhere). */
    public function roleOf(int $userId): string
    {
        return $this->cached("role:{$userId}", fn () => (string) $this->value(
            'SELECT r.name FROM {user_entity_roles} ur JOIN {roles} r ON r.id = ur.role_id JOIN {entities} e ON e.id = ur.entity_id
             WHERE ur.user_id = ? ORDER BY e.code = ? DESC, ur.id LIMIT 1',
            [$userId, self::SECRETARIAT]
        ));
    }

    /** The first active user holding a role, e.g. who a journal is submitted to. */
    public function holderOf(string $role): ?int
    {
        $id = $this->value(
            "SELECT u.id FROM {users} u JOIN {user_entity_roles} ur ON ur.user_id = u.id JOIN {roles} r ON r.id = ur.role_id
             WHERE r.name = ? AND u.status = 'active' ORDER BY u.id LIMIT 1",
            [$role]
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
     * (General, Capital, Endowment) is that fund. "Grant Fund" is one of several,
     * chosen by, in order: the grant on the line; for income and expenditure lines
     * the funder recorded on the account; the programme's grant fund; the
     * account's funder.
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

    /** @return array<string, array> bank and cash accounts by GL code */
    public function bankAccounts(): array
    {
        return $this->cached('bank-accounts', fn () => array_column($this->rows(
            'SELECT b.*, a.code FROM {bank_accounts} b JOIN {accounts} a ON a.id = b.account_id ORDER BY a.code'
        ), null, 'code'));
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
