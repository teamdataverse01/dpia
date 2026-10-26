<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Contracts\AuthProvider;
use App\Domain\PortalUser;

/**
 * Starting point for CU's real adapter: reads the identity the CU portal has already put in the
 * shared PHP session, e.g. $_SESSION['portal_user'] = ['id' => '…', 'name' => '…', 'email' => '…'].
 * CU should replace or adapt this once the portal's session mechanism is confirmed (PRD 17.9).
 */
final class PortalSessionAuthProvider implements AuthProvider
{
    public function __construct(
        private readonly string $sessionKey,
        private readonly string $portalLoginUrl = '/login',
    ) {
    }

    public function currentUser(): ?PortalUser
    {
        $u = $_SESSION[$this->sessionKey] ?? null;
        if (!is_array($u) || !isset($u['id']) || !is_string($u['id']) || $u['id'] === '') {
            return null;
        }
        return new PortalUser($u['id'], (string) ($u['name'] ?? $u['id']), isset($u['email']) ? (string) $u['email'] : null);
    }

    public function findUser(string $portalUserId): ?PortalUser
    {
        // The portal's user directory is not available to this module yet; return a minimal identity.
        $current = $this->currentUser();
        if ($current !== null && $current->id === $portalUserId) {
            return $current;
        }
        return new PortalUser($portalUserId, $portalUserId, null);
    }

    public function loginUrl(string $returnTo): string
    {
        return $this->portalLoginUrl . '?return=' . rawurlencode($returnTo);
    }
}
