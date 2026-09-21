<?php

namespace App\Libraries;

use App\Database\Seeds\BaselineSeeder;
use App\Repositories\AuthRepository;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use CodeIgniter\Database\BaseConnection;

/**
 * Standing up a new instance: the organisation, its head office, the first
 * financial year and the first person who can sign anything off.
 *
 * Everything before this is the same for every instance and comes from
 * BaselineSeeder — roles, permissions, segments, document series, the counties and
 * the payroll reference data. Everything after it is the organisation's own work:
 * import the chart of accounts, add the funds and cash accounts, carry the opening
 * balances, invite the rest of the people.
 *
 * What is written here is the smallest set that lets the ledger be posted to at
 * all: a journal needs an entity, an open period inside a fiscal year, and a
 * preparer; approving one needs a second person and an approval rule. The first
 * user therefore holds every settings permission and users.manage, and can invite
 * that second person.
 *
 * The first user's password comes from the answers file (userPassword) for an
 * unattended install, or is chosen in the browser from a one-time link the install
 * prints — never typed at an interactive prompt, where it would echo.
 *
 * It runs once. An instance that already has an entity is refused rather than
 * added to, because a second head office would break the consolidation every
 * report is built on.
 */
final class Installer
{
    /** Where a financial year end falls, and the month its year opens in. */
    public const YEAR_STARTS = ['31 December' => 1, '30 June' => 7, '30 September' => 10];

    /** The role the first user holds: the only one that can change settings. */
    public const FIRST_ROLE = 'Finance Manager';

    /** Owns records nobody signed for — an imported chart, a carried balance. It can never sign in. */
    public const SYSTEM_EMAIL = 'data-migration@system.invalid';

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * What the installer needs, with everything that has a sensible default
     * defaulted. Only the four organisation fields and the first user have none.
     *
     * @return array<string, array{0: string, 1: string, 2: string|null}> key => [prompt, note, default]
     */
    public static function questions(): array
    {
        return [
            'registeredName' => ['Registered name', 'As it appears on the certificate of registration', null],
            'shortName'      => ['Short name', 'What staff call the organisation', null],
            'taxPin'         => ['Tax PIN', 'Left blank if there is none yet', ''],
            'registrationNo' => ['Registration number', 'Left blank if there is none yet', ''],
            'entityCode'     => ['Head office code', 'Short, fixed once saved — user access and imported files refer to it', null],
            'entityName'     => ['Head office name', 'The reporting entity the accounts consolidate into', null],
            'currency'       => ['Functional currency', 'The currency the books are kept in', 'KES'],
            'framework'      => ['Reporting framework', 'One of ' . implode(', ', \App\Repositories\SettingsRepository::FRAMEWORKS), 'IFRS'],
            'yearEnd'        => ['Financial year end', 'One of ' . implode(', ', array_keys(self::YEAR_STARTS)), '31 December'],
            'codeLength'     => ['Account code length', 'One of ' . implode(', ', \App\Repositories\SettingsRepository::CODE_LENGTHS), '4 digits'],
            'firstYear'      => ['First financial year to keep', 'The year the ledger starts; its months are created open', (string) date('Y')],
            'userName'       => ['Your full name', 'The first user, who holds the Finance Manager role', null],
            'userEmail'      => ['Your email address', 'What you sign in with', null],
            'userPassword'   => ['Your password', 'Answers file only. Left blank, the install prints a one-time link to choose it in the browser', ''],
        ];
    }

    /** Why this instance cannot be installed, or null. */
    public function refusal(): ?string
    {
        if ($this->count('entities') > 0) {
            return 'This instance already belongs to an organisation. Installing again would add a second head office, '
                . 'which the consolidation cannot carry. Rebuild the database first if this was meant to be a fresh instance.';
        }
        if ($this->count('roles') === 0 || $this->count('permissions') === 0) {
            return 'The reference data is missing. Run `php spark db:seed BaselineSeeder` first — the roles and permissions '
                . 'the first user needs are seeded there.';
        }

        return null;
    }

