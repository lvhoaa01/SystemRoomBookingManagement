<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Config\AppConfig;
use App\Http\Response;

final readonly class SecurityHeaders
{
    public function __construct(private AppConfig $config)
    {
    }

    public function apply(Response $response): Response
    {
        $headers = [
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; script-src 'self'; style-src 'self'; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];
        if ($this->config->usesSecureCookies()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }
}
