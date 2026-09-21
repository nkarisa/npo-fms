<?php

namespace App\Repositories;

use App\Libraries\Brand;
use App\Libraries\Clock;
use App\Libraries\EntityCalendar;
use App\Libraries\Prototype;
use App\Libraries\Theme;

/**
 * Organisation, ledger, segment, currency, approval, payroll, user and language
 * settings, and the log of changes to them.
 *
 * The screen edits a draft and saves it in one go: save() compares what it is
 * sent with what is held, applies every change in one transaction, and writes
 * each change to the audit log in words ("Payment runs threshold raised from
 * 1,500,000 to 2,000,000"). A change that would break a control is refused with
 * the reason, and nothing from that save is applied.
 */
final class SettingsRepository extends Repository
{
    /** approval_rules.document_type → the screen's key. */
    private const APPROVAL_KEYS = ['journal' => 'journal', 'bill' => 'bill', 'payment_run' => 'payment',
        'subgrant' => 'subgrant', 'transfer' => 'transfer', 'budget_revision' => 'revision'];

    public const FRAMEWORKS = ['IFRS', 'IPSAS', 'Kenyan GAAP'];

    public const YEAR_ENDS = ['31 December', '30 June', '30 September'];

    public const CODE_LENGTHS = ['4 digits', '5 digits', '6 digits'];

    public const BASES = ['pct', 'flat'];

    /**
     * The procurement threshold, held on the head office's settings: above it a
     * purchase needs three quotations, and a bill captured straight into payables is
     * paid only to a pre-qualified supplier. A database set up before it was a
     * setting has no row, and reads the default.
     */
    public const QUOTE_THRESHOLD_KEY = 'quoteThreshold';

    public const QUOTE_THRESHOLD_DEFAULT = 500000;

    public const QUOTE_THRESHOLD_LABEL = 'Three quotations and a pre-qualified supplier required above';

    /** The rule every approval band carries, and the separations the ledger enforces. */
    public const SOD_RULES = [
        'A journal preparer can never approve their own entry, whatever its value.',
        'Bill coding, approval and payment release are three separate permissions and cannot be held together.',
        'Inter-fund transfers and sub-grants always need the Executive Director, regardless of amount.',
        'Auditors hold read-only access and cannot post, approve or change configuration.',
    ];

    /** Held on the setting row so the seeded database and the screen say the same thing. */
    public const THEME_NOTE = 'The colours the interface is drawn in. It changes what everyone reads on screen — never a figure, a code or a date.';

    /** What an entity may be. One head office, any number of the other two. */
    public const ENTITY_TYPES = ['Head office', 'Branch', 'Related trust'];

    private Lookups $lookups;

    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        parent::__construct($db);
        $this->lookups = new Lookups();
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /** The head office's registered details. */
    public function organisation(): array
    {
        $e = $this->headOffice();

        return [
            'registeredName' => (string) $e['registered_name'], 'shortName' => (string) $e['short_name'],
            'taxPin' => (string) $e['tax_pin'], 'ngoReg' => (string) $e['registration_no'],
        ];
    }

    /**
     * The entities that make up the organisation, the head office first.
     *
     * Each carries its posting count, because that is what decides whether its
     * functional currency can still be changed, and the screen says so rather than
     * letting someone find out by being refused.
     */
    public function entities(): array
    {
        $posted = array_column($this->rows(
            "SELECT entity_id, COUNT(*) AS n FROM {journals} WHERE status IN ('posted', 'reversed') GROUP BY entity_id"
        ), 'n', 'entity_id');

        return array_map(static fn ($e) => [
            'code' => $e['code'], 'name' => $e['name'], 'type' => $e['type'], 'currency' => $e['functional_currency'],
            'status' => ucfirst($e['status']), 'head' => $e['type'] === self::ENTITY_TYPES[0],
            'postings' => (int) ($posted[$e['id']] ?? 0),
        ], $this->rows('SELECT * FROM {entities} ORDER BY id'));
    }

    /**
     * The funds every posting is coded to, with how many each carries.
     *
     * A fund is opened here rather than drafted with the rest of the screen: it is a
     * coding dimension the ledger refers to, not a setting, and nothing can be coded
     * to it until it exists.
     */
    public function funds(): array
    {
        return $this->cached('funds', function () {
            $postings = array_column($this->rows(
                "SELECT l.fund_id, COUNT(*) AS n FROM {journal_lines} l JOIN {journals} j ON j.id = l.journal_id
                 WHERE j.status IN ('posted', 'reversed') GROUP BY l.fund_id"
            ), 'n', 'fund_id');

            return array_map(static fn ($f) => [
                'code' => $f['code'], 'name' => $f['name'], 'restriction' => ucfirst($f['restriction']),
                'group' => self::FUND_GROUPS[$f['ledger_group']], 'funder' => $f['funder'] ?? '',
                'status' => ucfirst($f['status']), 'purpose' => $f['purpose'] ?? '',
                'postings' => (int) ($postings[$f['id']] ?? 0),
            ], $this->rows('SELECT f.*, fu.name AS funder FROM {funds} f LEFT JOIN {funders} fu ON fu.id = f.funder_id ORDER BY f.code'));
        });
    }

    /** What a new fund may be, and the funders one can be held for. */
    public function fundOptions(): array
    {
        return [
            'restrictions' => array_map('ucfirst', FundRepository::RESTRICTIONS),
            'groups'       => array_values(self::FUND_GROUPS),
            'funders'      => array_column($this->rows('SELECT name FROM {funders} ORDER BY name'), 'name'),
        ];
    }

    /** @return list<array{code: string, name: string}> entities a user can be given access to */
    public function entityOptions(): array
    {
        return array_map(static fn ($e) => ['code' => $e['code'], 'name' => $e['name']], $this->rows("SELECT code, name FROM {entities} WHERE status = 'live' ORDER BY id"));
    }

    /** The reporting basis. */
    public function ledger(): array
    {
        $choices = array_column($this->settingRows('choice'), 'value', 'key');

        return [
            'framework' => $choices['framework'] ?? self::FRAMEWORKS[0], 'currency' => $this->headOffice()['functional_currency'],
            'yearEnd' => $choices['yearEnd'] ?? self::YEAR_ENDS[0], 'codeLength' => $choices['codeLength'] ?? self::CODE_LENGTHS[0],
        ];
    }

    /** The months of the working year and whether each is open for posting. */
    public function periods(): array
    {
        return array_map(static fn ($p) => ['label' => $p['name'], 'open' => $p['status'] === 'open'], $this->lookups->yearPeriods());
    }

    /** Posting controls. */
    public function toggles(): array
    {
        return array_map(static fn ($s) => ['key' => $s['key'], 'label' => $s['label'], 'note' => $s['note'] ?? '', 'on' => $s['value'] === '1'], $this->settingRows('toggle'));
    }

    /**
     * How the shell is branded and painted: the name it carries, the logo, the
     * theme and the two colours the custom theme is built from.
     *
     * One read serves the page shell and the API alike, which is why the name and
     * the theme are fetched together rather than a query each.
     */
    public function appearance(): array
    {
        return $this->cached('appearance', function () {
            $held = array_column($this->settingRows('appearance'), 'value', 'key');
            $custom = json_decode($held[Theme::CUSTOM_KEY] ?? '', true);
            $colour = static fn (string $part) => Theme::colour((string) ($custom[$part] ?? '')) ?? Theme::CUSTOM_DEFAULT[$part];

            return [
                'theme'      => Theme::isKnown($held[Theme::KEY] ?? null) ? (string) $held[Theme::KEY] : Theme::DEFAULT,
                'custom'     => ['accent' => $colour('accent'), 'rail' => $colour('rail')],
                'appName'    => trim((string) ($held[Brand::NAME_KEY] ?? '')) ?: Brand::DEFAULT_NAME,
                'appTagline' => (string) ($held[Brand::TAGLINE_KEY] ?? Brand::DEFAULT_TAGLINE),
                'logo'       => (string) ($held[Brand::LOGO_KEY] ?? ''),
            ];
        });
    }

    /** The interface theme the organisation reads the shell in. */
    public function theme(): string
    {
        return $this->appearance()['theme'];
    }

    public function formatsLocked(): bool
    {
        return $this->cached('formats-locked', fn () => ($this->value(
            "SELECT s.value FROM {settings} s JOIN {entities} e ON e.id = s.entity_id WHERE e.code = ? AND s.key = 'formatsLocked'",
            [$this->lookups->headOfficeCode()]
        ) ?? '1') === '1');
    }

    /** Segments with how many values each currently has. */
    public function segments(): array
    {
        return $this->cached('segments', function () {
            $counts = [
                'fund'        => $this->value("SELECT COUNT(*) FROM {funds} WHERE status = 'active'"),
                'program'     => $this->value("SELECT COUNT(*) FROM {programmes} WHERE status <> 'inactive'"),
                'restriction' => $this->value('SELECT COUNT(DISTINCT restriction) FROM {funds}'),
                'grant'       => $this->value('SELECT COUNT(*) FROM {grants}'),
                'funder'      => $this->value('SELECT COUNT(DISTINCT funder_id) FROM {grants}'),
                'county'      => $this->value('SELECT COUNT(*) FROM {counties}'),
            ];

            return array_map(static fn ($s) => [
                'key' => $s['key'], 'name' => $s['name'], 'example' => $s['example'] ?? '', 'count' => (int) ($counts[$s['key']] ?? 0),
                'required' => (bool) $s['is_required'], 'reported' => (bool) $s['is_reported'], 'applies' => $s['applies_to'],
            ], $this->rows('SELECT * FROM {segments} ORDER BY id'));
        });
    }

