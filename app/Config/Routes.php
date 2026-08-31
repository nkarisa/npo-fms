<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Pages::dashboard');
$routes->get('period-close', 'Pages::periodClose');
$routes->get('coa', 'Pages::coa');
$routes->get('gl', 'Pages::gl');
$routes->get('journals', 'Pages::journals');
$routes->get('journals/(:segment)', 'Pages::journalDetail/$1');
$routes->get('payables', 'Pages::payables');
$routes->get('bank-rec', 'Pages::bankRec');
$routes->get('asset-register', 'Pages::assets');
$routes->get('payroll', 'Pages::payroll');
$routes->get('funds', 'Pages::funds');
$routes->get('grants', 'Pages::grants');
$routes->get('budgets', 'Pages::budgets');
$routes->get('donor-reports', 'Pages::donorReports');
$routes->get('reports', 'Pages::reports');
$routes->get('settings', 'Pages::settings');

$routes->group('api', static function (RouteCollection $routes) {
    $routes->get('dashboard', 'Api\Dashboard::index');
    $routes->get('period-close', 'Api\PeriodClose::index');
    $routes->get('coa', 'Api\Coa::index');
    $routes->post('coa', 'Api\Coa::create');
    $routes->get('coa/export', 'Api\Coa::export');
    $routes->put('coa/(:segment)', 'Api\Coa::update/$1');
    $routes->post('coa/(:segment)/archive', 'Api\Coa::archive/$1');
    $routes->get('gl', 'Api\Gl::index');
    $routes->get('journals', 'Api\Journals::index');
    $routes->post('journals', 'Api\Journals::create');
    $routes->get('journals/(:segment)', 'Api\Journals::show/$1');
    $routes->get('payables', 'Api\Payables::index');
    $routes->get('payables/(:segment)', 'Api\Payables::show/$1');
    $routes->get('bank-rec', 'Api\BankRec::index');
    $routes->get('assets', 'Api\Assets::index');
    $routes->get('assets/(:segment)', 'Api\Assets::show/$1');
    $routes->get('payroll', 'Api\Payroll::index');
    $routes->get('funds', 'Api\Funds::index');
    $routes->get('funds/(:segment)', 'Api\Funds::show/$1');
    $routes->get('grants', 'Api\Grants::index');
    $routes->get('grants/(:segment)', 'Api\Grants::show/$1');
    $routes->get('budgets', 'Api\Budgets::index');
    $routes->get('donor-reports', 'Api\DonorReports::index');
    $routes->get('donor-reports/(:segment)', 'Api\DonorReports::show/$1');
    $routes->get('reports', 'Api\Reports::index');
    $routes->get('settings', 'Api\Settings::index');
});
