<?php

declare(strict_types=1);

namespace App\Contracts;

/** Notification delivery. CU owns SMTP; they swap in their own implementation. */
interface Mailer
{
    public function send(string $toEmail, string $subject, string $textBody): void;
}
