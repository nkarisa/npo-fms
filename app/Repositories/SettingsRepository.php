<?php

namespace App\Repositories;

use App\Libraries\Clock;
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

    /** The rule every approval band carries, and the separations the ledger enforces. */
    public const SOD_RULES = [
        'A journal preparer can never approve their own entry, whatever its value.',
        'Bill coding, approval and payment release are three separate permissions and cannot be held together.',
        'Inter-fund transfers and sub-grants always need the Executive Director, regardless of amount.',
        'Auditors hold read-only access and cannot post, approve or change configuration.',
    ];

    /** Held on the setting row so the seeded database and the screen say the same thing. */
    public const THEME_NOTE = 'The colours the interface is drawn in. It changes what everyone reads on screen — never a figure, a code or a date.';

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

    public function entities(): array
    {
        return array_map(static fn ($e) => [
            'name' => $e['name'], 'type' => $e['type'], 'currency' => $e['functional_currency'], 'status' => ucfirst($e['status']),
        ], $this->rows('SELECT * FROM {entities} ORDER BY id'));
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

    /** The interface theme the organisation reads the shell in. */
    public function theme(): string
    {
        return $this->cached('theme', function () {
            $held = $this->value(
                'SELECT s.value FROM {settings} s JOIN {entities} e ON e.id = s.entity_id WHERE e.code = ? AND s.key = ?',
                [Lookups::SECRETARIAT, Theme::KEY]
            );

            return Theme::isKnown($held) ? (string) $held : Theme::DEFAULT;
        });
    }

    public function formatsLocked(): bool
    {
        return $this->cached('formats-locked', fn () => ($this->value(
            "SELECT s.value FROM {settings} s JOIN {entities} e ON e.id = s.entity_id WHERE e.code = ? AND s.key = 'formatsLocked'",
            [Lookups::SECRETARIAT]
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

    /** Roles a user can hold: those with at least one permission, in the order they were set up. */
    public function roles(): array
    {
        return array_column($this->rows('SELECT r.name FROM {roles} r WHERE EXISTS (SELECT 1 FROM {role_permissions} rp WHERE rp.role_id = r.id) ORDER BY r.id'), 'name');
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

        return array_map(function ($u) use ($entityCount) {
            $entities = explode('|', (string) $u['entity_names']);
            $words = array_map(static fn ($n) => match (true) {
                str_contains($n, 'Secretariat') => 'Secretariat',
                str_contains($n, 'Trust')       => 'Trust',
                default                         => trim(preg_replace('/^ELOG | (Regional )?Office$/', '', $n)),
            }, $entities);
            $signIn = UserRepository::lastSignIn($u);

            return [
                'name' => $u['name'], 'email' => $u['email'], 'initials' => $u['initials'], 'role' => $this->lookups->roleOf((int) $u['id']),
                'entities' => count($entities) === $entityCount ? 'All entities' : implode(', ', $words),
                'lastActive' => $signIn === '—' ? '—' : lcfirst(trim(explode('·', $signIn)[0])), 'status' => ucfirst($u['status']),
            ];
        }, $this->withEntityNames());
    }

    /** Changes to settings and controls, newest first. */
    public function auditLog(): array
    {
        return $this->cached('audit', fn () => array_map(fn ($e) => [
            'when' => date('d M H:i', strtotime($e['occurred_at'])), 'who' => $this->lookups->shortName($e['actor_user_id'] === null ? null : (int) $e['actor_user_id']),
            'what' => $e['summary'] ?? '', 'area' => ucfirst(substr((string) strstr($e['object_type'], ':'), 1)),
        ], $this->rows("SELECT * FROM {audit_events} WHERE action = 'settings.changed' ORDER BY occurred_at DESC, id DESC")));
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
        if (isset($draft['payroll'])) {
            $this->planPayroll((array) $draft['payroll'], $plan);
        }
        if (isset($draft['users'])) {
            $this->planUsers((array) $draft['users'], $plan);
        }
        if (isset($draft['appearance']['theme'])) {
            $this->planAppearance((string) $draft['appearance']['theme'], $plan);
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
     * Invites a user: they appear as Invited, with their role at the entities named,
     * until they accept.
     *
     * @param list<string>|string $entities entity codes, or 'all'
     */
    public function invite(string $name, string $email, string $role, array|string $entities, int $actorId): array
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
        if (!in_array($role, $this->roles(), true)) {
            throw new RuleViolation('Choose the role the person will hold.');
        }
        $all = $this->rows('SELECT id, code, name FROM {entities} ORDER BY id');
        $chosen = $entities === 'all' ? $all : array_values(array_filter($all, static fn ($e) => in_array($e['code'], (array) $entities, true)));
        if ($chosen === []) {
            throw new RuleViolation('Choose at least one entity the person can work in.');
        }

        [$first, $last] = explode(' ', $name, 2) + [1 => ''];
        $short = mb_substr($first, 0, 1) . '. ' . ($last !== '' ? $last : $first);
        $initials = mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last !== '' ? $last : $first, 0, 1));
        $roleId = (int) $this->value('SELECT id FROM {roles} WHERE name = ?', [$role]);
        $now = Clock::timestamp();

        $this->transaction(function () use ($name, $email, $short, $initials, $chosen, $roleId, $role, $entities, $actorId, $now) {
            $userId = $this->insert('users', [
                'email' => $email, 'name' => $name, 'short_name' => mb_substr($short, 0, 60), 'initials' => $initials,
                'locale_id' => $this->value("SELECT id FROM {locales} WHERE code = 'en-GB'"), 'status' => 'invited', 'invited_at' => $now, 'created_at' => $now,
            ]);
            foreach ($chosen as $e) {
                $this->db->table('user_entity_roles')->insert(['user_id' => $userId, 'entity_id' => $e['id'], 'role_id' => $roleId, 'created_at' => $now]);
            }
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

    private function planAppearance(string $theme, callable $plan): void
    {
        $current = $this->theme();
        if ($theme === $current) {
            return;
        }
        if (!Theme::isKnown($theme)) {
            throw new RuleViolation($theme . ' is not one of the themes the interface is drawn in.');
        }

        $plan('Appearance', 'Interface theme changed from ' . Theme::name($current) . ' to ' . Theme::name($theme) . ' for everyone',
            fn () => $this->setTheme($theme));
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

    /** @param array<string, string> $in email → role */
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
            $plan('Users', $this->lookups->shortName($userId) . ' moved from ' . $u['role'] . ' to ' . $role,
                fn () => $this->db->table('user_entity_roles')->where('user_id', $userId)->update(['role_id' => $roleId, 'updated_at' => Clock::timestamp()]));
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

    /**
     * Written rather than updated blind: a database seeded before the theme
     * existed has no row to update, and a save that silently changed nothing
     * would still have been logged as a change.
     */
    private function setTheme(string $theme): void
    {
        $entityId = $this->headOffice()['id'];
        // Through the builder rather than raw SQL: "key" is a reserved word, and the
        // builder quotes it for whichever database is behind this.
        $held = $this->db->table('settings')->select('id')->where('entity_id', $entityId)->where('key', Theme::KEY)->get()->getRowArray();
        $now = Clock::timestamp();

        if ($held === null) {
            $this->insert('settings', [
                'entity_id' => $entityId, 'key' => Theme::KEY, 'kind' => 'appearance', 'value' => $theme,
                'label' => 'Interface theme', 'note' => self::THEME_NOTE, 'created_at' => $now,
            ]);

            return;
        }

        $this->db->table('settings')->where('id', $held['id'])->update(['value' => $theme, 'updated_at' => $now]);
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

    private function headOffice(): array
    {
        return $this->cached('head-office', fn () => $this->row('SELECT * FROM {entities} WHERE code = ?', [Lookups::SECRETARIAT]));
    }

    private function settingRows(string $kind): array
    {
        return $this->rows('SELECT s.* FROM {settings} s JOIN {entities} e ON e.id = s.entity_id WHERE e.code = ? AND s.kind = ? ORDER BY s.id', [Lookups::SECRETARIAT, $kind]);
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
