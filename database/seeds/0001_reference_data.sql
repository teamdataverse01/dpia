-- Reference seed data (PRD section 13). Safe to run once on an empty database.
-- Option scores are [ASSUMPTION] starting defaults for the Privacy Team to refine (PRD 8.2).

INSERT INTO departments (name) VALUES
  ('Admissions'), ('Registry'), ('Bursary / Finance'), ('ICT'), ('Research'), ('Human Resources');

-- factor_options ------------------------------------------------------------
INSERT INTO factor_options (factor, option_key, label, help_text, score, sort_order) VALUES
-- data classification
('data_classification', 'public', 'Public', 'Information anyone may see, such as published course lists.', 1, 1),
('data_classification', 'internal', 'Internal', 'Everyday information meant for CU staff only, such as internal memos.', 2, 2),
('data_classification', 'confidential', 'Confidential', 'Information that would harm people or CU if leaked, such as ID numbers, grades or bank details.', 4, 3),
('data_classification', 'special_category', 'Special category / sensitive personal data', 'The most sensitive data: health, biometrics (fingerprints, face scans), religion, ethnicity or criminal records.', 5, 4),
-- data volume
('data_volume', 'under_10', 'Fewer than 10 people', 'The activity touches data about fewer than 10 people.', 1, 1),
('data_volume', '10_99', '10 – 99 people', 'The activity touches data about 10 to 99 people.', 2, 2),
('data_volume', '100_999', '100 – 999 people', 'The activity touches data about 100 to 999 people.', 3, 3),
('data_volume', '1000_9999', '1,000 – 9,999 people', 'The activity touches data about 1,000 to 9,999 people.', 4, 4),
('data_volume', '10000_plus', '10,000 or more people', 'The activity touches data about 10,000 people or more.', 5, 5),
-- automated decision-making
('automated_decision_making', 'none', 'None', 'People make all decisions; no computer system decides anything about individuals.', 1, 1),
('automated_decision_making', 'assists_human', 'Assists a human', 'A system sorts or highlights information, but a person makes the decision.', 2, 2),
('automated_decision_making', 'human_reviews', 'Human reviews automated recommendation', 'A system recommends an outcome and a person checks every recommendation.', 3, 3),
('automated_decision_making', 'largely_automated', 'Largely automated, human can override', 'A system decides in most cases; a person steps in only when needed.', 4, 4),
('automated_decision_making', 'solely_automated', 'Solely automated with significant effect', 'A system decides on its own, and the decision significantly affects the person (e.g. admission refused).', 5, 5),
-- cross-border transfer
('cross_border_transfer', 'none', 'None — data stays in Nigeria', 'The data is stored and accessed only inside Nigeria.', 1, 1),
('cross_border_transfer', 'adequate_safeguards', 'Outside Nigeria, adequate safeguards', 'Data goes to a country recognised as protecting data adequately, or under approved safeguards.', 2, 2),
('cross_border_transfer', 'contractual_safeguards', 'Outside Nigeria, contractual safeguards only', 'Data goes abroad and is protected only by contract clauses with the recipient.', 3, 3),
('cross_border_transfer', 'weak_safeguards', 'Outside Nigeria, weak or unclear safeguards', 'Data goes abroad and it is not clear how it is protected there.', 4, 4),
('cross_border_transfer', 'no_safeguards', 'Outside Nigeria, no safeguards', 'Data goes abroad with no protection agreed at all.', 5, 5),
-- third-party transfer
('third_party_transfer', 'none', 'None', 'No one outside the CU unit running the activity receives the data.', 1, 1),
('third_party_transfer', 'internal_affiliate', 'One internal affiliate', 'One other CU-affiliated body receives the data.', 2, 2),
('third_party_transfer', 'one_external', 'One external party', 'One outside organisation receives the data.', 3, 3),
('third_party_transfer', 'several_external', 'Several external parties', 'More than one outside organisation receives the data.', 4, 4),
('third_party_transfer', 'subprocessors_unknown', 'Several, including sub-processors or unknown recipients', 'Outside organisations pass the data on to others, or we do not know everyone who receives it.', 5, 5),
-- nature of processing
('nature_of_processing', 'routine_admin', 'Routine administration', 'Ordinary record keeping, such as maintaining a staff contact list.', 1, 1),
('nature_of_processing', 'structured_collection', 'Structured collection', 'Collecting data through a set form or process, such as an event registration.', 2, 2),
('nature_of_processing', 'large_scale_routine', 'Large-scale routine processing', 'Routine processing done at large scale, such as running the student records system.', 3, 3),
('nature_of_processing', 'profiling_monitoring', 'Profiling or systematic monitoring', 'Evaluating or tracking people, such as ranking applicants or monitoring network use.', 4, 4),
('nature_of_processing', 'innovative_combined', 'Innovative technology or combined datasets', 'New technology (e.g. facial recognition) or joining datasets from different sources.', 5, 5),
-- data subject type
('data_subject_type', 'public_body_none', 'Public body / no individuals', 'The data is about organisations, not individual people.', 1, 1),
('data_subject_type', 'staff', 'Staff', 'The data is about CU employees.', 2, 2),
('data_subject_type', 'adult_students', 'Adult students', 'The data is about current students aged 18 or over.', 3, 3),
('data_subject_type', 'minors_vulnerable', 'Includes minors or vulnerable groups', 'The data includes children under 18 or people who need extra protection.', 5, 4),
('data_subject_type', 'prospective_students_minors', 'Prospective students (including minors)', 'Applicants, some of whom are under 18.', 5, 5),
-- legal basis (DPIA only)
('legal_basis', 'legal_obligation', 'Legal obligation', 'A law requires CU to process this data.', 1, 1),
('legal_basis', 'contract', 'Contract', 'The processing is needed to deliver a contract with the person (e.g. enrolment).', 2, 2),
('legal_basis', 'legitimate_interest', 'Legitimate interest', 'CU has a genuine reason that does not override the person''s rights; needs a balancing test.', 3, 3),
('legal_basis', 'consent', 'Consent', 'The person has freely agreed and can withdraw at any time.', 3, 4),
('legal_basis', 'none_documented', 'None documented', 'No lawful reason has been written down yet.', 5, 5),
-- data types (captured, NOT scored: score column is required by the table, value is ignored)
('data_type', 'contact', 'Contact details', 'Names, phone numbers, email and postal addresses.', 1, 1),
('data_type', 'identity', 'Identity / ID numbers', 'National ID (NIN), passport, JAMB registration or matriculation numbers.', 1, 2),
('data_type', 'academic', 'Academic records', 'Grades, transcripts, exam scores, course registrations.', 1, 3),
('data_type', 'financial', 'Financial', 'Bank details, fees, payments, salaries.', 1, 4),
('data_type', 'health', 'Health', 'Medical records, disability information, clinic visits.', 1, 5),
('data_type', 'biometric', 'Biometric', 'Fingerprints, face scans, voice prints.', 1, 6),
('data_type', 'location', 'Location / tracking', 'GPS location, access-card logs, CCTV.', 1, 7),
('data_type', 'online', 'Online identifiers', 'Usernames, IP addresses, device IDs, cookies.', 1, 8),
('data_type', 'religious_beliefs', 'Religious or philosophical beliefs', 'Faith, chapel attendance or similar.', 1, 9),
('data_type', 'criminal', 'Criminal records', 'Convictions, offences or disciplinary findings with legal effect.', 1, 10);

