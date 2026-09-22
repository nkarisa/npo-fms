<?php
/**
 * The installer: standing a new instance up in a browser.
 *
 * Outside the shell, like the sign-in screens — the shell draws a sidebar and an
 * entity picker, and neither exists yet. Unbranded on purpose: there is no
 * organisation to be branded as until this has finished.
 *
 * @var string $title
 * @var string $name  what to call the application before it belongs to anybody
 * @var string $theme
 */

use App\Libraries\Brand;
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= esc($theme) ?>" data-brand="<?= esc($name, 'attr') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex, nofollow">
<title><?= esc($title) ?> · <?= esc($name) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset_url('/assets/css/app.css') ?>">
</head>
<body class="auth-body">
<main class="auth-wrap ins-wrap">
  <div class="auth-brand">
    <div class="brand-mark"><?= esc(Brand::mark($name)) ?></div>
    <div class="auth-brand-text">
      <span class="auth-brand-name"><?= esc($name) ?></span>
      <span class="auth-brand-sub">Setting up a new instance</span>
    </div>
  </div>
  <section class="auth-card ins-card" id="install" aria-live="polite">
    <noscript><p class="auth-note">The installer needs JavaScript switched on. Without it, stand the instance up from the
      command line instead: <code>php spark migrate</code>, <code>php spark db:seed BaselineSeeder</code>,
      <code>php spark install</code>.</p></noscript>
  </section>
  <p class="auth-foot">Nothing is written to the database until the last step, and it is written in one transaction.</p>
</main>
<div id="toast" class="toast" role="status" aria-live="polite"></div>
<script src="<?= asset_url('/assets/js/ui.js') ?>"></script>
<script src="<?= asset_url('/assets/js/install.js') ?>"></script>
</body>
</html>
