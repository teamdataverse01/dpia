<?php
/** @var array $e @var array $byCategory @var bool $hasAssessments @var bool $canStart */
include __DIR__ . '/../partials/entity_header.php';
$id = (int) $e['id'];
$total = array_sum(array_map('count', $byCategory));
?>
<div class="card">
  <h2>Recommended <?= term('controls', 'control') ?></h2>
  <?php if ($e['inherent_rating'] === null): ?>
    <p>Score the inherent risk first; controls are recommended from the answers.</p>
  <?php else: ?>
    <p>Based on the answers, these <?= (int) $total ?> safeguards are recommended. Each one says why. Controls marked <span class="tag">mandatory</span> must all be in place for their type to count as “cleared” and reduce the residual risk.</p>
    <p class="muted small">There are three types of control. <strong>Technology</strong> controls reduce risk the most; reducing High risk to Low needs at least two types cleared, one of them Technology.</p>
  <?php endif; ?>
</div>

<?php foreach ($byCategory as $cat => $rows): $c = \App\Domain\ControlCategory::from($cat); ?>
  <div class="cat-head"><h2><?= e($c->label()) ?></h2><span class="muted small"><?= count($rows) ?> recommended</span></div>
  <?php if ($rows === []): ?>
    <p class="muted small">None recommended for this assessment.</p>
  <?php endif; ?>
  <?php foreach ($rows as $r): $ctl = $r['control']; ?>
    <div class="card">
      <h3><?= e($ctl['title']) ?>
        <?php if ($ctl['mandatory']): ?><span class="tag">mandatory</span><?php endif; ?>
        <?php if ($ctl['verification_required']): ?><span class="tag">needs Reviewer verification</span><?php endif; ?>
        <?php if ($r['source'] === 'template'): ?><span class="tag">template standard</span><?php endif; ?>
      </h3>
      <p><?= e($ctl['description']) ?></p>
      <p class="small"><strong>Why:</strong> <?= e($r['reason']) ?></p>
      <?php if ($ctl['expected_evidence']): ?><p class="small muted"><strong>Evidence expected:</strong> <?= e($ctl['expected_evidence']) ?></p><?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endforeach; ?>

<div class="form-actions">
  <?php if ($canStart && $e['inherent_rating'] !== null): ?>
    <form method="post" action="<?= e(url("/assessments/{$id}/recommended")) ?>"><?= csrf_field() ?>
      <button class="btn" type="submit"><?= $hasAssessments ? 'Update and continue to control assessment' : 'Accept and continue to control assessment' ?></button>
    </form>
  <?php else: ?>
    <a class="btn" href="<?= e(url("/assessments/{$id}/controls")) ?>">Go to control assessment</a>
  <?php endif; ?>
</div>
