<?php /** @var array $filters @var array $rows @var int $page */ ?>
<h1>Audit log</h1>
<p class="muted">Every change is recorded here: who, when, and the old and new values. The log is read-only and cannot be edited or deleted.</p>
<form class="card filters" method="get" action="<?= e(url('/audit')) ?>">
  <div class="field"><label for="a-table">Table</label><input type="text" id="a-table" name="entity_table" value="<?= e($filters['entity_table'] ?? '') ?>" placeholder="risk_entities"></div>
  <div class="field"><label for="a-id">Record ID</label><input type="number" id="a-id" name="entity_id" value="<?= e($filters['entity_id'] ?? '') ?>"></div>
  <div class="field"><label for="a-actor">Who</label><input type="text" id="a-actor" name="actor" value="<?= e($filters['actor'] ?? '') ?>"></div>
  <div class="field"><label for="a-action">Action</label><input type="text" id="a-action" name="action" value="<?= e($filters['action'] ?? '') ?>"></div>
  <div class="field"><label for="a-from">From</label><input type="date" id="a-from" name="from" value="<?= e($filters['from'] ?? '') ?>"></div>
  <div class="field"><label for="a-to">To</label><input type="date" id="a-to" name="to" value="<?= e($filters['to'] ?? '') ?>"></div>
  <button class="btn" type="submit">Filter</button>
</form>
<div class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>When</th><th>Who</th><th>Record</th><th>Action</th><th>Old</th><th>New</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr>
        <td class="nowrap"><?= fmt_datetime($r['occurred_at']) ?></td>
        <td><?= e($r['actor_portal_user_id']) ?></td>
        <td><?= e($r['entity_table']) ?> #<?= (int) $r['entity_id'] ?></td>
        <td><?= e($r['action']) ?></td>
        <td class="small"><code><?= e(mb_strimwidth((string) $r['old_value'], 0, 300, '…')) ?></code></td>
        <td class="small"><code><?= e(mb_strimwidth((string) $r['new_value'], 0, 300, '…')) ?></code></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <div class="form-actions">
    <?php if ($page > 1): ?><a href="<?= e(url('/audit', $filters + ['page' => $page - 1])) ?>">← Newer</a><?php endif; ?>
    <?php if (count($rows) === 100): ?><a href="<?= e(url('/audit', $filters + ['page' => $page + 1])) ?>">Older →</a><?php endif; ?>
  </div>
</div>
