<?php

declare(strict_types=1);

namespace App\Engine;

final class TemplateMatch
{
    /**
     * @param list<string> $matchedSignatures
     * @param list<string> $staleReasons
     */
    public function __construct(
        public readonly TemplateCandidate $template,
        public readonly string $matchType, // name_department | signature
        public readonly string $reason,
        public readonly array $matchedSignatures,
        public readonly bool $stale,
        public readonly array $staleReasons,
    ) {
    }

    public function toArray(): array
    {
        return [
            'template_id' => $this->template->id,
            'activity_name' => $this->template->activityName,
            'department' => $this->template->departmentName,
            'match_type' => $this->matchType,
            'why_matched' => $this->reason,
            'matched_signatures' => $this->matchedSignatures,
            'stale' => $this->stale,
            'stale_reasons' => $this->staleReasons,
        ];
    }
}
