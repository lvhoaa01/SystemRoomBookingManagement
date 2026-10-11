<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

final readonly class DatabaseConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
    ) {
        if ($this->port < 1 || $this->port > 65535) {
            throw new RuntimeException('DB_PORT nằm ngoài phạm vi hợp lệ.');
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $this->database)) {
            throw new RuntimeException('DB_DATABASE chứa ký tự không hợp lệ.');
        }
    }

    public static function fromEnv(): self
    {
        return new self(
            Env::require('DB_HOST'),
            (int) Env::require('DB_PORT'),
            Env::require('DB_DATABASE'),
            Env::require('DB_USERNAME'),
            Env::require('DB_PASSWORD', true),
        );
    }

    public function dsn(): string
    {
        return "mysql:host={$this->host};port={$this->port};dbname={$this->database};charset=utf8mb4";
    }
}