-- scoring_config v1 -----------------------------------------------------------
INSERT INTO scoring_config (version, config_json, active, change_note, created_by) VALUES (
  1,
  '{"weights":{"data_classification":20,"data_volume":15,"automated_decision_making":15,"cross_border_transfer":15,"vendor_risk":10,"third_party_transfer":10,"nature_of_processing":5,"data_subject_type":5,"legal_basis":5},"bands":{"low_max":2.0,"medium_max":3.5},"reduction_weights":{"policy":0.15,"process":0.25,"technology":0.40},"clearance_min_effectiveness":70,"review_interval_months":{"high":6,"medium":12,"low":24,"very_low":24},"template_staleness_months":12,"template_signature_threshold":3,"approval_routing":{"high":["risk_approver","privacy_dpo"],"medium":["privacy_dpo"],"low":["reviewer"],"very_low":["reviewer"]}}',
  TRUE,
  'Initial PRD defaults (sections 8.1, 8.4, 9.4, 13, 16).',
  'system'
);

-- control library -----------------------------------------------------------
INSERT INTO controls (category, title, description, risk_trigger, mandatory, expected_evidence, owner_role, verification_required) VALUES
-- policy / documents
('policy', 'Data Protection Policy', 'A current, approved CU data protection policy that covers this activity or vendor relationship.', 'always', TRUE, 'Approved policy document with version and approval date.', 'privacy_dpo', FALSE),
('policy', 'Data Processing Agreement with the recipient', 'A signed agreement setting out how the recipient may use, protect, and return or delete CU data.', 'involves_data_sharing=true | third_party_transfer>=3 | entity_type=vendor', TRUE, 'Signed DPA, including sub-processor and breach-notification clauses.', 'business_owner', TRUE),
('policy', 'Data Retention Schedule', 'A written rule for how long the data is kept and how it is destroyed afterwards.', 'data_classification>=4 | data_volume>=4', TRUE, 'Retention schedule entry covering this data, approved by the records owner.', 'privacy_dpo', FALSE),
('policy', 'Cross-border transfer safeguards', 'Approved transfer mechanism (e.g. standard contractual clauses) for data leaving Nigeria.', 'cross_border_transfer>=3', TRUE, 'Signed transfer clauses or regulator approval.', 'privacy_dpo', TRUE),
('policy', 'Privacy notice and lawful-basis record', 'A privacy notice telling people how their data is used, and a record of the lawful basis relied on.', 'legal_basis>=3 | data_subject_type>=5', FALSE, 'Published privacy notice and lawful-basis assessment.', 'privacy_dpo', FALSE),
-- process / procedures
('process', 'Periodic Access Review', 'Regular (at least quarterly) review of who can access the data, removing anyone who no longer needs it.', 'data_classification>=4', TRUE, 'Signed-off access review report from the last quarter.', 'business_owner', FALSE),
('process', 'Incident Response Procedure', 'A tested procedure for detecting, reporting and handling data breaches within regulatory deadlines.', 'always', TRUE, 'Procedure document and record of the last test or exercise.', 'infosec', FALSE),
('process', 'Approval workflow for data sharing', 'Every disclosure of data to an outside party is approved and logged before it happens.', 'involves_data_sharing=true | third_party_transfer>=4', TRUE, 'Workflow description and a sample of approved sharing requests.', 'business_owner', FALSE),
('process', 'Human review of automated decisions', 'A person reviews significant automated decisions and people can ask for a human to reconsider.', 'automated_decision_making>=4', TRUE, 'Review procedure and log of reviewed decisions.', 'business_owner', FALSE),
('process', 'Vendor due-diligence review', 'Annual review of the vendor''s security and privacy posture (questionnaire, certifications, audit reports).', 'entity_type=vendor', TRUE, 'Completed due-diligence questionnaire and certificates (e.g. ISO 27001).', 'infosec', FALSE),
-- technology / technical
('technology', 'Encryption at rest and in transit', 'Data is encrypted when stored and when sent over networks.', 'data_classification>=4 | cross_border_transfer>=2', TRUE, 'Configuration screenshots or vendor attestation showing encryption settings.', 'infosec', TRUE),
('technology', 'Role-based access control with MFA', 'Access is limited by role and protected with multi-factor authentication.', 'data_classification>=4 | data_volume>=4', TRUE, 'Role matrix and MFA enforcement report.', 'infosec', TRUE),
('technology', 'Audit logging / DLP', 'Access and changes are logged and data-loss-prevention rules detect unusual exports.', 'inherent_rating>=medium', TRUE, 'Log retention settings and a sample DLP alert report.', 'infosec', FALSE),
('technology', 'Data minimisation / pseudonymisation', 'Only the fields needed are collected, and identifiers are replaced with codes where possible.', 'data_volume>=5 | nature_of_processing>=4', FALSE, 'Data-field inventory showing minimisation decisions.', 'infosec', FALSE);
