<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\ProgrammeRepository;
use App\Repositories\Repository;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The programme register.
 *
 * Two rules are worth holding on to. Shared costs are recovered across
 * programmes by the percentages held here, so the base has to total exactly 100%
 * after every write or the monthly allocation under-recovers or charges twice.
 * And programme is a mandatory coding dimension on every posting line, so a
 * programme is closed to new coding rather than deleted, and a rename leaves the
 * postings already made reading under the name they were recorded under.
 */
final class ProgrammesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use \Tests\Support\SignsIn;

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

    public function testTheBaseBalancesAndOnlySaysSoWhenItDoesNot(): void
    {
        $index = $this->api('api/programmes');

        $this->assertSame('100%', $this->stat($index, 'Shared-cost base')['value']);
        $this->assertSame('Allocation balances', $this->stat($index, 'Shared-cost base')['note']);
        $this->assertNull($index['shareWarning'], 'a base that balances needs no commentary');
    }

    /**
     * The shared-cost pool holds no share of its own, and neither does a closed
     * programme. Only the flag tells them apart, and without it the base would
     * read as complete when a live pool had quietly dropped out of it.
     */
    public function testTheSharedCostPoolIsNotMistakenForAClosedProgramme(): void
    {
        $repo = new ProgrammeRepository();

        $pool   = $repo->find('PRG-90');
        $closed = $repo->find('PRG-50');

        $this->assertNull($pool['share']);
        $this->assertNull($closed['share']);
        $this->assertTrue($pool['allocatesOut']);
        $this->assertFalse($closed['allocatesOut']);

        $drawer = $this->api('api/programmes/PRG-90');
        $this->assertStringContainsString('shared-cost pool', $drawer['shareText']);
        $this->assertSame('No shared-cost share — programme is closed', $this->api('api/programmes/PRG-50')['shareText']);
    }

    public function testOpeningAProgrammeProRatesTheOthersSoTheBaseStaysAtExactlyOneHundred(): void
    {
        $this->actAs('w.kamau@elog.or.ke');

        $before = $this->shares();
        $this->send('api/programmes', $this->form(['share' => 10]))->assertOK();

        $after = $this->shares();
        $this->assertSame(100.0, round(array_sum($after), 3), 'the base still totals 100%');
        $this->assertSame(10.0, (float) $after['Media Monitoring']);

        // Each existing programme keeps its place in the order, reduced by a tenth.
        foreach ($before as $name => $was) {
            $this->assertEqualsWithDelta($was * 0.9, $after[$name], 0.05, $name . ' was reduced proportionally');
        }

        $opened = (new ProgrammeRepository())->find('PRG-100');
        $this->assertSame('Pipeline', $opened['status'], 'a new programme opens with no budget and nothing coded to it');
        $this->assertSame(0, $opened['budget']);
        $this->assertFalse($opened['allocatesOut']);
    }

    /**
     * Deferring means the programme carries none of the shared costs — so its own
     * share is zero however the form was filled in, or the base would be left at
     * 100% plus whatever was typed.
     */
    public function testDeferringLeavesTheExistingSharesAloneAndGivesTheNewProgrammeNone(): void
    {
        $this->actAs('w.kamau@elog.or.ke');

        $before = $this->shares();
        $this->send('api/programmes', $this->form(['share' => 10, 'mode' => 'defer']))->assertOK();

        $after = $this->shares();
        $this->assertSame(0.0, (float) $after['Media Monitoring']);
        unset($after['Media Monitoring']);
        $this->assertSame($before, $after);
        $this->assertSame(100.0, (new ProgrammeRepository())->shareTotal());
    }

    public function testHandSetSharesAreRefusedUnlessTheyTotalOneHundred(): void
    {
        $this->actAs('w.kamau@elog.or.ke');

        $shares = [
            ['code' => 'PRG-10', 'pct' => 40], ['code' => 'PRG-20', 'pct' => 30],
            ['code' => 'PRG-30', 'pct' => 22], ['code' => 'PRG-40', 'pct' => 4],
        ];
        $this->refusal('api/programmes', $this->form(['share' => 10, 'mode' => 'manual', 'shares' => $shares]), 'has to be exactly 100%');

        $shares[0]['pct'] = 34;
        $this->send('api/programmes', $this->form(['share' => 10, 'mode' => 'manual', 'shares' => $shares]))->assertOK();

        $after = $this->shares();
        $this->assertSame(34.0, (float) $after['Election Observation']);
        $this->assertSame(100.0, round(array_sum($after), 3));
    }

    public function testAProgrammeNeedsANameOfItsOwnAndAPurpose(): void
    {
        $this->actAs('w.kamau@elog.or.ke');

        $this->refusal('api/programmes', $this->form(['name' => 'Civic Education']), 'already exists');
        $this->refusal('api/programmes', $this->form(['purpose' => '']), 'State what the programme covers');
        $this->refusal('api/programmes', $this->form(['share' => 120]), 'between 0 and 99%');
    }

    /**
     * A rename changes the label from now on. What is already posted has to keep
     * reading under the name it was recorded under, or a donor report re-run next
     * year stops agreeing with the one that was filed — so the rename is kept as a
     * dated event.
     */
    public function testARenameIsRecordedAsADatedEventRatherThanOverwritingHistory(): void
    {
        $this->actAs('w.kamau@elog.or.ke');

        $this->refusal('api/programmes/PRG-20/rename', ['name' => 'Election Observation'], 'already in use');

        $res = $this->send('api/programmes/PRG-20/rename', ['name' => 'Civic and Voter Education']);
        $res->assertOK();
        $this->assertStringContainsString('Journals already posted keep Civic Education as recorded', json_decode($res->getJSON(), true)['message']);

        $after = $this->api('api/programmes/PRG-20');
        $this->assertSame('Civic and Voter Education', $after['name']);
        $this->assertSame([[
            'on' => '31 Aug 2026', 'from' => 'Civic Education', 'to' => 'Civic and Voter Education', 'by' => 'W. Kamau',
        ]], $after['renames']);
    }

    public function testAProgrammeThatIsStillCarryingWorkCannotBeClosed(): void
    {
        $observation = $this->api('api/programmes/PRG-10');

        $this->assertFalse($observation['canDeactivate']);
        $what = array_column($observation['blockers'], 'what');
        $this->assertContains('2 live awards', $what);
        $this->assertContains('8 staff allocations', $what);
        $this->assertContains('Receives 44% of shared costs', $what);
        $this->assertContains('Unspent budget of 58,835,000', $what);

        // Each blocker says what clears it, because "cannot deactivate" alone is useless.
        foreach ($observation['blockers'] as $blocker) {
            $this->assertNotSame('', $blocker['detail']);
        }

        // Purchase orders raised but not yet received are the other way work is
        // still in flight, even where nothing has been posted.
        $civic = $this->api('api/programmes/PRG-20');
        $this->assertContains('Open purchase commitments', array_column($civic['blockers'], 'what'));

        $this->actAs('w.kamau@elog.or.ke');
        $this->refusal('api/programmes/PRG-10/deactivate', [], 'cannot be deactivated yet');
    }

    /** The pool has to hand the job on before it can close, or shared costs have nowhere to go. */
    public function testTheSharedCostPoolCannotSimplyBeClosed(): void
    {
        $this->assertSame('Collects the shared costs', $this->api('api/programmes/PRG-90')['blockers'][0]['what']);
    }

    public function testReleasingAShareRebasesTheRestToOneHundred(): void
    {
        $this->actAs('w.kamau@elog.or.ke');

        $this->send('api/programmes/PRG-40/zero-share')->assertOK();

        $after = $this->shares();
        $this->assertSame(0.0, (float) $after['Youth and Gender Inclusion']);
        $this->assertSame(100.0, round(array_sum($after), 3));
        $this->assertGreaterThan(44, $after['Election Observation'], 'the others absorbed the released share');

        $this->refusal('api/programmes/PRG-40/zero-share', [], 'carries no shared costs already');
    }

    public function testAClosedProgrammeLeavesTheBaseAndComesBackAtNothing(): void
    {
        $this->actAs('w.kamau@elog.or.ke');
        $this->send('api/programmes/PRG-40/zero-share')->assertOK();

        $before = $this->api('api/programmes/PRG-40');
        $this->assertSame([], $before['blockers']);
        $this->assertTrue($before['canDeactivate']);

        $this->send('api/programmes/PRG-40/deactivate')->assertOK();

        $closed = (new ProgrammeRepository())->find('PRG-40');
        $this->assertSame('Inactive', $closed['status']);
        $this->assertNull($closed['share'], 'a closed programme is out of the base entirely, not in it for nothing');
        $this->assertSame(100.0, (new ProgrammeRepository())->shareTotal());
        $this->assertNull($this->api('api/programmes')['shareWarning']);

        $this->send('api/programmes/PRG-40/reactivate')->assertOK();
        $reopened = (new ProgrammeRepository())->find('PRG-40');
        $this->assertSame('Pipeline', $reopened['status']);
        $this->assertSame(0, $reopened['share'], 'it comes back with no share until one is set');
        $this->assertSame(100.0, (new ProgrammeRepository())->shareTotal());
    }

    // ---- Helpers ----

    /** Every programme still in the shared-cost base, by name. */
    private function shares(): array
    {
        Repository::forget();
        $out = [];
        foreach ((new ProgrammeRepository())->allocable() as $p) {
            $out[$p['name']] = (float) $p['share'];
        }

        return $out;
    }

    private function form(array $over = []): array
    {
        return $over + [
            'name' => 'Media Monitoring', 'code' => '', 'manager' => 'M. Otieno', 'since' => '2026-09-01',
            'purpose' => 'Monitoring of broadcast and online coverage during the campaign period.',
            'share' => 10, 'mode' => 'prorate', 'shares' => [],
        ];
    }

    private function stat(array $index, string $label): array
    {
        foreach ($index['stats'] as $stat) {
            if ($stat['label'] === $label) {
                return $stat;
            }
        }

        $this->fail($label . ' is not one of the figures on the page.');
    }

    private function refusal(string $url, array $body, string $expected): void
    {
        $res = $this->withBodyFormat('json')->post($url, $body);
        $res->assertStatus(422);
        $this->assertStringContainsString($expected, json_decode($res->getJSON(), true)['error']);
    }

    private function send(string $url, array $body = [])
    {
        return $this->withBodyFormat('json')->post($url, $body);
    }

    private function actAs(string $email): void
    {
        $_COOKIE['elog_actor'] = $email;
        service('superglobals')->setCookie('elog_actor', $email);
        Repository::forget();
    }

    private function api(string $url): array
    {
        Repository::forget();

        return json_decode($this->get($url)->getJSON(), true);
    }
}