    /**
     * Writes the organisation, its head office, the financial year and its months,
     * and the first user, in one transaction.
     *
     * @param array<string, string> $answers keyed as questions()
     * @return array{entity: string, year: string, periods: int, user: string, role: string, link: string|null}
     */
    public function install(array $answers): array
    {
        if ($refusal = $this->refusal()) {
            throw new RuleViolation($refusal);
        }

        $in = $this->validated($answers);
        $now = Clock::timestamp();

        $this->db->transException(true)->transStart();

        try {
            $entityId = $this->insert('entities', [
                'parent_id' => null, 'code' => $in['entityCode'], 'name' => $in['entityName'], 'type' => 'Head office',
                'functional_currency' => $in['currency'], 'status' => 'live', 'registered_name' => $in['registeredName'],
                'short_name' => $in['shortName'], 'tax_pin' => $in['taxPin'] ?: null,
                'registration_no' => $in['registrationNo'] ?: null, 'created_at' => $now,
            ]);

            $this->settings($entityId, $in, $now);
            $this->approvals($entityId, $now);
            $periods = $this->year($entityId, $in, $now);
            $userId = $this->firstUser($in, $entityId, $now);

            $this->insert('audit_events', [
                'entity_id' => $entityId, 'occurred_at' => $now, 'actor_user_id' => $userId, 'action' => 'installed',
                'object_type' => 'entity', 'object_id' => $entityId, 'object_ref' => $in['entityCode'],
                'summary' => mb_substr($in['registeredName'] . ' installed by ' . $in['userName'] . ' — head office ' . $in['entityCode']
                    . ', financial year ' . $in['yearCode'] . ' opened with ' . count($periods) . ' months', 0, 255),
            ]);

            $this->db->transComplete();
        } catch (\Throwable $e) {
            if ($this->db->transDepth > 0) {
                $this->db->transRollback();
            }

            throw $e;
        }

        Repository::forget();
        $link = $in['userPassword'] === '' ? (new AuthRepository($this->db))->setPasswordLink($userId) : null;

        return [
            'entity' => $in['entityCode'] . ' · ' . $in['entityName'],
            'year'   => $in['yearCode'] . ' · ' . $in['yearStarts'] . ' to ' . $in['yearEnds'],
            'periods' => count($periods),
            'user'   => $in['userName'] . ' <' . $in['userEmail'] . '>',
            'role'   => self::FIRST_ROLE,
            // Where the first user chooses a password, when the answers did not give one.
            'link'   => $link,
        ];
    }

    /** What is still to be done once the instance exists, in the order it is done. */
    public static function nextSteps(): array
    {
        return [
            'Settings → Ledger, Segments, Currencies and Approvals: check the defaults against how the organisation actually works.',
            'Chart of accounts → Import: load the chart. Imported accounts open at zero — only journals move a balance.',
            'Settings → Opening balances: carry the trial balance from the old system. It becomes a draft journal for approval.',
            'Settings → Users: invite a second person. The conversion cannot be approved by whoever loaded it.',
        ];
    }

    // ------------------------------------------------------------------
    // Checking the answers
    // ------------------------------------------------------------------

