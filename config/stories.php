<?php

declare(strict_types=1);

/*
 * Dashboard "Key risk areas" wording (PRD 11.4).
 * Edit the sentences here; the numbers and links are computed in src/Services/StoryGenerator.php.
 * Placeholders in {braces} are filled in by the generator. Keep the language plain.
 */
return [
    'top_factors' => [
        'two' => '{factor1} and {factor2} account for most current High-risk items ({count} of {total}).',
        'one' => '{factor1} drives most current High-risk items ({count} of {total}).',
    ],
    'top_departments' => [
        'two' => '{dept1} and {dept2} carry the most open High and Medium risk ({count1} and {count2} items), largely driven by {driver}.',
        'one' => '{dept1} carries the most open High and Medium risk ({count1} items), largely driven by {driver}.',
    ],
    'control_effectiveness' => [
        'best' => '{category} controls are closing the most risk: assessments where they are cleared show an average {pct}% reduction.',
        'policy_only' => ' Items with only Policy controls cleared are slowest to move off High: {count} still rated Medium or High.',
    ],
    'remediation_health' => [
        'overdue' => '{count} remediation {actions} overdue; the oldest is {days} days late ("{oldest}").',
        'on_track' => 'All {count} open remediation {actions} on schedule.',
    ],
    'trend' => [
        'down' => 'High risks down {pct}% since {date} ({from} → {to}).',
        'up' => 'High risks up {pct}% since {date} ({from} → {to}).',
        'from_zero' => 'High risks rose from none to {to} since {date}.',
        'flat' => 'High risks unchanged since {date} ({to}).',
    ],
];
