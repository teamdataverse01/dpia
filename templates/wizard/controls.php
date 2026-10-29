<?php
/** @var array $e @var array $rows @var ?array $explanation @var array $addable @var bool $canVerify @var array $statuses @var int $minEffectiveness @var int $focus */
include __DIR__ . '/../partials/entity_header.php';
$id = (int) $e['id'];
$byCat = ['policy' => [], 'process' => [], 'technology' => []];
foreach ($rows as $r) {
    $byCat[$r['control']['category']][] = $r;
}
?>
<?php if ($explanation === null): ?>
  <div class="card"><p>Score the inherent risk first.</p></div>
<?php else: ?>
<div class="card">
  <h2><?= term('Control', 'control') ?> assessment</h2>
  <p>For each safeguard, record whether it is in place, how effective it is, and upload evidence. A control type is <strong>cleared</strong> when every mandatory control in it is implemented, at least <?= (int) $minEffectiveness ?>% effective, has current evidence and — where required — has been verified by a Reviewer.</p>
  <div class="row">
    <?php foreach ($explanation['categories'] as $cat => $c): ?>
      <span class="tag <?= $c['cleared'] ? 'tag-ok' : 'tag-bad' ?>"><?= e(ucfirst($cat)) ?>: <?= $c['cleared'] ? 'cleared' : 'not cleared' ?></span>
    <?php endforeach; ?>
    <span class="spacer"></span>
    <span class="small"><?= term('Residual risk', 'residual') ?> right now: <?= rating_badge($explanation['residual_rating']) ?></span>
  </div>
</div>

