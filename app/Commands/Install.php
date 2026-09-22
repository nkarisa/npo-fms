<?php

namespace App\Commands;

use App\Libraries\Installer;
use App\Repositories\RuleViolation;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Stands a new instance up: the organisation, its head office, the first financial
 * year and the first person who can sign anything off.
 *
 *     php spark migrate
 *     php spark db:seed BaselineSeeder
 *     php spark install
 *
 * Asks for each answer in turn, or takes them all from a JSON file:
 *
 *     php spark install --config=install.json    (or --config install.json)
 *     php spark install --show-config --no-header > install.json
 *
 * A relative path is read from the directory spark is run in.
 *
 * Nothing is written until every answer has been checked, and it is written in one
 * transaction, so a refusal leaves the instance exactly as it was.
 */
class Install extends BaseCommand
{
    protected $group       = 'Setup';
    protected $name        = 'install';
    protected $description = 'Creates the organisation, its head office, its first financial year and its first user.';
    protected $usage       = 'install [--config=file.json] [--show-config] [--yes]';
    protected $arguments   = [];
    protected $options     = [
        '--config'      => 'A JSON file holding the answers, for an unattended install.',
        '--show-config' => 'Prints an empty answers file and exits, writing nothing. Add --no-header to redirect it to a file.',
        '--yes'         => 'Skips the confirmation. Implied when --config is given.',
    ];

    public function run(array $params)
    {
        if (array_key_exists('show-config', $params) || CLI::getOption('show-config')) {
            CLI::write(json_encode($this->template(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return EXIT_SUCCESS;
        }

        $installer = new Installer();
        if ($refusal = $installer->refusal()) {
            CLI::error($refusal);

            return EXIT_ERROR;
        }

        $file = self::answersFile(CLI::getOptions(), (string) getenv('PWD'));
        try {
            $answers = is_string($file) ? $this->fromFile($file) : $this->ask();
        } catch (RuleViolation $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        if (!is_string($file) && !CLI::getOption('yes') && strtolower(CLI::prompt('Install with these answers?', ['y', 'n'])) !== 'y') {
            CLI::write('Nothing was written.', 'yellow');

            return EXIT_SUCCESS;
        }

        try {
            $done = $installer->install($answers);
        } catch (RuleViolation $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        } catch (Throwable $e) {
            CLI::error('The install was rolled back: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        CLI::newLine();
        CLI::write('Installed.', 'green');
        foreach (['Head office' => $done['entity'], 'Financial year' => $done['year'] . ' · ' . $done['periods'] . ' months, all open',
            'First user' => $done['user'] . ' · ' . $done['role']] as $label => $value) {
            CLI::write('  ' . str_pad($label, 16) . $value);
        }
        // Only worth listing when there is more than the head office just named.
        foreach (count($done['entities']) > 1 ? array_slice($done['entities'], 1) : [] as $i => $entity) {
            CLI::write('  ' . str_pad($i === 0 ? 'Also opened' : '', 16) . $entity);
        }

        if ($done['link'] !== null) {
            CLI::newLine();
            CLI::write('Choose your password here (the link works once, for seven days):', 'yellow');
            CLI::write('  ' . $done['link']);
            CLI::write('  ' . CLI::color('Lost it? `php spark user:link ' . explode('<', rtrim($done['user'], '>'))[1] . '` prints a new one.', 'dark_gray'));
        }

        CLI::newLine();
        CLI::write('Next:', 'yellow');
        foreach (Installer::nextSteps() as $i => $step) {
            CLI::write('  ' . ($i + 1) . '. ' . $step);
        }
        CLI::newLine();

        return EXIT_SUCCESS;
    }

    // ------------------------------------------------------------------
    // Answers
    // ------------------------------------------------------------------

    /** @return array<string, string> */
    private function ask(): array
    {
        CLI::write('Setting up a new instance. Press enter to take the answer in brackets.', 'yellow');
        CLI::newLine();

        $answers = [];
        foreach (Installer::questions() as $key => [$prompt, $note, $default]) {
            // A password typed here would echo; the install prints a link to choose it instead.
            if ($key === 'userPassword') {
                continue;
            }
            CLI::write('  ' . CLI::color($note, 'dark_gray'));
            $answers[$key] = $default === null
                ? CLI::prompt($prompt, null, 'required')
                : CLI::prompt($prompt, $default);
            CLI::newLine();
        }

        return $answers;
    }

    /**
     * The answers file --config names, or null when there is none and the answers
     * are asked for instead.
     *
     * CodeIgniter only reads `--config file`; `--config=file` reaches it as an option
     * named `config=file` with no value, so both spellings are read here. A relative
     * path is taken from the directory spark was run in ($cwd) — spark itself has
     * already moved into public/ — or from the project root when that is unknown.
     *
     * @param array<string, string|null> $options
     */
    public static function answersFile(array $options, string $cwd): ?string
    {
        $path = null;
        foreach ($options as $name => $value) {
            if ($name === 'config') {
                $path = (string) $value;
            } elseif (str_starts_with((string) $name, 'config=')) {
                $path = substr((string) $name, strlen('config='));
            }
        }

        if ($path === null || $path === '' || str_starts_with($path, '/')) {
            return $path;
        }

        $base = $cwd !== '' && is_dir($cwd) ? $cwd : ROOTPATH;

        return rtrim($base, '/') . '/' . $path;
    }

    /** @return array<string, string> */
    private function fromFile(string $path): array
    {
        if ($path === '') {
            throw new RuleViolation('--config needs the answers file: `php spark install --config=install.json`.');
        }
        if (!is_file($path)) {
            throw new RuleViolation($path . ' does not exist. Write the answers file with `php spark install --show-config --no-header > ' . $path . '`.');
        }

        $answers = json_decode((string) file_get_contents($path), true);
        if (!is_array($answers)) {
            throw new RuleViolation($path . ' is not a JSON object of answers.');
        }

        $unknown = array_diff(array_keys($answers), array_keys(Installer::questions()), Installer::EXTRAS);
        if ($unknown !== []) {
            throw new RuleViolation($path . ' holds answers this installer does not ask for: ' . implode(', ', $unknown) . '.');
        }

        // The extras are a list and a flag, not text: everything else is a string.
        $extras = array_intersect_key($answers, array_flip(Installer::EXTRAS));

        return array_map('strval', array_diff_key($answers, $extras)) + $extras;
    }

    /** @return array<string, string> */
    private function template(): array
    {
        return array_map(static fn ($q) => (string) ($q[2] ?? ''), Installer::questions());
    }
}
