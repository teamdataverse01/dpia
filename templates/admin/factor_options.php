<?php /** @var array $options @var array $factors */
$groups = array_merge(array_map(fn ($f) => [$f->value, $f->label()], $factors), [['data_type', 'Data types (recorded, not scored)']]);
?>
<h1>Factor options</h1>
<?php include __DIR__ . '/_nav.php'; ?>
<p class="note">Each answer maps to a risk score from 1 (lowest) to 5 (highest). Changing a score creates a new scoring framework version. Help text is shown to users under each answer — keep it to one plain sentence.</p>
<?php foreach ($groups as [$key, $label]): ?>
  <section class="card">
    <h2><?= e($label) ?></h2>
    <div class="table-wrap"><table>
      <thead><tr><th>Key</th><th>Label</th><th>Help text</th><th class="num">Score</th><th class="num">Order</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($options[$key] ?? [] as $o): $fid = 'opt-' . (int) $o['id']; ?>
        <tr id="<?= $fid ?>">
          <td><code><?= e($o['option_key']) ?></code></td>
          <td><input form="<?= $fid ?>-f" type="text" name="label" value="<?= e($o['label']) ?>" aria-label="Label for <?= e($o['option_key']) ?>"></td>
          <td><input form="<?= $fid ?>-f" type="text" name="help_text" maxlength="300" value="<?= e($o['help_text']) ?>" aria-label="Help text for <?= e($o['option_key']) ?>"></td>
          <td class="num"><input form="<?= $fid ?>-f" type="number" min="1" max="5" name="score" value="<?= (int) $o['score'] ?>" aria-label="Score for <?= e($o['option_key']) ?>"<?= $key === 'data_type' ? ' readonly' : '' ?>></td>
          <td class="num"><input form="<?= $fid ?>-f" type="number" name="sort_order" value="<?= (int) $o['sort_order'] ?>" aria-label="Order for <?= e($o['option_key']) ?>"></td>
          <td><form id="<?= $fid ?>-f" method="post" action="<?= e(url('/admin/factor-options/' . (int) $o['id'])) ?>"><?= csrf_field() ?><button class="btn btn-small" type="submit">Save</button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
<?php endforeach; ?>

<form class="card" method="post" action="<?= e(url('/admin/factor-options')) ?>">
  <?= csrf_field() ?>
  <h2>Add an option</h2>
  <div class="grid grid-3">
    <div class="field"><label for="n-factor">Factor</label><select id="n-factor" name="factor"><?php foreach ($groups as [$k, $l]): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="n-key">Key</label><input type="text" id="n-key" name="option_key" pattern="[a-z0-9_]+" placeholder="lowercase_with_underscores"></div>
    <div class="field"><label for="n-label">Label</label><input type="text" id="n-label" name="label"></div>
    <div class="field"><label for="n-help">Help text</label><input type="text" id="n-help" name="help_text" maxlength="300"></div>
    <div class="field"><label for="n-score">Score (1–5)</label><input type="number" id="n-score" name="score" min="1" max="5" value="3"></div>
    <div class="field"><label for="n-order">Order</label><input type="number" id="n-order" name="sort_order" value="99"></div>
  </div>
  <button class="btn" type="submit">Add option</button>
</form>
