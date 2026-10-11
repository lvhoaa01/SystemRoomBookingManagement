<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Shared\Database\TransactionManager;
use PDO;
use PDOException;
use RuntimeException;
use Tests\Support\TestCase;

final class TransactionManagerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE sample (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    }

    public function test_commits_successful_transaction(): void
    {
        $manager = new TransactionManager($this->pdo, static fn (): null => null);

        $result = $manager->run(function (PDO $pdo): string {
            $pdo->exec("INSERT INTO sample (value) VALUES ('ok')");
            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM sample')->fetchColumn());
    }

    public function test_rolls_back_non_retryable_failure(): void
    {
        $manager = new TransactionManager($this->pdo, static fn (): null => null);

        try {
            $manager->run(function (PDO $pdo): never {
                $pdo->exec("INSERT INTO sample (value) VALUES ('rollback')");
                throw new RuntimeException('failed');
            });
            self::fail('Expected RuntimeException.');
        } catch (RuntimeException) {
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM sample')->fetchColumn());
        }
    }

    public function test_retries_sqlstate_and_driver_deadlock_at_most_configured_times(): void
    {
        $attempts = 0;
        $sleeps = 0;
        $manager = new TransactionManager($this->pdo, static function () use (&$sleeps): void {
            ++$sleeps;
        });

        $manager->run(function (PDO $pdo) use (&$attempts): void {
            ++$attempts;
            if ($attempts === 1) {
                $exception = new PDOException('deadlock');
                $exception->errorInfo = ['40001', 1213, 'Deadlock found'];
                throw $exception;
            }
            $pdo->exec("INSERT INTO sample (value) VALUES ('after-retry')");
        }, 2);

        self::assertSame(2, $attempts);
        self::assertSame(1, $sleeps);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM sample')->fetchColumn());
    }
}
