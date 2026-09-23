<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Database\Seeds\BaselineSeeder;
use App\Database\Seeds\DatabaseSeeder;
use App\Libraries\DatabaseProbe;
use App\Libraries\EntityScope;
use App\Libraries\EnvFile;
use App\Libraries\InstallState;
use App\Libraries\Installer;
use App\Libraries\PasswordPolicy;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use App\Repositories\SettingsRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\MigrationRunner;
use Config\Database as DatabaseConfig;
use Config\Migrations as MigrationsConfig;
use Throwable;

/**
 * Standing a new instance up from a browser: the same work as `php spark install`,
 * asked for a step at a time.
 *
 * Deliberately not a BaseApiController. That one reads the locale table, the acting
 * user and their permissions before it will answer anything — none of which exists
 * here, which is the whole point. So the responses are plain JSON and say nothing
 * about a session.
 *
 * Every answer comes back with the step the wizard is now on, and the client draws
 * that step (public/assets/js/install.js, after the sign-in screens). The server
 * decides where the wizard is, so a reload carries on rather than starting again,
 * and a refusal that cannot be moved past leaves it where it was.
 *
 * The answers are checked as they are given, so a mistake is caught on the step it
 * was made on. They are checked again by App\Libraries\Installer when it writes,
 * because that is the only place that has to be right.
 *
 * Nothing is written until the last four calls — except a database the person
 * asks to have created on the database step. Then, in this order:
 *
 *   env      the database credentials, into .env
 *   migrate  the schema
 *   seed     the reference data, or the whole demonstration organisation
 *   finish   the organisation, its entities, its first year and its first user
 *
 * env goes first on purpose: once it has run, an install that is abandoned half way
 * through can be finished from the shell with `php spark migrate` and
 * `php spark install`. Each of the four is a separate request, so none of them
 * depends on .env having been re-read, and each builds its own connection from the
 * credentials the wizard is holding rather than trusting the one this process
 * booted with.
 */
class Install extends BaseController
{
    /** What the wizard calls each step, in order. */
    private const LABELS = [
        'server'       => 'the server',
        'database'     => 'the database',
        'data'         => 'what to put in it',
        'organisation' => 'the organisation',
        'entities'     => 'its entities',
        'user'         => 'the first user',
        'review'       => 'check and install',
        'done'         => 'done',
    ];

    // ------------------------------------------------------------------
    // Where the wizard is
    // ------------------------------------------------------------------

    /** Everything the wizard needs to draw itself, whatever step it is on. */
    public function index()
    {
        return $this->state();
    }

    /** The token from writable/install.token, which an instance off this machine needs. */
    public function token()
    {
        if (!InstallState::tokenRequired($this->request)) {
            InstallState::authorise();

            return $this->state('This installer is open because you are on the server itself.');
        }

        if (!InstallState::accepts((string) $this->body('token'))) {
            return $this->refused(new RuleViolation(
                'That is not the setup key for this instance. It is in writable/' . InstallState::TOKEN_FILE
                . ' on the server; copy it exactly.'
            ));
        }

        InstallState::authorise();

        return $this->state('Setup key accepted.');
    }

    // ------------------------------------------------------------------
    // The answers
    // ------------------------------------------------------------------

