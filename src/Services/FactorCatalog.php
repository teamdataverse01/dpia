<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\EntityType;
use App\Domain\Factor;
use App\Engine\FactorSelection;
use App\Engine\VendorRiskMapper;
use App\Repositories\ReferenceRepository;
use App\Repositories\RiskEntityRepository;
use App\Support\ValidationException;

/**
 * The shared factor panel (PRD 7): validation of submitted factor values, including the
 * vendor-risk condition (7.1), and conversion into engine FactorSelections.
 */
final class FactorCatalog
{
    /** Factor-input columns that hold a factor_options key (same names as the Factor enum values). */
    public const OPTION_FIELDS = [
        'data_classification', 'data_volume', 'data_subject_type', 'legal_basis',
        'cross_border_transfer', 'third_party_transfer', 'nature_of_processing', 'automated_decision_making',
    ];

    public function __construct(
        private readonly ReferenceRepository $refs,
        private readonly RiskEntityRepository $entities,
    ) {
    }

    /** @return array<string,list<array<string,mixed>>> */
    public function options(): array
    {
        return $this->refs->factorOptions();
    }

    public static function parseBool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Validate raw input (form or JSON) and return normalised factor values ready to store.
     *
     * @throws ValidationException
     */
    public function normalise(EntityType $type, array $in, ?int $selfId = null): array
    {
        $errors = [];
        $out = [];

        $types = $in['data_type'] ?? [];
        if (is_string($types)) {
            $types = $types === '' ? [] : explode(',', $types);
        }
        $types = array_values(array_unique(array_map(fn ($t) => trim((string) $t), is_array($types) ? $types : [])));
        if ($types === []) {
            $errors['data_type'] = 'Choose at least one type of data.';
        }
        foreach ($types as $t) {
            if ($this->refs->factorOption('data_type', $t) === null) {
                $errors['data_type'] = 'Unknown data type "' . $t . '".';
            }
        }
        $out['data_type'] = $types;

        foreach (self::OPTION_FIELDS as $field) {
            $v = isset($in[$field]) ? trim((string) $in[$field]) : '';
            if ($field === 'legal_basis' && $type === EntityType::Vendor) {
                if ($v !== '') {
                    $errors['legal_basis'] = 'Legal basis is not asked of vendors; it is supplied by each processing activity.';
                }
                $out['legal_basis'] = null;
                continue;
            }
            if ($v === '') {
                $errors[$field] = 'Please choose an option for "' . Factor::from($field)->label() . '".';
                $out[$field] = null;
                continue;
            }
            if ($this->refs->factorOption($field, $v) === null) {
                $errors[$field] = 'That option is not valid for "' . Factor::from($field)->label() . '".';
            }
            $out[$field] = $v;
        }

        // 7.1 — the vendor-risk condition.
        $sharing = self::parseBool($in['involves_data_sharing'] ?? false);
        $vendorRef = isset($in['vendor_risk_ref']) && $in['vendor_risk_ref'] !== '' && $in['vendor_risk_ref'] !== null
            ? (int) $in['vendor_risk_ref'] : null;

        if ($type === EntityType::Vendor) {
            if ($sharing || $vendorRef !== null) {
                $errors['involves_data_sharing'] = 'Vendor assessments never include a vendor-risk factor, so "involves data sharing" and a linked vendor do not apply.';
            }
            $sharing = false;
            $vendorRef = null;
        } elseif (!$sharing && $vendorRef !== null) {
            $errors['vendor_risk_ref'] = 'A vendor can only be linked when the activity involves sharing data. Either answer "Yes" to data sharing or remove the vendor.';
        } elseif ($sharing && $vendorRef === null) {
            $errors['vendor_risk_ref'] = 'This activity shares data, so choose the vendor (third party) that receives it. Their risk score becomes part of this DPIA.';
        } elseif ($sharing && $vendorRef !== null) {
            $vendor = $this->entities->find($vendorRef);
            if ($vendor === null || $vendor['entity_type'] !== EntityType::Vendor->value) {
                $errors['vendor_risk_ref'] = 'The selected vendor does not exist.';
            } elseif ($vendorRef === $selfId) {
                $errors['vendor_risk_ref'] = 'An assessment cannot reference itself.';
            } elseif ($vendor['inherent_score'] === null) {
                $errors['vendor_risk_ref'] = 'This vendor has not been risk-assessed yet, so it has no score to use. Assess the vendor first.';
            }
        }
        $out['involves_data_sharing'] = $sharing;
        $out['vendor_risk_ref'] = $vendorRef;

        if ($errors !== []) {
            throw new ValidationException('Some answers need attention.', $errors);
        }
        return $out;
    }

    /**
     * Convert stored/normalised factor values into engine selections. The vendor factor is read LIVE
     * from the linked vendor's current residual score (7.1 rule 2; 10.5).
     *
     * @return list<FactorSelection>
     */
    public function selections(EntityType $type, array $factors): array
    {
        $out = [];
        foreach (self::OPTION_FIELDS as $field) {
            if ($field === 'legal_basis' && $type === EntityType::Vendor) {
                continue;
            }
            $opt = $this->refs->factorOption($field, (string) $factors[$field]);
            if ($opt === null) {
                throw new ValidationException('A stored factor option no longer exists: ' . $field . '=' . $factors[$field], [$field => 'Please choose again.']);
            }
            $out[] = new FactorSelection(Factor::from($field), $opt['option_key'], (int) $opt['score'], $opt['label']);
        }
        if ($type === EntityType::ProcessingActivity && !empty($factors['involves_data_sharing'])) {
            $out[] = $this->vendorSelection((int) $factors['vendor_risk_ref']);
        }
        return $out;
    }

    public function vendorSelection(int $vendorId): FactorSelection
    {
        $v = $this->entities->find($vendorId);
        if ($v === null || $v['inherent_score'] === null) {
            throw new ValidationException('The linked vendor has no risk score yet.', ['vendor_risk_ref' => 'Assess the vendor first.']);
        }
        $score100 = self::vendorScore100($v);
        $factorScore = VendorRiskMapper::factorScore($score100);
        return new FactorSelection(
            Factor::VendorRisk,
            'vendor:' . $vendorId,
            $factorScore,
            sprintf('%s — vendor score %d/100 (%s band)', $v['name'], $score100, VendorRiskMapper::bandLabel($factorScore)),
        );
    }

    /** The vendor's current 0–100 score: residual when calculated, otherwise derived from inherent. */
    public static function vendorScore100(array $vendor): int
    {
        if ($vendor['residual_score_100'] !== null) {
            return (int) $vendor['residual_score_100'];
        }
        return VendorRiskMapper::to100((float) $vendor['inherent_score']);
    }

    /** Factor scores keyed by factor value (for risk-trigger evaluation). */
    public static function scoreMap(array $selections): array
    {
        $m = [];
        foreach ($selections as $s) {
            $m[$s->factor->value] = $s->score;
        }
        return $m;
    }

    /** Plain label for one stored option. */
    public function label(string $factor, ?string $key): string
    {
        if ($key === null || $key === '') {
            return '—';
        }
        return $this->refs->factorOption($factor, $key)['label'] ?? $key;
    }
}
