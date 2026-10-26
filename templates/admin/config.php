<?php
/** @var \App\Engine\ScoringConfig $active @var array $values @var array $errors @var array $history @var array $factors @var array $categories @var array $roles */
$err = fn (string $k) => field_error($errors, $k);
?>
<h1>Scoring configuration</h1>
<?php include __DIR__ . '/_nav.php'; ?>
<p class="note">Saving creates <strong>version <?= (int) $active->version + 1 ?></strong>; the current version (<?= (int) $active->version ?>) is never edited. Existing assessments keep the version they were scored with until someone recalculates them.</p>
<?php if ($errors): ?><div class="flash flash-error" role="alert">The configuration was not saved. <?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<form method="post" action="<?= e(url('/admin/config')) ?>" novalidate>
  <?= csrf_field() ?>
  <div class="grid grid-2">
    <fieldset class="card">
      <legend>Factor weights (%) — must total 100</legend>
      <?php foreach ($factors as $f): ?>
        <div class="field row">
          <label for="w-<?= e($f->value) ?>" class="spacer"><?= e($f->label()) ?></label>
          <input type="number" step="0.01" min="0" max="100" id="w-<?= e($f->value) ?>" name="weights[<?= e($f->value) ?>]" value="<?= e((string) ($values['weights'][$f->value] ?? '')) ?>" data-weight>
        </div>
      <?php endforeach; ?>
      <p class="small">Total: <strong data-weight-total><?= e((string) array_sum(array_map('floatval', $values['weights'] ?? []))) ?></strong>%</p>
      <?= $err('weights') ?>
      <p class="hint">For vendor assessments, Legal basis and Vendor risk are left out and the rest are scaled to 100%. For DPIAs without data sharing, Vendor risk is left out the same way.</p>
    </fieldset>

    <div>
      <fieldset class="card">
        <legend>Rating bands (inherent score 1–5)</legend>
        <div class="field"><label for="low_max">Low up to and including</label><input type="number" step="0.01" id="low_max" name="bands[low_max]" value="<?= e((string) $values['bands']['low_max']) ?>"></div>
        <div class="field"><label for="medium_max">Medium up to and including</label><input type="number" step="0.01" id="medium_max" name="bands[medium_max]" value="<?= e((string) $values['bands']['medium_max']) ?>"></div>
        <p class="hint">Anything above the Medium limit is High.</p>
        <?= $err('bands') ?>
      </fieldset>
      <fieldset class="card">
        <legend>Control type reduction weights (0–1)</legend>
        <?php foreach ($categories as $c): ?>
          <div class="field row"><label class="spacer" for="r-<?= e($c->value) ?>"><?= e($c->label()) ?></label>
            <input type="number" step="0.01" min="0" max="1" id="r-<?= e($c->value) ?>" name="reduction_weights[<?= e($c->value) ?>]" value="<?= e((string) $values['reduction_weights'][$c->value]) ?>"></div>
        <?php endforeach; ?>
        <p class="hint">Used for the residual score indicator only. Technology must be the highest.</p>
        <?= $err('reduction_weights') ?>
      </fieldset>
      <fieldset class="card">
        <legend>Clearance and templates</legend>
        <div class="field"><label for="cme">Minimum effectiveness for a control to count (%)</label><input type="number" min="0" max="100" id="cme" name="clearance_min_effectiveness" value="<?= e((string) $values['clearance_min_effectiveness']) ?>"><?= $err('clearance_min_effectiveness') ?></div>
        <div class="field"><label for="tsm">Template is stale after (months)</label><input type="number" min="1" id="tsm" name="template_staleness_months" value="<?= e((string) $values['template_staleness_months']) ?>"><?= $err('template_staleness_months') ?></div>
        <div class="field"><label for="tst">Template signature match needs (of 3)</label><input type="number" min="1" max="3" id="tst" name="template_signature_threshold" value="<?= e((string) $values['template_signature_threshold']) ?>"><?= $err('template_signature_threshold') ?></div>
      </fieldset>
    </div>
  </div>

  <fieldset class="card">
    <legend>Review intervals and approval routing, by residual rating</legend>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Residual rating</th><th>Review every (months)</th><th>Approver roles (comma-separated role keys)</th></tr></thead>
        <tbody>
        <?php foreach (\App\Domain\Rating::displayOrder() as $r): ?>
          <tr>
            <td><?= rating_badge($r->value) ?></td>
            <td><label class="visually-hidden" for="ri-<?= $r->value ?>">Months</label><input type="number" min="1" id="ri-<?= $r->value ?>" name="review_interval_months[<?= $r->value ?>]" value="<?= e((string) $values['review_interval_months'][$r->value]) ?>"><?= $err('review_interval_months.' . $r->value) ?></td>
            <td><label class="visually-hidden" for="ar-<?= $r->value ?>">Roles</label><input type="text" id="ar-<?= $r->value ?>" name="approval_routing[<?= $r->value ?>]" value="<?= e(implode(', ', (array) $values['approval_routing'][$r->value])) ?>"><?= $err('approval_routing.' . $r->value) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint">Role keys: <?= e(implode(', ', array_map(fn ($r) => $r->value, $roles))) ?>.</p>
  </fieldset>

  <div class="card">
    <div class="field"><label for="change_note">Why are you changing this?</label><input type="text" id="change_note" name="change_note" maxlength="300"></div>
    <button class="btn" type="submit">Save as a new version</button>
  </div>
</form>

<div class="card">
  <h2>Version history</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Version</th><th>Active</th><th>By</th><th>When</th><th>Note</th></tr></thead>
    <tbody><?php foreach ($history as $h): ?>
      <tr><td><?= (int) $h['version'] ?></td><td><?= $h['active'] ? '<span class="tag tag-ok">active</span>' : '' ?></td><td><?= e($h['created_by']) ?></td><td class="nowrap"><?= fmt_datetime($h['created_at']) ?></td><td><?= e($h['change_note']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
