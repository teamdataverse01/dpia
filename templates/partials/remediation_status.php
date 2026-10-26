<?php /** @var array $a decorated remediation row */
$labels = ['open' => 'Open', 'in_progress' => 'In progress', 'overdue' => 'Overdue', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
$cls = ['overdue' => 'tag-bad', 'completed' => 'tag-ok'][$a['display_status']] ?? '';
?>
<span class="tag <?= $cls ?>"><?= e($labels[$a['display_status']] ?? $a['display_status']) ?><?= $a['display_status'] === 'overdue' ? ' · ' . (int) $a['days_overdue'] . ' days' : '' ?></span>
