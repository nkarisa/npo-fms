<?php

namespace App\Commands;

use App\Libraries\Storage\LocalStore;
use App\Libraries\Storage\S3Store;
use App\Repositories\AttachmentRepository;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Documents;

/**
 * Copies the documents kept under writable/uploads into the S3 bucket, once
 * documents.disk is s3. Each file is checked against the SHA-256 recorded when it
 * was uploaded, sent with that checksum so S3 refuses a damaged copy, and locked
 * like any new document. An upload still waiting for a record goes to pending/,
 * unlocked. Files already in the bucket are skipped, so it can be run again.
 *
 *     php spark documents:migrate --dry-run
 *     php spark documents:migrate
 *
 * The local files are left in place. Remove them once the report is clean and
 * the application has been tried against the bucket.
 */
class DocumentsMigrate extends BaseCommand
{
    protected $group       = 'Setup';
    protected $name        = 'documents:migrate';
    protected $description = 'Copies supporting documents from writable/uploads to the S3 bucket.';
    protected $usage       = 'documents:migrate [--dry-run]';
    protected $options     = ['--dry-run' => 'List what would be copied without copying it.'];

    public function run(array $params)
    {
        if (config(Documents::class)->disk !== 's3') {
            CLI::error('Set documents.disk = s3 and the bucket settings in .env first. `php spark documents:check` tests them.');

            return EXIT_ERROR;
        }
        $s3 = service('documents');
        assert($s3 instanceof S3Store);
        $local = new LocalStore();
        $dry = CLI::getOption('dry-run') !== null || in_array('--dry-run', $params, true);
        $db = db_connect();

        $count = ['copied' => 0, 'already' => 0, 'missing' => 0, 'changed' => 0, 'failed' => 0];
        foreach ($db->table('attachments')->orderBy('id')->get()->getResultArray() as $a) {
            $label = '#' . $a['id'] . ' ' . $a['filename'] . ' (' . $a['object_type'] . ')';
            $waiting = $a['object_type'] === AttachmentRepository::UPLOAD;
            $key = $waiting ? $s3->uploadDir() . '/' . basename($a['storage_key']) : $a['storage_key'];

            if ($s3->matches($key, $a['sha256'])) {
                $count['already']++;
                continue;
            }
            $path = $local->path($a['storage_key']);
            if (!is_file($path)) {
                $count['missing']++;
                CLI::write('  missing   ' . $label . ' — no file at ' . $path, 'red');
                continue;
            }
            if (hash_file('sha256', $path) !== $a['sha256']) {
                $count['changed']++;
                CLI::write('  changed   ' . $label . ' — the file no longer matches the SHA-256 recorded at upload; not copied', 'red');
                continue;
            }
            if ($dry) {
                $count['copied']++;
                CLI::write('  would copy ' . $label . ' → ' . $key);
                continue;
            }

            try {
                $s3->put($key, $path, $a['mime_type'], $a['sha256'], !$waiting);
                if ($key !== $a['storage_key']) {
                    $db->table('attachments')->where('id', $a['id'])->update(['storage_key' => $key]);
                }
                $count['copied']++;
            } catch (\Throwable $e) {
                $count['failed']++;
                CLI::write('  failed    ' . $label . ' — ' . $e->getMessage(), 'red');
            }
        }

        CLI::write(($dry ? 'Would copy ' : 'Copied ') . $count['copied'] . ' · already in the bucket ' . $count['already']
            . ' · missing ' . $count['missing'] . ' · changed ' . $count['changed'] . ' · failed ' . $count['failed'],
            $count['missing'] + $count['changed'] + $count['failed'] === 0 ? 'green' : 'yellow');

        return $count['failed'] === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
