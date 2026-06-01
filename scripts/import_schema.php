<?php

$host = getenv('QODA_IMPORT_HOST') ?: getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('QODA_IMPORT_PORT') ?: getenv('DB_PORT') ?: '3306';
$database = getenv('QODA_IMPORT_DB') ?: getenv('DB_NAME') ?: 'qoda_db';
$user = getenv('QODA_IMPORT_USER') ?: getenv('DB_USER') ?: 'root';
$password = getenv('QODA_IMPORT_PASS') ?: getenv('DB_PASS') ?: '';
$schemaPath = $argv[1] ?? __DIR__ . '/../backend-php/database.sql';

if (!is_file($schemaPath)) {
    fwrite(STDERR, "Schema file not found: {$schemaPath}\n");
    exit(1);
}

$dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
$pdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$sql = file_get_contents($schemaPath);
$pdo->exec($sql);

echo "Database import completed.\n";
