<?php
require_once __DIR__ . '/config.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// Check if user is a student
if ($_SESSION['user_role'] !== 'STUDENT') {
    header('Location: lecturer_dashboard.php');
    exit;
}

$db = getDB();

try {
    $checkResultsPublished = $db->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exams' AND COLUMN_NAME = 'results_published'
    ");
    $checkResultsPublished->execute();
    if ((int)$checkResultsPublished->fetchColumn() === 0) {
        $db->exec("ALTER TABLE exams ADD COLUMN results_published TINYINT(1) NOT NULL DEFAULT 0");
    }
    $checkResultsPublishedAt = $db->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exams' AND COLUMN_NAME = 'results_published_at'
    ");
    $checkResultsPublishedAt->execute();
    if ((int)$checkResultsPublishedAt->fetchColumn() === 0) {
        $db->exec("ALTER TABLE exams ADD COLUMN results_published_at DATETIME NULL");
    }
} catch (Exception $e) {
    // The dashboard can still load; unpublished results will be treated as hidden if the column exists.
}

// Get student details
$stmt = $db->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$studentData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$studentData) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Get enrolled courses with proper ordering
$coursesStmt = $db->prepare("
    SELECT ce.*, u.full_name as lecturer_name 
    FROM course_enrollments ce
    LEFT JOIN users u ON ce.lecturer_id = u.id
    WHERE ce.student_id = ?
    ORDER BY ce.course_code ASC
");
$coursesStmt->execute([$_SESSION['user_id']]);
$enrolledCoursesList = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);
$coursesCount = count($enrolledCoursesList);

