<?php
/** @var \App\Domain\EntityType $type */
$isVendor = $type === \App\Domain\EntityType::Vendor;
?>
<div class="page-head">
  <div>
    <h1><?= $isVendor ? 'New vendor risk assessment' : 'New ' . term('DPIA', 'dpia') ?></h1>
    <p class="muted">Step 1 of 10 · <?= $isVendor ? 'Tell us about the vendor.' : 'Describe the processing activity.' ?></p>
  </div>
  <?php if (!$isVendor): ?><a class="btn btn-secondary" href="<?= e(url('/dpias/new')) ?>">Check for a ready-made template first</a><?php endif; ?>
</div>
<?php if ($isVendor): ?>
  <p class="note">A vendor (<?= term('third party', 'third_party') ?>) is an outside company that handles data for CU. This assessment checks how risky that company is with CU's data before or while CU works with it.</p>
<?php endif; ?>
<form class="card" method="post" action="<?= e(url($isVendor ? '/vendors' : '/dpias')) ?>" novalidate>
  <?= csrf_field() ?>
  <?php include __DIR__ . '/../partials/subject_fields.php'; ?>
  <div class="form-actions">
    <button class="btn" type="submit">Save and continue</button>
    <a href="<?= e(url('/dashboard')) ?>">Cancel</a>
  </div>
</form>
