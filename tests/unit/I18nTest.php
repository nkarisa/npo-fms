<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Libraries\I18n;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

final class I18nTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    // The books are loaded into the test database once for this class.
    protected $namespace   = 'App';
    protected $refresh     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $seed        = DatabaseSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        // Figures that depend on "today" are measured from the date the data describes.
        $_ENV['app.asOf'] = '2026-08-31';
        Repository::forget();
    }

    public function testSourceLocalePassesEverythingThrough(): void
    {
        $en = new I18n(I18n::SOURCE_LOCALE);

        $this->assertTrue($en->isSource());
        $this->assertSame('ltr', $en->dir());
        $this->assertSame('Chart of accounts', $en->t('Chart of accounts'));
        $this->assertSame([], I18n::missing(I18n::SOURCE_LOCALE));
    }

    public function testTranslatesCatalogueStrings(): void
    {
        $fr = new I18n('fr');

        $this->assertSame('Plan comptable', $fr->t('Chart of accounts'));
        $this->assertSame('Grand livre', $fr->t('General ledger'));
        $this->assertSame('rtl', (new I18n('ar'))->dir());
    }

    /**
     * Anything outside the catalogue is ledger data — an account name, a supplier,
     * a reference — and must come back untouched rather than marked or guessed at.
     */
    public function testDataStringsAreNeverTouched(): void
    {
        $sw = new I18n('sw', 'mark');

        $this->assertSame('1110 · Bank — KCB Current (KES)', $sw->t('1110 · Bank — KCB Current (KES)'));
        $this->assertSame('Safaricom PLC', $sw->t('Safaricom PLC'));
        $this->assertSame('JV-26-0311', $sw->t('JV-26-0311'));
    }

    public function testFallbackModesForUntranslatedCatalogueStrings(): void
    {
        // "Currencies" is in the catalogue but has no approved Swahili wording.
        $this->assertTrue(I18n::isTranslatable('Currencies'));
        $this->assertFalse(I18n::hasIn('sw', 'Currencies'));

        $this->assertSame('Currencies EN', (new I18n('sw', 'mark'))->t('Currencies'));
        $this->assertSame('Currencies', (new I18n('sw', 'silent'))->t('Currencies'));
        $this->assertSame('[Currencies]', (new I18n('sw', 'key'))->t('Currencies'));
    }

    public function testUnknownLocaleFallsBackToSourceRatherThanFailing(): void
    {
        $this->assertSame(I18n::SOURCE_LOCALE, (new I18n('klingon'))->code());
        $this->assertSame(I18n::SOURCE_LOCALE, (new I18n(null))->code());
        $this->assertSame(I18n::DEFAULT_FALLBACK, (new I18n('fr', 'nonsense'))->fallbackMode());
    }

    /**
     * The rule the design turns on: only display keys move. A response walked by
     * the translator must come back with every figure, code and reference intact.
     */
    public function testTranslateResponseLeavesLedgerDataAlone(): void
    {
        $response = [
            'rows' => [
                ['label' => 'Journals', 'code' => '5110', 'amount' => '12,480,000', 'name' => 'Salaries and wages'],
            ],
            'stats' => [
                ['label' => 'Budgets', 'value' => '(1,240)', 'note' => 'Reports'],
            ],
        ];

        $out = (new I18n('fr'))->translateResponse($response);

        $this->assertSame('Écritures', $out['rows'][0]['label']);
        $this->assertSame('5110', $out['rows'][0]['code']);
        $this->assertSame('12,480,000', $out['rows'][0]['amount']);
        $this->assertSame('Salaries and wages', $out['rows'][0]['name']);
        $this->assertSame('Budgets', $out['stats'][0]['label']);
        $this->assertSame('(1,240)', $out['stats'][0]['value']);
        $this->assertSame('États financiers', $out['stats'][0]['note']);
    }

    public function testLockedTerminologyIsIdentifiable(): void
    {
        $lock = I18n::lockOn('Restricted fund');

        $this->assertNotNull($lock);
        $this->assertSame('Finance Director', $lock['unlock']);
        $this->assertNull(I18n::lockOn('Chart of accounts'));
    }

    public function testCoverageAreasAreCompleteForTheSourceLanguage(): void
    {
        foreach (I18n::coverageAreas(I18n::SOURCE_LOCALE) as $area) {
            $this->assertSame(100, $area['coverage']);
            $this->assertSame('Source language', $area['note']);
        }

        $this->assertNotEmpty(I18n::coverageAreas('ar'));
    }
}
