<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\Lookups;
use App\Repositories\PeriodCloseRepository;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use App\Repositories\UserRepository;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Period close: ledger checks are read from the books, confirmations are taken
 * from the people entitled to give them, a closed month is settled history, and
 * closing and reopening run in order and are recorded.
 */
final class PeriodCloseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace   = 'App';
    protected $refresh     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $seed        = DatabaseSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['app.asOf'] = '2026-08-31';
        Repository::forget();
    }

    public function testTheOpenMonthIsBlockedByTheLedgerAndItsConfirmations(): void
    {
        $repo = new PeriodCloseRepository();
        $view = $repo->overview($repo->period('2026-08'), 2026, $this->actor('W. Kamau'));
        $tasks = array_column($view['tasks'], null, 'key');

        $this->assertCount(14, $view['tasks']);
        $this->assertFalse($tasks['journals']['settled']);
        $this->assertSame('ledger', $tasks['journals']['kind']);
        $this->assertTrue($tasks['tb']['settled']);
        $this->assertSame('confirmation', $tasks['review']['kind']);
        $this->assertTrue($tasks['review']['canTick']);
        $this->assertFalse($tasks['signoff']['canTick']);
        $this->assertSame('blocked', $view['readiness']['tone']);
        $this->assertStringStartsWith('Close blocked', $view['close']['label']);
    }

    public function testALockedMonthIsSettledHistoryWithItsArchivedEntries(): void
    {
        $repo = new PeriodCloseRepository();
        $view = $repo->overview($repo->period('2026-07'), 2026, $this->actor('W. Kamau'));

        $this->assertSame([], array_filter($view['tasks'], static fn ($t) => !$t['settled']));
        $this->assertSame('Closed 19 Aug 2026 · M. Otieno · approved by D. Kiptoo · reopening is recorded in the audit log', $view['footer']);
        // 88 entries held in the archive, plus the July journals posted live.
        $this->assertGreaterThan(88, (int) $view['totals']['rows'][0]['value']);
        $this->assertSame('Locked', $view['readiness']['lock']);
    }

    public function testTheYearSwitcherShowsTheLatestThreeYearsAndTheRestAsEarlier(): void
    {
        $repo = new PeriodCloseRepository();
        $years = $repo->overview($repo->period('2026-08'), 2026, $this->actor('W. Kamau'))['years'];

        $this->assertSame(['FY2024', 'FY2025', 'FY2026'], array_column($years['shown'], 'label'));
        $this->assertSame(['FY2023', 'FY2022', 'FY2021'], array_column($years['earlier'], 'label'));
        // The working year runs to the month after today.
        $this->assertSame(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep'], array_column($years['periods'], 'label'));
        $this->assertSame('7 of 9 months locked · earliest open is Aug 2026', $years['note']);

        $picked = $repo->overview($repo->period('2026-08'), 2022, $this->actor('W. Kamau'))['years'];
        $this->assertSame(['FY2022', 'FY2025', 'FY2026'], array_column($picked['shown'], 'label'));
        $this->assertSame('Showing FY2022 · the checklist below is still Aug 2026', $picked['offYear']);
    }

    public function testAConfirmationIsTakenOnlyFromWhoeverItIsFor(): void
    {
        $repo = new PeriodCloseRepository();
        $august = $repo->period('2026-08');

        try {
            $repo->confirm($august, 'review', true, $this->actor('M. Otieno'), $this->userId('M. Otieno'));
            $this->fail('A Senior Accountant confirmed the Finance Manager review.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('It is for Finance Manager', $e->getMessage());
        }

        $repo->confirm($august, 'review', true, $this->actor('W. Kamau'), $this->userId('W. Kamau'));
        $review = array_column($repo->checklist($august), null, 'key')['review'];
        $this->assertTrue($review['settled']);
        $this->assertStringStartsWith('Confirmed by W. Kamau', $review['note']);
    }

    public function testTheExecutiveDirectorAuthorisesLast(): void
    {
        $repo = new PeriodCloseRepository();

        $this->expectException(RuleViolation::class);
        $this->expectExceptionMessage('Authorise the close last');
        $repo->confirm($repo->period('2026-08'), 'signoff', true, $this->actor('D. Kiptoo'), $this->userId('D. Kiptoo'));
    }

    public function testAMonthWithOutstandingItemsCannotBeClosed(): void
    {
        $repo = new PeriodCloseRepository();

        $this->expectException(RuleViolation::class);
        $this->expectExceptionMessage('still outstanding');
        $repo->close($repo->period('2026-08'), $this->actor('W. Kamau'), $this->userId('W. Kamau'));
    }

    public function testPeriodsCloseInOrder(): void
    {
        $repo = new PeriodCloseRepository();

        $this->expectException(RuleViolation::class);
        $this->expectExceptionMessage('Close Aug 2026 first');
        $repo->close($repo->period('2026-09'), $this->actor('W. Kamau'), $this->userId('W. Kamau'));
    }

    public function testOnlyTheExecutiveDirectorReopensAndFromTheLatestBack(): void
    {
        $repo = new PeriodCloseRepository();

        try {
            $repo->reopen($repo->period('2026-07'), $this->actor('W. Kamau'), $this->userId('W. Kamau'), '');
            $this->fail('The Finance Manager reopened a closed period.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('needs the Executive Director', $e->getMessage());
        }

        try {
            $repo->reopen($repo->period('2026-06'), $this->actor('D. Kiptoo'), $this->userId('D. Kiptoo'), '');
            $this->fail('June was reopened while July was still closed.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('Reopen Jul 2026 first', $e->getMessage());
        }
    }

    public function testReopeningWithdrawsTheReviewAndAuthorisationAndIsRecorded(): void
    {
        $repo = new PeriodCloseRepository();
        $repo->reopen($repo->period('2026-07'), $this->actor('D. Kiptoo'), $this->userId('D. Kiptoo'), 'Misposted stipend batch');
        $july = $repo->period('2026-07');

        $this->assertSame('open', $july['status']);
        $this->assertNull($july['closed_by']);
        $this->assertSame('Jul 2026 reopened — sign-off withdrawn and the close must be retaken: Misposted stipend batch', $repo->history()[0]['what']);

        $checks = array_column($repo->checklist($july), null, 'key');
        $this->assertFalse($checks['review']['settled']);
        $this->assertFalse($checks['signoff']['settled']);
    }

    public function testTheConfirmationsOfAClosedPeriodCannotChange(): void
    {
        $db = db_connect();
        $june = (new PeriodCloseRepository())->period('2026-06');
        $check = $db->table('period_close_checks')->where('key', 'accruals')->get()->getRowArray();

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Reopen the period to change them');
        $db->table('period_close_steps')->insert([
            'period_id' => $june['id'], 'check_id' => $check['id'], 'completed_by' => $this->userId('M. Otieno'), 'completed_at' => '2026-08-31 10:00:00',
        ]);
    }

    public function testTheCloseHistoryIsNewestFirst(): void
    {
        // Other tests in this class may have reopened July since the data was seeded.
        $history = array_values(array_filter((new PeriodCloseRepository())->history(), static fn ($h) => !str_contains($h['what'], 'reopened —')));

        $this->assertSame('July 2026 closed to further posting', $history[0]['what']);
        $this->assertSame('19 Aug 11:22 · M. Otieno · approved by D. Kiptoo', $history[0]['meta']);
        $this->assertSame('June 2026 closed after the audit sample was drawn', $history[1]['what']);
    }

    private function actor(string $short): array
    {
        foreach ((new UserRepository())->actors() as $a) {
            if ($a['short'] === $short) {
                return $a;
            }
        }

        $this->fail("No actor {$short}.");
    }

    private function userId(string $short): int
    {
        return (new Lookups())->userId($short);
    }
}
