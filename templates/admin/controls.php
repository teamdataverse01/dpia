<?php /** @var array $controls @var \App\Engine\RiskTriggerEvaluator $triggers */ ?>
<div class="page-head">
  <h1>Control library</h1>
  <a class="btn" href="<?= e(url('/admin/controls/new')) ?>">New control</a>
</div>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>Type</th><th>Control</th><th>Recommended when</th><th>Mandatory</th><th>Verification</th><th>Active</th></tr></thead>
    <tbody>
    <?php foreach ($controls as $c): ?>
      <tr>
        <td><?= e(ucfirst($c['category'])) ?></td>
        <td><a href="<?= e(url('/admin/controls/' . (int) $c['id'])) ?>"><?= e($c['title']) ?></a></td>
        <td class="small"><?= e($triggers->describe($c['risk_trigger'])) ?><br><code><?= e($c['risk_trigger']) ?></code></td>
        <td><?= $c['mandatory'] ? 'Yes' : 'No' ?></td>
        <td><?= $c['verification_required'] ? 'Reviewer' : '—' ?></td>
        <td><?= $c['active'] ? 'Yes' : '<span class="tag">inactive</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