    /**
     * Currencies awards and claims may be stated in, the functional currency first.
     *
     * @return list<array{code: string, name: string, rate: string, active: bool, base: bool, claims: int, awards: int}>
     */
    public function currencies(): array
    {
        return $this->cached('currencies', function () {
            $base = $this->headOffice()['functional_currency'];
            $claims = array_column($this->rows("SELECT currency, COUNT(*) AS n FROM {invoices} WHERE status IN ('draft', 'issued', 'part_received') GROUP BY currency"), 'n', 'currency');
            $awards = array_column($this->rows('SELECT currency, COUNT(*) AS n FROM {grants} GROUP BY currency'), 'n', 'currency');
            $rows = array_map(static fn ($c) => [
                'code' => $c['code'], 'name' => $c['name'], 'rate' => number_format((float) $c['indicative_rate'], 2, '.', ''),
                'active' => (bool) $c['is_active'], 'base' => $c['code'] === $base,
                'claims' => (int) ($claims[$c['code']] ?? 0), 'awards' => (int) ($awards[$c['code']] ?? 0),
            ], $this->rows('SELECT * FROM {currencies} ORDER BY id'));
            usort($rows, static fn ($a, $b) => $b['base'] <=> $a['base']);

            return $rows;
        });
    }

    /** The active currencies and their indicative rates, for new awards and claims. */
    public function activeRates(): array
    {
        return array_map(static fn ($c) => (float) $c['rate'], array_column(array_filter($this->currencies(), static fn ($c) => $c['active']), null, 'code'));
    }

    public function approvals(): array
    {
        return array_map(static fn ($a) => [
            'key' => self::APPROVAL_KEYS[$a['document_type']] ?? $a['document_type'], 'label' => $a['label'], 'threshold' => self::num($a['threshold']),
            'approver' => $a['approver'], 'escalation' => $a['escalation'] ?? $a['escalation_note'] ?? '—',
        ], $this->rows(
            'SELECT ar.*, r.name AS approver, e.name AS escalation FROM {approval_rules} ar JOIN {roles} r ON r.id = ar.approver_role_id
             LEFT JOIN {roles} e ON e.id = ar.escalation_role_id WHERE ar.document_type IN (' . self::quoted(array_keys(self::APPROVAL_KEYS)) . ') ORDER BY ar.id'
        ));
    }

    /** The procurement controls shown with the approval bands. */
    public function procurement(): array
    {
        return ['quoteThreshold' => self::num($this->quoteThreshold()), 'label' => self::QUOTE_THRESHOLD_LABEL];
    }

    public function quoteThreshold(): float
    {
        return $this->cached('quote-threshold', fn () => (float) ($this->value(
            'SELECT s.value FROM {settings} s JOIN {entities} e ON e.id = s.entity_id WHERE e.code = ? AND s.key = ?',
            [$this->lookups->headOfficeCode(), self::QUOTE_THRESHOLD_KEY]
        ) ?? self::QUOTE_THRESHOLD_DEFAULT));
    }

    /**
     * Payment terms and reminder windows, in days: key => [label, standard value,
     * what it decides]. Changed in Settings → Terms and reminders.
     */
    public const DAY_RULES = [
        'supplierTerms'       => ['Supplier payment terms offered', '14, 30, 45, 60', 'The terms a bill can be captured on. A bill raised from goods received takes 30 days when it is offered, otherwise the shortest.'],
        'claimTermsDays'      => ['A donor claim falls due after', 30, 'Days from issue; the ageing and the expected receipts work from it.'],
        'advanceRecoveryDays' => ['An unsurrendered advance is recovered from pay after', 14, 'Days past the surrender date before the balance can be taken from payroll.'],
        'prequalWarningDays'  => ['A supplier shows as expiring', 30, 'Days before its pre-qualification lapses.'],
        'reportWarningDays'   => ['A donor report is flagged as due', 45, 'Days before its deadline, on the grants page and the reporting calendar.'],
        'trancheWarningDays'  => ['A grant tranche shows as due', 30, 'Days before it is expected.'],
    ];

    /** One of the day rules, as held or at its standard value. */
    public static function day(string $key): int
    {
        return (int) (new self())->dayRule($key);
    }

    /** @return list<int> the payment terms a bill may carry, shortest first */
    public static function supplierTerms(): array
    {
        return self::termList((string) (new self())->dayRule('supplierTerms'));
    }

    /** What a bill raised without a choice of terms is given: 30 days when offered, otherwise the shortest. */
    public static function defaultSupplierTerms(): int
    {
        $terms = self::supplierTerms();

        return in_array(30, $terms, true) ? 30 : $terms[0];
    }

    /** The rules with their values, for the screen. */
    public function dayRules(): array
    {
        return array_map(fn (string $key) => [
            'key' => $key, 'label' => self::DAY_RULES[$key][0], 'note' => self::DAY_RULES[$key][2],
            'value' => (string) $this->dayRule($key), 'standard' => (string) self::DAY_RULES[$key][1],
        ], array_keys(self::DAY_RULES));
    }

    private function dayRule(string $key): string|int
    {
        $standard = self::DAY_RULES[$key][1] ?? throw new \InvalidArgumentException('No day rule ' . $key . '.');

        return $this->cached('day:' . $key, fn () => $this->value(
            'SELECT s.value FROM {settings} s JOIN {entities} e ON e.id = s.entity_id WHERE e.code = ? AND s.key = ?',
            [$this->lookups->headOfficeCode(), $key]
        ) ?? $standard);
    }

