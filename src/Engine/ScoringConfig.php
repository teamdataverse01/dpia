<?php

declare(strict_types=1);

namespace App\Engine;

use App\Domain\ControlCategory;
use App\Domain\Factor;
use App\Domain\Rating;
use App\Domain\Role;

/**
 * Immutable, validated view of one `scoring_config` version (PRD 8.1, 8.4, 9.2, 9.4, 10, 13, 16).
 * Weights are percentages (they must total 100). Everything the engine needs comes from here,
 * so changing a weight or threshold never requires a code change.
 */
final class ScoringConfig
{
    /**
     * @param array<string,float> $weights factor value => percentage
     * @param array<string,float> $reductionWeights category value => fraction 0..1
     * @param array<string,int> $reviewIntervalMonths rating value => months
     * @param array<string,list<string>> $approvalRouting rating value => list of role values
     */
    private function __construct(
        public readonly int $version,
        public readonly array $weights,
        public readonly float $lowMax,
        public readonly float $mediumMax,
        public readonly array $reductionWeights,
        public readonly int $clearanceMinEffectiveness,
        public readonly array $reviewIntervalMonths,
        public readonly int $templateStalenessMonths,
        public readonly int $templateSignatureThreshold,
        public readonly array $approvalRouting,
    ) {
    }

    /** The PRD default values (scoring_config v1). */
    public static function defaults(): array
    {
        return [
            'weights' => [
                'data_classification' => 20,
                'data_volume' => 15,
                'automated_decision_making' => 15,
                'cross_border_transfer' => 15,
                'vendor_risk' => 10,
                'third_party_transfer' => 10,
                'nature_of_processing' => 5,
                'data_subject_type' => 5,
                'legal_basis' => 5,
            ],
            'bands' => ['low_max' => 2.0, 'medium_max' => 3.5],
            'reduction_weights' => ['policy' => 0.15, 'process' => 0.25, 'technology' => 0.40],
            'clearance_min_effectiveness' => 70,
            'review_interval_months' => ['high' => 6, 'medium' => 12, 'low' => 24, 'very_low' => 24],
            'template_staleness_months' => 12,
            'template_signature_threshold' => 3,
            'approval_routing' => [
                'high' => ['privacy_dpo'],
                'medium' => ['privacy_dpo'],
                'low' => ['reviewer'],
                'very_low' => ['reviewer'],
            ],
        ];
    }

    /** @throws InvalidConfigException */
    public static function fromArray(array $data, int $version): self
    {
        $errors = self::validate($data);
        if ($errors !== []) {
            throw new InvalidConfigException($errors);
        }

        $weights = [];
        foreach (Factor::cases() as $f) {
            $weights[$f->value] = (float) $data['weights'][$f->value];
        }
        $reduction = [];
        foreach (ControlCategory::cases() as $c) {
            $reduction[$c->value] = (float) $data['reduction_weights'][$c->value];
        }
        $intervals = [];
        foreach (Rating::cases() as $r) {
            $intervals[$r->value] = (int) $data['review_interval_months'][$r->value];
        }
        $routing = [];
        foreach (Rating::cases() as $r) {
            $routing[$r->value] = array_values(array_map('strval', $data['approval_routing'][$r->value]));
        }

        return new self(
            $version,
            $weights,
            (float) $data['bands']['low_max'],
            (float) $data['bands']['medium_max'],
            $reduction,
            (int) $data['clearance_min_effectiveness'],
            $intervals,
            (int) $data['template_staleness_months'],
            (int) $data['template_signature_threshold'],
            $routing,
        );
    }

