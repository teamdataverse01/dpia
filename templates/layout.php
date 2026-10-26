<?php
/** @var string $content */
use App\Domain\Permission as P;

$user = $currentUser ?? null;
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$cur = fn (string $prefix) => str_contains($path, $prefix) ? ' aria-current="page"' : '';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e(\App\Http\Csrf::token()) ?>">
  <meta name="app-base" content="<?= e(url('')) ?>">
  <title><?= e(($title ?? 'Privacy Risk') . ' · CU Privacy Risk') ?></title>
  <link rel="icon" href="data:,">
  <link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
  <script src="<?= e(url('/assets/js/app.js')) ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
  <div class="inner">
    <a class="brand" href="<?= e(url('/dashboard')) ?>">CU Privacy Risk <small>Vendor &amp; DPIA</small></a>
    <?php if ($user): ?>
    <nav aria-label="Main">
      <ul class="nav">
        <li><a href="<?= e(url('/dashboard')) ?>"<?= $cur('/dashboard') ?>>Dashboard</a></li>
        <li><a href="<?= e(url('/risks')) ?>"<?= $cur('/risks') ?>>Risks</a></li>
        <?php if ($authz->can($user, P::Create)): ?>
        <li><a href="<?= e(url('/vendors/new')) ?>"<?= $cur('/vendors/new') ?>>New vendor assessment</a></li>
        <li><a href="<?= e(url('/dpias/new')) ?>"<?= $cur('/dpias/new') ?>>New DPIA</a></li>
        <?php endif; ?>
        <li><a href="<?= e(url('/remediation')) ?>"<?= $cur('/remediation') ?>>Remediation</a></li>
        <?php if ($authz->can($user, P::Create) || $authz->can($user, P::SaveTemplate) || $authz->can($user, P::ReadAll)): ?>
        <li><a href="<?= e(url('/templates')) ?>"<?= $cur('/templates') ?>>Templates</a></li>
        <?php endif; ?>
        <?php if ($authz->can($user, P::AdminConfig)): ?>
        <li><a href="<?= e(url('/admin/config')) ?>"<?= $cur('/admin') ?>>Admin</a></li>
        <?php endif; ?>
        <?php if ($authz->can($user, P::ReadAll)): ?>
        <li><a href="<?= e(url('/audit')) ?>"<?= $cur('/audit') ?>>Audit log</a></li>
        <?php endif; ?>
        <li><a href="<?= e(url('/help')) ?>"<?= $cur('/help') ?>>Help</a></li>
      </ul>
    </nav>
    <div class="userbox">
      <span><?= e($user->displayName) ?></span>
      <span class="roles">(<?= e($user->roleLabels()) ?>)</span>
      <?php if (!empty($isDevAuth)): ?>
        <a href="<?= e(url('/dev/switch-user', ['return' => $_SERVER['REQUEST_URI'] ?? ''])) ?>">Switch dev user</a>
      <?php endif; ?>
      <?php if (!empty($isDemoAuth)): ?>
        <form class="inline-form" method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button class="btn-link" type="submit">Sign out</button></form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</header>
<main id="main" tabindex="-1">
  <?php foreach (($flash ?? []) as $f): ?>
    <div class="flash flash-<?= e($f['type']) ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($f['message']) ?></div>
  <?php endforeach; ?>
  <?php if ($user && $user->roles === []): ?>
    <div class="flash flash-info" role="status">You are signed in to the portal but have no role in the privacy module yet. Ask an Administrator to assign one.</div>
  <?php endif; ?>
  <?= $content ?>
</main>
<footer class="footer">Covenant University · Unified Privacy Risk module</footer>
</body>
</html>
