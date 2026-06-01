<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$examId    = $_POST['exam_id']        ?? null;
$type      = $_POST['violation_type'] ?? $_POST['type'] ?? 'unknown';
$details   = $_POST['details']        ?? '';
$userId    = $_SESSION['user_id'];

if (!$examId) {
    echo json_encode(['success' => false, 'error' => 'Missing exam_id']);
    exit;
}

// Resolve to students.id
$studRow = $pdo->prepare("SELECT id FROM students WHERE user_id = ? LIMIT 1");
$studRow->execute([$userId]);
$sRow = $studRow->fetch();
$studentId = $sRow ? $sRow['id'] : $userId;

try {
    $stmt = $pdo->prepare("
        INSERT INTO suspicious_logs
            (exam_id, student_id, event_type, details, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$examId, $studentId, $type, $details]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    // Table may not exist yet — fail silently so the exam isn't disrupted
    error_log('violation_logger error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Log error']);
}
?>
