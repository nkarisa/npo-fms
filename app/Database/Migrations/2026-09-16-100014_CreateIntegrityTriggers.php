<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;
use RuntimeException;

/**
 * The accounting rules the README requires "at the database and service layer,
 * not just the UI". Constraints that fit on one row (one-sided lines, preparer ≠
 * approver, three-dimensional coding) are CHECKs and NOT NULLs on the tables; the
 * rules that need other rows are triggers here:
 *
 * - A journal is created as a draft; it can only become posted if it balances
 *   (rule 1), its period is open and contains the journal date (rule 5), every
 *   line is on an active leaf account, and no restricted or endowment fund it
 *   draws on ends below zero (rule 8).
 * - A posted journal is immutable (rule 4). The only change allowed is marking it
 *   reversed once a reversal has been posted; its lines never change.
 * - The audit trail and the personal-data access log are append-only (rule 10).
 *
 * Written for MySQL 8 and SQLite; other drivers are refused rather than migrated
 * without the rules.
 */
class CreateIntegrityTriggers extends SchemaMigration
{
    private const MSG_CREATED_POSTED = 'A journal is created as a draft and posted once its lines are in place.';
    private const MSG_IMMUTABLE      = 'A posted journal cannot be changed or deleted. Correct it with a reversal.';
    private const MSG_PERIOD_CLOSED  = 'The period is closed or belongs to another entity. Posting is locked.';
    private const MSG_DATE_OUTSIDE   = 'The journal date falls outside its period.';
    private const MSG_UNBALANCED     = 'The journal does not balance. Debits must equal credits, with at least two lines.';
    private const MSG_ACCOUNT        = 'Only active leaf accounts can be posted to.';
    private const MSG_RESTRICTED     = 'This posting would take a restricted fund below zero.';
    private const MSG_LINES_LOCKED   = 'Journal lines can only change while the journal is a draft or rejected.';
    private const MSG_APPEND_ONLY    = 'This log is append-only. Entries cannot be changed or deleted.';

    /** Columns of a posted journal that must never change. */
    private const FROZEN_COLUMNS = ['entity_id', 'period_id', 'reference', 'journal_date', 'type', 'document_type_id',
        'document_ref', 'source_type', 'source_id', 'memo', 'narration', 'prepared_by', 'approved_by', 'approved_at',
        'posted_at', 'reverses_journal_id'];

    private const TRIGGERS = [
        'journals'                 => ['insert_guard', 'update_guard', 'delete_guard'],
        'journal_lines'            => ['insert_guard', 'update_guard', 'delete_guard'],
        'audit_events'             => ['update_guard', 'delete_guard'],
        'personal_data_access_log' => ['update_guard', 'delete_guard'],
    ];

    public function up(): void
    {
        $statements = match ($this->db->DBDriver) {
            'MySQLi'  => $this->mysql(),
            'SQLite3' => $this->sqlite(),
            default   => throw new RuntimeException('The integrity triggers are written for MySQL and SQLite, not ' . $this->db->DBDriver . '.'),
        };

        foreach ($statements as $sql) {
            $this->db->query($this->sql($sql));
        }
    }

    public function down(): void
    {
        foreach (self::TRIGGERS as $table => $triggers) {
            foreach ($triggers as $trigger) {
                $this->db->query($this->sql("DROP TRIGGER IF EXISTS {{$table}}_{$trigger}"));
            }
        }
    }

    // ------------------------------------------------------------------
    // Conditions shared by both dialects (NEW refers to the journal row)
    // ------------------------------------------------------------------

    private function periodNotOpen(): string
    {
        return "NOT EXISTS (SELECT 1 FROM {periods} p WHERE p.id = NEW.period_id AND p.entity_id = NEW.entity_id AND p.status = 'open')";
    }

    private function dateOutsidePeriod(): string
    {
        return 'EXISTS (SELECT 1 FROM {periods} p WHERE p.id = NEW.period_id AND (NEW.journal_date < p.starts_on OR NEW.journal_date > p.ends_on))';
    }

    private function unbalanced(): string
    {
        return '(SELECT COUNT(*) < 2 OR ROUND(COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0), 2) <> 0 OR COALESCE(SUM(l.debit), 0) = 0'
            . ' FROM {journal_lines} l WHERE l.journal_id = NEW.id)';
    }

