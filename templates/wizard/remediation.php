<?php
/**
 * @var array $e @var array $actions @var array $gaps @var array $people @var array $counts
 * @var bool $canManage @var array $errors @var array $old @var string $defaultDate
 */
include __DIR__ . '/../partials/entity_header.php';
$id = (int) $e['id'];
$return = "/assessments/{$id}/remediation";
$fixLink = fn (int $cid) => url("/assessments/{$id}/controls", ['control' => $cid]) . '#control-' . $cid;
$unassigned = $counts['gaps'] - $counts['assigned'];
?>
<div class="card">
  <h2>Remediation plan</h2>
  <p>A <strong>gap</strong> is a recommended safeguard (control) that is not fully in place yet. Gaps are why the residual risk has not come down further.</p>
  <ol class="small">
    <li><strong>Assign</strong> each gap here: who will fix it, how urgent it is (priority) and by when (target date). This is a to-do — it does not change the risk.</li>
    <li>The owner does the work, then presses <strong>Record the fix</strong> to open the control and mark it implemented, give its effectiveness and upload evidence.</li>
    <li>Once the control meets all the criteria (and is verified where needed), the gap disappears, the residual risk is recalculated and <strong>the action closes by itself</strong>. Nobody has to tick it off.</li>
  </ol>
  <div class="row">
    <span class="tag<?= $counts['gaps'] ? ' tag-bad' : ' tag-ok' ?>"><?= (int) $counts['gaps'] ?> gap<?= $counts['gaps'] === 1 ? '' : 's' ?> open</span>
    <span class="tag"><?= (int) $counts['assigned'] ?> assigned</span>
    <span class="tag<?= $unassigned ? ' tag-bad' : '' ?>"><?= (int) $unassigned ?> not assigned yet</span>
    <?php if ($counts['closed']): ?><span class="tag tag-ok"><?= (int) $counts['closed'] ?> action<?= $counts['closed'] === 1 ? '' : 's' ?> done</span><?php endif; ?>
  </div>
</div>

<?php if ($gaps !== []): ?>
  <h2>Gaps</h2>
  <?php foreach ($gaps as $g): $c = $g['control']; $cid = (int) $c['id']; $a = $g['action']; ?>
    <div class="card" id="gap-<?= $cid ?>">
      <div class="row">
        <h3><?= e($c['title']) ?></h3>
        <span class="tag"><?= e(ucfirst($c['category'])) ?></span>
        <?php if ($g['mandatory_for_clearance']): ?><span class="tag">mandatory</span><?php endif; ?>
        <span class="spacer"></span>
        <?php if ($a): ?><span class="tag tag-ok">assigned</span><?php else: ?><span class="tag tag-bad">not assigned</span><?php endif; ?>
      </div>
      <p class="small"><strong>Still missing:</strong></p>
      <ul class="unmet"><?php foreach ($g['unmet'] as $u): ?><li><?= e($u) ?></li><?php endforeach; ?></ul>

      <?php if ($a): ?>
        <p class="note small">
          Assigned to <strong><?= e($a['owner_name']) ?></strong> · <?= e(ucfirst($a['priority'])) ?> priority · due <?= fmt_date($a['target_date']) ?>
          <?php include __DIR__ . '/../partials/remediation_status.php'; ?>
        </p>
        <a class="btn btn-small" href="<?= e($fixLink($cid)) ?>">Record the fix / upload evidence</a>
      <?php elseif ($canManage): ?>
        <form method="post" action="<?= e(url($return)) ?>" class="stack">
          <?= csrf_field() ?>
          <input type="hidden" name="control_id" value="<?= $cid ?>">
          <input type="hidden" name="gap_description" value="<?= e($c['title'] . ': ' . implode('; ', $g['unmet']) . '.') ?>">
          <div class="grid grid-3">
            <div class="field">
              <label for="o-<?= $cid ?>">Who will fix it?</label>
              <?php $selName = 'owner_portal_user_id'; $selId = 'o-' . $cid; $selValue = $g['suggested_owner']; $selRequired = true; include __DIR__ . '/../partials/person_select.php'; ?>
              <?php if ($c['owner_role']): ?><span class="hint">Usually: <?= e(\App\Domain\Role::from($c['owner_role'])->label()) ?></span><?php endif; ?>
            </div>
            <div class="field">
              <label for="p-<?= $cid ?>">Priority</label>
              <select id="p-<?= $cid ?>" name="priority">
                <option value="high"<?= $g['mandatory_for_clearance'] ? ' selected' : '' ?>>High — blocks the risk coming down</option>
                <option value="medium"<?= !$g['mandatory_for_clearance'] ? ' selected' : '' ?>>Medium</option>
                <option value="low">Low</option>
              </select>
              <span class="hint">How urgent it is. It orders the work; it does not change the score.</span>
            </div>
            <div class="field">
              <label for="d-<?= $cid ?>">Fix by (target date)</label>
              <input type="date" id="d-<?= $cid ?>" name="target_date" required value="<?= e($defaultDate) ?>">
              <span class="hint">After this date it shows as overdue.</span>
            </div>
          </div>
          <div class="row">
            <button class="btn btn-small" type="submit">Assign this gap</button>
            <a href="<?= e($fixLink($cid)) ?>" class="small">Already fixed? Record it now</a>
          </div>
        </form>
      <?php else: ?>
        <a class="btn btn-small btn-secondary" href="<?= e($fixLink($cid)) ?>">Open the control</a>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php else: ?>
  <p class="note">No gaps: every recommended control meets the criteria.</p>