    /** @return array<string, string> */
    private function validated(array $answers): array
    {
        $in = [];
        foreach (self::questions() as $key => [, , $default]) {
            $value = trim((string) ($answers[$key] ?? ''));
            $in[$key] = $value !== '' ? $value : (string) ($default ?? '');
            if ($in[$key] === '' && $default === null) {
                throw new RuleViolation(self::questions()[$key][0] . ' is needed before the instance can be installed.');
            }
        }

        $settings = \App\Repositories\SettingsRepository::class;
        $this->oneOf($in['framework'], $settings::FRAMEWORKS, 'Reporting framework');
        $this->oneOf($in['yearEnd'], array_keys(self::YEAR_STARTS), 'Financial year end');
        $this->oneOf($in['codeLength'], $settings::CODE_LENGTHS, 'Account code length');

        $in['entityCode'] = mb_strtoupper($in['entityCode']);
        if (preg_match('/^[A-Z0-9][A-Z0-9-]{1,19}$/', $in['entityCode']) !== 1) {
            throw new RuleViolation('The head office code is 2 to 20 letters, digits and hyphens, e.g. ACME-HQ. It is fixed once saved.');
        }

        $in['currency'] = mb_strtoupper($in['currency']);
        if ($this->count('currencies', ['code' => $in['currency']]) === 0) {
            throw new RuleViolation($in['currency'] . ' is not a currency this instance holds. Add it in Settings → Currencies after installing, or use one of the currencies seeded with the baseline.');
        }

        if (filter_var($in['userEmail'], FILTER_VALIDATE_EMAIL) === false) {
            throw new RuleViolation($in['userEmail'] . ' is not an email address.');
        }
        if (!str_contains(trim($in['userName']), ' ')) {
            throw new RuleViolation('Give the first user\'s full name, so the ledger can show who prepared and who approved each entry.');
        }
        if ($in['userPassword'] !== '') {
            (new AuthRepository($this->db))->assertStrong($in['userPassword'], ['email' => $in['userEmail']]);
        }

        if (preg_match('/^\d{4}$/', $in['firstYear']) !== 1) {
            throw new RuleViolation('The first financial year is a four-digit year, e.g. ' . date('Y') . '.');
        }

        // A year ending 30 June 2027 opens on 1 July 2026 and is called FY2027.
        $endMonth = self::YEAR_STARTS[$in['yearEnd']] === 1 ? 12 : self::YEAR_STARTS[$in['yearEnd']] - 1;
        $endYear = (int) $in['firstYear'];
        $starts = date('Y-m-d', mktime(0, 0, 0, self::YEAR_STARTS[$in['yearEnd']], 1, $endMonth === 12 ? $endYear : $endYear - 1));
        $in['yearCode']   = 'FY' . $endYear;
        $in['yearStarts'] = $starts;
        $in['yearEnds']   = date('Y-m-t', strtotime($starts . ' +11 months'));

        return $in;
    }

