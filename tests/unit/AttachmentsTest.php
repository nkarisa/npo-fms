<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\AssetRepository;
use App\Repositories\AttachmentRepository;
use App\Repositories\DonorReportRepository;
use App\Repositories\Lookups;
use App\Repositories\ReceivablesRepository;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Supporting documents: uploaded on their own, attached by the form that creates
 * a record or later from the record's own screen, and opened only by people who
 * reach the entity they belong to. The required ones are tested with their
 * modules (Payables, Advances, Grants, Journals, Procurement).
 */
final class AttachmentsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use \Tests\Support\SignsIn;
    use \Tests\Support\StoresDocuments;

    protected $namespace = 'App';
    protected $refresh   = true;
    protected $seed      = DatabaseSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['app.asOf'] = '2026-08-31';
        Repository::forget();
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['elog_actor']);
        service('superglobals')->unsetCookie('elog_actor');
        parent::tearDown();
    }

    public function testOnlyDocumentTypesTheAuditFileAcceptsAreKept(): void
    {
        $repo = new AttachmentRepository();
        $exe = $this->documentFile('payload.exe');

        $this->expectException(RuleViolation::class);
        $this->expectExceptionMessage('payload.exe is not a document type the audit file accepts');
        $repo->upload($exe, (new Lookups())->userId('s.njeri@elog.or.ke'));
    }

    public function testRecommendedDocumentsAreAddedToRecordsThatAlreadyExist(): void
    {
        $this->actAs('s.njeri@elog.or.ke');
        $tag = (new AssetRepository())->register()[0]['tag'];
        $report = (new DonorReportRepository())->all()[0]['ref'];
        $invoice = (new ReceivablesRepository())->all()[0]['no'];

        $asset = $this->send('api/documents/asset/' . rawurlencode($tag), ['documents' => [$this->document('s.njeri@elog.or.ke', 'purchase-invoice.pdf')]]);
        $this->assertSame(['purchase-invoice.pdf'], array_column($asset['documents'], 'name'));
        $this->assertSame(['purchase-invoice.pdf'], array_column($this->api('api/assets/' . rawurlencode($tag))['documents'], 'name'));
        $this->seeInDatabase('audit_events', ['object_ref' => $tag, 'summary' => 'Supporting document added: purchase-invoice.pdf']);

        $this->send('api/documents/donor_report/' . rawurlencode($report), ['documents' => [$this->document('s.njeri@elog.or.ke', 'report-as-submitted.pdf')]]);
        Repository::forget();
        $filed = current(array_filter((new DonorReportRepository())->all(), static fn ($r) => $r['ref'] === $report));
        $this->assertSame(['report-as-submitted.pdf'], array_column($filed['attachments'], 'name'));

        $this->send('api/documents/invoice/' . rawurlencode($invoice), ['documents' => [$this->document('s.njeri@elog.or.ke', 'donor-request.pdf')]]);
        Repository::forget();
        $this->assertSame(['donor-request.pdf'], array_column((new ReceivablesRepository())->find($invoice)['documents'], 'name'));

        // Nothing to attach, a record that is not there, and a kind that takes no documents.
        $this->assertStringContainsString('Choose at least one document', $this->refused('api/documents/asset/' . rawurlencode($tag), ['documents' => []], 422));
        $this->assertStringContainsString('There is no asset NOPE-1', $this->refused('api/documents/asset/NOPE-1', ['documents' => [$this->document('s.njeri@elog.or.ke')]], 422));
        $this->refused('api/documents/payroll/2026-08', ['documents' => [$this->document('s.njeri@elog.or.ke')]], 404);

        // The auditor looks; they do not add to the file.
        $this->actAs('audit@pkfea.com');
        $this->assertStringContainsString('cannot add documents to assets', $this->refused('api/documents/asset/' . rawurlencode($tag), ['documents' => [$this->document('audit@pkfea.com')]], 403));
    }

    public function testACountCanCarryAPhotoOfWhatWasFound(): void
    {
        $this->actAs('s.njeri@elog.or.ke');
        $tag = (new AssetRepository())->countSheet()[0]['tag'];

        $result = $this->send('api/asset-verification/' . $tag, ['result' => 'Condition issue', 'note' => 'Screen cracked', 'documents' => [$this->document('s.njeri@elog.or.ke', 'cracked-screen.jpg')]]);
        $this->assertSame('Condition issue', $result['result']['result']);
        $this->assertSame(['cracked-screen.jpg'], array_column($result['result']['documents'], 'name'));
    }

    public function testADocumentOpensOnlyForPeopleWhoReachItsEntity(): void
    {
        $njeri = (new Lookups())->userId('s.njeri@elog.or.ke');
        $mine = $this->document($njeri, 'draft-invoice.pdf');

        // While it waits to be attached, it is the uploader's alone.
        $this->signIn('s.njeri@elog.or.ke');
        $this->assertDownloads($this->get('api/attachments/' . $mine), '%PDF-1.4 draft-invoice.pdf');
        $this->signIn('j.achieng@elog.or.ke');
        $this->get('api/attachments/' . $mine)->assertStatus(404);
        $this->assertStringContainsString('Only a document still waiting to be attached can be removed', $this->refused('api/attachments/' . $mine . '/discard', [], 422));

        // Once on a record, anyone holding a role at its entity may open it.
        $this->signIn('s.njeri@elog.or.ke');
        $tag = (new AssetRepository())->register()[0]['tag'];
        (new AttachmentRepository())->attach('asset', $tag, [$mine], $njeri);
        $this->signIn('j.achieng@elog.or.ke');
        $this->assertDownloads($this->get('api/attachments/' . $mine), '%PDF-1.4 draft-invoice.pdf');

        // An upload nobody attached can be taken back by the person who made it.
        $this->signIn('s.njeri@elog.or.ke');
        $spare = $this->document($njeri, 'wrong-file.pdf');
        $this->send('api/attachments/' . $spare . '/discard', []);
        $this->dontSeeInDatabase('attachments', ['id' => $spare]);
    }

    public function testUploadsNobodyAttachedAreRemovedAfterADay(): void
    {
        $njeri = (new Lookups())->userId('s.njeri@elog.or.ke');
        $old = $this->document($njeri, 'forgotten.pdf');
        $key = db_connect()->table('attachments')->where('id', $old)->get()->getRow()->storage_key;
        db_connect()->table('attachments')->where('id', $old)->update(['uploaded_at' => '2026-08-29 09:00:00']);

        $this->document($njeri, 'today.pdf');

        $this->dontSeeInDatabase('attachments', ['id' => $old]);
        $this->assertFileDoesNotExist(WRITEPATH . 'uploads/' . $key);
        $this->assertStringContainsString('no longer waiting to be attached', $this->catch(static fn () => (new AttachmentRepository())->pending([$old], $njeri)));
    }

    // ------------------------------------------------------------------

    private function actAs(string $email): void
    {
        $_COOKIE['elog_actor'] = $email;
        service('superglobals')->setCookie('elog_actor', $email);
    }

    private function api(string $url): array
    {
        return json_decode($this->get($url)->getJSON(), true);
    }

    private function send(string $url, array $body): array
    {
        $response = $this->withBodyFormat('json')->post($url, $body);
        $json = json_decode($response->getJSON(), true) ?? [];
        $this->assertSame(200, $response->response()->getStatusCode(), $url . ': ' . ($json['error'] ?? ''));

        return $json;
    }

    private function refused(string $url, array $body, int $status): string
    {
        $response = $this->withBodyFormat('json')->post($url, $body);
        $this->assertSame($status, $response->response()->getStatusCode(), $url);

        return (string) (json_decode($response->getJSON(), true)['error'] ?? '');
    }

    private function catch(callable $call): string
    {
        try {
            $call();
        } catch (RuleViolation $e) {
            return $e->getMessage();
        }
        $this->fail('Expected a refusal.');
    }
}
