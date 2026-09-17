<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\JournalRepository;
use App\Repositories\Lookups;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The new-journal form: an award decides the funds and programmes a line may
 * carry, a line in an award-held fund names its award before it is submitted, and
 * the document reference follows the source record or the type's ELOG series.
 */
final class JournalFormTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace   = 'App';
    protected $refresh     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $seed        = DatabaseSeeder::class;

    private const ACTOR = ['short' => 'J. Achieng', 'role' => 'Senior Accountant', 'canPrepare' => true, 'canApprove' => false];

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['app.asOf'] = '2026-08-31';
        Repository::forget();
    }

    public function testAnAwardOffersOnlyTheFundsAndProgrammesItsAgreementCovers(): void
    {
        $form   = (new JournalRepository())->formOptions(self::ACTOR);
        $grants = array_column($form['grants'], null, 'ref');

        $this->assertSame(['Grant Fund', 'Capital Fund'], $grants['DANIDA/CE-2025/27']['funds']);
        $this->assertEqualsCanonicalizing(['Civic Education', 'Shared services'], $grants['DANIDA/CE-2025/27']['programmes']);
        $this->assertSame(['Grant Fund'], $grants['USAID/URAIA/2026']['funds']);
        // Suspended and closed awards are not offered.
        $this->assertArrayNotHasKey('KAS/2025-KE', $grants);
        $this->assertSame(['Grant Fund', 'Capital Fund'], $form['awardFunds']);
    }

    public function testTheFormStartsInTheFirstOpenPeriodWithTheNextNumbers(): void
    {
        $form = (new JournalRepository())->formOptions(self::ACTOR);

        $this->assertSame('Aug 2026', $form['period']);
        $this->assertSame('2026-08-31', $form['date']);
        $this->assertSame('ELOG/JV/0282', $form['docRefs']['Standard']);
        $this->assertSame('ELOG/AC/0256', $form['docRefs']['Accrual']);
        $this->assertSame('ELOG/AL/0231', $form['docRefs']['Allocation']);
    }

    public function testALineCannotBeChargedToAFundItsAwardDoesNotCover(): void
    {
        $this->expectException(RuleViolation::class);
        $this->expectExceptionMessage('USAID / Uraia 2026 can only be charged to Grant Fund');

        $this->create('Draft', [
            $this->line('5130', 'USAID/URAIA/2026', 'Capital Fund', 'Election Observation', 500, 0),
            $this->line('1110', 'USAID/URAIA/2026', 'Grant Fund', 'Election Observation', 0, 500),
        ]);
    }

    public function testALineCannotBeChargedToAProgrammeItsAwardDoesNotFund(): void
    {
        $this->expectException(RuleViolation::class);
        $this->expectExceptionMessage('does not fund Civic Education');

        $this->create('Draft', [
            $this->line('5130', 'USAID/URAIA/2026', 'Grant Fund', 'Civic Education', 500, 0),
            $this->line('1110', 'USAID/URAIA/2026', 'Grant Fund', 'Election Observation', 0, 500),
        ]);
    }

    public function testARestrictedLineWithoutItsAwardSavesAsDraftButIsNotSubmitted(): void
    {
        $lines = [
            $this->line('5120', '', 'Grant Fund', 'Civic Education', 500, 0),
            $this->line('1110', 'DANIDA/CE-2025/27', 'Grant Fund', 'Civic Education', 0, 500),
        ];

        $this->assertSame('Draft', $this->create('Draft', $lines)['status']);

        $this->expectException(RuleViolation::class);
        $this->expectExceptionMessage('One line charges a restricted fund with no grant against it.');
        $this->create('Pending approval', $lines);
    }

    public function testAnEntryRaisedFromABillCarriesItsReferenceAndOnePostedInTheFundTheAwardNames(): void
    {
        $journal = $this->create('Pending approval', [
            $this->line('1310', 'DANIDA/CE-2025/27', 'Capital Fund', 'Shared services', 800, 0),
            $this->line('2110', 'DANIDA/CE-2025/27', 'Capital Fund', 'Shared services', 0, 800),
        ], 'bill:BILL-0418');

        $this->assertSame('BILL-0418', $journal['doc']);
        $this->assertSame('bill:BILL-0418', $journal['docLink']);
        $this->assertSame('J. Achieng', $journal['preparer']);
        $this->assertSame(['DANIDA/CE-2025/27', 'DANIDA/CE-2025/27'], array_column($journal['lines'], 'grantRef'));
        $this->assertSame(['Capital Fund', 'Capital Fund'], array_column($journal['lines'], 'fund'));
    }

    public function testAManualEntryTakesTheNextNumberInItsTypesSeries(): void
    {
        $journal = $this->create('Draft', [
            $this->line('5330', '', 'General Fund', 'Shared services', 300, 0),
            $this->line('2120', '', 'General Fund', 'Shared services', 0, 300),
        ], 'auto', 'Accrual');

        $this->assertSame('ELOG/AC/0256', $journal['doc']);
        $this->assertSame('ELOG/AC/0257', (new JournalRepository())->formOptions(self::ACTOR)['docRefs']['Accrual']);
    }

    public function testAClosedPeriodIsRefused(): void
    {
        $this->expectException(RuleViolation::class);
        $this->expectExceptionMessage('Jul 2026 is closed to further posting.');

        $this->create('Draft', [
            $this->line('5310', '', 'General Fund', 'Shared services', 100, 0),
            $this->line('1110', '', 'General Fund', 'Shared services', 0, 100),
        ], 'auto', 'Standard', 'Jul 2026', '2026-07-20');
    }

    private function create(string $status, array $lines, string $docLink = 'auto', string $type = 'Standard', string $period = 'Aug 2026', string $date = '2026-08-20'): array
    {
        return (new JournalRepository())->create([
            'date' => $date, 'type' => $type, 'period' => $period, 'status' => $status, 'docLink' => $docLink,
            'memo' => '', 'narration' => 'Test entry', 'lines' => $lines,
        ], (new Lookups())->userId(self::ACTOR['short']));
    }

    private function line(string $code, string $grant, string $fund, string $programme, float $dr, float $cr): array
    {
        return ['code' => $code, 'desc' => '', 'grantRef' => $grant, 'fund' => $fund, 'program' => $programme, 'dr' => $dr, 'cr' => $cr];
    }
}