    /**
     * The database server and a user to reach it with — for SQLite, only that it is
     * SQLite. Answered with the databases that user can see, to choose from on the
     * next step, and whether a new one may be created.
     */
    public function server()
    {
        if ($stop = $this->guard()) {
            return $stop;
        }

        $given = [
            'driver'   => (string) $this->body('driver', 'MySQLi'),
            'hostname' => (string) $this->body('hostname', 'localhost'),
            'port'     => (string) $this->body('port', '3306'),
            'username' => (string) $this->body('username'),
            'password' => (string) $this->body('password'),
            'prefix'   => (string) $this->body('prefix'),
        ];
        // Held whether or not it connects, so the form comes back filled in.
        InstallState::put(['server' => $given, 'databases' => null, 'report' => null]);

        try {
            $probe     = new DatabaseProbe(DatabaseProbe::config($given, false));
            $databases = $probe->databases();
            $canCreate = $probe->canCreate();
        } catch (RuleViolation $e) {
            return $this->refused($e);
        } catch (Throwable $e) {
            return $this->refused(new RuleViolation('The database server could not be reached: ' . $e->getMessage()));
        }

        InstallState::put(['databases' => ['list' => $databases, 'canCreate' => $canCreate]]);
        InstallState::setStep('database');

        $usable = count(array_filter($databases, static fn ($d) => $d['usable']));
        $sqlite = $given['driver'] === 'SQLite3';

        return $this->state(match (true) {
            $databases === [] => $sqlite ? 'No database files in writable/ yet.' : 'Connected. This user cannot see any databases yet.',
            default           => 'Connected. ' . $usable . ' of ' . count($databases) . ' '
                . ($sqlite ? 'database files' : 'databases') . ' can be installed into.',
        });
    }

    /**
     * The database to install into: one from the list, one typed by name, or — where
     * the user may — a new one, created empty with the right character set. Reached,
     * read and proved able to hold the schema before it is accepted.
     */
    public function database()
    {
        if ($stop = $this->guard('database')) {
            return $stop;
        }

        $server = (array) InstallState::get('server', []);
        $name   = trim((string) $this->body('database'));
        $create = filter_var($this->body('create', false), FILTER_VALIDATE_BOOLEAN);

        try {
            if ($create) {
                if (!(bool) (InstallState::get('databases')['canCreate'] ?? false)) {
                    throw new RuleViolation('This user cannot create a database. Choose one from the list, or ask the administrator to create one.');
                }
                $name = (new DatabaseProbe(DatabaseProbe::config($server, false)))->create($name);
            }
            $given  = ['database' => $name] + $server;
            $probe  = new DatabaseProbe(DatabaseProbe::config($given));
            $report = $probe->report();
        } catch (RuleViolation $e) {
            return $this->refused($e);
        } catch (Throwable $e) {
            return $this->refused(new RuleViolation('The database could not be reached: ' . $e->getMessage()));
        }

        InstallState::put(['database' => $given, 'report' => $report]);
        if ($create) {
            $this->refreshList($server);
        }

        if (!$report['ok']) {
            return $this->response->setStatusCode(422)->setJSON($this->payload($report['refusal']) + ['error' => $report['refusal']]);
        }

        InstallState::setStep('data');

        return $this->state(($create ? $report['database'] . ' created. ' : $report['database'] . ' is ready. ') . $report['schema']['note']);
    }

    /** The list again, after a database was added to it. */
    private function refreshList(array $server): void
    {
        try {
            $held = (array) InstallState::get('databases', []);
            InstallState::put(['databases' => ['list' => (new DatabaseProbe(DatabaseProbe::config($server, false)))->databases()] + $held]);
        } catch (Throwable $e) {
            // The list is a convenience; the database itself was already reported on.
        }
    }

    /** A new instance, or the demonstration organisation. */
    public function data()
    {
        if ($stop = $this->guard('data')) {
            return $stop;
        }

        $choice = (string) $this->body('data');
        if (!in_array($choice, ['new', 'demo'], true)) {
            return $this->refused(new RuleViolation('Choose either a new instance or the demonstration data.'));
        }

        // A copy of the demonstration organisation carries published passwords; on a
        // production server that is asked for twice.
        if ($choice === 'demo' && self::production() && !filter_var($this->body('confirm', false), FILTER_VALIDATE_BOOLEAN)) {
            return $this->refused(new RuleViolation('This server runs in production. Tick the box to confirm the demonstration '
                . 'organisation is wanted here, knowing its passwords are published.'));
        }

        InstallState::put(['data' => $choice]);
        InstallState::setStep($choice === 'demo' ? 'review' : 'organisation');

        return $this->state($choice === 'demo'
            ? 'The demonstration organisation it is. Nothing else to answer — it brings its own.'
            : 'A new instance it is.');
    }