<?php endif; ?>

<div class="card">
  <h2>Actions</h2>
  <p class="muted small">Everything assigned for this assessment. Anything past its date and not completed shows as <strong>overdue</strong> on the dashboard.</p>
  <?php if ($actions === []): ?>
    <p class="muted">No actions yet. Assign a gap above to create one.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Action</th><th>Owner</th><th>Priority</th><th>Due</th><th>Status</th><th>Next step</th></tr></thead>
        <tbody>
        <?php foreach ($actions as $a): ?>
          <tr<?= $a['display_status'] === 'overdue' ? ' class="highlight"' : '' ?>>
            <td><?= e($a['control_title'] ?? $a['gap_description']) ?><?php if ($a['control_title']): ?><div class="muted small"><?= e($a['gap_description']) ?></div><?php endif; ?></td>
            <td><?= e($a['owner_name']) ?></td>
            <td><?= e(ucfirst($a['priority'])) ?></td>
            <td class="nowrap"><?= fmt_date($a['target_date']) ?></td>
            <td><?php include __DIR__ . '/../partials/remediation_status.php'; ?></td>
            <td><?php include __DIR__ . '/../partials/action_controls.php'; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($canManage): ?>
  <details class="card"<?= $errors ? ' open' : '' ?>>
    <summary><strong>Add any other action</strong> <span class="muted small">— for work not tied to one of the gaps</span></summary>
    <form method="post" action="<?= e(url($return)) ?>" novalidate class="stack">
      <?= csrf_field() ?>
      <div class="field">
        <label for="gap_description">What needs to be done?</label>
        <textarea id="gap_description" name="gap_description" required<?= aria_err($errors, 'gap_description') ?>><?= e($old['gap_description'] ?? '') ?></textarea>
        <?= field_error($errors, 'gap_description') ?>
      </div>
      <div class="grid grid-3">
        <div class="field"><label for="owner_portal_user_id">Who will do it?</label>
          <?php $selName = 'owner_portal_user_id'; $selId = 'owner_portal_user_id'; $selValue = $old['owner_portal_user_id'] ?? null; $selRequired = true; include __DIR__ . '/../partials/person_select.php'; ?>
          <?= field_error($errors, 'owner_portal_user_id') ?></div>
        <div class="field"><label for="priority">Priority</label>
          <select id="priority" name="priority"<?= aria_err($errors, 'priority') ?>><option value="">Choose…</option><?php foreach (['high', 'medium', 'low'] as $p): ?><option value="<?= $p ?>"<?= selected($old['priority'] ?? '', $p) ?>><?= ucfirst($p) ?></option><?php endforeach; ?></select><?= field_error($errors, 'priority') ?></div>
        <div class="field"><label for="target_date">Do by (target date)</label>
          <input type="date" id="target_date" name="target_date" required value="<?= e($old['target_date'] ?? $defaultDate) ?>"<?= aria_err($errors, 'target_date') ?>><?= field_error($errors, 'target_date') ?></div>
      </div>
      <div class="field"><label for="reviewer_portal_user_id">Who checks it's done? (optional)</label>
        <?php $selName = 'reviewer_portal_user_id'; $selId = 'reviewer_portal_user_id'; $selValue = $old['reviewer_portal_user_id'] ?? null; $selRequired = false; include __DIR__ . '/../partials/person_select.php'; ?></div>
      <button class="btn" type="submit">Add action</button>
    </form>
  </details>
<?php endif; ?>

<div class="form-actions">
  <a class="btn" href="<?= e(url("/assessments/{$id}/review")) ?>">Continue to review &amp; approval</a>
</div>
