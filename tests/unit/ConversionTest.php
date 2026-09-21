<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Libraries\TrialBalanceCsv;
use App\Repositories\ConversionRepository;
use App\Repositories\JournalRepository;
use App\Repositories\Lookups;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Carrying balances from a legacy system: the file is checked before anything is
 * written, what is written is a draft for someone else to approve, and a ledger
 * that already holds postings will not take opening balances on top of them.
 */
final class ConversionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use \Tests\Support\SignsIn;

    protected $namespace = 'App';
    protected $refresh   = true;
    protected $seed      = DatabaseSeeder::class;

    /** The first period the demo ledger keeps nothing before: the start of FY2025. */
    private const PERIOD = 'Jan 2025';

    /** What the demo ledger already carries on the two accounts the fixtures touch. */
    private float $banked = 0.0;

    private float $owed = 0.0;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['app.asOf'] = '2026-08-31';
        Repository::forget();
        $this->banked = (new Lookups())->balance('1110');
        $this->owed   = (new Lookups())->balance('2110');
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['elog_actor']);
        service('superglobals')->unsetCookie('elog_actor');
        parent::tearDown();
    }

    public function testTheScreenOffersOnlyPeriodsTheLedgerIsEmptyBefore(): void
    {
        $options = (new ConversionRepository())->options();

        // Every open period in the demo ledger already has postings behind it.
        $this->assertNotSame([], $options['periods']);
        foreach ($options['periods'] as $p) {
            $this->assertFalse($p['available'], $p['name'] . ' should not take opening balances');
            $this->assertGreaterThan(0, $p['postedBefore']);
        }
        $this->assertNull($options['batch']);
        $this->assertSame('KES', $options['currency']);
        $this->assertSame('account code', $options['columns']['account']);

        $this->open(self::PERIOD);
        $offered = $this->periodOption((new ConversionRepository())->options(), self::PERIOD);
        $this->assertTrue($offered['available']);
        $this->assertSame('31 Dec 2024', $offered['cutOff']);
        $this->assertTrue($offered['yearStart']);
    }

    public function testATrialBalanceIsCheckedBeforeAnythingIsWritten(): void
    {
        $this->open(self::PERIOD);

        $preview = (new ConversionRepository())->run(self::PERIOD, $this->csv($this->balanced()), ['source' => 'Sage 50'], $this->kamau(), false);

        $this->assertTrue($preview['ok']);
        $this->assertSame(31, $preview['summary']['read']);
        $this->assertSame(31, $preview['summary']['loaded']);
        $this->assertSame(0, $preview['summary']['problems']);
        $this->assertSame($preview['summary']['debit'], $preview['summary']['credit']);
        $this->assertSame('31 Dec 2024', $preview['cutOff']);
        $this->assertContains('The trial balance balances', array_column($preview['checks'], 'label'));

        // A preview writes nothing at all.
        Repository::forget();
        $this->assertNull((new ConversionRepository())->batch());
        $this->seeNumRecords(0, 'conversion_batches', []);
        $this->dontSeeInDatabase('journals', ['reference' => 'OB-25-0001']);
    }

    public function testBalancesAreCarriedAsADraftJournalForSomeoneElseToApprove(): void
    {
        $this->open(self::PERIOD);
        $kamau = $this->kamau();

        $loaded = (new ConversionRepository())->run(self::PERIOD, $this->csv($this->balanced()), ['source' => 'Sage 50'], $kamau, true);
        Repository::forget();

        $ref = $loaded['committed']['reference'];
        $this->assertSame('OB-25-0001', $ref);
        $this->assertStringContainsString('submit it on the Journals screen', $loaded['committed']['message']);

        // Written as a draft, in the ledger's own convention for brought-forward figures.
        $this->seeInDatabase('journals', ['reference' => $ref, 'status' => 'draft', 'type' => 'adjustment',
            'source_type' => 'fiscal_year', 'journal_date' => '2025-01-01', 'prepared_by' => $kamau]);
        $journal = (new JournalRepository())->find($ref);
        $this->assertTrue($journal['opening']);
        $this->assertCount(31, $journal['lines']);
        $this->assertSame('Draft', $journal['status']);

        // The batch keeps what the file said, so the load can be explained later.
        $batch = (new ConversionRepository())->batch();
        $this->assertSame('committed', $batch['status']);
        $this->assertSame('Sage 50', $batch['source_system']);
        $this->assertSame('2024-12-31', $batch['conversion_date']);
        $this->assertSame(31, (int) $batch['rows_loaded']);
        $this->seeNumRecords(31, 'conversion_lines', ['batch_id' => $batch['id']]);

        // Nothing has reached the ledger yet: a draft moves no balance.
        $this->assertSame($this->banked, (new Lookups())->balance('1110'));

        // It is the preparer who cannot approve it, exactly as for any other entry.
        $this->expectException(RuleViolation::class);
        $this->expectExceptionMessage('cannot also approve it');
        $journals = new JournalRepository();
        $journals->update($ref, ['date' => '01 Jan 2025', 'type' => 'Adjustment', 'period' => self::PERIOD, 'status' => 'Pending approval',
            'docLink' => 'auto', 'memo' => $journal['memo'], 'narration' => $journal['narration'],
            'lines' => $journal['lines']], $kamau);
        $journals->approve($ref, $kamau);
    }

    public function testTheBalancesReachTheLedgerOnlyWhenTheEntryIsApproved(): void
    {
        $this->open(self::PERIOD);
        $kamau = $this->kamau();
        $otieno = (new Lookups())->userId('M. Otieno');

        $ref = (new ConversionRepository())->run(self::PERIOD, $this->csv($this->balanced()), ['source' => 'Sage 50'], $otieno, true)['committed']['reference'];
        Repository::forget();

        $journals = new JournalRepository();
        $draft = $journals->find($ref);
        $journals->update($ref, ['date' => '01 Jan 2025', 'type' => 'Adjustment', 'period' => self::PERIOD, 'status' => 'Pending approval',
            'docLink' => 'auto', 'memo' => $draft['memo'], 'narration' => $draft['narration'], 'lines' => $draft['lines']], $otieno);
        // A conversion is worth more than a Finance Manager may approve, so it escalates
        // to the Executive Director — the ledger's own approval limits, applied to it.
        try {
            (new JournalRepository())->approve($ref, $kamau);
            $this->fail('The Finance Manager should not be able to approve a conversion of this size.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString("Executive Director's approval", $e->getMessage());
        }
        Repository::forget();
        (new JournalRepository())->approve($ref, (int) (new Lookups())->userId('D. Kiptoo'));
        Repository::forget();

        $this->assertSame('Posted', (new JournalRepository())->find($ref)['status']);
        $this->assertSame($this->banked + 4200000.0, (new Lookups())->balance('1110'));
        // 1,450,000 of trade payables plus the 66,000 of the file's smaller payable lines.
        $this->assertSame($this->owed + 1516000.0, (new Lookups())->balance('2110'));

        // Once it has posted the conversion can no longer be thrown away.
        $this->expectException(RuleViolation::class);
        $this->expectExceptionMessage('part of the ledger now');
        (new ConversionRepository())->discard($kamau);
    }

    public function testEveryRowTheChartCannotPlaceIsNamedAndNothingIsLoaded(): void
    {
        $this->open(self::PERIOD);

        $rows = $this->balanced();
        $rows[] = ['9999', 'Suspense from the old system', 'FND-100', 'PRG-90', '', '', '12000', ''];
        $rows[] = ['1000', 'Current assets', 'FND-100', 'PRG-90', '', '', '', '12000'];
        $rows[] = ['1110', 'Bank', 'FND-999', 'PRG-90', '', '', '5000', ''];
        $rows[] = ['2110', 'Payables', 'FND-100', 'PRG-90', '', '', '', '5000'];

        $preview = (new ConversionRepository())->run(self::PERIOD, $this->csv($rows), [], $this->kamau(), false);

        $this->assertFalse($preview['ok']);
        $problems = array_values(array_filter(array_column($preview['rows'], 'problem')));
        $this->assertCount(3, $problems);
        $this->assertStringContainsString('9999 is not in the chart of accounts', $problems[0]);
        $this->assertStringContainsString('is a heading, not a postable account', $problems[1]);
        $this->assertStringContainsString('Fund "FND-999" is not in the ledger', $problems[2]);
        $this->assertStringContainsString('3 balances cannot be placed', $this->failedCheck($preview));

        $this->expectException(RuleViolation::class);
        (new ConversionRepository())->run(self::PERIOD, $this->csv($rows), [], $this->kamau(), true);
    }

    public function testALineInAnAwardFundMustNameItsAward(): void
    {
        $this->open(self::PERIOD);

        $rows = $this->balanced();
        $rows[2][4] = ''; // grants receivable, in a restricted fund, with no award against it

        $preview = (new ConversionRepository())->run(self::PERIOD, $this->csv($rows), [], $this->kamau(), false);

        $this->assertFalse($preview['ok']);
        $problems = array_values(array_filter(array_column($preview['rows'], 'problem')));
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('restricted fund with no grant against it', $problems[0]);

        // And an award that does not cover the line's fund is named as such.
        $rows[2][4] = 'FORD/GA-24';
        $mismatched = (new ConversionRepository())->run(self::PERIOD, $this->csv($rows), [], $this->kamau(), false);
        $this->assertStringContainsString('Ford Foundation GA-24 does not fund Election Observation',
            array_values(array_filter(array_column($mismatched['rows'], 'problem')))[0]);
    }

    public function testAFileThatDoesNotBalanceIsRefusedWithTheDifference(): void
    {
        $this->open(self::PERIOD);

        $rows = $this->balanced();
        $rows[0][6] = '4300000'; // the bank, 100,000 more than the file's credits

        $preview = (new ConversionRepository())->run(self::PERIOD, $this->csv($rows), [], $this->kamau(), false);

        $this->assertFalse($preview['ok']);
        $this->assertStringContainsString('The trial balance is out by 100,000', $this->failedCheck($preview));
    }

    public function testAYearThatHasEndedCarriesItsResultInTheAccumulatedFundNotLineByLine(): void
    {
        $this->open(self::PERIOD);

        $rows = $this->balanced();
        $rows[] = ['4110', 'Grant income last year', 'FND-100', 'PRG-90', '', '', '', '800000'];
        $rows[] = ['5110', 'Salaries last year', 'FND-100', 'PRG-90', '', '', '800000', ''];

        $preview = (new ConversionRepository())->run(self::PERIOD, $this->csv($rows), [], $this->kamau(), false);

        $this->assertFalse($preview['ok']);
        $problems = array_values(array_filter(array_column($preview['rows'], 'problem')));
        $this->assertCount(2, $problems);
        $this->assertStringContainsString('carries its result in the accumulated fund', $problems[0]);

        // Mid-year, the same balances carry as the year to date.
        $this->open('Feb 2025');
        $midYear = (new ConversionRepository())->run('Feb 2025', $this->csv($rows), [], $this->kamau(), false);
        $this->assertSame([], array_values(array_filter(array_column($midYear['rows'], 'problem'))));
        $this->assertFalse($midYear['yearStart']);
        $this->assertTrue($midYear['ok']);
    }

    public function testARestrictedFundMayNotOpenBelowZero(): void
    {
        $this->open(self::PERIOD);

        $rows = $this->balanced();
        $rows[] = ['3200', 'Restricted fund overspent', 'FND-220', 'PRG-20', 'DANIDA/CE-2025/27', '', '60000', ''];
        $rows[] = ['1110', 'Bank', 'FND-100', 'PRG-90', '', '', '', '60000'];

        $preview = (new ConversionRepository())->run(self::PERIOD, $this->csv($rows), [], $this->kamau(), false);

        $this->assertFalse($preview['ok']);
        $this->assertStringContainsString('DANIDA Civic Education Fund opens below zero', $this->failedCheck($preview));
    }

    public function testOpeningBalancesAreRefusedOnTopOfALedgerThatAlreadyHoldsPostings(): void
    {
        // Aug 2026 is open, but 527 entries stand behind it.
        $preview = (new ConversionRepository())->run('Aug 2026', $this->csv($this->balanced()), [], $this->kamau(), false);

        $this->assertFalse($preview['ok']);
        $this->assertStringContainsString('already posted up to 31 Jul 2026', $this->failedCheck($preview));
        $this->assertStringContainsString('the figures count twice', $this->failedCheck($preview));
    }

    public function testBalancesAreCarriedOnceAndADraftCanBeThrownAway(): void
    {
        $this->open(self::PERIOD);
        $kamau = $this->kamau();

        $ref = (new ConversionRepository())->run(self::PERIOD, $this->csv($this->balanced()), ['source' => 'Sage 50'], $kamau, true)['committed']['reference'];
        Repository::forget();

        $again = (new ConversionRepository())->run(self::PERIOD, $this->csv($this->balanced()), [], $kamau, false);
        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('already loaded as ' . $ref, $this->failedCheck($again));

        $discarded = (new ConversionRepository())->discard($kamau);
        Repository::forget();
        $this->assertStringContainsString('Nothing of it remains', $discarded['message']);
        $this->dontSeeInDatabase('journals', ['reference' => $ref]);
        $this->assertNull((new ConversionRepository())->batch());
        // What was attempted is still on the record, marked discarded.
        $this->seeInDatabase('conversion_batches', ['status' => 'discarded', 'journal_id' => null]);

        // And the same file loads again afterwards.
        $this->assertTrue((new ConversionRepository())->run(self::PERIOD, $this->csv($this->balanced()), [], $kamau, false)['ok']);
    }

    public function testOnlyTheFinanceManagerCanCarryBalances(): void
    {
        $this->assertSame(200, $this->get('api/settings/conversion')->response()->getStatusCode());

        $this->actAs('p.mwangi@elog.or.ke');
        $refused = $this->post('api/settings/conversion/load');
        $refused->assertStatus(403);
        $this->assertStringContainsString('That needs a role with settings.ledger', json_decode($refused->getJSON(), true)['error']);
    }

    public function testTheTemplateIsTheOrganisationsOwnChartReadyForFigures(): void
    {
        $this->open(self::PERIOD);

        // The endpoint serves it as a download. Its body is read from the repository
        // below: the test harness wraps a plain-text body in HTML and escapes it.
        $response = $this->get('api/settings/conversion/template?period=' . rawurlencode(self::PERIOD));
        $response->assertStatus(200);
        $this->assertSame('text/csv; charset=utf-8', $response->response()->getHeaderLine('Content-Type'));
        $this->assertSame('attachment; filename="ELOG opening-balances-2024-12-31.csv"',
            $response->response()->getHeaderLine('Content-Disposition'));

        $template = (new ConversionRepository())->template(self::PERIOD);
        $this->assertSame('opening-balances-2024-12-31.csv', $template['filename']);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $template['csv'], 'A BOM, so a spreadsheet reads the account names correctly.');

        $rows = $this->parse($template['csv']);
        $this->assertSame(['Account code', 'Account name', 'Fund code', 'Programme code', 'Award ref', 'County code', 'Debit', 'Credit'], $rows[0]);

        $codes = array_column(array_slice($rows, 1), 0);
        $this->assertContains('1110', $codes);
        $this->assertNotContains('1000', $codes, 'A heading is not postable, so it is not in the template.');
        $this->assertNotContains('1395', $codes, 'An archived account is not in the template.');
        // Jan 2025 opens the fiscal year, so a year has ended and its result is in the fund.
        $this->assertNotContains('4110', $codes, 'Income does not carry into a year-start conversion.');
        $this->assertNotContains('5110', $codes);

        // The coding the chart defaults to is already filled in, and the figures are blank.
        $bank = current(array_filter($rows, static fn ($r) => $r[0] === '1110'));
        $this->assertSame(['FND-100', 'PRG-90', '', '', '', ''], array_slice($bank, 2));

        // Mid-year the template carries income and expenditure too.
        $this->open('Feb 2025');
        $this->assertContains('4110', array_column($this->parse((new ConversionRepository())->template('Feb 2025')['csv']), 0));
    }

    public function testTheTemplateLoadsBackWithNoMappingOnceItsFiguresAreEntered(): void
    {
        $this->open(self::PERIOD);

        $rows = $this->parse((new ConversionRepository())->template(self::PERIOD)['csv']);

        // Fill in two accounts and delete the rest, as the panel says to.
        $filled = [$rows[0]];
        foreach ($rows as $r) {
            if ($r[0] === '1110') {
                $filled[] = array_replace($r, [6 => '750000']);
            }
            if ($r[0] === '3100') {
                $filled[] = array_replace($r, [7 => '750000']);
            }
        }
        $this->assertCount(3, $filled, 'The two accounts filled in, under the header.');

        $path = tempnam(sys_get_temp_dir(), 'tb') . '.csv';
        $out = fopen($path, 'w');
        foreach ($filled as $r) {
            fputcsv($out, $r);
        }
        fclose($out);

        $preview = (new ConversionRepository())->run(
            self::PERIOD,
            ['path' => $path, 'name' => 'opening-balances-2024-12-31.csv', 'size' => filesize($path), 'mime' => 'text/csv'],
            ['source' => 'Sage 50'],
            $this->kamau(),
            false
        );

        $this->assertTrue($preview['ok'], 'The template the application issued loads back with nothing to fix.');
        $this->assertSame([], array_values(array_filter(array_column($preview['rows'], 'problem'))));
        $this->assertSame(750000, $preview['summary']['debit']);
        $this->assertSame(750000, $preview['summary']['credit']);
    }

    public function testTheReaderTakesTheColumnsTheOldSystemHappensToUse(): void
    {
        // Separate debit and credit columns, semicolons, a report title above the header,
        // European decimals, a totals footer and a nil balance that brings nothing forward.
        $read = TrialBalanceCsv::read(
            "Trial balance as at 31.12.2024;;;\n"
            . "Nominal;Particulars;Dr;Cr\n"
            . "1110;Bank;4.200.000,00;\n"
            . "2110;Payables;;4.200.000,00\n"
            . "1230;Prepayments;0,00;\n"
            . "Total;;4.200.000,00;4.200.000,00\n",
            ','
        );
        $this->assertNull($read['error']);
        $this->assertSame(2, $read['headerLine']);
        $this->assertSame(['1110', '2110'], array_column($read['rows'], 'account'));

        // One signed balance column, where a credit is written negative.
        $signed = TrialBalanceCsv::read("Account Code,Account Name,Balance\n1110,Bank,4200000\n2110,Payables,-4200000\n");
        $this->assertSame([4200000.0, 0.0], [$signed['rows'][0]['debit'], $signed['rows'][0]['credit']]);
        $this->assertSame([0.0, 4200000.0], [$signed['rows'][1]['debit'], $signed['rows'][1]['credit']]);

        $this->assertStringContainsString('No header row names an account column', (string) TrialBalanceCsv::read("Name,Town\nA,B\n")['error']);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /** Opens a period so a conversion can be dated in it, as standing the system up would. */
    private function open(string $name): void
    {
        db_connect()->table('periods')->where('name', $name)->update(['status' => 'open', 'closed_by' => null, 'closed_at' => null]);
        Repository::forget();
    }

    private function actAs(string $email): void
    {
        $_COOKIE['elog_actor'] = $email;
        service('superglobals')->setCookie('elog_actor', $email);
    }

    /** A served CSV as rows, past its byte-order mark. */
    private function parse(string $csv): array
    {
        return array_map('str_getcsv', array_filter(explode("\n", trim(substr($csv, 3)))));
    }

    private function kamau(): int
    {
        return (int) (new Lookups())->userId('W. Kamau');
    }

    private function periodOption(array $options, string $name): array
    {
        return current(array_filter($options['periods'], static fn ($p) => $p['name'] === $name));
    }

    private function failedCheck(array $result): string
    {
        return current(array_filter($result['checks'], static fn ($c) => !$c['ok']))['label'];
    }

    /**
     * A balanced trial balance out of the old system: assets against liabilities and
     * the accumulated fund, coded the way the chart expects.
     *
     * @return list<list<string>> account, name, fund, programme, award, county, debit, credit
     */
    private function balanced(): array
    {
        $rows = [
            ['1110', 'Bank — KCB Current', 'FND-100', 'PRG-90', '', '', '4200000', ''],
            ['1140', 'Petty cash', 'FND-100', 'PRG-90', '', '', '85000', ''],
            ['1210', 'Grants receivable', 'FND-210', 'PRG-10', 'USAID/URAIA/2026', '', '1800000', ''],
            ['1220', 'Staff advances', 'FND-100', 'PRG-90', '', '', '240000', ''],
            ['1310', 'Motor vehicles — cost', 'FND-300', 'PRG-90', 'DANIDA/CE-2025/27', '', '6500000', ''],
            ['1390', 'Accumulated depreciation', 'FND-300', 'PRG-90', 'DANIDA/CE-2025/27', '', '', '2100000'],
            ['2110', 'Trade payables', 'FND-100', 'PRG-90', '', '', '', '1450000'],
            ['3100', 'Accumulated fund — unrestricted', 'FND-100', 'PRG-90', '', '', '', '7475000'],
            ['3200', 'Accumulated fund — restricted', 'FND-210', 'PRG-10', 'USAID/URAIA/2026', '', '', '1800000'],
        ];

        // Padded out to a file of a realistic length, in balanced pairs.
        for ($i = 0; $i < 11; $i++) {
            $rows[] = ['1230', 'Prepaid expenses ' . ($i + 1), 'FND-100', 'PRG-90', '', '', (string) (1000 * ($i + 1)), ''];
            $rows[] = ['2110', 'Trade payables ' . ($i + 1), 'FND-100', 'PRG-90', '', '', '', (string) (1000 * ($i + 1))];
        }

        return $rows;
    }

    /** The rows as a CSV file on disk, as the old system would export it. */
    private function csv(array $rows): array
    {
        $path = tempnam(sys_get_temp_dir(), 'tb') . '.csv';
        $out = "Account Code,Account Name,Fund Code,Programme Code,Award Ref,County Code,Debit,Credit\n";
        foreach ($rows as $r) {
            $out .= implode(',', array_map(static fn ($c) => '"' . str_replace('"', '""', (string) $c) . '"', $r)) . "\n";
        }
        file_put_contents($path, $out);

        return ['path' => $path, 'name' => 'trial-balance.csv', 'size' => filesize($path), 'mime' => 'text/csv'];
    }
}
