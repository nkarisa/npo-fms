<?php
/**
 * Shared shell: sidebar + topbar. Included at the top of every page view.
 * @var string $title
 * @var string $page
 * @var string $crumbGroup
 * @var string $crumbPage
 */

use App\Libraries\Brand;
use App\Libraries\EntityScope;
use App\Libraries\I18n;
use App\Libraries\Navigation;
use App\Libraries\SettingsAccess;
use App\Libraries\Theme;

// Language and direction are set server-side, the same way the API decides them, so a
// right-to-left language lays out correctly on first paint rather than flipping
// once the page script has run.
$request = service('request');
$locale  = I18n::forRequest($request);
$current = $locale->locale();

// The theme and the brand are organisation settings, so they are resolved here
// with the language rather than in the browser: the shell paints in the right
// colours, under the right name, on first paint — no flash of the default palette
// or of somebody else's name while a script runs.
$theme = Theme::current();
$brand = Brand::current();
// Only the custom theme's two chosen colours are written inline. Every shade
// derived from them is in app.css, so there is one statement of how a custom
// palette is built rather than one here and another in the settings preview.
$style = $theme === Theme::CUSTOM ? Theme::customStyle(Theme::currentCustom()) : '';
$entities = Navigation::entities();
// Settings is offered only to someone with a section of it to see.
try {
    $hidden = SettingsAccess::current()->any() ? [] : ['settings'];
} catch (Throwable $e) {
    $hidden = ['settings'];
}
?>
<!DOCTYPE html>
<html lang="<?= esc($locale->code()) ?>" dir="<?= esc($locale->dir()) ?>" data-theme="<?= esc($theme) ?>" data-brand="<?= esc($brand['name'], 'attr') ?>"<?= $style !== '' ? ' style="' . esc($style, 'attr') . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($title) ?> · <?= esc(trim($brand['name'] . ' ' . $brand['tagline'])) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset_url('/assets/css/app.css') ?>">
<script>
  // Applied before first paint so a collapsed sidebar does not flash open.
  try { if (localStorage.getItem('elog.nav') === 'collapsed') document.documentElement.classList.add('nav-collapsed'); } catch (e) {}
</script>
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand">
      <?php if ($brand['logo'] !== ''): ?>
        <img class="brand-logo" src="<?= esc($brand['logo']) ?>" alt="<?= esc($brand['name']) ?>">
      <?php else: ?>
        <div class="brand-mark"><?= esc($brand['mark']) ?></div>
      <?php endif; ?>
      <div class="brand-text"><span class="brand-name"><?= esc($brand['name']) ?></span><?php if ($brand['tagline'] !== ''): ?><span class="brand-sub"><?= esc($brand['tagline']) ?></span><?php endif; ?></div>
      <button type="button" class="nav-toggle" id="nav-toggle" title="Collapse the menu" aria-label="Collapse the menu">‹</button>
    </div>
    <nav class="nav">
      <?php foreach (Navigation::GROUPS as $group => $items): ?>
        <div class="nav-group-label"><?= esc($group) ?></div>
        <?php foreach ($items as $item): ?>
          <?php if (in_array($item['page'], $hidden, true)) continue; ?>
          <a class="nav-item <?= $item['page'] === $page ? 'active' : '' ?>" href="<?= $item['url'] ?>" aria-label="<?= esc($item['label']) ?>" data-rail>
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

        <?php if (count($entities) > 1): ?>
        <label class="entity-picker">Entity
          <select id="entity-picker" title="The entity whose books you are working in">
            <?php foreach ($entities as $e): ?><option value="<?= esc($e['value'], 'attr') ?>"<?= $e['current'] ? ' selected' : '' ?>><?= esc($e['name']) ?></option><?php endforeach; ?>
          </select>
          <?php if (in_array(true, array_map(static fn ($e) => $e['current'] && $e['value'] === EntityScope::CONSOLIDATED, $entities), true)): ?>
          <span class="entity-readonly" title="Choose an entity to record or approve anything">Read only</span>
          <?php endif; ?>
        </label>
        <?php elseif ($entities !== []): ?>
        <span class="entity-picker" title="The only entity you hold a role at">Entity <strong class="entity-fixed"><?= esc($entities[0]['name']) ?></strong></span>
        <?php endif; ?>

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
