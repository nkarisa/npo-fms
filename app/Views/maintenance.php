<?php
/**
 * What everyone but the people who can open it again sees while the application
 * is closed for maintenance (App\Filters\SignedIn).
 *
 * Outside the shell — a shell whose pages cannot be reached would only invite
 * clicking — but in the organisation's own name and colours, so it is plainly
 * this application saying it is closed rather than a server that has fallen over.
 * Nothing on it needs a session or a script.
 *
 * @var array $state as App\Libraries\Maintenance::state() describes it
 */

use App\Libraries\Brand;
use App\Libraries\Maintenance;
use App\Libraries\Theme;

$theme = Theme::current();
$brand = Brand::current();
$style = $theme === Theme::CUSTOM ? Theme::customStyle(Theme::currentCustom()) : '';
$message = $state['message'] !== '' ? $state['message'] : Maintenance::DEFAULT_MESSAGE;
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= esc($theme) ?>" data-brand="<?= esc($brand['name'], 'attr') ?>"<?= $style !== '' ? ' style="' . esc($style, 'attr') . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Closed for maintenance · <?= esc(trim($brand['name'] . ' ' . $brand['tagline'])) ?></title>
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
  <section class="auth-card mt-page">
    <h1 class="auth-title">Closed for maintenance</h1>
    <p class="mt-page-msg"><?= esc($message) ?></p>
    <?php if ($state['until'] !== null): ?>
      <p class="mt-page-when">Expected back at <?= esc(Maintenance::when($state['until'])) ?>.</p>
    <?php else: ?>
      <p class="mt-page-when">It will be back as soon as the work is finished.</p>
    <?php endif; ?>
    <p class="auth-note">Nothing you have saved is affected. Your work is where you left it, and this page is safe to reload.</p>
  </section>
  <p class="auth-foot">Signed in already? You are still signed in — refresh once the application is back.</p>
</main>
</body>
</html>
