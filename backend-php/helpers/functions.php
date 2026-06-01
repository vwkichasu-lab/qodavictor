<?php
// backend-php/helpers/functions.php

if (!function_exists('hashPassword')) {
    function hashPassword($password)
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}

if (!function_exists('verifyPassword')) {
    function verifyPassword($password, $hash)
    {
        return password_verify($password, $hash);
    }
}

function escapeHTML($str)
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function formatDate($date, $format = 'M j, Y H:i')
{
    if (!$date) return '—';
    return date($format, strtotime($date));
}

function generateExamId()
{
    return 'EXAM-' . strtoupper(uniqid());
}

function generateQuestionId()
{
    return 'Q-' . strtoupper(uniqid());
}

function generateBankId()
{
    return 'QB-' . strtoupper(uniqid());
}

function generateSubmissionId()
{
    return 'SUB-' . strtoupper(uniqid());
}

function calculateGrade($score, $total)
{
    if ($total <= 0) return ['grade' => 'N/A', 'class' => ''];

    $percentage = ($score / $total) * 100;

    if ($percentage >= 80) return ['grade' => 'A', 'class' => 'grade-a'];
    if ($percentage >= 75) return ['grade' => 'B+', 'class' => 'grade-bplus'];
    if ($percentage >= 70) return ['grade' => 'B', 'class' => 'grade-b'];
    if ($percentage >= 65) return ['grade' => 'C+', 'class' => 'grade-cplus'];
    if ($percentage >= 60) return ['grade' => 'C', 'class' => 'grade-c'];
    if ($percentage >= 55) return ['grade' => 'D+', 'class' => 'grade-dplus'];
    if ($percentage >= 50) return ['grade' => 'D', 'class' => 'grade-d'];
    return ['grade' => 'E', 'class' => 'grade-e'];
}

function logActivity($userId, $userRole, $action, $details = [])
{
    $db = getDB();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $stmt = $db->prepare("INSERT INTO activity_log (userId, userRole, action, details, ipAddress, userAgent, createdAt) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$userId, $userRole, $action, json_encode($details), $ip, $userAgent]);
}
