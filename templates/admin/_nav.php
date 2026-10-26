<?php $here = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''; ?>
<nav aria-label="Administration">
  <ul class="stepper">
    <?php foreach (['/admin/config' => 'Scoring configuration', '/admin/factor-options' => 'Factor options', '/admin/controls' => 'Control library', '/admin/users' => 'User roles', '/templates' => 'Templates', '/audit' => 'Audit log'] as $p => $l): ?>
      <li><a href="<?= e(url($p)) ?>"<?= str_ends_with($here, $p) ? ' aria-current="step"' : '' ?>><?= e($l) ?></a></li>
    <?php endforeach; ?>
  </ul>
</nav>
