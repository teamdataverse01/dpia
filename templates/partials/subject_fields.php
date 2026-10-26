<?php
/** @var \App\Domain\EntityType $type @var array $old @var array $errors @var array $departments */
$isVendor = $type === \App\Domain\EntityType::Vendor;
$v = fn (string $k) => $old[$k] ?? '';
$dis = !empty($readonly) ? ' disabled' : '';
?>
<div class="field">
  <label for="name"><?= $isVendor ? 'Vendor name' : 'Name of the processing activity' ?></label>
  <span class="hint" id="name-hint"><?= $isVendor ? 'The company as CU knows it, e.g. “Paystack”.' : 'A short name people will recognise, e.g. “Processing Student Applications”.' ?></span>
  <input type="text" id="name" name="name" maxlength="200" required value="<?= e($v('name')) ?>"<?= aria_err($errors, 'name') ?><?= $dis ?>>
  <?= field_error($errors, 'name') ?>
</div>
<div class="field">
  <label for="department_id">Responsible department</label>
  <select id="department_id" name="department_id" required<?= aria_err($errors, 'department_id') ?><?= $dis ?>>
    <option value="">Choose…</option>
    <?php foreach ($departments as $d): ?>
      <option value="<?= (int) $d['id'] ?>"<?= selected($v('department_id'), $d['id']) ?>><?= e($d['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <?= field_error($errors, 'department_id') ?>
</div>
<?php if ($isVendor): ?>
  <div class="field">
    <label for="legal_name">Legal name <span class="muted">(optional)</span></label>
    <input type="text" id="legal_name" name="legal_name" maxlength="200" value="<?= e($v('legal_name')) ?>"<?= $dis ?>>
  </div>
  <div class="field">
    <label for="service_description">What service does the vendor provide?</label>
    <span class="hint">Explain in plain words what they do for CU and what CU data they touch.</span>
    <textarea id="service_description" name="service_description" required<?= aria_err($errors, 'service_description') ?><?= $dis ?>><?= e($v('service_description')) ?></textarea>
    <?= field_error($errors, 'service_description') ?>
  </div>
  <div class="grid grid-2">
    <div class="field">
      <label for="contract_start">Contract start <span class="muted">(optional)</span></label>
      <input type="date" id="contract_start" name="contract_start" value="<?= e(substr((string) $v('contract_start'), 0, 10)) ?>"<?= aria_err($errors, 'contract_start') ?><?= $dis ?>>
      <?= field_error($errors, 'contract_start') ?>
    </div>
    <div class="field">
      <label for="contract_end">Contract end <span class="muted">(optional)</span></label>
      <input type="date" id="contract_end" name="contract_end" value="<?= e(substr((string) $v('contract_end'), 0, 10)) ?>"<?= aria_err($errors, 'contract_end') ?><?= $dis ?>>
      <?= field_error($errors, 'contract_end') ?>
    </div>
  </div>
  <div class="field">
    <label for="subprocessors">Sub-processors <span class="muted">(optional)</span></label>
    <span class="hint">Other companies the vendor passes CU data to.</span>
    <textarea id="subprocessors" name="subprocessors"<?= $dis ?>><?= e($v('subprocessors')) ?></textarea>
  </div>
  <div class="grid grid-2">
    <div class="field">
      <label for="contact_name">Vendor contact name <span class="muted">(optional)</span></label>
      <input type="text" id="contact_name" name="contact_name" maxlength="150" value="<?= e($v('contact_name')) ?>"<?= $dis ?>>
    </div>
    <div class="field">
      <label for="contact_email">Vendor contact e-mail <span class="muted">(optional)</span></label>
      <input type="email" id="contact_email" name="contact_email" maxlength="200" value="<?= e($v('contact_email')) ?>"<?= aria_err($errors, 'contact_email') ?><?= $dis ?>>
      <?= field_error($errors, 'contact_email') ?>
    </div>
  </div>
  <div class="field">
    <label for="vendor_portal_user_id">Vendor's portal user ID <span class="muted">(optional)</span></label>
    <span class="hint">Only if the vendor has a CU portal account. It lets them update this vendor record (Vendor role).</span>
    <input type="text" id="vendor_portal_user_id" name="vendor_portal_user_id" maxlength="100" value="<?= e($v('vendor_portal_user_id')) ?>"<?= $dis ?>>
  </div>
<?php else: ?>
  <div class="field">
    <label for="purpose">Why does CU need to do this?</label>
    <span class="hint">The purpose of processing the data.</span>
    <textarea id="purpose" name="purpose" required<?= aria_err($errors, 'purpose') ?><?= $dis ?>><?= e($v('purpose')) ?></textarea>
    <?= field_error($errors, 'purpose') ?>
  </div>
  <div class="field">
    <label for="description">What happens to the data?</label>
    <span class="hint">Describe the steps: where the data comes from, who uses it, where it goes.</span>
    <textarea id="description" name="description" required<?= aria_err($errors, 'description') ?><?= $dis ?>><?= e($v('description')) ?></textarea>
    <?= field_error($errors, 'description') ?>
  </div>
  <div class="field">
    <label for="retention_period">How long is the data kept? <span class="muted">(optional)</span></label>
    <input type="text" id="retention_period" name="retention_period" maxlength="100" value="<?= e($v('retention_period')) ?>"<?= $dis ?>>
  </div>
<?php endif; ?>
