<?php

namespace App\Libraries\Storage;

use App\Repositories\RuleViolation;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Config\Documents;

/**
 * Documents in an S3 bucket created with Object Lock (and so versioning) turned on.
 *
 *   pending/…     uploads waiting for a record. Not locked; a lifecycle rule on the
 *                 bucket expires what is left there after two days.
 *   documents/…   uploads a record has claimed, copied here and locked.
 *   journals/…, quotations/…, statements/…   files stored with their record,
 *                 locked as they are written.
 *
 * A locked file cannot be deleted or overwritten by anyone, the bucket's owner
 * included, until its retention date: Config\Documents::$retentionYears from when
 * it was locked. Deleting it only adds a delete marker; the locked version stays.
 *
 * Every write sends the file's SHA-256, so S3 refuses a file that did not arrive
 * whole. Downloads are presigned GET links that expire after
 * Config\Documents::$linkSeconds, issued only after the application has checked
 * who is asking. The bucket stays private and no link is ever stored.
 */
final class S3Store implements DocumentStore
{
    public const PENDING = 'pending';
    public const CLAIMED = 'documents';

    public function __construct(
        private readonly S3Client $s3,
        private readonly string $bucket,
        private readonly string $prefix = '',
        private readonly string $lockMode = 'COMPLIANCE',
        private readonly int $retentionYears = 7,
        private readonly int $linkSeconds = 120,
    ) {
    }

    public static function fromConfig(Documents $c): self
    {
        if ($c->s3Bucket === '') {
            throw new \RuntimeException('documents.disk is s3, but documents.s3Bucket is not set.');
        }
        $options = ['version' => 'latest', 'region' => $c->s3Region];
        // Without a key the SDK finds credentials itself: an instance role, AWS_* variables, ~/.aws.
        if ($c->s3Key !== '') {
            $options['credentials'] = ['key' => $c->s3Key, 'secret' => $c->s3Secret];
        }
        if ($c->s3Endpoint !== '') {
            $options['endpoint'] = $c->s3Endpoint;
        }
        if ($c->s3PathStyle) {
            $options['use_path_style_endpoint'] = true;
        }

        return new self(new S3Client($options), $c->s3Bucket, $c->s3Prefix, strtoupper($c->lockMode), $c->retentionYears, $c->linkSeconds);
    }

    public function uploadDir(): string
    {
        return self::PENDING;
    }

    public function put(string $key, string $source, string $mime, string $sha256, bool $lock): void
    {
        $this->call('PutObject', [
            'Key' => $this->object($key), 'SourceFile' => $source, 'ContentType' => $mime,
            'ChecksumAlgorithm' => 'SHA256', 'ChecksumSHA256' => base64_encode((string) hex2bin($sha256)),
        ] + ($lock ? $this->retention() : []));
    }

    public function lock(string $key): string
    {
        if (!str_starts_with($key, self::PENDING . '/')) {
            return $key; // stored with its record, and locked then
        }
        $claimed = self::CLAIMED . substr($key, strlen(self::PENDING));
        $this->call('CopyObject', [
            'Key' => $this->object($claimed),
            'CopySource' => $this->bucket . '/' . str_replace('%2F', '/', rawurlencode($this->object($key))),
            'ChecksumAlgorithm' => 'SHA256',
        ] + $this->retention());

        // The copy under pending/ is left for the bucket's lifecycle rule: removing it
        // now would lose the file if the record's transaction rolls back.
        return $claimed;
    }

    public function delete(string $key): void
    {
        try {
            $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $this->object($key)]);
        } catch (AwsException $e) {
            log_message('warning', 'Could not remove {key} from S3: {error}', ['key' => $key, 'error' => $e->getAwsErrorMessage() ?? $e->getMessage()]);
        }
    }

    public function exists(string $key): bool
    {
        return $this->s3->doesObjectExistV2($this->bucket, $this->object($key));
    }

    public function link(string $key, string $filename, string $mime): ?string
    {
        $command = $this->s3->getCommand('GetObject', [
            'Bucket' => $this->bucket, 'Key' => $this->object($key),
            'ResponseContentType' => $mime,
            'ResponseContentDisposition' => self::disposition($filename),
        ]);

        return (string) $this->s3->createPresignedRequest($command, '+' . $this->linkSeconds . ' seconds')->getUri();
    }

    public function path(string $key): ?string
    {
        return null;
    }

    /**
     * Whether the stored file is the one recorded: S3's own SHA-256 of it matches.
     * Used after a migration and by `documents:check`.
     */
    public function matches(string $key, string $sha256): bool
    {
        try {
            $head = $this->s3->headObject(['Bucket' => $this->bucket, 'Key' => $this->object($key), 'ChecksumMode' => 'ENABLED']);
        } catch (AwsException) {
            return false;
        }

        return ($head['ChecksumSHA256'] ?? null) === base64_encode((string) hex2bin($sha256));
    }

    /**
     * What the bucket is set up to do, for `documents:check`: Object Lock and
     * versioning on, and a lifecycle rule tidying pending/.
     *
     * @return array{lock: bool, versioning: bool, pendingRule: bool}
     */
    public function bucketSetup(): array
    {
        $lock = $this->s3->getObjectLockConfiguration(['Bucket' => $this->bucket]);
        $versioning = $this->s3->getBucketVersioning(['Bucket' => $this->bucket]);
        try {
            $rules = $this->s3->getBucketLifecycleConfiguration(['Bucket' => $this->bucket])['Rules'] ?? [];
        } catch (AwsException) {
            $rules = []; // no lifecycle configuration at all
        }
        $pending = $this->object(self::PENDING . '/');
        $pendingRule = (bool) array_filter($rules, static fn ($r) => ($r['Status'] ?? '') === 'Enabled' && isset($r['Expiration']['Days'])
            && str_starts_with($pending, (string) ($r['Filter']['Prefix'] ?? $r['Prefix'] ?? "\0")));

        return [
            'lock' => ($lock['ObjectLockConfiguration']['ObjectLockEnabled'] ?? '') === 'Enabled',
            'versioning' => ($versioning['Status'] ?? '') === 'Enabled',
            'pendingRule' => $pendingRule,
        ];
    }

    // ------------------------------------------------------------------

    private function object(string $key): string
    {
        return $this->prefix === '' ? $key : trim($this->prefix, '/') . '/' . $key;
    }

    /** Lock headers: the mode, and a retain-until date counted from now, not the books' date. */
    private function retention(): array
    {
        if ($this->lockMode === '' || $this->lockMode === 'OFF' || $this->retentionYears <= 0) {
            return [];
        }

        return [
            'ObjectLockMode' => $this->lockMode,
            'ObjectLockRetainUntilDate' => gmdate('Y-m-d\TH:i:s\Z', strtotime('+' . $this->retentionYears . ' years')),
        ];
    }

    private function call(string $operation, array $args): void
    {
        try {
            $this->s3->execute($this->s3->getCommand($operation, ['Bucket' => $this->bucket] + $args));
        } catch (AwsException $e) {
            log_message('error', 'S3 {op} of {key} failed: {error}', ['op' => $operation, 'key' => $args['Key'], 'error' => $e->getAwsErrorCode() . ' ' . ($e->getAwsErrorMessage() ?? $e->getMessage())]);

            throw new RuleViolation('Supporting documents cannot be stored right now. Try again in a few minutes; if it keeps happening, tell whoever runs the system.');
        }
    }

    /** Opens as a download under its own name, including names that are not plain ASCII. */
    private static function disposition(string $filename): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $filename);

        return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
    }
}
