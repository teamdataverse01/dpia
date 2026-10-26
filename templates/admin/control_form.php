<?php /** @var ?array $control @var array $old @var array $errors @var array $categories @var array $roles */
$v = fn (string $k) => $old[$k] ?? '';
$flag = fn (string $k) => !empty($old[$k]) && $old[$k] !== '0';
?>
<h1><?= $control ? 'Edit control' : 'New control' ?></h1>
<?php include __DIR__ . '/_nav.php'; ?>
<form class="card" method="post" action="<?= e(url($control ? '/admin/controls/' . (int) $control['id'] : '/admin/controls')) ?>" novalidate>
  <?= csrf_field() ?>
  <div class="grid grid-2">
    <div class="field"><label for="category">Type</label>
      <select id="category" name="category"<?= aria_err($errors, 'category') ?>><option value="">Choose…</option>
        <?php foreach ($categories as $c): ?><option value="<?= e($c->value) ?>"<?= selected($v('category'), $c->value) ?>><?= e($c->label()) ?></option><?php endforeach; ?>
      </select><?= field_error($errors, 'category') ?></div>
    <div class="field"><label for="title">Title</label><input type="text" id="title" name="title" maxlength="200" value="<?= e($v('title')) ?>"<?= aria_err($errors, 'title') ?>><?= field_error($errors, 'title') ?></div>
  </div>
  <div class="field"><label for="description">Description</label><textarea id="description" name="description"<?= aria_err($errors, 'description') ?>><?= e($v('description')) ?></textarea><?= field_error($errors, 'description') ?></div>
  <div class="field">
    <label for="risk_trigger">Recommend when (rule)</label>
    <span class="hint">Examples: <code>always</code> · <code>data_classification>=4</code> · <code>cross_border_transfer>=3 | involves_data_sharing=true</code> · <code>entity_type=vendor</code> · <code>inherent_rating>=medium</code> · <code>data_type~health</code>. Use <code>|</code> for OR and <code>&amp;</code> for AND.</span>
    <input type="text" id="risk_trigger" name="risk_trigger" maxlength="200" value="<?= e($v('risk_trigger')) ?>"<?= aria_err($errors, 'risk_trigger') ?>><?= field_error($errors, 'risk_trigger') ?>
  </div>
  <div class="field"><label for="expected_evidence">Evidence expected</label><textarea id="expected_evidence" name="expected_evidence"><?= e($v('expected_evidence')) ?></textarea></div>
  <div class="field"><label for="owner_role">Usual owner role</label>
    <select id="owner_role" name="owner_role"><option value="">None</option>
      <?php foreach ($roles as $r): ?><option value="<?= e($r->value) ?>"<?= selected($v('owner_role'), $r->value) ?>><?= e($r->label()) ?></option><?php endforeach; ?>
    </select><?= field_error($errors, 'owner_role') ?></div>
  <div class="choices choices-inline">
    <label class="choice"><input type="checkbox" name="mandatory" value="1"<?= checked($flag('mandatory')) ?>><span><strong>Mandatory</strong><span class="muted small">Must be met for its type to clear.</span></span></label>
    <label class="choice"><input type="checkbox" name="verification_required" value="1"<?= checked($flag('verification_required')) ?>><span><strong>Needs Reviewer verification</strong></span></label>
    <label class="choice"><input type="checkbox" name="active" value="1"<?= checked($flag('active')) ?>><span><strong>Active</strong><span class="muted small">Inactive controls are no longer recommended.</span></span></label>
  </div>
  <div class="form-actions"><button class="btn" type="submit">Save control</button><a href="<?= e(url('/admin/controls')) ?>">Cancel</a></div>
</form>
