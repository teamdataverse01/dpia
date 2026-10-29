<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Contracts\AuthProvider;
use App\Domain\PortalUser;

/**
 * DEMO ONLY (AUTH_ADAPTER=demo): a simple sign-in for showing the module outside the CU portal.
 * The seeded demo accounts (one per role) share one password from the DEMO_PASSWORD variable.
 * In production the CU portal provides identity and this adapter is not used.
 */
final class DemoAuthProvider implements AuthProvider
{
    public const SESSION_KEY = '_demo_portal_user';

    public function __construct(private readonly string $password)
    {
        if (strlen($password) < 8) {
            throw new \RuntimeException('Set DEMO_PASSWORD (at least 8 characters) to use the demo sign-in.');
        }
    }

    /** Sign-in name => portal user id (the demo data is owned by the dev.* ids). */
    public static function usernames(): array
    {
        return [
            'requestor' => 'dev.requestor',
            'requestor2' => 'dev.requestor2',
            'dpo' => 'dev.privacy_dpo',
            'reviewer' => 'dev.reviewer',
            'infosec' => 'dev.infosec',
            'owner' => 'dev.business_owner',
            'vendor' => 'dev.vendor',
            'admin' => 'dev.admin',
            'auditor' => 'dev.auditor',
        ];
    }

    public function currentUser(): ?PortalUser
    {
        $id = $_SESSION[self::SESSION_KEY] ?? null;
        return is_string($id) ? $this->findUser($id) : null;
    }

    public function findUser(string $portalUserId): ?PortalUser
    {
        if (!isset(DevAuthProvider::USERS[$portalUserId])) {
            return null;
        }
        [$name, $email] = DevAuthProvider::USERS[$portalUserId];
        return new PortalUser($portalUserId, $name, $email);
    }

    public function loginUrl(string $returnTo): string
    {
        return '/login?return=' . rawurlencode($returnTo);
    }

    public function attempt(string $username, string $password): bool
    {
        $id = self::usernames()[strtolower(trim($username))] ?? null;
        $ok = hash_equals($this->password, $password);
        if ($id === null || !$ok) {
            usleep(400000); // slow down guessing
            return false;
        }
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = $id;
        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }
}
