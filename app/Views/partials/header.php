<?php
/**
 * Shared shell: sidebar + topbar. Included at the top of every page view.
 * @var string $title
 * @var string $page
 * @var string $crumbGroup
 * @var string $crumbPage
 */
// Root-relative links: works regardless of host/port the dev server is bound to.
$navGroups = [
    'Overview' => [
        ['label' => 'Dashboard', 'icon' => '◧', 'page' => 'dash', 'url' => '/'],
        ['label' => 'Period close', 'icon' => '◷', 'page' => 'period', 'url' => '/period-close'],
    ],
    'Accounting' => [
        ['label' => 'Chart of accounts', 'icon' => '☰', 'page' => 'coa', 'url' => '/coa'],
        ['label' => 'General ledger', 'icon' => '▤', 'page' => 'gl', 'url' => '/gl'],
        ['label' => 'Journals', 'icon' => '✎', 'page' => 'journals', 'url' => '/journals'],
        ['label' => 'Payables', 'icon' => '◇', 'page' => 'payables', 'url' => '/payables'],
        ['label' => 'Bank reconciliation', 'icon' => '⇄', 'page' => 'bankrec', 'url' => '/bank-rec'],
        ['label' => 'Asset register', 'icon' => '▣', 'page' => 'assets', 'url' => '/asset-register'],
        ['label' => 'Payroll', 'icon' => '◔', 'page' => 'payroll', 'url' => '/payroll'],
    ],
    'Funds and grants' => [
        ['label' => 'Funds', 'icon' => '◈', 'page' => 'funds', 'url' => '/funds'],
        ['label' => 'Grants and awards', 'icon' => '◉', 'page' => 'grants', 'url' => '/grants'],
        ['label' => 'Budgets', 'icon' => '▦', 'page' => 'budgets', 'url' => '/budgets'],
        ['label' => 'Donor reports', 'icon' => '◐', 'page' => 'donor', 'url' => '/donor-reports'],
    ],
    'Insight' => [
        ['label' => 'Reports', 'icon' => '◍', 'page' => 'reports', 'url' => '/reports'],
        ['label' => 'Settings', 'icon' => '⚙', 'page' => 'settings', 'url' => '/settings'],
    ],
];
$entities = ['ELOG National Secretariat', 'ELOG Coast Regional Office', 'ELOG Western Regional Office', 'ELOG Trust (Endowment)', 'Consolidated — all entities'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($title) ?> · ELOG Finance Suite</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-mark">EL</div>
      <div class="brand-text"><span class="brand-name">ELOG</span><span class="brand-sub">Finance Suite</span></div>
    </div>
    <nav class="nav">
      <?php foreach ($navGroups as $group => $items): ?>
        <div class="nav-group-label"><?= esc($group) ?></div>
        <?php foreach ($items as $item): ?>
          <a class="nav-item <?= $item['page'] === $page ? 'active' : '' ?>" href="<?= $item['url'] ?>">
            <span class="nav-icon"><?= $item['icon'] ?></span><?= esc($item['label']) ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
      <div class="sidebar-footer-label">Reporting framework</div>
      <div class="sidebar-footer-value">IFRS · KES functional</div>
    </div>
  </aside>

  <main class="main">
    <header class="topbar">
      <div class="crumbs"><span><?= esc($crumbGroup) ?></span><span class="sep">/</span><span class="current"><?= esc($crumbPage) ?></span></div>
      <div class="topbar-right">
        <label class="entity-picker">Entity
          <select>
            <?php foreach ($entities as $e): ?><option><?= esc($e) ?></option><?php endforeach; ?>
          </select>
        </label>
        <div class="fy-pill"><span>FY</span><strong>2026</strong><span class="dot"></span><span class="fy-open">Open</span></div>
        <div class="avatar">WK</div>
      </div>
    </header>
    <div class="content" id="page-content">