    /**
     * Validation used at config save. Returns field => plain-language message; empty when valid.
     *
     * @return array<string,string>
     */
    public static function validate(array $data): array
    {
        $errors = [];

        $weights = $data['weights'] ?? null;
        if (!is_array($weights)) {
            $errors['weights'] = 'Factor weights are missing.';
        } else {
            $sum = 0.0;
            foreach (Factor::cases() as $f) {
                $w = $weights[$f->value] ?? null;
                if (!is_numeric($w) || (float) $w < 0) {
                    $errors['weights.' . $f->value] = 'Weight for "' . $f->label() . '" must be a number of 0 or more.';
                    continue;
                }
                $sum += (float) $w;
            }
            if (!isset($errors['weights']) && abs($sum - 100.0) > 0.0001 && count($errors) === 0) {
                $errors['weights'] = sprintf('Factor weights must total 100%% (they currently total %s%%).', rtrim(rtrim(number_format($sum, 4, '.', ''), '0'), '.'));
            }
        }

        $low = $data['bands']['low_max'] ?? null;
        $med = $data['bands']['medium_max'] ?? null;
        if (!is_numeric($low) || !is_numeric($med)) {
            $errors['bands'] = 'Both rating band limits are required.';
        } elseif (!((float) $low >= 1.0 && (float) $low < (float) $med && (float) $med <= 5.0)) {
            $errors['bands'] = 'Bands must satisfy 1.0 ≤ Low limit < Medium limit ≤ 5.0.';
        }

        $red = $data['reduction_weights'] ?? null;
        if (!is_array($red)) {
            $errors['reduction_weights'] = 'Category reduction weights are missing.';
        } else {
            $sum = 0.0;
            $ok = true;
            foreach (ControlCategory::cases() as $c) {
                $v = $red[$c->value] ?? null;
                if (!is_numeric($v) || (float) $v < 0 || (float) $v > 1) {
                    $errors['reduction_weights.' . $c->value] = 'Reduction weight for ' . $c->label() . ' must be between 0 and 1.';
                    $ok = false;
                    continue;
                }
                $sum += (float) $v;
            }
            if ($ok) {
                if ($sum > 1.0 + 1e-9) {
                    $errors['reduction_weights'] = 'Category reduction weights must not total more than 1.0.';
                } elseif (
                    (float) $red['technology'] <= (float) $red['policy']
                    || (float) $red['technology'] <= (float) $red['process']
                ) {
                    $errors['reduction_weights'] = 'Technology must have the highest reduction weight (PRD 9.1).';
                }
            }
        }

        $eff = $data['clearance_min_effectiveness'] ?? null;
        if (!is_numeric($eff) || (int) $eff < 0 || (int) $eff > 100) {
            $errors['clearance_min_effectiveness'] = 'Minimum effectiveness must be between 0 and 100.';
        }

        foreach (Rating::cases() as $r) {
            $m = $data['review_interval_months'][$r->value] ?? null;
            if (!is_numeric($m) || (int) $m < 1) {
                $errors['review_interval_months.' . $r->value] = 'Review interval for ' . $r->label() . ' must be at least 1 month.';
            }
            $roles = $data['approval_routing'][$r->value] ?? null;
            if (!is_array($roles) || $roles === []) {
                $errors['approval_routing.' . $r->value] = 'At least one approver role is required for ' . $r->label() . '.';
            } else {
                foreach ($roles as $role) {
                    if (Role::tryFrom((string) $role) === null) {
                        $errors['approval_routing.' . $r->value] = 'Unknown role "' . $role . '".';
                    }
                }
            }
        }

        $stale = $data['template_staleness_months'] ?? null;
        if (!is_numeric($stale) || (int) $stale < 1) {
            $errors['template_staleness_months'] = 'Template staleness must be at least 1 month.';
        }
        $sig = $data['template_signature_threshold'] ?? null;
        if (!is_numeric($sig) || (int) $sig < 1 || (int) $sig > 3) {
            $errors['template_signature_threshold'] = 'Signature threshold must be 1, 2 or 3.';
        }

        return $errors;
    }

    public function toArray(): array
    {
        return [
            'weights' => $this->weights,
            'bands' => ['low_max' => $this->lowMax, 'medium_max' => $this->mediumMax],
            'reduction_weights' => $this->reductionWeights,
            'clearance_min_effectiveness' => $this->clearanceMinEffectiveness,
            'review_interval_months' => $this->reviewIntervalMonths,
            'template_staleness_months' => $this->templateStalenessMonths,
            'template_signature_threshold' => $this->templateSignatureThreshold,
            'approval_routing' => $this->approvalRouting,
        ];
    }

    public function weight(Factor $factor): float
    {
        return $this->weights[$factor->value];
    }

    public function reductionWeight(ControlCategory $category): float
    {
        return $this->reductionWeights[$category->value];
    }

    /** Band lookup on a score already rounded to 2 decimals (PRD 8.4). Compared in hundredths to avoid float drift. */
    public function ratingFor(float $score): Rating
    {
        $s = (int) round($score * 100);
        if ($s <= (int) round($this->lowMax * 100)) {
            return Rating::Low;
        }
        if ($s <= (int) round($this->mediumMax * 100)) {
            return Rating::Medium;
        }
        return Rating::High;
    }

    /** @return list<Role> */
    public function approversFor(Rating $rating): array
    {
        return array_map(fn (string $r) => Role::from($r), $this->approvalRouting[$rating->value]);
    }
}
