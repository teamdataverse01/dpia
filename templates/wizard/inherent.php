<?php
/** @var array $e @var ?array $stored @var ?array $vendor @var int $currentVersion @var \App\Engine\ScoringConfig $config @var list<\App\Engine\TemplateMatch> $matches @var array $history */
include __DIR__ . '/../partials/entity_header.php';
$id = (int) $e['id'];
?>
<?php if ($stored === null): ?>
  <div class="card">
    <h2><?= term('Inherent risk', 'inherent') ?></h2>
    <p>Not scored yet. Answer the risk factor questions first.</p>
    <a class="btn" href="<?= e(url("/assessments/{$id}/factors")) ?>">Go to risk factors</a>
  </div>
<?php else: ?>
  <div class="card">
    <h2><?= term('Inherent risk', 'inherent') ?> — risk before any safeguards</h2>
    <div class="score-hero">
      <div class="score-number"><?= e(number_format($stored['inherent_score'], 2)) ?> <small>out of 5</small></div>
      <?= rating_badge($stored['inherent_rating']) ?>
      <p class="muted small">Bands: Low up to <?= e(number_format($config->lowMax, 1)) ?>, Medium up to <?= e(number_format($config->mediumMax, 1)) ?>, High above that.</p>
    </div>
    <p>Each answer was scored from 1 (lowest risk) to 5 (highest) and multiplied by how much that factor counts (its weight). The weighted values add up to the score.</p>

    <?php if ($stored['framework_version'] !== $currentVersion): ?>
      <div class="warn">
        This was scored with scoring framework version <?= (int) $stored['framework_version'] ?>; the current version is <?= (int) $currentVersion ?>.
        <?php if ($canEditOwn || $canEditControls): ?>
          <form class="inline-form" method="post" action="<?= e(url("/assessments/{$id}/rescore")) ?>"><?= csrf_field() ?><button class="btn btn-small" type="submit">Recalculate with the current framework</button></form>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="table-wrap">
      <table>
        <caption class="visually-hidden">How the inherent risk score was built</caption>
        <thead><tr><th>Factor</th><th>Answer</th><th class="num">Score</th><th class="num">Weight used</th><th class="num">Weighted</th></tr></thead>
        <tbody>
        <?php foreach ($stored['breakdown'] as $b): ?>
          <tr>
            <td><?= e($b['factor_label']) ?></td>
            <td><?= e($b['option_label']) ?></td>
            <td class="num"><?= (int) $b['score'] ?></td>
            <td class="num"><?= e(number_format($b['weight_used'] * 100, 1)) ?>%</td>
            <td class="num"><?= e(number_format($b['weighted_value'], 2)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="4">Inherent risk score</td><td class="num"><?= e(number_format($stored['inherent_score'], 2)) ?></td></tr></tfoot>
      </table>
    </div>

    <?php foreach ($stored['excluded_factors'] as $x): ?>
      <p class="note small"><strong><?= e($x['factor_label']) ?> not included:</strong> <?= e($x['reason']) ?> The remaining weights were scaled up so they still total 100%.</p>
    <?php endforeach; ?>

    <?php if ($vendor !== null): ?>
      <p class="small">Vendor risk comes from <a href="<?= e(url('/assessments/' . (int) $vendor['id'] . '/residual')) ?>"><?= e($vendor['name']) ?></a>, whose current rating is <?= rating_badge($vendor['residual_rating'] ?? $vendor['inherent_rating']) ?>. If that vendor's score changes, this DPIA picks it up the next time it is recalculated.</p>
    <?php endif; ?>
  </div>

  <?php if ($matches !== []): ?>
    <div class="card">
      <h2>This looks like a known activity</h2>
      <?php foreach ($matches as $m): ?>
        <p>“<?= e($m->template->activityName) ?>” (<?= e($m->template->departmentName) ?>): <?= e($m->reason) ?>
          <a href="<?= e(url('/templates/' . $m->template->id)) ?>">View template</a></p>
      <?php endforeach; ?>
      <p class="muted small">For your information only — your own answers above are what count.</p>
    </div>
  <?php endif; ?>

  <div class="form-actions">
    <a class="btn btn-secondary" href="<?= e(url("/assessments/{$id}/factors")) ?>">Change answers</a>
    <a class="btn" href="<?= e(url("/assessments/{$id}/recommended")) ?>">Continue to recommended controls</a>
  </div>

  <?php if (count($history) > 1): ?>
    <div class="card">
      <h2>Score history</h2>
      <ul class="history">
        <?php foreach ($history as $h): ?>
          <li><time><?= fmt_datetime($h['calculated_at']) ?></time> <?= e(number_format((float) $h['score'], 2)) ?> <span class="muted">(framework v<?= (int) $h['framework_version'] ?>)</span></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
<?php endif; ?>
