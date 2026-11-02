<?php /** @var array $records @var array $actions */
$needsYou = count(array_filter($records, fn ($r) => $r['next']['needs_you']));
$waiting = count(array_filter($records, fn ($r) => $r['status'] === 'pending_approval'));
$overdue = count(array_filter($actions, fn ($a) => $a['display_status'] === 'overdue'));
?>
<div class="page-head">
  <div>
    <h1>My work</h1>
    <p class="muted">The vendors and data activities you are responsible for at CU, and what to do next on each.</p>
  </div>
  <div class="row">
    <a class="btn" href="<?= e(url('/dpias/new')) ?>">New DPIA</a>
    <a class="btn btn-secondary" href="<?= e(url('/vendors/new')) ?>">New vendor assessment</a>
  </div>
</div>
<div class="tiles">
  <a class="tile<?= $needsYou ? ' tile-alert' : '' ?>" href="#records"><div class="n"><?= (int) $needsYou ?></div><div class="lbl">Need you</div></a>
  <a class="tile" href="#records"><div class="n"><?= (int) $waiting ?></div><div class="lbl">Waiting for approval</div></a>
  <a class="tile<?= $overdue ? ' tile-alert' : '' ?>" href="#actions"><div class="n"><?= count($actions) ?></div><div class="lbl">Fixes assigned to me<?= $overdue ? ' · ' . (int) $overdue . ' overdue' : '' ?></div></a>
</div>

<section class="card" id="records">
  <h2>My records</h2>
  <?php if ($records === []): ?>
    <p class="muted">You have no records yet. Start a DPIA or a vendor assessment above.</p>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Name</th><th>Status</th><th>Risk left</th><th>Next step</th></tr></thead>
      <tbody>
      <?php foreach ($records as $r): ?>
        <tr>
          <td><a href="<?= e(url('/assessments/' . (int) $r['id'])) ?>"><?= e($r['name']) ?></a><div class="muted small"><?= $r['entity_type'] === 'vendor' ? 'Vendor' : 'DPIA' ?> · <?= e($r['department_name'] ?? '—') ?></div></td>
          <td><?= status_badge($r['status']) ?></td>
          <td><?= rating_badge($r['residual_rating']) ?></td>
          <td>
            <?php if ($r['next']['needs_you']): ?>
              <a class="btn btn-small" href="<?= e(url('/assessments/' . (int) $r['id'] . '/' . $r['next']['step'])) ?>"><?= e($r['next']['label']) ?></a>
            <?php else: ?>
              <span class="muted small"><?= e($r['next']['label']) ?><?= $r['next_review_date'] && $r['status'] !== 'pending_approval' ? ' (' . fmt_date($r['next_review_date']) . ')' : '' ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>

<section class="card" id="actions">
  <h2>Fixes assigned to me</h2>
  <?php if ($actions === []): ?>
    <p class="muted">Nothing assigned to you right now.</p>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Fix</th><th>Assessment</th><th>Due</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($actions as $a): ?>
        <tr<?= $a['display_status'] === 'overdue' ? ' class="highlight"' : '' ?>>
          <td><?= e($a['control_title'] ?? $a['gap_description']) ?></td>
          <td><?= e($a['entity_name']) ?></td>
          <td class="nowrap"><?= fmt_date($a['target_date']) ?></td>
          <td><?php include __DIR__ . '/../partials/remediation_status.php'; ?></td>
          <td><?php if ($a['control_id']): ?><a class="btn btn-small" href="<?= e(url('/assessments/' . (int) $a['risk_entity_id'] . '/controls', ['control' => (int) $a['control_id']]) . '#control-' . (int) $a['control_id']) ?>">Record the fix</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>
