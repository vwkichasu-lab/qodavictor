<?php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$examId = $data['exam_id'] ?? 0;
$studentId = $data['student_id'] ?? 0;
$imageData = $data['image'] ?? '';

if ($imageData) {
    // Remove base64 header
    $imageData = str_replace('data:image/jpeg;base64,', '', $imageData);
    $imageData = str_replace(' ', '+', $imageData);
    $imageBinary = base64_decode($imageData);
    
    // Save to file
    $uploadDir = __DIR__ . '/../uploads/screens/';
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
    
    $filename = 'screen_' . $examId . '_' . $studentId . '_' . time() . '.jpg';
    file_put_contents($uploadDir . $filename, $imageBinary);
    
    // Store in database
    try {
        $pdo = require_once 'config/database.php';
        $stmt = $pdo->prepare("INSERT INTO screen_captures (exam_id, student_id, image_path, captured_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$examId, $studentId, $filename]);
    } catch(Exception $e) {}
}

echo json_encode(['success' => true]);
?>