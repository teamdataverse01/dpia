<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Permissions. The first six map 1:1 to the columns of the PRD section 5 matrix.
 * The rest are narrower permissions the PRD assigns to specific roles elsewhere
 * (see docs/DECISIONS.md for the mapping).
 */
enum Permission: string
{
    // PRD section 5 matrix columns
    case Create = 'create';
    case EditOwn = 'edit_own';
    case EditControls = 'edit_controls';
    case Approve = 'approve';
    case AdminConfig = 'admin_config';
    case ReadAll = 'read_all';

    // Derived permissions
    case View = 'view';                         // view a single record
    case ViewDashboard = 'view_dashboard';      // institution-wide dashboard (11)
    case ConfirmNegligible = 'confirm_negligible'; // 9.3 Very Low confirmation
    case VerifyEvidence = 'verify_evidence';    // 9.2 verification by a Reviewer
    case SaveTemplate = 'save_template';        // 10.3 Privacy Team/DPO or Administrator
    case Decide = 'decide';                     // record an approval decision (16 routing)
    case ManageRemediation = 'manage_remediation';
    case Submit = 'submit';
}
