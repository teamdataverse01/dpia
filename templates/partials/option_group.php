<?php
/**
 * Radio group for one factor.
 * @var string $field @var string $legendHtml (already escaped) @var string $hint @var array $options @var array $values @var array $errors @var bool $disabled
 */
$current = (string) ($values[$field] ?? '');
?>
<fieldset<?= isset($errors[$field]) ? ' aria-invalid="true" aria-describedby="err-' . e($field) . '"' : '' ?>>
  <legend><?= $legendHtml ?></legend>
  <?php if ($hint !== ''): ?><p class="hint"><?= e($hint) ?></p><?php endif; ?>
  <div class="choices">
    <?php foreach ($options[$field] ?? [] as $o): ?>
      <label class="choice">
        <input type="radio" name="<?= e($field) ?>" value="<?= e($o['option_key']) ?>"<?= checked($current === $o['option_key']) ?><?= $disabled ? ' disabled' : '' ?>>
        <span><strong><?= e($o['label']) ?></strong><?php if ($o['help_text']): ?><span class="muted small"><?= e($o['help_text']) ?></span><?php endif; ?></span>
        <span class="score" title="Risk score for this answer (1 lowest – 5 highest)">score <?= (int) $o['score'] ?></span>
      </label>
    <?php endforeach; ?>
  </div>
  <?= field_error($errors, $field) ?>
</fieldset>
