<?php

namespace App\Controllers;

/**
 * Renders the ELOG shell for each functional area. Each view fetches its own
 * data from the /api/* endpoints (see App\Controllers\Api) which serve the
 * prototype data extracted from the original design prototype.
 */
class Pages extends BaseController
{
    private function render(string $view, string $page, string $jsPage, string $title, string $crumbGroup)
    {
        return view($view, [
            'page'        => $page,
            'jsPage'      => $jsPage,
            'title'       => $title,
            'crumbGroup'  => $crumbGroup,
            'crumbPage'   => $title,
        ]);
    }

    public function dashboard()
    {
        return $this->render('pages/dashboard', 'dash', 'dashboard', 'Finance overview', 'Overview');
    }

    public function periodClose()
    {
        return $this->render('pages/period_close', 'period', 'period_close', 'Period close', 'Overview');
    }

    public function coa()
    {
        return $this->render('pages/coa', 'coa', 'coa', 'Chart of accounts', 'Accounting');
    }

    public function gl()
    {
        return $this->render('pages/gl', 'gl', 'gl', 'General ledger', 'Accounting');
    }

    public function journals()
    {
        return $this->render('pages/journals', 'journals', 'journals', 'Journals', 'Accounting');
    }

    public function journalDetail($ref)
    {
        return view('pages/journal_detail', [
            'page'        => 'journal-detail',
            'jsPage'      => 'journal_detail',
            'title'       => 'Journal entry',
            'crumbGroup'  => 'Accounting',
            'crumbPage'   => 'Journal entry',
            'ref'         => $ref,
        ]);
    }

    public function payables()
    {
        return $this->render('pages/payables', 'payables', 'payables', 'Payables', 'Accounting');
    }

    public function bankRec()
    {
        return $this->render('pages/bank_rec', 'bankrec', 'bank_rec', 'Bank reconciliation', 'Accounting');
    }

    public function assets()
    {
        return $this->render('pages/assets', 'assets', 'assets', 'Asset register', 'Accounting');
    }

    public function payroll()
    {
        return $this->render('pages/payroll', 'payroll', 'payroll', 'Payroll', 'Accounting');
    }

    public function funds()
    {
        return $this->render('pages/funds', 'funds', 'funds', 'Funds', 'Funds and grants');
    }

    public function grants()
    {
        return $this->render('pages/grants', 'grants', 'grants', 'Grants and awards', 'Funds and grants');
    }

    public function budgets()
    {
        return $this->render('pages/budgets', 'budgets', 'budgets', 'Budgets', 'Funds and grants');
    }

    public function donorReports()
    {
        return $this->render('pages/donor_reports', 'donor', 'donor_reports', 'Donor reports', 'Funds and grants');
    }

    public function reports()
    {
        return $this->render('pages/reports', 'reports', 'reports', 'Reports', 'Insight');
    }

    public function settings()
    {
        return $this->render('pages/settings', 'settings', 'settings', 'Settings', 'Insight');
    }
}
