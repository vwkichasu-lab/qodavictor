<?php

require_once __DIR__ . '/../backend-php/lib/migrations.php';

$migrationDir = __DIR__ . '/../database/migrations';
$results = qodaRunMigrations($pdo, $migrationDir);

foreach ($results as $result) {
    $line = '[' . strtoupper($result['status']) . '] ' . $result['migration'];
    if (!empty($result['error'])) {
        $line .= ' - ' . $result['error'];
    }
    echo $line . PHP_EOL;
}

$failed = array_filter($results, fn($result) => $result['status'] === 'failed');
exit(count($failed) > 0 ? 1 : 0);
