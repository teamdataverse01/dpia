-- 0003 retire the Risk Approver role (DECISIONS D-33): its powers move to the Privacy Team / DPO.
-- On a fresh database (no config yet) this does nothing; seeds already use the new routing.

DELETE FROM user_roles WHERE role = 'risk_approver';

-- Never edit a config version in place: save the corrected routing as a new version and make it active.
INSERT INTO scoring_config (version, config_json, active, change_note, created_by)
SELECT (SELECT MAX(version) FROM scoring_config) + 1,
       REPLACE(REPLACE(REPLACE(config_json, '"risk_approver",', ''), ',"risk_approver"', ''), '"risk_approver"', '"privacy_dpo"'),
       FALSE,
       'Risk Approver role retired; its approvals move to the Privacy Team / DPO.',
       'system'
  FROM scoring_config
 WHERE active = TRUE AND config_json LIKE '%risk_approver%';

UPDATE scoring_config SET active = FALSE
 WHERE active = TRUE
   AND EXISTS (SELECT 1 FROM scoring_config s2 WHERE s2.change_note = 'Risk Approver role retired; its approvals move to the Privacy Team / DPO.');

UPDATE scoring_config SET active = TRUE
 WHERE change_note = 'Risk Approver role retired; its approvals move to the Privacy Team / DPO.';
