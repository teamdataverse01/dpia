-- 0001 core schema (PRD section 12).
-- Written for PostgreSQL. Portability notes:
--   * "BIGINT GENERATED ALWAYS AS IDENTITY"  -> MySQL/MariaDB: "BIGINT AUTO_INCREMENT"
--   * BOOLEAN / TEXT / TIMESTAMP / DATE / NUMERIC are ANSI and work on MySQL 8 as-is.
-- Additions beyond the PRD DDL are marked "-- added:" and explained in docs/DECISIONS.md.

-- reference ---------------------------------------------------------------

CREATE TABLE departments (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE user_roles (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  portal_user_id VARCHAR(100) NOT NULL,
  role VARCHAR(40) NOT NULL,              -- requestor|vendor|business_owner|infosec|privacy_dpo|reviewer|admin|auditor
  department_id BIGINT NULL REFERENCES departments(id),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (portal_user_id, role)
);

-- shared core -------------------------------------------------------------

CREATE TABLE risk_entities (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  entity_type VARCHAR(30) NOT NULL,       -- vendor | processing_activity
  name VARCHAR(200) NOT NULL,
  owner_portal_user_id VARCHAR(100) NOT NULL,
  department_id BIGINT NULL REFERENCES departments(id),
  status VARCHAR(40) NOT NULL DEFAULT 'draft',
  inherent_score NUMERIC(4,2) NULL,
  inherent_rating VARCHAR(10) NULL,       -- low|medium|high
  residual_score NUMERIC(4,2) NULL,       -- indicator (9.4)
  residual_score_100 SMALLINT NULL,       -- vendors only
  residual_rating VARCHAR(10) NULL,       -- very_low|low|medium|high
  negligible_residual_confirmed BOOLEAN NOT NULL DEFAULT FALSE,
  negligible_justification TEXT NULL,
  risk_reduced_pct NUMERIC(5,2) NULL,
  cleared_categories VARCHAR(60) NULL,    -- added: comma list of cleared control categories (dashboard stories)
  customised BOOLEAN NOT NULL DEFAULT FALSE,
  template_id BIGINT NULL,                -- FK to activity_templates (added after that table)
  scoring_framework_version INT NOT NULL,
  next_review_date DATE NULL,
  residual_calculated_at TIMESTAMP NULL,  -- added: when the residual rating was last computed
  submitted_at TIMESTAMP NULL,            -- added: last submission for approval (approval rounds)
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT ck_risk_entities_type CHECK (entity_type IN ('vendor', 'processing_activity'))
);
CREATE INDEX ix_risk_entities_dept_rating ON risk_entities(department_id, residual_rating);
CREATE INDEX ix_risk_entities_owner ON risk_entities(owner_portal_user_id);

CREATE TABLE risk_factor_inputs (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  risk_entity_id BIGINT NOT NULL UNIQUE REFERENCES risk_entities(id) ON DELETE CASCADE,
  data_type TEXT NOT NULL,                -- JSON array of selected types
  data_classification VARCHAR(40) NOT NULL,
  data_volume VARCHAR(40) NOT NULL,
  data_subject_type VARCHAR(60) NOT NULL,
  legal_basis VARCHAR(40) NULL,           -- DPIA only
  cross_border_transfer VARCHAR(60) NOT NULL,
  third_party_transfer VARCHAR(60) NOT NULL,
  involves_data_sharing BOOLEAN NOT NULL DEFAULT FALSE,
  nature_of_processing VARCHAR(60) NOT NULL,
  automated_decision_making VARCHAR(60) NOT NULL,
  vendor_risk_ref BIGINT NULL REFERENCES risk_entities(id),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT ck_vendor_ref_requires_sharing
    CHECK (vendor_risk_ref IS NULL OR involves_data_sharing = TRUE)
);

CREATE TABLE factor_score_breakdown (      -- audit of how inherent score was built
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  risk_entity_id BIGINT NOT NULL REFERENCES risk_entities(id) ON DELETE CASCADE,
  factor VARCHAR(40) NOT NULL,
  selected_option VARCHAR(80) NOT NULL,
  factor_score SMALLINT NOT NULL,
  weight_used NUMERIC(6,4) NOT NULL,
  weighted_value NUMERIC(6,4) NOT NULL,
  framework_version INT NULL,             -- added: config version used for this calculation
  calculated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX ix_breakdown_entity ON factor_score_breakdown(risk_entity_id, calculated_at);

-- type-specific -----------------------------------------------------------

CREATE TABLE vendor_profiles (
  risk_entity_id BIGINT PRIMARY KEY REFERENCES risk_entities(id) ON DELETE CASCADE,
  legal_name VARCHAR(200) NOT NULL,
  service_description TEXT NOT NULL,
  contract_start DATE NULL, contract_end DATE NULL,
  subprocessors TEXT NULL,
  contact_name VARCHAR(150) NULL, contact_email VARCHAR(200) NULL,
  vendor_portal_user_id VARCHAR(100) NULL, -- added: portal user of the vendor's contact (Vendor role "own record")
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE processing_activities (
  risk_entity_id BIGINT PRIMARY KEY REFERENCES risk_entities(id) ON DELETE CASCADE,
  purpose TEXT NOT NULL,
  description TEXT NOT NULL,
  retention_period VARCHAR(100) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE activity_templates (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  department_id BIGINT NOT NULL REFERENCES departments(id),
  activity_name VARCHAR(200) NOT NULL,
  data_type_signature VARCHAR(255) NOT NULL,
  data_subject_signature VARCHAR(255) NOT NULL,
  characteristics_signature VARCHAR(255) NOT NULL,
  involves_data_sharing BOOLEAN NOT NULL DEFAULT FALSE,
  default_vendor_entity_id BIGINT NULL REFERENCES risk_entities(id),
  inherent_score NUMERIC(4,2) NOT NULL,
  inherent_rating VARCHAR(10) NOT NULL,
  factor_snapshot TEXT NOT NULL,          -- JSON of factor selections used to prefill
  framework_version INT NOT NULL,
  last_reviewed_at DATE NOT NULL,
  source_entity_id BIGINT NULL REFERENCES risk_entities(id), -- added: DPIA it was saved from (save-as-template)
  created_by VARCHAR(100) NULL,           -- added
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (department_id, activity_name),
  CONSTRAINT ck_template_vendor_requires_sharing
    CHECK (default_vendor_entity_id IS NULL OR involves_data_sharing = TRUE) -- added: mirrors 7.1
);
CREATE TABLE activity_template_controls (
  template_id BIGINT NOT NULL REFERENCES activity_templates(id) ON DELETE CASCADE,
  control_id BIGINT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (template_id, control_id)
);
ALTER TABLE risk_entities ADD CONSTRAINT fk_risk_entities_template
  FOREIGN KEY (template_id) REFERENCES activity_templates(id);

-- controls and remediation ------------------------------------------------

CREATE TABLE controls (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  category VARCHAR(20) NOT NULL,          -- policy | process | technology
  title VARCHAR(200) NOT NULL,
  description TEXT NOT NULL,
  risk_trigger VARCHAR(200) NOT NULL,     -- machine-readable rule, see src/Engine/RiskTriggerEvaluator.php
  mandatory BOOLEAN NOT NULL DEFAULT TRUE,
  expected_evidence TEXT NULL,
  owner_role VARCHAR(40) NULL,
  verification_required BOOLEAN NOT NULL DEFAULT FALSE,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT ck_controls_category CHECK (category IN ('policy', 'process', 'technology'))
);
ALTER TABLE activity_template_controls ADD CONSTRAINT fk_atc_control
  FOREIGN KEY (control_id) REFERENCES controls(id);

CREATE TABLE control_assessments (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  risk_entity_id BIGINT NOT NULL REFERENCES risk_entities(id) ON DELETE CASCADE,
  control_id BIGINT NOT NULL REFERENCES controls(id),
  status VARCHAR(20) NOT NULL DEFAULT 'not_started', -- not_started|in_progress|implemented|not_applicable
  effectiveness_pct SMALLINT NULL CHECK (effectiveness_pct BETWEEN 0 AND 100),
  evidence_path VARCHAR(500) NULL, evidence_owner VARCHAR(150) NULL,
  evidence_date DATE NULL, expiry_date DATE NULL,
  evidence_original_name VARCHAR(255) NULL, -- added: uploaded file name shown to users (stored name is random)
  evidence_mime VARCHAR(100) NULL,          -- added
  notes TEXT NULL,                          -- added: assessor comments
  source VARCHAR(20) NOT NULL DEFAULT 'recommended', -- added: recommended | template | manual (manual never blocks clearance)
  verified_by VARCHAR(100) NULL, verified_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (risk_entity_id, control_id)
);

CREATE TABLE remediation_actions (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  risk_entity_id BIGINT NOT NULL REFERENCES risk_entities(id) ON DELETE CASCADE,
  control_id BIGINT NULL REFERENCES controls(id),
  gap_description TEXT NOT NULL,
  owner_portal_user_id VARCHAR(100) NOT NULL,
  priority VARCHAR(10) NOT NULL,          -- low|medium|high
  target_date DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open', -- open|in_progress|completed|cancelled ("overdue" is derived)
  completion_pct SMALLINT NOT NULL DEFAULT 0 CHECK (completion_pct BETWEEN 0 AND 100),
  reviewer_portal_user_id VARCHAR(100) NULL,
  approval_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  completed_at TIMESTAMP NULL,            -- added
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX ix_remediation_entity ON remediation_actions(risk_entity_id);
CREATE INDEX ix_remediation_status_target ON remediation_actions(status, target_date);

CREATE TABLE approvals (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  risk_entity_id BIGINT NOT NULL REFERENCES risk_entities(id) ON DELETE CASCADE,
  decision VARCHAR(20) NOT NULL,          -- approved|rejected|risk_accepted
  decided_by VARCHAR(100) NOT NULL,
  approver_role VARCHAR(40) NULL,         -- added: role in which the decision was made (routing, section 16)
  justification TEXT NOT NULL,
  accepted_until DATE NULL,               -- required when risk_accepted
  residual_rating_at_decision VARCHAR(10) NULL, -- added
  decided_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT ck_acceptance_has_date CHECK (decision <> 'risk_accepted' OR accepted_until IS NOT NULL)
);
CREATE INDEX ix_approvals_entity ON approvals(risk_entity_id, decided_at);

-- configuration (all editable by Administrator, versioned) ----------------

CREATE TABLE scoring_config (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  version INT NOT NULL UNIQUE,
  config_json TEXT NOT NULL,              -- weights, band thresholds, category reduction weights,
                                          -- clearance_min_effectiveness, review intervals, template staleness months
  active BOOLEAN NOT NULL DEFAULT FALSE,
  change_note TEXT NULL,                  -- added: why the admin changed it
  created_by VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE factor_options (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  factor VARCHAR(40) NOT NULL,
  option_key VARCHAR(80) NOT NULL,
  label VARCHAR(150) NOT NULL,
  help_text VARCHAR(300) NULL,            -- plain-language explanation shown in UI
  score SMALLINT NOT NULL CHECK (score BETWEEN 1 AND 5),
  sort_order SMALLINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (factor, option_key)
);

-- reporting and audit -----------------------------------------------------

CREATE TABLE risk_snapshots (             -- written by a scheduled job, for dashboard trends
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  snapshot_date DATE NOT NULL,
  department_id BIGINT NULL,
  entity_type VARCHAR(30) NOT NULL,
  residual_rating VARCHAR(10) NOT NULL,
  inherent_rating VARCHAR(10) NOT NULL,
  risk_count INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX ix_snapshots_date ON risk_snapshots(snapshot_date);

CREATE TABLE audit_log (                  -- append-only: no UPDATE/DELETE from the app
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor_portal_user_id VARCHAR(100) NOT NULL,
  entity_table VARCHAR(60) NOT NULL,
  entity_id BIGINT NOT NULL,
  action VARCHAR(40) NOT NULL,
  old_value TEXT NULL,
  new_value TEXT NULL
);
CREATE INDEX ix_audit_entity ON audit_log(entity_table, entity_id);
CREATE INDEX ix_audit_occurred ON audit_log(occurred_at);
