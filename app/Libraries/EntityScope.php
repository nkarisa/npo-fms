<?php

namespace App\Libraries;

use Config\Auth;

/**
 * Which entity's books the person signed in is working in: the entity picker at
 * the top of every page.
 *
 * Someone holding a role at one entity only ever sees that entity. Someone holding
 * roles at several picks one at a time; the choice is kept in the session and
 * remembered on the user (users.last_entity_id) for their next sign-in. Someone
 * holding a role at every entity may also pick the consolidated view, which reads
 * all of them together and changes nothing (App\Filters\SignedIn refuses writes).
 *
 * Every repository query is limited to the entities chosen here (see
 * Repository::sql()), so a record of another entity is not found rather than
 * merely hidden from a list. Outside a signed-in request — migrations, seeders,
 * spark commands, the sign-in screens — nothing is limited.
 */
final class EntityScope
{
    /** Session key: the chosen entity id, or CONSOLIDATED. */
    public const SESSION = 'auth_entity';
    public const CONSOLIDATED = 'all';

    /**
     * Tables holding one entity's records, and the condition that keeps a read to
     * the entities in scope. Settings, approval rules, statement formats and the
     * M-Pesa integration are the organisation's, held on the head office, and are
     * not here; nor are the chart, funds, programmes, suppliers and funders, which
     * every entity shares. Line tables follow the document they belong to.
     */
    public const TABLES = [
        'advances', 'asset_additions', 'assets', 'bank_accounts', 'bills', 'budget_versions',
        'cashflow_forecasts', 'conversion_batches', 'depreciation_runs', 'donor_reports', 'fiscal_years',
        'fund_transfers', 'goods_received_notes', 'grants', 'invoices', 'journals', 'legacy_balances', 'locations', 'notifications',
        'payment_runs', 'payments', 'payroll_runs', 'periods', 'purchase_orders', 'receipts', 'receivable_allowances',
        'recurring_templates', 'requisitions', 'staff', 'verification_rounds', 'wht_remittances', 'v_budget_availability',
        // A history entry written without an entity (signing in, user access) belongs to no entity's books.
        'audit_events',
    ];

    /** Tables whose rows may carry no entity and are then read in every scope. */
    private const NULL_ALLOWED = ['audit_events'];

    /** @var array{key: string, scope: array}|null */
    private static ?array $resolved = null;

    private static int $suspended = 0;

    /** Drops what was resolved, e.g. after the user's roles change or between tests. */
    public static function forget(): void
    {
        self::$resolved = null;
    }

    /**
     * The entity ids reads are limited to, or null when nothing is limited.
     *
     * @return list<int>|null
     */
    public static function ids(): ?array
    {
        return self::$suspended > 0 ? null : self::scope()['ids'];
    }

    /**
     * The entity records are written to and whose calendar is read: the one chosen,
     * or the head office in the consolidated view. Null outside a signed-in request.
     */
    public static function activeId(): ?int
    {
        return self::scope()['active'];
    }

    public static function consolidated(): bool
    {
        return self::scope()['consolidated'];
    }

    /**
     * What the picker offers the acting user: the entities they hold a role at, and
     * the consolidated view when they hold one at every entity.
     *
     * @return array{options: list<array{value: string, code: string, name: string, current: bool}>, consolidated: bool, canConsolidate: bool}
     */
    public static function picker(): array
    {
        $scope = self::scope();
        $options = [];
        foreach ($scope['held'] as $e) {
            $options[] = [
                'value' => (string) $e['id'], 'code' => $e['code'], 'name' => $e['name'],
                'current' => !$scope['consolidated'] && (int) $e['id'] === $scope['active'],
            ];
        }
        if ($scope['canConsolidate']) {
            $options[] = ['value' => self::CONSOLIDATED, 'code' => self::CONSOLIDATED, 'name' => 'Consolidated', 'current' => $scope['consolidated']];
        }

        return ['options' => $options, 'consolidated' => $scope['consolidated'], 'canConsolidate' => $scope['canConsolidate']];
    }

    /**
     * Switches the acting user to an entity they hold a role at (by id or code), or
     * to the consolidated view. Returns false when the choice is not theirs to make.
     */
    public static function choose(string $choice): bool
    {
        $scope = self::scope();
        if ($scope['userId'] === null) {
            return false;
        }

        if ($choice === self::CONSOLIDATED) {
            if (!$scope['canConsolidate']) {
                return false;
            }
            session()->set(self::SESSION, self::CONSOLIDATED);
            self::forget();

            return true;
        }

        foreach ($scope['held'] as $e) {
            if ($choice === (string) $e['id'] || strcasecmp($choice, $e['code']) === 0) {
                session()->set(self::SESSION, (int) $e['id']);
                $db = db_connect();
                $db->table('users')->where('id', $scope['userId'])->update(['last_entity_id' => (int) $e['id']]);
                self::forget();

                return true;
            }
        }

        return false;
    }

    /**
     * Runs a read across every entity whatever is chosen: a check that has to see
     * the whole organisation, such as whether an asset tag or a supplier's invoice
     * number is already taken.
     */
    public static function across(callable $read): mixed
    {
        self::$suspended++;
        try {
            return $read();
        } finally {
            self::$suspended--;
        }
    }