    /** "14, 30, 45, 60" as whole days, shortest first. */
    private static function termList(string $text): array
    {
        $terms = array_map('intval', preg_split('/[^0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY));
        sort($terms);

        return array_values(array_unique($terms));
    }

    /** Roles a user can hold — every role, in the order they were set up. Settings → Roles defines them. */
    public function roles(): array
    {
        return array_column($this->rows('SELECT r.name FROM {roles} r ORDER BY r.id'), 'name');
    }

    /** Roles that can approve, and so can be named as an approver. */
    public function approverRoles(): array
    {
        return array_column($this->rows(
            "SELECT r.name FROM {roles} r WHERE EXISTS (SELECT 1 FROM {role_permissions} rp JOIN {permissions} p ON p.id = rp.permission_id
             WHERE rp.role_id = r.id AND p.key = 'journal.approve') ORDER BY r.id"
        ), 'name');
    }

    /** Benefits paid on top of basic, and how many staff are paid each today. */
    public function benefits(): array
    {
        $paid = array_column($this->rows(
            'SELECT i.pay_component_id, COUNT(DISTINCT i.staff_id) AS n FROM {staff_pay_items} i JOIN {staff} s ON s.id = i.staff_id
             WHERE i.amount > 0 AND i.effective_from <= ? AND (i.effective_to IS NULL OR i.effective_to >= ?) AND (s.left_on IS NULL OR s.left_on >= ?)
             GROUP BY i.pay_component_id',
            [Clock::date(), Clock::date(), Clock::date()]
        ), 'n', 'pay_component_id');

        return array_map(static fn ($c) => [
            'key' => $c['key'], 'name' => $c['name'], 'basis' => $c['basis'] ?? 'flat', 'taxable' => (bool) $c['is_taxable'],
            'active' => (bool) $c['is_active'], 'paidTo' => (int) ($paid[$c['id']] ?? 0),
        ], $this->rows('SELECT * FROM {pay_components} WHERE is_benefit = 1 ORDER BY id'));
    }

    /**
     * Where each pay component posts, and what a run is still missing.
     *
     * A newly installed instance has the components but no chart, so nothing is
     * mapped and payroll has nowhere to post. This is where that is set.
     */
    public function payAccounts(): array
    {
        $needed = PayrollRepository::POSTING_COMPONENTS;

        return array_map(static fn ($c) => [
            'key' => $c['key'], 'name' => $c['name'], 'kind' => ucfirst($c['kind']),
            'code' => $c['code'] ?? '', 'required' => in_array($c['key'], $needed, true),
        ], $this->rows(
            'SELECT c.key, c.name, c.kind, a.code FROM {pay_components} c LEFT JOIN {accounts} a ON a.id = c.account_id
             WHERE c.is_active = 1 ORDER BY c.id'
        ));
    }

    /** Postable accounts a pay component can be charged or credited to. */
    public function payAccountOptions(): array
    {
        return array_map(static fn ($a) => ['code' => $a['code'], 'name' => $a['name']], $this->rows(
            "SELECT code, name FROM {accounts} WHERE is_leaf = 1 AND status = 'active'
             AND type IN ('expense', 'liability', 'asset') ORDER BY code"
        ));
    }

    /** The grade scale, with each benefit's value and how many staff hold the grade. */
    public function grades(): array
    {
        $ben = [];
        foreach ($this->rows('SELECT gb.pay_grade_id, gb.amount, c.key FROM {pay_grade_benefits} gb JOIN {pay_components} c ON c.id = gb.pay_component_id') as $b) {
            $ben[(int) $b['pay_grade_id']][$b['key']] = self::num($b['amount']);
        }
        $held = array_column($this->rows('SELECT grade, COUNT(*) AS n FROM {staff} WHERE left_on IS NULL OR left_on >= ? GROUP BY grade', [Clock::date()]), 'n', 'grade');

        return array_map(static fn ($g) => [
            'grade' => $g['code'], 'band' => $g['title'], 'ben' => (object) ($ben[(int) $g['id']] ?? []), 'active' => (bool) $g['is_active'],
            'staff' => (int) ($held[$g['code']] ?? 0),
        ], $this->rows('SELECT * FROM {pay_grades} ORDER BY sort_order, id'));
    }

    public function users(): array
    {
        $entityCount = (int) $this->value('SELECT COUNT(*) FROM {entities}');
        $access = new RoleRepository($this->db);

        return array_map(function ($u) use ($entityCount, $access) {
            $entities = explode('|', (string) $u['entity_names']);
            $words = array_map(static fn ($n) => match (true) {
                str_contains($n, 'Secretariat') => 'Secretariat',
                str_contains($n, 'Trust')       => 'Trust',
                default                         => trim(preg_replace('/^ELOG | (Regional )?Office$/', '', $n)),
            }, $entities);
            $signIn = UserRepository::lastSignIn($u);

            $held = $access->access((int) $u['id']);

            return [
                'id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'initials' => $u['initials'], 'role' => $this->lookups->roleOf((int) $u['id']),
                // Every role the person holds, and where; `role` above is the one named on their approvals.
                'roles' => array_column($held, 'role'), 'access' => $held,
                'entities' => count($entities) === $entityCount ? 'All entities' : implode(', ', $words),
                'lastActive' => $signIn === '—' ? '—' : lcfirst(trim(explode('·', $signIn)[0])), 'status' => ucfirst($u['status']),
                'mfa' => (bool) $u['mfa_enabled'] ? (string) $u['mfa_method'] : null,
                'locked' => $u['locked_until'] !== null && strtotime($u['locked_until']) > time(),
                // An active user can be without one too: someone added before sign-in existed.
                'hasPassword' => ($u['password_hash'] ?? '') !== '',
            ];
        }, $this->withEntityNames());
    }

    /** Changes to settings and controls, newest first. */
    public function auditLog(): array
    {
        return $this->cached('audit', fn () => array_map(fn ($e) => [
            'when' => date('d M H:i', strtotime($e['occurred_at'])), 'who' => $this->lookups->shortName($e['actor_user_id'] === null ? null : (int) $e['actor_user_id']),
            'what' => $e['summary'] ?? '', 'area' => ucfirst(substr((string) strstr($e['object_type'], ':'), 1)),
        ], $this->rows("SELECT * FROM {all:audit_events} WHERE action = 'settings.changed' ORDER BY occurred_at DESC, id DESC")));
    }

    // ------------------------------------------------------------------
    // Saving
    // ------------------------------------------------------------------

    /**
     * Applies the sections of the draft that differ from what is held.
     *
     * @param array $draft any of: organisation, ledger, toggles, segments, currencies,
     *        approvals, payroll {benefits, grades}, users {email: role}, language {formatsLocked}
     * @return list<array{area: string, what: string}> the changes made, as the audit log records them
     */
    public function save(array $draft, int $actorId): array
    {
        $changes = [];
        $writes = [];
        $plan = static function (string $area, string $what, ?callable $write = null) use (&$changes, &$writes): void {
            $changes[] = ['area' => $area, 'what' => $what];
            if ($write !== null) {
                $writes[] = $write;
            }
        };

        // Everything is checked before anything is written.
        if (isset($draft['organisation'])) {
            $this->planOrganisation((array) $draft['organisation'], $plan);
        }
        if (isset($draft['ledger'])) {
            $this->planLedger((array) $draft['ledger'], $plan);
        }
        if (isset($draft['toggles'])) {
            $this->planToggles((array) $draft['toggles'], $plan);
        }
        if (isset($draft['segments'])) {
            $this->planSegments((array) $draft['segments'], $plan);
        }
        if (isset($draft['currencies'])) {
            $this->planCurrencies((array) $draft['currencies'], $plan);
        }
        if (isset($draft['approvals'])) {
            $this->planApprovals((array) $draft['approvals'], $plan);
        }
        if (isset($draft['procurement'])) {
            $this->planProcurement((array) $draft['procurement'], $plan);
        }
        if (isset($draft['taxes'])) {
            $this->planTaxes((array) $draft['taxes'], $plan);
        }
        if (isset($draft['days'])) {
            $this->planDays((array) $draft['days'], $plan);
        }
        if (isset($draft['postingAccounts'])) {
            $this->planPostingAccounts((array) $draft['postingAccounts'], $plan);
        }
        if (isset($draft['payroll'])) {
            $this->planPayroll((array) $draft['payroll'], $plan);
        }
        if (isset($draft['payAccounts'])) {
            $this->planPayAccounts((array) $draft['payAccounts'], $plan);
        }
        if (isset($draft['users'])) {
            $this->planUsers((array) $draft['users'], $plan);
        }
        if (isset($draft['entities'])) {
            $this->planEntities((array) $draft['entities'], $plan);
        }
        if (isset($draft['appearance'])) {
            $this->planAppearance((array) $draft['appearance'], $plan);
        }
        if (isset($draft['language']['formatsLocked'])) {
            $locked = (bool) $draft['language']['formatsLocked'];
            if ($locked !== $this->formatsLocked()) {
                $plan('Language', $locked ? 'Numbers, dates and currency held in the reporting locale (en-KE · KES)' : 'Numbers, dates and currency released to each user\'s locale',
                    fn () => $this->setSetting('formatsLocked', $locked ? '1' : '0'));
            }
        }

        if ($changes === []) {
            return [];
        }

        $this->transaction(function () use ($writes, $changes, $actorId) {
            foreach ($writes as $write) {
                $write();
            }
            $this->assertSettingsManaged();
            foreach ($changes as $c) {
                $this->logChange($c['area'], $c['what'], $actorId);
            }
        });

        return $changes;
    }

    /**
     * Invites a user: they appear as Invited, with their roles at the entities named,
     * until they accept. The invitation email itself is sent by AuthRepository::invite().
     *
     * @param list<string>|string $roles    one role, or several
     * @param list<string>|string $entities entity codes, or 'all'
     */
    public function invite(string $name, string $email, array|string $roles, array|string $entities, int $actorId): array
    {
        $name = trim($name);
        $email = mb_strtolower(trim($email));
        if ($name === '') {
            throw new RuleViolation('Give the person\'s full name as it should appear on approvals.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuleViolation($email === '' ? 'Enter the email address the invitation goes to.' : $email . ' is not an email address.');
        }
        if ($this->value('SELECT id FROM {users} WHERE LOWER(email) = ?', [$email]) !== null) {
            throw new RuleViolation($email . ' already has an account.');
        }
        $roles = array_values(array_filter(array_map('trim', (array) $roles), static fn ($r) => $r !== ''));
        if ($roles === [] || array_diff($roles, $this->roles()) !== []) {
            throw new RuleViolation('Choose the role the person will hold.');
        }
        $all = $this->rows('SELECT id, code, name FROM {entities} ORDER BY id');
        $chosen = $entities === 'all' ? $all : array_values(array_filter($all, static fn ($e) => in_array($e['code'], (array) $entities, true)));
        if ($chosen === []) {
            throw new RuleViolation('Choose at least one entity the person can work in.');
        }
        $plan = (new RoleRepository($this->db))->planAccess(array_map(static fn ($r) => ['role' => $r, 'entities' => $entities === 'all' ? 'all' : (array) $entities], $roles));
        $role = implode(', ', $roles);

        [$first, $last] = explode(' ', $name, 2) + [1 => ''];
        $short = mb_substr($first, 0, 1) . '. ' . ($last !== '' ? $last : $first);
        $initials = mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last !== '' ? $last : $first, 0, 1));
        $now = Clock::timestamp();

        $this->transaction(function () use ($name, $email, $short, $initials, $chosen, $plan, $role, $entities, $actorId, $now) {
            $userId = $this->insert('users', [
                'email' => $email, 'name' => $name, 'short_name' => mb_substr($short, 0, 60), 'initials' => $initials,
                'locale_id' => $this->value("SELECT id FROM {locales} WHERE code = 'en-GB'"), 'status' => 'invited', 'invited_at' => $now, 'created_at' => $now,
            ]);
            (new RoleRepository($this->db))->writeAccess($userId, $plan);
            $this->logChange('Users', $short . ' invited as ' . $role . ($entities === 'all' ? ' across all entities' : ' — ' . implode(', ', array_column($chosen, 'name'))), $actorId);
        });

        return current(array_filter($this->users(), static fn ($u) => $u['email'] === $email));
    }

    // ---- Sections ----

