<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\JournalRepository;
use App\Repositories\Lookups;
use App\Repositories\RecurringTemplateRepository;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * A journal opened in the editor: a draft is changed or discarded, a posted entry
 * is corrected only by a linked reversal that someone else approves, and a
 * recurring template raises ordinary entries that still need approval.
 */
final class JournalLifecycleTest extends CIUnitTestCase
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

    public function testTheRegisterIsServedAPageAtATimeWithStatsOverEveryJournal(): void
    {
        $register = json_decode($this->get('api/journals')->getJSON(), true);

        $this->assertLessThanOrEqual(10, count($register['rows']));
        $this->assertSame((int) ceil($register['filtered'] / 10), $register['pages']);
        $this->assertSame(['Awaiting approval', 'Drafts', 'Posted this period', 'Value posted YTD', 'Reversals'], array_column($register['stats'], 'label'));
        $this->assertSame('August 2026', $register['stats'][2]['note']);
        $this->assertArrayHasKey('grant', $register['rows'][0]);
        $this->assertArrayHasKey('grantMissing', $register['rows'][0]);

        $drafts = json_decode($this->get('api/journals?status=Draft')->getJSON(), true);
        $this->assertSame(['Draft'], array_values(array_unique(array_column($drafts['rows'], 'status'))));
    }

    public function testTheEditorActionsAreServedOverTheApi(): void
    {
        $this->actAs('j.achieng@elog.or.ke');
        $draft = $this->draft();

        $update = $this->withBodyFormat('json')->post('api/journals/' . $draft['ref'], [
            'date' => '2026-08-22', 'type' => 'Accrual', 'period' => 'Aug 2026', 'status' => 'Draft', 'docLink' => $draft['docLink'],
            'memo' => '', 'narration' => 'Changed', 'lines' => [$this->line('5330', 700, 0), $this->line('2120', 0, 700)],
        ]);
        $update->assertStatus(200);
        $this->assertSame('Changed', json_decode($update->getJSON(), true)['journal']['narration']);

        $this->post('api/journals/' . $draft['ref'] . '/discard')->assertStatus(200);
        $this->get('api/journals/' . $draft['ref'])->assertStatus(404);

        $recurring = json_decode($this->get('api/journals/recurring')->getJSON(), true);
        $this->assertSame('3 active · 1 paused · nothing posts without approval', $recurring['summary']);

        $run = $this->post('api/journals/recurring/RT-02/run');
        $run->assertStatus(201);
        $run = json_decode($run->getJSON(), true)['journal'];
        $this->assertSame('Pending approval', $run['status']);

        // An accountant cannot approve, and an approver without preparation rights cannot raise entries.
        $this->post('api/journals/' . $run['ref'] . '/approve')->assertStatus(403);
        $this->actAs('d.kiptoo@elog.or.ke');
        $this->post('api/journals/recurring/RT-01/run')->assertStatus(403);
    }

    public function testJournalsAboveTheThresholdNeedTheExecutiveDirector(): void
    {
        $repo = new JournalRepository();

        try {
            $repo->approve('JV-26-0310', $this->user('W. Kamau'));
            $this->fail('The Finance Manager approved a journal above the threshold.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString("above KES 500,000 need the Executive Director's approval", $e->getMessage());
        }

        $this->assertSame('Posted', $repo->approve('JV-26-0310', $this->user('D. Kiptoo'))['status']);
        $this->assertStringStartsWith('Approval threshold KES 500,000 · above it the Executive Director approves', json_decode($this->get('api/journals')->getJSON(), true)['policy']);
    }

    public function testRecurringTemplatesAreFoundInGlobalSearch(): void
    {
        $search = json_decode($this->get('api/search?q=RT-01')->getJSON(), true);
        $group = array_values(array_filter($search['groups'], static fn ($g) => $g['label'] === 'Recurring templates'))[0] ?? null;

        $this->assertNotNull($group);
        $this->assertSame('RT-01', $group['items'][0]['ref']);
        $this->assertSame('/journals?template=RT-01', $group['items'][0]['href']);
    }

    public function testADraftIsChangedAndSubmittedWithoutChangingItsPreparer(): void
    {
        $repo = new JournalRepository();
        $draft = $this->draft();

        $saved = $repo->update($draft['ref'], [
            'date' => '2026-08-25', 'type' => 'Accrual', 'period' => 'Aug 2026', 'status' => 'Pending approval', 'docLink' => $draft['docLink'],
            'memo' => 'Revised', 'narration' => 'Revised accrual',
            'lines' => [$this->line('5330', 900, 0), $this->line('2120', 0, 900)],
        ], $this->user('M. Otieno'));

        $this->assertSame('Pending approval', $saved['status']);
        $this->assertSame('J. Achieng', $saved['preparer']);
        $this->assertSame('25 Aug 2026', $saved['date']);
        $this->assertSame([900, 0], array_column($saved['lines'], 'dr'));
        $this->assertSame($draft['doc'], $saved['doc']);
        $this->assertStringStartsWith('Submitted for approval by M. Otieno', end($saved['trail'])['what']);
    }

    public function testADraftIsDiscardedButAPostedEntryIsNot(): void
    {
        $repo = new JournalRepository();
        $draft = $this->draft();

        $repo->discard($draft['ref'], $this->user('J. Achieng'));
        $this->assertNull($repo->find($draft['ref']));

        $posted = current(array_filter($repo->all(), fn ($j) => $j['status'] === 'Posted' && !$j['opening']));
        $this->expectException(RuleViolation::class);
        $this->expectExceptionMessage('Only a draft can be discarded.');
        $repo->discard($posted['ref'], $this->user('J. Achieng'));
    }

    public function testAReversalSwapsTheSidesAndMarksTheOriginalOnlyOnceApproved(): void
    {
        $repo = new JournalRepository();
        $original = $this->posted();

        $reversal = $repo->reverse($original['ref'], $this->user('J. Achieng'));

        $this->assertSame('Pending approval', $reversal['status']);
        $this->assertSame('Reversing', $reversal['type']);
        $this->assertSame($original['ref'], $reversal['reversalOf']);
        $this->assertSame(array_column($original['lines'], 'cr'), array_column($reversal['lines'], 'dr'));
        $this->assertSame('Posted', $repo->find($original['ref'])['status']);

        $repo->approve($reversal['ref'], $this->user('W. Kamau'));

        $this->assertSame('Reversed', $repo->find($original['ref'])['status']);
        $this->assertSame('Posted', $repo->find($reversal['ref'])['status']);
    }

    public function testAnEntryIsReversedOnlyOnce(): void
    {
        $repo = new JournalRepository();
        $original = $this->posted();
        $repo->reverse($original['ref'], $this->user('J. Achieng'));

        $this->expectException(RuleViolation::class);
        $this->expectExceptionMessage('already reverses ' . $original['ref']);
        $repo->reverse($original['ref'], $this->user('J. Achieng'));
    }

    public function testThePreparerCannotApproveTheirOwnEntry(): void
    {
        $draft = $this->draft('Pending approval');

        $this->expectException(RuleViolation::class);
        $this->expectExceptionMessage('cannot also approve it');
        (new JournalRepository())->approve($draft['ref'], $this->user('J. Achieng'));
    }

    // ---- Recurring templates ----

    public function testTheScheduleFollowsTheRuleNotThePreviousDate(): void
    {
        $this->assertSame('2026-10-31', RecurringTemplateRepository::advance('2026-09-30', 'monthly', 'Last day of the month'));
        $this->assertSame('2026-02-28', RecurringTemplateRepository::advance('2026-01-31', 'monthly', 'Last day of the month'));
        $this->assertSame('2027-01-01', RecurringTemplateRepository::advance('2026-10-01', 'quarterly', 'First day of the quarter'));
        $this->assertSame('2026-10-25', RecurringTemplateRepository::advance('2026-09-25', 'monthly', '25th of the month'));

        $this->assertSame('Last day of the month', RecurringTemplateRepository::ruleFor('2026-06-30'));
        $this->assertSame('First day of the month', RecurringTemplateRepository::ruleFor('2026-07-01'));
        $this->assertSame('22nd of the month', RecurringTemplateRepository::ruleFor('2026-06-22'));
    }

    public function testTheSeededTemplatesCarryTheirLinesAndRuns(): void
    {
        $templates = array_column((new RecurringTemplateRepository())->all(), null, 'id');

        $this->assertSame(['RT-01', 'RT-02', 'RT-03', 'RT-04'], array_keys($templates));
        $this->assertSame('Paused', $templates['RT-04']['status']);
        $this->assertSame('30 Sep 2026', $templates['RT-01']['next']);
        $this->assertSame(['5350', '1390'], array_column($templates['RT-01']['lines'], 'code'));
        $this->assertSame(['AC-26-0308', 'AC-26-0290', 'AC-26-0255'], array_column($templates['RT-01']['runs'], 'ref'));
    }

    public function testRunningATemplateRaisesAnEntryForApprovalAndMovesTheScheduleOn(): void
    {
        $repo = new RecurringTemplateRepository();

        $journal = $repo->run('RT-01', $this->user('M. Otieno'));

        $this->assertSame('Pending approval', $journal['status']);
        $this->assertSame('30 Sep 2026', $journal['date']);
        $this->assertSame('Sep 2026', $journal['period']);
        $this->assertSame('Recurring', $journal['type']);
        $this->assertSame('recurring:RT-01', $journal['docLink']);
        $this->assertSame('ELOG/AC/RT-01', $journal['doc']);
        $this->assertSame([364000, 0], array_column($journal['lines'], 'dr'));
        $this->assertStringStartsWith('Generated from recurring template RT-01 by M. Otieno', $journal['trail'][0]['what']);

        $template = $repo->find('RT-01');
        $this->assertSame('31 Oct 2026', $template['next']);
        $this->assertSame('30 Sep 2026', $template['last']);
        $this->assertSame(['ref' => $journal['ref'], 'status' => 'Pending approval'], array_intersect_key($template['runs'][0], ['ref' => 1, 'status' => 1]));
    }

    public function testATemplateNotSetToSubmitRaisesADraft(): void
    {
        $journal = (new RecurringTemplateRepository())->run('RT-03', $this->user('S. Njeri'));

        $this->assertSame('Draft', $journal['status']);
        $this->assertSame('01 Oct 2026', $journal['date']);
    }

    public function testAPausedTemplateDoesNotRunUntilResumed(): void
    {
        $repo = new RecurringTemplateRepository();

        try {
            $repo->run('RT-04', $this->user('J. Achieng'));
            $this->fail('A paused template ran.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('is paused', $e->getMessage());
        }

        $this->assertSame('Active', $repo->toggle('RT-04', $this->user('J. Achieng'))['status']);
        $this->assertSame('Draft', $repo->run('RT-04', $this->user('J. Achieng'))['status']);
    }

    public function testAnEntryBecomesAMonthlyTemplateWithItAsTheFirstRun(): void
    {
        $repo = new RecurringTemplateRepository();
        $draft = $this->draft();

        $template = $repo->fromJournal($draft['ref'], $this->user('J. Achieng'));

        $this->assertSame('RT-05', $template['id']);
        $this->assertSame('Accrual', $template['type']);
        $this->assertSame('Monthly', $template['frequency']);
        $this->assertSame('20th of the month', $template['rule']);
        $this->assertSame('20 Sep 2026', $template['next']);
        $this->assertSame([$draft['ref']], array_column($template['runs'], 'ref'));

        $this->expectException(RuleViolation::class);
        $this->expectExceptionMessage('already a run of recurring template RT-05');
        $repo->fromJournal($draft['ref'], $this->user('J. Achieng'));
    }

    public function testALargeOrAdjustingEntryGoesForApprovalOnlyWithItsDocument(): void
    {
        $repo = new JournalRepository();
        $achieng = $this->user('J. Achieng');
        $entry = static fn (string $type, float $amount, string $status) => [
            'date' => '2026-08-20', 'type' => $type, 'period' => 'Aug 2026', 'status' => $status, 'docLink' => 'auto',
            'memo' => '', 'narration' => 'Consultancy accrual',
        ];
        $lines = fn (float $amount) => ['lines' => [$this->line('5330', $amount, 0), $this->line('2120', 0, $amount)]];

        // Above the threshold: a draft saves, but it is not submitted without its document.
        $draft = $repo->create($entry('Accrual', 750000, 'Draft') + $lines(750000), $achieng);
        $this->assertSame('Draft', $draft['status']);
        try {
            $repo->update($draft['ref'], $entry('Accrual', 750000, 'Pending approval') + $lines(750000), $achieng);
            $this->fail('A 750,000 entry went for approval without its document.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('above the 500,000 at which a journal needs its supporting document', $e->getMessage());
        }
        $sent = $repo->update($draft['ref'], $entry('Accrual', 750000, 'Pending approval') + $lines(750000), $achieng, [$this->documentFile('consultancy-contract.pdf')]);
        $this->assertSame(['Pending approval', 'consultancy-contract.pdf'], [$sent['status'], $sent['attachments'][0]['name']]);

        // At or below it, only the types that always need one are stopped.
        $this->assertSame('Pending approval', $repo->create($entry('Accrual', 500000, 'Pending approval') + $lines(500000), $achieng)['status']);
        try {
            $repo->create($entry('Adjustment', 400, 'Pending approval') + $lines(400), $achieng);
            $this->fail('An adjustment went for approval without its document.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('adjustment journal goes for approval only with the document', $e->getMessage());
        }
    }

    public function testADocumentStaysWithAnEntryUntilItIsTakenBackFromApproval(): void
    {
        $repo = new JournalRepository();
        $achieng = $this->user('J. Achieng');
        $entry = fn (string $status) => [
            'date' => '2026-08-20', 'type' => 'Accrual', 'period' => 'Aug 2026', 'status' => $status, 'docLink' => 'auto',
            'memo' => '', 'narration' => 'Audit fee accrual',
            'lines' => [$this->line('5330', 500, 0), $this->line('2120', 0, 500)],
        ];
        $sent = $repo->create($entry('Pending approval'), $achieng, [$this->documentFile('engagement-letter.pdf'), $this->documentFile('fee-note.pdf')]);
        [$letter, $note] = array_column($sent['attachments'], 'id');

        // Awaiting approval: neither an edit that stays submitted nor one that saves a draft removes it.
        foreach (['Pending approval', 'Draft'] as $status) {
            try {
                $repo->update($sent['ref'], $entry($status), $achieng, [], [$letter]);
                $this->fail('A document was removed from an entry awaiting approval.');
            } catch (RuleViolation $e) {
                $this->assertStringContainsString('awaiting approval, and the documents it was submitted with stay with it', $e->getMessage());
            }
        }
        $this->assertSame(['Pending approval', 2], [$repo->find($sent['ref'])['status'], count($repo->find($sent['ref'])['attachments'])]);

        // Taken back to draft by its preparer, it can drop one.
        $this->assertSame('Draft', $repo->update($sent['ref'], $entry('Draft'), $achieng)['status']);
        $this->assertSame(['fee-note.pdf'], array_column($repo->update($sent['ref'], $entry('Pending approval'), $achieng, [], [$letter])['attachments'], 'name'));

        // Returned to draft by the approver, likewise.
        $repo->reject($sent['ref'], $this->user('W. Kamau'), 'Attach the signed letter instead');
        $this->assertSame([], $repo->update($sent['ref'], $entry('Draft'), $achieng, [], [$note])['attachments']);
    }

    private function draft(string $status = 'Draft'): array
    {
        return (new JournalRepository())->create([
            'date' => '2026-08-20', 'type' => 'Accrual', 'period' => 'Aug 2026', 'status' => $status, 'docLink' => 'auto',
            'memo' => '', 'narration' => 'Audit fee accrual',
            'lines' => [$this->line('5330', 500, 0), $this->line('2120', 0, 500)],
        ], $this->user('J. Achieng'));
    }

    /** A posted expense reclassification in an open period. */
    private function posted(): array
    {
        $repo = new JournalRepository();
        $journal = $repo->create([
            'date' => '2026-08-21', 'type' => 'Adjustment', 'period' => 'Aug 2026', 'status' => 'Pending approval', 'docLink' => 'auto',
            'memo' => '', 'narration' => 'Reclassify stationery',
            'lines' => [$this->line('5330', 400, 0), $this->line('5310', 0, 400)],
        ], $this->user('J. Achieng'), [$this->documentFile('reclassification-memo.pdf')]);

        return $repo->approve($journal['ref'], $this->user('W. Kamau'));
    }

    private function line(string $code, float $dr, float $cr): array
    {
        return ['code' => $code, 'desc' => '', 'grantRef' => '', 'fund' => 'General Fund', 'program' => 'Shared services', 'dr' => $dr, 'cr' => $cr];
    }

    private function user(string $name): int
    {
        return (new Lookups())->userId($name);
    }

    /** The request reads cookies from the shared superglobals, which a test request does not refresh. */
    private function actAs(string $email): void
    {
        $_COOKIE['elog_actor'] = $email;
        service('superglobals')->setCookie('elog_actor', $email);
    }
}
