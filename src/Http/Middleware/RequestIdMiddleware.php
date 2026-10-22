<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
// Đặt id cho request của mỗi user - kiểm soát request 
final class RequestIdMiddleware implements Middleware
{
    public function process(Request $request, RequestHandler $next): Response
    {
        return $next->handle($request)->withHeader('X-Request-ID', $request->requestId);
    }
}