    private function unpostableAccount(): string
    {
        return 'EXISTS (SELECT 1 FROM {journal_lines} l JOIN {accounts} a ON a.id = l.account_id'
            . " WHERE l.journal_id = NEW.id AND (a.is_leaf = 0 OR a.status <> 'active'))";
    }

    /**
     * A fund's balance is its net assets: credits less debits on fund, income and
     * expenditure accounts. Only funds this journal reduces are tested, so a fund
     * already overdrawn (e.g. by migrated history) can still be topped up.
     */
    private function restrictedFundOverdrawn(): string
    {
        return 'EXISTS (SELECT 1'
            . ' FROM {journal_lines} l'
            . ' JOIN {journals} j ON j.id = l.journal_id'
            . ' JOIN {accounts} a ON a.id = l.account_id'
            . ' JOIN {funds} f ON f.id = l.fund_id'
            . " WHERE f.restriction IN ('restricted', 'endowment')"
            . "   AND a.type IN ('equity', 'income', 'expense')"
            . '   AND j.entity_id = NEW.entity_id'
            . "   AND (j.status IN ('posted', 'reversed') OR j.id = NEW.id)"
            . '   AND l.fund_id IN (SELECT l2.fund_id FROM {journal_lines} l2 WHERE l2.journal_id = NEW.id)'
            . ' GROUP BY l.fund_id'
            . ' HAVING ROUND(SUM(l.credit - l.debit), 2) < 0'
            . '    AND SUM(CASE WHEN j.id = NEW.id THEN l.credit - l.debit ELSE 0 END) < 0)';
    }

    /** True when any frozen column differs between OLD and NEW. */
    private function frozenColumnsChanged(string $nullSafeEquals): string
    {
        $same = array_map(static fn ($c) => "NEW.{$c} {$nullSafeEquals} OLD.{$c}", self::FROZEN_COLUMNS);

        return 'NOT (' . implode(' AND ', $same) . ')';
    }

    /** True when the update is anything other than marking a posted journal reversed. */
    private function illegalChangeToPosted(string $nullSafeEquals): string
    {
        return "OLD.status IN ('posted', 'reversed')"
            . " AND (NOT (OLD.status = 'posted' AND NEW.status = 'reversed') OR " . $this->frozenColumnsChanged($nullSafeEquals) . ')';
    }

    private function linesLocked(string $journalId): string
    {
        return "EXISTS (SELECT 1 FROM {journals} j WHERE j.id = {$journalId} AND j.status NOT IN ('draft', 'rejected'))";
    }

    // ------------------------------------------------------------------
    // MySQL
    // ------------------------------------------------------------------

    private function mysql(): array
    {
        $fail = static fn (string $message) => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';";
        $guard = static fn (string $condition, string $message) => "IF {$condition} THEN {$fail($message)} END IF;";

        $appendOnly = [];
        foreach (['audit_events', 'personal_data_access_log'] as $table) {
            foreach (['UPDATE' => 'update_guard', 'DELETE' => 'delete_guard'] as $event => $name) {
                $appendOnly[] = "CREATE TRIGGER {{$table}}_{$name} BEFORE {$event} ON {{$table}} FOR EACH ROW BEGIN "
                    . $fail(self::MSG_APPEND_ONLY) . ' END';
            }
        }

        return [
            'CREATE TRIGGER {journals}_insert_guard BEFORE INSERT ON {journals} FOR EACH ROW BEGIN '
                . $guard("NEW.status IN ('posted', 'reversed')", self::MSG_CREATED_POSTED)
                . ' END',

            'CREATE TRIGGER {journals}_update_guard BEFORE UPDATE ON {journals} FOR EACH ROW BEGIN '
                . $guard($this->illegalChangeToPosted('<=>'), self::MSG_IMMUTABLE)
                . " IF NEW.status = 'posted' AND OLD.status <> 'posted' THEN "
                . $guard($this->periodNotOpen(), self::MSG_PERIOD_CLOSED)
                . $guard($this->dateOutsidePeriod(), self::MSG_DATE_OUTSIDE)
                . $guard($this->unbalanced(), self::MSG_UNBALANCED)
                . $guard($this->unpostableAccount(), self::MSG_ACCOUNT)
                . $guard($this->restrictedFundOverdrawn(), self::MSG_RESTRICTED)
                . ' END IF; END',

            'CREATE TRIGGER {journals}_delete_guard BEFORE DELETE ON {journals} FOR EACH ROW BEGIN '
                . $guard("OLD.status IN ('posted', 'reversed')", self::MSG_IMMUTABLE)
                . ' END',

            // Cascaded deletes do not fire MySQL triggers, but a posted journal
            // cannot be deleted, so only draft lines are ever removed that way.
            'CREATE TRIGGER {journal_lines}_insert_guard BEFORE INSERT ON {journal_lines} FOR EACH ROW BEGIN '
                . $guard($this->linesLocked('NEW.journal_id'), self::MSG_LINES_LOCKED)
                . ' END',

            'CREATE TRIGGER {journal_lines}_update_guard BEFORE UPDATE ON {journal_lines} FOR EACH ROW BEGIN '
                . $guard($this->linesLocked('OLD.journal_id') . ' OR ' . $this->linesLocked('NEW.journal_id'), self::MSG_LINES_LOCKED)
                . ' END',

            'CREATE TRIGGER {journal_lines}_delete_guard BEFORE DELETE ON {journal_lines} FOR EACH ROW BEGIN '
                . $guard($this->linesLocked('OLD.journal_id'), self::MSG_LINES_LOCKED)
                . ' END',

            ...$appendOnly,
        ];
    }