    private function oneOf(string $value, array $allowed, string $label): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new RuleViolation($label . ' is one of ' . implode(', ', $allowed) . ', not "' . $value . '".');
        }
    }

    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    /** The reporting basis, the posting controls and the appearance, all held on the head office. */
    private function settings(int $entityId, array $in, string $now): void
    {
        $row = fn (string $key, string $kind, string $value, string $label, ?string $note = null) => $this->insert('settings', [
            'entity_id' => $entityId, 'key' => $key, 'kind' => $kind, 'value' => $value,
            'label' => $label, 'note' => $note, 'created_at' => $now,
        ]);

        foreach ([['framework', 'Framework'], ['yearEnd', 'Financial year end'], ['codeLength', 'Account code length']] as [$key, $label]) {
            $row($key, 'choice', $in[$key], $label);
        }
        foreach (BaselineSeeder::TOGGLES as $key => [$label, $note, $on]) {
            $row($key, 'toggle', $on ? '1' : '0', $label, $note);
        }

        $settings = \App\Repositories\SettingsRepository::class;
        $row($settings::QUOTE_THRESHOLD_KEY, 'approvals', (string) $settings::QUOTE_THRESHOLD_DEFAULT, $settings::QUOTE_THRESHOLD_LABEL);

        $row(Theme::KEY, 'appearance', Theme::DEFAULT, 'Interface theme', \App\Repositories\SettingsRepository::THEME_NOTE);
        $row(Theme::CUSTOM_KEY, 'appearance', (string) json_encode(Theme::CUSTOM_DEFAULT), 'Custom theme colours');
        $row(Brand::NAME_KEY, 'appearance', mb_substr($in['shortName'], 0, Brand::MAX_NAME), 'Application name');
        $row(Brand::TAGLINE_KEY, 'appearance', Brand::DEFAULT_TAGLINE, 'Line under the application name');
        $row(Brand::LOGO_KEY, 'appearance', '', 'Logo');
        $row('formatsLocked', 'language', '1',
            "Hold numbers, dates and currency in the organisation's reporting locale",
            'Recommended. Finance staff, auditors and funders read the same figure the same way in every language, so a report cannot be misread as a different amount.');
    }

    /** The approval policy the instance starts with. */
    private function approvals(int $entityId, string $now): void
    {
        foreach (BaselineSeeder::APPROVAL_RULES as [$type, $label, $threshold, $approver, $escalation]) {
            // "Board Treasurer" and "Funder consent above 10%" are authorities outside
            // the system, not roles: they are satisfied by recording their reference.
            $escalationId = $escalation === null ? null : $this->findRole($escalation);
            $this->insert('approval_rules', [
                'entity_id' => $entityId, 'document_type' => $type, 'label' => $label, 'threshold' => $threshold,
                'approver_role_id' => $this->roleId($approver), 'escalation_role_id' => $escalationId,
                // An escalation naming no role is an authority outside the system.
                'escalation_note' => $escalationId === null ? $escalation : null, 'created_at' => $now,
            ]);
        }
    }

    /** The first financial year and its twelve months, all open. */
    private function year(int $entityId, array $in, string $now): array
    {
        $yearId = $this->insert('fiscal_years', [
            'entity_id' => $entityId, 'code' => $in['yearCode'], 'starts_on' => $in['yearStarts'],
            'ends_on' => $in['yearEnds'], 'status' => 'open', 'created_at' => $now,
        ]);

        $periods = [];
        for ($month = 0; $month < 12; $month++) {
            $starts = date('Y-m-01', strtotime($in['yearStarts'] . ' +' . $month . ' months'));
            $periods[] = $starts;
            $this->insert('periods', [
                'entity_id' => $entityId, 'fiscal_year_id' => $yearId, 'code' => date('Y-m', strtotime($starts)),
                'name' => date('M Y', strtotime($starts)), 'starts_on' => $starts, 'ends_on' => date('Y-m-t', strtotime($starts)),
                'status' => 'open', 'archived_journals' => 0, 'archived_value' => 0, 'created_at' => $now,
            ]);
        }

        return $periods;
    }

    /** The first person, and the system user that owns what nobody signed for. */
    private function firstUser(array $in, int $entityId, string $now): int
    {
        [$first, $last] = explode(' ', trim($in['userName']), 2) + [1 => ''];
        $localeId = $this->db->table('locales')->select('id')->where('code', BaselineSeeder::LOCALE['code'])->get()->getRowArray()['id'] ?? null;

        $userId = $this->insert('users', [
            'email' => mb_strtolower($in['userEmail']), 'name' => $in['userName'],
            'short_name' => mb_substr($first, 0, 1) . '. ' . $last,
            'initials' => mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1)),
            'locale_id' => $localeId, 'status' => 'active', 'created_at' => $now,
            'password_hash' => $in['userPassword'] === '' ? null : password_hash($in['userPassword'], PASSWORD_DEFAULT),
            'password_changed_at' => $in['userPassword'] === '' ? null : $now,
        ]);
        $this->insert('user_entity_roles', [
            'user_id' => $userId, 'entity_id' => $entityId, 'role_id' => $this->roleId(self::FIRST_ROLE), 'created_at' => $now,
        ]);

        $this->insert('users', [
            'email' => self::SYSTEM_EMAIL, 'name' => 'Data migration (system)', 'short_name' => 'System',
            'initials' => 'SY', 'status' => 'suspended', 'created_at' => $now,
        ]);

        return $userId;
    }

    // ------------------------------------------------------------------
    // Small things
    // ------------------------------------------------------------------

    private function roleId(string $name): int
    {
        return $this->findRole($name)
            ?? throw new RuleViolation('The role ' . $name . ' is missing. Run `php spark db:seed BaselineSeeder` first.');
    }

    private function findRole(string $name): ?int
    {
        $row = $this->db->table('roles')->select('id')->where('name', $name)->get()->getRowArray();

        return $row === null ? null : (int) $row['id'];
    }

    private function insert(string $table, array $row): int
    {
        $this->db->table($table)->insert($row);

        return (int) $this->db->insertID();
    }

    private function count(string $table, array $where = []): int
    {
        $builder = $this->db->table($table);

        return ($where === [] ? $builder : $builder->where($where))->countAllResults();
    }
}
