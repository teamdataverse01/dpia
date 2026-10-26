<?php /** Inline status/progress update for one remediation action. @var array $a @var string $return */ ?>
<form class="row" method="post" action="<?= e(url('/remediation/' . (int) $a['id'])) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="return" value="<?= e($return) ?>">
  <label class="visually-hidden" for="st-<?= (int) $a['id'] ?>">Status</label>
  <select id="st-<?= (int) $a['id'] ?>" name="status">
    <?php foreach (\App\Domain\RemediationStatus::cases() as $s): ?>
      <option value="<?= e($s->value) ?>"<?= selected($a['status'], $s->value) ?>><?= e($s->label()) ?></option>
    <?php endforeach; ?>
  </select>
  <label class="visually-hidden" for="pc-<?= (int) $a['id'] ?>">Completion %</label>
  <input type="number" id="pc-<?= (int) $a['id'] ?>" name="completion_pct" min="0" max="100" value="<?= (int) $a['completion_pct'] ?>">
  <button class="btn btn-small btn-secondary" type="submit">Update</button>
</form>
