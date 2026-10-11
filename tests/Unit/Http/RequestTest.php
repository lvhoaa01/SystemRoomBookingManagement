<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Request;
use InvalidArgumentException;
use Tests\Support\TestCase;

final class RequestTest extends TestCase
{
    public function test_parses_json_headers_cookies_files_ip_and_request_id(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/DuAnNhom/public/api/example?filter=active',
            'SCRIPT_NAME' => '/DuAnNhom/public/index.php',
            'CONTENT_TYPE' => 'application/json; charset=utf-8',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUEST_ID' => 'request-12345678',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        $_GET = ['filter' => 'active'];
        $_COOKIE = ['locale' => 'vi'];
        $_FILES = ['photo' => ['name' => 'room.jpg']];

        $request = Request::fromGlobals('{"name":"Khách sạn"}');

        self::assertSame('/api/example', $request->path);
        self::assertSame('Khách sạn', $request->body['name']);
        self::assertSame('active', $request->query['filter']);
        self::assertSame('application/json', $request->header('accept'));
        self::assertSame('vi', $request->cookies['locale']);
        self::assertSame('room.jpg', $request->files['photo']['name']);
        self::assertSame('127.0.0.1', $request->clientIp);
        self::assertSame('request-12345678', $request->requestId);
        self::assertTrue($request->expectsJson());
    }

    public function test_supports_method_override_for_html_form(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/properties/1',
            'SCRIPT_NAME' => '/index.php',
        ];
        $_POST = ['_method' => 'PATCH', 'name' => 'Mới'];

        $request = Request::fromGlobals();

        self::assertSame('PATCH', $request->method);
        self::assertSame('Mới', $request->input('name'));
    }

    public function test_rejects_invalid_json(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/example',
            'SCRIPT_NAME' => '/index.php',
            'CONTENT_TYPE' => 'application/json',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON_BODY_INVALID');

        Request::fromGlobals('{invalid');
    }
}
