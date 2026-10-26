<?php

declare(strict_types=1);

namespace App\Infrastructure;

/** Applies database/migrations/*.sql in order and loads database/seeds/*.sql reference data. */
final class Migrator
{
    public function __construct(private readonly PdoConnection $db, private readonly string $root)
    {
    }

    /** @return list<string> names of migrations applied by this call */
    public function migrate(): array
    {
        $pdo = $this->db->pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(200) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $done = array_column($this->db->fetchAll('SELECT name FROM schema_migrations'), 'name');
        $files = glob($this->root . '/database/migrations/*.sql') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            $this->db->transaction(function () use ($pdo, $file, $name) {
                $pdo->exec((string) file_get_contents($file)); // trusted, version-controlled DDL
                $this->db->insertNoId('schema_migrations', ['name' => $name]);
            });
            $applied[] = $name;
        }
        return $applied;
    }

    /** Load reference seed data once (skipped when departments already exist). */
    public function seedReference(): bool
    {
        if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM departments') > 0) {
            return false;
        }
        $files = glob($this->root . '/database/seeds/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $this->db->pdo()->exec((string) file_get_contents($file));
        }
        return true;
    }
}
