<?php
/** @var array $e @var array $actions @var array $gaps @var bool $canManage @var array $errors @var array $old @var string $defaultDate */
include __DIR__ . '/../partials/entity_header.php';
$id = (int) $e['id'];
$return = "/assessments/{$id}/remediation";
?>
<?php $openCount = count(array_filter($actions, fn ($a) => in_array($a['display_status'], ['open', 'in_progress', 'overdue'], true))); ?>
<div class="card">
  <h2>Remediation plan</h2>
  <p>A <strong>gap</strong> is a recommended safeguard (control) that is not fully in place yet — for example it is not implemented, is less than 70% effective, has no evidence, or its evidence has not been verified. Gaps are why the residual risk has not come down further.</p>
  <p>This page turns each gap into an <strong>action</strong>: who will fix it, how urgent it is, and by when. When the control is fixed, record it on the Control assessment screen and the residual risk updates.</p>
  <div class="row">
    <span class="tag<?= $gaps ? ' tag-bad' : ' tag-ok' ?>"><?= count($gaps) ?> gap<?= count($gaps) === 1 ? '' : 's' ?> still need an action</span>
    <span class="tag"><?= (int) $openCount ?> action<?= $openCount === 1 ? '' : 's' ?> in progress</span>
  </div>
</div>

<?php if ($gaps !== []): ?>
  <h2>Step 1 — Gaps that still need an action</h2>
  <p class="muted small">Each box shows what is missing. Choose an owner, a priority and a target date, then press “Create action”.</p>
  <?php foreach ($gaps as $g): $c = $g['control']; ?>
    <div class="card">
      <h3><?= e($c['title']) ?> <span class="tag"><?= e(ucfirst($c['category'])) ?></span><?php if ($g['mandatory_for_clearance']): ?> <span class="tag">mandatory</span><?php endif; ?></h3>
      <ul class="unmet"><?php foreach ($g['unmet'] as $u): ?><li><?= e($u) ?></li><?php endforeach; ?></ul>
      <?php if ($canManage): ?>
        <form method="post" action="<?= e(url($return)) ?>" class="stack">
          <?= csrf_field() ?>
          <input type="hidden" name="control_id" value="<?= (int) $c['id'] ?>">
          <input type="hidden" name="gap_description" value="<?= e($c['title'] . ': ' . implode('; ', $g['unmet']) . '.') ?>">
          <div class="grid grid-3">
            <div class="field"><label for="o-<?= (int) $c['id'] ?>">Owner (portal user ID)</label><input type="text" id="o-<?= (int) $c['id'] ?>" name="owner_portal_user_id" required placeholder="e.g. dev.infosec"></div>
            <div class="field"><label for="p-<?= (int) $c['id'] ?>">Priority</label>
              <select id="p-<?= (int) $c['id'] ?>" name="priority"><option value="high"<?= $g['mandatory_for_clearance'] ? ' selected' : '' ?>>High</option><option value="medium"<?= !$g['mandatory_for_clearance'] ? ' selected' : '' ?>>Medium</option><option value="low">Low</option></select></div>
            <div class="field"><label for="d-<?= (int) $c['id'] ?>">Target date</label><input type="date" id="d-<?= (int) $c['id'] ?>" name="target_date" required value="<?= e($defaultDate) ?>"></div>
          </div>
          <?php if ($c['owner_role']): ?><p class="hint">Suggested owner role: <?= e(\App\Domain\Role::from($c['owner_role'])->label()) ?>.</p><?php endif; ?>
          <button class="btn btn-small" type="submit">Create action</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<div class="card">
  <h2>Step 2 — Track the actions</h2>
  <p class="muted small">Update the status as work progresses. An action past its target date that is not completed shows as <strong>overdue</strong>.</p>
  <?php if ($actions === []): ?>
    <p class="muted">No remediation actions yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Action</th><th>Owner</th><th>Priority</th><th>Target</th><th>Status</th><th class="num">Done</th><th>Update</th></tr></thead>
        <tbody>
        <?php foreach ($actions as $a): ?>
          <tr<?= $a['display_status'] === 'overdue' ? ' class="highlight"' : '' ?>>
            <td><?= e($a['gap_description']) ?><?php if ($a['control_title']): ?><div class="muted small"><?= e($a['control_title']) ?></div><?php endif; ?></td>
            <td><?= e($a['owner_portal_user_id']) ?></td>
            <td><?= e(ucfirst($a['priority'])) ?></td>
            <td class="nowrap"><?= fmt_date($a['target_date']) ?></td>
            <td><?php include __DIR__ . '/../partials/remediation_status.php'; ?></td>
            <td class="num"><?= (int) $a['completion_pct'] ?>%</td>
            <td><?php if ($canManage || $a['owner_portal_user_id'] === $currentUser->id): include __DIR__ . '/../partials/action_row_form.php'; endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($canManage): ?>
  <form class="card" method="post" action="<?= e(url($return)) ?>" novalidate>
    <?= csrf_field() ?>
    <h2>Add any other action</h2>
    <p class="muted small">For work that is not tied to one of the gaps above.</p>
    <div class="field">
      <label for="gap_description">What needs to be done?</label>
      <textarea id="gap_description" name="gap_description" required<?= aria_err($errors, 'gap_description') ?>><?= e($old['gap_description'] ?? '') ?></textarea>
      <?= field_error($errors, 'gap_description') ?>
    </div>
    <div class="grid grid-3">
      <div class="field"><label for="owner_portal_user_id">Owner (portal user ID)</label>
        <input type="text" id="owner_portal_user_id" name="owner_portal_user_id" required value="<?= e($old['owner_portal_user_id'] ?? '') ?>"<?= aria_err($errors, 'owner_portal_user_id') ?>><?= field_error($errors, 'owner_portal_user_id') ?></div>
      <div class="field"><label for="priority">Priority</label>
        <select id="priority" name="priority"<?= aria_err($errors, 'priority') ?>><option value="">Choose…</option><?php foreach (['high', 'medium', 'low'] as $p): ?><option value="<?= $p ?>"<?= selected($old['priority'] ?? '', $p) ?>><?= ucfirst($p) ?></option><?php endforeach; ?></select><?= field_error($errors, 'priority') ?></div>
      <div class="field"><label for="target_date">Target date</label>
        <input type="date" id="target_date" name="target_date" required value="<?= e($old['target_date'] ?? $defaultDate) ?>"<?= aria_err($errors, 'target_date') ?>><?= field_error($errors, 'target_date') ?></div>
    </div>
    <div class="field"><label for="reviewer_portal_user_id">Reviewer (portal user ID, optional)</label>
      <input type="text" id="reviewer_portal_user_id" name="reviewer_portal_user_id" value="<?= e($old['reviewer_portal_user_id'] ?? '') ?>"></div>
    <button class="btn" type="submit">Add action</button>
  </form>
<?php endif; ?>

<div class="form-actions">
  <a class="btn" href="<?= e(url("/assessments/{$id}/review")) ?>">Continue to review &amp; approval</a>
</div>
