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

    /** A journal opens in the editor over the register, as rows in the register do. */
    public function journalDetail($ref)
    {
        return view('pages/journals', [
            'page'        => 'journals',
            'jsPage'      => 'journals',
            'title'       => 'Journals',
            'crumbGroup'  => 'Accounting',
            'crumbPage'   => 'Journals',
            'openRef'     => $ref,
        ]);
    }

    public function payables()
    {
        return $this->render('pages/payables', 'payables', 'payables', 'Payables', 'Accounting');
    }

    /** A bill opens in its drawer over the bill list, as rows in the list do. */
    public function billDetail($no)
    {
        return view('pages/payables', [
            'page'       => 'payables',
            'jsPage'     => 'payables',
            'title'      => 'Payables',
            'crumbGroup' => 'Accounting',
            'crumbPage'  => 'Payables',
            'openNo'     => $no,
        ]);
    }

    public function receivables()
    {
        return $this->render('pages/receivables', 'receivables', 'receivables', 'Receivables', 'Accounting');
    }

    /** An invoice opens in its drawer over the invoice list, as rows in the list do. */
    public function invoiceDetail($no)
    {
        return view('pages/receivables', [
            'page'       => 'receivables',
            'jsPage'     => 'receivables',
            'title'      => 'Receivables',
            'crumbGroup' => 'Accounting',
            'crumbPage'  => 'Receivables',
            'openNo'     => $no,
        ]);
    }

    public function procurement()
    {
        return $this->render('pages/procurement', 'procure', 'procurement', 'Procurement', 'Accounting');
    }

    /** A requisition opens in its drawer over the list, as rows in the list do. */
    public function requisitionDetail($no)
    {
        return view('pages/procurement', [
            'page'       => 'procure',
            'jsPage'     => 'procurement',
            'title'      => 'Procurement',
            'crumbGroup' => 'Accounting',
            'crumbPage'  => 'Procurement',
            'openNo'     => $no,
        ]);
    }

    public function bankRec()
    {
        return $this->render('pages/bank_rec', 'bankrec', 'bank_rec', 'Bank reconciliation', 'Accounting');
    }

    public function assets()
    {
        return $this->render('pages/assets', 'assets', 'assets', 'Asset register', 'Accounting');
    }

    public function assetVerification()
    {
        return $this->render('pages/asset_verification', 'verify', 'asset_verification', 'Asset verification', 'Accounting');
    }

    public function payroll()
    {
        return $this->render('pages/payroll', 'payroll', 'payroll', 'Payroll', 'Accounting');
    }

    public function advances()
    {
        return $this->render('pages/advances', 'advances', 'advances', 'Staff advances', 'Accounting');
    }

    public function programmes()
    {
        return $this->render('pages/programmes', 'programmes', 'programmes', 'Programmes', 'Accounting');
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

    public function cashflow()
    {
        return $this->render('pages/cashflow', 'cashflow', 'cashflow', 'Cashflow forecast', 'Insight');
    }

    public function reports()
    {
        return $this->render('pages/reports', 'reports', 'reports', 'Reports', 'Insight');
    }

    public function settings()
    {
        return $this->render('pages/settings', 'settings', 'settings', 'Settings', 'Insight');
    }

    public function userManual()
    {
        return $this->render('pages/user_manual', 'manual', 'user_manual', 'User manual', 'Insight');
    }
}
