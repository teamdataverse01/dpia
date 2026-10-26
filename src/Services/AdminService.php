<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\ControlCategory;
use App\Domain\Factor;
use App\Domain\Permission as P;
use App\Domain\PortalUser;
use App\Domain\Role;
use App\Engine\InvalidConfigException;
use App\Engine\RiskTriggerEvaluator;
use App\Engine\ScoringConfig;
use App\Infrastructure\AuditLogger;
use App\Infrastructure\PdoConnection;
use App\Repositories\ConfigRepository;
use App\Repositories\ControlRepository;
use App\Repositories\ReferenceRepository;
use App\Support\NotFoundException;
use App\Support\ValidationException;

/** Administrator configuration (PRD 8.1, 8.2, 9.1, 12, 16 milestone 8). Every change is versioned or audited. */
final class AdminService
{
    public function __construct(
        private readonly PdoConnection $db,
        private readonly ConfigRepository $configs,
        private readonly ReferenceRepository $refs,
        private readonly ControlRepository $controls,
        private readonly RiskTriggerEvaluator $triggers,
        private readonly Authorizer $authz,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Save a new scoring_config version (never edits in place). Rejects weights not totalling 100%.
     *
     * @throws ValidationException
     */
    public function saveConfig(PortalUser $user, array $data, ?string $note): int
    {
        $this->authz->authorize($user, P::AdminConfig);
        $data = self::coerceConfig($data);
        $errors = ScoringConfig::validate($data);
        if ($errors !== []) {
            throw new ValidationException('The configuration was not saved.', $errors);
        }
        try {
            $config = ScoringConfig::fromArray($data, 0);
        } catch (InvalidConfigException $e) {
            throw new ValidationException('The configuration was not saved.', $e->errors);
        }
        $old = $this->configs->active();
        return $this->db->transaction(function () use ($user, $config, $note, $old) {
            $version = $this->configs->createVersion($config->toArray(), $user->id, $note);
            $this->audit->record($user->id, 'scoring_config', $version, 'new_version', ['version' => $old->version] + $old->toArray(), ['version' => $version, 'note' => $note] + $config->toArray());
            return $version;
        });
    }

    /** Turn form strings into numbers; leaves already-typed JSON input alone. */
    public static function coerceConfig(array $d): array
    {
        $num = fn ($v) => is_numeric($v) ? $v + 0 : $v;
        foreach (Factor::cases() as $f) {
            if (isset($d['weights'][$f->value])) {
                $d['weights'][$f->value] = $num($d['weights'][$f->value]);
            }
        }
        foreach (['low_max', 'medium_max'] as $k) {
            if (isset($d['bands'][$k])) {
                $d['bands'][$k] = $num($d['bands'][$k]);
            }
        }
        foreach (ControlCategory::cases() as $c) {
            if (isset($d['reduction_weights'][$c->value])) {
                $d['reduction_weights'][$c->value] = $num($d['reduction_weights'][$c->value]);
            }
        }
        foreach (['clearance_min_effectiveness', 'template_staleness_months', 'template_signature_threshold'] as $k) {
            if (isset($d[$k])) {
                $d[$k] = $num($d[$k]);
            }
        }
        foreach ($d['review_interval_months'] ?? [] as $k => $v) {
            $d['review_interval_months'][$k] = $num($v);
        }
        foreach ($d['approval_routing'] ?? [] as $k => $v) {
            $d['approval_routing'][$k] = is_string($v) ? array_values(array_filter(array_map('trim', explode(',', $v)))) : array_values((array) $v);
        }
        return $d;
    }

    /**
     * Edit a factor option. A score change creates a new scoring_config version so assessments
     * show they were scored under an older framework until rescored (DECISIONS D-12).
     */
    public function updateFactorOption(PortalUser $user, int $id, array $in): void
    {
        $this->authz->authorize($user, P::AdminConfig);
        $opt = $this->refs->factorOptionById($id);
        if ($opt === null) {
            throw new NotFoundException('Option not found.');
        }
        $label = trim((string) ($in['label'] ?? ''));
        $help = trim((string) ($in['help_text'] ?? ''));
        $score = (string) ($in['score'] ?? '');
        $sort = (string) ($in['sort_order'] ?? $opt['sort_order']);
        $errors = [];
        if ($label === '') {
            $errors['label'] = 'Label is required.';
        }
        if (mb_strlen($help) > 300) {
            $errors['help_text'] = 'Keep help text under 300 characters.';
        }
        if (!ctype_digit($score) || (int) $score < 1 || (int) $score > 5) {
            $errors['score'] = 'Score must be 1–5.';
        }
        if (!preg_match('/^-?\d+$/', $sort)) {
            $errors['sort_order'] = 'Order must be a whole number.';
        }
        if ($errors !== []) {
            throw new ValidationException('The option was not saved.', $errors);
        }
        $this->db->transaction(function () use ($user, $id, $opt, $label, $help, $score, $sort) {
            $this->refs->updateFactorOption($id, $label, $help === '' ? null : $help, (int) $score, (int) $sort);
            $new = ['label' => $label, 'help_text' => $help, 'score' => (int) $score, 'sort_order' => (int) $sort];
            $this->audit->record($user->id, 'factor_options', $id, 'update', array_intersect_key($opt, $new), $new + ['factor' => $opt['factor'], 'option_key' => $opt['option_key']]);
            if ((int) $opt['score'] !== (int) $score && $opt['factor'] !== 'data_type') {
                $current = $this->configs->active();
                $version = $this->configs->createVersion($current->toArray(), $user->id, sprintf(
                    'Option score changed: %s / %s from %d to %d.',
                    $opt['factor'],
                    $opt['option_key'],
                    (int) $opt['score'],
                    (int) $score,
                ));
                $this->audit->record($user->id, 'scoring_config', $version, 'new_version', ['version' => $current->version], ['version' => $version, 'reason' => 'factor option score change']);
            }
        });
    }

    public function createFactorOption(PortalUser $user, array $in): int
    {
        $this->authz->authorize($user, P::AdminConfig);
        $factor = (string) ($in['factor'] ?? '');
        $key = strtolower(trim((string) ($in['option_key'] ?? '')));
        $errors = [];
        if (Factor::tryFrom($factor) === null && $factor !== 'data_type' || $factor === Factor::VendorRisk->value) {
            $errors['factor'] = 'Choose a factor.';
        }
        if (!preg_match('/^[a-z0-9_]{1,80}$/', $key)) {
            $errors['option_key'] = 'Use lowercase letters, digits and underscores.';
        } elseif ($this->refs->factorOption($factor, $key) !== null) {
            $errors['option_key'] = 'That key already exists for this factor.';
        }
        $score = (string) ($in['score'] ?? '');
        if (!ctype_digit($score) || (int) $score < 1 || (int) $score > 5) {
            $errors['score'] = 'Score must be 1–5.';
        }
        if (trim((string) ($in['label'] ?? '')) === '') {
            $errors['label'] = 'Label is required.';
        }
        if ($errors !== []) {
            throw new ValidationException('The option was not added.', $errors);
        }
        return $this->db->transaction(function () use ($user, $factor, $key, $in, $score) {
            $id = $this->refs->createFactorOption($factor, $key, trim((string) $in['label']), trim((string) ($in['help_text'] ?? '')) ?: null, (int) $score, (int) ($in['sort_order'] ?? 99));
            $this->audit->record($user->id, 'factor_options', $id, 'create', null, ['factor' => $factor, 'option_key' => $key, 'score' => (int) $score]);
            return $id;
        });
    }

    /** Create or update a library control. */
    public function saveControl(PortalUser $user, ?int $id, array $in): int
    {
        $this->authz->authorize($user, P::AdminConfig);
        $v = [
            'category' => (string) ($in['category'] ?? ''),
            'title' => trim((string) ($in['title'] ?? '')),
            'description' => trim((string) ($in['description'] ?? '')),
            'risk_trigger' => trim((string) ($in['risk_trigger'] ?? '')),
            'mandatory' => FactorCatalog::parseBool($in['mandatory'] ?? false),
            'expected_evidence' => trim((string) ($in['expected_evidence'] ?? '')) ?: null,
            'owner_role' => trim((string) ($in['owner_role'] ?? '')) ?: null,
            'verification_required' => FactorCatalog::parseBool($in['verification_required'] ?? false),
            'active' => FactorCatalog::parseBool($in['active'] ?? true),
        ];
        $errors = [];
        if (ControlCategory::tryFrom($v['category']) === null) {
            $errors['category'] = 'Choose Policy, Process or Technology.';
        }
        if ($v['title'] === '') {
            $errors['title'] = 'Title is required.';
        }
        if ($v['description'] === '') {
            $errors['description'] = 'Description is required.';
        }
        $triggerError = $this->triggers->validate($v['risk_trigger']);
        if ($triggerError !== null) {
            $errors['risk_trigger'] = $triggerError;
        }
        if ($v['owner_role'] !== null && Role::tryFrom($v['owner_role']) === null) {
            $errors['owner_role'] = 'Unknown role.';
        }
        if ($errors !== []) {
            throw new ValidationException('The control was not saved.', $errors);
        }
        return $this->db->transaction(function () use ($user, $id, $v) {
            if ($id === null) {
                $newId = $this->controls->create($v);
                $this->audit->record($user->id, 'controls', $newId, 'create', null, $v);
                return $newId;
            }
            $old = $this->controls->find($id);
            if ($old === null) {
                throw new NotFoundException('Control not found.');
            }
            $this->controls->update($id, $v);
            $this->audit->record($user->id, 'controls', $id, 'update', array_intersect_key($old, $v), $v);
            return $id;
        });
    }

    public function addUserRole(PortalUser $user, string $portalUserId, string $role, ?int $departmentId): void
    {
        $this->authz->authorize($user, P::AdminConfig);
        $r = Role::tryFrom($role);
        $portalUserId = trim($portalUserId);
        $errors = [];
        if ($portalUserId === '') {
            $errors['portal_user_id'] = 'Enter the portal user id.';
        }
        if ($r === null) {
            $errors['role'] = 'Choose a role.';
        }
        if ($departmentId !== null && $this->refs->department($departmentId) === null) {
            $errors['department_id'] = 'Unknown department.';
        }
        if ($errors === [] && in_array($r, $this->refs->rolesFor($portalUserId)['roles'], true)) {
            $errors['role'] = 'This user already has that role.';
        }
        if ($errors !== []) {
            throw new ValidationException('The role was not added.', $errors);
        }
        $this->db->transaction(function () use ($user, $portalUserId, $r, $departmentId) {
            $id = $this->refs->addUserRole($portalUserId, $r, $departmentId);
            $this->audit->record($user->id, 'user_roles', $id, 'create', null, ['portal_user_id' => $portalUserId, 'role' => $r->value, 'department_id' => $departmentId]);
        });
    }

    public function removeUserRole(PortalUser $user, int $id): void
    {
        $this->authz->authorize($user, P::AdminConfig);
        $row = $this->refs->findUserRole($id);
        if ($row === null) {
            throw new NotFoundException('Role assignment not found.');
        }
        $this->db->transaction(function () use ($user, $id, $row) {
            $this->refs->removeUserRole($id);
            $this->audit->record($user->id, 'user_roles', $id, 'delete', $row, null);
        });
    }
}
