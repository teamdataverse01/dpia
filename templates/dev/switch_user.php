<div class="card">
  <h1>Development: choose a portal user</h1>
  <p class="note">This page exists only in development (<code>APP_ENV=dev</code>, <code>AUTH_ADAPTER=dev</code>). In production, identity comes from the CU portal session and this module has no login of its own.</p>
  <form method="post" action="<?= e(url('/dev/switch-user')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="return" value="<?= e($returnTo) ?>">
    <fieldset>
      <legend>Act as</legend>
      <div class="choices">
        <?php foreach ($users as $u): ?>
          <label class="choice">
            <input type="radio" name="user" value="<?= e($u['id']) ?>"<?= checked($current === $u['id'] || ($current === null && $u['id'] === 'dev.requestor')) ?>>
            <span><strong><?= e($u['name']) ?></strong>
              <span class="muted small"><?= e($u['id']) ?> · <?= e(implode(', ', array_map(fn ($r) => $r->label(), $u['roles'])) ?: 'no role mapped (run the demo seed)') ?></span></span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <button class="btn" type="submit">Continue</button>
  </form>
</div>
