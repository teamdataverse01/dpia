<?php /** @var array $rows @var array $roles @var array $departments @var array $errors @var array $old */ ?>
<h1>User roles</h1>
<?php include __DIR__ . '/_nav.php'; ?>
<p class="note">People sign in through the CU portal. Here you map a portal user ID to this module's roles. A Business Owner tied to a department only sees that department's records.</p>
<form class="card" method="post" action="<?= e(url('/admin/users')) ?>" novalidate>
  <?= csrf_field() ?>
  <h2>Add a role</h2>
  <div class="filters">
    <div class="field"><label for="portal_user_id">Portal user ID</label><input type="text" id="portal_user_id" name="portal_user_id" value="<?= e($old['portal_user_id'] ?? '') ?>"<?= aria_err($errors, 'portal_user_id') ?>><?= field_error($errors, 'portal_user_id') ?></div>
    <div class="field"><label for="role">Role</label><select id="role" name="role"<?= aria_err($errors, 'role') ?>><option value="">Choose…</option>
      <?php foreach ($roles as $r): ?><option value="<?= e($r->value) ?>"<?= selected($old['role'] ?? '', $r->value) ?>><?= e($r->label()) ?></option><?php endforeach; ?></select><?= field_error($errors, 'role') ?></div>
    <div class="field"><label for="department_id">Department (optional)</label><select id="department_id" name="department_id"><option value="">None</option>
      <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"<?= selected($old['department_id'] ?? '', $d['id']) ?>><?= e($d['name']) ?></option><?php endforeach; ?></select><?= field_error($errors, 'department_id') ?></div>
    <button class="btn" type="submit">Add</button>
  </div>
</form>
<div class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>Portal user</th><th>Role</th><th>Department</th><th></th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td><?= e($r['portal_user_id']) ?></td><td><?= e(\App\Domain\Role::tryFrom($r['role'])?->label() ?? $r['role']) ?></td><td><?= e($r['department_name'] ?? '—') ?></td>
        <td><form method="post" action="<?= e(url('/admin/users/' . (int) $r['id'] . '/delete')) ?>"><?= csrf_field() ?><button class="btn-link" type="submit">Remove</button></form></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