    private function planOrganisation(array $in, callable $plan): void
    {
        $current = $this->organisation();
        $fields = ['registeredName' => ['registered_name', 'Registered name', 160], 'shortName' => ['short_name', 'Short name', 40],
            'taxPin' => ['tax_pin', 'KRA PIN', 20], 'ngoReg' => ['registration_no', 'NGO Board registration', 60]];

        foreach ($fields as $key => [$column, $label, $max]) {
            if (!array_key_exists($key, $in)) {
                continue;
            }
            $value = trim((string) $in[$key]);
            if ($value === $current[$key]) {
                continue;
            }
            if ($value === '' && in_array($key, ['registeredName', 'shortName'], true)) {
                throw new RuleViolation('The ' . strtolower($label) . ' cannot be blank — it prints on every statement and donor report.');
            }
            if (mb_strlen($value) > $max) {
                throw new RuleViolation('The ' . strtolower($label) . ' is longer than ' . $max . ' characters.');
            }
            if ($key === 'taxPin') {
                $value = strtoupper($value);
                if (preg_match('/^[AP]\d{9}[A-Z]$/', $value) !== 1) {
                    throw new RuleViolation($value . ' is not a KRA PIN. A PIN is a letter, nine digits and a letter, such as P051290384H.');
                }
                if ($value === $current[$key]) {
                    continue;
                }
            }
            $plan('Organisation', $label . ' changed from ' . ($current[$key] !== '' ? $current[$key] : 'blank') . ' to ' . $value,
                fn () => $this->db->table('entities')->where('id', $this->headOffice()['id'])->update([$column => $value, 'updated_at' => Clock::timestamp()]));
        }
    }

    private function planLedger(array $in, callable $plan): void
    {
        $current = $this->ledger();

        if (isset($in['framework']) && $in['framework'] !== $current['framework']) {
            if (!in_array($in['framework'], self::FRAMEWORKS, true)) {
                throw new RuleViolation($in['framework'] . ' is not a reporting framework the statements are prepared under.');
            }
            $plan('Ledger', 'Reporting framework changed from ' . $current['framework'] . ' to ' . $in['framework'], fn () => $this->setSetting('framework', $in['framework']));
        }

        if (isset($in['currency']) && $in['currency'] !== $current['currency']) {
            $posted = (int) $this->value("SELECT COUNT(*) FROM {journals} WHERE status IN ('posted', 'reversed')");
            if ($posted > 0) {
                throw new RuleViolation('The functional currency cannot change once the ledger holds postings — ' . number_format($posted) . ' journals are posted in ' . $current['currency'] . '.');
            }
            if (!in_array($in['currency'], array_column($this->currencies(), 'code'), true)) {
                throw new RuleViolation($in['currency'] . ' is not on the currency list.');
            }
            $plan('Ledger', 'Functional currency changed from ' . $current['currency'] . ' to ' . $in['currency'],
                fn () => $this->db->table('entities')->update(['functional_currency' => $in['currency'], 'updated_at' => Clock::timestamp()]));
        }

        if (isset($in['yearEnd']) && $in['yearEnd'] !== $current['yearEnd']) {
            if (!in_array($in['yearEnd'], self::YEAR_ENDS, true)) {
                throw new RuleViolation($in['yearEnd'] . ' is not a financial year end the ledger supports.');
            }
            $plan('Ledger', 'Financial year end changed from ' . $current['yearEnd'] . ' to ' . $in['yearEnd'] . ', from the next financial year', fn () => $this->setSetting('yearEnd', $in['yearEnd']));
        }

        if (isset($in['codeLength']) && $in['codeLength'] !== $current['codeLength']) {
            if (!in_array($in['codeLength'], self::CODE_LENGTHS, true)) {
                throw new RuleViolation($in['codeLength'] . ' is not an account code length the chart supports.');
            }
            $digits = (int) $in['codeLength'];
            $off = (int) $this->value('SELECT COUNT(*) FROM {accounts} WHERE LENGTH(code) <> ?', [$digits]);
            if ($off > 0) {
                throw new RuleViolation(number_format($off) . ' accounts in the chart do not have ' . $digits . '-digit codes. Recode the chart before changing the code length.');
            }
            $plan('Ledger', 'Account code length changed from ' . $current['codeLength'] . ' to ' . $in['codeLength'], fn () => $this->setSetting('codeLength', $in['codeLength']));
        }
    }

    private function planToggles(array $in, callable $plan): void
    {
        foreach ($this->toggles() as $t) {
            if (!array_key_exists($t['key'], $in) || (bool) $in[$t['key']] === $t['on']) {
                continue;
            }
            $on = (bool) $in[$t['key']];
            $plan('Ledger', $t['label'] . ' — turned ' . ($on ? 'on' : 'off'), fn () => $this->setSetting($t['key'], $on ? '1' : '0'));
        }
    }

    private function planAppearance(array $in, callable $plan): void
    {
        $current = $this->appearance();

        if (isset($in['appName'])) {
            $name = trim((string) $in['appName']);
            if ($name === '') {
                throw new RuleViolation('The application needs a name — it is what the sidebar and the browser tab carry.');
            }
            if (mb_strlen($name) > Brand::MAX_NAME) {
                throw new RuleViolation('The name is longer than ' . Brand::MAX_NAME . ' characters, and the sidebar has room for about half that.');
            }
            if ($name !== $current['appName']) {
                $plan('Appearance', 'Application name changed from ' . $current['appName'] . ' to ' . $name,
                    fn () => $this->setAppearance(Brand::NAME_KEY, $name, 'Application name'));
            }
        }

        if (isset($in['appTagline'])) {
            $tagline = trim((string) $in['appTagline']);
            if (mb_strlen($tagline) > Brand::MAX_TAGLINE) {
                throw new RuleViolation('The line under the name is longer than ' . Brand::MAX_TAGLINE . ' characters.');
            }
            if ($tagline !== $current['appTagline']) {
                $plan('Appearance', $tagline === ''
                    ? 'Line under the application name removed'
                    : 'Line under the application name changed from ' . ($current['appTagline'] !== '' ? $current['appTagline'] : 'blank') . ' to ' . $tagline,
                    fn () => $this->setAppearance(Brand::TAGLINE_KEY, $tagline, 'Line under the application name'));
            }
        }

        if (isset($in['custom'])) {
            $this->planCustomTheme((array) $in['custom'], $current['custom'], $plan);
        }

        if (isset($in['theme']) && $in['theme'] !== $current['theme']) {
            $theme = (string) $in['theme'];
            if (!Theme::isKnown($theme)) {
                throw new RuleViolation($theme . ' is not one of the themes the interface is drawn in.');
            }
            $plan('Appearance', 'Interface theme changed from ' . Theme::name($current['theme']) . ' to ' . Theme::name($theme) . ' for everyone',
                fn () => $this->setAppearance(Theme::KEY, $theme, 'Interface theme', self::THEME_NOTE));
        }
    }

    /**
     * The two colours the custom theme is built from.
     *
     * Both carry light text — white on the accent, the menu labels on the rail —
     * so each is held to a contrast ratio rather than taken as given. A colour that
     * fails is refused with the ratio it reached and the one it needed, because
     * "too light" on its own tells nobody how much darker to go.
     */
    private function planCustomTheme(array $in, array $current, callable $plan): void
    {
        $labels = ['accent' => 'Custom accent colour', 'rail' => 'Custom menu colour'];

        foreach ($labels as $part => $label) {
            if (!isset($in[$part])) {
                continue;
            }
            $colour = Theme::colour((string) $in[$part]);
            if ($colour === null) {
                throw new RuleViolation($in[$part] . ' is not a colour. Give it as a hex value, such as #0F5C4A.');
            }
            if ($colour === $current[$part]) {
                continue;
            }
            $contrast = Theme::contrastWithWhite($colour);
            $least = Theme::MIN_CONTRAST[$part];
            if ($contrast < $least) {
                throw new RuleViolation(
                    $colour . ' is too light to carry white text — it reaches ' . $contrast . ':1 against white where '
                    . $least . ':1 is needed. Choose a darker shade.'
                );
            }
            $plan('Appearance', $label . ' changed from ' . $current[$part] . ' to ' . $colour,
                fn () => $this->setCustomTheme([$part => $colour] + $current));
            $current[$part] = $colour;
        }
    }

    /**
     * Adds and changes entities.
     *
     * An entity is never removed here. It is carried by every posting made against
     * it, so the way one leaves service is to be made dormant — it then keeps its
     * history and drops out of the lists that offer a choice.
     */
    private function planEntities(array $in, callable $plan): void
    {
        $held = array_column($this->entities(), null, 'code');
        $currencies = array_column(array_filter($this->currencies(), static fn ($c) => $c['active']), 'code');
        $names = [];
        foreach ($held as $e) {
            $names[mb_strtolower($e['name'])] = $e['code'];
        }

        foreach ($in as $row) {
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            $name = trim((string) ($row['name'] ?? ''));
            $type = trim((string) ($row['type'] ?? ''));
            $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
            $status = mb_strtolower(trim((string) ($row['status'] ?? 'live')));
            $current = $held[$code] ?? null;

            if ($name === '') {
                throw new RuleViolation('Name the entity as it should read on a consolidated statement.');
            }
            if (mb_strlen($name) > 120) {
                throw new RuleViolation('The entity name is longer than 120 characters.');
            }
            if (isset($names[mb_strtolower($name)]) && $names[mb_strtolower($name)] !== $code) {
                throw new RuleViolation($name . ' is already the name of another entity.');
            }
            if (!in_array($type, self::ENTITY_TYPES, true)) {
                throw new RuleViolation($type === '' ? 'Choose what kind of entity this is.' : $type . ' is not a kind of entity the consolidation understands.');
            }
            if (!in_array($currency, $currencies, true)) {
                throw new RuleViolation($currency === '' ? 'Choose the currency ' . $name . ' keeps its books in.' : $currency . ' is not an active currency.');
            }
            if (!in_array($status, ['live', 'dormant'], true)) {
                throw new RuleViolation($status . ' is not a status an entity can hold.');
            }
            $names[mb_strtolower($name)] = $code;

            if ($current === null) {
                $this->planNewEntity($code, $name, $type, $currency, $status, $plan);

                continue;
            }
            $this->planEntityChange($current, $name, $type, $currency, $status, $plan);
        }
    }

