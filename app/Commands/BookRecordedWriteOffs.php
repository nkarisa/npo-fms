<?php

namespace App\Commands;

use App\Repositories\Lookups;
use App\Repositories\ReceivablesRepository;
use App\Repositories\RuleViolation;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Posts the ledger entries for claims recorded as written off that have none —
 * on a database seeded before the allowance for doubtful debts existed. Running
 * it again books nothing twice.
 */
class BookRecordedWriteOffs extends BaseCommand
{
    protected $group       = 'Finance';
    protected $name        = 'receivables:book-write-offs';
    protected $description = 'Posts the allowance and write-off entries for claims recorded as written off without any.';

    public function run(array $params)
    {
        $system = (new Lookups())->userId('data-migration@system.invalid');
        if ($system === null) {
            CLI::error('The system user data-migration@system.invalid is missing; seed the database first.');

            return EXIT_ERROR;
        }

        try {
            $booked = (new ReceivablesRepository())->bookRecordedWriteOffs($system);
        } catch (RuleViolation $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write($booked === [] ? 'Every written-off claim is already in the ledger.' : 'Booked ' . implode(', ', $booked) . '.', 'green');

        return EXIT_SUCCESS;
    }
}