    /** The organisation, its reporting basis and the first financial year. */
    public function organisation()
    {
        if ($stop = $this->guard('organisation')) {
            return $stop;
        }

        $given = [];
        foreach (['registeredName', 'shortName', 'taxPin', 'registrationNo', 'currency', 'framework', 'yearEnd', 'codeLength', 'firstYear'] as $key) {
            $given[$key] = trim((string) $this->body($key, (string) (Installer::questions()[$key][2] ?? '')));
        }

        try {
            $this->checkAnswers($given + $this->entityDefaults($given));
        } catch (RuleViolation $e) {
            InstallState::put(['organisation' => $given]);

            return $this->refused($e);
        }

        InstallState::put(['organisation' => $given]);
        InstallState::setStep('entities');

        return $this->state($given['shortName'] . ' it is.');
    }

    /**
     * The head office, and any other entity to open with it. The head office is
     * required: an organisation is at least one reporting entity, and everything
     * consolidates into it.
     */
    public function entities()
    {
        if ($stop = $this->guard('entities')) {
            return $stop;
        }

        $head = [
            'entityCode' => trim((string) $this->body('entityCode')),
            'entityName' => trim((string) $this->body('entityName')),
        ];
        $more = $this->body('entities');
        $more = is_array($more) ? array_values($more) : [];

        $organisation = (array) InstallState::get('organisation', []);

        try {
            $this->checkAnswers($organisation + $head, $more);
        } catch (RuleViolation $e) {
            InstallState::put(['head' => $head, 'branches' => $more]);

            return $this->refused($e);
        }

        InstallState::put(['head' => $head, 'branches' => $more]);
        InstallState::setStep('user');

        return $this->state($more === []
            ? $head['entityCode'] . ' will be the head office.'
            : $head['entityCode'] . ' and ' . count($more) . ' more ' . (count($more) === 1 ? 'entity' : 'entities') . ' will be opened.');
    }

    /**
     * The first user, who holds every permission at every entity — and approves as
     * the Finance Manager (App\Libraries\Installer::FIRST_ROLE).
     */
    public function user()
    {
        if ($stop = $this->guard('user')) {
            return $stop;
        }

        $given = [
            'userName'       => trim((string) $this->body('userName')),
            'userEmail'      => trim((string) $this->body('userEmail')),
            'userPassword'   => (string) $this->body('userPassword'),
        ];

        try {
            // What was just typed first: "+" keeps the left-hand value, and what is
            // held for these keys is still empty.
            $this->checkAnswers($given + $this->answers());
        } catch (RuleViolation $e) {
            InstallState::put(['user' => ['userName' => $given['userName'], 'userEmail' => $given['userEmail']]]);

            return $this->refused($e);
        }

        InstallState::put(['user' => $given]);
        InstallState::setStep('review');

        return $this->state('Ready to install.');
    }

