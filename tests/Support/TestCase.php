<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;

abstract class TestCase extends PhpUnitTestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    /** @var array<string, mixed> */
    private array $environmentBackup = [];

    /** @var array<string, mixed> */
    private array $sessionBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        $this->environmentBackup = $_ENV;
        $this->sessionBackup = $_SESSION ?? [];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_ENV = $this->environmentBackup;
        $_SESSION = $this->sessionBackup;
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];

        parent::tearDown();
    }
}
