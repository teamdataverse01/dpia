-- 0004 reduce the module to four profiles (DECISIONS D-35): DPO / Administrator, Reviewer, Business Owner, Vendor.
-- On a fresh database this changes nothing; the seeds already use the new roles.
-- Run one role at a time so a user holding two old roles cannot break UNIQUE (portal_user_id, role).

UPDATE user_roles SET role = 'admin' WHERE role = 'privacy_dpo'
   AND NOT EXISTS (SELECT 1 FROM user_roles u WHERE u.portal_user_id = user_roles.portal_user_id AND u.role = 'admin');
UPDATE user_roles SET role = 'business_owner' WHERE role = 'requestor'
   AND NOT EXISTS (SELECT 1 FROM user_roles u WHERE u.portal_user_id = user_roles.portal_user_id AND u.role = 'business_owner');
UPDATE user_roles SET role = 'business_owner' WHERE role = 'infosec'
   AND NOT EXISTS (SELECT 1 FROM user_roles u WHERE u.portal_user_id = user_roles.portal_user_id AND u.role = 'business_owner');
-- Auditors are NOT promoted: a read-only user must not silently gain full rights. The DPO re-assigns them if needed.
DELETE FROM user_roles WHERE role IN ('privacy_dpo', 'requestor', 'infosec', 'auditor');

UPDATE controls SET owner_role = 'admin' WHERE owner_role IN ('privacy_dpo', 'auditor');
UPDATE controls SET owner_role = 'business_owner' WHERE owner_role IN ('infosec', 'requestor');

-- Never edit a config version in place: save the new routing as a new version and make it active.
INSERT INTO scoring_config (version, config_json, active, change_note, created_by)
SELECT (SELECT MAX(version) FROM scoring_config) + 1,
       REPLACE(REPLACE(REPLACE(REPLACE(config_json, '"privacy_dpo"', '"admin"'), '"auditor"', '"admin"'), '"infosec"', '"business_owner"'), '"requestor"', '"business_owner"'),
       FALSE,
       'Reduced to four profiles; DPO approvals now route to the DPO / Administrator role.',
       'system'
  FROM scoring_config
 WHERE active = TRUE
   AND (config_json LIKE '%privacy_dpo%' OR config_json LIKE '%auditor%' OR config_json LIKE '%infosec%' OR config_json LIKE '%requestor%');

UPDATE scoring_config SET active = FALSE
 WHERE active = TRUE
   AND EXISTS (SELECT 1 FROM scoring_config s2 WHERE s2.change_note = 'Reduced to four profiles; DPO approvals now route to the DPO / Administrator role.');

UPDATE scoring_config SET active = TRUE
 WHERE change_note = 'Reduced to four profiles; DPO approvals now route to the DPO / Administrator role.';
