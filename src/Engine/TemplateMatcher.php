<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * PRD 10 — deterministic, explainable activity-template matching and the staleness guard.
 *
 * 1. Exact match on activity name + department (case- and whitespace-insensitive).
 * 2. Otherwise, signature overlap: the number of equal signatures (data types, data subject,
 *    processing characteristics) must reach `template_signature_threshold` (default 3 = all equal).
 */
final class TemplateMatcher
{
    /** Canonical data-type signature: sorted, de-duplicated, comma-joined. */
    public static function dataTypeSignature(array $dataTypes): string
    {
        $t = array_values(array_unique(array_filter(array_map(fn ($v) => strtolower(trim((string) $v)), $dataTypes))));
        sort($t);
        return implode(',', $t);
    }

    public static function dataSubjectSignature(string $dataSubjectType): string
    {
        return strtolower(trim($dataSubjectType));
    }

    /** Processing characteristics: the option keys that describe *how* the data is processed. */
    public static function characteristicsSignature(
        string $natureOfProcessing,
        string $automatedDecisionMaking,
        string $crossBorderTransfer,
        string $thirdPartyTransfer,
    ): string {
        return sprintf(
            'nature=%s;adm=%s;cross_border=%s;third_party=%s',
            $natureOfProcessing,
            $automatedDecisionMaking,
            $crossBorderTransfer,
            $thirdPartyTransfer,
        );
    }

    public static function normaliseName(string $name): string
    {
        $n = strtolower(trim($name));
        $n = str_replace(['—', '–'], '-', $n);
        return (string) preg_replace('/\s+/', ' ', $n);
    }

    /**
     * @param list<TemplateCandidate> $templates
     * @return list<TemplateMatch> best first; exact name+department matches come before signature matches
     */
    public function match(
        array $templates,
        ?int $departmentId,
        ?string $activityName,
        ?string $dataTypeSignature,
        ?string $dataSubjectSignature,
        ?string $characteristicsSignature,
        ScoringConfig $config,
        \DateTimeImmutable $today,
    ): array {
        $exact = [];
        $bySignature = [];

        foreach ($templates as $t) {
            if (
                $departmentId !== null && $activityName !== null && $activityName !== ''
                && $t->departmentId === $departmentId
                && self::normaliseName($t->activityName) === self::normaliseName($activityName)
            ) {
                [$stale, $why] = $this->staleness($t, $config, $today);
                $exact[] = new TemplateMatch(
                    $t,
                    'name_department',
                    sprintf('The activity name "%s" and department "%s" exactly match this template.', $t->activityName, $t->departmentName),
                    [],
                    $stale,
                    $why,
                );
                continue;
            }

            $matched = [];
            if ($dataTypeSignature !== null && $dataTypeSignature !== '' && $dataTypeSignature === $t->dataTypeSignature) {
                $matched[] = 'data types';
            }
            if ($dataSubjectSignature !== null && $dataSubjectSignature !== '' && $dataSubjectSignature === $t->dataSubjectSignature) {
                $matched[] = 'data subjects';
            }
            if ($characteristicsSignature !== null && $characteristicsSignature !== '' && $characteristicsSignature === $t->characteristicsSignature) {
                $matched[] = 'processing characteristics';
            }
            if (count($matched) >= $config->templateSignatureThreshold) {
                [$stale, $why] = $this->staleness($t, $config, $today);
                $bySignature[] = new TemplateMatch(
                    $t,
                    'signature',
                    sprintf(
                        'The %s you entered are the same as this template\'s (%d of 3 must match; %d did).',
                        implode(', ', $matched),
                        $config->templateSignatureThreshold,
                        count($matched),
                    ),
                    $matched,
                    $stale,
                    $why,
                );
            }
        }

        usort($bySignature, fn (TemplateMatch $a, TemplateMatch $b) => [count($b->matchedSignatures), $a->template->id] <=> [count($a->matchedSignatures), $b->template->id]);
        return array_merge($exact, $bySignature);
    }

    /** @return array{0: bool, 1: list<string>} */
    public function staleness(TemplateCandidate $t, ScoringConfig $config, \DateTimeImmutable $today): array
    {
        $why = [];
        if ($t->frameworkVersion !== $config->version) {
            $why[] = sprintf(
                'It was scored with scoring framework version %d; the current version is %d.',
                $t->frameworkVersion,
                $config->version,
            );
        }
        $limit = $today->modify(sprintf('-%d months', $config->templateStalenessMonths))->format('Y-m-d');
        if ($t->lastReviewedAt < $limit) {
            $why[] = sprintf(
                'It was last reviewed on %s, more than %d months ago.',
                $t->lastReviewedAt,
                $config->templateStalenessMonths,
            );
        }
        return [$why !== [], $why];
    }
}
