<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AuthProvider;
use App\Contracts\Mailer;
use App\Domain\Role;
use App\Repositories\ReferenceRepository;

/** Plain-text notifications through the Mailer interface. Failures never break the business action. */
final class Notifier
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly AuthProvider $auth,
        private readonly ReferenceRepository $refs,
        private readonly string $baseUrl,
    ) {
    }

    /** @param list<Role> $roles */
    public function toRoles(array $roles, string $subject, string $body): int
    {
        $sent = 0;
        foreach ($this->refs->usersWithRoles($roles) as $userId) {
            $sent += $this->toUser($userId, $subject, $body) ? 1 : 0;
        }
        return $sent;
    }

    public function toUser(string $portalUserId, string $subject, string $body): bool
    {
        $user = $this->auth->findUser($portalUserId);
        if ($user === null || $user->email === null || $user->email === '') {
            return false;
        }
        try {
            $this->mailer->send($user->email, $subject, "Hello {$user->displayName},\n\n{$body}\n\n— CU Privacy Risk module");
            return true;
        } catch (\Throwable $e) {
            error_log('Notification failed: ' . $e->getMessage());
            return false;
        }
    }

    public function link(string $path): string
    {
        return rtrim($this->baseUrl, '/') . $path;
    }
}
