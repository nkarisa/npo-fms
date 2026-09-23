<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Libraries\I18n;
use App\Libraries\Navigation;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

final class I18nTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use \Tests\Support\SignsIn;

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

    /**
     * The "mark" fallback is a badge the shell draws beside the label, not two
     * extra characters inside it. A caller that renders its own marker asks for
     * plain() and needsMark(); one that just wants a string still gets the mark
     * from t(), which is what every API response relies on.
     */
    public function testTheUntranslatedMarkIsSeparableFromTheWording(): void
    {
        $sw = new I18n('sw', 'mark');

        // Swahili has no approved wording for this one.
        $this->assertSame('Asset verification EN', $sw->t('Asset verification'));
        $this->assertSame('Asset verification', $sw->plain('Asset verification'));
        $this->assertTrue($sw->needsMark('Asset verification'));

        // One it does have is never marked, either way round.
        $this->assertSame('Leja kuu', $sw->plain('General ledger'));
        $this->assertSame('Leja kuu', $sw->t('General ledger'));
        $this->assertFalse($sw->needsMark('General ledger'));

        // Nothing is marked in the source language, nor under the other fallbacks.
        $this->assertFalse((new I18n(I18n::SOURCE_LOCALE, 'mark'))->needsMark('Asset verification'));
        $this->assertFalse((new I18n('sw', 'silent'))->needsMark('Asset verification'));
        $this->assertSame('[Asset verification]', (new I18n('sw', 'key'))->plain('Asset verification'));

        // And ledger data is outside all of it.
        $this->assertFalse($sw->needsMark('Safaricom PLC'));
    }

    /**
     * The sidebar was the one part of the shell the prototype translated that the
     * application did not. Each item now reads in the reader's language and still
     * carries the English source, which is the catalogue key a raise is made
     * against whatever the reader's screen says.
     */
    public function testTheSidebarIsReadInTheReadersLanguage(): void
    {
        $groups = Navigation::groups(new I18n('fr', 'mark'));

        $overview = $groups[0];
        $this->assertSame("Vue d'ensemble", $overview['label']);
        $this->assertSame('Overview', $overview['source']);

        $dashboard = $overview['items'][0];
        $this->assertSame('Tableau de bord', $dashboard['label']);
        $this->assertSame('Dashboard', $dashboard['source']);
        $this->assertSame('/', $dashboard['url']);
        $this->assertFalse($dashboard['mark']);

        // Swahili has no wording for asset verification, so it reads English, marked.
        $items = array_merge(...array_column(Navigation::groups(new I18n('sw', 'mark')), 'items'));
        $verify = array_values(array_filter($items, static fn ($i) => $i['source'] === 'Asset verification'))[0];
        $this->assertSame('Asset verification', $verify['label']);
        $this->assertTrue($verify['mark']);

        // A page the reader may not see is not offered to them in any language.
        $hidden = array_column(array_merge(...array_column(Navigation::groups(new I18n('fr'), ['settings']), 'items')), 'page');
        $this->assertNotContains('settings', $hidden);
    }

    /** The shell marks each label with its key, so a reader can raise it from where they read it. */
    public function testTheShellCarriesTheCatalogueKeyOnEveryLabelItDraws(): void
    {
        $_COOKIE['elog_locale'] = 'sw';
        service('superglobals')->setCookie('elog_locale', 'sw');

        try {
            $page = $this->get('settings')->getBody();
            $this->assertStringContainsString('data-i18n="Dashboard"', $page);
            $this->assertStringContainsString('data-i18n="Asset verification"', $page);
            // Read in Swahili, and the untranslated one wears its badge.
            $this->assertStringContainsString('Dashibodi', $page);
            $this->assertStringContainsString('class="i18n-mark"', $page);
        } finally {
            unset($_COOKIE['elog_locale']);
            service('superglobals')->unsetCookie('elog_locale');
        }
    }

    /**
     * What the raise popover asks for when a label is Cmd-clicked. Somebody
     * reading English can still raise a French label, so every target language
     * comes back, not only the one being read.
     */
    public function testAStringCanBeLookedUpForRaising(): void
    {
        $data = json_decode($this->get('api/i18n/string?str=' . rawurlencode('Asset verification'))->getJSON(), true);

        $this->assertSame('Asset verification', $data['str']);
        $this->assertNull($data['locked']);
        $this->assertSame('en-GB', $data['current']);

        $by = array_column($data['locales'], null, 'code');
        $this->assertSame(['fr', 'es', 'ar', 'sw'], array_keys($by));
        $this->assertTrue($by['fr']['translated']);
        $this->assertSame('Vérification des immobilisations', $by['fr']['text']);
        // Untranslated: the source comes back, said to be untranslated, so the
        // popover shows what the reader is actually looking at.
        $this->assertFalse($by['sw']['translated']);
        $this->assertSame('Asset verification', $by['sw']['text']);
        $this->assertNotSame('', $by['fr']['reviewer']);

        // A locked term says so, and names who may unlock it.
        $locked = json_decode($this->get('api/i18n/string?str=' . rawurlencode('Restricted fund'))->getJSON(), true);
        $this->assertSame('Finance Director', $locked['locked']['unlock']);

        // Ledger data is not raisable, and says why rather than failing.
        $refused = $this->get('api/i18n/string?str=' . rawurlencode('Safaricom PLC'));
        $refused->assertStatus(404);
        $this->assertStringContainsString('not a string in the translation catalogue', json_decode($refused->getJSON(), true)['error']);
    }

    /** The wording under review must not itself be run through the translator. */
    public function testALookupIsNotTranslatedIntoTheLanguageBeingRead(): void
    {
        $data = json_decode($this->withHeaders(['X-Locale' => 'fr'])->get('api/i18n/string?str=' . rawurlencode('Journals'))->getJSON(), true);

        $this->assertSame('Journals', $data['str']);
        $this->assertSame('fr', $data['current']);
        $this->assertSame('Écritures', array_column($data['locales'], null, 'code')['fr']['text']);
    }

    public function testLockedTerminologyIsIdentifiable(): void
    {
        $lock = I18n::lockOn('Restricted fund');

        $this->assertNotNull($lock);
        $this->assertSame('Finance Director', $lock['unlock']);
        $this->assertNull(I18n::lockOn('Chart of accounts'));
    }

    /**
     * With no language chosen, the browser's preference decides — and an English
     * browser reads the English source even when it also lists French. The page
     * shell and the API it calls must land on the same language.
     */
    public function testTheBrowsersLanguageIsReadTheSameWayByThePageAndTheApi(): void
    {
        $cases = [
            'en-US,en;q=0.9,fr;q=0.8' => 'en-GB',
            'en'                      => 'en-GB',
            'fr-CH,fr;q=0.9,en;q=0.8' => 'fr',
            'de-DE,de;q=0.9'          => 'en-GB',
        ];

        foreach ($cases as $header => $expected) {
            $api = json_decode($this->withHeaders(['Accept-Language' => $header])->get('api/me')->getJSON(), true);
            $this->assertSame($expected, $api['locale']['code'], 'API for ' . $header);

            $page = $this->withHeaders(['Accept-Language' => $header])->get('settings')->getBody();
            $this->assertStringContainsString('<html lang="' . $expected . '"', $page, 'Page for ' . $header);
        }
    }

    public function testTheLanguageChosenInTheTopBarWinsOverTheBrowser(): void
    {
        $_COOKIE['elog_locale'] = 'en-GB';
        service('superglobals')->setCookie('elog_locale', 'en-GB');

        try {
            $api = json_decode($this->withHeaders(['Accept-Language' => 'fr-FR,fr;q=0.9'])->get('api/settings')->getJSON(), true);
            $this->assertSame('en-GB', $api['locale']['code']);
            $this->assertSame('Ledger', $api['sections'][1]['label']);
        } finally {
            unset($_COOKIE['elog_locale']);
            service('superglobals')->unsetCookie('elog_locale');
        }
    }

    /**
     * The point of the whole arrangement: language is one person's preference, not
     * the machine's. Two people at the same workstation each read in their own.
     */
    public function testTheLanguageAUserChoosesIsTheirsAloneAndFollowsThem(): void
    {
        $lookups = new \App\Repositories\Lookups();
        $me      = 'w.kamau@elog.or.ke';
        $someone = 'm.otieno@elog.or.ke';

        try {
            $this->signIn($me);
            $chosen = json_decode($this->withBodyFormat('json')->post('api/i18n/locale', ['locale' => 'fr'])->getJSON(), true);
            $this->assertSame('fr', $chosen['locale']['code']);

            // Read back on a request carrying no cookie and an English browser: the
            // choice is held against the account, so it follows them to any machine.
            $this->signIn($me);
            $mine = json_decode($this->withHeaders(['Accept-Language' => 'en-GB,en;q=0.9'])->get('api/me')->getJSON(), true);
            $this->assertSame('fr', $mine['locale']['code']);
            $this->assertStringContainsString('<html lang="fr"', $this->get('settings')->getBody());

            // Nobody else moved. The next person to sign in at this machine reads in
            // their own language — here, the one their browser asks for.
            $this->signIn($someone);
            $theirs = json_decode($this->withHeaders(['Accept-Language' => 'en-GB,en;q=0.9'])->get('api/me')->getJSON(), true);
            $this->assertSame('en-GB', $theirs['locale']['code']);
            $this->assertNull((new \App\Repositories\UserRepository())->localeOf((int) $lookups->userId($someone)));
        } finally {
            // The books are seeded once for the class, so put the choice back.
            db_connect()->table('users')->where('id', $lookups->userId($me))->update(['locale_id' => null]);
            Repository::forget();
            $this->signIn();
        }
    }

    /** A language the instance does not publish is refused rather than stored. */
    public function testAnUnknownLanguageCannotBeChosen(): void
    {
        $refused = $this->withBodyFormat('json')->post('api/i18n/locale', ['locale' => 'xx']);

        $refused->assertStatus(422);
        $this->assertStringContainsString('not a language this instance publishes', $refused->getJSON());
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
