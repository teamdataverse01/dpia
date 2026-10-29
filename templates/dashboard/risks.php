<?php /** @var array $filters @var array $rows @var array $departments @var array $statuses */
$basis = $filters['basis'] ?? 'residual';
$describe = [];
if (!empty($filters['rating'])) {
    $describe[] = \App\Domain\Rating::from($filters['rating'])->label() . ' ' . $basis . ' risk';
}
if (!empty($filters['factor'])) {
    $describe[] = '“' . \App\Domain\Factor::from($filters['factor'])->label() . '” scored 4 or 5';
}
if (!empty($filters['cleared'])) {
    $describe[] = $filters['cleared'] === 'none' ? 'no control types cleared' : 'only ' . str_replace(',', ' + ', $filters['cleared']) . ' controls cleared';
}
$action = '/risks';
$extraHidden = array_intersect_key($filters, array_flip(['rating', 'factor', 'cleared', 'status']));
?>
<div class="page-head">
  <div>
    <h1>Risks</h1>
    <?php $scored = count(array_filter($rows, fn ($r) => $r['inherent_rating'] !== null)); $drafts = count($rows) - $scored; ?>
    <p class="muted"><?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?> · <?= $scored ?> scored<?= $drafts ? ' · ' . $drafts . ' not scored yet (draft)' : '' ?><?= $describe ? ' · ' . e(implode(' · ', $describe)) : '' ?></p>
    <?php if ($drafts): ?><p class="muted small">The dashboard counts only scored records, so it can show fewer than this list. Each scored record has two ratings — inherent (before safeguards) and residual (after) — it is still one record.</p><?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../partials/dashboard_filters.php'; ?>
<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Type</th><th>Department</th><th>Status</th><th>Inherent</th><th>Residual</th><th>Updated</th></tr></thead>
      <tbody>
      <?php if ($rows === []): ?><tr><td colspan="7" class="muted">Nothing matches.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><a href="<?= e(url('/assessments/' . (int) $r['id'])) ?>"><?= e($r['name']) ?></a></td>
          <td><?= $r['entity_type'] === 'vendor' ? 'Vendor' : 'DPIA' ?></td>
          <td><?= e($r['department_name'] ?? '—') ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td><?= rating_badge($r['inherent_rating']) ?></td>
          <td><?= rating_badge($r['residual_rating']) ?></td>
          <td class="nowrap"><?= fmt_date($r['updated_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
