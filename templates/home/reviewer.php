<?php /** @var array $evidence @var array $decisions */ ?>
<div class="page-head">
  <div>
    <h1>My review queue</h1>
    <p class="muted">Check the evidence people have uploaded, and approve Low-risk assessments. Medium and High risks go to the DPO.</p>
  </div>
  <a class="btn btn-secondary" href="<?= e(url('/overview')) ?>">University overview</a>
</div>
<div class="tiles">
  <a class="tile<?= $evidence ? ' tile-alert' : '' ?>" href="#evidence"><div class="n"><?= count($evidence) ?></div><div class="lbl">Evidence to verify</div></a>
  <a class="tile<?= $decisions ? ' tile-alert' : '' ?>" href="#decisions"><div class="n"><?= count($decisions) ?></div><div class="lbl">Assessments to approve</div></a>
</div>

<section class="card" id="evidence">
  <h2>Evidence to verify</h2>
  <?php if ($evidence === []): ?>
    <p class="muted">Nothing waiting. All uploaded evidence that needs checking has been checked.</p>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Safeguard</th><th>Assessment</th><th>File</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($evidence as $x): $link = url('/assessments/' . (int) $x['entity_id'] . '/controls', ['control' => (int) $x['control_id']]) . '#control-' . (int) $x['control_id']; ?>
        <tr>
          <td><?= e($x['title']) ?></td>
          <td><?= e($x['entity_name']) ?><div class="muted small"><?= e($x['department_name'] ?? '—') ?></div></td>
          <td><?= e($x['evidence_original_name']) ?></td>
          <td><a class="btn btn-small" href="<?= e($link) ?>">Check it</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>

<section class="card" id="decisions">
  <h2>Assessments to approve</h2>
  <?php if ($decisions === []): ?>
    <p class="muted">Nothing waiting for your decision.</p>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Assessment</th><th>Risk left</th><th>Submitted</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($decisions as $d): ?>
        <tr>
          <td><?= e($d['name']) ?><div class="muted small"><?= e($d['department_name'] ?? '—') ?></div></td>
          <td><?= rating_badge($d['residual_rating']) ?></td>
          <td class="nowrap"><?= fmt_date($d['submitted_at']) ?></td>
          <td><a class="btn btn-small" href="<?= e(url('/assessments/' . (int) $d['id'] . '/review')) ?>">Review</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>
