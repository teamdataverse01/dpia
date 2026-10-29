<?php
/**
 * What a person can do with one remediation action, shared by the tracker and the remediation plan.
 *  - anyone: "Record the fix" (opens the linked control, where evidence is uploaded)
 *  - the action's owner: update the status of their own action
 *  - the DPO / control owners / the record owner: remind the owner, or change owner, date or priority
 *
 * @var array $a decorated action row (with can_manage) @var string $return @var array $people
 */
$aid = (int) $a['id'];
$isOpen = in_array($a['display_status'], ['open', 'in_progress', 'overdue'], true);
$isMine = $a['owner_portal_user_id'] === $currentUser->id;
$fix = $a['control_id'] ? url('/assessments/' . (int) $a['risk_entity_id'] . '/controls', ['control' => (int) $a['control_id']]) . '#control-' . (int) $a['control_id'] : null;
?>
<?php if (!$isOpen): ?>
  <span class="muted small"><?= $a['status'] === 'completed' ? 'Done' : 'Cancelled' ?></span>
<?php else: ?>
  <div class="row">
    <?php if ($fix): ?><a class="btn btn-small<?= $isMine ? '' : ' btn-secondary' ?>" href="<?= e($fix) ?>">Record the fix</a><?php endif; ?>
    <?php if (!empty($a['can_manage']) && !$isMine): ?>
      <form class="inline-form" method="post" action="<?= e(url('/remediation/' . $aid . '/remind')) ?>"><?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e($return) ?>">
        <button class="btn btn-small btn-secondary" type="submit">Remind owner</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if ($isMine && empty($a['can_manage'])): ?>
    <details class="small"><summary>Update status</summary>
      <form class="row" method="post" action="<?= e(url('/remediation/' . $aid)) ?>"><?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e($return) ?>">
        <label class="visually-hidden" for="st-<?= $aid ?>">Status</label>
        <select id="st-<?= $aid ?>" name="status">
          <option value="open"<?= selected($a['status'], 'open') ?>>Open</option>
          <option value="in_progress"<?= selected($a['status'], 'in_progress') ?>>In progress</option>
        </select>
        <button class="btn btn-small btn-secondary" type="submit">Save</button>
      </form>
      <p class="hint">It closes by itself once the fix is recorded and verified.</p>
    </details>
  <?php elseif (!empty($a['can_manage'])): ?>
    <details class="small"><summary>Change</summary>
      <form class="stack" method="post" action="<?= e(url('/remediation/' . $aid)) ?>"><?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e($return) ?>">
        <div class="field"><label for="ow-<?= $aid ?>">Owner</label>
          <?php $selName = 'owner_portal_user_id'; $selId = 'ow-' . $aid; $selValue = $a['owner_portal_user_id']; $selRequired = true; include __DIR__ . '/person_select.php'; ?></div>
        <div class="field"><label for="pr-<?= $aid ?>">Priority</label>
          <select id="pr-<?= $aid ?>" name="priority"><?php foreach (['high', 'medium', 'low'] as $p): ?><option value="<?= $p ?>"<?= selected($a['priority'], $p) ?>><?= ucfirst($p) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="td-<?= $aid ?>">Due</label>
          <input type="date" id="td-<?= $aid ?>" name="target_date" value="<?= e(substr((string) $a['target_date'], 0, 10)) ?>"></div>
        <div class="field"><label for="ss-<?= $aid ?>">Status</label>
          <select id="ss-<?= $aid ?>" name="status">
            <option value="open"<?= selected($a['status'], 'open') ?>>Open</option>
            <option value="in_progress"<?= selected($a['status'], 'in_progress') ?>>In progress</option>
            <option value="cancelled">Cancelled (no longer needed)</option>
          </select></div>
        <button class="btn btn-small" type="submit">Save changes</button>
      </form>
    </details>
  <?php endif; ?>
<?php endif; ?>
