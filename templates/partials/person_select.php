<?php
/**
 * Pick a person (instead of typing a portal user ID).
 * @var array $people from PeopleDirectory::all() @var string $selName @var string $selId @var ?string $selValue @var bool $selRequired
 */
?>
<select id="<?= e($selId) ?>" name="<?= e($selName) ?>"<?= !empty($selRequired) ? ' required' : '' ?>>
  <option value=""><?= !empty($selRequired) ? 'Choose a person…' : 'Nobody' ?></option>
  <?php foreach ($people as $p): ?>
    <option value="<?= e($p['id']) ?>"<?= selected($selValue ?? '', $p['id']) ?>><?= e($p['label']) ?></option>
  <?php endforeach; ?>
</select>
