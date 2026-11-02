<?php /** @var array $requests */ ?>
<div class="page-head">
  <div>
    <h1>What CU needs from you</h1>
    <p class="muted">Covenant University needs proof of how your company protects its data. Upload a document for each item below.</p>
  </div>
</div>
<?php if ($requests === []): ?>
  <div class="card"><p class="muted">CU has not asked you for anything yet.</p></div>
<?php endif; ?>
<?php foreach ($requests as $r): $e = $r['entity']; ?>
  <section class="card">
    <h2><?= e($e['name']) ?></h2>
    <p class="muted small"><?= count($r['done']) ?> of <?= count($r['todo']) + count($r['done']) ?> items complete.</p>
    <?php if ($r['todo'] === []): ?>
      <p class="note">Nothing outstanding. Thank you.</p>
    <?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>What CU needs</th><th>What to send</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($r['todo'] as $row): $c = $row['control']; $cid = (int) $c['id']; ?>
          <tr>
            <td><?= e($c['title']) ?><div class="muted small"><?= e($c['description']) ?></div></td>
            <td class="small"><?= e($c['expected_evidence'] ?? 'A document showing this is in place.') ?></td>
            <?php $sent = !empty($row['assessment']['evidence_path']); ?>
            <td>
              <?php if ($sent): ?><span class="tag">Sent<?= $row['control']['verification_required'] && empty($row['assessment']['verified_at']) ? ', waiting for CU to check' : '' ?></span><?php endif; ?>
              <a class="btn btn-small<?= $sent ? ' btn-secondary' : '' ?>" href="<?= e(url('/assessments/' . (int) $e['id'] . '/controls', ['control' => $cid]) . '#control-' . $cid) ?>"><?= $sent ? 'Open' : 'Upload' ?></a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
