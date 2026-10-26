<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Contracts\Mailer;

/** Development mailer: appends each message to a log file instead of sending it. */
final class LogMailer implements Mailer
{
    public function __construct(private readonly string $logFile, private readonly string $from)
    {
    }

    public function send(string $toEmail, string $subject, string $textBody): void
    {
        $entry = sprintf(
            "=== %s ===\nFrom: %s\nTo: %s\nSubject: %s\n\n%s\n\n",
            date('c'),
            $this->from,
            $toEmail,
            str_replace(["\r", "\n"], ' ', $subject),
            $textBody,
        );
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}
