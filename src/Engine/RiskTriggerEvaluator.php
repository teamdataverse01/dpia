<?php

declare(strict_types=1);

namespace App\Engine;

use App\Domain\EntityType;
use App\Domain\Factor;
use App\Domain\Rating;

/**
 * Evaluates the machine-readable `controls.risk_trigger` rules (PRD 9.1).
 *
 * Grammar (whitespace ignored):
 *   rule  := all ( "|" all )*            -- OR
 *   all   := atom ( "&" atom )*          -- AND
 *   atom  := "always"
 *          | <factor> <op> <1-5>         e.g. data_classification>=4
 *          | involves_data_sharing=true|false
 *          | inherent_rating <op> low|medium|high
 *          | entity_type=vendor|processing_activity
 *          | data_type~<type>            data_type list contains <type>
 *   op    := >= | <= | > | < | = | !=
 *
 * A factor that is not active for the assessment (e.g. vendor_risk without sharing) never matches.
 */
final class RiskTriggerEvaluator
{
    private const ATOM = '/^([a-z_]+)\s*(>=|<=|!=|=|>|<|~)\s*([a-z0-9_]+)$/';

    public function matches(string $rule, RiskProfile $p): bool
    {
        foreach (explode('|', $rule) as $orPart) {
            $all = true;
            foreach (explode('&', $orPart) as $atom) {
                if (!$this->atom(trim($atom), $p)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }
        return false;
    }

    /** Validate a rule; returns an error message or null when valid. */
    public function validate(string $rule): ?string
    {
        if (trim($rule) === '') {
            return 'Rule is empty.';
        }
        foreach (explode('|', $rule) as $orPart) {
            foreach (explode('&', $orPart) as $atom) {
                $atom = trim($atom);
                if ($atom === 'always') {
                    continue;
                }
                if (!preg_match(self::ATOM, $atom, $m)) {
                    return "Cannot read \"{$atom}\".";
                }
                [, $name, $op, $value] = $m;
                $err = match (true) {
                    Factor::tryFrom($name) !== null => ctype_digit($value) && (int) $value >= 1 && (int) $value <= 5 && $op !== '~' ? null : "{$name} needs a score 1–5.",
                    $name === 'involves_data_sharing' => in_array($value, ['true', 'false'], true) && in_array($op, ['=', '!='], true) ? null : 'involves_data_sharing needs =true or =false.',
                    $name === 'inherent_rating' => in_array($value, ['low', 'medium', 'high'], true) && $op !== '~' ? null : 'inherent_rating needs low, medium or high.',
                    $name === 'entity_type' => EntityType::tryFrom($value) !== null && in_array($op, ['=', '!='], true) ? null : 'entity_type needs =vendor or =processing_activity.',
                    $name === 'data_type' => $op === '~' ? null : 'data_type only supports ~ (contains).',
                    default => "Unknown field \"{$name}\".",
                };
                if ($err !== null) {
                    return $err;
                }
            }
        }
        return null;
    }

    /** Plain-language description of a rule, for "why was this recommended". */
    public function describe(string $rule): string
    {
        $ors = [];
        foreach (explode('|', $rule) as $orPart) {
            $ands = [];
            foreach (explode('&', $orPart) as $atom) {
                $ands[] = $this->describeAtom(trim($atom));
            }
            $ors[] = implode(' and ', $ands);
        }
        return implode(', or ', $ors);
    }

    private function describeAtom(string $atom): string
    {
        if ($atom === 'always') {
            return 'every assessment';
        }
        if (!preg_match(self::ATOM, $atom, $m)) {
            return $atom;
        }
        [, $name, $op, $value] = $m;
        $opText = ['>=' => 'is at least', '<=' => 'is at most', '>' => 'is above', '<' => 'is below', '=' => 'is', '!=' => 'is not', '~' => 'includes'][$op];
        $factor = Factor::tryFrom($name);
        if ($factor !== null) {
            return "{$factor->label()} score {$opText} {$value}";
        }
        return match ($name) {
            'involves_data_sharing' => $value === 'true' ? 'the activity involves sharing data' : 'the activity does not involve sharing data',
            'inherent_rating' => "inherent risk {$opText} " . ucfirst($value),
            'entity_type' => 'this is a ' . (EntityType::from($value)->label()),
            'data_type' => "the data types include {$value}",
            default => $atom,
        };
    }

    private function atom(string $atom, RiskProfile $p): bool
    {
        if ($atom === 'always') {
            return true;
        }
        if (!preg_match(self::ATOM, $atom, $m)) {
            return false; // unreadable rules never recommend anything; admin save validates rules
        }
        [, $name, $op, $value] = $m;

        if (Factor::tryFrom($name) !== null) {
            if (!array_key_exists($name, $p->factorScores)) {
                return false;
            }
            return $this->compare($p->factorScores[$name], $op, (int) $value);
        }

        return match ($name) {
            'involves_data_sharing' => $this->compareEq($p->involvesDataSharing === ($value === 'true'), $op),
            'inherent_rating' => Rating::tryFrom($value) !== null
                && $this->compare($p->inherentRating->rank(), $op, Rating::from($value)->rank()),
            'entity_type' => $this->compareEq($p->entityType->value === $value, $op),
            'data_type' => $op === '~' && in_array($value, $p->dataTypes, true),
            default => false,
        };
    }

    private function compare(int $a, string $op, int $b): bool
    {
        return match ($op) {
            '>=' => $a >= $b,
            '<=' => $a <= $b,
            '>' => $a > $b,
            '<' => $a < $b,
            '=' => $a === $b,
            '!=' => $a !== $b,
            default => false,
        };
    }

    private function compareEq(bool $equal, string $op): bool
    {
        return match ($op) {
            '=' => $equal,
            '!=' => !$equal,
            default => false,
        };
    }
}
