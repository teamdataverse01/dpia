<?php /** @var array $filters @var array $summary @var array $rows @var array $departments @var array $unowned */
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
  <?php $unownedMandatory = array_sum(array_column($unowned, 'mandatory')); ?>
  <?php if (!isset($filters['owner'])): ?>
    <a class="tile<?= $unownedMandatory ? ' tile-alert' : '' ?>" href="#unowned"><div class="n"><?= (int) $unownedMandatory ?></div><div class="lbl">Mandatory gaps nobody owns</div></a>
  <?php endif; ?>
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

<?php if (!isset($filters['owner'])): ?>
<section class="card" id="unowned" aria-labelledby="unowned-h">
  <h2 id="unowned-h">Gaps nobody owns yet</h2>
  <p class="muted small">Recommended safeguards that are not in place and that nobody has been assigned to fix. A <strong>mandatory</strong> gap stops its control type (Policy, Process or Technology) from being cleared, so it keeps the residual risk from coming down; optional gaps help but never block it. Only records still being assessed (or due for review soon) are listed — approved records have had their remaining gaps accepted.</p>
  <?php if ($unowned === []): ?>
    <p class="note">Every open gap has an owner.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Record</th><th>Residual risk</th><th>Status</th><th class="num">Mandatory gaps</th><th class="num">Optional gaps</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($unowned as $u): $ue = $u['entity']; ?>
          <tr>
            <td><a href="<?= e(url('/assessments/' . (int) $ue['id'])) ?>"><?= e($ue['name']) ?></a><div class="muted small"><?= $ue['entity_type'] === 'vendor' ? 'Vendor' : 'DPIA' ?> · <?= e($ue['department_name'] ?? '—') ?><?= $u['review_due'] ? ' · review due ' . fmt_date($ue['next_review_date']) : '' ?></div></td>
            <td><?= rating_badge($ue['residual_rating']) ?></td>
            <td><?= status_badge($ue['status']) ?></td>
            <td class="num"><?= $u['mandatory'] ? '<strong>' . (int) $u['mandatory'] . '</strong>' : '0' ?></td>
            <td class="num"><?= (int) $u['optional'] ?></td>
            <td><a class="btn btn-small" href="<?= e(url('/assessments/' . (int) $ue['id'] . '/remediation')) ?>">Assign →</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<h2>Assigned actions</h2>
<?php endif; ?>
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
