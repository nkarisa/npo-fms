<?php

namespace App\Commands;

use App\Libraries\Storage\S3Store;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Documents;

/**
 * Checks that the S3 bucket is ready for supporting documents: the credentials
 * work, Object Lock and versioning are on, a lifecycle rule tidies pending/, and
 * a file can be written, found, linked to and removed.
 *
 *     php spark documents:check
 */
class DocumentsCheck extends BaseCommand
{
    protected $group       = 'Setup';
    protected $name        = 'documents:check';
    protected $description = 'Checks the S3 bucket that keeps supporting documents.';

    public function run(array $params)
    {
        $config = config(Documents::class);
        if ($config->disk !== 's3') {
            CLI::write('documents.disk is ' . $config->disk . ': documents are kept under writable/uploads. Nothing to check.');

            return EXIT_SUCCESS;
        }

        try {
            $store = service('documents');
            assert($store instanceof S3Store);
            CLI::write('Bucket ' . $config->s3Bucket . ' in ' . $config->s3Region . ($config->s3Prefix !== '' ? ', folder ' . $config->s3Prefix : '')
                . ' · lock ' . $config->lockMode . ' for ' . $config->retentionYears . ' years · links last ' . $config->linkSeconds . 's');

            $setup = $store->bucketSetup();
            $failed = false;
            foreach ([
                'lock' => ['Object Lock is on', 'Object Lock is off. It can only be turned on when the bucket is created (or by AWS support): create a new bucket with Object Lock.'],
                'versioning' => ['Versioning is on', 'Versioning is off. Object Lock needs it.'],
                'pendingRule' => ['A lifecycle rule expires pending/', 'No lifecycle rule expires pending/. Add one (2 days) so uploads nobody attached do not pile up.'],
            ] as $key => [$ok, $bad]) {
                $setup[$key] ? CLI::write('  ✓ ' . $ok, 'green') : CLI::write('  ✗ ' . $bad, $key === 'pendingRule' ? 'yellow' : 'red');
                $failed = $failed || (!$setup[$key] && $key !== 'pendingRule');
            }

            $probe = tempnam(sys_get_temp_dir(), 'chk');
            file_put_contents($probe, 'documents:check ' . date(DATE_ATOM));
            $key = $store->uploadDir() . '/check-' . bin2hex(random_bytes(6)) . '.txt';
            $store->put($key, $probe, 'text/plain', hash_file('sha256', $probe), false);
            $found = $store->exists($key) && $store->matches($key, hash_file('sha256', $probe));
            $store->link($key, 'check.txt', 'text/plain');
            $store->delete($key);
            @unlink($probe);
            $found ? CLI::write('  ✓ A file was written, read back whole, linked to and removed', 'green') : CLI::write('  ✗ A file was written but could not be read back', 'red');

            return $failed || !$found ? EXIT_ERROR : EXIT_SUCCESS;
        } catch (\Throwable $e) {
            CLI::error('The bucket could not be reached: ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }
}
