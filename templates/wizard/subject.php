<?php include __DIR__ . '/../partials/entity_header.php'; ?>
<form class="card" method="post" action="<?= e(url("/assessments/{$e['id']}/subject")) ?>" novalidate>
  <h2>Subject</h2>
  <?= csrf_field() ?>
  <?php $readonly = !$canEditOwn; include __DIR__ . '/../partials/subject_fields.php'; ?>
  <div class="form-actions">
    <?php if ($canEditOwn): ?><button class="btn" type="submit">Save and continue</button><?php endif; ?>
    <a href="<?= e(url("/assessments/{$e['id']}/factors")) ?>">Go to risk factors</a>
  </div>
</form>
