<?php
/** @var array $e @var array $round @var array $history @var bool $canSubmit @var bool $canDecide @var bool $canAccept @var int $openActions @var string $minAcceptDate */
include __DIR__ . '/../partials/entity_header.php';
$id = (int) $e['id'];
$roleLabels = fn (array $roles) => implode(' and ', array_map(fn ($r) => $r->label(), $roles));
?>
<div class="card">
  <h2>Review &amp; approval</h2>
  <dl class="kv">
    <dt><?= term('Inherent risk', 'inherent') ?></dt><dd><?= rating_badge($e['inherent_rating']) ?> <?= $e['inherent_score'] !== null ? e(number_format((float) $e['inherent_score'], 2)) : '' ?></dd>
    <dt><?= term('Residual risk', 'residual') ?></dt><dd><?= rating_badge($e['residual_rating']) ?></dd>
    <dt>Controls cleared</dt><dd><?= e(implode(', ', array_map('ucfirst', array_filter(explode(',', (string) $e['cleared_categories'])))) ?: 'None') ?></dd>
    <dt>Open remediation actions</dt><dd><?= (int) $openActions ?></dd>
    <?php if ($round['required'] !== []): ?>
      <dt>Who must approve</dt><dd><?= e($roleLabels($round['required'])) ?> <span class="muted small">(routing for <?= e(\App\Domain\Rating::from($e['residual_rating'])->label()) ?> residual risk)</span></dd>
    <?php endif; ?>
    <?php if ($e['status'] === 'pending_approval'): ?>
      <dt>Still waiting for</dt><dd><?= $round['outstanding'] === [] ? '—' : e($roleLabels($round['outstanding'])) ?></dd>
      <dt>Submitted</dt><dd><?= fmt_datetime($e['submitted_at']) ?></dd>
    <?php endif; ?>
  </dl>
</div>

<?php if ($canSubmit): ?>
  <form class="card" method="post" action="<?= e(url("/assessments/{$id}/submit")) ?>">
    <?= csrf_field() ?>
    <h2>Submit for approval</h2>
    <p>The approvers listed above will be notified. You cannot change the assessment while it is waiting for a decision.</p>
    <?php if ($openActions > 0): ?><p class="warn small">There are <?= (int) $openActions ?> open remediation actions. Approvers will see them.</p><?php endif; ?>
    <button class="btn" type="submit">Submit for approval</button>
  </form>
<?php elseif (in_array($e['status'], ['scored', 'controls_in_progress', 'draft', 'rejected', 'reassessment_due'], true)): ?>
  <p class="note">To submit, first confirm the residual risk on the <a href="<?= e(url("/assessments/{$id}/residual")) ?>">Residual risk</a> screen.</p>
<?php endif; ?>

<?php if ($canDecide): ?>
  <form class="card" method="post" action="<?= e(url("/assessments/{$id}/decide")) ?>" data-decision-form novalidate>
    <?= csrf_field() ?>
    <h2>Your decision</h2>
    <fieldset>
      <legend>Decision</legend>
      <div class="choices">
        <label class="choice"><input type="radio" name="decision" value="approved" required><span><strong>Approve</strong><span class="muted small">The residual risk is acceptable with the controls in place.</span></span></label>
        <label class="choice"><input type="radio" name="decision" value="rejected"><span><strong>Reject</strong><span class="muted small">Send it back; the owner must address your comments and resubmit.</span></span></label>
        <?php if ($canAccept): ?>
        <label class="choice"><input type="radio" name="decision" value="risk_accepted" data-accept-toggle><span><strong>Accept the risk for a limited time</strong><span class="muted small">CU knowingly accepts the remaining risk until a set date, then it must be reassessed.</span></span></label>
        <?php endif; ?>
      </div>
    </fieldset>
    <?php if ($canAccept): ?>
    <div class="field" data-accept-until>
      <label for="accepted_until">Risk accepted until</label>
      <span class="hint">Required when accepting the risk.</span>
      <input type="date" id="accepted_until" name="accepted_until" min="<?= e($minAcceptDate) ?>">
    </div>
    <?php endif; ?>
    <div class="field">
      <label for="justification">Justification</label>
      <span class="hint">Recorded in the audit trail and shown to the owner.</span>
      <textarea id="justification" name="justification" required></textarea>
    </div>
    <button class="btn" type="submit">Record decision</button>
  </form>
<?php endif; ?>

<div class="card">
  <h2>Decisions</h2>
  <?php if ($history === []): ?>
    <p class="muted">No decisions yet.</p>
  <?php else: ?>
    <ul class="history">
      <?php foreach ($history as $h): ?>
        <li><time><?= fmt_datetime($h['decided_at']) ?></time>
          <strong><?= e(\App\Domain\ApprovalDecision::from($h['decision'])->label()) ?></strong>
          by <?= e($h['decided_by']) ?><?= $h['approver_role'] ? ' as ' . e(\App\Domain\Role::tryFrom($h['approver_role'])?->label() ?? $h['approver_role']) : '' ?>
          <?= $h['accepted_until'] ? ' · until ' . fmt_date($h['accepted_until']) : '' ?>
          — “<?= e($h['justification']) ?>”</li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