    private function planNewEntity(string $code, string $name, string $type, string $currency, string $status, callable $plan): void
    {
        if (preg_match('/^[A-Z0-9][A-Z0-9-]{1,19}$/', $code) !== 1) {
            throw new RuleViolation($code === ''
                ? 'Give ' . $name . ' a short code — it is what user access and imported files refer to it by.'
                : $code . ' is not an entity code. Use 2 to 20 letters, digits and hyphens, such as ELOG-RV.');
        }
        if ($type === self::ENTITY_TYPES[0]) {
            throw new RuleViolation('There is already a head office. A second one would leave the consolidation with two tops.');
        }

        $parentId = $this->headOffice()['id'];
        $plan('Organisation', $name . ' (' . $code . ') added as a ' . mb_strtolower($type) . ' reporting in ' . $currency . ($status === 'dormant' ? ', dormant' : ''),
            // With the organisation's calendar: nothing can be posted to an entity without periods.
            fn () => EntityCalendar::fill($this->db, $this->insert('entities', [
                'parent_id' => $parentId, 'code' => $code, 'name' => $name, 'type' => $type,
                'functional_currency' => $currency, 'status' => $status, 'created_at' => Clock::timestamp(),
            ])));

        // Whoever reaches every entity reaches the new one too, in the roles they hold
        // at the head office — "All entities" stays true, and so does their consolidated view.
        $everywhere = $this->rows(
            'SELECT ur.user_id FROM {user_entity_roles} ur GROUP BY ur.user_id HAVING COUNT(DISTINCT ur.entity_id) = (SELECT COUNT(*) FROM {entities})'
        );
        if ($everywhere !== []) {
            $userIds = array_map('intval', array_column($everywhere, 'user_id'));
            $plan('Users', count($userIds) . ' ' . (count($userIds) === 1 ? 'person' : 'people') . ' with access to all entities given access to ' . $name,
                fn () => $this->extendAccess($userIds, $code, $parentId));
        }
    }

    /** Gives each user the roles they hold at the head office at an entity just added. */
    private function extendAccess(array $userIds, string $code, int $headOfficeId): void
    {
        $entityId = (int) $this->value('SELECT id FROM {entities} WHERE code = ?', [$code]);
        $now = Clock::timestamp();
        foreach ($userIds as $userId) {
            foreach ($this->rows('SELECT DISTINCT role_id FROM {user_entity_roles} WHERE user_id = ? AND entity_id = ?', [$userId, $headOfficeId]) as $r) {
                $this->insert('user_entity_roles', ['user_id' => $userId, 'entity_id' => $entityId, 'role_id' => (int) $r['role_id'], 'created_at' => $now]);
            }
        }
    }

    private function planEntityChange(array $current, string $name, string $type, string $currency, string $status, callable $plan): void
    {
        $code = $current['code'];
        $write = fn (array $row) => $this->db->table('entities')->where('code', $code)->update($row + ['updated_at' => Clock::timestamp()]);

        if ($name !== $current['name']) {
            $plan('Organisation', $code . ' renamed from ' . $current['name'] . ' to ' . $name, fn () => $write(['name' => $name]));
        }

        if ($type !== $current['type']) {
            if ($current['head']) {
                throw new RuleViolation($current['name'] . ' is the head office. The consolidation is drawn from it, so it cannot become a ' . mb_strtolower($type) . '.');
            }
            if ($type === self::ENTITY_TYPES[0]) {
                throw new RuleViolation('There is already a head office. A second one would leave the consolidation with two tops.');
            }
            $plan('Organisation', $code . ' changed from a ' . mb_strtolower($current['type']) . ' to a ' . mb_strtolower($type), fn () => $write(['type' => $type]));
        }

        if ($currency !== $current['currency']) {
            if ($current['postings'] > 0) {
                throw new RuleViolation(
                    $current['name'] . ' cannot change its functional currency once it holds postings — '
                    . number_format($current['postings']) . ' journals are posted in ' . $current['currency'] . '.'
                );
            }
            $plan('Organisation', $code . ' functional currency changed from ' . $current['currency'] . ' to ' . $currency, fn () => $write(['functional_currency' => $currency]));
        }

        if ($status !== mb_strtolower($current['status'])) {
            if ($current['head'] && $status === 'dormant') {
                throw new RuleViolation($current['name'] . ' is the head office and cannot be made dormant — the organisation settings, the chart and the approval policy all hang off it.');
            }
            if ($status === 'dormant') {
                $open = (int) $this->value(
                    "SELECT COUNT(*) FROM {journals} j JOIN {entities} e ON e.id = j.entity_id WHERE e.code = ? AND j.status IN ('draft', 'submitted')",
                    [$code]
                );
                if ($open > 0) {
                    throw new RuleViolation($current['name'] . ' has ' . number_format($open) . ($open === 1 ? ' journal' : ' journals') . ' still to be posted or rejected. Clear those before it goes dormant.');
                }
            }
            $plan('Organisation', $code . ($status === 'dormant' ? ' made dormant — no longer offered for new work' : ' made live'), fn () => $write(['status' => $status]));
        }
    }

    private function planSegments(array $in, callable $plan): void
    {
        foreach ($this->segments() as $s) {
            if (!array_key_exists($s['key'], $in) || (bool) $in[$s['key']] === $s['required']) {
                continue;
            }
            $required = (bool) $in[$s['key']];
            $plan('Segments', $s['name'] . ' segment made ' . ($required ? 'mandatory' : 'optional') . ' on ' . lcfirst($s['applies']),
                fn () => $this->db->table('segments')->where('key', $s['key'])->update(['is_required' => (int) $required, 'updated_at' => Clock::timestamp()]));
        }
    }

