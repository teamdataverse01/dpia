<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Contracts\AuthProvider;
use App\Domain\PortalUser;

/**
 * DEVELOPMENT ONLY. Pretends to be the portal: the developer picks a seeded dev user from a list
 * (no passwords — there is no login code in this module). Refuses to run unless APP_ENV=dev.
 */
final class DevAuthProvider implements AuthProvider
{
    public const SESSION_KEY = '_dev_portal_user';

    /** Dev users — one per role (PRD 13). Ids mimic CU portal ids. */
    public const USERS = [
        'dev.requestor' => ['Ada Okafor', 'ada.okafor@dev.cu.local'],
        'dev.requestor2' => ['Tunde Bakare', 'tunde.bakare@dev.cu.local'],
        'dev.vendor' => ['JAMB Vendor Contact', 'contact@vendor.dev.local'],
        'dev.business_owner' => ['Bola Adeyemi', 'bola.adeyemi@dev.cu.local'],
        'dev.infosec' => ['Chidi Nwosu', 'chidi.nwosu@dev.cu.local'],
        'dev.privacy_dpo' => ['Dami Ogun', 'dami.ogun@dev.cu.local'],
        'dev.reviewer' => ['Efe Ibrahim', 'efe.ibrahim@dev.cu.local'],
        'dev.risk_approver' => ['Funmi Bello', 'funmi.bello@dev.cu.local'],
        'dev.admin' => ['Gbenga Lawal', 'gbenga.lawal@dev.cu.local'],
        'dev.auditor' => ['Halima Musa', 'halima.musa@dev.cu.local'],
    ];

    public function __construct(bool $isDev)
    {
        if (!$isDev) {
            throw new \RuntimeException('DevAuthProvider may only be used when APP_ENV=dev.');
        }
    }

    public function currentUser(): ?PortalUser
    {
        $id = $_SESSION[self::SESSION_KEY] ?? null;
        return is_string($id) ? $this->findUser($id) : null;
    }

    public function findUser(string $portalUserId): ?PortalUser
    {
        if (!isset(self::USERS[$portalUserId])) {
            return null;
        }
        [$name, $email] = self::USERS[$portalUserId];
        return new PortalUser($portalUserId, $name, $email);
    }

    public function loginUrl(string $returnTo): string
    {
        return '/dev/switch-user?return=' . rawurlencode($returnTo);
    }

    public function switchTo(string $portalUserId): void
    {
        if (!isset(self::USERS[$portalUserId])) {
            throw new \InvalidArgumentException('Unknown dev user.');
        }
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = $portalUserId;
    }
}
