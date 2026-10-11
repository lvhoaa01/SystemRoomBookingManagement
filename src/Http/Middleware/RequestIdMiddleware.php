<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;

final class RequestIdMiddleware implements Middleware
{
    public function process(Request $request, RequestHandler $next): Response
    {
        return $next->handle($request)->withHeader('X-Request-ID', $request->requestId);
    }
}
