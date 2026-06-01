<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['unlocked' => false, 'error' => 'Not logged in']);
    exit;
}

$examId    = $_POST['exam_id'] ?? $_GET['exam_id'] ?? null;
$studentId = $_SESSION['user_id'];

if (!$examId) {
    echo json_encode(['unlocked' => false, 'error' => 'Missing exam_id']);
    exit;
}

try {
    // Check exam_visibility for explicit unlock
    $stmt = $pdo->prepare(
        "SELECT visible FROM exam_visibility WHERE exam_id = ? AND student_id = ? LIMIT 1"
    );
    $stmt->execute([$examId, $studentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && $row['visible'] == 1) {
        echo json_encode(['unlocked' => true]);
    } else {
        echo json_encode(['unlocked' => false]);
    }
} catch (PDOException $e) {
    error_log('check_unlock error: ' . $e->getMessage());
    echo json_encode(['unlocked' => false, 'error' => 'DB error']);
}
?>
