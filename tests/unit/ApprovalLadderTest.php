<?php

use App\Database\Seeds\DatabaseSeeder;
use App\Repositories\ApprovalPolicy;
use App\Repositories\AdvancesRepository;
use App\Repositories\AuthorityRequired;
use App\Repositories\JournalRepository;
use App\Repositories\Lookups;
use App\Repositories\PayablesRepository;
use App\Repositories\ProcurementRepository;
use App\Repositories\Repository;
use App\Repositories\RuleViolation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The signature ladder a document climbs before it counts as approved.
 *
 * What has to hold: a database upgraded from the single-approver rule approves
 * exactly what it approved before, because each rule became one or two banded
 * steps; a ladder with a second step leaves the document waiting after the first
 * signature and finishes only on the last; nobody signs twice in a round, and a
 * quorum takes different people; a step engages only inside its band; an
 * authority outside the system is satisfied by its reference; and a return closes
 * the round, so a resubmitted document is signed again from the top.
 *
 * See docs/approvals.md.
 */
final class ApprovalLadderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use \Tests\Support\SignsIn;

    protected $namespace = 'App';
    protected $refresh   = true;
    protected $seed      = DatabaseSeeder::class;

    /** Journals: the Finance Manager up to 500,000, the Executive Director above it. */
    private const OVER  = 600000.0;
    private const UNDER = 400000.0;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['app.asOf'] = '2026-08-31';
        Repository::forget();
    }

    // ------------------------------------------------------------------
    // What the upgrade preserved
    // ------------------------------------------------------------------

    public function testAnUpgradedRuleBecomesTheTwoBandedStepsItAlreadyMeant(): void
    {
        $steps = (new ApprovalPolicy())->steps('journal');

        $this->assertCount(2, $steps);
        $this->assertSame(['Finance Manager', 0.0, 500000.0], [$steps[0]['role'], $steps[0]['above'], $steps[0]['upto']]);
        $this->assertSame(['Executive Director', 500000.0, null], [$steps[1]['role'], $steps[1]['above'], $steps[1]['upto']]);
    }

    public function testTheUpgradeMapsEveryShapeOfOldRuleToTheLadderItMeant(): void
    {
        // The mapping the ladder migration, the installer and the demonstration
        // seed all write from — a seeded database exercises only the last of them.
        [$approver, $escalation] = [7, 9];

        $banded = ApprovalPolicy::stepsFor(500000, $approver, $escalation);
        $this->assertSame([1, $approver, 0.0, 500000.0], array_values(array_intersect_key($banded[0], array_flip(['step_no', 'role_id', 'applies_above', 'applies_upto']))));
        $this->assertSame([2, $escalation, 500000.0, null], array_values(array_intersect_key($banded[1], array_flip(['step_no', 'role_id', 'applies_above', 'applies_upto']))));

        // An escalation naming no role is an authority outside the system.
        $authority = ApprovalPolicy::stepsFor(2000000, $approver, null, 'Board Treasurer');
        $this->assertSame([null, 'Board Treasurer'], [$authority[1]['role_id'], $authority[1]['authority']]);

        // A threshold with nothing above it, and an escalation with no threshold
        // to pass, never bound anybody: both are one step that always engages.
        $this->assertCount(1, ApprovalPolicy::stepsFor(200000, $approver));
        $this->assertCount(1, ApprovalPolicy::stepsFor(0, $approver, null, 'Board minute required'));
        $this->assertNull(ApprovalPolicy::stepsFor(0, $approver)[0]['applies_upto']);
    }

    public function testOnlyOneBandedStepEngagesSoTheEscalationStillSignsInsteadNotAsWell(): void
    {
        $policy = new ApprovalPolicy();

        $this->assertSame(['Finance Manager'], array_column($policy->ladder('journal', self::UNDER), 'role'));
        $this->assertSame(['Executive Director'], array_column($policy->ladder('journal', self::OVER), 'role'));
    }

    public function testARuleWithNoThresholdIsOneStepThatEngagesWhateverTheAmount(): void
    {
        $policy = new ApprovalPolicy();

        // Payroll runs go to the Executive Director whatever they come to.
        $this->assertSame(['Executive Director'], array_column($policy->steps('payroll_run'), 'role'));
        $this->assertCount(1, $policy->ladder('payroll_run', 0.0), 'a nil run still needs its signature');
        $this->assertCount(1, $policy->ladder('payroll_run', 90000000.0));
    }

    public function testTheEscalationRoleMaySignBelowItsBandAndTheApproverMayNotAboveIt(): void
    {
        $policy = new ApprovalPolicy();

        // What the old rule said: up to the threshold either signs; above it, only the escalation.
        $this->assertNull($policy->refusal('journal', self::UNDER, $this->user('w.kamau@elog.or.ke'), 'JV-1'));
        $this->assertNull($policy->refusal('journal', self::UNDER, $this->user('d.kiptoo@elog.or.ke'), 'JV-1'));
        $this->assertNull($policy->refusal('journal', self::OVER, $this->user('d.kiptoo@elog.or.ke'), 'JV-1'));

        $refusal = $policy->refusal('journal', self::OVER, $this->user('w.kamau@elog.or.ke'), 'JV-1');
        $this->assertStringContainsString("above KES 500,000 need the Executive Director's approval", $refusal['message']);
    }

    public function testSomebodyHoldingNoApprovingRoleIsToldWhoSigns(): void
    {
        $refusal = (new ApprovalPolicy())->refusal('journal', self::UNDER, $this->user('j.achieng@elog.or.ke'), 'JV-1');

        $this->assertStringContainsString('are approved by the Finance Manager or the Executive Director', $refusal['message']);
        $this->assertStringContainsString('cannot sign off JV-1', $refusal['message']);
        $this->assertStringNotContainsString('signature 1 of', $refusal['message'], 'a one-step ladder does not count signatures at the reader');
    }

    public function testADocumentTypeWithNoRuleIsHeldToNone(): void
    {
        $this->assertNull((new ApprovalPolicy())->refusal('period_reopen', 10.0, $this->user('j.achieng@elog.or.ke'), 'X'));
    }

    // ------------------------------------------------------------------
    // Climbing a ladder
    // ------------------------------------------------------------------

    public function testOneSignatureFinishesAOneStepLadder(): void
    {
        $policy = new ApprovalPolicy();

        $this->assertTrue($policy->sign('journal', 901, 'JV-901', 'journal', self::UNDER, $this->user('w.kamau@elog.or.ke')));
        $this->assertTrue($policy->progress('journal', 901, 'journal', self::UNDER)['complete']);
    }

    public function testASecondStepLeavesTheDocumentWaitingUntilItsRoleSigns(): void
    {
        $this->ladder('journal', [['Finance Manager', 0, null], ['Executive Director', 0, null]]);
        $policy = new ApprovalPolicy();

        $this->assertFalse(
            $policy->sign('journal', 902, 'JV-902', 'journal', self::UNDER, $this->user('w.kamau@elog.or.ke')),
            'the first signature does not finish a two-step ladder'
        );

        $progress = $policy->progress('journal', 902, 'journal', self::UNDER);
        $this->assertSame([1, 2, false], [$progress['signed'], $progress['of'], $progress['complete']]);
        $this->assertSame('Executive Director', $progress['open']['role']);
        $this->assertSame(' · awaiting the Executive Director', $policy->awaitingNote('journal', 902, 'journal', self::UNDER));

        $this->assertTrue($policy->sign('journal', 902, 'JV-902', 'journal', self::UNDER, $this->user('d.kiptoo@elog.or.ke')));
    }

    public function testTheOpenStepIsTheOneAnApproverIsMeasuredAgainst(): void
    {
        $this->ladder('journal', [['Finance Manager', 0, null], ['Executive Director', 0, null]]);
        $policy = new ApprovalPolicy();
        $policy->sign('journal', 903, 'JV-903', 'journal', self::UNDER, $this->user('w.kamau@elog.or.ke'));

        // The accountant is now told about the step that is open, not the first one.
        $refusal = $policy->refusal('journal', self::UNDER, $this->user('j.achieng@elog.or.ke'), 'JV-903', null, 'journal', 903);
        $this->assertStringContainsString('are approved by the Executive Director', $refusal['message']);
        $this->assertStringContainsString('This is signature 2 of 2.', $refusal['message']);
    }

    public function testNobodySignsTwiceInTheSameRound(): void
    {
        $this->ladder('journal', [['Finance Manager', 0, null], ['Finance Manager', 0, null]]);
        $policy = new ApprovalPolicy();
        $kamau  = $this->user('w.kamau@elog.or.ke');

        $this->assertFalse($policy->sign('journal', 904, 'JV-904', 'journal', self::UNDER, $kamau));

        $refusal = $policy->refusal('journal', self::UNDER, $kamau, 'JV-904', null, 'journal', 904);
        $this->assertStringContainsString('has already signed JV-904', $refusal['message']);

        $this->expectException(RuleViolation::class);
        $policy->sign('journal', 904, 'JV-904', 'journal', self::UNDER, $kamau);
    }

    public function testAQuorumIsSatisfiedOnlyByDifferentPeople(): void
    {
        $this->ladder('journal', [['Finance Manager', 0, null]], 2);
        $this->alsoHolds('m.otieno@elog.or.ke', 'Finance Manager');
        $policy = new ApprovalPolicy();

        $this->assertFalse($policy->sign('journal', 905, 'JV-905', 'journal', self::UNDER, $this->user('w.kamau@elog.or.ke')));
        $this->assertTrue($policy->sign('journal', 905, 'JV-905', 'journal', self::UNDER, $this->user('m.otieno@elog.or.ke')));
    }

    public function testAStepOutsideItsBandIsSkippedRatherThanLeftOutstanding(): void
    {
        // A countersignature that only large entries need.
        $this->ladder('journal', [['Finance Manager', 0, null], ['Executive Director', 500000, null]]);
        $policy = new ApprovalPolicy();

        $this->assertTrue(
            $policy->sign('journal', 906, 'JV-906', 'journal', self::UNDER, $this->user('w.kamau@elog.or.ke')),
            'below the band the second signature is not asked for'
        );
        $this->assertFalse($policy->sign('journal', 907, 'JV-907', 'journal', self::OVER, $this->user('w.kamau@elog.or.ke')));
        $this->assertTrue($policy->sign('journal', 907, 'JV-907', 'journal', self::OVER, $this->user('d.kiptoo@elog.or.ke')));
    }

    // ------------------------------------------------------------------
    // Authorities and returns
    // ------------------------------------------------------------------

    public function testAnAuthorityOutsideTheSystemIsSatisfiedByItsReference(): void
    {
        $policy = new ApprovalPolicy();
        // Payment runs above 2,000,000 go to the Board Treasurer, who is not a user.
        $kiptoo = $this->user('d.kiptoo@elog.or.ke');

        $refusal = $policy->refusal('payment_run', 3000000.0, $kiptoo, 'PR-1');
        $this->assertTrue($refusal['needsAuthority']);
        $this->assertStringContainsString('go to the Board Treasurer', $refusal['message']);
        $this->assertNull($policy->refusal('payment_run', 3000000.0, $kiptoo, 'PR-1', 'BM/2026/08/11'));

        $this->expectException(AuthorityRequired::class);
        $policy->check('payment_run', 3000000.0, $kiptoo, 'PR-1');
    }

    public function testTheReferenceIsKeptOnTheSignatureThatCarriedIt(): void
    {
        $policy = new ApprovalPolicy();
        $policy->sign('payment_run', 908, 'PR-908', 'payment_run', 3000000.0, $this->user('d.kiptoo@elog.or.ke'), 'BM/2026/08/11');

        $this->assertSame('BM/2026/08/11', $policy->signatures('payment_run', 908)[0]['authorityRef']);
    }

    public function testAReturnClosesTheRoundSoTheLadderIsClimbedAgainFromTheTop(): void
    {
        $this->ladder('journal', [['Finance Manager', 0, null], ['Executive Director', 0, null]]);
        $policy = new ApprovalPolicy();
        $kamau  = $this->user('w.kamau@elog.or.ke');

        $policy->sign('journal', 909, 'JV-909', 'journal', self::UNDER, $kamau);
        $policy->returnToPreparer('journal', 909, 'JV-909', 'journal', self::UNDER, $this->user('d.kiptoo@elog.or.ke'), 'Coding wrong');

        $progress = $policy->progress('journal', 909, 'journal', self::UNDER);
        $this->assertSame([2, 0, 'Finance Manager'], [$progress['round'], $progress['signed'], $progress['open']['role']]);

        // The same person signs the new round: it is a different document to them now.
        $this->assertFalse($policy->sign('journal', 909, 'JV-909', 'journal', self::UNDER, $kamau));
        $this->assertSame(2, $policy->progress('journal', 909, 'journal', self::UNDER)['round']);
        $this->assertCount(3, $policy->signatures('journal', 909), 'nothing is deleted when a document goes back');
    }

    // ------------------------------------------------------------------
    // A journal climbing its ladder for real
    // ------------------------------------------------------------------

    public function testAnEntryPostsOnTheLastSignatureAndNotBefore(): void
    {
        $this->ladder('journal', [['Finance Manager', 0, null], ['Executive Director', 0, null]]);
        $repo  = new JournalRepository();
        $entry = $this->pendingEntry();

        $waiting = $repo->approve($entry['ref'], $this->user('w.kamau@elog.or.ke'));
        $this->assertSame('Pending approval', $waiting['status'], 'one signature of two does not post it');
        $this->assertSame([1, 2, 'Executive Director'], [$waiting['approval']['signed'], $waiting['approval']['of'], $waiting['approval']['awaiting']]);
        $this->assertSame('1 of 2 signatures · awaiting the Executive Director', $waiting['approval']['note']);
        $this->assertContains('Approved by W. Kamau · awaiting the Executive Director', array_column($waiting['trail'], 'what'));
        $this->seeInDatabase('journals', ['reference' => $entry['ref'], 'status' => 'pending_approval', 'approved_by' => null]);

        $posted = $repo->approve($entry['ref'], $this->user('d.kiptoo@elog.or.ke'));
        $this->assertSame('Posted', $posted['status']);
        $this->assertNull($posted['approval'], 'a posted entry is waiting on nobody');
        $this->assertContains('Approved and posted by D. Kiptoo', array_column($posted['trail'], 'what'));
    }

    public function testAnEntryOnAOneStepLadderPostsOnTheFirstSignatureAsItAlwaysDid(): void
    {
        $entry  = $this->pendingEntry();
        $posted = (new JournalRepository())->approve($entry['ref'], $this->user('w.kamau@elog.or.ke'));

        $this->assertSame('Posted', $posted['status']);
    }

    public function testNeitherThePreparerNorTheSameApproverSignsASecondStep(): void
    {
        $this->ladder('journal', [['Finance Manager', 0, null], ['Finance Manager', 0, null]]);
        $repo  = new JournalRepository();
        $entry = $this->pendingEntry();
        $kamau = $this->user('w.kamau@elog.or.ke');

        $repo->approve($entry['ref'], $kamau);

        try {
            $repo->approve($entry['ref'], $kamau);
            $this->fail('The same person signed two steps of one round.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('has already signed', $e->getMessage());
        }

        // And the person who wrote it is refused at every step, as before.
        try {
            $repo->approve($entry['ref'], $this->user('j.achieng@elog.or.ke'));
            $this->fail('The preparer signed their own entry.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('cannot also approve it', $e->getMessage());
        }
    }

    public function testAReturnSendsAPartlySignedEntryBackToItsPreparerAndClearsTheRound(): void
    {
        $this->ladder('journal', [['Finance Manager', 0, null], ['Executive Director', 0, null]]);
        $repo  = new JournalRepository();
        $entry = $this->pendingEntry();
        $repo->approve($entry['ref'], $this->user('w.kamau@elog.or.ke'));

        $returned = $repo->reject($entry['ref'], $this->user('d.kiptoo@elog.or.ke'), 'Coding wrong');
        $this->assertSame('Draft', $returned['status'], 'it goes back to the preparer, not back a step');

        // Resubmitted, it needs both signatures again: the signature was given to
        // the entry as it stood, and the preparer has had it back since.
        $id = (int) $this->value('SELECT id FROM journals WHERE reference = ?', $entry['ref']);
        $progress = (new ApprovalPolicy())->progress('journal', $id, 'journal', 1000.0);
        $this->assertSame([2, 0, 'Finance Manager'], [$progress['round'], $progress['signed'], $progress['open']['role']]);
        $this->assertCount(2, (new ApprovalPolicy())->signatures('journal', $id), 'the signature given stays on the record');
    }

    // ------------------------------------------------------------------
    // Every register on the same ladder
    // ------------------------------------------------------------------

    public function testABillIsApprovedAndPostedOnlyOnTheLastSignature(): void
    {
        $this->ladder('bill', [['Finance Manager', 0, null], ['Executive Director', 0, null]]);
        $repo = new PayablesRepository();
        $bill = $this->waiting('bills', 'pending_approval', 'prepared_by');

        $waiting = $repo->approve([$bill['ref']], $this->user('w.kamau@elog.or.ke'))['done'][0];
        $this->assertSame('Awaiting approval', $waiting['status'], 'one signature of two does not post the cost');
        $this->assertSame('1 of 2 signatures · awaiting the Executive Director', $waiting['approval']['note']);
        $this->seeInDatabase('bills', ['reference' => $bill['ref'], 'status' => 'pending_approval', 'approved_by' => null]);

        $approved = $repo->approve([$bill['ref']], $this->user('d.kiptoo@elog.or.ke'))['done'][0];
        $this->assertSame('Approved', $approved['status']);
        $this->assertNotNull($approved['journal'], 'the cost posts on the last signature');
        $this->assertNull($approved['approval'], 'an approved bill is waiting on nobody');
    }

    public function testARejectedBillGoesBackToItsPreparerAndClearsTheRound(): void
    {
        $this->ladder('bill', [['Finance Manager', 0, null], ['Executive Director', 0, null]]);
        $repo = new PayablesRepository();
        $bill = $this->waiting('bills', 'pending_approval', 'prepared_by');
        $repo->approve([$bill['ref']], $this->user('w.kamau@elog.or.ke'));

        $repo->reject($bill['ref'], 'Coded to the wrong programme', $this->user('d.kiptoo@elog.or.ke'));

        $policy   = new ApprovalPolicy();
        $progress = $policy->progress('bill', $bill['id'], 'bill', $bill['amount']);
        $this->assertSame([2, 0, 'Finance Manager'], [$progress['round'], $progress['signed'], $progress['open']['role']]);
        $this->assertCount(2, $policy->signatures('bill', $bill['id']), 'the signature given stays on the record');
    }

    public function testAnAdvanceIsNotPayableUntilItsLadderIsFinished(): void
    {
        $this->ladder('advance', [['Finance Manager', 0, null], ['Executive Director', 0, null]]);
        $repo    = new AdvancesRepository();
        $advance = $this->waiting('advances', 'requested', 'requested_by');

        $repo->approve($advance['ref'], $this->user('w.kamau@elog.or.ke'));
        $this->seeInDatabase('advances', ['reference' => $advance['ref'], 'status' => 'requested', 'approved_by' => null]);

        $repo->approve($advance['ref'], $this->user('d.kiptoo@elog.or.ke'));
        $this->seeInDatabase('advances', ['reference' => $advance['ref'], 'status' => 'approved']);
    }

    public function testARequisitionTakesTheLadderAnAdministratorGivesIt(): void
    {
        // Nothing approves requisitions by a rule until one is written; then they
        // climb it like anything else.
        $this->ladder('requisition', [['Finance Manager', 0, null], ['Executive Director', 0, null]]);
        $repo = new ProcurementRepository();
        $req  = $this->waiting('requisitions', 'pending_approval', 'requested_by');

        $waiting = $repo->approve($req['ref'], $this->user('w.kamau@elog.or.ke'));
        $this->assertSame('Awaiting approval', $waiting['status']);
        $this->assertSame('1 of 2 signatures · awaiting the Executive Director', $waiting['approval']['note']);

        $approved = $repo->approve($req['ref'], $this->user('d.kiptoo@elog.or.ke'));
        $this->assertSame('Approved', $approved['status']);
    }

    public function testAPayrollRunWaitsForEverySignatureBeforeItCanBePosted(): void
    {
        $this->ladder('payroll_run', [['Finance Manager', 0, null], ['Executive Director', 0, null]]);
        $this->signIn('j.achieng@elog.or.ke');
        $this->withBodyFormat('json')->post('api/payroll/submit', ['period' => 'Aug 2026'])->assertOK();

        $this->signIn('w.kamau@elog.or.ke');
        $this->withBodyFormat('json')->post('api/payroll/approve', ['period' => 'Aug 2026'])->assertOK();
        $this->seeInDatabase('payroll_runs', ['status' => 'pending_approval', 'approved_by' => null]);
        $refused = $this->withBodyFormat('json')->post('api/payroll/post', ['period' => 'Aug 2026']);
        $this->assertStringContainsString('Only an approved run can be posted', $refused->getJSON());

        $this->signIn('d.kiptoo@elog.or.ke');
        $this->withBodyFormat('json')->post('api/payroll/approve', ['period' => 'Aug 2026'])->assertOK();
        $this->seeInDatabase('payroll_runs', ['status' => 'approved']);
    }

    public function testAPaymentRunSaysSoRatherThanPayingOutOnTheFirstOfTwoSignatures(): void
    {
        // The money leaves as the run is made, so there is nowhere to hold a run
        // between two signatures. It refuses rather than releasing on the first.
        $this->ladder('payment_run', [['Finance Manager', 0, null], ['Executive Director', 0, null]]);
        $bill = $this->waiting('bills', 'approved', 'prepared_by');

        try {
            (new PayablesRepository())->pay([$bill['ref']], $this->user('w.kamau@elog.or.ke'));
            $this->fail('A payment run was released on the first of two signatures.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('needs 2 signatures', $e->getMessage());
            $this->assertStringContainsString('released in one act', $e->getMessage());
        }
        $this->seeInDatabase('bills', ['reference' => $bill['ref'], 'status' => 'approved']);
    }

    public function testReleasingAPaymentRunOnOneSignatureRecordsItLikeAnyOther(): void
    {
        $bill = $this->waiting('bills', 'approved', 'prepared_by');
        $runs = (new PayablesRepository())->pay([$bill['ref']], $this->user('d.kiptoo@elog.or.ke'))['runs'];

        $this->assertNotEmpty($runs);
        $signature = (new ApprovalPolicy())->signaturesFor('payment_run');
        $this->assertCount(1, $signature, 'the release stands in the record of signatures');
        $this->assertSame('approved', reset($signature)[0]['decision']);
    }

    // ------------------------------------------------------------------
    // Writing a ladder, telling the next role, counting what is yours
    // ------------------------------------------------------------------

    public function testALadderIsWrittenFromSettingsAndBindsAtOnce(): void
    {
        $saved = $this->save(['journal' => [
            ['role' => 'Finance Manager', 'above' => 0, 'upto' => '500,000', 'quorum' => 1],
            ['role' => 'Administrator', 'above' => '500,000', 'upto' => '', 'quorum' => 1],
            ['role' => 'Executive Director', 'above' => '500,000', 'upto' => '', 'quorum' => 1],
        ]]);

        $this->assertContains(
            'Journal entries now take three signatures — the Finance Manager up to 500,000, then the Administrator above 500,000, then the Executive Director above 500,000',
            array_column($saved['changes'] ?? [], 'what')
        );

        Repository::forget();
        $policy = new ApprovalPolicy();
        $this->assertCount(3, $policy->steps('journal'));
        // A value in the upper band engages the two steps banded there, not all three.
        $this->assertSame(['Administrator', 'Executive Director'], array_column($policy->ladder('journal', self::OVER), 'role'));
        $this->assertSame(['Finance Manager'], array_column($policy->ladder('journal', self::UNDER), 'role'));

        // The band header on the same screen says what the ladder says, so the
        // messages that read from it stay true.
        $rule = $policy->rule('journal');
        $this->assertSame([500000.0, 'Finance Manager', 'Administrator'], [$rule['threshold'], $rule['approver'], $rule['escalation']]);
    }

    public function testAnUntouchedLadderIsNotSavedAsAChange(): void
    {
        $current = $this->api('api/settings')['ladders']['journal'];
        $saved   = $this->save(['journal' => array_map(static fn ($s) => [
            'role' => $s['role'], 'above' => $s['above'], 'upto' => $s['upto'] ?? '', 'quorum' => $s['quorum'],
        ], $current)]);

        $this->assertSame([], $saved['changes'] ?? [], 'a ladder read back and sent unchanged is not a change');
    }

    public function testALadderIsCheckedBeforeAnyOfItIsWritten(): void
    {
        $was = (new ApprovalPolicy())->steps('journal');

        foreach ([
            [[['role' => '', 'authority' => '', 'above' => 0, 'upto' => '', 'quorum' => 1]], 'names nobody'],
            [[['role' => '', 'authority' => 'Board Treasurer', 'above' => 0, 'upto' => '', 'quorum' => 1]], 'cannot be the first step'],
            [[['role' => 'Finance Manager', 'above' => '900,000', 'upto' => '400,000', 'quorum' => 1]], 'no band at all'],
            [[['role' => 'Finance Manager', 'above' => 0, 'upto' => '', 'quorum' => 9]], 'Documents would wait for a signature nobody can give'],
            [[['role' => 'Accountant', 'above' => 0, 'upto' => '', 'quorum' => 1]], 'has no approval rights'],
        ] as [$ladder, $expected]) {
            $refused = $this->withBodyFormat('json')->post('api/settings', ['ladders' => ['journal' => $ladder]]);
            $this->assertStringContainsString($expected, $refused->getJSON());
        }

        Repository::forget();
        $this->assertSame($was, (new ApprovalPolicy())->steps('journal'), 'nothing was written by any of them');
    }

    public function testAnEntityThatSetsItsOwnBandsGetsTheLadderWithThem(): void
    {
        // Its own bands are a copy of the head office's. A rule copied without its
        // steps would ask for no signature at all, which is the whole policy gone.
        $this->signIn('w.kamau@elog.or.ke');
        $this->withBodyFormat('json')->post('api/me/entity', ['entity' => 'ELOG-CST'])->assertOK();
        $this->withBodyFormat('json')->post('api/settings', ['approvals' => ['journal' => ['threshold' => '750,000', 'approver' => 'Finance Manager']]])->assertOK();

        $db    = db_connect();
        $coast = (int) $db->table('entities')->select('id')->where('code', 'ELOG-CST')->get()->getRow()->id;
        $rule  = $db->table('approval_rules')->where(['entity_id' => $coast, 'document_type' => 'journal'])->get()->getRowArray();

        $this->assertNotNull($rule, 'the Coast office now holds its own band');
        $this->assertSame(2, (int) $db->table('approval_steps')->where('rule_id', (int) $rule['id'])->countAllResults(),
            'and the ladder came with it');

        Repository::forget();
        $this->assertSame(['Finance Manager', 'Executive Director'], array_column((new ApprovalPolicy())->steps('journal'), 'role'));
        $this->assertSame(750000.0, (new ApprovalPolicy())->steps('journal')[0]['upto'], 'written from the band just set');
    }

    public function testARuleThatNamesNobodyRefusesRatherThanLettingAnyoneSign(): void
    {
        // A rule with no step under it asks for nobody's signature. Read as "no
        // policy" it would let anyone approve anything, so it is read as broken.
        $db = db_connect();
        $db->table('approval_steps')
            ->whereIn('rule_id', static fn ($b) => $b->select('id')->from($db->prefixTable('approval_rules'))->where('document_type', 'journal'))
            ->delete();
        Repository::forget();

        $refusal = (new ApprovalPolicy())->refusal('journal', self::UNDER, $this->user('w.kamau@elog.or.ke'), 'JV-1');
        $this->assertNotNull($refusal, 'a rule naming no signatory does not let everybody through');
        $this->assertStringContainsString('until it names who signs', $refusal['message']);

        $entry = $this->pendingEntry();
        try {
            (new JournalRepository())->approve($entry['ref'], $this->user('w.kamau@elog.or.ke'));
            $this->fail('An entry was approved against a rule that names nobody.');
        } catch (RuleViolation $e) {
            $this->assertStringContainsString('until it names who signs', $e->getMessage());
        }
    }

    public function testTheRoleThatSignsNextIsToldItIsWaitingForThem(): void
    {
        $this->ladder('journal', [['Finance Manager', 0, null], ['Executive Director', 0, null]]);
        $entry = $this->pendingEntry();
        (new JournalRepository())->approve($entry['ref'], $this->user('w.kamau@elog.or.ke'));

        $told = db_connect()->table('notifications')->where('user_id', $this->user('d.kiptoo@elog.or.ke'))
            ->where('kind', 'approval')->get()->getResultArray();

        $this->assertCount(1, $told, 'the Executive Director was told the entry is now theirs to sign');
        $this->assertSame($entry['ref'] . ' is waiting for your approval', $told[0]['title']);
        $this->assertStringContainsString('W. Kamau has signed; yours is signature 2 of 2', $told[0]['body']);
        // Nobody else is told: the signature wanted is not theirs to give.
        $this->assertSame(0, db_connect()->table('notifications')->where('user_id', $this->user('j.achieng@elog.or.ke'))
            ->where('kind', 'approval')->countAllResults());
    }

    public function testARegisterSeparatesWhatIsWaitingFromWhatIsWaitingForYou(): void
    {
        $this->ladder('journal', [['Finance Manager', 0, null], ['Executive Director', 0, null]]);
        $entry = $this->pendingEntry();

        // The Finance Manager signs first, so to begin with the entry is theirs.
        $this->signIn('w.kamau@elog.or.ke');
        $before = $this->api('api/journals')['awaitingMe'];

        $this->signIn('d.kiptoo@elog.or.ke');
        $director = $this->api('api/journals')['awaitingMe'];

        $this->signIn('w.kamau@elog.or.ke');
        (new JournalRepository())->approve($entry['ref'], $this->user('w.kamau@elog.or.ke'));
        Repository::forget();

        // Having signed it, the Finance Manager is waiting on one fewer; the
        // Director, who signs next, is waiting on one more.
        $this->assertGreaterThan(0, $before, 'it was the Finance Manager\'s to sign');
        $this->assertSame($before - 1, $this->api('api/journals')['awaitingMe']);
        $this->signIn('d.kiptoo@elog.or.ke');
        $this->assertSame($director + 1, $this->api('api/journals')['awaitingMe']);

        // And the register says both figures, not just the one.
        $this->assertStringContainsString('awaiting yours', $this->api('api/journals')['footer']);
    }

    // ------------------------------------------------------------------

    /** Saves a ladder through the settings screen and returns what it reports. */
    private function save(array $ladders): array
    {
        $response = $this->withBodyFormat('json')->post('api/settings', ['ladders' => $ladders]);
        $body     = json_decode($response->getJSON(), true) ?? [];
        $this->assertArrayNotHasKey('error', $body, (string) ($body['error'] ?? ''));

        return $body;
    }

    private function api(string $path): array
    {
        return json_decode($this->get($path)->getJSON(), true);
    }

    // ------------------------------------------------------------------

    /**
     * A record the demonstration data leaves waiting, and the value it is signed
     * off for — picked so that neither ladder role prepared it.
     *
     * @return array{id: int, ref: string, amount: float}
     */
    private function waiting(string $table, string $status, string $preparer): array
    {
        $amounts = ['bills' => 'total', 'advances' => 'amount', 'requisitions' => 'estimated_amount'];
        $mine    = [$this->user('w.kamau@elog.or.ke'), $this->user('d.kiptoo@elog.or.ke')];
        $row     = db_connect()->table($table)->where('status', $status)
            ->whereNotIn($preparer, $mine)->orderBy('id')->get(1)->getRowArray();

        $this->assertNotNull($row, 'the demonstration data has no ' . $table . ' waiting to sign');

        return ['id' => (int) $row['id'], 'ref' => $row['reference'], 'amount' => (float) $row[$amounts[$table]]];
    }

    /** An entry of 1,000 awaiting approval, written by someone who cannot approve it. */
    private function pendingEntry(): array
    {
        $line = static fn (string $code, float $dr, float $cr) => [
            'code' => $code, 'desc' => '', 'grantRef' => '', 'fund' => 'General Fund',
            'program' => 'Shared services', 'dr' => $dr, 'cr' => $cr,
        ];

        return (new JournalRepository())->create([
            'date' => '2026-08-20', 'type' => 'Accrual', 'period' => 'Aug 2026', 'status' => 'Pending approval',
            'docLink' => 'auto', 'memo' => '', 'narration' => 'Audit fee accrual',
            'lines' => [$line('5330', 1000, 0), $line('2120', 0, 1000)],
        ], $this->user('j.achieng@elog.or.ke'));
    }

    private function value(string $sql, string $bind): mixed
    {
        $row = db_connect()->query(str_replace('journals', db_connect()->prefixTable('journals'), $sql), [$bind])->getRowArray();

        return $row === null ? null : reset($row);
    }

    /**
     * Replaces a document type's ladder with the steps given, as
     * [role, engages above, up to] — what Settings → Approvals will write.
     */
    private function ladder(string $documentType, array $steps, int $quorum = 1): void
    {
        $db   = db_connect();
        $rule = $db->table('approval_rules')->select('id')->where('document_type', $documentType)->get()->getRow();

        // A type nothing approves by a rule yet — a requisition, an asset disposal —
        // has none until an administrator writes one, which is what this stands for.
        if ($rule === null) {
            $db->table('approval_rules')->insert([
                'entity_id'        => (int) $db->table('entities')->select('id')->orderBy('id')->get(1)->getRow()->id,
                'document_type'    => $documentType,
                'label'            => ucfirst(str_replace('_', ' ', $documentType)),
                'threshold'        => 0,
                'approver_role_id' => (int) $db->table('roles')->select('id')->where('name', 'Finance Manager')->get()->getRow()->id,
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
            $ruleId = (int) $db->insertID();
        } else {
            $ruleId = (int) $rule->id;
        }
        $db->table('approval_steps')->where('rule_id', $ruleId)->delete();

        foreach ($steps as $no => [$role, $above, $upto]) {
            $db->table('approval_steps')->insert([
                'rule_id' => $ruleId, 'step_no' => $no + 1, 'label' => 'Approved', 'quorum' => $quorum,
                'role_id' => $db->table('roles')->select('id')->where('name', $role)->get()->getRow()->id,
                'applies_above' => $above, 'applies_upto' => $upto, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        Repository::forget();
    }

    /** Gives someone a second role, everywhere they already work. */
    private function alsoHolds(string $email, string $role): void
    {
        $db     = db_connect();
        $userId = $this->user($email);
        $roleId = (int) $db->table('roles')->select('id')->where('name', $role)->get()->getRow()->id;

        foreach ($db->table('user_entity_roles')->select('entity_id')->where('user_id', $userId)->get()->getResultArray() as $held) {
            $db->table('user_entity_roles')->ignore(true)->insert([
                'user_id' => $userId, 'entity_id' => (int) $held['entity_id'], 'role_id' => $roleId, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        Repository::forget();
    }

    private function user(string $email): int
    {
        return (new Lookups())->userId($email);
    }
}
