<?php

declare(strict_types=1);

namespace App\Shared\Database;

use Closure;
use PDO;
use PDOException;
use Throwable;

final readonly class TransactionManager
{
    /** @var Closure(int): void */
    private Closure $sleeper;

    /** @param null|callable(int): void $sleeper receives microseconds */
    public function __construct(
        private PDO $pdo,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper === null
            ? static fn (int $microseconds): mixed => usleep($microseconds)
            : Closure::fromCallable($sleeper);
    }

    public function run(callable $callback, int $maxRetries = 2): mixed
    {
        if ($maxRetries < 0 || $maxRetries > 5) {
            throw new \InvalidArgumentException('Số lần retry transaction phải từ 0 đến 5.');
        }

        for ($attempt = 0; ; ++$attempt) {
            try {
                $this->pdo->beginTransaction();
                $result = $callback($this->pdo);
                $this->pdo->commit();
                return $result;
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                if (!$exception instanceof PDOException || !self::isRetryable($exception) || $attempt >= $maxRetries) {
                    throw $exception;
                }

                ($this->sleeper)(random_int(20_000, 80_000));
            }
        }
    }

    public static function isRetryable(PDOException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return in_array($sqlState, ['40001', '40P01'], true)
            || in_array($driverCode, [1205, 1213], true);
    }
}
