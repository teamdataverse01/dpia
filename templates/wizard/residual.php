<?php
/** @var array $e @var ?array $explanation @var bool $canConfirmNegligible @var bool $canCalculate */
include __DIR__ . '/../partials/entity_header.php';
$id = (int) $e['id'];
$x = $explanation;
?>
<?php if ($x === null): ?>
  <div class="card"><p>Score the inherent risk first.</p></div>
<?php else: ?>
<div class="card">
  <h2><?= term('Residual risk', 'residual') ?> — risk after safeguards</h2>
  <div class="score-hero">
    <div><div class="muted small">Inherent</div><?= rating_badge($x['inherent_rating'], '') ?></div>
    <span class="arrow" aria-hidden="true">→</span>
    <div><div class="muted small">Residual</div><span class="badge badge-big badge-<?= e($x['residual_rating']) ?>"><?= e($x['residual_rating_label']) ?></span></div>
  </div>
  <p><strong>Why:</strong> <?= e($x['rule_explanation']) ?></p>
  <p class="muted small">The rating comes only from which control types are cleared. The figures below are indicators for trends and never override the rating.</p>
  <dl class="kv">
    <dt>Risk reduced by controls</dt><dd><?= e(number_format($x['risk_reduced_pct'], 0)) ?>%</dd>
    <dt>Residual score (indicator)</dt><dd><?= e(number_format($x['residual_score'], 2)) ?> out of 5 (inherent <?= e(number_format($x['inherent_score'], 2)) ?>)</dd>
    <?php if ($x['residual_score_100'] !== null): ?>
      <dt>Vendor score used by DPIAs</dt><dd><?= (int) $x['residual_score_100'] ?> / 100</dd>
    <?php endif; ?>
  </dl>
</div>

<div class="card">
  <h2>Control types</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Type</th><th>Status</th><th class="num">Reduces risk by</th><th>What is missing</th></tr></thead>
      <tbody>
      <?php foreach ($x['categories'] as $cat => $c): ?>
        <tr>
          <td><?= e($c['label']) ?></td>
          <td><span class="tag <?= $c['cleared'] ? 'tag-ok' : 'tag-bad' ?>"><?= $c['cleared'] ? 'Cleared' : 'Not cleared' ?></span></td>
          <td class="num"><?= e(number_format($x['reduction_weights'][$cat] * 100, 0)) ?>%</td>
          <td class="small"><?php if ($c['why_not'] === []): ?>—<?php else: ?><ul class="unmet"><?php foreach ($c['why_not'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="small muted">Rules: High → Low needs two cleared types including Technology. Medium → Low needs one. Very Low is only given when a Reviewer or the Privacy Team / DPO confirms that a Low remaining risk is negligible.</p>
</div>

<?php if ($canConfirmNegligible): ?>
  <div class="card">
    <h2>Very Low (negligible remaining risk)</h2>
    <?php if ($e['negligible_residual_confirmed']): ?>
      <p>Confirmed: “<?= e($e['negligible_justification']) ?>”</p>
      <?php if ($x['computed_rating'] !== 'low'): ?><p class="warn small">The computed rating is no longer Low, so this confirmation is currently ignored.</p><?php endif; ?>
      <form method="post" action="<?= e(url("/assessments/{$id}/negligible")) ?>"><?= csrf_field() ?><input type="hidden" name="confirm" value="0"><button class="btn btn-secondary btn-small" type="submit">Withdraw confirmation</button></form>
    <?php elseif ($x['computed_rating'] === 'low'): ?>
      <form method="post" action="<?= e(url("/assessments/{$id}/negligible")) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="confirm" value="1">
        <div class="field">
          <label for="justification">Why is the remaining risk negligible?</label>
          <span class="hint">This is recorded in the audit trail.</span>
          <textarea id="justification" name="justification" required minlength="10"></textarea>
        </div>
        <button class="btn btn-secondary" type="submit">Confirm Very Low</button>
      </form>
    <?php else: ?>
      <p class="muted small">Only available when the residual rating is Low.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="form-actions">
  <a class="btn btn-secondary" href="<?= e(url("/assessments/{$id}/controls")) ?>">Back to controls</a>
  <?php if ($canCalculate && $editable && $e['status'] !== 'residual_calculated'): ?>
    <form method="post" action="<?= e(url("/assessments/{$id}/residual")) ?>"><?= csrf_field() ?><button class="btn" type="submit">Confirm residual risk</button></form>
  <?php endif; ?>
  <a class="btn" href="<?= e(url("/assessments/{$id}/remediation")) ?>">Continue to remediation plan</a>
</div>
<?php endif; ?>