    /**
     * The condition that keeps a read of $table to the entities in scope, or null
     * when the table is not an entity's or nothing is limited.
     */
    public static function condition(string $table): ?string
    {
        if (!in_array($table, self::TABLES, true) || ($ids = self::ids()) === null) {
            return null;
        }
        $in = 'entity_id IN (' . implode(', ', $ids) . ')';

        return in_array($table, self::NULL_ALLOWED, true) ? '(' . $in . ' OR entity_id IS NULL)' : $in;
    }

    // ------------------------------------------------------------------

    /**
     * @return array{ids: list<int>|null, active: int|null, consolidated: bool, canConsolidate: bool, held: list<array>, userId: int|null}
     */
    private static function scope(): array
    {
        $signedIn = isset($_SESSION) && ($_SESSION[SignIn::STAGE] ?? null) === SignIn::DONE && isset($_SESSION[SignIn::USER]);
        $key = $signedIn ? $_SESSION[SignIn::USER] . '|' . self::actAsCookie() . '|' . json_encode($_SESSION[self::SESSION] ?? null) : '';
        if (self::$resolved !== null && self::$resolved['key'] === $key) {
            return self::$resolved['scope'];
        }

        $userId = $signedIn ? self::actingUserId() : null;
        $scope = $userId === null ? self::none() : self::resolve($userId);
        self::$resolved = ['key' => $key, 'scope' => $scope];

        return $scope;
    }

    private static function none(): array
    {
        return ['ids' => null, 'active' => null, 'consolidated' => false, 'canConsolidate' => false, 'held' => [], 'userId' => null];
    }

    private static function resolve(int $userId): array
    {
        $db = db_connect();
        $t = static fn (string $table) => $db->prefixTable($table);

        $held = $db->query(
            'SELECT DISTINCT e.id, e.code, e.name, e.parent_id FROM ' . $t('user_entity_roles') . ' ur JOIN ' . $t('entities') . ' e ON e.id = ur.entity_id'
            . ' WHERE ur.user_id = ? ORDER BY e.parent_id IS NOT NULL, e.id',
            [$userId]
        )->getResultArray();
        if ($held === []) {
            // No role anywhere: nothing to see. The SignedIn filter ends such a session.
            return ['ids' => [0], 'active' => null, 'consolidated' => false, 'canConsolidate' => false, 'held' => [], 'userId' => $userId];
        }

        $heldIds = array_map('intval', array_column($held, 'id'));
        $allIds = array_map('intval', array_column($db->query('SELECT id FROM ' . $t('entities') . ' ORDER BY id')->getResultArray(), 'id'));
        $headOffice = (int) ($db->query('SELECT id FROM ' . $t('entities') . ' WHERE parent_id IS NULL ORDER BY id LIMIT 1')->getRowArray()['id'] ?? $heldIds[0]);
        $canConsolidate = count($allIds) > 1 && array_diff($allIds, $heldIds) === [];

        $chosen = $_SESSION[self::SESSION] ?? null;
        if ($chosen === self::CONSOLIDATED && $canConsolidate) {
            return ['ids' => $allIds, 'active' => $headOffice, 'consolidated' => true, 'canConsolidate' => true, 'held' => $held, 'userId' => $userId];
        }

        // The choice made in this session; else the one made last time; else the
        // head office; else the first entity the user holds a role at.
        $remembered = $db->query('SELECT last_entity_id FROM ' . $t('users') . ' WHERE id = ?', [$userId])->getRowArray()['last_entity_id'] ?? null;
        $active = $heldIds[0];
        foreach ([$chosen, $remembered, $headOffice] as $candidate) {
            if ($candidate !== null && ctype_digit((string) $candidate) && in_array((int) $candidate, $heldIds, true)) {
                $active = (int) $candidate;
                break;
            }
        }

        return ['ids' => [$active], 'active' => $active, 'consolidated' => false, 'canConsolidate' => $canConsolidate, 'held' => $held, 'userId' => $userId];
    }

    /**
     * Whoever is acting in this request: the person signed in, or on a training
     * instance the person they have chosen to act as. Null before sign-in is
     * complete, and outside a web request.
     */
    private static function actingUserId(): int
    {
        $id = (int) $_SESSION[SignIn::USER];

        if (($email = self::actAsCookie()) !== '') {
            $db = db_connect();
            $other = $db->query(
                'SELECT u.id FROM ' . $db->prefixTable('users') . " u WHERE u.email = ? AND u.status = 'active'"
                . ' AND EXISTS (SELECT 1 FROM ' . $db->prefixTable('user_entity_roles') . ' ur WHERE ur.user_id = u.id)',
                [$email]
            )->getRowArray();
            if ($other !== null) {
                return (int) $other['id'];
            }
        }

        return $id;
    }

    /** The "Act as" choice on a training instance, or ''. */
    private static function actAsCookie(): string
    {
        if (!config(Auth::class)->actAs) {
            return '';
        }
        $request = service('request');

        return method_exists($request, 'getCookie') ? (string) $request->getCookie('elog_actor') : '';
    }
}
