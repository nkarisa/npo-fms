<?php
/**
 * The sign-in screens: password, second step, setting a second step up, accepting
 * an invitation and resetting a password. Outside the shell — nothing here needs
 * a session — but in the organisation's own name and colours.
 *
 * @var string $mode  login | invite | reset
 * @var string $title
 * @var string $next  where to go once signed in (a path on this site)
 */

use App\Libraries\Brand;
use App\Libraries\Theme;

$theme = Theme::current();
$brand = Brand::current();
$style = $theme === Theme::CUSTOM ? Theme::customStyle(Theme::currentCustom()) : '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= esc($theme) ?>" data-brand="<?= esc($brand['name'], 'attr') ?>"<?= $style !== '' ? ' style="' . esc($style, 'attr') . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title><?= esc($title) ?> · <?= esc(trim($brand['name'] . ' ' . $brand['tagline'])) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset_url('/assets/css/app.css') ?>">
</head>
<body class="auth-body">
<main class="auth-wrap">
  <div class="auth-brand">
    <?php if ($brand['logo'] !== ''): ?>
      <img class="auth-logo" src="<?= esc($brand['logo']) ?>" alt="<?= esc($brand['name']) ?>">
    <?php else: ?>
      <div class="brand-mark"><?= esc($brand['mark']) ?></div>
    <?php endif; ?>
    <div class="auth-brand-text"><span class="auth-brand-name"><?= esc($brand['name']) ?></span><?php if ($brand['tagline'] !== ''): ?><span class="auth-brand-sub"><?= esc($brand['tagline']) ?></span><?php endif; ?></div>
  </div>
  <section class="auth-card" id="auth" data-mode="<?= esc($mode, 'attr') ?>" data-next="<?= esc($next, 'attr') ?>" aria-live="polite">
    <noscript><p class="auth-note">Signing in needs JavaScript switched on.</p></noscript>
  </section>
  <p class="auth-foot">Every sign-in is recorded with the address it came from.</p>
</main>
<script src="<?= asset_url('/assets/js/ui.js') ?>"></script>
<script src="<?= asset_url('/assets/js/vendor/qrcode.js') ?>"></script>
<script src="<?= asset_url('/assets/js/mfa.js') ?>"></script>
<script src="<?= asset_url('/assets/js/auth.js') ?>"></script>
</body>
</html>
