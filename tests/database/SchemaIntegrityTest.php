<?php

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The README's accounting integrity rules, enforced by the schema itself.
 *
 * Every refusal here comes from the database (a CHECK constraint or a trigger),
 * not from application code, so it holds for any client that writes to it.
 * Runs on the test group's SQLite by default; point database.tests.* at MySQL
 * to run the same assertions against the production dialect.
 *
 * @internal
 */
final class SchemaIntegrityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    // Rebuilt for every test: rows written here are, by design, impossible to
    // clean up (posted journals and audit events cannot be deleted), and
    // CodeIgniter suppresses query errors inside a wrapping transaction.
    protected $namespace = 'App';
    protected $refresh   = true;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLedgerBasics();
    }

    // ---- Posting rules ----

    public function testABalancedJournalPostsToAnOpenPeriod(): void
    {
        $journal = $this->journal([[$this->ids['bank'], 1000, 0], [$this->ids['general_fund_account'], 0, 1000]]);

        $this->post($journal);

        $this->seeInDatabase('journals', ['id' => $journal, 'status' => 'posted']);
    }

    public function testAnUnbalancedJournalIsRefused(): void
    {
        $journal = $this->journal([[$this->ids['bank'], 1000, 0], [$this->ids['general_fund_account'], 0, 999.99]]);

        $this->assertRefused(fn () => $this->post($journal), 'does not balance');
    }

    public function testASingleLineJournalIsRefused(): void
    {
        $journal = $this->journal([[$this->ids['bank'], 1000, 0]]);

        $this->assertRefused(fn () => $this->post($journal), 'does not balance');
    }

    public function testAJournalCannotBeCreatedAlreadyPosted(): void
    {
        $this->assertRefused(fn () => $this->db->table('journals')->insert(
            $this->journalRow(['status' => 'posted', 'posted_at' => '2026-08-31 10:00:00'])
        ), 'created as a draft');
    }

    public function testPostingToAClosedPeriodIsRefused(): void
    {
        $journal = $this->journal(
            [[$this->ids['bank'], 1000, 0], [$this->ids['general_fund_account'], 0, 1000]],
            ['period_id' => $this->ids['july'], 'journal_date' => '2026-07-31']
        );

        $this->assertRefused(fn () => $this->post($journal), 'closed');
    }

    public function testBackDatingOutsideThePeriodIsRefused(): void
    {
        $journal = $this->journal(
            [[$this->ids['bank'], 1000, 0], [$this->ids['general_fund_account'], 0, 1000]],
            ['journal_date' => '2026-07-15']
        );

        $this->assertRefused(fn () => $this->post($journal), 'outside its period');
    }

    public function testHeadingAndArchivedAccountsCannotBePosted(): void
    {
        $toHeading = $this->journal([[$this->ids['assets_heading'], 500, 0], [$this->ids['general_fund_account'], 0, 500]]);
        $this->assertRefused(fn () => $this->post($toHeading), 'active leaf accounts');

        $toArchived = $this->journal([[$this->ids['archived'], 500, 0], [$this->ids['general_fund_account'], 0, 500]], ['reference' => 'JV-T-2']);
        $this->assertRefused(fn () => $this->post($toArchived), 'active leaf accounts');
    }

    public function testARestrictedFundCannotBeSpentBelowZero(): void
    {
        $income = $this->journal([
            [$this->ids['bank'], 5000, 0, $this->ids['grant_fund']],
            [$this->ids['grant_income'], 0, 5000, $this->ids['grant_fund']],
        ]);
        $this->post($income);

        $withinFunds = $this->journal([
            [$this->ids['programme_costs'], 4000, 0, $this->ids['grant_fund']],
            [$this->ids['bank'], 0, 4000, $this->ids['grant_fund']],
        ], ['reference' => 'JV-T-2']);
        $this->post($withinFunds);

        $overspend = $this->journal([
            [$this->ids['programme_costs'], 1000.01, 0, $this->ids['grant_fund']],
            [$this->ids['bank'], 0, 1000.01, $this->ids['grant_fund']],
        ], ['reference' => 'JV-T-3']);
        $this->assertRefused(fn () => $this->post($overspend), 'restricted fund below zero');
    }

    public function testAnUnrestrictedFundMayGoNegative(): void
    {
        $journal = $this->journal([[$this->ids['programme_costs'], 700, 0], [$this->ids['bank'], 0, 700]]);

        $this->post($journal);

        $this->seeInDatabase('journals', ['id' => $journal, 'status' => 'posted']);
    }

    // ---- Posted is immutable ----

    public function testAPostedJournalCannotBeEditedOrDeleted(): void
    {
        $journal = $this->postedJournal();

        $this->assertRefused(fn () => $this->db->table('journals')->where('id', $journal)->update(['narration' => 'Changed']), 'cannot be changed');
        $this->assertRefused(fn () => $this->db->table('journals')->where('id', $journal)->update(['status' => 'draft']), 'cannot be changed');
        $this->assertRefused(fn () => $this->db->table('journals')->where('id', $journal)->delete(), 'cannot be changed');
    }

    public function testAPostedJournalCanOnlyBeMarkedReversed(): void
    {
        $journal = $this->postedJournal();

        $this->db->table('journals')->where('id', $journal)->update(['status' => 'reversed']);

        $this->seeInDatabase('journals', ['id' => $journal, 'status' => 'reversed']);
        $this->assertRefused(fn () => $this->db->table('journals')->where('id', $journal)->update(['status' => 'posted']), 'cannot be changed');
    }

    public function testLinesOfAPostedJournalCannotChange(): void
    {
        $journal = $this->postedJournal();
        $line    = $this->db->table('journal_lines')->where('journal_id', $journal)->get()->getFirstRow();

        $this->assertRefused(fn () => $this->db->table('journal_lines')->insert($this->lineRow($journal, 3, $this->ids['bank'], 1, 0)), 'draft or rejected');
        $this->assertRefused(fn () => $this->db->table('journal_lines')->where('id', $line->id)->update(['debit' => 2]), 'draft or rejected');
        $this->assertRefused(fn () => $this->db->table('journal_lines')->where('id', $line->id)->delete(), 'draft or rejected');
    }

    public function testADraftJournalAndItsLinesCanStillBeEdited(): void
    {
        $journal = $this->journal([[$this->ids['bank'], 1000, 0], [$this->ids['general_fund_account'], 0, 1000]]);

        $this->db->table('journals')->where('id', $journal)->update(['narration' => 'Corrected before posting']);
        $this->db->table('journal_lines')->where('journal_id', $journal)->where('line_no', 1)->update(['description' => 'Edited']);

        $this->seeInDatabase('journal_lines', ['journal_id' => $journal, 'line_no' => 1, 'description' => 'Edited']);
    }

    // ---- Row-level constraints ----

    public function testThePreparerCannotApproveTheirOwnJournal(): void
    {
        $this->assertRefused(fn () => $this->db->table('journals')->insert(
            $this->journalRow(['approved_by' => $this->ids['preparer']])
        ), 'journals_sod_check');
    }

    public function testAJournalLineIsEitherADebitOrACredit(): void
    {
        $journal = $this->journal([]);

        foreach ([[0, 0], [100, 100], [-100, 0]] as $i => [$debit, $credit]) {
            $this->assertRefused(
                fn () => $this->db->table('journal_lines')->insert($this->lineRow($journal, $i + 1, $this->ids['bank'], $debit, $credit)),
                'journal_lines_one_side_check'
            );
        }
    }

    public function testAJournalLineMustCarryFundAndProgramme(): void
    {
        $journal = $this->journal([]);
        $row     = $this->lineRow($journal, 1, $this->ids['bank'], 100, 0);
        $row['programme_id'] = null;

        $this->expectException(DatabaseException::class);
        $this->db->table('journal_lines')->insert($row);
    }

    public function testAVerificationExceptionNeedsANote(): void
    {
        $round = $this->insert('verification_rounds', ['entity_id' => $this->ids['entity'], 'reference' => 'AV-T', 'name' => 'Test count',
            'opened_on' => '2026-08-28', 'status' => 'counting', 'opened_by' => $this->ids['preparer']]);
        $class = $this->insert('asset_classes', ['name' => 'IT equipment', 'tag_prefix' => 'IT', 'useful_life_years' => 3,
            'cost_account_id' => $this->ids['bank'], 'accumulated_depreciation_account_id' => $this->ids['bank'],
            'depreciation_expense_account_id' => $this->ids['programme_costs']]);
        $asset = $this->insert('assets', ['entity_id' => $this->ids['entity'], 'tag' => 'ELOG/IT/0055', 'description' => 'Laptop',
            'asset_class_id' => $class, 'acquired_on' => '2025-01-10', 'cost' => 150000, 'useful_life_years' => 3,
            'fund_id' => $this->ids['general_fund']]);

        $this->assertRefused(fn () => $this->db->table('verification_results')->insert([
            'verification_round_id' => $round, 'asset_id' => $asset, 'result' => 'not_found', 'note' => '',
            'counted_by' => $this->ids['preparer'], 'counted_at' => '2026-09-02 10:00:00',
        ]), 'verification_results_note_check');
    }

    // ---- Audit ----

    public function testTheAuditTrailIsAppendOnly(): void
    {
        $event = $this->insert('audit_events', ['entity_id' => $this->ids['entity'], 'occurred_at' => '2026-08-26 09:41:00',
            'actor_user_id' => $this->ids['preparer'], 'action' => 'setting.changed', 'object_type' => 'approval_rule', 'object_id' => 1]);

        $this->assertRefused(fn () => $this->db->table('audit_events')->where('id', $event)->update(['action' => 'edited']), 'append-only');
        $this->assertRefused(fn () => $this->db->table('audit_events')->where('id', $event)->delete(), 'append-only');
    }

    // ---- Views ----

    public function testBudgetAvailabilityDeductsActualsAndOpenCommitments(): void
    {
        $this->post($this->journal([[$this->ids['programme_costs'], 30000, 0], [$this->ids['bank'], 0, 30000]]));

        $version = $this->insert('budget_versions', ['entity_id' => $this->ids['entity'], 'fiscal_year_id' => $this->ids['fy'],
            'name' => 'Original', 'status' => 'approved', 'prepared_by' => $this->ids['preparer'], 'approved_by' => $this->ids['approver']]);
        $line = $this->insert('budget_lines', ['budget_version_id' => $version, 'account_id' => $this->ids['programme_costs'],
            'fund_id' => $this->ids['general_fund'], 'programme_id' => $this->ids['programme'], 'cost_group' => 'Programme costs',
            'annual_amount' => 100000]);

        $supplier    = $this->insert('suppliers', ['name' => 'Copy Cat Group', 'category' => 'IT equipment', 'status' => 'prequalified']);
        $requisition = $this->insert('requisitions', ['entity_id' => $this->ids['entity'], 'reference' => 'REQ-T', 'title' => 'Laptops',
            'requested_by' => $this->ids['preparer'], 'programme_id' => $this->ids['programme'], 'fund_id' => $this->ids['general_fund'],
            'account_id' => $this->ids['programme_costs'], 'raised_on' => '2026-08-20', 'estimated_amount' => 25000, 'status' => 'po_raised']);
        $po = $this->insert('purchase_orders', ['entity_id' => $this->ids['entity'], 'reference' => 'PO-T', 'requisition_id' => $requisition,
            'supplier_id' => $supplier, 'issued_on' => '2026-08-22', 'amount' => 25000, 'status' => 'open', 'prepared_by' => $this->ids['preparer']]);
        $poLine = $this->insert('purchase_order_lines', ['purchase_order_id' => $po, 'line_no' => 1, 'account_id' => $this->ids['programme_costs'],
            'fund_id' => $this->ids['general_fund'], 'programme_id' => $this->ids['programme'], 'description' => 'Laptops',
            'quantity' => 1, 'unit_cost' => 25000, 'amount' => 25000]);

        // A bill approved against part of the order moves that part from committed to billed.
        $bill = $this->insert('bills', ['entity_id' => $this->ids['entity'], 'reference' => 'BILL-T', 'supplier_id' => $supplier,
            'invoice_date' => '2026-08-25', 'due_date' => '2026-09-24', 'subtotal' => 10000, 'total' => 10000, 'status' => 'approved',
            'purchase_order_id' => $po, 'prepared_by' => $this->ids['preparer'], 'approved_by' => $this->ids['approver']]);
        $this->insert('bill_lines', ['bill_id' => $bill, 'line_no' => 1, 'account_id' => $this->ids['programme_costs'],
            'fund_id' => $this->ids['general_fund'], 'programme_id' => $this->ids['programme'], 'purchase_order_line_id' => $poLine,
            'description' => 'First delivery', 'amount' => 10000]);

        $row = $this->db->table('v_budget_availability')->where('budget_line_id', $line)->get()->getRowArray();

        $this->assertEquals(100000, (float) $row['budget']);
        $this->assertEquals(30000, (float) $row['actual']);
        $this->assertEquals(15000, (float) $row['committed']);
        $this->assertEquals(55000, (float) $row['available']);
    }

    public function testFundBalancesReportNetAssetsPerFund(): void
    {
        $this->post($this->journal([
            [$this->ids['bank'], 5000, 0, $this->ids['grant_fund']],
            [$this->ids['grant_income'], 0, 5000, $this->ids['grant_fund']],
        ]));
        $this->post($this->journal([
            [$this->ids['programme_costs'], 1200, 0, $this->ids['grant_fund']],
            [$this->ids['bank'], 0, 1200, $this->ids['grant_fund']],
        ], ['reference' => 'JV-T-2']));

        $row = $this->db->table('v_fund_balances')->where('fund_id', $this->ids['grant_fund'])->get()->getRowArray();

        $this->assertEquals(5000, (float) $row['income']);
        $this->assertEquals(1200, (float) $row['expenditure']);
        $this->assertEquals(3800, (float) $row['balance']);
    }

    // ---- Fixtures ----

    private function seedLedgerBasics(): void
    {
        $this->ids['entity']   = $this->insert('entities', ['code' => 'ELOG-NS', 'name' => 'ELOG National Secretariat', 'type' => 'Head office']);
        $this->ids['preparer'] = $this->insert('users', ['email' => 'j.achieng@elog.or.ke', 'name' => 'Joyce Achieng', 'short_name' => 'J. Achieng', 'initials' => 'JA', 'status' => 'active']);
        $this->ids['approver'] = $this->insert('users', ['email' => 'w.kamau@elog.or.ke', 'name' => 'Wanjiru Kamau', 'short_name' => 'W. Kamau', 'initials' => 'WK', 'status' => 'active']);

        $this->ids['fy']     = $this->insert('fiscal_years', ['entity_id' => $this->ids['entity'], 'code' => 'FY2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $this->ids['july']   = $this->insert('periods', ['entity_id' => $this->ids['entity'], 'fiscal_year_id' => $this->ids['fy'], 'code' => '2026-07', 'name' => 'Jul 2026',
            'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'status' => 'closed', 'closed_by' => $this->ids['approver'], 'closed_at' => '2026-08-05 17:00:00']);
        $this->ids['august'] = $this->insert('periods', ['entity_id' => $this->ids['entity'], 'fiscal_year_id' => $this->ids['fy'], 'code' => '2026-08', 'name' => 'Aug 2026',
            'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31']);

        $this->ids['general_fund'] = $this->insert('funds', ['code' => 'FND-100', 'name' => 'General Fund', 'restriction' => 'unrestricted', 'ledger_group' => 'general']);
        $this->ids['grant_fund']   = $this->insert('funds', ['code' => 'FND-210', 'name' => 'USAID / Uraia Election Observation Fund', 'restriction' => 'restricted', 'ledger_group' => 'grant']);
        $this->ids['programme']    = $this->insert('programmes', ['code' => 'PRG-10', 'name' => 'Election Observation']);

        $this->ids['assets_heading']       = $this->insert('accounts', ['code' => '1000', 'name' => 'Assets', 'type' => 'asset', 'level' => 0, 'is_leaf' => 0]);
        $this->ids['bank']                 = $this->insert('accounts', ['code' => '1110', 'name' => 'Bank — KCB Current (KES)', 'type' => 'asset', 'level' => 2, 'parent_id' => $this->ids['assets_heading']]);
        $this->ids['archived']             = $this->insert('accounts', ['code' => '1395', 'name' => 'Old suspense', 'type' => 'asset', 'level' => 2, 'parent_id' => $this->ids['assets_heading'], 'status' => 'archived']);
        $this->ids['general_fund_account'] = $this->insert('accounts', ['code' => '3100', 'name' => 'General fund', 'type' => 'equity', 'level' => 1]);
        $this->ids['grant_income']         = $this->insert('accounts', ['code' => '4110', 'name' => 'Grant income', 'type' => 'income', 'level' => 1]);
        $this->ids['programme_costs']      = $this->insert('accounts', ['code' => '5110', 'name' => 'Programme costs', 'type' => 'expense', 'level' => 1]);
    }

    private function insert(string $table, array $row): int
    {
        $this->db->table($table)->insert($row);

        return (int) $this->db->insertID();
    }

    private function journalRow(array $overrides = []): array
    {
        return $overrides + [
            'entity_id'    => $this->ids['entity'],
            'period_id'    => $this->ids['august'],
            'reference'    => 'JV-T-1',
            'journal_date' => '2026-08-31',
            'narration'    => 'Test entry',
            'prepared_by'  => $this->ids['preparer'],
        ];
    }

    private function lineRow(int $journal, int $no, int $account, float $debit, float $credit, ?int $fund = null): array
    {
        return [
            'journal_id'   => $journal,
            'line_no'      => $no,
            'account_id'   => $account,
            'fund_id'      => $fund ?? $this->ids['general_fund'],
            'programme_id' => $this->ids['programme'],
            'description'  => 'Line ' . $no,
            'debit'        => $debit,
            'credit'       => $credit,
        ];
    }

    /** @param list<array{0: int, 1: float, 2: float, 3?: int}> $lines [account, debit, credit, fund] */
    private function journal(array $lines, array $overrides = []): int
    {
        $journal = $this->insert('journals', $this->journalRow($overrides));

        foreach ($lines as $i => $line) {
            $this->db->table('journal_lines')->insert($this->lineRow($journal, $i + 1, $line[0], $line[1], $line[2], $line[3] ?? null));
        }

        return $journal;
    }

    private function post(int $journal): void
    {
        $this->db->table('journals')->where('id', $journal)->update([
            'status' => 'posted', 'approved_by' => $this->ids['approver'], 'posted_at' => '2026-08-31 16:00:00',
        ]);
    }

    private function postedJournal(): int
    {
        $journal = $this->journal([[$this->ids['bank'], 1000, 0], [$this->ids['general_fund_account'], 0, 1000]]);
        $this->post($journal);

        return $journal;
    }

    private function assertRefused(callable $write, string $expectedMessage): void
    {
        try {
            $write();
        } catch (DatabaseException $e) {
            $this->assertStringContainsStringIgnoringCase($expectedMessage, $e->getMessage());

            return;
        }

        $this->fail('The database accepted a write it should refuse (expected: ' . $expectedMessage . ').');
    }
}