    /** @param list<array{code: string, name: string, rate: mixed, active: mixed}> $in */
    private function planCurrencies(array $in, callable $plan): void
    {
        $held = array_column($this->currencies(), null, 'code');
        $seen = [];

        foreach ($in as $c) {
            $code = strtoupper(trim((string) ($c['code'] ?? '')));
            $name = trim((string) ($c['name'] ?? ''));
            $active = (bool) ($c['active'] ?? true);
            $rateText = trim((string) ($c['rate'] ?? ''));
            $rate = is_numeric(str_replace(',', '', $rateText)) ? round((float) str_replace(',', '', $rateText), 6) : null;

            if (isset($seen[$code])) {
                throw new RuleViolation($code . ' is on the list twice.');
            }
            $seen[$code] = true;
            $current = $held[$code] ?? null;

            if ($current === null) {
                if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
                    throw new RuleViolation('Use the three-letter ISO code — SEK, NOK, CHF.');
                }
                if ($name === '') {
                    throw new RuleViolation('Name ' . $code . ' so it reads properly on donor claims.');
                }
                if ($rate === null || $rate <= 0) {
                    throw new RuleViolation('Enter an indicative rate for ' . $code . ' to the ' . $this->ledger()['currency'] . '.');
                }
                $plan('Currencies', $code . ' (' . $name . ') added at ' . number_format($rate, 2) . ' to the ' . $this->ledger()['currency'],
                    fn () => $this->insert('currencies', ['code' => $code, 'name' => $name, 'indicative_rate' => $rate, 'is_active' => (int) $active, 'created_at' => Clock::timestamp()]));
                continue;
            }

            if ($name !== '' && $name !== $current['name']) {
                $plan('Currencies', $code . ' renamed from ' . $current['name'] . ' to ' . $name,
                    fn () => $this->db->table('currencies')->where('code', $code)->update(['name' => $name, 'updated_at' => Clock::timestamp()]));
            }
            if (!$current['base'] && $rateText !== '' && ($rate === null || round($rate, 2) != round((float) $current['rate'], 2))) {
                if ($rate === null || $rate <= 0) {
                    throw new RuleViolation('The indicative rate for ' . $code . ' must be a number above nil.');
                }
                $plan('Currencies', $code . ' indicative rate changed from ' . $current['rate'] . ' to ' . number_format($rate, 2, '.', ''),
                    fn () => $this->db->table('currencies')->where('code', $code)->update(['indicative_rate' => $rate, 'updated_at' => Clock::timestamp()]));
            }
            if ($active !== $current['active']) {
                if (!$active && $current['base']) {
                    throw new RuleViolation($code . ' is the reporting currency and cannot be disabled.');
                }
                if (!$active && $current['claims'] > 0) {
                    throw new RuleViolation($code . ' is stated on ' . $current['claims'] . ($current['claims'] === 1 ? ' open donor claim' : ' open donor claims') . ' — settle or write those off before it can be disabled.');
                }
                $plan('Currencies', $code . ($active ? ' enabled on new awards and donor claims' : ' disabled — no longer offered on new awards or claims'),
                    fn () => $this->db->table('currencies')->where('code', $code)->update(['is_active' => (int) $active, 'updated_at' => Clock::timestamp()]));
            }
        }
    }

    private function planApprovals(array $in, callable $plan): void
    {
        $approvers = $this->approverRoles();

        foreach ($this->approvals() as $a) {
            $change = $in[$a['key']] ?? null;
            if (!is_array($change)) {
                continue;
            }
            $type = array_search($a['key'], self::APPROVAL_KEYS, true);

            if (array_key_exists('threshold', $change)) {
                $text = preg_replace('/[^0-9.]/', '', (string) $change['threshold']);
                $threshold = $text === '' ? 0.0 : (float) $text;
                if ($threshold < 0) {
                    throw new RuleViolation('A threshold cannot be below nil.');
                }
                if (round($threshold, 2) != round((float) $a['threshold'], 2)) {
                    $from = $a['threshold'] == 0 ? 'nil' : Prototype::fmt((float) $a['threshold']);
                    $to = $threshold == 0 ? 'nil — every transaction needs sign-off' : Prototype::fmt($threshold);
                    $verb = $threshold > $a['threshold'] ? 'raised' : 'lowered';
                    $plan('Approvals', $a['label'] . ' threshold ' . $verb . ' from ' . $from . ' to ' . $to,
                        fn () => $this->db->table('approval_rules')->where('document_type', $type)->update(['threshold' => $threshold, 'updated_at' => Clock::timestamp()]));
                }
            }

            if (isset($change['approver']) && $change['approver'] !== $a['approver']) {
                if (!in_array($change['approver'], $approvers, true)) {
                    throw new RuleViolation('The ' . $change['approver'] . ' has no approval rights, so cannot approve ' . lcfirst($a['label']) . '. Choose ' . implode(' or ', $approvers) . '.');
                }
                $roleId = (int) $this->value('SELECT id FROM {roles} WHERE name = ?', [$change['approver']]);
                $plan('Approvals', $a['label'] . ' approver changed from ' . $a['approver'] . ' to ' . $change['approver'],
                    fn () => $this->db->table('approval_rules')->where('document_type', $type)->update(['approver_role_id' => $roleId, 'updated_at' => Clock::timestamp()]));
            }
        }
    }

    private function planProcurement(array $in, callable $plan): void
    {
        if (!array_key_exists('quoteThreshold', $in)) {
            return;
        }
        $text = preg_replace('/[^0-9.\-]/', '', (string) $in['quoteThreshold']);
        if ($text === '' || !is_numeric($text)) {
            throw new RuleViolation('Give the procurement threshold as an amount in KES.');
        }
        $threshold = round((float) $text, 2);
        if ($threshold <= 0) {
            throw new RuleViolation('The procurement threshold has to be above nil. At nil every purchase would need three quotations and every bill a pre-qualified supplier.');
        }
        $current = $this->quoteThreshold();
        if ($threshold == round($current, 2)) {
            return;
        }
        $plan('Approvals', 'Procurement threshold ' . ($threshold > $current ? 'raised' : 'lowered') . ' from ' . Prototype::fmt($current) . ' to ' . Prototype::fmt($threshold)
            . ' — three quotations and a pre-qualified supplier above it',
            fn () => $this->hold(self::QUOTE_THRESHOLD_KEY, 'approvals', (string) self::num($threshold), self::QUOTE_THRESHOLD_LABEL));
    }

    /**
     * Terms and reminder windows. Each is a whole number of days within a year; the
     * supplier terms are a list. A bill already captured keeps the terms it carries.
     *
     * $in: {key: value}
     */
    private function planDays(array $in, callable $plan): void
    {
        foreach ($in as $key => $value) {
            $key = (string) $key;
            if (!isset(self::DAY_RULES[$key])) {
                continue;
            }
            [$label] = self::DAY_RULES[$key];
            $current = (string) $this->dayRule($key);
            if ($key === 'supplierTerms') {
                $terms = self::termList((string) $value);
                if ($terms === [] || min($terms) < 1 || max($terms) > 365 || preg_match('/[^0-9,\s]/', (string) $value)) {
                    throw new RuleViolation('Give the supplier payment terms as days between 1 and 365, separated by commas — e.g. 14, 30, 45, 60.');
                }
                $new = implode(', ', $terms);
                if ($new === implode(', ', self::termList($current))) {
                    continue;
                }
                $plan('Terms', $label . ' changed from ' . $current . ' to ' . $new . ' days', fn () => $this->hold($key, 'terms', $new, $label));

                continue;
            }
            $text = trim((string) $value);
            if (!ctype_digit($text) || (int) $text < 1 || (int) $text > 365) {
                throw new RuleViolation($label . ' has to be a whole number of days between 1 and 365.');
            }
            if ((int) $text === (int) $current) {
                continue;
            }
            $plan('Terms', $label . ' changed from ' . (int) $current . ' to ' . (int) $text . ' days', fn () => $this->hold($key, 'terms', (string) (int) $text, $label));
        }
    }

    /**
     * Which account each automatic posting goes to. Only future postings follow a
     * change; a control account that still holds a balance cannot move, because the
     * entries that clear it would land somewhere else.
     *
     * $in: {role: account code}
     */
    private function planPostingAccounts(array $in, callable $plan): void
    {
        $accounts = new PostingAccounts();
        foreach ($in as $role => $code) {
            $role = (string) $role;
            $code = trim((string) $code);
            if ($code === '') {
                throw new RuleViolation('Every posting role needs an account. ' . (PostingAccounts::ROLES[$role][0] ?? $role) . ' has none.');
            }
            $what = $accounts->check($role, $code);
            if ($what !== null) {
                $plan('Ledger', $what, fn () => $accounts->set($role, $code));
            }
        }
    }

    /**
     * VAT and the withholding rates, changed from a date: the rates in force then end
     * the day before and the new ones start, so a bill keeps the tax it was captured
     * with and one dated after the change takes the new rates. A change starts today
     * or later — a rate is never rewritten under bills already on file.
     *
     * $in: {vat: {rate, label}, wht: [{rate, label}], from: Y-m-d}
     */
    private function planTaxes(array $in, callable $plan): void
    {
        $taxes = new TaxRepository();
        $today = Clock::date();
        $from = (string) ($in['from'] ?? '') ?: $today;
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $rate = static function (mixed $value, string $what): float {
            $text = trim(str_replace('%', '', (string) $value));
            if ($text === '' || !is_numeric($text) || (float) $text <= 0 || (float) $text >= 100) {
                throw new RuleViolation($what . ' has to be a percentage above nil and below 100.');
            }

            return round((float) $text, 3);
        };
        $shown = static fn (array $rates) => $rates === [] ? 'none' : implode(', ', array_map(static fn ($r) => self::num($r['rate']) . '%', $rates));
        $changes = [];

        if (isset($in['vat'])) {
            $vat = ['rate' => $rate($in['vat']['rate'] ?? '', 'The VAT rate'), 'label' => trim((string) ($in['vat']['label'] ?? '')) ?: 'Standard rate'];
            $held = $taxes->labelled('vat', $from)[0] ?? null;
            if ($held === null || $held['rate'] != $vat['rate'] || $held['label'] !== $vat['label']) {
                $changes['vat'] = [[$vat], $held === null ? 'VAT set at ' . self::num($vat['rate']) . '%'
                    : ($held['rate'] != $vat['rate'] ? 'VAT changed from ' . self::num($held['rate']) . '% to ' . self::num($vat['rate']) . '%' : 'VAT rate renamed "' . $vat['label'] . '"')];
            }
        }

        if (isset($in['wht'])) {
            $wht = [];
            foreach ((array) $in['wht'] as $r) {
                $pct = $rate($r['rate'] ?? '', 'A withholding rate');
                $label = trim((string) ($r['label'] ?? ''));
                if ($label === '') {
                    throw new RuleViolation('Say what the ' . self::num($pct) . '% withholding rate applies to. The bill form lists it by that.');
                }
                if (isset($wht[(string) $pct])) {
                    throw new RuleViolation('The ' . self::num($pct) . '% withholding rate is listed twice.');
                }
                $wht[(string) $pct] = ['rate' => self::num($pct), 'label' => mb_substr($label, 0, 80)];
            }
            ksort($wht, SORT_NUMERIC);
            $wht = array_values($wht);
            $held = $taxes->labelled('wht', $from);
            if ($wht != $held) {
                // A default no longer offered would put every bill in its category out of policy.
                $kept = array_map(static fn ($r) => (float) $r['rate'], $wht);
                foreach ($this->rows('SELECT name, wht_rate_pct FROM {spend_categories} WHERE wht_rate_pct > 0 ORDER BY id') as $c) {
                    if (!in_array((float) $c['wht_rate_pct'], $kept, true)) {
                        throw new RuleViolation($c['name'] . ' withholds ' . self::num($c['wht_rate_pct']) . '% by default. Keep that rate, or change the spend category\'s default first.');
                    }
                }
                $changes['wht'] = [$wht, 'Withholding rates changed from ' . $shown($held) . ' to ' . $shown($wht)
                    . ($shown($held) === $shown($wht) ? ' (what they apply to reworded)' : '')];
            }
        }

        if ($changes === []) {
            return;
        }
        if ($day === false || $day->format('Y-m-d') !== $from) {
            throw new RuleViolation('Give the date the new rates take effect.');
        }
        if ($from < $today) {
            throw new RuleViolation('A new rate takes effect today or later. Bills already captured keep the tax they were entered with, so a rate is never changed under them.');
        }

        foreach ($changes as $tax => [$rates, $what]) {
            $plan('Taxes', $what . ' from ' . self::dmy($from), fn () => $taxes->change($tax, $rates, $from));
        }
    }

    /**
     * Where each pay component posts. An account has to be one a journal could carry
     * — a postable leaf — or the run would be refused by the ledger at the moment it
     * mattered rather than here.
     */
    private function planPayAccounts(array $in, callable $plan): void
    {
        $held = array_column($this->payAccounts(), null, 'key');
        $accounts = array_column($this->payAccountOptions(), null, 'code');

        foreach ($in as $key => $code) {
            $key = (string) $key;
            $code = trim((string) $code);
            $current = $held[$key] ?? null;
            if ($current === null || $code === $current['code']) {
                continue;
            }
            if ($code !== '' && !isset($accounts[$code])) {
                throw new RuleViolation($code . ' is not a postable account. ' . $current['name']
                    . ' posts to an active leaf account in the expenditure, liability or asset range.');
            }
            if ($code === '' && $current['required']) {
                throw new RuleViolation($current['name'] . ' is part of every run\'s journal, so it needs an account to post to.');
            }

            $was = $current['code'] === '' ? 'nothing' : $current['code'];
            $now = $code === '' ? 'nothing' : $code . ' ' . $accounts[$code]['name'];
            $plan('Payroll', $current['name'] . ' now posts to ' . $now . ' (was ' . $was . ')',
                fn () => $this->db->table('pay_components')->where('key', $key)->update([
                    'account_id' => $code === '' ? null : $this->value('SELECT id FROM {accounts} WHERE code = ?', [$code]),
                    'updated_at' => Clock::timestamp(),
                ]));
        }
    }

    private function planPayroll(array $in, callable $plan): void
    {
        $benefits = array_column($this->benefits(), null, 'key');
        $keyOf = []; // screen key → component key (new benefits get theirs here)
        $newKeys = [];

        foreach ((array) ($in['benefits'] ?? []) as $b) {
            $key = (string) ($b['key'] ?? '');
            $name = trim((string) ($b['name'] ?? ''));
            $basis = (string) ($b['basis'] ?? 'flat');
            $taxable = (bool) ($b['taxable'] ?? true);
            $active = (bool) ($b['active'] ?? true);
            if ($name === '') {
                throw new RuleViolation('Name every benefit as it should read on a payslip.');
            }
            if (!in_array($basis, self::BASES, true)) {
                throw new RuleViolation($name . ' needs a basis: a percentage of basic or a flat amount.');
            }

            $current = $benefits[$key] ?? null;
            if ($current === null) {
                $slug = trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($name)), '_') ?: 'benefit';
                $component = mb_substr($slug, 0, 30);
                $n = 2;
                while (isset($newKeys[$component]) || $this->value('SELECT c.id FROM {pay_components} c WHERE c.key = ?', [$component]) !== null) {
                    $component = mb_substr($slug, 0, 27) . '_' . $n++;
                }
                if (in_array(mb_strtolower($name), array_map('mb_strtolower', array_column($benefits, 'name')), true)) {
                    throw new RuleViolation($name . ' is already on the list.');
                }
                $newKeys[$component] = true;
                $keyOf[$key] = $component;
                $plan('Payroll', $name . ' added as a ' . ($basis === 'pct' ? 'percentage-of-basic' : 'flat') . ($taxable ? ' taxable' : ' non-taxable') . ' benefit',
                    fn () => $this->insert('pay_components', [
                        'key' => $component, 'name' => mb_substr($name, 0, 60), 'kind' => 'earning', 'is_taxable' => (int) $taxable, 'is_statutory' => 0,
                        'is_benefit' => 1, 'basis' => $basis, 'is_active' => (int) $active, 'created_at' => Clock::timestamp(),
                        'account_id' => $this->value("SELECT c.account_id FROM {pay_components} c WHERE c.key = 'basic_salary'"),
                    ]));
                continue;
            }

            $keyOf[$key] = $key;
            if ($name !== $current['name']) {
                $plan('Payroll', $current['name'] . ' renamed to ' . $name, fn () => $this->updateComponent($key, ['name' => mb_substr($name, 0, 60)]));
            }
            if ($basis !== $current['basis']) {
                $plan('Payroll', $name . ' changed to ' . ($basis === 'pct' ? 'a percentage of basic' : 'a flat amount'), fn () => $this->updateComponent($key, ['basis' => $basis]));
            }
            if ($taxable !== $current['taxable']) {
                $plan('Payroll', $name . ' made ' . ($taxable ? 'taxable' : 'non-taxable — excluded from taxable pay but still paid'), fn () => $this->updateComponent($key, ['is_taxable' => (int) $taxable]));
            }
            if ($active !== $current['active']) {
                if (!$active && $current['paidTo'] > 0) {
                    throw new RuleViolation($name . ' is paid to ' . $current['paidTo'] . ($current['paidTo'] === 1 ? ' member of staff' : ' members of staff') . ' — clear it on their records before withdrawing it.');
                }
                $plan('Payroll', $name . ($active ? ' reinstated as a benefit' : ' withdrawn — it drops off the grade scale'), fn () => $this->updateComponent($key, ['is_active' => (int) $active]));
            }
        }
        foreach ($benefits as $key => $b) {
            $keyOf[$key] ??= $key;
        }
        $bases = [];
        foreach ((array) ($in['benefits'] ?? []) as $b) {
            $bases[$keyOf[(string) ($b['key'] ?? '')] ?? ''] = ['basis' => (string) ($b['basis'] ?? 'flat'), 'name' => trim((string) ($b['name'] ?? ''))];
        }
        foreach ($benefits as $key => $b) {
            $bases[$key] ??= ['basis' => $b['basis'], 'name' => $b['name']];
        }

        if (!isset($in['grades'])) {
            return;
        }
        $grades = array_column($this->grades(), null, 'grade');
        $seen = [];
        $order = count($grades);
        foreach ((array) $in['grades'] as $g) {
            $code = strtoupper(trim((string) ($g['grade'] ?? '')));
            $title = trim((string) ($g['band'] ?? ''));
            $active = (bool) ($g['active'] ?? true);
            if (isset($seen[$code])) {
                throw new RuleViolation($code . ' is on the scale twice.');
            }
            $seen[$code] = true;
            if ($title === '') {
                throw new RuleViolation('Name the band for ' . ($code ?: 'every grade') . ' so it reads on payslips and the roster.');
            }

            $amounts = [];
            foreach ((array) ($g['ben'] ?? []) as $screenKey => $value) {
                $component = $keyOf[(string) $screenKey] ?? null;
                if ($component === null) {
                    continue;
                }
                $text = preg_replace('/[^0-9.]/', '', (string) $value);
                $amount = $text === '' ? 0.0 : (float) $text;
                if (($bases[$component]['basis'] ?? 'flat') === 'pct' && $amount > 100) {
                    throw new RuleViolation($bases[$component]['name'] . ' is a percentage of basic pay — enter something up to 100 for ' . $code . '.');
                }
                $amounts[$component] = round($amount, 2);
            }

            $current = $grades[$code] ?? null;
            if ($current === null) {
                if (preg_match('/^[A-Z][A-Z0-9]{0,4}$/', $code) !== 1) {
                    throw new RuleViolation('Give the grade a short code — G8, or whatever the scale uses next.');
                }
                $sort = ++$order;
                $plan('Payroll', $code . ' · ' . $title . ' added to the grade scale', function () use ($code, $title, $active, $amounts, $sort) {
                    $id = $this->insert('pay_grades', ['code' => $code, 'title' => mb_substr($title, 0, 60), 'sort_order' => $sort, 'is_active' => (int) $active, 'created_at' => Clock::timestamp()]);
                    $this->setGradeBenefits($id, $amounts);
                });
                continue;
            }

            if ($title !== $current['band']) {
                $plan('Payroll', $code . ' band renamed from ' . $current['band'] . ' to ' . $title,
                    fn () => $this->db->table('pay_grades')->where('code', $code)->update(['title' => mb_substr($title, 0, 60), 'updated_at' => Clock::timestamp()]));
            }
            if ($active !== $current['active']) {
                if (!$active && $current['staff'] > 0) {
                    throw new RuleViolation($code . ' is held by ' . $current['staff'] . ($current['staff'] === 1 ? ' member of staff' : ' members of staff') . ' — move them to another grade before it can be withdrawn.');
                }
                $plan('Payroll', $code . ($active ? ' reinstated for new appointments' : ' withdrawn from new appointments'),
                    fn () => $this->db->table('pay_grades')->where('code', $code)->update(['is_active' => (int) $active, 'updated_at' => Clock::timestamp()]));
            }
            $held = (array) $current['ben'];
            foreach ($amounts as $component => $amount) {
                if (round((float) ($held[$component] ?? 0), 2) == $amount) {
                    continue;
                }
                $pct = ($bases[$component]['basis'] ?? 'flat') === 'pct';
                $show = static fn ($v) => $pct ? rtrim(rtrim(number_format((float) $v, 2), '0'), '.') . '%' : Prototype::fmt((float) $v);
                $plan('Payroll', $code . ' ' . lcfirst($bases[$component]['name']) . ' changed from ' . $show($held[$component] ?? 0) . ' to ' . $show($amount),
                    fn () => $this->setGradeBenefits((int) $this->value('SELECT id FROM {pay_grades} WHERE code = ?', [$code]), [$component => $amount]));
            }
        }
    }

    /**
     * Moves each user named to a single role, at every entity they reach — the
     * draft's shorthand. Several roles per person are set in Users → Access
     * (RoleRepository::setAccess), which saves as it is made.
     *
     * @param array<string, string> $in email → role
     */
    private function planUsers(array $in, callable $plan): void
    {
        $roles = $this->roles();
        foreach ($this->users() as $u) {
            $role = $in[$u['email']] ?? null;
            if ($role === null || $role === $u['role']) {
                continue;
            }
            if (!in_array($role, $roles, true)) {
                throw new RuleViolation($role . ' is not a role a user can hold.');
            }
            $roleId = (int) $this->value('SELECT id FROM {roles} WHERE name = ?', [$role]);
            $userId = (int) $this->lookups->userId($u['email']);
            if ($u['roles'] === [$role]) {
                continue;
            }
            $plan('Users', $this->lookups->shortName($userId) . ' moved from ' . implode(', ', $u['roles']) . ' to ' . $role, function () use ($userId, $roleId) {
                $entities = array_column($this->rows('SELECT DISTINCT entity_id FROM {user_entity_roles} WHERE user_id = ?', [$userId]), 'entity_id');
                $this->db->table('user_entity_roles')->where('user_id', $userId)->delete();
                foreach ($entities as $entityId) {
                    $this->db->table('user_entity_roles')->insert(['user_id' => $userId, 'entity_id' => (int) $entityId, 'role_id' => $roleId, 'created_at' => Clock::timestamp()]);
                }
            });
        }
    }

    // ------------------------------------------------------------------

    /** Someone active must still be able to change settings once a save is applied. */
    private function assertSettingsManaged(): void
    {
        $managers = (int) $this->value(
            "SELECT COUNT(DISTINCT u.id) FROM {users} u JOIN {user_entity_roles} ur ON ur.user_id = u.id JOIN {role_permissions} rp ON rp.role_id = ur.role_id
             JOIN {permissions} p ON p.id = rp.permission_id WHERE u.status = 'active' AND p.key = 'settings.manage'"
        );
        if ($managers === 0) {
            throw new RuleViolation('That would leave no active Finance Manager, and nobody else can change settings or approve above their thresholds. Assign the role to someone first.');
        }
    }

    private function setAppearance(string $key, string $value, string $label, string $note = ''): void
    {
        $this->hold($key, 'appearance', $value, $label, $note);
    }

    /**
     * Writes a head-office setting. Written rather than updated blind: a database
     * seeded before a given setting existed has no row to update, and a save that
     * silently changed nothing would still have been logged as a change.
     */
    private function hold(string $key, string $kind, string $value, string $label, string $note = ''): void
    {
        $entityId = $this->headOffice()['id'];
        // Through the builder rather than raw SQL: "key" is a reserved word, and the
        // builder quotes it for whichever database is behind this.
        $held = $this->db->table('settings')->select('id')->where('entity_id', $entityId)->where('key', $key)->get()->getRowArray();
        $now = Clock::timestamp();

        if ($held === null) {
            $this->insert('settings', [
                'entity_id' => $entityId, 'key' => $key, 'kind' => $kind, 'value' => $value,
                'label' => $label, 'note' => $note !== '' ? $note : null, 'created_at' => $now,
            ]);

            return;
        }

        $this->db->table('settings')->where('id', $held['id'])->update(['value' => $value, 'updated_at' => $now]);
    }

    private function setCustomTheme(array $custom): void
    {
        $this->setAppearance(Theme::CUSTOM_KEY, json_encode(['accent' => $custom['accent'], 'rail' => $custom['rail']]), 'Custom theme colours');
    }

    /**
     * Stores an uploaded logo and hangs it on the brand, replacing whatever was
     * there. Saved as it is chosen rather than drafted: a file cannot sit in a
     * browser draft waiting for Save.
     *
     * @param array{path: string, name: string, size: int, mime: string} $file
     */
    public function setLogo(array $file, int $actorId): array
    {
        $extension = Brand::LOGO_TYPES[$file['mime']] ?? null;
        if ($extension === null) {
            throw new RuleViolation(
                ($file['mime'] === 'image/svg+xml' ? 'An SVG can carry script as well as a picture, so it is not accepted. ' : '')
                . 'A logo has to be a PNG, JPEG or WebP image.'
            );
        }
        if ($file['size'] > Brand::MAX_LOGO_BYTES) {
            throw new RuleViolation('The logo is ' . round($file['size'] / 1000) . ' KB. It is drawn at 30 pixels, so keep it under ' . round(Brand::MAX_LOGO_BYTES / 1000) . ' KB.');
        }

        $key = Brand::STORAGE_DIR . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $path = WRITEPATH . 'uploads/' . $key;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            throw new RuleViolation('The logo cannot be stored right now.');
        }
        if (!(is_uploaded_file($file['path']) ? move_uploaded_file($file['path'], $path) : copy($file['path'], $path))) {
            throw new RuleViolation($file['name'] . ' could not be stored.');
        }

        $previous = $this->appearance()['logo'];
        $this->transaction(function () use ($key, $previous, $actorId) {
            $this->setAppearance(Brand::LOGO_KEY, $key, 'Logo');
            $this->logChange('Appearance', $previous === '' ? 'Logo added' : 'Logo replaced', $actorId);
        });
        $this->removeStoredLogo($previous);

        return $this->appearance();
    }

    /** Puts the brand back to the initials mark drawn from the application name. */
    public function clearLogo(int $actorId): array
    {
        $previous = $this->appearance()['logo'];
        if ($previous === '') {
            throw new RuleViolation('There is no logo to remove — the sidebar is already drawing the initials.');
        }

        $this->transaction(function () use ($actorId) {
            $this->setAppearance(Brand::LOGO_KEY, '', 'Logo');
            $this->logChange('Appearance', 'Logo removed — the sidebar draws the initials again', $actorId);
        });
        $this->removeStoredLogo($previous);

        return $this->appearance();
    }

    /** Where a stored logo is on disk, or null when the brand carries none. */
    public function logoPath(): ?string
    {
        $key = $this->appearance()['logo'];
        if ($key === '' || !str_starts_with($key, Brand::STORAGE_DIR . '/')) {
            return null;
        }
        $path = WRITEPATH . 'uploads/' . $key;

        return is_file($path) ? $path : null;
    }

    /** A replaced logo is nobody's record: it is deleted rather than left to accumulate. */
    private function removeStoredLogo(string $key): void
    {
        if ($key === '' || !str_starts_with($key, Brand::STORAGE_DIR . '/')) {
            return;
        }
        $path = WRITEPATH . 'uploads/' . $key;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function setSetting(string $key, string $value): void
    {
        $this->db->table('settings')->where('entity_id', $this->headOffice()['id'])->where('key', $key)->update(['value' => $value, 'updated_at' => Clock::timestamp()]);
    }

    private function updateComponent(string $key, array $row): void
    {
        $this->db->table('pay_components')->where('key', $key)->update($row + ['updated_at' => Clock::timestamp()]);
    }

    /** @param array<string, float> $amounts component key → amount */
    private function setGradeBenefits(int $gradeId, array $amounts): void
    {
        foreach ($amounts as $component => $amount) {
            $componentId = (int) $this->value('SELECT c.id FROM {pay_components} c WHERE c.key = ?', [$component]);
            $this->db->table('pay_grade_benefits')->where('pay_grade_id', $gradeId)->where('pay_component_id', $componentId)->delete();
            $this->db->table('pay_grade_benefits')->insert(['pay_grade_id' => $gradeId, 'pay_component_id' => $componentId, 'amount' => $amount]);
        }
    }

    private function logChange(string $area, string $what, int $actorId): void
    {
        $this->audit('settings:' . strtolower($area), null, null, $what, $actorId, 'settings.changed', $this->headOffice()['id']);
    }

    /**
     * The entity the organisation's own details and settings are held on: the one
     * with no parent. An instance that has not been installed has none, and says so
     * rather than serving a screen with nothing behind it.
     */
    private function headOffice(): array
    {
        return $this->cached('head-office', fn () => $this->row('SELECT * FROM {entities} WHERE parent_id IS NULL ORDER BY id LIMIT 1')
            ?? throw new \RuntimeException('This instance has no organisation yet. Run `php spark db:seed BaselineSeeder` and then `php spark install`.'));
    }

    private function settingRows(string $kind): array
    {
        return $this->rows('SELECT s.* FROM {settings} s JOIN {entities} e ON e.id = s.entity_id WHERE e.code = ? AND s.kind = ? ORDER BY s.id', [$this->lookups->headOfficeCode(), $kind]);
    }

    /** Users with their entity names joined by "|" (entity names contain commas). */
    private function withEntityNames(): array
    {
        $names = [];
        foreach ($this->rows('SELECT ur.user_id, e.name FROM {user_entity_roles} ur JOIN {entities} e ON e.id = ur.entity_id ORDER BY e.id') as $r) {
            $names[(int) $r['user_id']][$r['name']] = true;
        }

        return array_map(static fn ($u) => $u + ['entity_names' => implode('|', array_keys($names[(int) $u['id']] ?? []))], $this->rows(
            'SELECT u.* FROM {users} u WHERE EXISTS (SELECT 1 FROM {user_entity_roles} ur WHERE ur.user_id = u.id) ORDER BY u.id'
        ));
    }

    private static function quoted(array $values): string
    {
        return implode(', ', array_map(static fn ($v) => "'" . $v . "'", $values));
    }
}
