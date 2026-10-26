<?php /** @var array $templates @var bool $canRescore @var ?array $rescored @var int $currentVersion */ ?>
<div class="page-head">
  <div>
    <h1>Activity templates</h1>
    <p class="muted">Pre-scored recurring activities used by the DPIA fast path. Current scoring framework: version <?= (int) $currentVersion ?>.</p>
  </div>
  <?php if ($canRescore): ?>
    <form method="post" action="<?= e(url('/templates/rescore')) ?>"><?= csrf_field() ?><button class="btn btn-secondary" type="submit">Re-score all templates</button></form>
  <?php endif; ?>
</div>

<?php if ($rescored): ?>
  <div class="card">
    <h2>Re-score results</h2>
    <ul><?php foreach ($rescored as $r): ?><li><?= e($r['name']) ?>: <?= e($r['old']) ?> → <?= e($r['new']) ?><?= $r['error'] ? ' — ' . e($r['error']) : '' ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Activity</th><th>Department</th><th>Inherent</th><th>Data sharing</th><th>Framework</th><th>Last reviewed</th></tr></thead>
      <tbody>
      <?php foreach ($templates as $t): ?>
        <tr>
          <td><a href="<?= e(url('/templates/' . (int) $t['id'])) ?>"><?= e($t['activity_name']) ?></a></td>
          <td><?= e($t['department_name']) ?></td>
          <td><?= rating_badge($t['inherent_rating']) ?> <?= e(number_format((float) $t['inherent_score'], 2)) ?></td>
          <td><?= $t['involves_data_sharing'] ? 'Yes — ' . e($t['vendor_name'] ?? '') : 'No' ?></td>
          <td>v<?= (int) $t['framework_version'] ?><?= (int) $t['framework_version'] !== $currentVersion ? ' <span class="tag tag-bad">old</span>' : '' ?></td>
          <td class="nowrap"><?= fmt_date($t['last_reviewed_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
