# Database schema — handover notes

Source of truth: `database/migrations/*.sql` (applied in order by `php bin/console migrate`, which records them in `schema_migrations`). Reference data: `database/seeds/0001_reference_data.sql`.

## Tables

| Table | Purpose | Key rules |
|---|---|---|
| `departments` | CU departments | Unique name |
| `user_roles` | Maps portal user IDs to module roles (and optionally a department) | Unique (user, role). The portal owns users; there are no passwords here. |
| `risk_entities` | **One row per assessment**, vendor or DPIA (`entity_type`) | Holds status, inherent and residual scores and ratings, `scoring_framework_version`, `next_review_date`, `template_id` |
| `vendor_profiles` / `processing_activities` | Type-specific details (1:1 with `risk_entities`) | Cascade delete |
| `risk_factor_inputs` | The shared factor panel (1:1) | `CHECK (vendor_risk_ref IS NULL OR involves_data_sharing)` enforces PRD 7.1 |
| `factor_score_breakdown` | How each inherent score was built | A fresh set of rows per calculation, grouped by `calculated_at`; the latest set is current |
| `controls` | Control library | `risk_trigger` rule grammar: see `src/Engine/RiskTriggerEvaluator.php` |
| `control_assessments` | Per-assessment control status, effectiveness and evidence metadata | Unique (assessment, control). `source` = recommended / template / manual |
| `remediation_actions` | Gaps turned into tracked actions | "overdue" is derived (target date passed and not completed/cancelled), never stored |
| `approvals` | Decisions | `risk_accepted` requires `accepted_until` (CHECK) |
| `activity_templates` (+ `activity_template_controls`) | DPIA fast path | Unique (department, activity name). The vendor score is read live, never frozen |
| `scoring_config` | Versioned configuration JSON | Exactly one `active`. Saving always inserts a new version; nothing is edited in place |
| `factor_options` | Answer → 1–5 score, with plain-language help text | Unique (factor, key). `factor='data_type'` rows are not scored |
| `risk_snapshots` | Daily counts for dashboard trends | Written by `jobs:daily` (replaces the same day's rows) |
| `audit_log` | Every state change: who, when, old and new values | **Append-only**: a trigger blocks UPDATE/DELETE. Also grant the app user INSERT/SELECT only |

## scoring_config JSON

```json
{
  "weights": {"data_classification":20,"data_volume":15,"automated_decision_making":15,"cross_border_transfer":15,
              "vendor_risk":10,"third_party_transfer":10,"nature_of_processing":5,"data_subject_type":5,"legal_basis":5},
  "bands": {"low_max":2.0,"medium_max":3.5},
  "reduction_weights": {"policy":0.15,"process":0.25,"technology":0.40},
  "clearance_min_effectiveness": 70,
  "review_interval_months": {"high":6,"medium":12,"low":24,"very_low":24},
  "template_staleness_months": 12,
  "template_signature_threshold": 3,
  "approval_routing": {"high":["privacy_dpo"],"medium":["privacy_dpo"],"low":["reviewer"],"very_low":["reviewer"]}
}
```

It is validated by `App\Engine\ScoringConfig::validate()`: weights must total 100, bands must be ordered, Technology must have the highest reduction weight, and roles must exist.

## Assessment status lifecycle

`draft → scored → controls_in_progress → residual_calculated → pending_approval → approved | rejected | risk_accepted → monitoring → reassessment_due`

Allowed transitions are in `App\Domain\AssessmentStatus::allowedNext()`. Every transition writes an `audit_log` row in the same transaction.

## Porting to MySQL/MariaDB

1. Replace `BIGINT GENERATED ALWAYS AS IDENTITY` with `BIGINT AUTO_INCREMENT`.
2. In `PdoConnection::insert()`, replace `RETURNING id` with `lastInsertId()`.
3. Rewrite `0002_audit_log_append_only.sql` with `SIGNAL` triggers (example in the file).
4. In `bin/console migrate`, execute statements one at a time if the driver rejects multi-statement `exec`.