// API endpoints
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'get_enrolled_courses_count') {
        echo json_encode(['success' => true, 'count' => $coursesCount]);
        exit;
    }
    
    if ($_POST['action'] === 'get_enrolled_courses_details') {
        echo json_encode(['success' => true, 'data' => $enrolledCoursesList]);
        exit;
    }
    
    if ($_POST['action'] === 'upload_profile_pic') {
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] == 0) {
            $uploadDir = 'uploads/';
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $ext = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($ext, $allowed)) {
                $filename = 'student_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
                $uploadPath = $uploadDir . $filename;
                
                if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $uploadPath)) {
                    $updateStmt = $db->prepare("UPDATE students SET profile_pic = ? WHERE id = ?");
                    $updateStmt->execute([$filename, $_SESSION['user_id']]);
                    echo json_encode(['success' => true, 'url' => $uploadPath]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Upload failed']);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid file type']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'No file uploaded']);
        }
        exit;
    }
    
    if ($_POST['action'] === 'change_student_password') {
        try {
            $current = $_POST['current_password'];
            $new = $_POST['new_password'];
            
            $stmt = $db->prepare("SELECT password FROM students WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!password_verify($current, $user['password'])) {
                echo json_encode(['success' => false, 'error' => 'Current password is incorrect']);
                exit;
            }
            
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $updateStmt = $db->prepare("UPDATE students SET password = ? WHERE id = ?");
            $updateStmt->execute([$hashed, $_SESSION['user_id']]);
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'get_student_exams') {
        try {
            $studentId = $_SESSION['user_id'];
            
            $coursesStmt = $db->prepare("SELECT DISTINCT course_code FROM course_enrollments WHERE student_id = ?");
            $coursesStmt->execute([$studentId]);
            $enrolledCourses = $coursesStmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($enrolledCourses)) {
                echo json_encode(['success' => true, 'data' => []]);
                exit;
            }
            
            $placeholders = implode(',', array_fill(0, count($enrolledCourses), '?'));
            
            $examStmt = $db->prepare("
                SELECT 
                    e.*,
                    e.id as exam_db_id,
                    e.title as exam_name,
                    e.course_code,
                    e.duration_minutes,
                    e.start_datetime,
                    e.end_datetime,
                    e.questions,
                    e.instructions,
                    e.results_published,
                    es.percentage as score,
                    es.total_score,
                    es.answers,
                    es.status as submission_status,
                    es.submitted,
                    es.submitted_at,
                    es.submittedAt
                FROM exams e
                LEFT JOIN exam_submissions es ON es.id = (
                    SELECT es2.id
                    FROM exam_submissions es2
                    WHERE es2.exam_id = e.id
                      AND es2.student_id = ?
                    ORDER BY COALESCE(es2.submitted_at, es2.submittedAt, es2.updated_at, es2.started_at) DESC, es2.id DESC
                    LIMIT 1
                )
                LEFT JOIN exam_visibility ev ON ev.exam_id = e.id AND ev.student_id = ?
                WHERE e.published = 1
                  AND e.course_code IN ($placeholders)
                  AND COALESCE(ev.visible, 1) = 1
                ORDER BY e.start_datetime ASC
            ");
            
            $params = array_merge([$studentId, $studentId], $enrolledCourses);
            $examStmt->execute($params);
            $exams = $examStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $serverNow = $db->query("SELECT NOW()")->fetchColumn();
            $nowTs = strtotime($serverNow ?: date('Y-m-d H:i:s'));
            $formattedExams = [];
            
            foreach ($exams as $exam) {
                $durationMins = max(1, (int)($exam['duration_minutes'] ?? 180));
                $startTs = !empty($exam['start_datetime']) ? strtotime($exam['start_datetime']) : null;
                $endTs = !empty($exam['end_datetime']) ? strtotime($exam['end_datetime']) : null;
                if ($startTs && (!$endTs || $endTs <= $startTs)) {
                    $endTs = $startTs + ($durationMins * 60);
                }
                $startDate = $startTs ? date('M d, Y h:i A', $startTs) : 'Not scheduled';
                $endDate = $endTs ? date('Y-m-d H:i:s', $endTs) : null;
                
                $submissionStatus = $exam['submission_status'] ?? null;
                $grading = [];
                if (!empty($exam['answers'])) {
                    $answers = json_decode($exam['answers'], true);
                    if (is_array($answers)) {
                        $grading = $answers['grading'] ?? $answers['_grading'] ?? [];
                    }
                }

                $classScore = isset($grading['class_score']) ? round((float)$grading['class_score']) : null;
                $examScore = isset($grading['exam_score']) ? round((float)$grading['exam_score']) : null;
                $grade = $grading['grade'] ?? null;
                $gradePoint = isset($grading['grade_point']) ? round((float)$grading['grade_point'], 1) : null;

                $score = $grading['total_score'] ?? $exam['score'];
                if (($score === null || $score === '') && $exam['total_score'] !== null && $exam['total_score'] !== '') {
                    $score = $exam['total_score'];
                }
                if (($score === null || $score === '') && !empty($grading)) {
                    $score = $grading['total_score'] ?? null;
                }
                $score = ($score === null || $score === '') ? null : round((float)$score);
                if ($grade === null && $score !== null) {
                    if ($score >= 80) { $grade = 'A'; $gradePoint = 4.0; }
                    elseif ($score >= 75) { $grade = 'B+'; $gradePoint = 3.5; }
                    elseif ($score >= 70) { $grade = 'B'; $gradePoint = 3.0; }
                    elseif ($score >= 65) { $grade = 'C+'; $gradePoint = 2.5; }
                    elseif ($score >= 60) { $grade = 'C'; $gradePoint = 2.0; }
                    elseif ($score >= 55) { $grade = 'D+'; $gradePoint = 1.5; }
                    elseif ($score >= 50) { $grade = 'D'; $gradePoint = 1.0; }
                    else { $grade = 'E'; $gradePoint = 0.0; }
                }
                
                // Proper status determination
                $submissionStatusKey = strtoupper((string)$submissionStatus);

                $hasRealSubmission = !empty($exam['submitted_at']) || !empty($exam['submittedAt']) || intval($exam['submitted'] ?? 0) === 1;

                $resultsPublished = intval($exam['results_published'] ?? 0) === 1;

                if ($hasRealSubmission && $resultsPublished && $score !== null) {
                    $status = 'completed';
                } elseif ($hasRealSubmission) {
                    $status = 'submitted';
                } elseif ($startTs && $startTs > $nowTs) {
                    $status = 'upcoming';
                } elseif ($startTs && $startTs <= $nowTs && (!$endTs || $endTs > $nowTs)) {
                    $status = 'active';
                } elseif ($endTs && $endTs < $nowTs) {
                    $status = 'expired';
                } else {
                    $status = 'available';
                }
                
                $formattedExams[] = [
                    'id' => $exam['exam_db_id'],
                    'exam_id' => $exam['exam_id'],
                    'name' => $exam['exam_name'],
                    'code' => $exam['course_code'],
                    'date' => $startDate,
                    'start_datetime' => $exam['start_datetime'],
                    'end_datetime' => $exam['end_datetime'],
                    'rawDate' => $exam['start_datetime'],
                    'submitted_at' => $exam['submitted_at'] ?: ($exam['submittedAt'] ?? null),
                    'endDate' => $endDate,
                    'duration' => $exam['duration_minutes'] . ' minutes',
                    'durationMins' => $exam['duration_minutes'],
                    'status' => $status,
                    'score' => $score,
                    'class_score' => $classScore,
                    'exam_score' => $examScore,
                    'grade' => $grade,
                    'grade_point' => $gradePoint,
                    'instructions' => $exam['instructions'] ?? ''
                ];
            }
            
            echo json_encode(['success' => true, 'data' => $formattedExams]);
            
        } catch (Exception $e) {
            error_log("get_student_exams error: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage(), 'data' => []]);
        }
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

$pageTitle = "Qoda | Student Dashboard";
$theme = isset($_SESSION['theme']) ? $_SESSION['theme'] : 'dark';
$_SESSION['theme'] = $theme;

$student = (object)[
    'id' => $studentData['id'],
    'name' => $studentData['full_name'],
    'email' => $studentData['email'] ?? '',
    'level' => $studentData['level'] ?? '',
    'program' => $studentData['programme'] ?? '',
    'matric_number' => $studentData['student_id'],
    'profile_pic' => $studentData['profile_pic'] ?? '',
    'contact' => $studentData['contact'] ?? '',
    'gender' => $studentData['gender'] ?? '',
    'dob' => $studentData['date_of_birth'] ?? ''
];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    :root {
        /* Dark Theme (Default) */
        --bg: #0a0a0f;
        --bg-card: #111827;
        --bg-hover: #1a1a2e;
        --border: #2d2d44;
        --text: #e0e0e0;
        --text-muted: #a0a0a0;
        --text-dark: #ffffff;
        --accent: #3b82f6;
        --accent-hover: #60a5fa;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --purple: #8b5cf6;
        --pink: #ec4899;
        --btn-bg: #1f2937;
        --btn-hover: #374151;
        --input-bg: #1f2937;
        --input-border: #4b5563;
        --shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.2);
    }

    /* Light Theme */
    body.light {
        --bg: #f3f4f6;
        --bg-card: #ffffff;
        --bg-hover: #f9fafb;
        --border: #e5e7eb;
        --text: #111827;
        --text-muted: #6b7280;
        --text-dark: #111827;
        --accent: #3b82f6;
        --accent-hover: #2563eb;
        --success: #059669;
        --warning: #d97706;
        --danger: #dc2626;
        --btn-bg: #f3f4f6;
        --btn-hover: #e5e7eb;
        --input-bg: #ffffff;
        --input-border: #d1d5db;
        --shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.05);
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: var(--bg);
        color: var(--text);
        transition: all 0.3s ease;
    }

    /* Top Bar */
    .top-bar {
        background: var(--bg-card);
        border-bottom: 2px solid var(--border);
        padding: 15px 25px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
        box-shadow: var(--shadow-sm);
    }

    .logo-area {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .logo-icon {
        width: 45px;
        height: 45px;
        background: linear-gradient(135deg, var(--accent), var(--purple));
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        font-weight: bold;
        color: white;
    }

    .logo-text h1 {
        font-size: 20px;
        font-weight: 700;
        color: var(--text);
    }

    .logo-text p {
        font-size: 11px;
        color: var(--text-muted);
    }

    .student-info {
        display: flex;
        align-items: center;
        gap: 15px;
        background: var(--bg);
        padding: 8px 20px;
        border-radius: 50px;
        border: 1px solid var(--border);
    }

    .student-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--accent), var(--purple));
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
    }

    .student-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .student-details {
        text-align: right;
    }

    .student-name {
        font-weight: 600;
        font-size: 14px;
        color: var(--text);
    }

    .student-id {
        font-size: 11px;
        color: var(--text-muted);
    }

    .theme-btn {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--btn-bg);
        border: 1px solid var(--input-border);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
        color: var(--text);
    }

    .theme-btn:hover {
        background: var(--accent);
        color: white;
        transform: scale(1.05);
    }

    .logout-btn {
        padding: 8px 20px;
        border-radius: 30px;
        background: var(--danger);
        color: white;
        border: none;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.2s;
    }

    .logout-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
    }

    /* Main Container */
    .container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 30px;
    }

    /* Back Button - FIXED VISIBILITY */
    .back-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 20px;
        background: var(--btn-bg);
        border: 1px solid var(--input-border);
        border-radius: 30px;
        cursor: pointer;
        margin-bottom: 20px;
        font-size: 14px;
        font-weight: 600;
        transition: all 0.2s;
        color: var(--text);
    }

    .back-btn i {
        color: var(--accent);
    }

    .back-btn:hover {
        background: var(--accent);
        color: white;
        transform: translateX(-3px);
        border-color: var(--accent);
    }

    .back-btn:hover i {
        color: white;
    }

    /* Cards Grid */
    .cards-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 25px;
        margin-bottom: 30px;
    }

    .dashboard-card {
        background: var(--bg-card);
        border-radius: 20px;
        padding: 25px;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border: 1px solid var(--border);
        position: relative;
        overflow: hidden;
    }

    .dashboard-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: var(--card-color);
    }

    .dashboard-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow);
        border-color: var(--card-color);
    }

    .card-icon {
        width: 55px;
        height: 55px;
        border-radius: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        margin-bottom: 15px;
    }

    .card-icon.blue {
        background: rgba(59, 130, 246, 0.15);
        color: #3b82f6;
    }

    .card-icon.green {
        background: rgba(16, 185, 129, 0.15);
        color: #10b981;
    }

    .card-icon.orange {
        background: rgba(245, 158, 11, 0.15);
        color: #f59e0b;
    }

    .card-icon.purple {
        background: rgba(139, 92, 246, 0.15);
        color: #8b5cf6;
    }

    .card-icon.pink {
        background: rgba(236, 72, 153, 0.15);
        color: #ec4899;
    }

    .card-icon.red {
        background: rgba(239, 68, 68, 0.15);
        color: #ef4444;
    }

    .card-value {
        font-size: 32px;
        font-weight: 700;
        margin-bottom: 5px;
        color: var(--text);
    }

    .card-label {
        font-size: 14px;
        color: var(--text-muted);
    }

    /* Panel */
    .panel {
        background: var(--bg-card);
        border-radius: 16px;
        border: 1px solid var(--border);
        overflow: hidden;
    }

    .panel-header {
        padding: 18px 22px;
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
        background: var(--bg-card);
    }

    .panel-header h3 {
        font-size: 18px;
        font-weight: 600;
        color: var(--text);
    }

    .panel-body {
        padding: 20px;
        background: var(--bg-card);
    }

    /* Filter Bar */
    .filter-bar {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        margin-bottom: 20px;
        padding: 15px;
        background: var(--bg);
        border-radius: 12px;
        align-items: center;
    }

    .filter-bar input,
    .filter-bar select {
        padding: 10px 14px;
        border-radius: 8px;
        border: 1px solid var(--input-border);
        background: var(--input-bg);
        color: var(--text);
        min-width: 180px;
        font-size: 13px;
    }

    .filter-bar input::placeholder {
        color: var(--text-muted);
    }

    .filter-bar input:focus,
    .filter-bar select:focus {
        outline: none;
        border-color: var(--accent);
        box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
    }

    /* Table */
    .data-table {
        width: 100%;
        border-collapse: collapse;
    }

    .data-table th,
    .data-table td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid var(--border);
    }

    .data-table th {
        color: var(--text-muted);
        font-weight: 600;
        font-size: 12px;
        text-transform: uppercase;
        background: var(--bg);
    }

    .data-table td {
        color: var(--text);
    }

    .data-table tr:hover td {
        background: var(--bg-hover);
    }

    /* Badges */
    .badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 500;
        display: inline-block;
    }

    .badge-success {
        background: rgba(16, 185, 129, 0.15);
        color: #10b981;
    }

    .badge-warning {
        background: rgba(245, 158, 11, 0.15);
        color: #f59e0b;
    }

    .badge-info {
        background: rgba(59, 130, 246, 0.15);
        color: #3b82f6;
    }

    .badge-danger {
        background: rgba(239, 68, 68, 0.15);
        color: #ef4444;
    }

    .badge-purple {
        background: rgba(139, 92, 246, 0.15);
        color: #8b5cf6;
    }

    .badge-expired {
        background: rgba(107, 114, 128, 0.15);
        color: #9ca3af;
    }

    /* Exam Card for Upcoming */
    .exam-card {
        background: var(--bg);
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 15px;
        border: 1px solid var(--border);
        transition: all 0.3s;
        cursor: pointer;
    }

    .exam-card:hover {
        transform: translateX(5px);
        border-color: var(--accent);
        box-shadow: var(--shadow-sm);
    }

    .exam-priority-section {
        margin-bottom: 26px;
    }

    .student-hero {
        background:
            linear-gradient(135deg, rgba(59, 130, 246, .22), rgba(16, 185, 129, .14)),
            var(--bg-card);
        border: 1px solid var(--border);
        border-radius: 18px;
        padding: 22px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 18px;
        margin-bottom: 18px;
        box-shadow: var(--shadow-sm);
    }

    .student-hero h2 {
        font-size: clamp(22px, 3vw, 34px);
        margin-bottom: 6px;
        color: var(--text);
        letter-spacing: 0;
    }

    .student-hero p {
        color: var(--text-muted);
        line-height: 1.5;
    }

    .hero-badge {
        min-width: 96px;
        min-height: 96px;
        border-radius: 18px;
        background: rgba(59, 130, 246, .18);
        color: var(--accent);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 38px;
    }

    .exam-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 16px;
    }

    .exam-card.pro {
        margin-bottom: 0;
        background: linear-gradient(180deg, rgba(255,255,255,.035), rgba(255,255,255,0)), var(--bg-card);
        border-radius: 14px;
        padding: 18px;
        border: 1px solid var(--border);
        display: flex;
        flex-direction: column;
        gap: 14px;
        min-height: 190px;
        cursor: default;
    }

    .exam-card.pro:hover {
        transform: translateY(-3px);
    }

    .exam-card-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
    }

    .exam-card-title {
        font-size: 17px;
        font-weight: 800;
        color: var(--text);
        line-height: 1.25;
    }

    .exam-card-course {
        color: var(--accent);
        font-size: 12px;
        font-weight: 700;
        margin-top: 6px;
        text-transform: uppercase;
    }

    .exam-meta-list {
        display: grid;
        gap: 8px;
        color: var(--text-muted);
        font-size: 13px;
    }

    .exam-meta-list span {
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 0;
    }

    .exam-actions {
        margin-top: auto;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
    }

    .exam-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 10px;
    }

    .exam-title {
        font-size: 18px;
        font-weight: 700;
        color: var(--accent);
    }

    .exam-details {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
        margin-top: 10px;
        font-size: 13px;
        color: var(--text-muted);
    }

    /* Timer */
    .timer {
        font-family: monospace;
        font-weight: 700;
        padding: 3px 10px;
        border-radius: 12px;
        font-size: 11px;
        background: rgba(245, 158, 11, 0.15);
        color: #f59e0b;
    }

    .timer.urgent {
        background: rgba(239, 68, 68, 0.15);
        color: #ef4444;
        animation: pulse 1s infinite;
    }

    @keyframes pulse {

        0%,
        100% {
            opacity: 1;
        }

        50% {
            opacity: 0.6;
        }
    }

    /* Buttons - FIXED VISIBILITY */
    .btn {
        padding: 8px 16px;
        border-radius: 8px;
        border: none;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 13px;
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--accent), var(--purple));
        color: white;
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
    }

    .btn-outline {
        background: var(--btn-bg);
        border: 1px solid var(--input-border);
        color: var(--text);
    }

    .btn-outline:hover {
        background: var(--accent);
        color: white;
        border-color: var(--accent);
    }

    .btn-outline i {
        color: var(--accent);
    }

    .btn-outline:hover i {
        color: white;
    }

    .btn-success {
        background: var(--success);
        color: white;
    }

    .btn-danger {
        background: var(--danger);
        color: white;
    }

    .btn:disabled,
    .btn.disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none;
    }

    /* Modal */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.8);
        z-index: 1000;
        align-items: center;
        justify-content: center;
    }

    .modal-content {
        background: var(--bg-card);
        border-radius: 20px;
        padding: 25px;
        max-width: 800px;
        width: 90%;
        max-height: 85vh;
        overflow-y: auto;
        border: 1px solid var(--border);
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 1px solid var(--border);
    }

    .modal-header h3 {
        font-size: 20px;
        color: var(--text);
    }

    .modal-close {
        cursor: pointer;
        font-size: 24px;
        transition: all 0.2s;
        color: var(--text-muted);
    }

    .modal-close:hover {
        color: var(--danger);
    }

    /* Result Header */
    .result-header {
        text-align: center;
        margin-bottom: 25px;
        padding-bottom: 20px;
        border-bottom: 2px solid var(--border);
    }

    .result-header h2 {
        font-size: 22px;
        margin-bottom: 5px;
        color: var(--text);
    }

    .result-header p {
        color: var(--text-muted);
        font-size: 13px;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 50px;
        color: var(--text-muted);
    }

    .empty-state i {
        font-size: 48px;
        margin-bottom: 15px;
        opacity: 0.5;
    }

    /* Toast */
    .toast {
        position: fixed;
        bottom: 30px;
        left: 50%;
        transform: translateX(-50%);
        background: var(--bg-card);
        color: var(--text);
        padding: 12px 24px;
        border-radius: 50px;
        font-size: 14px;
        z-index: 1100;
        opacity: 0;
        transition: opacity 0.3s;
        pointer-events: none;
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
    }

    /* Spinner */
    .spinner {
        width: 40px;
        height: 40px;
        border: 3px solid var(--border);
        border-top-color: var(--accent);
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 20px auto;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    /* Scrollbar */
    ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }

    ::-webkit-scrollbar-track {
        background: var(--bg);
        border-radius: 4px;
    }

    ::-webkit-scrollbar-thumb {
        background: var(--border);
        border-radius: 4px;
    }

    ::-webkit-scrollbar-thumb:hover {
        background: var(--accent);
    }

    /* Responsive */
    @media (max-width: 768px) {
        .container {
            padding: 15px;
        }

        .top-bar {
            flex-direction: column;
            text-align: center;
        }

        .student-info {
            width: 100%;
            justify-content: center;
        }

        .cards-grid {
            grid-template-columns: 1fr;
        }

        .data-table {
            display: block;
            overflow-x: auto;
        }

        .filter-bar {
            flex-direction: column;
            align-items: stretch;
        }

        .filter-bar input,
        .filter-bar select {
            width: 100%;
        }

        .student-hero {
            align-items: flex-start;
        }

        .hero-badge {
            display: none;
        }
    }
    </style>
