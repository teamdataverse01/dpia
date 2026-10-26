<?php

declare(strict_types=1);

/*
 * Template helpers (autoloaded via composer "files"). Plain functions so templates stay readable.
 */

use App\Domain\Rating;
use App\Http\Csrf;
use App\Http\View;

if (!function_exists('e')) {
    /** HTML-escape for text and attribute contexts. */
    function e(mixed $v): string
    {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** App-relative URL honouring APP_BASE_PATH (module mounted inside the portal). */
    function url(string $path, array $query = []): string
    {
        $q = array_filter($query, fn ($v) => $v !== null && $v !== '');
        return View::$basePath . $path . ($q ? '?' . http_build_query($q) : '');
    }

    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
    }

    function rating_badge(?string $rating, string $prefix = ''): string
    {
        if ($rating === null || $rating === '') {
            return '<span class="badge badge-none">Not scored</span>';
        }
        $r = Rating::tryFrom($rating);
        return '<span class="badge badge-' . e($rating) . '">' . e($prefix . ($r?->label() ?? $rating)) . '</span>';
    }

    function status_badge(string $status): string
    {
        $s = \App\Domain\AssessmentStatus::tryFrom($status);
        return '<span class="status status-' . e($status) . '">' . e($s?->label() ?? $status) . '</span>';
    }

    /** Plain-language definitions for glossary terms (PRD 1 glossary, 15). */
    function glossary(string $term): string
    {
        return [
            'dpia' => 'Data Protection Impact Assessment — a structured check, done before a risky data activity starts, of how risky it is and what safeguards are needed.',
            'dsar' => 'Data Subject Access Request — a person asking to see, correct or delete the data CU holds about them.',
            'inherent' => 'Inherent risk — how risky the activity is before any safeguards are in place.',
            'residual' => 'Residual risk — the risk that remains after safeguards (controls) are in place.',
            'control' => 'Control — a safeguard. Policy controls are documents, Process controls are procedures, Technology controls are technical measures.',
            'third_party' => 'Third party / vendor — an outside company that handles data for CU.',
            'cross_border' => 'Cross-border transfer — data sent to, or stored in, a country outside Nigeria.',
            'legal_basis' => 'Legal basis — the lawful reason CU is allowed to use the data (for example consent, contract or a legal obligation).',
            'data_sharing' => 'Data sharing — giving the data to an organisation outside CU (for example JAMB or a software vendor).',
            'rbac' => 'Role-based access control — people can only do what their role allows.',
        ][$term] ?? '';
    }

    /** A term with an accessible tooltip (works on hover and keyboard focus; definition also readable by screen readers). */
    function term(string $label, string $key): string
    {
        $def = glossary($key);
        return '<span class="term" tabindex="0">' . e($label) . '<span class="term-tip" role="tooltip">' . e($def) . '</span></span>';
    }

    function fmt_date(?string $d): string
    {
        if ($d === null || $d === '') {
            return '—';
        }
        $t = strtotime(substr($d, 0, 19));
        return $t === false ? e($d) : date('j M Y', $t);
    }

    function fmt_datetime(?string $d): string
    {
        if ($d === null || $d === '') {
            return '—';
        }
        $t = strtotime(substr($d, 0, 19));
        return $t === false ? e($d) : date('j M Y, H:i', $t);
    }

    function field_error(array $errors, string $field): string
    {
        return isset($errors[$field]) ? '<p class="field-error" id="err-' . e($field) . '">' . e($errors[$field]) . '</p>' : '';
    }

    function aria_err(array $errors, string $field): string
    {
        return isset($errors[$field]) ? ' aria-invalid="true" aria-describedby="err-' . e($field) . '"' : '';
    }

    function selected(mixed $a, mixed $b): string
    {
        return (string) $a === (string) $b ? ' selected' : '';
    }

    function checked(bool $c): string
    {
        return $c ? ' checked' : '';
    }
}
