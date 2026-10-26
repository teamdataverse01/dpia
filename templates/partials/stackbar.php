<?php
/**
 * Proportional stacked bar (SVG, no inline styles so the strict CSP holds).
 * Segments are separated by a 2px surface gap; each has a native hover tooltip (<title>).
 * @var array<string,int> $counts rating => count  @var string $label accessible name  @var string $barClass
 */
$sum = array_sum($counts);
$order = ['high', 'medium', 'low', 'very_low'];
$names = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low', 'very_low' => 'Very Low'];
$W = 1000;
$gap = 2;
$present = array_values(array_filter($order, fn ($r) => ($counts[$r] ?? 0) > 0));
$usable = $W - $gap * max(0, count($present) - 1);
$x = 0.0;
?>
<svg class="<?= e($barClass ?? 'stackbar') ?>" viewBox="0 0 <?= $W ?> 28" preserveAspectRatio="none" role="img" aria-label="<?= e($label) ?>">
  <?php if ($sum === 0): ?>
    <rect class="fill-track" x="0" y="0" width="<?= $W ?>" height="28" rx="4"><title>No rated items</title></rect>
  <?php else: foreach ($present as $i => $r): $w = $usable * $counts[$r] / $sum; ?>
    <rect class="fill-<?= e($r) ?>" x="<?= e(number_format($x, 2, '.', '')) ?>" y="0" width="<?= e(number_format($w, 2, '.', '')) ?>" height="28"><title><?= e($names[$r] . ': ' . $counts[$r]) ?></title></rect>
  <?php $x += $w + $gap; endforeach; endif; ?>
</svg>
