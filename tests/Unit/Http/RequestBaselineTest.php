<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Request;
use Tests\Support\TestCase;

final class RequestBaselineTest extends TestCase
{
    public function test_normalizes_path_when_running_in_xampp_subdirectory(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/DuAnNhom/public/';
        $_SERVER['SCRIPT_NAME'] = '/DuAnNhom/public/index.php';

        $request = Request::fromGlobals();

        self::assertSame('GET', $request->method);
        self::assertSame('/', $request->path);
    }
}
