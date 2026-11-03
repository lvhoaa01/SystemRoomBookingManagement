<?php

declare(strict_types=1);

use App\Config\DatabaseConfig;
use App\Config\Env;
use App\Shared\Database\Connection;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
Env::load($root . '/.env');
$pdo = Connection::create(DatabaseConfig::fromEnv());
$sql = file_get_contents($root . '/database/validation/001_integrity_checks.sql');
if ($sql === false) {
    fwrite(STDERR, 'Không thể đọc SQL integrity validator.' . PHP_EOL);
    exit(1);
}

$sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
$sql = preg_replace('/^\s*USE\s+[^;]+;\s*$/mi', '', $sql) ?? $sql;
$statements = array_values(array_filter(array_map('trim', explode(';', $sql))));
$passed = 0;
$failed = [];
foreach ($statements as $statement) {
    $rows = $pdo->query($statement)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        if (!array_key_exists('CheckName', $row) || !array_key_exists('Violations', $row)) {
            $failed[] = 'Kết quả validator không đúng contract.';
            continue;
        }
        $name = (string) $row['CheckName'];
        $violations = (int) $row['Violations'];
        if ($violations === 0) {
            ++$passed;
            fwrite(STDOUT, "[PASS] {$name}" . PHP_EOL);
        } else {
            $failed[] = "{$name}: {$violations}";
            fwrite(STDERR, "[FAIL] {$name} — {$violations}" . PHP_EOL);
        }
    }
}

fwrite(STDOUT, PHP_EOL . "SQL integrity: {$passed} passed, " . count($failed) . ' failed.' . PHP_EOL);
exit($failed === [] ? 0 : 1);
