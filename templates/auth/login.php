<?php /** @var array $accounts @var ?string $error @var string $username @var string $returnTo */ ?>
<div class="login-wrap">
  <div class="card">
    <h1>Sign in to the demo</h1>
    <p class="muted">Unified Privacy Risk module — Vendor Risk Assessment and DPIA. In the live system, sign-in is handled by the CU portal.</p>
    <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(url('/login')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="return" value="<?= e($returnTo) ?>">
      <div class="field">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" autocomplete="username" required value="<?= e($username) ?>" placeholder="e.g. dpo">
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
      </div>
      <button class="btn" type="submit">Sign in</button>
    </form>
  </div>
  <div class="card">
    <h2>Demo accounts</h2>
    <p class="muted small">Each account shows the module from one role's point of view. All use the same demo password.</p>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Username</th><th>Who they are</th><th>Use them to show</th></tr></thead>
        <tbody>
        <?php foreach ($accounts as $a): ?>
          <tr><td><code><?= e($a['username']) ?></code></td><td><?= e($a['who']) ?></td><td><?= e($a['shows']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
