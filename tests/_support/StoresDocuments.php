<?php

namespace Tests\Support;

use App\Repositories\AttachmentRepository;
use App\Repositories\Lookups;
use CodeIgniter\Test\TestResponse;
use Config\Documents;

/**
 * Uploads supporting documents the way the screens do — on their own first,
 * waiting to be attached — so a test can send their ids with a form, and removes
 * the stored files afterwards.
 *
 * CIUnitTestCase calls tearDownStoresDocuments() after each test.
 */
trait StoresDocuments
{
    /** @var list<string> */
    private array $documentTemp = [];

    /**
     * An upload waiting to be attached, held by the person named (a short name,
     * email or user id). Returns its id.
     */
    protected function document(int|string $who, string $name = 'supplier-invoice.pdf'): int
    {
        $id = is_int($who) ? $who : (new Lookups())->userId($who);

        return (new AttachmentRepository())->upload($this->documentFile($name), $id)['id'];
    }

    /** A file as a form's upload arrives, for the modules that take files with the form (journals, quotations). */
    protected function documentFile(string $name = 'board-minute.pdf'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, '%PDF-1.4 ' . $name);
        $this->documentTemp[] = $path;

        return ['path' => $path, 'name' => $name, 'size' => filesize($path), 'mime' => 'application/pdf'];
    }

    /**
     * Asserts a download answers with the document, on whichever disk .env keeps
     * them. On disk the file is sent. In S3 (LocalStack while developing,
     * ./localstack.sh) the answer is a redirect to a short-lived presigned link,
     * which is followed to check the bucket returns the file as it was uploaded.
     */
    protected function assertDownloads(TestResponse $response, string $contents): void
    {
        $config = config(Documents::class);
        if ($config->disk !== 's3') {
            $response->assertStatus(200);

            return;
        }

        $response->assertStatus(302);
        $link = $response->response()->getHeaderLine('Location');
        if ($config->s3Endpoint !== '') {
            $this->assertStringStartsWith(rtrim($config->s3Endpoint, '/') . '/', $link, 'The link points at the configured S3 endpoint.');
        }

        $body = @file_get_contents($link, false, stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]));
        $this->assertNotFalse($body, 'S3 did not answer at ' . $link . '. Is LocalStack running? (./localstack.sh)');
        $status = (int) (preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m) === 1 ? $m[1] : 0);
        $this->assertSame(200, $status, 'The presigned link was refused: ' . mb_substr((string) $body, 0, 300));
        $this->assertSame($contents, $body);
    }

    protected function tearDownStoresDocuments(): void
    {
        foreach (db_connect()->table('attachments')->get()->getResultArray() as $a) {
            if (str_starts_with($a['storage_key'], 'documents/') || str_starts_with($a['storage_key'], 'journals/') || str_starts_with($a['storage_key'], 'quotations/')) {
                @unlink(WRITEPATH . 'uploads/' . $a['storage_key']);
            }
        }
        foreach ($this->documentTemp as $path) {
            @unlink($path);
        }
        $this->documentTemp = [];
    }
}
