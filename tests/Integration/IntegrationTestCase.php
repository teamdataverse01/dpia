<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\App;
use App\Domain\PortalUser;
use App\Domain\Role;
use App\Infrastructure\DevAuthProvider;
use App\Infrastructure\Migrator;
use App\Support\Clock;
use App\Support\Config;
use PHPUnit\Framework\TestCase;

/** Fixed "today" that still moves forward a microsecond per call, so ordered timestamps stay distinct. */
final class TestClock extends Clock
{
    private int $tick = 0;

    public function __construct(private readonly string $base)
    {
        parent::__construct();
    }

    public function now(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($this->base))->modify('+' . (++$this->tick) . ' microseconds');
    }

    public function today(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($this->base))->setTime(0, 0);
    }
}

final class RollbackSignal extends \RuntimeException
{
}

/**
 * Runs against a dedicated test database (TEST_DB_DSN, default privacy_risk_test on the compose db service).
 * The schema is rebuilt and demo data seeded once per test class; each test runs inside a transaction
 * that is rolled back, so tests do not affect each other.
 */
abstract class IntegrationTestCase extends TestCase
{
    public const TODAY = '2026-10-18 09:00:00';

    protected static ?App $app = null;
    private static ?string $seededFor = null;

    protected static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    protected static function makeApp(): App
    {
        $config = Config::fromArray([
            'APP_ENV' => 'dev',
            'APP_BASE_URL' => 'http://localhost',
            'DB_DSN' => getenv('TEST_DB_DSN') ?: 'pgsql:host=db;port=5432;dbname=privacy_risk_test',
            'DB_USER' => getenv('DB_USER') ?: 'privacy_risk',
            'DB_PASSWORD' => getenv('DB_PASSWORD') ?: 'privacy_risk_dev',
            'AUTH_ADAPTER' => 'dev',
            'STORAGE_PATH' => sys_get_temp_dir() . '/cu-privacy-test-evidence',
            'LOG_PATH' => sys_get_temp_dir() . '/cu-privacy-test-logs',
        ]);
        return new App($config, self::root(), new TestClock(self::TODAY));
    }

    public static function setUpBeforeClass(): void
    {
        if (self::$seededFor === static::class) {
            return;
        }
        try {
            $app = self::makeApp();
            $app->db();
        } catch (\PDOException $e) {
            self::markTestSkipped('Test database not reachable: ' . $e->getMessage());
        }
        $pdo = $app->db()->pdo();
        $pdo->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
        $m = new Migrator($app->db(), self::root());
        $m->migrate();
        $m->seedReference();
        require_once self::root() . '/database/seeds/DemoSeeder.php';
        (new \DemoSeeder($app, fn (string $s) => null))->run();
        self::$app = $app;
        self::$seededFor = static::class;
    }

    protected function app(): App
    {
        if (self::$app === null) {
            self::markTestSkipped('Test database not available.');
        }
        return self::$app;
    }

    /** Run $fn and roll everything back afterwards. */
    protected function inRollback(callable $fn): void
    {
        try {
            $this->app()->db()->transaction(function () use ($fn) {
                $fn();
                throw new RollbackSignal();
            });
        } catch (RollbackSignal) {
        }
    }

    protected function user(string $id): PortalUser
    {
        $roles = $this->app()->refs()->rolesFor($id);
        [$name, $email] = DevAuthProvider::USERS[$id] ?? [$id, null];
        return new PortalUser($id, $name, $email, $roles['roles'], $roles['departments']);
    }

    protected function userWithRole(string $id, Role $role): PortalUser
    {
        return new PortalUser($id, $id, null, [$role]);
    }

    protected function entityByName(string $name): array
    {
        $id = (int) $this->app()->db()->fetchValue('SELECT id FROM risk_entities WHERE name = :n', ['n' => $name]);
        self::assertGreaterThan(0, $id, "seeded entity {$name} missing");
        return $this->app()->entities()->find($id);
    }

    /** Factor answers for the PRD 8.5 example. */
    protected static function studentApplicationFactors(bool $sharing, ?int $vendorId): array
    {
        return [
            'data_type' => ['identity', 'contact', 'academic'],
            'data_classification' => 'confidential', 'data_volume' => '10000_plus',
            'automated_decision_making' => 'largely_automated', 'cross_border_transfer' => 'adequate_safeguards',
            'third_party_transfer' => 'several_external', 'nature_of_processing' => 'profiling_monitoring',
            'data_subject_type' => 'prospective_students_minors', 'legal_basis' => 'consent',
            'involves_data_sharing' => $sharing, 'vendor_risk_ref' => $vendorId,
        ];
    }
}
