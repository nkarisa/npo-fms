<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Views over the posted ledger for the two figures most screens need.
 *
 * - v_fund_balances: income, expenditure and balance per fund (the restricted-fund
 *   test in CreateIntegrityTriggers uses the same definition).
 * - v_budget_availability: available = budget − actual − committed (rule 9), where
 *   committed is the unbilled value of open purchase order lines issued in the year.
 *
 * Posted and reversed journals both count: a reversal is its own posted journal
 * that nets the original to nil.
 */
class CreateReportingViews extends SchemaMigration
{
    public function up(): void
    {
        $sameGrant = fn (string $a, string $b) => $this->isMySQL() ? "{$a} <=> {$b}" : "{$a} IS {$b}";

        $this->db->query($this->sql(
            'CREATE VIEW {v_fund_balances} AS'
            . ' SELECT j.entity_id, l.fund_id, f.code AS fund_code, f.name AS fund_name, f.restriction,'
            . "   SUM(CASE WHEN a.type = 'income' THEN l.credit - l.debit ELSE 0 END) AS income,"
            . "   SUM(CASE WHEN a.type = 'expense' THEN l.debit - l.credit ELSE 0 END) AS expenditure,"
            . "   SUM(CASE WHEN a.type = 'equity' THEN l.credit - l.debit ELSE 0 END) AS transfers_and_opening,"
            . "   SUM(CASE WHEN a.type IN ('equity', 'income', 'expense') THEN l.credit - l.debit ELSE 0 END) AS balance"
            . ' FROM {journal_lines} l'
            . ' JOIN {journals} j ON j.id = l.journal_id'
            . ' JOIN {accounts} a ON a.id = l.account_id'
            . ' JOIN {funds} f ON f.id = l.fund_id'
            . " WHERE j.status IN ('posted', 'reversed')"
            . ' GROUP BY j.entity_id, l.fund_id, f.code, f.name, f.restriction'
        ));

        $actual = 'SELECT SUM(l.debit - l.credit)'
            . ' FROM {journal_lines} l'
            . ' JOIN {journals} j ON j.id = l.journal_id'
            . ' JOIN {periods} p ON p.id = j.period_id'
            . " WHERE j.status IN ('posted', 'reversed')"
            . '   AND j.entity_id = bv.entity_id AND p.fiscal_year_id = bv.fiscal_year_id'
            . '   AND l.account_id = bl.account_id AND l.fund_id = bl.fund_id AND l.programme_id = bl.programme_id'
            . '   AND ' . $sameGrant('l.grant_id', 'bl.grant_id');

        $billed = 'SELECT SUM(bil.amount) FROM {bill_lines} bil JOIN {bills} b ON b.id = bil.bill_id'
            . " WHERE bil.purchase_order_line_id = pol.id AND b.status IN ('approved', 'scheduled', 'paid')";

        $committed = "SELECT SUM(CASE WHEN pol.amount - COALESCE(({$billed}), 0) > 0 THEN pol.amount - COALESCE(({$billed}), 0) ELSE 0 END)"
            . ' FROM {purchase_order_lines} pol'
            . ' JOIN {purchase_orders} po ON po.id = pol.purchase_order_id'
            . " WHERE po.status IN ('open', 'part_received')"
            . '   AND po.entity_id = bv.entity_id AND po.issued_on BETWEEN fy.starts_on AND fy.ends_on'
            . '   AND pol.account_id = bl.account_id AND pol.fund_id = bl.fund_id AND pol.programme_id = bl.programme_id'
            . '   AND ' . $sameGrant('pol.grant_id', 'bl.grant_id');

        $this->db->query($this->sql(
            'CREATE VIEW {v_budget_availability} AS'
            . ' SELECT x.*, x.budget - x.actual - x.committed AS available FROM ('
            . '   SELECT bl.id AS budget_line_id, bv.id AS budget_version_id, bv.entity_id, bv.fiscal_year_id,'
            . '     bl.account_id, bl.fund_id, bl.programme_id, bl.grant_id, bl.cost_group,'
            . '     bl.annual_amount AS budget,'
            . "     COALESCE(({$actual}), 0) AS actual,"
            . "     COALESCE(({$committed}), 0) AS committed"
            . '   FROM {budget_lines} bl'
            . '   JOIN {budget_versions} bv ON bv.id = bl.budget_version_id'
            . '   JOIN {fiscal_years} fy ON fy.id = bv.fiscal_year_id'
            . ' ) x'
        ));
    }

    public function down(): void
    {
        $this->db->query($this->sql('DROP VIEW IF EXISTS {v_budget_availability}'));
        $this->db->query($this->sql('DROP VIEW IF EXISTS {v_fund_balances}'));
    }
}
