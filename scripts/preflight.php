<?php

require_once __DIR__ . '/../backend-php/lib/code_runner.php';

$checks = [];

function add_check(array &$checks, string $name, bool $ok, string $detail = ''): void
{
    $checks[] = compact('name', 'ok', 'detail');
}

$pdo = null;
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'qoda_db';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';

if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    add_check($checks, 'Database connection', false, 'PDO MySQL driver is not installed for this PHP CLI.');
} else {
    try {
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->query('SELECT 1');
        add_check($checks, 'Database connection', true);
    } catch (Throwable $e) {
        add_check($checks, 'Database connection', false, $e->getMessage());
    }
}

foreach (['users', 'students', 'exams', 'exam_submissions'] as $table) {
    if (!$pdo) {
        add_check($checks, "Table {$table}", false, 'Skipped because database is unavailable.');
        continue;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        add_check($checks, "Table {$table}", (int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        add_check($checks, "Table {$table}", false, $e->getMessage());
    }
}

$isProductionLike = getenv('RAILWAY_ENVIRONMENT') || getenv('RAILWAY_ENVIRONMENT_ID') || getenv('QODA_ENV') === 'production';
foreach (['JWT_SECRET', 'QODA_APP_SECRET', 'QODA_SOCKET_SECRET'] as $secretName) {
    $configured = trim((string)getenv($secretName)) !== '';
    add_check(
        $checks,
        "Secret {$secretName}",
        $isProductionLike ? $configured : true,
        $configured ? 'Configured' : ($isProductionLike ? 'Missing in production' : 'Optional for local development')
    );
}

foreach (['runtime/code-execution', 'uploads', 'web-client/uploads'] as $dir) {
    $path = __DIR__ . '/../' . $dir;
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
    add_check($checks, "Writable {$dir}", is_dir($path) && is_writable($path));
}

$largeFiles = [
    'web-client/lecturer_dashboard.php' => 900000,
    'web-client/exam_interface.php' => 300000,
];
foreach ($largeFiles as $file => $warningSize) {
    $path = __DIR__ . '/../' . $file;
    $size = is_file($path) ? filesize($path) : 0;
    add_check(
        $checks,
        "Maintainability {$file}",
        $size > 0,
        $size > $warningSize ? 'Large file: plan modularization (' . number_format($size) . ' bytes)' : number_format($size) . ' bytes'
    );
}

$executables = [
    'Python' => ['python', 'python3', 'C:\\Python314\\python.exe'],
    'Node.js' => ['node', 'C:\\Program Files\\nodejs\\node.exe'],
    'PHP CLI' => ['php', 'C:\\xampp\\php\\php.exe'],
    'Java compiler' => ['javac', 'C:\\Program Files\\Java\\jdk-21\\bin\\javac.exe', 'C:\\Program Files\\Java\\jdk-25\\bin\\javac.exe'],
    'GCC' => ['gcc', __DIR__ . '/../tools/winlibs-gcc/mingw64/bin/gcc.exe'],
    'G++' => ['g++', __DIR__ . '/../tools/winlibs-gcc/mingw64/bin/g++.exe'],
    'SQLite' => ['sqlite3', 'C:\\sqlite\\sqlite3.exe'],
    '.NET SDK' => ['dotnet', 'C:\\Program Files\\dotnet\\dotnet.exe'],
];

foreach ($executables as $name => $candidates) {
    add_check($checks, $name, qodaFindExecutable($candidates) !== null);
}

$migrationFiles = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
add_check($checks, 'Migration files discovered', count($migrationFiles) > 0, count($migrationFiles) . ' file(s)');
if ($pdo) {
    require_once __DIR__ . '/../backend-php/lib/migrations.php';
    $applied = qodaAppliedMigrations($pdo);
    add_check($checks, 'Migrations applied', count($applied) >= count($migrationFiles), count($applied) . '/' . count($migrationFiles));
} else {
    add_check($checks, 'Migrations applied', false, 'Skipped because database is unavailable.');
}

$failed = 0;
foreach ($checks as $check) {
    $status = $check['ok'] ? 'PASS' : 'FAIL';
    if (!$check['ok']) $failed++;
    echo "[{$status}] {$check['name']}";
    if ($check['detail'] !== '') echo " - {$check['detail']}";
    echo PHP_EOL;
}

exit($failed > 0 ? 1 : 0);
