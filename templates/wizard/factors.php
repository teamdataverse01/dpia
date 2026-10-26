<?php
/** @var array $e @var array $values @var array $errors @var array $options @var \App\Domain\EntityType $type */
include __DIR__ . '/../partials/entity_header.php';
$isDpia = $type === \App\Domain\EntityType::ProcessingActivity;
$disabled = !$canEditOwn;
$types = (array) ($values['data_type'] ?? []);
?>
<div class="with-aside">
<form method="post" action="<?= e(url("/assessments/{$e['id']}/factors")) ?>" data-preview-form data-entity-type="<?= e($e['entity_type']) ?>" data-entity-id="<?= (int) $e['id'] ?>" novalidate>
  <?= csrf_field() ?>
  <?php // the other screen's current answers, so the live preview can score the whole panel ?>
  <?php foreach (['cross_border_transfer', 'third_party_transfer'] as $k): ?>
    <input type="hidden" data-preview-only name="<?= e($k) ?>" value="<?= e((string) ($values[$k] ?? '')) ?>">
  <?php endforeach; ?>
  <?php if ($isDpia): ?>
    <input type="hidden" data-preview-only name="involves_data_sharing" value="<?= !empty($values['involves_data_sharing']) ? '1' : '0' ?>">
    <input type="hidden" data-preview-only name="vendor_risk_ref" value="<?= e((string) ($values['vendor_risk_ref'] ?? '')) ?>">
  <?php endif; ?>

  <div class="card">
    <h2>Risk factors</h2>
    <p class="muted">These answers are the same for vendor assessments and DPIAs so that every risk is scored the same way. Each answer has a risk score from 1 (lowest) to 5 (highest).</p>

    <fieldset<?= isset($errors['data_type']) ? ' aria-invalid="true" aria-describedby="err-data_type"' : '' ?>>
      <legend>What types of personal data are involved?</legend>
      <p class="hint">Tick all that apply. This is recorded for the risk picture and template matching; it does not change the score by itself.</p>
      <div class="choices choices-inline">
        <?php foreach ($options['data_type'] ?? [] as $o): ?>
          <label class="choice">
            <input type="checkbox" name="data_type[]" value="<?= e($o['option_key']) ?>"<?= checked(in_array($o['option_key'], $types, true)) ?><?= $disabled ? ' disabled' : '' ?>>
            <span><strong><?= e($o['label']) ?></strong><span class="muted small"><?= e($o['help_text']) ?></span></span>
          </label>
        <?php endforeach; ?>
      </div>
      <?= field_error($errors, 'data_type') ?>
    </fieldset>

    <?php
    $groups = [
        ['data_classification', e('How sensitive is the data?'), 'Choose the most sensitive data involved.'],
        ['data_volume', e('How many people\'s data is involved?'), 'Count the individuals (data subjects), not the records.'],
        ['data_subject_type', e('Whose data is it?'), 'Choose the most vulnerable group involved.'],
        ['nature_of_processing', e('What kind of processing is it?'), ''],
        ['automated_decision_making', e('Does a computer make decisions about people?'), $isDpia ? '' : 'Answer for any automation the vendor itself performs.'],
    ];
    if ($isDpia) {
        $groups[] = ['legal_basis', term('Legal basis', 'legal_basis') . e(' — what lawful reason does CU rely on?'), 'Not asked for vendor assessments: each activity supplies its own legal basis.'];
    }
    foreach ($groups as [$field, $legendHtml, $hint]) {
        include __DIR__ . '/../partials/option_group.php';
    }
    ?>
  </div>
  <div class="form-actions">
    <?php if ($canEditOwn): ?><button class="btn" type="submit">Save and continue to third-party &amp; transfers</button><?php endif; ?>
    <a href="<?= e(url("/assessments/{$e['id']}/transfers")) ?>">Go to third-party &amp; transfers</a>
  </div>
</form>
<?php if ($canEditOwn) { include __DIR__ . '/../partials/preview_box.php'; } ?>
</div>
