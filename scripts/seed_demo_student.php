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

$lecturer = $pdo->query("SELECT id FROM users WHERE user_id = 'PULC/IT/00001' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$lecturer) {
    fwrite(STDERR, "Default lecturer PULC/IT/00001 was not found.\n");
    exit(1);
}

$studentPasswordHash = '$2y$10$.qOvckQyasCwO.B0fmRo5eZp/XnyOXlp2BPzTuzI7ycScZ/edHozC';
$stmt = $pdo->prepare("
    INSERT INTO students (
        student_id, studentId, full_name, fullName, email, password, level,
        programme, department, faculty, status, lecturer_id
    ) VALUES (
        'PUSE/22210033', 'PUSE/22210033', 'Demo Student', 'Demo Student',
        'student@qoda.test', ?, '200', 'Information Technology', 'IT',
        'Technology', 'Active', ?
    )
    ON DUPLICATE KEY UPDATE
        full_name = VALUES(full_name),
        fullName = VALUES(fullName),
        email = VALUES(email),
        level = VALUES(level),
        programme = VALUES(programme),
        department = VALUES(department),
        faculty = VALUES(faculty),
        status = VALUES(status),
        lecturer_id = VALUES(lecturer_id)
");
$stmt->execute([$studentPasswordHash, (int) $lecturer['id']]);

$student = $pdo->query("SELECT id FROM students WHERE student_id = 'PUSE/22210033' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    fwrite(STDERR, "Demo student could not be created.\n");
    exit(1);
}

$enroll = $pdo->prepare("
    INSERT INTO course_enrollments (
        student_id, course_code, course_name, lecturer_id, semester, academic_year
    ) VALUES (?, 'PBIT102', 'Programming Fundamentals', ?, 'First Semester', '2024/2025')
    ON DUPLICATE KEY UPDATE
        course_name = VALUES(course_name),
        lecturer_id = VALUES(lecturer_id),
        semester = VALUES(semester),
        academic_year = VALUES(academic_year)
");
$enroll->execute([(int) $student['id'], (int) $lecturer['id']]);

echo "Demo student ready: PUSE/22210033\n";