</head>

<body>
    <div class="top-bar">
        <div class="logo-area">
            <div class="logo-icon">Q</div>
            <div class="logo-text">
                <h1>Qoda PU</h1>
                <p>Student Portal</p>
            </div>
        </div>
        <div class="student-info">
            <div class="student-details">
                <div class="student-name"><?php echo htmlspecialchars($student->name); ?></div>
                <div class="student-id">ID: <?php echo htmlspecialchars($student->matric_number); ?></div>
            </div>
            <div class="student-avatar">
                <?php if (!empty($student->profile_pic)): ?>
                <img src="uploads/<?php echo htmlspecialchars($student->profile_pic); ?>" alt="Profile">
                <?php else: ?>
                <i class="fas fa-user-graduate"></i>
                <?php endif; ?>
            </div>
        </div>
        <div style="display: flex; gap: 10px;">
            <button class="theme-btn" onclick="toggleTheme()">
                <i class="fas fa-moon" id="themeIcon"></i>
            </button>
            <button class="logout-btn" onclick="logout()">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </div>
    </div>

    <div class="container">
        <!-- Dashboard View -->
        <div id="dashboardView">
            <div class="exam-priority-section">
                <div class="student-hero">
                    <div>
                        <h2>Your Exams</h2>
                        <p>Upcoming, active, and submitted exams are arranged first so you can move straight to what matters.</p>
                    </div>
                    <div class="hero-badge"><i class="fas fa-laptop-code"></i></div>
                </div>
                <div id="dashboardExamsContainer" class="exam-grid">
                    <div class="spinner"></div>
                </div>
            </div>

            <div class="cards-grid">
                <div class="dashboard-card" onclick="navigateTo('exams')" style="--card-color: #3b82f6;">
                    <div class="card-icon blue"><i class="fas fa-file-alt"></i></div>
                    <div class="card-value" id="totalExams">0</div>
                    <div class="card-label">My Exams</div>
                </div>
                <div class="dashboard-card" onclick="navigateTo('completed')" style="--card-color: #10b981;">
                    <div class="card-icon green"><i class="fas fa-check-circle"></i></div>
                    <div class="card-value" id="completedExams">0</div>
                    <div class="card-label">Submitted</div>
                </div>
                <div class="dashboard-card" onclick="navigateTo('upcoming')" style="--card-color: #f59e0b;">
                    <div class="card-icon orange"><i class="fas fa-clock"></i></div>
                    <div class="card-value" id="upcomingExams">0</div>
                    <div class="card-label">Upcoming / Ongoing</div>
                </div>
                <div class="dashboard-card" onclick="showEnrolledCourses()" style="--card-color: #8b5cf6;">
                    <div class="card-icon purple"><i class="fas fa-book-open"></i></div>
                    <div class="card-value" id="coursesCount"><?php echo $coursesCount; ?></div>
                    <div class="card-label">Courses Enrolled</div>
                </div>
                <div class="dashboard-card" onclick="navigateTo('results')" style="--card-color: #ec4899;">
                    <div class="card-icon pink"><i class="fas fa-chart-bar"></i></div>
                    <div class="card-value" id="resultsCount">0</div>
                    <div class="card-label">Results</div>
                </div>
                <div class="dashboard-card" onclick="navigateTo('profile')" style="--card-color: #ef4444;">
                    <div class="card-icon red"><i class="fas fa-user"></i></div>
                    <div class="card-value">👤</div>
                    <div class="card-label">My Profile</div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <h3><i class="fas fa-hourglass-half"></i> Next Exam</h3>
                </div>
                <div class="panel-body" id="nextExamPanel">
                    <div class="empty-state"><i class="fas fa-calendar-times"></i>
                        <p>No upcoming exams</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- My Exams View -->
        <div id="examsView" style="display: none;">
            <button class="back-btn" onclick="goBack()"><i class="fas fa-arrow-left"></i> Back</button>
            <div class="panel">
                <div class="panel-header">
                    <h3><i class="fas fa-file-alt"></i> My Exams</h3>
                </div>
                <div class="filter-bar">
                    <input type="text" id="searchMyExams" placeholder="🔍 Search by exam name or course code...">
                    <select id="filterMyExamsStatus">
                        <option value="all">All Status</option>
                        <option value="upcoming">Upcoming</option>
                        <option value="active">Available Now</option>
                        <option value="completed">Completed</option>
                        <option value="submitted">Submitted (Pending)</option>
                        <option value="expired">Expired</option>
                    </select>
                    <button class="btn btn-primary" onclick="filterMyExams()">Apply Filter</button>
                    <button class="btn btn-outline" onclick="resetMyExamsFilter()">Reset</button>
                </div>
                <div class="panel-body" style="padding: 0;" id="myExamsContainer">
                    <div class="spinner"></div>
                </div>
            </div>
        </div>

        <!-- Completed Exams View -->
        <div id="completedView" style="display: none;">
            <button class="back-btn" onclick="goBack()"><i class="fas fa-arrow-left"></i> Back</button>
            <div class="panel">
                <div class="panel-header">
                    <h3><i class="fas fa-check-circle"></i> Submitted Exams</h3>
                </div>
                <div class="filter-bar">
                    <input type="text" id="searchCompleted" placeholder="🔍 Search by exam name or course...">
                    <select id="groupCompletedBy">
                        <option value="none">No Grouping</option>
                        <option value="course">Group by Course</option>
                        <option value="month">Group by Month</option>
                    </select>
                    <button class="btn btn-primary" onclick="filterCompleted()">Apply</button>
                </div>
                <div class="panel-body" style="padding: 0;" id="completedContainer">
                    <div class="spinner"></div>
                </div>
            </div>
        </div>

        <!-- Upcoming Exams View -->
        <div id="upcomingView" style="display: none;">
            <button class="back-btn" onclick="goBack()"><i class="fas fa-arrow-left"></i> Back</button>
            <div class="panel">
                <div class="panel-header">
                    <h3><i class="fas fa-calendar-alt"></i> Upcoming Exams</h3>
                </div>
                <div class="filter-bar">
                    <input type="text" id="searchUpcoming" placeholder="🔍 Search by exam name or course...">
                    <button class="btn btn-primary" onclick="filterUpcoming()">Apply Filter</button>
                </div>
                <div class="panel-body" id="upcomingContainer">
                    <div class="spinner"></div>
                </div>
            </div>
        </div>

        <!-- Results View -->
        <div id="resultsView" style="display: none;">
            <button class="back-btn" onclick="goBack()"><i class="fas fa-arrow-left"></i> Back</button>
            <div class="panel">
                <div class="panel-header">
                    <h3><i class="fas fa-chart-bar"></i> My Results</h3>
                    <div>
                        <button class="btn btn-outline" onclick="printResults()"><i class="fas fa-print"></i>
                            Print</button>
                        <button class="btn btn-outline" onclick="downloadResultsPDF()"><i class="fas fa-file-pdf"></i>
                            Save as PDF</button>
                    </div>
                </div>
                <div class="filter-bar">
                    <input type="text" id="searchResults" placeholder="🔍 Search by course name or code...">
                    <select id="filterResultsCourse">
                        <option value="all">All Courses</option>
                    </select>
                    <select id="filterResultsTimeframe">
                        <option value="all">All Time</option>
                        <option value="today">Today</option>
                        <option value="week">This Week</option>
                        <option value="month">This Month</option>
                        <option value="year">This Year</option>
                    </select>
                    <input type="month" id="filterResultsDate" placeholder="Filter by month">
                    <button class="btn btn-primary" onclick="filterResults()">Apply Filter</button>
                    <button class="btn btn-outline" onclick="resetResultsFilter()">Reset</button>
                </div>
                <div class="panel-body" style="padding: 0;" id="resultsContainer">
                    <div class="spinner"></div>
                </div>
            </div>
        </div>

        <!-- Profile View -->
        <div id="profileView" style="display: none;">
            <button class="back-btn" onclick="goBack()"><i class="fas fa-arrow-left"></i> Back</button>
            <div class="panel">
                <div class="panel-header">
                    <h3><i class="fas fa-user"></i> My Profile</h3>
                </div>
                <div class="panel-body">
                    <div style="display: flex; gap: 30px; flex-wrap: wrap;">
                        <div style="text-align: center;">
                            <div class="student-avatar"
                                style="width: 120px; height: 120px; border-radius: 50%; font-size: 48px;">
                                <?php if (!empty($student->profile_pic)): ?>
                                <img src="uploads/<?php echo htmlspecialchars($student->profile_pic); ?>" alt="Profile"
                                    id="profileImage" style="width:100%;height:100%;object-fit:cover;">
                                <?php else: ?>
                                <i class="fas fa-user-graduate"></i>
                                <?php endif; ?>
                            </div>
                            <button class="btn btn-outline" style="margin-top: 12px;"
                                onclick="document.getElementById('profilePicInput').click()">
                                <i class="fas fa-camera"></i> Change Photo
                            </button>
                            <input type="file" id="profilePicInput" style="display: none;" accept="image/*"
                                onchange="uploadProfilePic(this)">
                        </div>
                        <div style="flex: 1;">
                            <div style="display: grid; gap: 16px;">
                                <div>
                                    <label style="color: var(--text-muted); font-size: 12px;">Full Name</label>
                                    <div style="font-size: 18px; font-weight: 600;">
                                        <?php echo htmlspecialchars($student->name); ?></div>
                                </div>
                                <div>
                                    <label style="color: var(--text-muted); font-size: 12px;">Student ID</label>
                                    <div style="font-size: 18px; font-weight: 600;">
                                        <?php echo htmlspecialchars($student->matric_number); ?></div>
                                </div>
                                <div>
                                    <label style="color: var(--text-muted); font-size: 12px;">Programme</label>
                                    <div style="font-size: 18px; font-weight: 600;">
                                        <?php echo htmlspecialchars($student->program ?: 'Not set'); ?></div>
                                </div>
                                <div>
                                    <label style="color: var(--text-muted); font-size: 12px;">Level</label>
                                    <div style="font-size: 18px; font-weight: 600;">
                                        <?php echo htmlspecialchars($student->level ?: 'Not set'); ?></div>
                                </div>
                                <div>
                                    <label style="color: var(--text-muted); font-size: 12px;">Email</label>
                                    <div style="font-size: 18px; font-weight: 600;">
                                        <?php echo htmlspecialchars($student->email ?: 'Not set'); ?></div>
                                </div>
                                <div>
                                    <label style="color: var(--text-muted); font-size: 12px;">Change Password</label>
                                    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 8px;">
                                        <input type="password" id="currentPassword" placeholder="Current Password"
                                            style="flex: 1; padding: 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg); color: var(--text);">
                                        <input type="password" id="newPassword" placeholder="New Password"
                                            style="flex: 1; padding: 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg); color: var(--text);">
                                        <button class="btn btn-primary" onclick="changePassword()">Update</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Enrolled Courses Modal -->
    <div id="coursesModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-book-open"></i> My Enrolled Courses (<?php echo $coursesCount; ?> courses)</h3>
                <span class="modal-close" onclick="closeCoursesModal()">&times;</span>
            </div>
            <div id="coursesModalBody">
                <div class="spinner"></div>
            </div>
        </div>
    </div>

    <!-- Result Details Modal -->
    <div id="resultModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="resultModalTitle">Exam Result</h3>
                <span class="modal-close" onclick="closeResultModal()">&times;</span>
            </div>
            <div id="resultModalBody"></div>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <script>
    let myExams = [];
    let timerInterval;
    let currentView = 'dashboard';

    // Theme Functions
    function toggleTheme() {
        const isLight = document.body.classList.contains('light');
        const icon = document.getElementById('themeIcon');
        if (isLight) {
            document.body.classList.remove('light');
            icon.className = 'fas fa-moon';
            fetch('set-theme.php', {
                method: 'POST',
                body: 'theme=dark',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            });
        } else {
            document.body.classList.add('light');
            icon.className = 'fas fa-sun';
            fetch('set-theme.php', {
                method: 'POST',
                body: 'theme=light',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            });
        }
    }

    const savedTheme = '<?php echo $theme; ?>';
    if (savedTheme === 'light') {
        document.body.classList.add('light');
        document.getElementById('themeIcon').className = 'fas fa-sun';
    }

    // Navigation
    function navigateTo(view) {
        currentView = view;
        document.querySelectorAll(
            '#dashboardView, #examsView, #completedView, #upcomingView, #resultsView, #profileView').forEach(el =>
            el.style.display = 'none');

        const viewMap = {
            'exams': 'examsView',
            'completed': 'completedView',
            'upcoming': 'upcomingView',
            'results': 'resultsView',
            'profile': 'profileView'
        };
        if (viewMap[view]) {
            document.getElementById(viewMap[view]).style.display = 'block';
            if (view === 'exams') renderMyExams();
            else if (view === 'completed') renderCompleted();
            else if (view === 'upcoming') renderUpcoming();
            else if (view === 'results') renderResults();
        } else {
            document.getElementById('dashboardView').style.display = 'block';
        }
    }

    function goBack() {
        navigateTo('dashboard');
    }

    // Fetch Exams
    async function fetchExams() {
        try {
            const formData = new URLSearchParams();
            formData.append('action', 'get_student_exams');
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (result.success && result.data) {
                myExams = result.data;
                updateDashboard();
                // Initialize all views
                renderMyExams();
                renderCompleted();
                renderUpcoming();
                renderResults();
                return myExams;
            }
            return [];
        } catch (error) {
            console.error('Error:', error);
            return [];
        }
    }

    function getExamUi(exam) {
        if (exam.status === 'active') {
            return {
                badge: '<span class="badge badge-info">Available Now</span>',
                action: `<button class="btn btn-primary" onclick="event.stopPropagation(); startExam(${exam.id})">Start Exam</button>`,
                score: '-'
            };
        }
        if (exam.status === 'upcoming') {
            return {
                badge: '<span class="badge badge-warning">Upcoming</span>',
                action: '<button class="btn btn-outline" disabled>Starts Soon</button>',
                score: '-'
            };
        }
        if (exam.status === 'completed') {
            const hasScore = exam.score !== null && exam.score !== undefined && exam.score !== '';
            return {
                badge: '<span class="badge badge-success">Completed</span>',
                action: `<button class="btn btn-outline" onclick="event.stopPropagation(); viewResult(${exam.id})">View Result</button>`,
                score: hasScore ? `<strong style="color: var(--success);">${exam.score}%</strong>` : '<span class="badge badge-warning">Pending</span>'
            };
        }
        if (exam.status === 'submitted') {
            return {
                badge: '<span class="badge badge-warning">Submitted</span>',
                action: '<button class="btn btn-outline" disabled>Awaiting Grade</button>',
                score: '<span class="badge badge-warning">Pending</span>'
            };
        }
        if (exam.status === 'expired') {
            return {
                badge: '<span class="badge badge-expired">Expired</span>',
                action: '<button class="btn btn-outline" disabled>Closed</button>',
                score: '-'
            };
        }
        return {
            badge: '<span class="badge badge-success">Available</span>',
            action: `<button class="btn btn-primary" onclick="event.stopPropagation(); startExam(${exam.id})">Start Exam</button>`,
            score: '-'
        };
    }

    function renderExamCard(exam) {
        const ui = getExamUi(exam);
        return `
            <div class="exam-card pro">
                <div class="exam-card-top">
                    <div>
                        <div class="exam-card-title">${escapeHtml(exam.name)}</div>
                        <div class="exam-card-course">${escapeHtml(exam.code)}</div>
                    </div>
                    ${ui.badge}
                </div>
                <div class="exam-meta-list">
                    <span><i class="fas fa-calendar"></i> ${escapeHtml(exam.date)}</span>
                    <span><i class="fas fa-clock"></i> ${escapeHtml(exam.duration)}</span>
                    <span><i class="fas fa-chart-line"></i> Score: ${ui.score}</span>
                </div>
                <div class="exam-actions">
                    ${ui.action}
                </div>
            </div>
        `;
    }

    function sortStudentExams(exams) {
        const order = { active: 0, upcoming: 1, available: 2, submitted: 3, completed: 4, expired: 5 };
        return [...exams].sort((a, b) => {
            const byStatus = (order[a.status] ?? 9) - (order[b.status] ?? 9);
            if (byStatus !== 0) return byStatus;
            return new Date(a.start_datetime || a.rawDate || 0) - new Date(b.start_datetime || b.rawDate || 0);
        });
    }

    function updateDashboard() {
        const total = myExams.length;
        const completed = myExams.filter(e => ['submitted', 'completed'].includes(e.status)).length;
        const upcoming = myExams.filter(e => e.status === 'upcoming' || e.status === 'active' || e.status === 'available').length;
        const results = myExams.filter(e => e.status === 'completed' && e.score !== null).length;

        document.getElementById('totalExams').innerText = total;
        document.getElementById('completedExams').innerText = completed;
        document.getElementById('upcomingExams').innerText = upcoming;
        document.getElementById('resultsCount').innerText = results;

        const dashboardExams = document.getElementById('dashboardExamsContainer');
        if (dashboardExams) {
            const homeExams = sortStudentExams(myExams)
                .filter(e => e.status === 'upcoming' || e.status === 'active' || e.status === 'available')
                .slice(0, 6);
            dashboardExams.innerHTML = homeExams.length
                ? homeExams.map(renderExamCard).join('')
                : '<div class="empty-state" style="grid-column:1/-1;"><i class="fas fa-calendar-times"></i><p>No upcoming or ongoing exams. Submitted exams are saved under the Submitted card.</p></div>';
        }

        const nextExam = sortStudentExams(myExams).find(e => e.status === 'upcoming' || e.status === 'active' || e.status === 'available');
        const nextPanel = document.getElementById('nextExamPanel');

        if (nextExam) {
            const now = new Date().getTime();
            const startTime = nextExam.start_datetime ? new Date(nextExam.start_datetime).getTime() : null;
            const endTime = nextExam.endDate ? new Date(nextExam.endDate).getTime() : (startTime ? startTime + (nextExam
                .durationMins * 60 * 1000) : null);

            let timerHtml = '';
            if (nextExam.status === 'upcoming' && startTime) {
                const timeToStart = startTime - now;
                timerHtml = `<span class="timer">Starts in: ${formatTime(timeToStart)}</span>`;
            } else if (nextExam.status === 'active' && endTime) {
                const timeLeft = endTime - now;
                timerHtml =
                    `<span class="timer ${timeLeft < 300000 ? 'urgent' : ''}">Time left: ${formatTime(timeLeft)}</span>`;
            }

            nextPanel.innerHTML = `
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                        <div>
                            <div style="font-weight: 700; font-size: 18px; color: var(--accent);">${escapeHtml(nextExam.name)}</div>
                            <div style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                                <i class="fas fa-calendar"></i> ${nextExam.date} | ${nextExam.duration}
                            </div>
                            ${timerHtml}
                        </div>
                        <button class="btn btn-primary" onclick="startExam(${nextExam.id})">View Details →</button>
                    </div>
                `;
        } else {
            nextPanel.innerHTML =
                '<div class="empty-state"><i class="fas fa-calendar-times"></i><p>No upcoming exams</p></div>';
        }
    }

    function renderMyExams() {
        const container = document.getElementById('myExamsContainer');

        // Get filter values
        const searchTerm = document.getElementById('searchMyExams')?.value.toLowerCase() || '';
        const statusFilter = document.getElementById('filterMyExamsStatus')?.value || 'all';

        // Filter exams
        let filtered = [...myExams];

        if (searchTerm) {
            filtered = filtered.filter(e =>
                e.name.toLowerCase().includes(searchTerm) ||
                e.code.toLowerCase().includes(searchTerm)
            );
        }
        if (statusFilter !== 'all') {
            filtered = filtered.filter(e => e.status === statusFilter);
        }

        if (filtered.length === 0) {
            container.innerHTML =
                '<div class="empty-state"><i class="fas fa-file-alt"></i><p>No exams found matching your criteria</p></div>';
            return;
        }

        container.innerHTML = `<div class="exam-grid" style="padding:20px;">${sortStudentExams(filtered).map(renderExamCard).join('')}</div>`;
    }

    function filterMyExams() {
        renderMyExams();
    }

    function resetMyExamsFilter() {
        document.getElementById('searchMyExams').value = '';
        document.getElementById('filterMyExamsStatus').value = 'all';
        renderMyExams();
    }

    function renderCompleted() {
        const container = document.getElementById('completedContainer');
        let completed = myExams.filter(e => ['submitted', 'completed'].includes(e.status));

        const searchTerm = document.getElementById('searchCompleted')?.value.toLowerCase() || '';
        const groupBy = document.getElementById('groupCompletedBy')?.value || 'none';

        if (searchTerm) {
            completed = completed.filter(e =>
                e.name.toLowerCase().includes(searchTerm) ||
                e.code.toLowerCase().includes(searchTerm)
            );
        }

        if (completed.length === 0) {
            container.innerHTML =
                '<div class="empty-state"><i class="fas fa-check-circle"></i><p>No submitted exams found</p></div>';
            return;
        }

        container.innerHTML = `<div class="exam-grid" style="padding:20px;">${sortStudentExams(completed).map(renderExamCard).join('')}</div>`;
        return;

        if (groupBy === 'course') {
            const grouped = {};
            completed.forEach(e => {
                if (!grouped[e.code]) grouped[e.code] = [];
                grouped[e.code].push(e);
            });
            let html = '';
            const sortedCourses = Object.keys(grouped).sort();
            for (let course of sortedCourses) {
                const exams = grouped[course];
                html +=
                    `<h4 style="margin: 15px 0 10px; color: var(--accent); padding-left: 10px;">📚 ${escapeHtml(course)} (${exams.length} exams)</h4>`;
                html +=
                    '<table class="data-table"><thead><tr><th>Exam</th><th>Date</th><th>Score</th><th>Grade</th><th>Action</th></tr></thead><tbody>';
                exams.forEach(e => {
                    const grade = e.score >= 80 ? 'A' : e.score >= 70 ? 'B' : e.score >= 60 ? 'C' : e.score >=
                        50 ? 'D' : 'F';
                    html += `<tr>
                            <td><strong>${escapeHtml(e.name)}</strong></td>
                            <td>${escapeHtml(e.date)}</span></td>
                            <td><strong style="color: var(--success);">${e.score}%</strong></span></td>
                            <td><span class="badge badge-info">${grade}</span></td>
                            <td><button class="btn btn-outline" onclick="viewResult(${e.id})">Details</button></td>
                        </tr>`;
                });
                html += '</tbody></table>';
            }
            container.innerHTML = html;
        } else {
            let html =
                '<table class="data-table"><thead><tr><th>Exam</th><th>Course</th><th>Date</th><th>Score</th><th>Grade</th><th>Action</th></tr></thead><tbody>';
            completed.forEach(e => {
                const grade = e.score >= 80 ? 'A' : e.score >= 70 ? 'B' : e.score >= 60 ? 'C' : e.score >= 50 ?
                    'D' : 'F';
                html += `<tr>
                        <td><strong>${escapeHtml(e.name)}</strong></td>
                        <td>${escapeHtml(e.code)}</span></td>
                        <td>${escapeHtml(e.date)}</span></td>
                        <td><strong style="color: var(--success);">${e.score}%</strong></span></td>
                        <td><span class="badge badge-info">${grade}</span></td>
                        <td><button class="btn btn-outline" onclick="viewResult(${e.id})">Details</button></td>
                    </tr>`;
            });
            html += '</tbody></table>';
            container.innerHTML = html;
        }
    }

    function filterCompleted() {
        renderCompleted();
    }

    function renderUpcoming() {
        const container = document.getElementById('upcomingContainer');
        let upcoming = myExams.filter(e => e.status === 'upcoming' || e.status === 'active' || e.status === 'available');

        const searchTerm = document.getElementById('searchUpcoming')?.value.toLowerCase() || '';

        if (searchTerm) {
            upcoming = upcoming.filter(e =>
                e.name.toLowerCase().includes(searchTerm) ||
                e.code.toLowerCase().includes(searchTerm)
            );
        }

        if (upcoming.length === 0) {
            container.innerHTML =
                '<div class="empty-state"><i class="fas fa-calendar-alt"></i><p>No upcoming exams found</p></div>';
            return;
        }

        let html = '';
        upcoming.forEach(exam => {
            const now = new Date().getTime();
            const startTime = exam.start_datetime ? new Date(exam.start_datetime).getTime() : null;
            const endTime = exam.endDate ? new Date(exam.endDate).getTime() : (startTime ? startTime + (exam
                .durationMins * 60 * 1000) : null);

            let statusText = '',
                timerHtml = '';
            if (exam.status === 'active') {
                const timeLeft = endTime - now;
                statusText = '<span class="badge badge-info">Available Now</span>';
                timerHtml =
                    `<span class="timer ${timeLeft < 300000 ? 'urgent' : ''}" data-timer-id="${exam.id}">${formatTime(timeLeft)}</span>`;
            } else if (exam.status === 'upcoming') {
                const timeToStart = startTime - now;
                statusText = '<span class="badge badge-warning">Upcoming</span>';
                timerHtml =
                    `<span class="timer" data-timer-id="${exam.id}">Starts in: ${formatTime(timeToStart)}</span>`;
            } else {
                statusText = '<span class="badge badge-success">Available</span>';
                timerHtml = '<span class="timer">Open now</span>';
            }

            html += `
                    <div class="exam-card" onclick="startExam(${exam.id})">
                        <div class="exam-card-header">
                            <div class="exam-title">${escapeHtml(exam.name)}</div>
                            ${statusText}
                        </div>
                        <div class="exam-details">
                            <span><i class="fas fa-code"></i> ${escapeHtml(exam.code)}</span>
                            <span><i class="fas fa-calendar"></i> ${escapeHtml(exam.date)}</span>
                            <span><i class="fas fa-clock"></i> ${escapeHtml(exam.duration)}</span>
                            ${timerHtml}
                        </div>
                    </div>
                `;
        });
        container.innerHTML = html;
    }

    function filterUpcoming() {
        renderUpcoming();
    }

    function renderResults() {
        const container = document.getElementById('resultsContainer');
        let results = myExams.filter(e => e.status === 'completed' && e.score !== null);

        const courses = [...new Map(results.map(e => [e.code, `${e.name} (${e.code})`])).entries()].sort((a, b) => a[0].localeCompare(b[0]));
        const courseSelect = document.getElementById('filterResultsCourse');
        if (courseSelect) {
            const selectedCourse = courseSelect.value || 'all';
            courseSelect.innerHTML = '<option value="all">All Courses</option>' + courses.map(([code, label]) =>
                `<option value="${escapeHtml(code)}">${escapeHtml(label)}</option>`).join('');
            courseSelect.value = courses.some(([code]) => code === selectedCourse) ? selectedCourse : 'all';
        }

        const searchTerm = document.getElementById('searchResults')?.value.toLowerCase() || '';
        const courseFilter = document.getElementById('filterResultsCourse')?.value || 'all';
        const dateFilter = document.getElementById('filterResultsDate')?.value;
        const timeframe = document.getElementById('filterResultsTimeframe')?.value || 'all';

        if (searchTerm) {
            results = results.filter(e =>
                e.name.toLowerCase().includes(searchTerm) ||
                e.code.toLowerCase().includes(searchTerm)
            );
        }
        if (courseFilter !== 'all') {
            results = results.filter(e => e.code === courseFilter);
        }
        if (dateFilter) {
            results = results.filter(e => {
                const sourceDate = e.submitted_at || e.rawDate || e.start_datetime;
                if (!sourceDate) return false;
                const d = new Date(sourceDate);
                if (Number.isNaN(d.getTime())) return false;
                const month = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
                return month === dateFilter;
            });
        }
        if (timeframe !== 'all') {
            const now = new Date();
            results = results.filter(e => {
                const sourceDate = e.submitted_at || e.rawDate || e.start_datetime;
                if (!sourceDate) return false;
                const d = new Date(sourceDate);
                if (Number.isNaN(d.getTime())) return false;
                if (timeframe === 'today') return d.toDateString() === now.toDateString();
                if (timeframe === 'week') return d >= new Date(now.getTime() - 7 * 86400000);
                if (timeframe === 'month') return d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear();
                if (timeframe === 'year') return d.getFullYear() === now.getFullYear();
                return true;
            });
        }

        if (results.length === 0) {
            container.innerHTML =
                '<div class="empty-state"><i class="fas fa-chart-bar"></i><p>No results found matching your criteria</p></div>';
            return;
        }

        let totalScore = 0;
        results.forEach(r => totalScore += r.score);
        const averageScore = results.length > 0 ? (totalScore / results.length).toFixed(1) : 0;

        let html = `
                <div class="result-header">
                    <h2>Qoda University</h2>
                    <p>Student: <?php echo htmlspecialchars($student->name); ?> (<?php echo htmlspecialchars($student->matric_number); ?>)</p>
                    <p>Programme: <?php echo htmlspecialchars($student->program); ?> | Level: <?php echo htmlspecialchars($student->level); ?></p>
                    <p><strong>Average Score: ${averageScore}%</strong> | Total Results: ${results.length}</p>
                </div>
                <table class="data-table" id="resultsTable">
                    <thead><tr><th>Exam</th><th>Course</th><th>Date</th><th>Class Score (40)</th><th>Exam Score (60)</th><th>Total (100)</th><th>Grade</th><th>GP</th><th>Action</th></tr></thead>
                    <tbody>
            `;
        results.forEach(e => {
            const grade = e.grade || (e.score >= 80 ? 'A' : e.score >= 75 ? 'B+' : e.score >= 70 ? 'B' : e.score >= 65 ?
                'C+' : e.score >= 60 ? 'C' : e.score >= 55 ? 'D+' : e.score >= 50 ? 'D' : 'E');
            const gp = e.grade_point !== null && e.grade_point !== undefined ? Number(e.grade_point).toFixed(1) : (e.score >= 80 ? '4.0' : e.score >= 75 ? '3.5' : e.score >= 70 ? '3.0' : e.score >= 65 ?
                '2.5' : e.score >= 60 ? '2.0' : e.score >= 55 ? '1.5' : e.score >= 50 ? '1.0' : '0.0');
            html += `<tr>
                    <td><strong>${escapeHtml(e.name)}</strong></td>
                    <td>${escapeHtml(e.code)}</span></td>
                    <td>${escapeHtml(e.date)}</span></td>
                    <td>${e.class_score ?? '-'}</span></td>
                    <td>${e.exam_score ?? '-'}</span></td>
                    <td><strong style="color: var(--success);">${e.score}</strong></span></td>
                    <td><span class="badge badge-info">${grade}</span></span></td>
                    <td>${gp}</span></td>
                    <td><button class="btn btn-outline" onclick="viewResult(${e.id})">Details</button></span></td>
                </tr>`;
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    }

    function filterResults() {
        renderResults();
    }

    function resetResultsFilter() {
        document.getElementById('searchResults').value = '';
        document.getElementById('filterResultsCourse').value = 'all';
        const timeframe = document.getElementById('filterResultsTimeframe');
        if (timeframe) timeframe.value = 'all';
        document.getElementById('filterResultsDate').value = '';
        renderResults();
    }

    function printResults() {
        const printContent = document.getElementById('resultsContainer').innerHTML;
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
                <html><head><title>Exam Results - <?php echo htmlspecialchars($student->name); ?></title>
                <style>
                    body{font-family:Arial;padding:20px}
                    table{width:100%;border-collapse:collapse;margin-top:20px}
                    th,td{border:1px solid #ddd;padding:8px;text-align:left}
                    th{background:#f0f0f0}
                    .result-header{text-align:center;margin-bottom:20px}
                </style>
                </head><body>${printContent}</body></html>
            `);
        printWindow.document.close();
        printWindow.print();
    }

    function downloadResultsPDF() {
        const element = document.getElementById('resultsContainer');
        html2pdf().from(element).set({
            margin: 1,
            filename: 'exam_results_<?php echo htmlspecialchars($student->matric_number); ?>.pdf',
            html2canvas: {
                scale: 2
            },
            jsPDF: {
                unit: 'in',
                format: 'letter',
                orientation: 'portrait'
            }
        }).save();
    }

    function viewResult(examId) {
        const exam = myExams.find(e => e.id === examId);
        if (!exam || exam.score === null || exam.score === undefined || exam.score === '') {
            toast('Result not available');
            return;
        }

        const grade = exam.score >= 80 ? 'A' : exam.score >= 75 ? 'B+' : exam.score >= 70 ? 'B' : exam.score >= 65 ?
            'C+' : exam.score >= 60 ? 'C' : exam.score >= 55 ? 'D+' : exam.score >= 50 ? 'D' : 'E';
        const finalGrade = exam.grade || grade;
        const gp = exam.grade_point !== null && exam.grade_point !== undefined ? Number(exam.grade_point).toFixed(1) : (exam.score >= 80 ? '4.0' : exam.score >= 75 ? '3.5' : exam.score >= 70 ? '3.0' : exam.score >= 65 ?
            '2.5' : exam.score >= 60 ? '2.0' : exam.score >= 55 ? '1.5' : exam.score >= 50 ? '1.0' : '0.0');

        document.getElementById('resultModalTitle').innerHTML = exam.name;
        document.getElementById('resultModalBody').innerHTML = `
                <div class="result-header">
                    <h2>Qoda University</h2>
                    <p>Student: <?php echo htmlspecialchars($student->name); ?> (<?php echo htmlspecialchars($student->matric_number); ?>)</p>
                    <p>Programme: <?php echo htmlspecialchars($student->program); ?> | Level: <?php echo htmlspecialchars($student->level); ?></p>
                </div>
                <div style="background: var(--bg); border-radius: 12px; padding: 20px; text-align: center; margin-bottom: 15px;">
                    <div style="font-size: 48px; font-weight: 700; color: var(--success);">${exam.score}%</div>
                    <div style="font-size: 24px; font-weight: 600; margin-top: 5px;">Grade: ${finalGrade}</div>
                    <div style="font-size: 16px; color: var(--text-muted);">Grade Point: ${gp}</div>
                </div>
                <div style="background: var(--bg); border-radius: 12px; padding: 15px;">
                    <div style="display: grid; gap: 10px;">
                        <div><strong>Exam Name:</strong> ${escapeHtml(exam.name)}</div>
                        <div><strong>Course Code:</strong> ${escapeHtml(exam.code)}</div>
                        <div><strong>Class Score:</strong> ${exam.class_score ?? '-'} / 40</div>
                        <div><strong>Exam Score:</strong> ${exam.exam_score ?? '-'} / 60</div>
                        <div><strong>Total Score:</strong> ${exam.score} / 100</div>
                        <div><strong>Date Taken:</strong> ${escapeHtml(exam.date)}</div>
                        <div><strong>Duration:</strong> ${escapeHtml(exam.duration)}</div>
                        <div><strong>Status:</strong> <span class="badge badge-success">Completed</span></div>
                    </div>
                </div>
            `;
        document.getElementById('resultModal').style.display = 'flex';
    }

    function closeResultModal() {
        document.getElementById('resultModal').style.display = 'none';
    }

    function showEnrolledCourses() {
        const modal = document.getElementById('coursesModal');
        const body = document.getElementById('coursesModalBody');
        body.innerHTML = '<div class="spinner"></div>';
        modal.style.display = 'flex';

        fetch(window.location.href, {
                method: 'POST',
                body: 'action=get_enrolled_courses_details',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                }
            })
            .then(r => r.json()).then(data => {
                if (data.success && data.data && data.data.length) {
                    let html =
                        '<table class="data-table" style="width:100%"><thead><tr><th>#</th><th>Course Code</th><th>Course Name</th><th>Lecturer</th><th>Enrolled Date</th></tr></thead><tbody>';
                    data.data.forEach((c, index) => {
                        html += `<tr>
                                <td>${index + 1}</span></td>
                                <td><strong>${escapeHtml(c.course_code)}</strong></span></td>
                                <td>${escapeHtml(c.course_name)}</span></td>
                                <td>${escapeHtml(c.lecturer_name || 'Not assigned')}</span></td>
                                <td>${new Date(c.enrolled_at).toLocaleDateString()}</span></td>
                            </tr>`;
                    });
                    html += '</tbody><tr>';
                    body.innerHTML = html;
                } else {
                    body.innerHTML =
                        '<div class="empty-state"><i class="fas fa-book-open"></i><p>No courses enrolled</p></div>';
                }
            }).catch(() => {
                body.innerHTML =
                    '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading courses</p></div>';
            });
    }

    function closeCoursesModal() {
        document.getElementById('coursesModal').style.display = 'none';
    }

    async function startExam(examId) {
        if (examId) {
            const exam = myExams.find(e => parseInt(e.id) === parseInt(examId));
            const approved = await confirmPopup(
                `Open ${exam ? exam.name : 'this exam'}?\nMake sure you are ready before continuing.`,
                'Open exam',
                'Continue'
            );
            if (!approved) return;
            const url = `exam_landing.php?exam_id=${examId}&app_window=1`;
            const features = [
                'popup=yes',
                `width=${screen.availWidth || window.innerWidth}`,
                `height=${screen.availHeight || window.innerHeight}`,
                'left=0',
                'top=0',
                'toolbar=no',
                'menubar=no',
                'location=no',
                'status=no',
                'scrollbars=yes',
                'resizable=no'
            ].join(',');
            const examWindow = window.open(url, `qoda_exam_${examId}`, features);
            if (examWindow) {
                examWindow.focus();
            } else {
                window.location.href = url;
            }
        }
    }

    function formatTime(ms) {
        if (ms <= 0) return "00:00";
        const seconds = Math.floor(ms / 1000),
            minutes = Math.floor(seconds / 60),
            hours = Math.floor(minutes / 60);
        const remainingMinutes = minutes % 60,
            remainingSeconds = seconds % 60;
        return hours > 0 ? `${hours}h ${remainingMinutes}m ${remainingSeconds}s` :
            `${remainingMinutes.toString().padStart(2,'0')}:${remainingSeconds.toString().padStart(2,'0')}`;
    }

    function updateTimers() {
        const now = new Date().getTime();
        document.querySelectorAll('[data-timer-id]').forEach(el => {
            const examId = parseInt(el.dataset.timerId);
            const exam = myExams.find(e => e.id === examId);
            if (exam) {
                const startTime = exam.start_datetime ? new Date(exam.start_datetime).getTime() : null;
                const endTime = exam.endDate ? new Date(exam.endDate).getTime() : (startTime ? startTime + (exam
                    .durationMins * 60 * 1000) : null);
                let timeMs = 0,
                    timerClass = 'timer';
                if (exam.status === 'upcoming' && startTime) timeMs = startTime - now;
                else if (exam.status === 'active' && endTime) {
                    timeMs = endTime - now;
                    if (timeMs < 300000) timerClass = 'timer urgent';
                }
                if (timeMs > 0) {
                    el.textContent = formatTime(timeMs);
                    el.className = timerClass;
                }
            }
        });
    }

    async function changePassword() {
        const current = document.getElementById('currentPassword').value;
        const newPass = document.getElementById('newPassword').value;
        if (!current || !newPass) {
            toast('Please fill all fields');
            return;
        }
        if (newPass.length < 6) {
            toast('Password must be at least 6 characters');
            return;
        }

        if (!(await confirmPopup('Change your account password now?', 'Update password', 'Update'))) return;

        const formData = new URLSearchParams();
        formData.append('action', 'change_student_password');
        formData.append('current_password', current);
        formData.append('new_password', newPass);

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (result.success) {
                toast('Password changed successfully');
                document.getElementById('currentPassword').value = '';
                document.getElementById('newPassword').value = '';
            } else {
                toast(result.error || 'Failed to change password');
            }
        } catch (error) {
            toast('Network error');
        }
    }

    function uploadProfilePic(input) {
        if (input.files && input.files[0]) {
            const formData = new FormData();
            formData.append('action', 'upload_profile_pic');
            formData.append('profile_pic', input.files[0]);
            fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json()).then(data => {
                    if (data.success) {
                        document.getElementById('profileImage').src = data.url + '?t=' + new Date().getTime();
                        toast('Profile picture updated');
                    } else {
                        toast('Upload failed');
                    }
                }).catch(() => toast('Upload failed'));
        }
    }

    function toast(msg) {
        const t = document.getElementById('toast');
        t.textContent = msg;
        t.style.opacity = '1';
        setTimeout(() => t.style.opacity = '0', 3000);
    }

    function confirmPopup(message, title = 'Confirm action', confirmText = 'Continue') {
        return new Promise(resolve => {
            const old = document.getElementById('studentConfirmPopup');
            if (old) old.remove();
            const modal = document.createElement('div');
            modal.id = 'studentConfirmPopup';
            modal.className = 'modal';
            modal.style.display = 'flex';
            modal.innerHTML = `
                <div class="modal-content" style="max-width:430px;text-align:center;">
                    <div style="width:54px;height:54px;border-radius:16px;background:rgba(59,130,246,.16);color:var(--accent);display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:24px;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3 style="margin-bottom:10px;">${escapeHtml(title)}</h3>
                    <p style="white-space:pre-line;color:var(--text-muted);line-height:1.5;margin-bottom:22px;">${escapeHtml(message)}</p>
                    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                        <button class="btn btn-outline" data-confirm="cancel">Cancel</button>
                        <button class="btn btn-primary" data-confirm="ok">${escapeHtml(confirmText)}</button>
                    </div>
                </div>
            `;
            modal.querySelector('[data-confirm="cancel"]').onclick = () => {
                modal.remove();
                resolve(false);
            };
            modal.querySelector('[data-confirm="ok"]').onclick = () => {
                modal.remove();
                resolve(true);
            };
            document.body.appendChild(modal);
        });
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text.replace(/[&<>]/g, m => m === '&' ? '&amp;' : m === '<' ? '&lt;' : '&gt;');
    }

    async function logout() {
        if (await confirmPopup('Logout from your student portal?', 'Logout', 'Logout')) window.location.href = 'logout.php';
    }

    async function init() {
        await fetchExams();
        if (timerInterval) clearInterval(timerInterval);
        timerInterval = setInterval(updateTimers, 1000);
        setInterval(fetchExams, 10000);
        navigateTo('dashboard');
    }

    init();
    window.onclick = function(event) {
        if (event.target === document.getElementById('coursesModal')) closeCoursesModal();
        if (event.target === document.getElementById('resultModal')) closeResultModal();
    }
    </script>
</body>

</html>
