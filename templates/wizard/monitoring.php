<?php
/** @var array $e @var ?array $acceptance @var array $evidence @var string $today @var array $history @var array $scores @var array $linkedDpias @var bool $canReassess @var bool $canSaveTemplate */
include __DIR__ . '/../partials/entity_header.php';
$id = (int) $e['id'];
?>
<div class="grid grid-2">
  <div class="card">
    <h2>Monitoring</h2>
    <dl class="kv">
      <dt>Status</dt><dd><?= status_badge($e['status']) ?></dd>
      <dt>Next review</dt><dd><?= fmt_date($e['next_review_date']) ?><?php if ($e['next_review_date'] && substr((string) $e['next_review_date'], 0, 10) <= $today): ?> <span class="tag tag-bad">due</span><?php endif; ?></dd>
      <?php if ($acceptance): ?>
        <dt>Risk accepted until</dt><dd><?= fmt_date($acceptance['accepted_until']) ?> <span class="muted small">by <?= e($acceptance['decided_by']) ?></span></dd>
      <?php endif; ?>
      <?php if ($e['entity_type'] === 'vendor' && $e['contract_end']): ?>
        <dt>Contract ends</dt><dd><?= fmt_date($e['contract_end']) ?></dd>
      <?php endif; ?>
      <dt>Scoring framework</dt><dd>version <?= (int) $e['scoring_framework_version'] ?></dd>
    </dl>
    <p class="small muted">Reassessment becomes due automatically on the review date (or when a risk acceptance ends). You can also start one at any time.</p>
    <div class="form-actions">
      <?php if ($canReassess): ?>
        <form method="post" action="<?= e(url("/assessments/{$id}/reassess")) ?>"><?= csrf_field() ?><button class="btn" type="submit">Start reassessment</button></form>
      <?php endif; ?>
    </div>
    <?php if ($canSaveTemplate): ?>
      <form method="post" action="<?= e(url("/assessments/{$id}/save-template")) ?>" class="stack">
        <?= csrf_field() ?>
        <h3>Save as a template</h3>
        <p class="small muted">Lets future DPIAs for the same recurring activity start from these answers, score and controls.</p>
        <div class="field">
          <label for="activity_name">Template name</label>
          <input type="text" id="activity_name" name="activity_name" value="<?= e(($e['department_name'] ?? '') . ' — ' . $e['name']) ?>">
        </div>
        <button class="btn btn-secondary" type="submit">Save as template</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Evidence expiry</h2>
    <?php if ($evidence === []): ?>
      <p class="muted">No evidence with an expiry date.</p>
    <?php else: ?>
      <ul class="history">
        <?php foreach ($evidence as $a): $exp = substr((string) $a['expiry_date'], 0, 10); ?>
          <li><?= e($a['title']) ?> — <?= fmt_date($exp) ?>
            <?php if ($exp < $today): ?><span class="tag tag-bad">expired</span><?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if ($linkedDpias !== []): ?>
      <h3>DPIAs that use this vendor's score</h3>
      <ul class="history">
        <?php foreach ($linkedDpias as $d): ?>
          <li><a href="<?= e(url('/assessments/' . (int) $d['id'])) ?>"><?= e($d['name']) ?></a> <?= rating_badge($d['residual_rating']) ?></li>
        <?php endforeach; ?>
      </ul>
      <p class="small muted">They pick up this vendor's current score the next time they are recalculated.</p>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>History</h2>
  <?php if ($scores !== []): ?>
    <p class="small">Inherent score over time: <?php foreach (array_reverse($scores) as $i => $s): ?><?= $i ? ' → ' : '' ?><?= e(number_format((float) $s['score'], 2)) ?><?php endforeach; ?></p>
  <?php endif; ?>
  <ul class="history">
    <?php foreach ($history as $h): ?>
      <li><time><?= fmt_datetime($h['occurred_at']) ?></time><strong><?= e(str_replace('_', ' ', $h['action'])) ?></strong>
        <span class="muted">on <?= e($h['entity_table']) ?> by <?= e($h['actor_portal_user_id']) ?></span>
        <?php if ($h['action'] === 'status_change'): $n = json_decode((string) $h['new_value'], true); $o = json_decode((string) $h['old_value'], true); ?>
          — <?= e(\App\Domain\AssessmentStatus::tryFrom($o['status'] ?? '')?->label() ?? '') ?> → <?= e(\App\Domain\AssessmentStatus::tryFrom($n['status'] ?? '')?->label() ?? '') ?>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
