<?php
/** @var array $filters @var array $departments @var array $overview @var array $byDept @var array $rem @var array $stories @var bool $institutionWide */
$basis = $filters['basis'];
$q = array_filter(['entity_type' => $filters['entity_type'] ?? null, 'department_id' => $filters['department_id'] ?? null, 'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null]);
$listQ = $q + ($basis === 'inherent' ? ['basis' => 'inherent'] : []);
$counts = [];
foreach ($overview['ratings'] as $r) {
    $counts[$r['rating']] = $r['count'];
}
$action = '/dashboard';
?>
<div class="page-head">
  <div>
    <h1>Privacy risk dashboard</h1>
    <p class="muted"><?= $institutionWide ? 'All vendors and DPIAs across Covenant University.' : 'Showing only the items you own.' ?></p>
  </div>
  <div class="toggle" role="group" aria-label="Rating basis">
    <a href="<?= e(url('/dashboard', $q + ['basis' => 'residual'])) ?>"<?= $basis === 'residual' ? ' aria-current="true"' : '' ?>><?= 'Residual' ?> risk</a>
    <a href="<?= e(url('/dashboard', $q + ['basis' => 'inherent'])) ?>"<?= $basis === 'inherent' ? ' aria-current="true"' : '' ?>>Inherent risk</a>
  </div>
</div>
<p class="small muted">Showing <?= $basis === 'residual' ? term('residual risk', 'residual') . ' (after safeguards)' : term('inherent risk', 'inherent') . ' (before safeguards)' ?>. “Active” means scored and not rejected.</p>

<?php include __DIR__ . '/../partials/dashboard_filters.php'; ?>

<div class="tiles">
  <a class="tile tile-total" href="<?= e(url('/risks', $listQ + ['active' => '1'])) ?>"><div class="n"><?= (int) $overview['total_active'] ?></div><div class="lbl">Total active risks</div></a>
  <?php foreach ($overview['ratings'] as $r): ?>
    <a class="tile tile-<?= e($r['rating']) ?>" href="<?= e(url('/risks', $listQ + ['rating' => $r['rating']])) ?>">
      <div class="n"><?= (int) $r['count'] ?></div>
      <div class="lbl"><?= e($r['label']) ?> · <?= (int) $r['pct'] ?>%</div>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <h2 class="visually-hidden">Share of risks by rating</h2>
  <?php $label = 'Share of active risks by ' . $basis . ' rating: ' . implode(', ', array_map(fn ($r) => $r['label'] . ' ' . $r['pct'] . '%', $overview['ratings'])); include __DIR__ . '/../partials/stackbar.php'; ?>
  <ul class="legend">
    <?php foreach ($overview['ratings'] as $r): ?>
      <li><span class="swatch swatch-<?= e($r['rating']) ?>" aria-hidden="true"></span><?= e($r['label']) ?> <?= (int) $r['count'] ?> (<?= (int) $r['pct'] ?>%)</li>
    <?php endforeach; ?>
  </ul>
</div>

<div class="grid grid-2">
  <section class="card" aria-labelledby="stories-h">
    <h2 id="stories-h">Key risk areas</h2>
    <?php if ($stories === []): ?>
      <p class="muted">There is not enough data yet to describe trends.</p>
    <?php else: ?>
      <ul class="stories">
        <?php foreach ($stories as $s): ?>
          <li><span><?= e($s['text']) ?> <a href="<?= e(url($s['link'])) ?>">See the list</a></span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card" aria-labelledby="dept-h">
    <h2 id="dept-h">Risk by department</h2>
    <?php if ($byDept['departments'] === []): ?>
      <p class="muted">No active risks.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Department</th><th class="num">Total</th><th class="num">High</th><th class="num">Med</th><th class="num">Low</th><th class="num">V.Low</th></tr></thead>
          <tbody>
          <?php foreach ($byDept['departments'] as $d): $dq = $listQ + ['department_id' => $d['department_id'] ?? -1]; ?>
            <tr>
              <td><a href="<?= e(url('/risks', $dq + ['active' => '1'])) ?>"><?= e($d['department']) ?></a>
                <?php $counts2 = ['high' => $d['high'], 'medium' => $d['medium'], 'low' => $d['low'], 'very_low' => $d['very_low']]; ?>
                <?php $c0 = $counts; $counts = $counts2; $label = $d['department'] . ' rating split'; $barClass = 'dept-bar'; include __DIR__ . '/../partials/stackbar.php'; $counts = $c0; $barClass = 'stackbar'; ?>
              </td>
              <td class="num"><?= (int) $d['total'] ?></td>
              <td class="num"><?= (int) $d['high'] ?></td>
              <td class="num"><?= (int) $d['medium'] ?></td>
              <td class="num"><?= (int) $d['low'] ?></td>
              <td class="num"><?= (int) $d['very_low'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td>All departments</td><td class="num"><?= (int) $byDept['total'] ?></td><td colspan="4"></td></tr></tfoot>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

<section class="card" aria-labelledby="rem-h">
  <div class="row"><h2 id="rem-h">Remediation</h2><span class="spacer"></span><a href="<?= e(url('/remediation', array_intersect_key($q, ['entity_type' => 1, 'department_id' => 1]))) ?>">Open the tracker</a></div>
  <div class="tiles">
    <?php foreach (['open' => 'Open', 'in_progress' => 'In progress', 'overdue' => 'Overdue', 'completed' => 'Completed'] as $k => $lbl): ?>
      <a class="tile<?= $k === 'overdue' ? ' tile-alert' : '' ?>" href="<?= e(url('/remediation', array_intersect_key($q, ['entity_type' => 1, 'department_id' => 1]) + ['status' => $k])) ?>"><div class="n"><?= (int) $rem['summary'][$k] ?></div><div class="lbl"><?= e($lbl) ?></div></a>
    <?php endforeach; ?>
    <div class="tile"><div class="n"><?= $rem['avg_risk_reduced_pct'] === null ? '—' : e(number_format($rem['avg_risk_reduced_pct'], 0)) . '%' ?></div><div class="lbl">Average risk reduced by controls</div></div>
  </div>

  <div class="grid grid-3">
    <div>
      <h3>Awaiting review or approval</h3>
      <?php if ($rem['pending_approvals'] === []): ?><p class="muted small">None.</p><?php else: ?>
        <ul class="history"><?php foreach ($rem['pending_approvals'] as $p): ?>
          <li><a href="<?= e(url('/assessments/' . (int) $p['id'] . '/review')) ?>"><?= e($p['name']) ?></a> <?= rating_badge($p['residual_rating']) ?> <span class="muted">since <?= fmt_date($p['submitted_at']) ?></span></li>
        <?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
    <div>
      <h3>Expiring in the next 90 days</h3>
      <?php if ($rem['expiring'] === []): ?><p class="muted small">Nothing expiring.</p><?php else: ?>
        <ul class="history"><?php foreach ($rem['expiring'] as $x): ?>
          <li><span class="tag<?= $x['window'] === 30 ? ' tag-bad' : '' ?>">≤<?= (int) $x['window'] ?> days</span>
            <?= e(['risk_acceptance' => 'Risk acceptance', 'contract' => 'Contract', 'evidence' => 'Evidence'][$x['kind']]) ?>:
            <a href="<?= e(url('/assessments/' . (int) $x['entity_id'] . '/monitoring')) ?>"><?= e($x['entity_name']) ?></a><?= $x['kind'] === 'evidence' ? ' — ' . e($x['item']) : '' ?>
            <span class="muted"><?= fmt_date($x['expires']) ?></span></li>
        <?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
    <div>
      <h3>Most common control gaps</h3>
      <?php if ($rem['top_gaps'] === []): ?><p class="muted small">No gaps recorded.</p><?php else: ?>
        <ol class="small"><?php foreach ($rem['top_gaps'] as $g): ?>
          <li><?= e($g['title']) ?> <span class="muted">(<?= e(ucfirst($g['category'])) ?>, <?= (int) $g['n'] ?>)</span></li>
        <?php endforeach; ?></ol>
      <?php endif; ?>
    </div>
  </div>
</section>
