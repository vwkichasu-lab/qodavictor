<?php

declare(strict_types=1);

$host = getenv('QODA_IMPORT_HOST') ?: '';
$port = getenv('QODA_IMPORT_PORT') ?: '3306';
$db = getenv('QODA_IMPORT_DB') ?: '';
$user = getenv('QODA_IMPORT_USER') ?: '';
$pass = getenv('QODA_IMPORT_PASS') ?: '';

if ($host === '' || $db === '' || $user === '') {
    fwrite(STDERR, "Missing QODA_IMPORT_* database environment variables.\n");
    exit(1);
}

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$tables = ['users', 'students', 'exams', 'exam_submissions', 'course_enrollments'];
foreach ($tables as $table) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        echo "{$table}={$count}\n";
    } catch (Throwable $e) {
        echo "{$table}=ERR {$e->getMessage()}\n";
    }
}

echo "users sample:\n";
$stmt = $pdo->query("SELECT id, user_id, userId, role, status, staff_id FROM users LIMIT 5");
foreach ($stmt as $row) {
    echo json_encode($row, JSON_UNESCAPED_SLASHES) . "\n";
}

echo "students sample:\n";
$stmt = $pdo->query("SELECT id, student_id, studentId, full_name, status FROM students LIMIT 5");
foreach ($stmt as $row) {
    echo json_encode($row, JSON_UNESCAPED_SLASHES) . "\n";
}
