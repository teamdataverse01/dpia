<?php
/** @var array $e @var array $values @var array $errors @var array $options @var array $vendors @var \App\Domain\EntityType $type */
include __DIR__ . '/../partials/entity_header.php';
$isDpia = $type === \App\Domain\EntityType::ProcessingActivity;
$disabled = !$canEditOwn;
$sharing = !empty($values['involves_data_sharing']) && $values['involves_data_sharing'] !== '0' && $values['involves_data_sharing'] !== 'false';
$vendorRef = (string) ($values['vendor_risk_ref'] ?? '');
?>
<div class="with-aside">
<form method="post" action="<?= e(url("/assessments/{$e['id']}/transfers")) ?>" data-preview-form data-entity-type="<?= e($e['entity_type']) ?>" data-entity-id="<?= (int) $e['id'] ?>" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="_js" value="0" data-js-flag>
  <?php foreach (['data_classification', 'data_volume', 'data_subject_type', 'nature_of_processing', 'automated_decision_making', 'legal_basis'] as $k): ?>
    <input type="hidden" data-preview-only name="<?= e($k) ?>" value="<?= e((string) ($values[$k] ?? '')) ?>">
  <?php endforeach; ?>
  <?php foreach ((array) ($values['data_type'] ?? []) as $t): ?>
    <input type="hidden" data-preview-only name="data_type[]" value="<?= e($t) ?>">
  <?php endforeach; ?>

  <div class="card">
    <h2>Third-party &amp; transfers</h2>
    <?php if (!$isDpia): ?>
      <p class="muted">Answer these about the vendor's own handling of CU data: where they store it and who they pass it to (their sub-processors).</p>
    <?php endif; ?>
    <?php
    $groups = [
        ['cross_border_transfer', term('Cross-border transfer', 'cross_border') . e(' — does the data leave Nigeria?'), 'Include storage in cloud data centres abroad.'],
        ['third_party_transfer', term('Third-party', 'third_party') . e(' transfer — who else receives the data?'), $isDpia ? '' : 'Count the vendor\'s own sub-processors.'],
    ];
    foreach ($groups as [$field, $legendHtml, $hint]) {
        include __DIR__ . '/../partials/option_group.php';
    }
    ?>

    <?php if ($isDpia): ?>
    <fieldset<?= isset($errors['involves_data_sharing']) ? ' aria-invalid="true"' : '' ?>>
      <legend>Does this activity involve <?= term('sharing data', 'data_sharing') ?> with an outside organisation?</legend>
      <p class="hint">If yes, the receiving vendor's own risk score becomes part of this DPIA's score (10% weight). If no, vendor risk is left out and the other weights are scaled up to 100%.</p>
      <div class="choices choices-inline">
        <label class="choice"><input type="radio" name="involves_data_sharing" value="1" data-sharing-toggle<?= checked($sharing) ?><?= $disabled ? ' disabled' : '' ?>><span><strong>Yes</strong></span></label>
        <label class="choice"><input type="radio" name="involves_data_sharing" value="0" data-sharing-toggle<?= checked(!$sharing) ?><?= $disabled ? ' disabled' : '' ?>><span><strong>No</strong></span></label>
      </div>
      <?= field_error($errors, 'involves_data_sharing') ?>
    </fieldset>

    <div class="field" data-vendor-picker>
      <label for="vendor_risk_ref">Which vendor receives the data?</label>
      <span class="hint" id="vendor-hint">Only used when the answer above is “Yes”. The vendor's <strong>current</strong> risk score is read each time this DPIA is scored. Vendors appear here once they have been risk-assessed.</span>
      <select id="vendor_risk_ref" name="vendor_risk_ref" aria-describedby="vendor-hint"<?= aria_err($errors, 'vendor_risk_ref') ?><?= $disabled ? ' disabled' : '' ?>>
        <option value="">— No vendor —</option>
        <?php foreach ($vendors as $v): ?>
          <option value="<?= (int) $v['id'] ?>"<?= selected($vendorRef, $v['id']) ?>>
            <?= e($v['name']) ?> — score <?= (int) $v['score100'] ?>/100 (<?= e(\App\Domain\Rating::from($v['residual_rating'] ?? $v['inherent_rating'])->label()) ?>) → factor <?= (int) $v['factor_score'] ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?= field_error($errors, 'vendor_risk_ref') ?>
      <?php if ($vendors === []): ?><p class="small muted">No vendors have been assessed yet. <a href="<?= e(url('/vendors/new')) ?>">Start a vendor assessment</a>.</p><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="form-actions">
    <a class="btn btn-secondary" href="<?= e(url("/assessments/{$e['id']}/factors")) ?>">Back</a>
    <?php if ($canEditOwn): ?><button class="btn" type="submit">Save and calculate inherent risk</button><?php endif; ?>
  </div>
</form>
<?php if ($canEditOwn) { include __DIR__ . '/../partials/preview_box.php'; } ?>
</div>
