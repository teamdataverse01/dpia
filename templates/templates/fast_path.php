<?php /** @var array $departments @var ?int $dept @var string $q @var bool $searched @var array $results @var list<\App\Engine\TemplateMatch> $exact */ ?>
<div class="page-head">
  <div>
    <h1>New <?= term('DPIA', 'dpia') ?></h1>
    <p class="muted">Is this a recurring activity CU has already assessed? Starting from a template gives you the risk score straight away.</p>
  </div>
</div>

<form class="card" method="get" action="<?= e(url('/dpias/new')) ?>">
  <input type="hidden" name="searched" value="1">
  <h2>Find a ready-made template</h2>
  <div class="filters">
    <div class="field">
      <label for="department_id">Department</label>
      <select id="department_id" name="department_id">
        <option value="">Any department</option>
        <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"<?= selected($dept, $d['id']) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="q">Activity name</label>
      <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="e.g. JAMB data intake">
    </div>
    <button class="btn" type="submit">Search</button>
  </div>
</form>

<?php if ($searched): ?>
  <div class="card">
    <h2>Results</h2>
    <?php if ($exact !== []): ?>
      <p class="note">Exact match: <strong><?= e($exact[0]->template->activityName) ?></strong>. <?= e($exact[0]->reason) ?></p>
    <?php endif; ?>
    <?php if ($results === []): ?>
      <p>No template matches. That is fine — <a href="<?= e(url('/dpias/new/intake', ['name' => $q, 'department_id' => $dept])) ?>">start the full DPIA</a>.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Activity</th><th>Department</th><th>Inherent risk</th><th>Last reviewed</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($results as $t): ?>
            <tr>
              <td><?= e($t['activity_name']) ?><?php if ($t['stale']): ?> <span class="tag tag-bad">may be out of date</span><?php endif; ?></td>
              <td><?= e($t['department_name']) ?></td>
              <td><?= rating_badge($t['inherent_rating']) ?> <?= e(number_format((float) $t['inherent_score'], 2)) ?></td>
              <td class="nowrap"><?= fmt_date($t['last_reviewed_at']) ?></td>
              <td><a class="btn btn-small" href="<?= e(url('/templates/' . (int) $t['id'], ['why' => $exact !== [] && $exact[0]->template->id === (int) $t['id'] ? $exact[0]->reason : 'You chose this template from the search results.'])) ?>">Use this</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <h2>No template?</h2>
  <p>Go through the full ten-step DPIA. After it is approved, the Privacy Team can save it as a template for next time.</p>
  <a class="btn btn-secondary" href="<?= e(url('/dpias/new/intake', ['name' => $q, 'department_id' => $dept])) ?>">Start the full DPIA</a>
</div>