    // ------------------------------------------------------------------
    // SQLite
    // ------------------------------------------------------------------

    private function sqlite(): array
    {
        $raise = static fn (string $condition, string $message) => "SELECT RAISE(ABORT, '{$message}') WHERE {$condition};";

        $appendOnly = [];
        foreach (['audit_events', 'personal_data_access_log'] as $table) {
            foreach (['UPDATE' => 'update_guard', 'DELETE' => 'delete_guard'] as $event => $name) {
                $appendOnly[] = "CREATE TRIGGER {{$table}}_{$name} BEFORE {$event} ON {{$table}} BEGIN "
                    . "SELECT RAISE(ABORT, '" . self::MSG_APPEND_ONLY . "'); END";
            }
        }

        return [
            "CREATE TRIGGER {journals}_insert_guard BEFORE INSERT ON {journals} WHEN NEW.status IN ('posted', 'reversed') BEGIN "
                . "SELECT RAISE(ABORT, '" . self::MSG_CREATED_POSTED . "'); END",

            // Checks run in order, so the first failing rule is the one reported.
            'CREATE TRIGGER {journals}_update_guard BEFORE UPDATE ON {journals} BEGIN '
                . $raise($this->illegalChangeToPosted('IS'), self::MSG_IMMUTABLE)
                . $raise("NEW.status = 'posted' AND OLD.status <> 'posted' AND " . $this->periodNotOpen(), self::MSG_PERIOD_CLOSED)
                . $raise("NEW.status = 'posted' AND OLD.status <> 'posted' AND " . $this->dateOutsidePeriod(), self::MSG_DATE_OUTSIDE)
                . $raise("NEW.status = 'posted' AND OLD.status <> 'posted' AND " . $this->unbalanced(), self::MSG_UNBALANCED)
                . $raise("NEW.status = 'posted' AND OLD.status <> 'posted' AND " . $this->unpostableAccount(), self::MSG_ACCOUNT)
                . $raise("NEW.status = 'posted' AND OLD.status <> 'posted' AND " . $this->restrictedFundOverdrawn(), self::MSG_RESTRICTED)
                . ' END',

            "CREATE TRIGGER {journals}_delete_guard BEFORE DELETE ON {journals} WHEN OLD.status IN ('posted', 'reversed') BEGIN "
                . "SELECT RAISE(ABORT, '" . self::MSG_IMMUTABLE . "'); END",

            'CREATE TRIGGER {journal_lines}_insert_guard BEFORE INSERT ON {journal_lines} BEGIN '
                . $raise($this->linesLocked('NEW.journal_id'), self::MSG_LINES_LOCKED) . ' END',

            'CREATE TRIGGER {journal_lines}_update_guard BEFORE UPDATE ON {journal_lines} BEGIN '
                . $raise($this->linesLocked('OLD.journal_id') . ' OR ' . $this->linesLocked('NEW.journal_id'), self::MSG_LINES_LOCKED) . ' END',

            'CREATE TRIGGER {journal_lines}_delete_guard BEFORE DELETE ON {journal_lines} BEGIN '
                . $raise($this->linesLocked('OLD.journal_id'), self::MSG_LINES_LOCKED) . ' END',

            ...$appendOnly,
        ];
    }
}
