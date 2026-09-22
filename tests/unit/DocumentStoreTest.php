<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Libraries\Storage\S3Store;
use App\Repositories\AssetRepository;
use App\Repositories\AttachmentRepository;
use App\Repositories\Lookups;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\S3\S3Client;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;

/**
 * Supporting documents kept in S3 with Object Lock (S3Store), against a stand-in
 * for S3 that records what the application asked it to do. The attachments table
 * keeps each file's name and storage key; downloads are presigned links issued
 * after the application's own check.
 */
final class DocumentStoreTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use \Tests\Support\SignsIn;
    use \Tests\Support\StoresDocuments;
    use \CodeIgniter\Test\StreamFilterTrait;

    protected $namespace = 'App';
    protected $refresh   = true;
    protected $seed      = DatabaseSeeder::class;

    /** @var list<CommandInterface> what S3 was asked to do */
    private array $sent = [];

    /** An operation S3 refuses, for the failure test. */
    private ?string $refuse = null;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['app.asOf'] = '2026-08-31';
        Repository::forget();
        Services::injectMock('documents', $this->s3());
    }

    protected function tearDown(): void
    {
        Services::resetSingle('documents');
        parent::tearDown();
    }

    public function testAFileStoredWithItsRecordIsLockedAndSentWithItsChecksum(): void
    {
        $file = $this->documentFile('board-minute.pdf');
        $this->s3()->put('journals/x.pdf', $file['path'], 'application/pdf', hash_file('sha256', $file['path']), true);

        $put = $this->only('PutObject');
        $this->assertSame('fms/journals/x.pdf', $put['Key']);
        $this->assertSame('COMPLIANCE', $put['ObjectLockMode']);
        $this->assertSame(base64_encode(hash_file('sha256', $file['path'], true)), $put['ChecksumSHA256']);
        $years = (strtotime((string) $put['ObjectLockRetainUntilDate']) - time()) / (365.25 * 86400);
        $this->assertEqualsWithDelta(7, $years, 0.01);
    }

    public function testAnUploadWaitsUnlockedAndIsLockedWhenARecordClaimsIt(): void
    {
        $njeri = (new Lookups())->userId('s.njeri@elog.or.ke');
        $id = $this->document($njeri, 'purchase-invoice.pdf');

        $row = db_connect()->table('attachments')->where('id', $id)->get()->getRowArray();
        $this->assertStringStartsWith('pending/', $row['storage_key']);
        $this->assertSame('purchase-invoice.pdf', $row['filename']);
        $this->assertArrayNotHasKey('ObjectLockMode', $this->only('PutObject'), 'a waiting upload can still be removed');

        $this->sent = [];
        $tag = (new AssetRepository())->register()[0]['tag'];
        (new AttachmentRepository())->attach('asset', $tag, [$id], $njeri);

        $copy = $this->only('CopyObject');
        $claimed = 'documents/' . substr($row['storage_key'], strlen('pending/'));
        $this->assertSame('fms/' . $claimed, $copy['Key']);
        $this->assertSame('fms-documents/fms/' . $row['storage_key'], $copy['CopySource']);
        $this->assertSame('COMPLIANCE', $copy['ObjectLockMode']);
        // The table keeps where the file is now, never a link to it.
        $this->seeInDatabase('attachments', ['id' => $id, 'object_type' => 'asset', 'storage_key' => $claimed]);
        $this->assertStringNotContainsString('http', implode(' ', db_connect()->table('attachments')->where('id', $id)->get()->getRowArray()));
    }

    public function testADownloadIsARedirectToALinkThatExpiresInMinutes(): void
    {
        $njeri = (new Lookups())->userId('s.njeri@elog.or.ke');
        $id = $this->document($njeri, 'Mkataba wa ruzuku.pdf');

        $this->signIn('s.njeri@elog.or.ke');
        $response = $this->get('api/attachments/' . $id);
        $response->assertStatus(302);
        $link = $response->response()->getHeaderLine('Location');
        $key = db_connect()->table('attachments')->where('id', $id)->get()->getRow()->storage_key;

        $this->assertStringStartsWith('https://fms-documents.s3.af-south-1.amazonaws.com/fms/' . $key, $link);
        $this->assertStringContainsString('X-Amz-Expires=120', $link);
        $this->assertStringContainsString('X-Amz-Signature=', $link);
        $this->assertStringContainsString(rawurlencode("attachment; filename=\"Mkataba wa ruzuku.pdf\"; filename*=UTF-8''Mkataba%20wa%20ruzuku.pdf"), $link);
        $this->assertStringContainsString('no-store', $response->response()->getHeaderLine('Cache-Control'));

        // Someone the application would not show it to gets no link at all.
        $this->signIn('j.achieng@elog.or.ke');
        $this->get('api/attachments/' . $id)->assertStatus(404);
    }

    public function testWhenS3RefusesTheUploadNothingIsRecorded(): void
    {
        $this->refuse = 'PutObject';
        $before = db_connect()->table('attachments')->countAllResults();

        try {
            $this->document('s.njeri@elog.or.ke');
            $this->fail('Expected a refusal.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('cannot be stored right now', $e->getMessage());
        }
        $this->assertSame($before, db_connect()->table('attachments')->countAllResults());
    }

    public function testRemovingAWaitingUploadDeletesItFromTheBucket(): void
    {
        $njeri = (new Lookups())->userId('s.njeri@elog.or.ke');
        $id = $this->document($njeri);
        $key = db_connect()->table('attachments')->where('id', $id)->get()->getRow()->storage_key;

        $this->sent = [];
        (new AttachmentRepository())->discard($id, $njeri);
        $this->assertSame('fms/' . $key, $this->only('DeleteObject')['Key']);
    }

    public function testDocumentsOnTheServersDiskAreMovedIntoTheBucket(): void
    {
        // Two documents kept on disk before the switch: one on a record, one still waiting.
        Services::injectMock('documents', new \App\Libraries\Storage\LocalStore());
        $njeri = (new Lookups())->userId('s.njeri@elog.or.ke');
        $filed = $this->document($njeri, 'deed-of-gift.pdf');
        (new AttachmentRepository())->attach('asset', (new AssetRepository())->register()[0]['tag'], [$filed], $njeri);
        $waiting = $this->document($njeri, 'receipt.jpg');
        $rows = array_column(db_connect()->table('attachments')->whereIn('id', [$filed, $waiting])->get()->getResultArray(), null, 'id');

        Services::injectMock('documents', $this->s3());
        config(\Config\Documents::class)->disk = 's3';
        $this->sent = [];
        try {
            command('documents:migrate');
        } finally {
            \CodeIgniter\Config\Factories::reset('config');
        }

        $puts = array_column(array_map(static fn ($c) => $c->toArray(), array_filter($this->sent, static fn ($c) => $c->getName() === 'PutObject')), null, 'Key');
        $filedPut = $puts['fms/' . $rows[$filed]['storage_key']] ?? $this->fail('the attached document was not copied');
        $this->assertSame('COMPLIANCE', $filedPut['ObjectLockMode']);
        $this->assertSame(base64_encode((string) hex2bin($rows[$filed]['sha256'])), $filedPut['ChecksumSHA256']);

        $this->assertStringContainsString('Copied 2 · already in the bucket 0 · missing 0 · changed 0 · failed 0', $this->getStreamFilterBuffer());

        // The waiting upload goes to pending/, unlocked, and its row follows it.
        $moved = 'pending/' . basename($rows[$waiting]['storage_key']);
        $this->assertArrayNotHasKey('ObjectLockMode', $puts['fms/' . $moved]);
        $this->seeInDatabase('attachments', ['id' => $waiting, 'storage_key' => $moved]);
        @unlink(WRITEPATH . 'uploads/' . $rows[$waiting]['storage_key']);
    }

    public function testLockingCanBeTurnedOff(): void
    {
        $store = new S3Store($this->client(), 'fms-documents', '', 'OFF');
        $file = $this->documentFile();
        $store->put('journals/y.pdf', $file['path'], 'application/pdf', hash_file('sha256', $file['path']), true);

        $this->assertArrayNotHasKey('ObjectLockMode', $this->only('PutObject'));
    }

    // ------------------------------------------------------------------

    private function s3(): S3Store
    {
        return new S3Store($this->client(), 'fms-documents', 'fms', 'COMPLIANCE', 7, 120);
    }

    /** An S3 client whose requests never leave the test: each is recorded and answered. */
    private function client(): S3Client
    {
        return new S3Client([
            'version' => 'latest', 'region' => 'af-south-1',
            'credentials' => ['key' => 'AKIATEST', 'secret' => 'test-secret'],
            'handler' => function (CommandInterface $command, RequestInterface $request) {
                $this->sent[] = $command;
                if ($command->getName() === $this->refuse) {
                    return Create::rejectionFor(new AwsException('Access Denied', $command, ['code' => 'AccessDenied']));
                }

                return Create::promiseFor(new Result([]));
            },
        ]);
    }

    /** The one request of a kind S3 was sent. */
    private function only(string $operation): array
    {
        $found = array_values(array_filter($this->sent, static fn ($c) => $c->getName() === $operation));
        $this->assertCount(1, $found, 'S3 ' . $operation . ' requests');

        return $found[0]->toArray();
    }
}
