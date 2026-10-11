<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use Closure;

final class CallableRequestHandler implements RequestHandler
{
    /** @param callable(Request): Response $handler */
    public function __construct(private readonly Closure $handler)
    {
    }

    public function handle(Request $request): Response
    {
        return ($this->handler)($request);
    }
}