    /** Back a step, without losing what was typed. */
    public function back()
    {
        if ($stop = $this->guard()) {
            return $stop;
        }

        $steps = InstallState::STEPS;
        $at    = (int) array_search(InstallState::step(), $steps, true);
        // From the review of a demonstration install, back is the data choice: the
        // organisation steps were never asked.
        $to = $at <= 0 ? $steps[0] : ($steps[$at] === 'review' && InstallState::get('data') === 'demo' ? 'data' : $steps[$at - 1]);
        InstallState::setStep($to);

        return $this->state();
    }

    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    /** The credentials, into .env, so every request after this one connects. */
    public function env()
    {
        if ($stop = $this->guard('review')) {
            return $stop;
        }

        try {
            $probe  = new DatabaseProbe(DatabaseProbe::config((array) InstallState::get('database', [])));
            $values = $probe->envValues();
            // The demonstration instance is signed into with a password alone; its
            // passwords are published, so a second step would be theatre.
            if (InstallState::get('data') === 'demo') {
                $values['auth.mfaRequired'] = 'optional';
            }
            EnvFile::set($values);
            $key = EnvFile::ensureEncryptionKey();
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->state('Configuration written to .env.' . ($key ? ' An encryption key was generated.' : ''));
    }

    /** The schema. Forward only: nothing already there is removed. */
    public function migrate()
    {
        if ($stop = $this->guard('review')) {
            return $stop;
        }

        return $this->longRunning(function (BaseConnection $db) {
            $before = $this->migrationCount($db);
            $runner = new MigrationRunner(new MigrationsConfig(), $db);
            $runner->setSilent(true)->latest();
            $after = $this->migrationCount($db);

            $ran = $after - $before;

            return $this->state($ran === 0
                ? 'The schema was already up to date.'
                : $ran . ' ' . ($ran === 1 ? 'migration' : 'migrations') . ' run; ' . count($db->listTables()) . ' tables.');
        });
    }

    /** The reference data, or the whole demonstration organisation. */
    public function seed()
    {
        if ($stop = $this->guard('review')) {
            return $stop;
        }

        $demo = InstallState::get('data') === 'demo';

        return $this->longRunning(function (BaseConnection $db) use ($demo) {
            $seeder = $demo ? new DatabaseSeeder(new DatabaseConfig(), $db) : new BaselineSeeder(new DatabaseConfig(), $db);
            $seeder->setSilent(true)->run();
            Repository::forget();
            EntityScope::forget();

            return $this->state($demo
                ? 'The demonstration organisation is loaded.'
                : $db->table('roles')->countAllResults() . ' roles and ' . $db->table('permissions')->countAllResults() . ' permissions seeded.');
        });
    }

    /**
     * The organisation, its entities, its first financial year and its first user,
     * in the one transaction App\Libraries\Installer has always written them in.
     *
     * The demonstration organisation has already been written by its seeder, so
     * there is nothing to install: this only closes the installer.
     */
    public function finish()
    {
        if ($stop = $this->guard('review')) {
            return $stop;
        }

        $demo = InstallState::get('data') === 'demo';

        return $this->longRunning(function (BaseConnection $db) use ($demo) {
            $done = $demo ? $this->finishDemo($db) : $this->finishNew($db);

            InstallState::lock([
                'organisation' => $done['organisation'],
                'entities'     => implode(', ', $done['codes']),
                'by'           => $done['user'],
            ]);
            InstallState::forgetToken();
            InstallState::setStep('done');
            // The database password goes out of the session the moment it is in .env
            // and used; what is left is only what the done screen shows.
            InstallState::put(['server' => null, 'database' => null, 'databases' => null, 'user' => null, 'done' => $done]);

            return $this->state('Installed.');
        });
    }

    // ------------------------------------------------------------------

    private function finishNew(BaseConnection $db): array
    {
        $answers   = $this->answers();
        $installer = new Installer($db);
        if ($refusal = $installer->refusal()) {
            throw new RuleViolation($refusal);
        }

        $done = $installer->install($answers);

        return [
            'organisation' => $answers['registeredName'],
            'entities'     => $done['entities'],
            'codes'        => $this->codes($db),
            'year'         => $done['year'],
            'periods'      => $done['periods'],
            'user'         => $done['user'],
            'role'         => $done['role'],
            'link'         => $done['link'],
            'signIn'       => '/login',
            'next'         => Installer::nextSteps(),
            'demo'         => false,
            'note'         => 'You hold every permission at every entity, and approve as the Finance Manager. Whoever prepares an '
                . 'entry cannot also approve it, whatever permissions they hold, so the second '
                . 'person below is not optional. Your first sign-in will ask you to set up a second step and will show '
                . 'recovery codes: keep them.',
            // This screen cannot be returned to: the installer closed as it was drawn.
            'linkNote'     => $done['link'] === null ? null
                : 'This page is shown once — the installer has closed. Follow the link now, or have a new one printed on the '
                    . 'server with `php spark user:link ' . ($answers['userEmail'] ?? '') . '`.',
        ];
    }

    private function finishDemo(BaseConnection $db): array
    {
        $t    = static fn (string $table) => $db->prefixTable($table);
        $head = $db->table('entities')->select('name')->where('parent_id', null)->orderBy('id')->get(1)->getRowArray();
        $who  = $db->query(
            'SELECT u.name, u.email FROM ' . $t('users') . ' u JOIN ' . $t('user_entity_roles') . ' ur ON ur.user_id = u.id'
            . ' JOIN ' . $t('role_permissions') . ' rp ON rp.role_id = ur.role_id'
            . ' JOIN ' . $t('permissions') . " p ON p.id = rp.permission_id
             WHERE u.status = 'active' AND p.key = 'settings.organisation' ORDER BY u.id LIMIT 1"
        )->getRowArray();

        return [
            'organisation' => (string) ($head['name'] ?? 'the demonstration organisation'),
            'entities'     => array_map(static fn ($e) => $e['code'] . ' · ' . $e['name'], $db->table('entities')->select('code, name')->orderBy('id')->get()->getResultArray()),
            'codes'        => $this->codes($db),
            'year'         => '',
            'periods'      => 0,
            'user'         => trim((string) ($who['name'] ?? '') . ' <' . ($who['email'] ?? '') . '>'),
            'role'         => '',
            'link'         => null,
            'signIn'       => '/login',
            'next'         => [
                'Sign in as ' . ($who['email'] ?? 'any of the seeded users') . '. Every demonstration user shares one password, published in docs/setup.md.',
                'This data is for looking at. Nothing here is anybody\'s real books, and the passwords are public.',
                'To stand up a real instance, point the installer at an empty database and choose a new instance.',
            ],
            'demo'  => true,
            'note'  => 'A second sign-in step is switched off on this instance (auth.mfaRequired = optional), because the '
                . 'passwords are published.',
            'linkNote' => null,
        ];
    }

    /** @return list<string> */
    private function codes(BaseConnection $db): array
    {
        return array_column($db->table('entities')->select('code')->orderBy('id')->get()->getResultArray(), 'code');
    }

    private function migrationCount(BaseConnection $db): int
    {
        try {
            $table = (new MigrationsConfig())->table;
            if (!in_array(strtolower($db->prefixTable($table)), array_map('strtolower', $db->listTables()), true)) {
                return 0;
            }

            return (int) $db->table($table)->countAllResults();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Runs one of the writing steps on a connection of its own, built from the
     * credentials the wizard holds rather than from whatever this process booted
     * with — .env may have been written only moments ago, by an earlier request.
     *
     * Migrating and seeding take longer than a page usually may, and a browser that
     * gives up must not take the schema with it half built.
     */
    private function longRunning(callable $work)
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        try {
            $probe = new DatabaseProbe(DatabaseProbe::config((array) InstallState::get('database', [])));
            $db    = $probe->connect();
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        try {
            return $work($db);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        } catch (Throwable $e) {
            log_message('error', 'Install step failed: {message}', ['message' => $e->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON($this->payload() + [
                'error' => $this->failure($e),
            ]);
        } finally {
            $db->close();
        }
    }

    /**
     * A failure during the writing steps, said plainly. The schema is DDL and MySQL
     * does not keep that in a transaction, so a migration that dies leaves the
     * schema part built and there is no honest way to unpick it here.
     */
    private function failure(Throwable $e): string
    {
        return 'The install stopped: ' . $e->getMessage()
            . ' Nothing more will be written. If this happened while building the schema, the database is now part built: '
            . 'ask the administrator to drop and recreate it empty, then start again. The application\'s log has the detail.';
    }

    // ------------------------------------------------------------------
    // Checking
    // ------------------------------------------------------------------

    /**
     * Everything answered so far, in the shape App\Libraries\Installer takes.
     *
     * @return array<string, mixed>
     */
    private function answers(): array
    {
        $held = InstallState::all();
        $user = (array) ($held['user'] ?? []);

        return (array) ($held['organisation'] ?? [])
            + (array) ($held['head'] ?? [])
            + ['userName' => (string) ($user['userName'] ?? ''), 'userEmail' => (string) ($user['userEmail'] ?? ''),
                'userPassword' => (string) ($user['userPassword'] ?? '')]
            + ['entities' => (array) ($held['branches'] ?? [])];
    }

    /**
     * Checks what has been answered so far with the installer's own rules, so a
     * step refuses in the same words the install would have.
     *
     * The checks want every answer, and the wizard only has some of them yet, so
     * what is missing is filled with something that will pass. Only the answers the
     * step actually gave can fail here.
     *
     * @param array<string, mixed> $given
     * @param list<mixed> $entities
     */
    private function checkAnswers(array $given, array $entities = []): void
    {
        $filled = $given + [
            'entityCode' => 'TMP-HQ', 'entityName' => 'Temporary', 'userName' => 'A Person',
            'userEmail' => 'a.person@example.invalid', 'userPassword' => '',
        ];
        foreach (Installer::questions() as $key => [, , $default]) {
            if (!isset($filled[$key]) || $filled[$key] === '') {
                $filled[$key] = (string) ($default ?? '');
            }
        }
        $filled['entities'] = $entities;

        // Checked against the database it will be written to, so a currency this
        // instance does not hold is refused on the step that named it rather than at
        // the last one.
        $db = null;
        try {
            $db = (new DatabaseProbe(DatabaseProbe::config((array) InstallState::get('database', []))))->connect();
        } catch (Throwable $e) {
            // Not reachable from here; the writing steps will say so.
        }

        try {
            (new Installer($db))->check($filled);
        } finally {
            $db?->close();
        }
    }

    /** @return array<string, string> */
    private function entityDefaults(array $given): array
    {
        return ['entityCode' => 'TMP-HQ', 'entityName' => trim((string) ($given['shortName'] ?? 'Temporary')) ?: 'Temporary'];
    }

    // ------------------------------------------------------------------
    // Responses
    // ------------------------------------------------------------------

    /**
     * Refuses to go on when the installer has not been unlocked, or when the wizard
     * has not reached $step yet — so a request made out of order cannot skip one.
     * A step already passed may be answered again, as it is after going back.
     */
    private function guard(?string $step = null)
    {
        if (!InstallState::authorised($this->request)) {
            return $this->response->setStatusCode(403)->setJSON($this->payload() + [
                'error' => 'The setup key for this instance is needed first. It is in writable/' . InstallState::TOKEN_FILE . ' on the server.',
            ]);
        }

        if ($step === null) {
            return null;
        }

        $steps = InstallState::STEPS;
        $want  = (int) array_search($step, $steps, true);
        if ((int) array_search(InstallState::step(), $steps, true) < $want) {
            $before = $steps[max(0, $want - 1)];

            return $this->response->setStatusCode(409)->setJSON($this->payload() + [
                'error' => 'That step comes after ' . (self::LABELS[$before] ?? $before) . ', which has not been answered yet.',
            ]);
        }

        return null;
    }

    private function refused(RuleViolation $e)
    {
        return $this->response->setStatusCode(422)->setJSON($this->payload() + ['error' => $e->getMessage()]);
    }

    private function state(?string $message = null)
    {
        return $this->response->setJSON($this->payload($message));
    }

    /** @return array<string, mixed> */
    private function payload(?string $message = null): array
    {
        $held = InstallState::all();
        $database = (array) ($held['database'] ?? []);
        $server = (array) ($held['server'] ?? []);
        unset($database['password'], $server['password']);

        return [
            'step'      => InstallState::step(),
            'steps'     => self::LABELS,
            'message'   => $message,
            'authorised' => InstallState::authorised($this->request),
            'token'     => [
                'required' => InstallState::tokenRequired($this->request),
                'path'     => 'writable/' . InstallState::TOKEN_FILE,
            ],
            'preflight' => $this->preflight(),
            'options'   => $this->options(),
            'answers'   => [
                'server'       => $server,
                'databases'    => $held['databases'] ?? null,
                'database'     => $database,
                'report'       => $held['report'] ?? null,
                'data'         => $held['data'] ?? null,
                'organisation' => $held['organisation'] ?? null,
                'head'         => $held['head'] ?? null,
                'branches'     => $held['branches'] ?? [],
                // Never the password, in either direction.
                'user'         => array_diff_key((array) ($held['user'] ?? []), ['userPassword' => true]),
            ],
            'done'      => $held['done'] ?? null,
        ];
    }

    /** What has to be true of this server before anything else is worth asking. */
    private function preflight(): array
    {
        $rows = [];
        $row = static function (string $label, bool $ok, string $note, bool $blocks = true) use (&$rows) {
            $rows[] = ['label' => $label, 'ok' => $ok, 'blocks' => $blocks && !$ok, 'note' => $note];
        };

        $row('PHP ' . PHP_VERSION, PHP_VERSION_ID >= 80200, PHP_VERSION_ID >= 80200 ? 'Recent enough.' : 'This application needs PHP 8.2 or newer.');

        foreach (['intl', 'mbstring', 'fileinfo', 'openssl', 'json'] as $extension) {
            $loaded = extension_loaded($extension);
            $row('PHP extension ' . $extension, $loaded, $loaded ? 'Loaded.' : 'Needed, and not loaded. Add it to this server\'s PHP.');
        }

        $drivers = DatabaseProbe::available();
        $row('A database driver', $drivers !== [], $drivers !== []
            ? implode(' and ', array_keys($drivers)) . ' available.'
            : 'Neither the mysqli nor the sqlite3 extension is loaded, so there is no database this can be installed into.');

        foreach (['writable/' => WRITEPATH, 'writable/cache/' => WRITEPATH . 'cache/', 'writable/logs/' => WRITEPATH . 'logs/',
            'writable/session/' => WRITEPATH . 'session/', 'writable/uploads/' => WRITEPATH . 'uploads/'] as $label => $path) {
            $ok = is_dir($path) && is_writable($path);
            $row($label, $ok, $ok ? 'Writable.' : (is_dir($path) ? 'Not writable by the web server.' : 'Missing.'));
        }

        $envOk = EnvFile::writable();
        $row('.env', $envOk, $envOk ? 'Writable; the database credentials will be written here.' : 'Cannot be written, so the credentials have nowhere to go.');

        $hasKey = EnvFile::value('encryption.key') !== '';
        $row('Encryption key', $hasKey, $hasKey
            ? 'Set. Authenticator secrets can be encrypted.'
            : 'Not set yet. One will be generated when the configuration is written — without it nobody can set up an authenticator app.', false);

        return [
            'rows'   => $rows,
            'ok'     => array_filter($rows, static fn ($r) => $r['blocks']) === [],
        ];
    }

    /** Everything the forms offer, all of it from constants so none of it needs a database. */
    private function options(): array
    {
        $questions = Installer::questions();

        return [
            'drivers'   => DatabaseProbe::available(),
            'charset'   => DatabaseProbe::CHARSET,
            'collation' => DatabaseProbe::COLLATION,
            'frameworks' => SettingsRepository::FRAMEWORKS,
            'yearEnds'   => array_keys(Installer::YEAR_STARTS),
            'codeLengths' => SettingsRepository::CODE_LENGTHS,
            // From the seeder, because these are exactly the currencies it writes.
            'currencies' => array_map(static fn ($c) => ['code' => $c[0], 'name' => $c[1]], BaselineSeeder::CURRENCIES),
            'entityTypes' => Installer::BRANCH_TYPES,
            'adminRole'  => BaselineSeeder::ADMIN_ROLE,
            'firstRole'  => Installer::FIRST_ROLE,
            'permissionCount' => count(BaselineSeeder::PERMISSIONS),
            // No policy can have been saved yet, so the first password meets the standard one.
            'minPasswordLength' => PasswordPolicy::standard()['minLength'],
            'passwordRules' => PasswordPolicy::describe(PasswordPolicy::standard()),
            'production' => self::production(),
            'notes'      => array_map(static fn ($q) => ['prompt' => $q[0], 'note' => $q[1], 'default' => $q[2]], $questions),
        ];
    }

    private static function production(): bool
    {
        return ENVIRONMENT === 'production';
    }

    /** One value from the request body, whether it came as JSON or as a form. */
    private function body(string $key, mixed $default = '')
    {
        $json = $this->request->getJSON(true);
        if (is_array($json) && array_key_exists($key, $json)) {
            return $json[$key];
        }

        return $this->request->getPost($key) ?? $default;
    }
}
