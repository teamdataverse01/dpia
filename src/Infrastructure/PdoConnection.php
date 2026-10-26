<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Support\Config;

/** Thin wrapper over PDO. Prepared statements only — there is no API that accepts interpolated SQL values. */
final class PdoConnection
{
    private int $depth = 0;

    public function __construct(private readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }

    public static function fromConfig(Config $config): self
    {
        // Hosts such as Railway/Heroku provide postgres://user:pass@host:port/db instead of a PDO DSN.
        $url = (string) $config->get('DATABASE_URL', '');
        if ((string) $config->get('DB_DSN', '') === '' && $url !== '') {
            $p = parse_url($url);
            if ($p === false || !isset($p['host'], $p['path'])) {
                throw new \RuntimeException('DATABASE_URL is not a valid URL.');
            }
            return new self(new \PDO(
                sprintf('pgsql:host=%s;port=%d;dbname=%s', $p['host'], $p['port'] ?? 5432, ltrim($p['path'], '/')),
                isset($p['user']) ? rawurldecode($p['user']) : null,
                isset($p['pass']) ? rawurldecode($p['pass']) : null,
            ));
        }
        return new self(new \PDO(
            $config->require('DB_DSN'),
            $config->get('DB_USER'),
            $config->get('DB_PASSWORD'),
        ));
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /** @return list<array<string,mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        $st = $this->run($sql, $params);
        return $st->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /** INSERT … RETURNING id (PostgreSQL-only syntax; on MySQL use lastInsertId()). */
    public function insert(string $table, array $values): int
    {
        $cols = array_keys($values);
        foreach ($cols as $c) {
            self::assertIdentifier($c);
        }
        self::assertIdentifier($table);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) RETURNING id',
            $table,
            implode(', ', $cols),
            implode(', ', array_map(fn ($c) => ':' . $c, $cols)),
        );
        return (int) $this->fetchValue($sql, $values);
    }

    /** INSERT for tables without an `id` column. */
    public function insertNoId(string $table, array $values): void
    {
        $cols = array_keys($values);
        foreach ($cols as $c) {
            self::assertIdentifier($c);
        }
        self::assertIdentifier($table);
        $this->execute(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_map(fn ($c) => ':' . $c, $cols)),
        ), $values);
    }

    /** UPDATE … SET col = :col … WHERE <where with named params>. Also bumps updated_at. */
    public function update(string $table, array $values, string $where, array $whereParams): int
    {
        self::assertIdentifier($table);
        $sets = [];
        $params = [];
        foreach ($values as $c => $v) {
            self::assertIdentifier($c);
            $sets[] = "{$c} = :set_{$c}";
            $params['set_' . $c] = $v;
        }
        $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        return $this->execute(sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $sets), $where), $params + $whereParams);
    }

    /**
     * Run $fn in a transaction. Nested calls join the outer transaction.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->depth > 0) {
            $this->depth++;
            try {
                return $fn();
            } finally {
                $this->depth--;
            }
        }
        $this->pdo->beginTransaction();
        $this->depth = 1;
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->depth = 0;
        }
    }

    private function run(string $sql, array $params): \PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $name = is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ':' . $k);
            if (is_bool($v)) {
                // PDO_PGSQL's PARAM_BOOL is unreliable with native prepares; PostgreSQL parses these literals.
                $v = $v ? 'true' : 'false';
            }
            $type = match (true) {
                is_int($v) => \PDO::PARAM_INT,
                $v === null => \PDO::PARAM_NULL,
                default => \PDO::PARAM_STR,
            };
            $st->bindValue($name, $v, $type);
        }
        $st->execute();
        return $st;
    }

    private static function assertIdentifier(string $name): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Unsafe SQL identifier: {$name}");
        }
    }
}
