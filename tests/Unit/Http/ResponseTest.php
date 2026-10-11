<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Response;
use Tests\Support\TestCase;

final class ResponseTest extends TestCase
{
    public function test_builds_json_and_redirect_responses(): void
    {
        $json = Response::json(['message' => 'Thành công'], 201);
        $redirect = Response::redirect('/account', 303);

        self::assertSame(201, $json->status());
        self::assertSame(['message' => 'Thành công'], json_decode($json->body(), true));
        self::assertSame('application/json; charset=UTF-8', $json->headers()['Content-Type']);
        self::assertSame(303, $redirect->status());
        self::assertSame('/account', $redirect->headers()['Location']);
    }
}
