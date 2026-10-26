<?php
/**
 * @var array $tpl @var array $factors @var bool $stale @var array $stale_reasons @var ?\App\Engine\InherentRiskResult $live
 * @var ?string $live_error @var array $controls @var bool $canStart @var array $errors @var array $old @var callable $optionLabel @var string $why
 */
$t = $tpl;
$sharing = (bool) $t['involves_data_sharing'];
$v = fn (string $k, string $d = '') => $old[$k] ?? $d;
?>
<div class="page-head">
  <div>
    <p class="muted small"><a href="<?= e(url('/dpias/new')) ?>">← Templates</a></p>
    <h1><?= e($t['activity_name']) ?></h1>
    <p class="muted"><?= e($t['department_name']) ?> · pre-scored recurring activity · framework v<?= (int) $t['framework_version'] ?> · last reviewed <?= fmt_date($t['last_reviewed_at']) ?></p>
  </div>
</div>

<?php if ($why !== ''): ?><p class="note"><strong>Why this template:</strong> <?= e($why) ?></p><?php endif; ?>

<?php if ($stale): ?>
  <div class="warn" role="alert">
    <strong>This template may be out of date.</strong>
    <ul><?php foreach ($stale_reasons as $r): ?><li><?= e($r) ?></li><?php endforeach; ?></ul>
    You can still use it, but confirm below that you have checked it still applies.
  </div>
<?php endif; ?>

<div class="grid grid-2">
  <div class="card">
    <h2><?= term('Inherent risk', 'inherent') ?></h2>
    <?php if ($live !== null): ?>
      <div class="score-hero">
        <div class="score-number"><?= e(number_format($live->score, 2)) ?> <small>out of 5</small></div>
        <?= rating_badge($live->rating->value) ?>
      </div>
      <?php if (abs($live->score - (float) $t['inherent_score']) >= 0.005): ?>
        <p class="small warn">The template was saved with <?= e(number_format((float) $t['inherent_score'], 2)) ?>. The score above uses today's framework<?= $sharing ? ' and the vendor\'s current score' : '' ?>.</p>
      <?php endif; ?>
    <?php else: ?>
      <div class="score-hero"><div class="score-number"><?= e(number_format((float) $t['inherent_score'], 2)) ?></div><?= rating_badge($t['inherent_rating']) ?></div>
      <?php if ($live_error): ?><p class="warn small"><?= e($live_error) ?></p><?php endif; ?>
    <?php endif; ?>
    <?php if ($sharing): ?>
      <p class="small">Shares data with <strong><?= e($t['vendor_name'] ?? 'a vendor') ?></strong>; that vendor's <em>current</em> risk score is read whenever this DPIA is scored.</p>
    <?php else: ?>
      <p class="small">Does not involve data sharing, so vendor risk is not part of the score.</p>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>Answers this template fills in</h2>
    <dl class="kv small">
      <dt>Data types</dt><dd><?= e(implode(', ', array_map(fn ($k) => $optionLabel('data_type', $k), (array) $factors['data_type']))) ?></dd>
      <?php foreach (\App\Services\FactorCatalog::OPTION_FIELDS as $f): ?>
        <dt><?= e(\App\Domain\Factor::from($f)->label()) ?></dt><dd><?= e($optionLabel($f, $factors[$f] ?? null)) ?></dd>
      <?php endforeach; ?>
    </dl>
  </div>
</div>

<div class="card">
  <h2>Standard <?= term('controls', 'control') ?></h2>
  <ul>
    <?php foreach ($controls as $c): ?><li><span class="tag"><?= e(ucfirst($c['category'])) ?></span> <?= e($c['title']) ?></li><?php endforeach; ?>
  </ul>
</div>

<?php if ($canStart): ?>
<form class="card" method="post" action="<?= e(url('/templates/' . (int) $t['id'] . '/start')) ?>" novalidate>
  <?= csrf_field() ?>
  <h2>Start the DPIA from this template</h2>
  <?php if (isset($errors['form'])): ?><p class="field-error"><?= e($errors['form']) ?></p><?php endif; ?>
  <div class="field">
    <label for="name">Name of this DPIA</label>
    <input type="text" id="name" name="name" maxlength="200" value="<?= e($v('name', $t['activity_name'])) ?>"<?= aria_err($errors, 'name') ?>>
    <?= field_error($errors, 'name') ?>
  </div>
  <div class="field">
    <label for="purpose">Why does CU need to do this?</label>
    <textarea id="purpose" name="purpose" required<?= aria_err($errors, 'purpose') ?>><?= e($v('purpose')) ?></textarea>
    <?= field_error($errors, 'purpose') ?>
  </div>
  <div class="field">
    <label for="description">What happens to the data?</label>
    <textarea id="description" name="description" required<?= aria_err($errors, 'description') ?>><?= e($v('description')) ?></textarea>
    <?= field_error($errors, 'description') ?>
  </div>
  <div class="field">
    <label for="retention_period">How long is the data kept? <span class="muted">(optional)</span></label>
    <input type="text" id="retention_period" name="retention_period" maxlength="100" value="<?= e($v('retention_period')) ?>">
  </div>
  <?php if ($stale): ?>
    <label class="choice"><input type="checkbox" name="confirm_stale" value="1"<?= checked(!empty($old['confirm_stale'])) ?>><span><strong>I have checked that this template still applies to this activity.</strong></span></label>
    <?= field_error($errors, 'confirm_stale') ?>
  <?php endif; ?>
  <?= field_error($errors, 'department_id') ?>
  <div class="form-actions">
    <button class="btn" type="submit" name="mode" value="accept">Accept — go straight to the control assessment</button>
    <button class="btn btn-secondary" type="submit" name="mode" value="customise">Customise — review and change the answers first</button>
  </div>
  <p class="small muted">Accept keeps the template's answers and prefills its controls. Customise lets you change answers; the DPIA is then marked “customised” and rescored.</p>
</form>
<?php endif; ?>
