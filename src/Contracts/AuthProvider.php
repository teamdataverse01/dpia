<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\PortalUser;

/**
 * Identity comes from the CU portal. This module never issues logins or handles passwords.
 * CU implements this against their real SSO/session (PHP session, JWT, SAML/OIDC — PRD 17.9).
 * Returned users carry no roles; roles are attached from the user_roles table by the module.
 */
interface AuthProvider
{
    /** The user of the current request, or null when there is no portal session. */
    public function currentUser(): ?PortalUser;

    /** Look up a portal user by id (for display names and notification e-mails). */
    public function findUser(string $portalUserId): ?PortalUser;

    /** Where to send the browser when there is no session (the portal's own login page). */
    public function loginUrl(string $returnTo): string;
}
