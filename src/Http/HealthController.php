<?php

declare(strict_types=1);

namespace App\Http;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class HealthController
{
    public function __construct(private PDO $pdo)
    {
    }

    public function __invoke(Request $request): Response
    {
        $databaseReady = (int) $this->pdo->query('SELECT 1')->fetchColumn() === 1;

        return Response::json([
            'status' => $databaseReady ? 'ok' : 'degraded',
            'checks' => [
                'application' => 'up',
                'database' => $databaseReady ? 'up' : 'down',
            ],
            'checkedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->format('Y-m-d\TH:i:s\Z'),
            'requestId' => $request->requestId,
        ], $databaseReady ? 200 : 503);
    }
}