<?php foreach ($byCat as $cat => $list): $category = \App\Domain\ControlCategory::from($cat); $c = $explanation['categories'][$cat]; ?>
  <div class="cat-head">
    <h2><?= e($category->label()) ?></h2>
    <span class="tag <?= $c['cleared'] ? 'tag-ok' : 'tag-bad' ?>"><?= $c['cleared'] ? 'Cleared' : 'Not cleared' ?></span>
  </div>
  <?php if ($list === []): ?><p class="muted small">No controls of this type in the assessment.</p><?php endif; ?>
  <?php foreach ($list as $r):
      $ctl = $r['control'];
      $a = $r['assessment'];
      $cid = (int) $ctl['id'];
      $met = $r['counts_for_clearance'] && $r['unmet'] === [];
  ?>
    <details class="control" id="control-<?= $cid ?>"<?= $focus === $cid ? ' open' : '' ?>>
      <summary>
        <span class="title"><?= e($ctl['title']) ?></span>
        <?php if ($r['mandatory_for_clearance']): ?><span class="tag">mandatory</span><?php endif; ?>
        <?php if (!$r['recommended']): ?><span class="tag"><?= $r['source'] === 'manual' ? 'added manually' : 'no longer recommended' ?></span><?php endif; ?>
        <span class="tag"><?= e(\App\Domain\ControlStatus::from($a['status'] ?? 'not_started')->label()) ?></span>
        <?php if ($r['counts_for_clearance']): ?>
          <span class="tag <?= $met ? 'tag-ok' : 'tag-bad' ?>"><?= $met ? 'meets criteria' : 'criteria not met' ?></span>
        <?php endif; ?>
        <?php if (isset($assigned[$cid])): $as = $assigned[$cid]; ?>
          <span class="tag<?= $as['display_status'] === 'overdue' ? ' tag-bad' : '' ?>">assigned to <?= e($as['owner_name']) ?> · due <?= fmt_date($as['target_date']) ?></span>
        <?php endif; ?>
      </summary>
      <div class="body">
        <p class="small"><?= e($ctl['description']) ?></p>
        <p class="small muted"><strong>Why:</strong> <?= e($r['reason']) ?><?php if ($ctl['expected_evidence']): ?> <strong>Evidence expected:</strong> <?= e($ctl['expected_evidence']) ?><?php endif; ?></p>
        <?php if ($r['unmet'] !== []): ?>
          <p class="small"><strong>Still needed:</strong></p>
          <ul class="unmet"><?php foreach ($r['unmet'] as $u): ?><li><?= e($u) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>

        <?php if (!empty($a['evidence_path'])): ?>
          <p class="small">Evidence: <a href="<?= e(url("/assessments/{$id}/controls/{$cid}/evidence")) ?>"><?= e($a['evidence_original_name'] ?: 'download') ?></a>
            <?php if ($a['evidence_date']): ?>· dated <?= fmt_date($a['evidence_date']) ?><?php endif; ?>
            <?php if ($a['expiry_date']): ?>· expires <?= fmt_date($a['expiry_date']) ?><?php endif; ?>
            <?php if ($a['verified_at']): ?>· <span class="tag tag-ok">verified by <?= e($a['verified_by']) ?> on <?= fmt_date($a['verified_at']) ?></span><?php elseif ($ctl['verification_required']): ?>· <span class="tag tag-bad">not yet verified</span><?php endif; ?>
          </p>
        <?php endif; ?>

        <?php if ($canEditControls): ?>
          <form method="post" enctype="multipart/form-data" action="<?= e(url("/assessments/{$id}/controls/{$cid}")) ?>">
            <?= csrf_field() ?>
            <div class="grid grid-3">
              <div class="field">
                <label for="status-<?= $cid ?>">Status</label>
                <select id="status-<?= $cid ?>" name="status">
                  <?php foreach ($statuses as $s): ?>
                    <option value="<?= e($s->value) ?>"<?= selected($a['status'] ?? 'not_started', $s->value) ?>><?= e($s->label()) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="eff-<?= $cid ?>">Effectiveness (%)</label>
                <span class="hint">How well it works in practice, 0–100.</span>
                <input type="number" min="0" max="100" step="1" id="eff-<?= $cid ?>" name="effectiveness_pct" value="<?= e((string) ($a['effectiveness_pct'] ?? '')) ?>">
              </div>
              <div class="field">
                <label for="owner-<?= $cid ?>">Evidence owner</label>
                <input type="text" id="owner-<?= $cid ?>" name="evidence_owner" maxlength="150" value="<?= e($a['evidence_owner'] ?? '') ?>">
              </div>
              <div class="field">
                <label for="evdate-<?= $cid ?>">Evidence date</label>
                <input type="date" id="evdate-<?= $cid ?>" name="evidence_date" value="<?= e(substr((string) ($a['evidence_date'] ?? ''), 0, 10)) ?>">
              </div>
              <div class="field">
                <label for="expiry-<?= $cid ?>">Evidence expiry date</label>
                <span class="hint">Leave empty if it does not expire.</span>
                <input type="date" id="expiry-<?= $cid ?>" name="expiry_date" value="<?= e(substr((string) ($a['expiry_date'] ?? ''), 0, 10)) ?>">
              </div>
              <div class="field">
                <label for="file-<?= $cid ?>"><?= !empty($a['evidence_path']) ? 'Replace evidence file' : 'Upload evidence file' ?></label>
                <span class="hint">PDF, Word, Excel, image, CSV or text; max 10 MB.</span>
                <input type="file" id="file-<?= $cid ?>" name="evidence" accept=".pdf,.docx,.xlsx,.png,.jpg,.jpeg,.csv,.txt">
              </div>
            </div>
            <div class="field">
              <label for="notes-<?= $cid ?>">Notes</label>
              <textarea id="notes-<?= $cid ?>" name="notes"><?= e($a['notes'] ?? '') ?></textarea>
            </div>
            <button class="btn btn-small" type="submit">Save control</button>
          </form>
        <?php endif; ?>
        <?php if ($canVerify && !empty($a['evidence_path'])): ?>
          <form class="form-actions" method="post" action="<?= e(url("/assessments/{$id}/controls/{$cid}/verify")) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="verified" value="<?= $a['verified_at'] ? '0' : '1' ?>">
            <button class="btn btn-small btn-secondary" type="submit"><?= $a['verified_at'] ? 'Remove verification' : 'Verify this evidence (Reviewer)' ?></button>
          </form>
        <?php endif; ?>
      </div>
    </details>
  <?php endforeach; ?>
<?php endforeach; ?>

<?php if ($canEditControls && $addable !== []): ?>
  <form class="card" method="post" action="<?= e(url("/assessments/{$id}/controls/add")) ?>">
    <?= csrf_field() ?>
    <h2>Add another control</h2>
    <p class="muted small">Add a library control that was not recommended. It can help a type to clear but never blocks it.</p>
    <div class="row">
      <label class="visually-hidden" for="add-control">Control</label>
      <select id="add-control" name="control_id">
        <?php foreach ($addable as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e(ucfirst($c['category']) . ' — ' . $c['title']) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-secondary" type="submit">Add</button>
    </div>
  </form>
<?php endif; ?>

<div class="form-actions">
  <?php if ($editable && ($canEditControls || $canEditOwn)): ?>
    <form method="post" action="<?= e(url("/assessments/{$id}/residual")) ?>"><?= csrf_field() ?><button class="btn" type="submit">Calculate residual risk</button></form>
  <?php else: ?>
    <a class="btn" href="<?= e(url("/assessments/{$id}/residual")) ?>">See residual risk</a>
  <?php endif; ?>
</div>
<?php endif; ?>
