<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;

interface RequestHandler
{
    public function handle(Request $request): Response;
}
