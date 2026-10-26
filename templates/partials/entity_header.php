<?php
/** @var array $e @var string $step @var \App\Domain\EntityType $type */
use App\Controllers\Web\WizardController;

$id = (int) $e['id'];
?>
<div class="entity-head">
  <h1><?= e($e['name']) ?></h1>
  <span class="tag"><?= $type === \App\Domain\EntityType::Vendor ? 'Vendor risk assessment' : term('DPIA', 'dpia') ?></span>
  <?= status_badge($e['status']) ?>
  <?php if ($e['inherent_rating']): ?>
    <span class="small"><?= term('Inherent', 'inherent') ?>: <?= rating_badge($e['inherent_rating']) ?></span>
    <span class="small"><?= term('Residual', 'residual') ?>: <?= rating_badge($e['residual_rating']) ?></span>
  <?php endif; ?>
</div>
<p class="muted small">
  <?= e($e['department_name'] ?? 'No department') ?> · Owner <?= e($e['owner_portal_user_id']) ?>
  <?php if ($e['template_name']): ?> · From template “<?= e($e['template_name']) ?>”<?= $e['customised'] ? ' (customised)' : '' ?><?php endif; ?>
</p>
<nav aria-label="Assessment stages">
  <ol class="stepper">
    <?php foreach (WizardController::STEPS as $key => $label): ?>
      <li><a href="<?= e(url("/assessments/{$id}/{$key}")) ?>"<?= $key === $step ? ' aria-current="step"' : '' ?>><?= e($label) ?></a></li>
    <?php endforeach; ?>
  </ol>
</nav>
<?php if (!$editable && in_array($step, ['subject', 'factors', 'transfers', 'controls', 'remediation'], true)): ?>
  <p class="note">This assessment is “<?= e(\App\Domain\AssessmentStatus::from($e['status'])->label()) ?>”, so this stage is read-only.
  <?= $e['status'] === 'pending_approval' ? 'Wait for the approval decision.' : 'Start a reassessment from the Monitoring stage to make changes.' ?></p>
<?php endif; ?>
