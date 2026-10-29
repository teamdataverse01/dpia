<?php /** @var array $filters @var array $summary @var array $rows @var array $departments */
$base = array_intersect_key($filters, array_flip(['department_id', 'entity_type']));
$return = $_SERVER['REQUEST_URI'] ?? '/remediation';
$return = '/' . ltrim(substr($return, strlen(url(''))), '/');
?>
<div class="page-head">
  <div>
    <h1>Remediation tracker</h1>
    <p class="muted">Every fix still outstanding across the university, and who owns it. Owners record their fixes; an action closes by itself once its control is fixed and verified. Use this page to spot overdue work and chase it.</p>
  </div>
</div>
<div class="tiles">
  <?php foreach (['open_all' => ['Open (all)', $summary['open'] + $summary['in_progress'] + $summary['overdue']], 'overdue' => ['Overdue', $summary['overdue']], 'in_progress' => ['In progress', $summary['in_progress']], 'completed' => ['Completed', $summary['completed']]] as $k => [$lbl, $n]): ?>
    <a class="tile<?= $k === 'overdue' ? ' tile-alert' : '' ?>" href="<?= e(url('/remediation', $base + ['status' => $k])) ?>"<?= ($filters['status'] ?? '') === $k ? ' aria-current="true"' : '' ?>><div class="n"><?= (int) $n ?></div><div class="lbl"><?= e($lbl) ?></div></a>
  <?php endforeach; ?>
</div>
<form class="card filters" method="get" action="<?= e(url('/remediation')) ?>" data-auto-submit>
  <div class="field"><label for="r-status">Status</label>
    <select id="r-status" name="status">
      <option value="">Any</option>
      <?php foreach (['open_all' => 'Open (all)', 'open' => 'Open', 'in_progress' => 'In progress', 'overdue' => 'Overdue', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $k => $l): ?>
        <option value="<?= $k ?>"<?= selected($filters['status'] ?? '', $k) ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select></div>
  <div class="field"><label for="r-dept">Department</label>
    <select id="r-dept" name="department_id"><option value="">All</option>
      <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"<?= selected($filters['department_id'] ?? '', $d['id']) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div class="field"><label for="r-prio">Priority</label>
    <select id="r-prio" name="priority"><option value="">Any</option>
      <?php foreach (['high', 'medium', 'low'] as $p): ?><option value="<?= $p ?>"<?= selected($filters['priority'] ?? '', $p) ?>><?= ucfirst($p) ?></option><?php endforeach; ?>
    </select></div>
  <label class="choice"><input type="checkbox" name="mine" value="1"<?= checked(!empty($filters['owner'])) ?>><span>Only my actions</span></label>
  <button class="btn" type="submit">Apply</button>
</form>
<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Action</th><th>Linked risk</th><th>Department</th><th>Owner</th><th>Priority</th><th>Due</th><th>Status</th><th>Next step</th></tr></thead>
      <tbody>
      <?php if ($rows === []): ?><tr><td colspan="8" class="muted">No actions match.</td></tr><?php endif; ?>
      <?php foreach ($rows as $a): ?>
        <tr<?= $a['display_status'] === 'overdue' ? ' class="highlight"' : '' ?>>
          <td><?= e($a['control_title'] ?? $a['gap_description']) ?><?php if ($a['control_title']): ?><div class="muted small"><?= e($a['gap_description']) ?></div><?php endif; ?></td>
          <td><a href="<?= e(url('/assessments/' . (int) $a['risk_entity_id'] . '/remediation')) ?>"><?= e($a['entity_name']) ?></a> <?= rating_badge($a['residual_rating']) ?></td>
          <td><?= e($a['department_name'] ?? '—') ?></td>
          <td><?= e($a['owner_name']) ?></td>
          <td><?= e(ucfirst($a['priority'])) ?></td>
          <td class="nowrap"><?= fmt_date($a['target_date']) ?></td>
          <td><?php include __DIR__ . '/../partials/remediation_status.php'; ?></td>
          <td><?php include __DIR__ . '/../partials/action_controls.php'; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
