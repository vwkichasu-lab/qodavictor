<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$examId        = $_POST['exam_id']        ?? '';
$answers       = $_POST['answers']        ?? '';
$totalScore    = $_POST['total_score']    ?? 0;
$totalMarks    = $_POST['total_marks']    ?? 0;
$percentage    = $_POST['percentage']     ?? 0;
$timeSpent     = $_POST['time_spent']     ?? null;
$studentId     = $_SESSION['user_id'];

if (empty($examId) || empty($answers)) {
    echo json_encode(['success' => false, 'error' => 'Missing data']);
    exit;
}

// Decode answers JSON for answers_json column
$answersDecoded = json_decode($answers, true);
$answersJson    = ($answersDecoded !== null) ? $answers : null;

// Resolve student row — exam_submissions.student_id is FK to students.id
// Session may store the users.id or students.id depending on login path; resolve both.
$studentRowId = $studentId;

$checkStudent = $pdo->prepare("SELECT id FROM students WHERE user_id = ? LIMIT 1");
$checkStudent->execute([$studentId]);
$studentRow = $checkStudent->fetch();
if ($studentRow) {
    $studentRowId = $studentRow['id'];
}

// Look for an existing in_progress or any submission for this exam+student
$checkStmt = $pdo->prepare(
    "SELECT id, started_at FROM exam_submissions
     WHERE exam_id = ? AND student_id = ?
     ORDER BY id DESC LIMIT 1"
);
$checkStmt->execute([$examId, $studentRowId]);
$existing = $checkStmt->fetch();

$ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

if ($existing) {
    $stmt = $pdo->prepare("
        UPDATE exam_submissions
        SET answers = ?, answers_json = ?, total_score = ?, total_marks = ?,
            percentage = ?, status = 'submitted', submitted_at = NOW(),
            submittedAt = NOW(), submitted = 1,
            time_spent_seconds = ?, ip_address = ?, user_agent = ?
        WHERE id = ?
    ");
    $result = $stmt->execute([
        $answers, $answersJson, $totalScore, $totalMarks,
        $percentage, $timeSpent, $ipAddress, $userAgent,
        $existing['id']
    ]);
} else {
    $stmt = $pdo->prepare("
        INSERT INTO exam_submissions
            (exam_id, student_id, answers, answers_json, total_score, total_marks,
             percentage, status, started_at, submitted_at, submittedAt,
             submitted, time_spent_seconds, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'submitted', NOW(), NOW(), NOW(), 1, ?, ?, ?)
    ");
    $result = $stmt->execute([
        $examId, $studentRowId, $answers, $answersJson,
        $totalScore, $totalMarks, $percentage,
        $timeSpent, $ipAddress, $userAgent
    ]);
}

if ($result) {
    $submissionId = $existing ? $existing['id'] : $pdo->lastInsertId();
    echo json_encode([
        'success'       => true,
        'message'       => 'Exam submitted successfully',
        'submission_id' => $submissionId
    ]);
} else {
    $error = $stmt->errorInfo();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . ($error[2] ?? 'unknown')]);
}
?>
