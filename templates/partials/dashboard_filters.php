<?php /** @var array $filters @var array $departments @var string $action */ ?>
<form class="card filters" method="get" action="<?= e(url($action)) ?>" data-auto-submit>
  <div class="field">
    <label for="f-type">Type</label>
    <select id="f-type" name="entity_type">
      <option value="">Vendors and DPIAs</option>
      <option value="vendor"<?= selected($filters['entity_type'] ?? '', 'vendor') ?>>Vendors only</option>
      <option value="processing_activity"<?= selected($filters['entity_type'] ?? '', 'processing_activity') ?>>DPIAs only</option>
    </select>
  </div>
  <div class="field">
    <label for="f-dept">Department</label>
    <select id="f-dept" name="department_id">
      <option value="">All departments</option>
      <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"<?= selected($filters['department_id'] ?? '', $d['id']) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="f-from">Created from</label>
    <input type="date" id="f-from" name="from" value="<?= e($filters['from'] ?? '') ?>">
  </div>
  <div class="field">
    <label for="f-to">to</label>
    <input type="date" id="f-to" name="to" value="<?= e($filters['to'] ?? '') ?>">
  </div>
  <input type="hidden" name="basis" value="<?= e($filters['basis'] ?? 'residual') ?>">
  <?php foreach (($extraHidden ?? []) as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
  <button class="btn" type="submit">Apply</button>
  <a href="<?= e(url($action)) ?>">Clear</a>
</form>
