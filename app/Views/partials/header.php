<?php
/**
 * Shared shell: sidebar + topbar. Included at the top of every page view.
 * @var string $title
 * @var string $page
 * @var string $crumbGroup
 * @var string $crumbPage
 */

use App\Libraries\I18n;
use App\Libraries\Navigation;

// Language and direction are set server-side from the saved preference, so a
// right-to-left language lays out correctly on first paint rather than flipping
// once the page script has run.
$request = service('request');
$locale  = new I18n($request->getCookie('elog_locale'));
$current = $locale->locale();
?>
<!DOCTYPE html>
<html lang="<?= esc($locale->code()) ?>" dir="<?= esc($locale->dir()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($title) ?> · ELOG Finance Suite</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/app.css">
<script>
  // Applied before first paint so a collapsed sidebar does not flash open.
  try { if (localStorage.getItem('elog.nav') === 'collapsed') document.documentElement.classList.add('nav-collapsed'); } catch (e) {}
</script>
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-mark">EL</div>
      <div class="brand-text"><span class="brand-name">ELOG</span><span class="brand-sub">Finance Suite</span></div>
      <button type="button" class="nav-toggle" id="nav-toggle" title="Collapse the menu" aria-label="Collapse the menu">‹</button>
    </div>
    <nav class="nav">
      <?php foreach (Navigation::GROUPS as $group => $items): ?>
        <div class="nav-group-label"><?= esc($group) ?></div>
        <?php foreach ($items as $item): ?>
          <a class="nav-item <?= $item['page'] === $page ? 'active' : '' ?>" href="<?= $item['url'] ?>" title="<?= esc($item['label']) ?>">
            <span class="nav-icon"><?= $item['icon'] ?></span><span class="nav-label"><?= esc($item['label']) ?></span>
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

        <div class="tb-pop">
          <button type="button" class="tb-lang" id="lang-btn" title="Interface language — the ledger itself is unchanged">
            <span class="tb-lang-glyph">⌾</span>
            <span class="tb-lang-code"><?= esc($locale->isSource() ? 'EN' : strtoupper($locale->code())) ?></span>
            <?php if (!$locale->isSource()): ?><span class="tb-lang-cov"><?= (int) $current['coverage'] ?>%</span><?php endif; ?>
            <span class="tb-caret">▾</span>
          </button>
          <div class="tb-menu tb-menu-lang" id="lang-menu" hidden></div>
        </div>

        <label class="entity-picker">Entity
          <select>
            <?php foreach (Navigation::ENTITIES as $e): ?><option><?= esc($e) ?></option><?php endforeach; ?>
          </select>
        </label>

        <button type="button" class="tb-search" id="search-btn" title="Search everything (⌘K)" aria-label="Search everything">
          <span class="tb-search-glyph">⌕</span>
          <span class="tb-search-label">Search everything</span>
          <span class="tb-kbd">⌘K</span>
        </button>

        <div class="fy-pill"><span>FY</span><strong>2026</strong><span class="dot"></span><span class="fy-open">Open</span></div>

        <div class="tb-pop">
          <button type="button" class="tb-icon" id="notif-btn" aria-label="Notifications">◎<span class="tb-badge" id="notif-badge" hidden></span></button>
          <div class="tb-menu tb-menu-notif" id="notif-menu" hidden></div>
        </div>

        <div class="tb-pop">
          <button type="button" class="tb-user" id="user-btn" aria-label="Account">
            <span class="avatar" id="user-initials">WK</span>
            <span class="tb-caret">▾</span>
          </button>
          <div class="tb-menu tb-menu-user" id="user-menu" hidden></div>
        </div>

      </div>
    </header>
    <div class="content" id="page-content">
