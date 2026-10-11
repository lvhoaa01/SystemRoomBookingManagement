<?php

declare(strict_types=1);

namespace App\Bootstrap;

use Closure;
use RuntimeException;

final class Container
{
    /** @var array<string, Closure(self): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $resolving = [];

    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
        unset($this->factories[$id]);
    }

    public function singleton(string $id, callable $factory): void
    {
        $this->factories[$id] = Closure::fromCallable($factory);
        unset($this->instances[$id]);
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || array_key_exists($id, $this->factories);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new RuntimeException("Container chưa đăng ký dependency: {$id}");
        }

        if (isset($this->resolving[$id])) {
            throw new RuntimeException("Phát hiện dependency vòng: {$id}");
        }

        $this->resolving[$id] = true;
        try {
            return $this->instances[$id] = ($this->factories[$id])($this);
        } finally {
            unset($this->resolving[$id]);
        }
    }
}
