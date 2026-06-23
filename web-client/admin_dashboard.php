<?php
// ========== admin_dashboard.php ==========
// Super Admin Dashboard with Full System Control

session_start();

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'ADMIN') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../backend-php/config/database.php';
require_once __DIR__ . '/../backend-php/config/response.php';
require_once __DIR__ . '/../backend-php/helpers/functions.php';

global $pdo;
$db = $pdo;
$adminId = $_SESSION['user_id'];

// Get admin details
$stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND role = 'ADMIN'");
$stmt->execute([$adminId]);
$adminData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$adminData) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Create necessary tables if not exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS suspicious_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        exam_id INT NOT NULL,
        session_id VARCHAR(255),
        event_type VARCHAR(50),
        details TEXT,
        severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
        resolved BOOLEAN DEFAULT FALSE,
        resolved_by INT NULL,
        resolved_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch(Exception $e) {}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        actor_id INT NOT NULL,
        actor_role VARCHAR(50),
        action VARCHAR(100),
        target_type VARCHAR(50),
        target_id INT,
        description TEXT,
        ip_address VARCHAR(45),
        user_agent TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch(Exception $e) {}

// ========== API ENDPOINTS ==========
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            
            // ========== DASHBOARD STATS ==========
            case 'get_dashboard_stats':
                $totalStudents = $db->query("SELECT COUNT(*) FROM students")->fetchColumn();
                $totalLecturers = $db->query("SELECT COUNT(*) FROM users WHERE role = 'LECTURER'")->fetchColumn();
                $totalExams = $db->query("SELECT COUNT(*) FROM exams")->fetchColumn();
                $totalSubmissions = $db->query("SELECT COUNT(*) FROM exam_submissions")->fetchColumn();
                $activeExams = $db->query("SELECT COUNT(*) FROM exams WHERE published = 1")->fetchColumn();
                $pendingSubmissions = $db->query("SELECT COUNT(*) FROM exam_submissions WHERE status != 'MARKED'")->fetchColumn();
                $suspiciousCount = $db->query("SELECT COUNT(*) FROM suspicious_logs WHERE resolved = 0")->fetchColumn();
                
                // Monthly data for charts
                $months = [];
                $studentsData = [];
                $examsData = [];
                for ($i = 5; $i >= 0; $i--) {
                    $months[] = date('M', strtotime("-$i months"));
                    $startDate = date('Y-m-01', strtotime("-$i months"));
                    $endDate = date('Y-m-t', strtotime("-$i months"));
                    $stmt = $db->prepare("SELECT COUNT(*) FROM students WHERE created_at BETWEEN ? AND ?");
                    $stmt->execute([$startDate, $endDate]);
                    $studentsData[] = (int)$stmt->fetchColumn();
                    $stmt = $db->prepare("SELECT COUNT(*) FROM exams WHERE created_at BETWEEN ? AND ?");
                    $stmt->execute([$startDate, $endDate]);
                    $examsData[] = (int)$stmt->fetchColumn();
                }
                
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'students' => $totalStudents,
                        'lecturers' => $totalLecturers,
                        'exams' => $totalExams,
                        'submissions' => $totalSubmissions,
                        'active_exams' => $activeExams,
                        'pending_submissions' => $pendingSubmissions,
                        'suspicious' => $suspiciousCount,
                        'months' => $months,
                        'students_growth' => $studentsData,
                        'exams_growth' => $examsData
                    ]
                ]);
                break;
            
            // ========== LECTURER MANAGEMENT ==========
            case 'get_lecturers':
                $stmt = $db->prepare("
                    SELECT u.*, COUNT(DISTINCT e.id) as exam_count, COUNT(DISTINCT es.id) as submission_count
                    FROM users u
                    LEFT JOIN exams e ON e.lecturer_id = u.id OR e.created_by = u.id
                    LEFT JOIN exam_submissions es ON es.exam_id = e.id
                    WHERE u.role = 'LECTURER'
                    GROUP BY u.id
                    ORDER BY u.created_at DESC
                ");
                $stmt->execute();
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                break;
            
            case 'create_lecturer':
                $userId = trim($_POST['user_id']);
                $fullName = trim($_POST['full_name']);
                $email = trim($_POST['email']);
                $department = trim($_POST['department'] ?? '');
                $faculty = trim($_POST['faculty'] ?? '');
                $levelsTaught = trim($_POST['levels_taught'] ?? '');
                $courses = trim($_POST['courses'] ?? '');
                $status = $_POST['status'] ?? 'Active';
                
                // Check existing
                $check = $db->prepare("SELECT id FROM users WHERE user_id = ? OR email = ?");
                $check->execute([$userId, $email]);
                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'error' => 'User ID or Email already exists']);
                    break;
                }
                
                $password = password_hash($userId, PASSWORD_DEFAULT);
                
                // Handle profile picture
                $profilePic = null;
                if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] == 0) {
                    $uploadDir = __DIR__ . '/../uploads/lecturer_profiles/';
                    if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
                    $ext = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                        $filename = 'lecturer_' . $userId . '_' . time() . '.' . $ext;
                        if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $uploadDir . $filename)) {
                            $profilePic = '../uploads/lecturer_profiles/' . $filename;
                        }
                    }
                }
                
                $stmt = $db->prepare("
                    INSERT INTO users (user_id, password, email, full_name, staff_id, department, faculty, levels_taught, courses, profile_pic, status, role, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'LECTURER', NOW())
                ");
                $stmt->execute([$userId, $password, $email, $fullName, $userId, $department, $faculty, $levelsTaught, $courses, $profilePic, $status]);
                $newId = $db->lastInsertId();
                
                // Log action
                $log = $db->prepare("INSERT INTO audit_logs (actor_id, actor_role, action, target_type, target_id, description, ip_address) VALUES (?, 'ADMIN', 'CREATE_LECTURER', 'user', ?, ?, ?)");
                $log->execute([$adminId, $newId, "Created lecturer: $fullName (ID: $userId)", $_SERVER['REMOTE_ADDR'] ?? '']);
                
                echo json_encode(['success' => true, 'message' => 'Lecturer created. Default password: ' . $userId]);
                break;
            
            case 'update_lecturer':
                $id = $_POST['lecturer_id'];
                $stmt = $db->prepare("
                    UPDATE users SET full_name = ?, email = ?, department = ?, faculty = ?, 
                    levels_taught = ?, courses = ?, status = ? WHERE id = ? AND role = 'LECTURER'
                ");
                $stmt->execute([
                    $_POST['full_name'], $_POST['email'], $_POST['department'], $_POST['faculty'],
                    $_POST['levels_taught'], $_POST['courses'], $_POST['status'], $id
                ]);
                echo json_encode(['success' => true]);
                break;
            
            case 'delete_lecturer':
                $stmt = $db->prepare("DELETE FROM users WHERE id = ? AND role = 'LECTURER'");
                $stmt->execute([$_POST['lecturer_id']]);
                echo json_encode(['success' => true]);
                break;
            
            case 'reset_lecturer_password':
                $stmt = $db->prepare("SELECT user_id FROM users WHERE id = ?");
                $stmt->execute([$_POST['lecturer_id']]);
                $user = $stmt->fetch();
                $newPass = $user['user_id'];
                $hashed = password_hash($newPass, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed, $_POST['lecturer_id']]);
                echo json_encode(['success' => true, 'new_password' => $newPass]);
                break;
            
            // ========== STUDENT MANAGEMENT ==========
            case 'get_students':
                $stmt = $db->prepare("
                    SELECT s.*, COUNT(DISTINCT ce.course_code) as enrolled_courses, COUNT(DISTINCT es.id) as exams_taken
                    FROM students s
                    LEFT JOIN course_enrollments ce ON s.id = ce.student_id
                    LEFT JOIN exam_submissions es ON s.id = es.student_id
                    GROUP BY s.id
                    ORDER BY s.created_at DESC
                ");
                $stmt->execute();
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                break;
            
            case 'create_student':
                $studentId = trim($_POST['student_id']);
                $fullName = trim($_POST['full_name']);
                $email = trim($_POST['email']);
                $level = $_POST['level'];
                $programme = $_POST['programme'];
                $status = $_POST['status'];
                
                $check = $db->prepare("SELECT id FROM students WHERE student_id = ?");
                $check->execute([$studentId]);
                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'error' => 'Student ID already exists']);
                    break;
                }
                
                $password = password_hash($studentId, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO students (student_id, full_name, email, level, programme, status, password, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$studentId, $fullName, $email, $level, $programme, $status, $password]);
                
                // Enroll in course if provided
                if (!empty($_POST['course_code']) && !empty($_POST['course_name'])) {
                    $newId = $db->lastInsertId();
                    $enroll = $db->prepare("INSERT INTO course_enrollments (course_code, course_name, student_id, lecturer_id) VALUES (?, ?, ?, (SELECT id FROM users WHERE role = 'ADMIN' LIMIT 1))");
                    $enroll->execute([$_POST['course_code'], $_POST['course_name'], $newId]);
                }
                
                echo json_encode(['success' => true, 'message' => 'Student created. Default password: ' . $studentId]);
                break;
            
            case 'update_student':
                $stmt = $db->prepare("UPDATE students SET student_id = ?, full_name = ?, email = ?, level = ?, programme = ?, status = ? WHERE id = ?");
                $stmt->execute([$_POST['student_id'], $_POST['full_name'], $_POST['email'], $_POST['level'], $_POST['programme'], $_POST['status'], $_POST['student_db_id']]);
                echo json_encode(['success' => true]);
                break;
            
            case 'delete_student':
                $stmt = $db->prepare("DELETE FROM students WHERE id = ?");
                $stmt->execute([$_POST['student_id']]);
                echo json_encode(['success' => true]);
                break;
            
            case 'reset_student_password':
                $stmt = $db->prepare("SELECT student_id FROM students WHERE id = ?");
                $stmt->execute([$_POST['student_id']]);
                $student = $stmt->fetch();
                $newPass = $student['student_id'];
                $hashed = password_hash($newPass, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE students SET password = ? WHERE id = ?");
                $stmt->execute([$hashed, $_POST['student_id']]);
                echo json_encode(['success' => true, 'new_password' => $newPass]);
                break;
            
            // ========== EXAM MANAGEMENT ==========
            case 'get_exams':
                $stmt = $db->prepare("
                    SELECT e.*, u.full_name as lecturer_name, 
                           COUNT(DISTINCT es.id) as submission_count,
                           AVG(es.percentage) as avg_score
                    FROM exams e
                    LEFT JOIN users u ON e.lecturer_id = u.id
                    LEFT JOIN exam_submissions es ON e.id = es.exam_id
                    GROUP BY e.id
                    ORDER BY e.created_at DESC
                ");
                $stmt->execute();
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                break;
            
            case 'delete_exam':
                $stmt = $db->prepare("DELETE FROM exams WHERE id = ?");
                $stmt->execute([$_POST['exam_id']]);
                echo json_encode(['success' => true]);
                break;
            
            case 'toggle_exam_status':
                $stmt = $db->prepare("UPDATE exams SET published = NOT published WHERE id = ?");
                $stmt->execute([$_POST['exam_id']]);
                echo json_encode(['success' => true]);
                break;
            
            // ========== SUBMISSIONS ==========
            case 'get_submissions':
                $stmt = $db->prepare("
                    SELECT es.*, s.full_name as student_name, s.student_id as student_identifier,
                           e.title as exam_title, e.course_code, u.full_name as lecturer_name
                    FROM exam_submissions es
                    JOIN students s ON es.student_id = s.id
                    JOIN exams e ON es.exam_id = e.id
                    LEFT JOIN users u ON e.lecturer_id = u.id
                    ORDER BY es.submitted_at DESC
                    LIMIT 200
                ");
                $stmt->execute();
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                break;
            
            // ========== PROCTORING / SUSPICIOUS LOGS ==========
            case 'get_suspicious_logs':
                $stmt = $db->prepare("
                    SELECT sl.*, s.full_name as student_name, s.student_id, e.title as exam_title
                    FROM suspicious_logs sl
                    JOIN students s ON sl.student_id = s.id
                    JOIN exams e ON sl.exam_id = e.id
                    ORDER BY sl.created_at DESC
                    LIMIT 100
                ");
                $stmt->execute();
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                break;
            
            case 'resolve_suspicious':
                $stmt = $db->prepare("UPDATE suspicious_logs SET resolved = 1, resolved_by = ?, resolved_at = NOW() WHERE id = ?");
                $stmt->execute([$adminId, $_POST['log_id']]);
                echo json_encode(['success' => true]);
                break;
            
            case 'add_suspicious_log':
                $stmt = $db->prepare("INSERT INTO suspicious_logs (student_id, exam_id, event_type, details, severity) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$_POST['student_id'], $_POST['exam_id'], $_POST['event_type'], $_POST['details'], $_POST['severity']]);
                echo json_encode(['success' => true]);
                break;
            
            // ========== AUDIT LOGS ==========
            case 'get_audit_logs':
                $stmt = $db->prepare("
                    SELECT al.*, u.full_name as actor_name
                    FROM audit_logs al
                    LEFT JOIN users u ON al.actor_id = u.id
                    ORDER BY al.created_at DESC
                    LIMIT 200
                ");
                $stmt->execute();
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                break;
            
            // ========== ADMIN PROFILE ==========
            case 'update_admin_profile':
                $stmt = $db->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ? AND role = 'ADMIN'");
                $stmt->execute([$_POST['full_name'], $_POST['email'], $adminId]);
                echo json_encode(['success' => true]);
                break;
            
            case 'change_admin_password':
                $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$adminId]);
                $admin = $stmt->fetch();
                if (!password_verify($_POST['current_password'], $admin['password'])) {
                    echo json_encode(['success' => false, 'error' => 'Current password is incorrect']);
                    break;
                }
                $hashed = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed, $adminId]);
                echo json_encode(['success' => true]);
                break;
            
            case 'update_profile_picture':
                if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
                    $uploadDir = __DIR__ . '/../uploads/profile_pictures/';
                    if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
                    $ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                        $filename = 'admin_' . $adminId . '_' . time() . '.' . $ext;
                        if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $uploadDir . $filename)) {
                            $stmt = $db->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
                            $stmt->execute(['../uploads/profile_pictures/' . $filename, $adminId]);
                            echo json_encode(['success' => true, 'url' => '../uploads/profile_pictures/' . $filename]);
                        } else {
                            echo json_encode(['success' => false, 'error' => 'Upload failed']);
                        }
                    } else {
                        echo json_encode(['success' => false, 'error' => 'Invalid file type']);
                    }
                } else {
                    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
                }
                break;
            
            // ========== SEARCH ==========
            case 'global_search':
                $query = '%' . trim($_POST['query']) . '%';
                $results = [];
                
                $stmt = $db->prepare("SELECT id, student_id as identifier, full_name as name, 'student' as type FROM students WHERE full_name LIKE ? OR student_id LIKE ? LIMIT 10");
                $stmt->execute([$query, $query]);
                $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
                
                $stmt = $db->prepare("SELECT id, user_id as identifier, full_name as name, 'lecturer' as type FROM users WHERE role = 'LECTURER' AND (full_name LIKE ? OR user_id LIKE ?) LIMIT 10");
                $stmt->execute([$query, $query]);
                $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
                
                $stmt = $db->prepare("SELECT id, title as name, course_code as identifier, 'exam' as type FROM exams WHERE title LIKE ? OR course_code LIKE ? LIMIT 10");
                $stmt->execute([$query, $query]);
                $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
                
                echo json_encode(['success' => true, 'data' => $results]);
                break;
            
            default:
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$profilePic = isset($adminData['profile_pic']) ? $adminData['profile_pic'] : null;
$page = $_GET['page'] ?? 'dashboard';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QODA | Super Admin Dashboard</title>
    <link rel="icon" type="image/png" href="../assets/qoda-logo.png">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    :root {
        --bg: #f8fafc;
        --panel: #ffffff;
        --border: #e2e8f0;
        --text: #0f172a;
        --text-light: #334155;
        --muted: #64748b;
        --sidebar: #0f172a;
        --blue: #3b82f6;
        --blue-dark: #2563eb;
        --danger: #ef4444;
        --warn: #f59e0b;
        --success: #10b981;
        --purple: #8b5cf6;
        --shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    }

    body.dark {
        --bg: #0f172a;
        --panel: #1e293b;
        --border: #334155;
        --text: #f8fafc;
        --text-light: #e2e8f0;
        --muted: #94a3b8;
        --sidebar: #020617;
    }

    body {
        font-family: 'Inter', system-ui, sans-serif;
        background: var(--bg);
        color: var(--text);
        transition: all 0.3s;
        padding-top: 72px;
    }

    /* Header */
    .header-bar {
        background: var(--panel);
        border-bottom: 1px solid var(--border);
        padding: 12px 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        height: 72px;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 1000;
    }

    .header-left {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .mobile-menu-btn {
        display: none;
        width: 42px;
        height: 42px;
        background: linear-gradient(135deg, #4f46e5, #06b6d4);
        border: none;
        border-radius: 12px;
        color: white;
        cursor: pointer;
    }

    .header-logo {
        width: 48px;
        height: 48px;
        background: var(--card);
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        border: 1px solid var(--border);
    }

    .header-logo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .header-title {
        font-size: 20px;
        font-weight: 800;
        background: linear-gradient(135deg, #4f46e5, #06b6d4);
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
    }

    .header-center {
        flex: 1;
        max-width: 500px;
        display: flex;
        gap: 8px;
    }

    .header-search {
        flex: 1;
        position: relative;
    }

    .header-search i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--muted);
    }

    .header-search input {
        width: 100%;
        padding: 10px 16px 10px 40px;
        border: 1px solid var(--border);
        border-radius: 30px;
        background: var(--bg);
        color: var(--text);
        outline: none;
    }

    .header-search-btn {
        padding: 0 20px;
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        border: none;
        border-radius: 30px;
        color: white;
        cursor: pointer;
    }

    .header-right {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .theme-toggle,
    .logout-btn {
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--bg);
        border: 1px solid var(--border);
        border-radius: 12px;
        cursor: pointer;
    }

    .theme-toggle {
        width: 40px;
    }

    .logout-btn {
        padding: 0 16px;
        gap: 8px;
    }

    .search-results {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 12px;
        margin-top: 8px;
        display: none;
        z-index: 1000;
        max-height: 300px;
        overflow-y: auto;
    }

    .search-results.active {
        display: block;
    }

    .search-result-item {
        padding: 12px 16px;
        cursor: pointer;
        border-bottom: 1px solid var(--border);
    }

    .search-result-item:hover {
        background: var(--bg);
    }

    /* Sidebar */
    .layout {
        margin-left: 80px;
    }

    .sidebar {
        position: fixed;
        left: 0;
        top: 72px;
        width: 80px;
        height: calc(100vh - 72px);
        background: linear-gradient(180deg, var(--sidebar) 0%, #0a0f1f 100%);
        border-right: 1px solid rgba(255, 255, 255, 0.1);
        display: flex;
        flex-direction: column;
        align-items: center;
        z-index: 900;
        overflow-y: auto;
    }

    .profile-icon {
        width: 52px;
        height: 52px;
        margin: 20px auto;
        background: linear-gradient(135deg, #3b82f6, #8b5cf6);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        overflow: hidden;
        border: 2px solid rgba(59, 130, 246, 0.5);
    }

    .profile-icon img,
    .profile-icon span {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        font-weight: 700;
        color: white;
    }

    .nav-icons {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
        width: 100%;
    }

    .nav-icon {
        position: relative;
        width: 52px;
        height: 52px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #94a3b8;
        cursor: pointer;
        transition: all 0.2s;
    }

    .nav-icon:hover {
        background: rgba(255, 255, 255, 0.1);
        color: white;
        transform: scale(1.04);
    }

    .nav-icon.active {
        background: linear-gradient(135deg, #3b82f6, #8b5cf6);
        color: white;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
    }

    .nav-icon i {
        font-size: 22px;
    }

    .nav-icon .badge {
        position: absolute;
        top: -4px;
        right: -4px;
        min-width: 18px;
        height: 18px;
        background: #ef4444;
        color: white;
        font-size: 10px;
        font-weight: 700;
        border-radius: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 5px;
    }

    .tooltip-text {
        position: absolute;
        left: 100%;
        top: 50%;
        transform: translateY(-50%);
        margin-left: 12px;
        padding: 6px 12px;
        background: #1e293b;
        color: white;
        font-size: 12px;
        border-radius: 8px;
        white-space: nowrap;
        opacity: 0;
        visibility: hidden;
        transition: all 0.2s;
        pointer-events: none;
    }

    .nav-icon:hover .tooltip-text {
        opacity: 1;
        visibility: visible;
    }

    .sidebar-bottom {
        padding: 20px 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 12px;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
    }

    .theme-switch-icon,
    .logout-icon {
        width: 48px;
        height: 48px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #94a3b8;
        cursor: pointer;
        border-radius: 12px;
    }

    .theme-switch-icon:hover,
    .logout-icon:hover {
        background: rgba(255, 255, 255, 0.1);
        color: white;
    }

    /* Main Content */
    .main {
        padding: 24px 32px;
        min-height: calc(100vh - 72px);
    }

    .page-title {
        background: linear-gradient(135deg, #3b82f6, #8b5cf6);
        color: white;
        border-radius: 20px;
        padding: 24px 28px;
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 24px;
    }

    .bluebar {
        background: linear-gradient(135deg, #3b82f6, #2563eb);
        color: white;
        border-radius: 12px;
        padding: 12px 20px;
        margin-bottom: 24px;
        font-weight: 600;
    }

    .view {
        display: none;
        animation: fadeIn 0.3s ease;
    }

    .view.active {
        display: block;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 24px;
    }

    .stat-card {
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 20px;
        cursor: pointer;
        transition: all 0.3s;
        position: relative;
        overflow: hidden;
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: var(--card-color);
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
    }

    .stat-card .stat-icon {
        font-size: 32px;
        opacity: 0.15;
        position: absolute;
        bottom: 15px;
        right: 15px;
    }

    .stat-value {
        font-size: 36px;
        font-weight: 800;
        margin: 8px 0;
        position: relative;
        z-index: 1;
    }

    .stat-label {
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
        color: var(--muted);
        position: relative;
        z-index: 1;
    }

    .stat-card small {
        font-size: 11px;
        color: var(--muted);
        position: relative;
        z-index: 1;
    }

    .progress {
        height: 6px;
        background: rgba(0, 0, 0, 0.1);
        border-radius: 3px;
        overflow: hidden;
        margin: 10px 0;
    }

    .progress-bar {
        height: 100%;
        border-radius: 3px;
        transition: width 1s ease-out;
    }

    .stat-card.blue {
        --card-color: #3b82f6;
    }

    .stat-card.blue .stat-value {
        color: #3b82f6;
    }

    .stat-card.green {
        --card-color: #10b981;
    }

    .stat-card.green .stat-value {
        color: #10b981;
    }

    .stat-card.purple {
        --card-color: #8b5cf6;
    }

    .stat-card.purple .stat-value {
        color: #8b5cf6;
    }

    .stat-card.orange {
        --card-color: #f59e0b;
    }

    .stat-card.orange .stat-value {
        color: #f59e0b;
    }

    .stat-card.red {
        --card-color: #ef4444;
    }

    .stat-card.red .stat-value {
        color: #ef4444;
    }

    /* Charts */
    .charts-row {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-bottom: 24px;
    }

    .chart-card {
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 20px;
    }

    .chart-card h3 {
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 20px;
    }

    .chart-container {
        height: 300px;
        position: relative;
    }

    /* Tables */
    .table-section {
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 20px;
        overflow-x: auto;
    }

    .table-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 12px;
    }

    .table {
        width: 100%;
        border-collapse: collapse;
    }

    .table th,
    .table td {
        padding: 14px 16px;
        text-align: left;
        border-bottom: 1px solid var(--border);
    }

    .table th {
        background: var(--bg);
        font-weight: 600;
        font-size: 12px;
        text-transform: uppercase;
    }

    .table tbody tr:hover {
        background: var(--bg);
    }

    /* Buttons */
    .btn {
        padding: 10px 20px;
        border-radius: 30px;
        font-weight: 600;
        font-size: 13px;
        cursor: pointer;
        transition: all 0.2s;
        border: none;
    }

    .btn-primary {
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        color: white;
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(79, 70, 229, 0.4);
    }

    .btn-outline {
        background: transparent;
        border: 1px solid var(--border);
        color: var(--text);
    }

    .btn-danger {
        background: linear-gradient(135deg, #ef4444, #dc2626);
        color: white;
    }

    .btn-success {
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
    }

    .btn-sm {
        padding: 6px 12px;
        font-size: 12px;
    }

    .action-buttons {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }

    .action-btn {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        border: 1px solid var(--border);
        background: var(--panel);
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }

    .action-btn:hover {
        background: var(--blue);
        color: white;
        border-color: var(--blue);
    }

    /* Status Badges */
    .status-badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
    }

    .status-active {
        background: rgba(16, 185, 129, 0.15);
        color: #10b981;
    }

    .status-inactive {
        background: rgba(239, 68, 68, 0.15);
        color: #ef4444;
    }

    .status-published {
        background: rgba(16, 185, 129, 0.15);
        color: #10b981;
    }

    .status-draft {
        background: rgba(245, 158, 11, 0.15);
        color: #f59e0b;
    }

    .severity-high {
        background: rgba(239, 68, 68, 0.15);
        color: #ef4444;
    }

    .severity-medium {
        background: rgba(245, 158, 11, 0.15);
        color: #f59e0b;
    }

    .severity-low {
        background: rgba(16, 185, 129, 0.15);
        color: #10b981;
    }

    /* Modal */
    .modal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.7);
        align-items: center;
        justify-content: center;
        z-index: 2000;
        backdrop-filter: blur(4px);
    }

    .modal.active {
        display: flex;
    }

    .modal-content {
        background: var(--panel);
        border-radius: 24px;
        padding: 28px;
        width: 100%;
        max-width: 600px;
        max-height: 85vh;
        overflow-y: auto;
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid var(--border);
    }

    .modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        margin-top: 24px;
        padding-top: 15px;
        border-top: 1px solid var(--border);
    }

    /* Forms */
    .form-group {
        margin-bottom: 16px;
    }

    .form-group label {
        display: block;
        margin-bottom: 6px;
        font-size: 13px;
        font-weight: 600;
    }

    .form-input {
        width: 100%;
        padding: 10px 14px;
        border: 1px solid var(--border);
        border-radius: 10px;
        background: var(--bg);
        color: var(--text);
        font-size: 14px;
    }

    .form-input:focus {
        outline: none;
        border-color: var(--blue);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    textarea.form-input {
        resize: vertical;
        min-height: 80px;
    }

    .toast {
        position: fixed;
        bottom: 30px;
        left: 50%;
        transform: translateX(-50%);
        background: var(--panel);
        border-left: 4px solid var(--success);
        padding: 12px 24px;
        border-radius: 12px;
        box-shadow: var(--shadow-lg);
        z-index: 3000;
        opacity: 0;
        transition: opacity 0.3s;
        pointer-events: none;
    }

    .toast.show {
        opacity: 1;
    }

    .empty-state {
        text-align: center;
        padding: 60px 40px;
        color: var(--muted);
    }

    /* Responsive */
    @media (max-width: 1024px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .charts-row {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .main {
            padding: 16px;
        }

        .stats-grid {
            grid-template-columns: 1fr;
        }

        .sidebar {
            left: -80px;
        }

        .sidebar.mobile-open {
            left: 0;
        }

        .layout {
            margin-left: 0;
        }

        .mobile-menu-btn {
            display: flex;
        }

        .header-title {
            display: none;
        }

        .header-search-btn span {
            display: none;
        }
    }

    /* Proctoring Cards */
    .student-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }

    .monitor-card {
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 16px;
        padding: 16px;
        transition: all 0.3s;
    }

    .monitor-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-lg);
    }

    .monitor-card.warning {
        border-left: 4px solid #f59e0b;
    }

    .monitor-card.critical {
        border-left: 4px solid #ef4444;
    }

    .student-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
    }

    .live-dot {
        width: 8px;
        height: 8px;
        background: #10b981;
        border-radius: 50%;
        animation: pulse 1s infinite;
        display: inline-block;
        margin-right: 6px;
    }

    @keyframes pulse {

        0%,
        100% {
            opacity: 1;
        }

        50% {
            opacity: 0.5;
        }
    }

    .screen-preview {
        width: 100%;
        height: 160px;
        background: var(--bg);
        border-radius: 12px;
        margin: 12px 0;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--muted);
        border: 1px solid var(--border);
    }

    .action-bar {
        display: flex;
        gap: 8px;
        margin-top: 12px;
        flex-wrap: wrap;
    }
    </style>
</head>

<body>
    <div class="header-bar">
        <div class="header-left">
            <button class="mobile-menu-btn" onclick="toggleMobileSidebar()"><i class="fas fa-bars"></i></button>
            <div class="header-logo"><img src="../assets/qoda-logo.png" alt="QODA logo"></div>
            <div class="header-title">QODA SUPER ADMIN</div>
        </div>
        <div class="header-center">
            <div class="header-search">
                <i class="fas fa-search"></i>
                <input type="text" id="globalSearch" placeholder="Search students, lecturers, exams..."
                    onkeyup="handleSearchEnter(event)">
                <div class="search-results" id="searchResults"></div>
            </div>
            <button class="header-search-btn" onclick="executeSearch()"><i
                    class="fas fa-search"></i><span>Search</span></button>
        </div>
        <div class="header-right">
            <div class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-moon" id="themeIcon"></i></div>
            <div class="logout-btn" onclick="logout()"><i class="fas fa-sign-out-alt"></i><span>Logout</span></div>
        </div>
    </div>

    <div class="layout">
        <aside class="sidebar" id="sidebar">
            <div class="profile-icon" onclick="go('profile')">
                <?php if ($profilePic && file_exists(__DIR__ . '/../' . $profilePic)): ?>
                <img src="<?php echo $profilePic; ?>" alt="Profile">
                <?php else: ?>
                <span><?php echo strtoupper(substr($adminData['full_name'] ?? 'A', 0, 2)); ?></span>
                <?php endif; ?>
            </div>
            <div class="nav-icons">
                <div class="nav-icon" data-tooltip="Home" onclick="handleNavClick(this, 'dashboard')"><i
                        class="fas fa-home"></i><span class="tooltip-text">Home</span></div>
                <div class="nav-icon" data-tooltip="Lecturers" onclick="handleNavClick(this, 'lecturers')"><i
                        class="fas fa-chalkboard-user"></i><span class="tooltip-text">Lecturers</span></div>
                <div class="nav-icon" data-tooltip="Students" onclick="handleNavClick(this, 'students')"><i
                        class="fas fa-users"></i><span class="tooltip-text">Students</span><span class="badge"
                        id="studentBadge" style="display:none;">0</span></div>
                <div class="nav-icon" data-tooltip="Exams" onclick="handleNavClick(this, 'exams')"><i
                        class="fas fa-file-alt"></i><span class="tooltip-text">Exams</span></div>
                <div class="nav-icon" data-tooltip="Submissions" onclick="handleNavClick(this, 'submissions')"><i
                        class="fas fa-check-circle"></i><span class="tooltip-text">Submissions</span></div>
                <div class="nav-icon" data-tooltip="Proctoring" onclick="handleNavClick(this, 'proctoring')"><i
                        class="fas fa-eye"></i><span class="tooltip-text">Proctoring</span><span class="badge"
                        id="suspiciousBadge" style="display:none;">0</span></div>
                <div class="nav-icon" data-tooltip="Audit Logs" onclick="handleNavClick(this, 'audit')"><i
                        class="fas fa-history"></i><span class="tooltip-text">Audit Logs</span></div>
            </div>
            <div class="sidebar-bottom">
                <div class="theme-switch-icon" onclick="toggleTheme()"><i class="fas fa-palette"></i><span
                        class="tooltip-text">Theme</span></div>
                <div class="logout-icon" onclick="logout()"><i class="fas fa-sign-out-alt"></i><span
                        class="tooltip-text">Logout</span></div>
            </div>
        </aside>

        <main class="main">
            <div class="page-title">👑 Super Admin Control Panel</div>
            <div class="bluebar" id="bluebarTitle"><span>🏠 Dashboard Overview</span></div>

            <!-- HOME / DASHBOARD -->
            <section id="view-dashboard" class="view active">
                <div class="stats-grid" id="dashboardStats"></div>
                <div class="charts-row">
                    <div class="chart-card">
                        <h3>📈 User Growth (Last 6 Months)</h3>
                        <div class="chart-container"><canvas id="userGrowthChart"></canvas></div>
                    </div>
                    <div class="chart-card">
                        <h3>📊 Exam Creation Trend</h3>
                        <div class="chart-container"><canvas id="examTrendChart"></canvas></div>
                    </div>
                </div>
                <div class="table-section">
                    <div class="table-header">
                        <h3>📋 Recent Audit Activities</h3><button class="btn btn-outline btn-sm"
                            onclick="go('audit')">View All</button>
                    </div>
                    <table class="table" id="recentAuditTable">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="4" class="empty-state">Loading...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- LECTURER MANAGEMENT -->
            <section id="view-lecturers" class="view">
                <div class="table-section">
                    <div class="table-header">
                        <h3>👨‍🏫 Lecturer Management</h3><button class="btn btn-primary btn-sm"
                            onclick="showAddLecturerModal()"><i class="fas fa-plus"></i> Add Lecturer</button>
                    </div>
                    <div style="margin-bottom: 16px;"><input type="text" id="lecturerSearch"
                            placeholder="🔍 Search by name, ID, email..." class="form-input" style="max-width: 300px;"
                            onkeyup="filterLecturers()"></div>
                    <div style="overflow-x: auto;">
                        <table class="table" id="lecturersTable">
                            <thead>
                                <tr>
                                    <th>Staff ID</th>
                                    <th>Full Name</th>
                                    <th>Email</th>
                                    <th>Department</th>
                                    <th>Exams</th>
                                    <th>Submissions</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="8" class="empty-state">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- STUDENT MANAGEMENT -->
            <section id="view-students" class="view">
                <div class="table-section">
                    <div class="table-header">
                        <h3>👨‍🎓 Student Management</h3><button class="btn btn-primary btn-sm"
                            onclick="showAddStudentModal()"><i class="fas fa-plus"></i> Add Student</button>
                    </div>
                    <div style="display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap;">
                        <input type="text" id="studentSearch" placeholder="🔍 Search by name, ID..." class="form-input"
                            style="max-width: 300px;" onkeyup="filterStudents()">
                        <select id="levelFilter" class="form-input" style="width: auto;" onchange="filterStudents()">
                            <option value="all">All Levels</option>
                            <option value="100">100</option>
                            <option value="200">200</option>
                            <option value="300">300</option>
                            <option value="400">400</option>
                        </select>
                        <button class="btn btn-success btn-sm" onclick="exportStudentsToExcel()"><i
                                class="fas fa-file-excel"></i> Export</button>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="table" id="studentsTable">
                            <thead>
                                <tr>
                                    <th>Student ID</th>
                                    <th>Full Name</th>
                                    <th>Email</th>
                                    <th>Level</th>
                                    <th>Programme</th>
                                    <th>Courses</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="8" class="empty-state">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- EXAM MANAGEMENT -->
            <section id="view-exams" class="view">
                <div class="table-section">
                    <div class="table-header">
                        <h3>📝 Exam Management</h3>
                    </div>
                    <div style="display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap;">
                        <input type="text" id="examSearch" placeholder="🔍 Search by title, course..."
                            class="form-input" style="max-width: 300px;" onkeyup="filterExams()">
                        <select id="examStatusFilter" class="form-input" style="width: auto;" onchange="filterExams()">
                            <option value="all">All Status</option>
                            <option value="published">Published</option>
                            <option value="draft">Draft</option>
                        </select>
                        <button class="btn btn-success btn-sm" onclick="exportExamsToExcel()"><i
                                class="fas fa-file-excel"></i> Export</button>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="table" id="examsTable">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Course Code</th>
                                    <th>Lecturer</th>
                                    <th>Duration</th>
                                    <th>Submissions</th>
                                    <th>Avg Score</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="8" class="empty-state">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- SUBMISSIONS -->
            <section id="view-submissions" class="view">
                <div class="table-section">
                    <div class="table-header">
                        <h3>📤 All Submissions</h3><button class="btn btn-success btn-sm"
                            onclick="exportSubmissionsToExcel()"><i class="fas fa-file-excel"></i> Export</button>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="table" id="submissionsTable">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Exam</th>
                                    <th>Lecturer</th>
                                    <th>Score</th>
                                    <th>Grade</th>
                                    <th>Submitted</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="7" class="empty-state">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- PROCTORING / MONITORING -->
            <section id="view-proctoring" class="view">
                <div class="stats-grid" style="grid-template-columns: repeat(3, 1fr); margin-bottom: 20px;">
                    <div class="stat-card red">
                        <div class="stat-value" id="totalSuspicious">0</div>
                        <div class="stat-label">Total Incidents</div>
                    </div>
                    <div class="stat-card orange">
                        <div class="stat-value" id="unresolvedSuspicious">0</div>
                        <div class="stat-label">Unresolved</div>
                    </div>
                    <div class="stat-card purple">
                        <div class="stat-value" id="criticalIncidents">0</div>
                        <div class="stat-label">Critical</div>
                    </div>
                </div>
                <div class="table-section">
                    <div class="table-header">
                        <h3>⚠️ Suspicious Activity Monitoring</h3><button class="btn btn-primary btn-sm"
                            onclick="loadSuspiciousLogs()"><i class="fas fa-sync-alt"></i> Refresh</button>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="table" id="suspiciousTable">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Student</th>
                                    <th>Exam</th>
                                    <th>Event</th>
                                    <th>Details</th>
                                    <th>Severity</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="8" class="empty-state">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- AUDIT LOGS -->
            <section id="view-audit" class="view">
                <div class="table-section">
                    <div class="table-header">
                        <h3>📜 System Audit Trail</h3><button class="btn btn-success btn-sm"
                            onclick="exportAuditLogs()"><i class="fas fa-file-excel"></i> Export</button>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="table" id="auditTable">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Action</th>
                                    <th>Description</th>
                                    <th>IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="6" class="empty-state">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- ADMIN PROFILE (Hidden page - accessed via profile icon) -->
            <section id="view-profile" class="view">
                <div class="table-section">
                    <div class="table-header">
                        <h3>👤 Admin Profile</h3>
                    </div>
                    <div style="display: flex; gap: 30px; margin-bottom: 30px; align-items: center;">
                        <div class="profile-icon" style="width: 100px; height: 100px; margin: 0;"
                            onclick="document.getElementById('profilePicInput').click()">
                            <?php if ($profilePic && file_exists(__DIR__ . '/../' . $profilePic)): ?>
                            <img src="<?php echo $profilePic; ?>" alt="Profile" id="profilePreview">
                            <?php else: ?>
                            <span
                                style="font-size: 40px;"><?php echo strtoupper(substr($adminData['full_name'] ?? 'A', 0, 2)); ?></span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h3><?php echo htmlspecialchars($adminData['full_name'] ?? 'Administrator'); ?></h3>
                            <p><?php echo htmlspecialchars($adminData['email'] ?? ''); ?></p>
                            <p><span class="status-badge status-active">Super Administrator</span></p>
                        </div>
                    </div>
                    <input type="file" id="profilePicInput" accept="image/*" style="display: none;"
                        onchange="uploadProfilePicture(this)">
                    <form id="profileForm" onsubmit="updateAdminProfile(event)">
                        <div class="form-group"><label>Full Name</label><input type="text" id="adminFullName"
                                class="form-input"
                                value="<?php echo htmlspecialchars($adminData['full_name'] ?? ''); ?>"></div>
                        <div class="form-group"><label>Email</label><input type="email" id="adminEmail"
                                class="form-input" value="<?php echo htmlspecialchars($adminData['email'] ?? ''); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary">Update Profile</button>
                    </form>
                    <hr style="margin: 30px 0; border-color: var(--border);">
                    <h4>Change Password</h4>
                    <form id="passwordForm" onsubmit="changeAdminPassword(event)">
                        <div class="form-group"><label>Current Password</label><input type="password"
                                id="currentPassword" class="form-input" required></div>
                        <div class="form-group"><label>New Password</label><input type="password" id="newPassword"
                                class="form-input" required></div>
                        <div class="form-group"><label>Confirm Password</label><input type="password"
                                id="confirmPassword" class="form-input" required></div>
                        <button type="submit" class="btn btn-primary">Change Password</button>
                    </form>
                </div>
            </section>
        </main>
    </div>

    <!-- Add Lecturer Modal -->
    <div id="addLecturerModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-chalkboard-user"></i> Add New Lecturer</h3><button
                    onclick="closeModal('addLecturerModal')"
                    style="background:none; border:none; font-size:28px;">&times;</button>
            </div>
            <form onsubmit="createLecturer(event)" enctype="multipart/form-data">
                <div class="form-group"><label>Staff ID *</label><input type="text" id="lecUserId" class="form-input"
                        required><small>Default password will be set to Staff ID</small></div>
                <div class="form-group"><label>Full Name *</label><input type="text" id="lecFullName" class="form-input"
                        required></div>
                <div class="form-group"><label>Email *</label><input type="email" id="lecEmail" class="form-input"
                        required></div>
                <div class="form-group"><label>Department</label><input type="text" id="lecDepartment"
                        class="form-input"></div>
                <div class="form-group"><label>Faculty</label><input type="text" id="lecFaculty" class="form-input">
                </div>
                <div class="form-group"><label>Levels Taught</label><input type="text" id="lecLevelsTaught"
                        class="form-input" placeholder="e.g., 100,200,300"></div>
                <div class="form-group"><label>Courses</label><textarea id="lecCourses" class="form-input" rows="2"
                        placeholder="e.g., Introduction to Programming, Data Structures"></textarea></div>
                <div class="form-group"><label>Profile Picture</label><input type="file" id="lecProfilePic"
                        class="form-input" accept="image/*"></div>
                <div class="form-group"><label>Status</label><select id="lecStatus" class="form-input">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select></div>
                <div class="modal-footer"><button type="button" class="btn btn-outline"
                        onclick="closeModal('addLecturerModal')">Cancel</button><button type="submit"
                        class="btn btn-primary">Create Lecturer</button></div>
            </form>
        </div>
    </div>

    <!-- Add Student Modal -->
    <div id="addStudentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-graduate"></i> Add New Student</h3><button
                    onclick="closeModal('addStudentModal')"
                    style="background:none; border:none; font-size:28px;">&times;</button>
            </div>
            <form onsubmit="createStudent(event)">
                <div class="form-group"><label>Student ID *</label><input type="text" id="stuStudentId"
                        class="form-input" required><small>Default password will be set to Student ID</small></div>
                <div class="form-group"><label>Full Name *</label><input type="text" id="stuFullName" class="form-input"
                        required></div>
                <div class="form-group"><label>Email *</label><input type="email" id="stuEmail" class="form-input"
                        required></div>
                <div class="form-group"><label>Programme</label><input type="text" id="stuProgramme" class="form-input">
                </div>
                <div class="form-group"><label>Level</label><select id="stuLevel" class="form-input">
                        <option>100</option>
                        <option>200</option>
                        <option>300</option>
                        <option>400</option>
                    </select></div>
                <div class="form-group"><label>Course Code (Optional)</label><input type="text" id="stuCourseCode"
                        class="form-input" placeholder="For enrollment"></div>
                <div class="form-group"><label>Course Name</label><input type="text" id="stuCourseName"
                        class="form-input" placeholder="For enrollment"></div>
                <div class="modal-footer"><button type="button" class="btn btn-outline"
                        onclick="closeModal('addStudentModal')">Cancel</button><button type="submit"
                        class="btn btn-primary">Create Student</button></div>
            </form>
        </div>
    </div>

    <!-- Edit Lecturer Modal -->
    <div id="editLecturerModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Lecturer</h3><button onclick="closeModal('editLecturerModal')"
                    style="background:none; border:none; font-size:28px;">&times;</button>
            </div>
            <form onsubmit="updateLecturer(event)">
                <input type="hidden" id="editLecturerId">
                <div class="form-group"><label>Full Name *</label><input type="text" id="editFullName"
                        class="form-input" required></div>
                <div class="form-group"><label>Email *</label><input type="email" id="editEmail" class="form-input"
                        required></div>
                <div class="form-group"><label>Department</label><input type="text" id="editDepartment"
                        class="form-input"></div>
                <div class="form-group"><label>Faculty</label><input type="text" id="editFaculty" class="form-input">
                </div>
                <div class="form-group"><label>Levels Taught</label><input type="text" id="editLevelsTaught"
                        class="form-input"></div>
                <div class="form-group"><label>Courses</label><textarea id="editCourses" class="form-input"
                        rows="2"></textarea></div>
                <div class="form-group"><label>Status</label><select id="editStatus" class="form-input">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select></div>
                <div class="modal-footer"><button type="button" class="btn btn-outline"
                        onclick="closeModal('editLecturerModal')">Cancel</button><button type="submit"
                        class="btn btn-primary">Update Lecturer</button></div>
            </form>
        </div>
    </div>

    <!-- Edit Student Modal -->
    <div id="editStudentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Student</h3><button onclick="closeModal('editStudentModal')"
                    style="background:none; border:none; font-size:28px;">&times;</button>
            </div>
            <form onsubmit="updateStudent(event)">
                <input type="hidden" id="editStudentDbId">
                <div class="form-group"><label>Student ID *</label><input type="text" id="editStudentId"
                        class="form-input" required></div>
                <div class="form-group"><label>Full Name *</label><input type="text" id="editStudentFullName"
                        class="form-input" required></div>
                <div class="form-group"><label>Email *</label><input type="email" id="editStudentEmail"
                        class="form-input" required></div>
                <div class="form-group"><label>Level</label><select id="editStudentLevel" class="form-input">
                        <option>100</option>
                        <option>200</option>
                        <option>300</option>
                        <option>400</option>
                    </select></div>
                <div class="form-group"><label>Programme</label><input type="text" id="editStudentProgramme"
                        class="form-input"></div>
                <div class="form-group"><label>Status</label><select id="editStudentStatus" class="form-input">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select></div>
                <div class="modal-footer"><button type="button" class="btn btn-outline"
                        onclick="closeModal('editStudentModal')">Cancel</button><button type="submit"
                        class="btn btn-primary">Update Student</button></div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Delete</h3><button onclick="closeModal('deleteModal')"
                    style="background:none; border:none; font-size:28px;">&times;</button>
            </div>
            <p id="deleteMessage">Are you sure you want to delete this item?</p>
            <div class="modal-footer"><button class="btn btn-outline"
                    onclick="closeModal('deleteModal')">Cancel</button><button class="btn btn-danger"
                    id="confirmDeleteBtn">Delete</button></div>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <script>
    // ============================================
    // GLOBAL VARIABLES
    // ============================================
    let deleteId = null,
        deleteType = null;
    let allLecturers = [],
        allStudents = [],
        allExams = [],
        allSubmissions = [],
        allSuspicious = [],
        allAuditLogs = [];
    let userGrowthChart, examTrendChart;

    // ============================================
    // UTILITY FUNCTIONS
    // ============================================
    function showToast(message, type = 'success') {
        const toast = document.getElementById('toast');
        toast.textContent = message;
        toast.classList.add('show');
        if (type === 'error') toast.style.borderLeftColor = '#ef4444';
        else if (type === 'warning') toast.style.borderLeftColor = '#f59e0b';
        else toast.style.borderLeftColor = '#10b981';
        setTimeout(() => toast.classList.remove('show'), 3000);
    }

    function showLoading(show) {
        /* Optional loading indicator */
    }

    function closeModal(modalId) {
        document.getElementById(modalId)?.classList.remove('active');
    }

    function showModal(modalId) {
        document.getElementById(modalId)?.classList.add('active');
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>]/g, m => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;'
        })[m]);
    }

    // ============================================
    // API REQUESTS
    // ============================================
    async function apiRequest(action, data = {}) {
        const formData = new URLSearchParams();
        formData.append('action', action);
        Object.keys(data).forEach(key => formData.append(key, data[key]));
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            return await response.json();
        } catch (error) {
            console.error('API Error:', error);
            return {
                success: false,
                error: error.message
            };
        }
    }

    // ============================================
    // DASHBOARD
    // ============================================
    async function loadDashboard() {
        const result = await apiRequest('get_dashboard_stats');
        if (result.success && result.data) {
            const d = result.data;
            document.getElementById('dashboardStats').innerHTML = `
                    <div class="stat-card blue" onclick="go('students')"><div class="stat-icon"><i class="fas fa-users"></i></div><div class="stat-label">Total Students</div><div class="stat-value">${d.students}</div><div class="progress"><div class="progress-bar" style="width:0%"></div></div></div>
                    <div class="stat-card green" onclick="go('lecturers')"><div class="stat-icon"><i class="fas fa-chalkboard-user"></i></div><div class="stat-label">Total Lecturers</div><div class="stat-value">${d.lecturers}</div></div>
                    <div class="stat-card purple" onclick="go('exams')"><div class="stat-icon"><i class="fas fa-file-alt"></i></div><div class="stat-label">Total Exams</div><div class="stat-value">${d.exams}</div><small>Active: ${d.active_exams}</small></div>
                    <div class="stat-card orange" onclick="go('submissions')"><div class="stat-icon"><i class="fas fa-check-circle"></i></div><div class="stat-label">Submissions</div><div class="stat-value">${d.submissions}</div><small>Pending: ${d.pending_submissions}</small></div>
                `;
            document.getElementById('studentBadge').textContent = d.students;
            document.getElementById('studentBadge').style.display = d.students > 0 ? 'flex' : 'none';
            document.getElementById('suspiciousBadge').textContent = d.suspicious;
            document.getElementById('suspiciousBadge').style.display = d.suspicious > 0 ? 'flex' : 'none';

            if (userGrowthChart) userGrowthChart.destroy();
            if (examTrendChart) examTrendChart.destroy();

            userGrowthChart = new Chart(document.getElementById('userGrowthChart'), {
                type: 'line',
                data: {
                    labels: d.months,
                    datasets: [{
                        label: 'Students',
                        data: d.students_growth,
                        borderColor: '#3b82f6',
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });
            examTrendChart = new Chart(document.getElementById('examTrendChart'), {
                type: 'bar',
                data: {
                    labels: d.months,
                    datasets: [{
                        label: 'Exams Created',
                        data: d.exams_growth,
                        backgroundColor: '#8b5cf6'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });

            await loadRecentAudit();
        }
    }

    async function loadRecentAudit() {
        const result = await apiRequest('get_audit_logs');
        if (result.success && result.data) {
            const tbody = document.querySelector('#recentAuditTable tbody');
            tbody.innerHTML = result.data.slice(0, 10).map(log => `
                    <tr><td>${new Date(log.created_at).toLocaleString()}</td><td>${escapeHtml(log.actor_name || log.actor_id)}</td><td><span class="status-badge">${escapeHtml(log.action)}</span></td><td>${escapeHtml(log.description)}</td></tr>
                `).join('');
        }
    }

    // ============================================
    // LECTURER MANAGEMENT
    // ============================================
    async function loadLecturers() {
        const result = await apiRequest('get_lecturers');
        if (result.success) {
            allLecturers = result.data;
            renderLecturers(allLecturers);
        }
    }

    function renderLecturers(lecturers) {
        const tbody = document.querySelector('#lecturersTable tbody');
        tbody.innerHTML = lecturers.map(l => `
                <tr><td><span class="status-badge">${escapeHtml(l.user_id || l.staff_id)}</span></td>
                <td><b>${escapeHtml(l.full_name)}</b></td><td>${escapeHtml(l.email)}</td>
                <td>${escapeHtml(l.department || '—')}</td><td>${l.exam_count || 0}</td>
                <td>${l.submission_count || 0}</td>
                <td><span class="status-badge ${l.status === 'Active' ? 'status-active' : 'status-inactive'}">${l.status || 'Active'}</span></td>
                <td class="action-buttons"><button class="action-btn" onclick="editLecturer(${l.id})" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="action-btn" onclick="resetLecturerPassword(${l.id})" title="Reset Password"><i class="fas fa-key"></i></button>
                <button class="action-btn" onclick="deleteLecturer(${l.id})" title="Delete" style="color: var(--danger);"><i class="fas fa-trash"></i></button></td></tr>
            `).join('');
    }

    function filterLecturers() {
        const search = document.getElementById('lecturerSearch')?.value.toLowerCase() || '';
        const filtered = allLecturers.filter(l => (l.full_name?.toLowerCase().includes(search)) || (l.user_id
            ?.toLowerCase().includes(search)) || (l.email?.toLowerCase().includes(search)));
        renderLecturers(filtered);
    }

    async function createLecturer(event) {
        event.preventDefault();
        const formData = new FormData();
        formData.append('action', 'create_lecturer');
        formData.append('user_id', document.getElementById('lecUserId').value);
        formData.append('full_name', document.getElementById('lecFullName').value);
        formData.append('email', document.getElementById('lecEmail').value);
        formData.append('department', document.getElementById('lecDepartment').value);
        formData.append('faculty', document.getElementById('lecFaculty').value);
        formData.append('levels_taught', document.getElementById('lecLevelsTaught').value);
        formData.append('courses', document.getElementById('lecCourses').value);
        formData.append('status', document.getElementById('lecStatus').value);
        const fileInput = document.getElementById('lecProfilePic');
        if (fileInput.files[0]) formData.append('profile_pic', fileInput.files[0]);

        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        if (result.success) {
            showToast(result.message);
            closeModal('addLecturerModal');
            loadLecturers();
        } else showToast(result.error, 'error');
    }

    function editLecturer(id) {
        const l = allLecturers.find(l => l.id === id);
        if (!l) return;
        document.getElementById('editLecturerId').value = l.id;
        document.getElementById('editFullName').value = l.full_name || '';
        document.getElementById('editEmail').value = l.email || '';
        document.getElementById('editDepartment').value = l.department || '';
        document.getElementById('editFaculty').value = l.faculty || '';
        document.getElementById('editLevelsTaught').value = l.levels_taught || '';
        document.getElementById('editCourses').value = l.courses || '';
        document.getElementById('editStatus').value = l.status || 'Active';
        showModal('editLecturerModal');
    }

    async function updateLecturer(event) {
        event.preventDefault();
        const result = await apiRequest('update_lecturer', {
            lecturer_id: document.getElementById('editLecturerId').value,
            full_name: document.getElementById('editFullName').value,
            email: document.getElementById('editEmail').value,
            department: document.getElementById('editDepartment').value,
            faculty: document.getElementById('editFaculty').value,
            levels_taught: document.getElementById('editLevelsTaught').value,
            courses: document.getElementById('editCourses').value,
            status: document.getElementById('editStatus').value
        });
        if (result.success) {
            showToast('Lecturer updated');
            closeModal('editLecturerModal');
            loadLecturers();
        } else showToast(result.error, 'error');
    }

    async function resetLecturerPassword(id) {
        if (!confirm('Reset password to Staff ID?')) return;
        const result = await apiRequest('reset_lecturer_password', {
            lecturer_id: id
        });
        if (result.success) showToast(`Password reset to: ${result.new_password}`);
        else showToast(result.error, 'error');
    }

    function deleteLecturer(id) {
        deleteId = id;
        deleteType = 'lecturer';
        document.getElementById('deleteMessage').innerText = 'Delete this lecturer? All their data will be affected.';
        showModal('deleteModal');
    }

    // ============================================
    // STUDENT MANAGEMENT
    // ============================================
    async function loadStudents() {
        const result = await apiRequest('get_students');
        if (result.success) {
            allStudents = result.data;
            renderStudents(allStudents);
        }
    }

    function renderStudents(students) {
        const tbody = document.querySelector('#studentsTable tbody');
        tbody.innerHTML = students.map(s => `
                <tr><td><span class="status-badge">${escapeHtml(s.student_id)}</span></td>
                <td><b>${escapeHtml(s.full_name)}</b></td><td>${escapeHtml(s.email || '—')}</td>
                <td>${escapeHtml(s.level)}</td><td>${escapeHtml(s.programme || '—')}</td>
                <td>${s.enrolled_courses || 0}</td>
                <td><span class="status-badge ${s.status === 'Active' ? 'status-active' : 'status-inactive'}">${s.status || 'Active'}</span></td>
                <td class="action-buttons"><button class="action-btn" onclick="editStudent(${s.id})" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="action-btn" onclick="resetStudentPassword(${s.id})" title="Reset Password"><i class="fas fa-key"></i></button>
                <button class="action-btn" onclick="deleteStudent(${s.id})" title="Delete" style="color: var(--danger);"><i class="fas fa-trash"></i></button></td></tr>
            `).join('');
    }

    function filterStudents() {
        const search = document.getElementById('studentSearch')?.value.toLowerCase() || '';
        const level = document.getElementById('levelFilter')?.value || 'all';
        let filtered = [...allStudents];
        if (search) filtered = filtered.filter(s => (s.full_name?.toLowerCase().includes(search)) || (s.student_id
            ?.toLowerCase().includes(search)));
        if (level !== 'all') filtered = filtered.filter(s => s.level === level);
        renderStudents(filtered);
    }

    async function createStudent(event) {
        event.preventDefault();
        const result = await apiRequest('create_student', {
            student_id: document.getElementById('stuStudentId').value,
            full_name: document.getElementById('stuFullName').value,
            email: document.getElementById('stuEmail').value,
            programme: document.getElementById('stuProgramme').value,
            level: document.getElementById('stuLevel').value,
            course_code: document.getElementById('stuCourseCode').value,
            course_name: document.getElementById('stuCourseName').value
        });
        if (result.success) {
            showToast(result.message);
            closeModal('addStudentModal');
            loadStudents();
        } else showToast(result.error, 'error');
    }

    function editStudent(id) {
        const s = allStudents.find(s => s.id === id);
        if (!s) return;
        document.getElementById('editStudentDbId').value = s.id;
        document.getElementById('editStudentId').value = s.student_id || '';
        document.getElementById('editStudentFullName').value = s.full_name || '';
        document.getElementById('editStudentEmail').value = s.email || '';
        document.getElementById('editStudentLevel').value = s.level || '100';
        document.getElementById('editStudentProgramme').value = s.programme || '';
        document.getElementById('editStudentStatus').value = s.status || 'Active';
        showModal('editStudentModal');
    }

    async function updateStudent(event) {
        event.preventDefault();
        const result = await apiRequest('update_student', {
            student_db_id: document.getElementById('editStudentDbId').value,
            student_id: document.getElementById('editStudentId').value,
            full_name: document.getElementById('editStudentFullName').value,
            email: document.getElementById('editStudentEmail').value,
            level: document.getElementById('editStudentLevel').value,
            programme: document.getElementById('editStudentProgramme').value,
            status: document.getElementById('editStudentStatus').value
        });
        if (result.success) {
            showToast('Student updated');
            closeModal('editStudentModal');
            loadStudents();
        } else showToast(result.error, 'error');
    }

    async function resetStudentPassword(id) {
        if (!confirm('Reset password to Student ID?')) return;
        const result = await apiRequest('reset_student_password', {
            student_id: id
        });
        if (result.success) showToast(`Password reset to: ${result.new_password}`);
        else showToast(result.error, 'error');
    }

    function deleteStudent(id) {
        deleteId = id;
        deleteType = 'student';
        document.getElementById('deleteMessage').innerText = 'Delete this student? All submissions will be removed.';
        showModal('deleteModal');
    }

    function exportStudentsToExcel() {
        const data = allStudents.map(s => ({
            'Student ID': s.student_id,
            'Full Name': s.full_name,
            'Email': s.email,
            'Level': s.level,
            'Programme': s.programme,
            'Status': s.status
        }));
        const ws = XLSX.utils.json_to_sheet(data);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Students');
        XLSX.writeFile(wb, `students_${new Date().toISOString().slice(0,10)}.xlsx`);
        showToast('Exported');
    }

    // ============================================
    // EXAM MANAGEMENT
    // ============================================
    async function loadExams() {
        const result = await apiRequest('get_exams');
        if (result.success) {
            allExams = result.data;
            renderExams(allExams);
        }
    }

    function renderExams(exams) {
        const tbody = document.querySelector('#examsTable tbody');
        tbody.innerHTML = exams.map(e => `
                <tr><td><b>${escapeHtml(e.title)}</b></td><td>${escapeHtml(e.course_code)}</td>
                <td>${escapeHtml(e.lecturer_name || '—')}</td><td>${e.duration_minutes} min</td>
                <td>${e.submission_count || 0}</td><td>${e.avg_score ? Math.round(e.avg_score) + '%' : '—'}</td>
                <td><span class="status-badge ${e.published ? 'status-active' : 'status-draft'}">${e.published ? 'Published' : 'Draft'}</span></td>
                <td class="action-buttons"><button class="action-btn" onclick="toggleExamStatus(${e.id})" title="${e.published ? 'Unpublish' : 'Publish'}"><i class="fas fa-${e.published ? 'eye-slash' : 'eye'}"></i></button>
                <button class="action-btn" onclick="deleteExam(${e.id})" title="Delete" style="color: var(--danger);"><i class="fas fa-trash"></i></button></td></tr>
            `).join('');
    }

    function filterExams() {
        const search = document.getElementById('examSearch')?.value.toLowerCase() || '';
        const status = document.getElementById('examStatusFilter')?.value || 'all';
        let filtered = [...allExams];
        if (search) filtered = filtered.filter(e => (e.title?.toLowerCase().includes(search)) || (e.course_code
            ?.toLowerCase().includes(search)));
        if (status !== 'all') filtered = filtered.filter(e => status === 'published' ? e.published : !e.published);
        renderExams(filtered);
    }

    async function toggleExamStatus(id) {
        const result = await apiRequest('toggle_exam_status', {
            exam_id: id
        });
        if (result.success) {
            showToast('Status updated');
            loadExams();
        }
    }

    function deleteExam(id) {
        deleteId = id;
        deleteType = 'exam';
        document.getElementById('deleteMessage').innerText = 'Delete this exam? All submissions will be deleted.';
        showModal('deleteModal');
    }

    function exportExamsToExcel() {
        const data = allExams.map(e => ({
            'Title': e.title,
            'Course Code': e.course_code,
            'Lecturer': e.lecturer_name,
            'Duration': e.duration_minutes + ' min',
            'Submissions': e.submission_count || 0,
            'Status': e.published ? 'Published' : 'Draft'
        }));
        const ws = XLSX.utils.json_to_sheet(data);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Exams');
        XLSX.writeFile(wb, `exams_${new Date().toISOString().slice(0,10)}.xlsx`);
        showToast('Exported');
    }

    // ============================================
    // SUBMISSIONS
    // ============================================
    async function loadSubmissions() {
        const result = await apiRequest('get_submissions');
        if (result.success) {
            allSubmissions = result.data;
            renderSubmissions(allSubmissions);
        }
    }

    function renderSubmissions(submissions) {
        const tbody = document.querySelector('#submissionsTable tbody');
        tbody.innerHTML = submissions.map(s => {
            const score = s.percentage || s.total_score || 0;
            const grade = score >= 80 ? 'A' : score >= 70 ? 'B' : score >= 60 ? 'C' : score >= 50 ? 'D' : 'E';
            return `<tr><td><b>${escapeHtml(s.student_name)}</b><br><small>${escapeHtml(s.student_identifier)}</small></td>
                <td>${escapeHtml(s.exam_title)}<br><small>${escapeHtml(s.course_code)}</small></td>
                <td>${escapeHtml(s.lecturer_name || '—')}</td>
                <td><strong>${Math.round(score)}%</strong></td>
                <td><span class="status-badge status-active">${grade}</span></td>
                <td>${new Date(s.submitted_at).toLocaleString()}</td>
                <td><span class="status-badge ${s.status === 'MARKED' ? 'status-active' : 'status-draft'}">${s.status || 'PENDING'}</span></td></tr>`;
        }).join('');
    }

    function exportSubmissionsToExcel() {
        const data = allSubmissions.map(s => ({
            'Student': s.student_name,
            'Exam': s.exam_title,
            'Score': s.percentage ? Math.round(s.percentage) + '%' : '—',
            'Submitted': new Date(s.submitted_at).toLocaleString()
        }));
        const ws = XLSX.utils.json_to_sheet(data);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Submissions');
        XLSX.writeFile(wb, `submissions_${new Date().toISOString().slice(0,10)}.xlsx`);
        showToast('Exported');
    }

    // ============================================
    // PROCTORING / SUSPICIOUS LOGS
    // ============================================
    async function loadSuspiciousLogs() {
        const result = await apiRequest('get_suspicious_logs');
        if (result.success) {
            allSuspicious = result.data;
            const total = allSuspicious.length;
            const unresolved = allSuspicious.filter(l => !l.resolved).length;
            const critical = allSuspicious.filter(l => l.severity === 'critical' && !l.resolved).length;
            document.getElementById('totalSuspicious').textContent = total;
            document.getElementById('unresolvedSuspicious').textContent = unresolved;
            document.getElementById('criticalIncidents').textContent = critical;
            renderSuspiciousLogs(allSuspicious);
        }
    }

    function renderSuspiciousLogs(logs) {
        const tbody = document.querySelector('#suspiciousTable tbody');
        tbody.innerHTML = logs.map(l => `
                <tr><td>${new Date(l.created_at).toLocaleString()}</td>
                <td><b>${escapeHtml(l.student_name)}</b><br><small>${escapeHtml(l.student_id)}</small></td>
                <td>${escapeHtml(l.exam_title)}</td>
                <td><span class="status-badge">${escapeHtml(l.event_type)}</span></td>
                <td>${escapeHtml(l.details)}</td>
                <td><span class="status-badge severity-${l.severity}">${l.severity}</span></td>
                <td>${l.resolved ? '<span class="status-badge status-active">Resolved</span>' : '<span class="status-badge status-draft">Pending</span>'}</td>
                <td>${!l.resolved ? `<button class="action-btn" onclick="resolveSuspicious(${l.id})" title="Mark Resolved"><i class="fas fa-check"></i></button>` : ''}</td></tr>
            `).join('');
    }

    async function resolveSuspicious(id) {
        const result = await apiRequest('resolve_suspicious', {
            log_id: id
        });
        if (result.success) {
            showToast('Marked as resolved');
            loadSuspiciousLogs();
        }
    }

    // ============================================
    // AUDIT LOGS
    // ============================================
    async function loadAuditLogs() {
        const result = await apiRequest('get_audit_logs');
        if (result.success) {
            allAuditLogs = result.data;
            renderAuditLogs(allAuditLogs);
        }
    }

    function renderAuditLogs(logs) {
        const tbody = document.querySelector('#auditTable tbody');
        tbody.innerHTML = logs.map(l => `
                <tr><td>${new Date(l.created_at).toLocaleString()}</td>
                <td>${escapeHtml(l.actor_name || l.actor_id)}</td>
                <td>${escapeHtml(l.actor_role || '—')}</td>
                <td><span class="status-badge">${escapeHtml(l.action)}</span></td>
                <td>${escapeHtml(l.description)}</td>
                <td>${escapeHtml(l.ip_address || '—')}</td></tr>
            `).join('');
    }

    function exportAuditLogs() {
        const data = allAuditLogs.map(l => ({
            'Time': new Date(l.created_at).toLocaleString(),
            'User': l.actor_name || l.actor_id,
            'Action': l.action,
            'Description': l.description,
            'IP': l.ip_address
        }));
        const ws = XLSX.utils.json_to_sheet(data);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'AuditLogs');
        XLSX.writeFile(wb, `audit_${new Date().toISOString().slice(0,10)}.xlsx`);
        showToast('Exported');
    }

    // ============================================
    // ADMIN PROFILE
    // ============================================
    async function updateAdminProfile(event) {
        event.preventDefault();
        const result = await apiRequest('update_admin_profile', {
            full_name: document.getElementById('adminFullName').value,
            email: document.getElementById('adminEmail').value
        });
        if (result.success) {
            showToast('Profile updated');
            setTimeout(() => location.reload(), 1000);
        } else showToast(result.error, 'error');
    }

    async function changeAdminPassword(event) {
        event.preventDefault();
        const newPass = document.getElementById('newPassword').value;
        const confirmPass = document.getElementById('confirmPassword').value;
        if (newPass !== confirmPass) {
            showToast('Passwords do not match', 'error');
            return;
        }
        if (newPass.length < 6) {
            showToast('Password must be at least 6 characters', 'error');
            return;
        }
        const result = await apiRequest('change_admin_password', {
            current_password: document.getElementById('currentPassword').value,
            new_password: newPass
        });
        if (result.success) {
            showToast('Password changed');
            document.getElementById('passwordForm').reset();
        } else showToast(result.error, 'error');
    }

    async function uploadProfilePicture(input) {
        if (!input.files[0]) return;
        const formData = new FormData();
        formData.append('action', 'update_profile_picture');
        formData.append('profile_picture', input.files[0]);
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        if (result.success) {
            showToast('Profile picture updated');
            setTimeout(() => location.reload(), 1000);
        } else showToast(result.error, 'error');
    }

    // ============================================
    // SEARCH
    // ============================================
    async function executeSearch() {
        const query = document.getElementById('globalSearch').value.trim();
        if (query.length < 2) {
            showToast('Enter at least 2 characters', 'warning');
            return;
        }
        const result = await apiRequest('global_search', {
            query: query
        });
        if (result.success && result.data) {
            const resultsDiv = document.getElementById('searchResults');
            if (result.data.length === 0) resultsDiv.innerHTML =
                '<div class="search-result-item">No results found</div>';
            else resultsDiv.innerHTML = result.data.map(item =>
                `<div class="search-result-item" onclick="navigateToResult('${item.type}', ${item.id})"><strong>${escapeHtml(item.name)}</strong><br><small>${item.type}: ${escapeHtml(item.identifier || '')}</small></div>`
            ).join('');
            resultsDiv.classList.add('active');
        }
    }

    function handleSearchEnter(event) {
        if (event.key === 'Enter') executeSearch();
        else handleSearch(event.target.value);
    }

    let searchTimeout;
    async function handleSearch(q) {
        const div = document.getElementById('searchResults');
        if (q.length < 2) {
            div.classList.remove('active');
            return;
        }
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(async () => {
            const result = await apiRequest('global_search', {
                query: q
            });
            if (result.success && result.data) {
                div.innerHTML = result.data.map(item =>
                    `<div class="search-result-item" onclick="navigateToResult('${item.type}', ${item.id})"><strong>${escapeHtml(item.name)}</strong><br><small>${item.type}: ${escapeHtml(item.identifier || '')}</small></div>`
                ).join('');
                div.classList.add('active');
            }
        }, 300);
    }

    function navigateToResult(type, id) {
        const pages = {
            student: 'students',
            lecturer: 'lecturers',
            exam: 'exams'
        };
        go(pages[type] || 'dashboard');
        document.getElementById('searchResults').classList.remove('active');
        document.getElementById('globalSearch').value = '';
    }

    // ============================================
    // NAVIGATION
    // ============================================
    function go(page) {
        document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
        document.getElementById(`view-${page}`)?.classList.add('active');
        document.querySelectorAll('.nav-icon').forEach(icon => icon.classList.remove('active'));
        const titles = {
            dashboard: '🏠 Dashboard Overview',
            lecturers: '👨‍🏫 Lecturer Management',
            students: '👨‍🎓 Student Management',
            exams: '📝 Exam Management',
            submissions: '📤 All Submissions',
            proctoring: '👁️ Proctoring Monitoring',
            audit: '📜 System Audit Trail',
            profile: '👤 Admin Profile'
        };
        document.getElementById('bluebarTitle').innerHTML = `<span>${titles[page] || page}</span>`;
        if (page === 'dashboard') loadDashboard();
        else if (page === 'lecturers') loadLecturers();
        else if (page === 'students') loadStudents();
        else if (page === 'exams') loadExams();
        else if (page === 'submissions') loadSubmissions();
        else if (page === 'proctoring') loadSuspiciousLogs();
        else if (page === 'audit') loadAuditLogs();
        if (window.innerWidth <= 768) toggleMobileSidebar();
    }

    function handleNavClick(element, page) {
        go(page);
    }

    function toggleMobileSidebar() {
        document.getElementById('sidebar').classList.toggle('mobile-open');
    }

    function toggleTheme() {
        document.body.classList.toggle('dark');
        localStorage.setItem('theme', document.body.classList.contains('dark') ? 'dark' : 'light');
        document.getElementById('themeIcon').className = document.body.classList.contains('dark') ? 'fas fa-sun' :
            'fas fa-moon';
    }

    function logout() {
        if (confirm('Logout?')) window.location.href = 'logout.php';
    }

    function showAddLecturerModal() {
        showModal('addLecturerModal');
    }

    function showAddStudentModal() {
        showModal('addStudentModal');
    }

    // Delete confirmation
    document.getElementById('confirmDeleteBtn')?.addEventListener('click', async () => {
        if (!deleteId || !deleteType) return;
        let result;
        if (deleteType === 'lecturer') result = await apiRequest('delete_lecturer', {
            lecturer_id: deleteId
        });
        else if (deleteType === 'student') result = await apiRequest('delete_student', {
            student_id: deleteId
        });
        else if (deleteType === 'exam') result = await apiRequest('delete_exam', {
            exam_id: deleteId
        });
        if (result?.success) {
            showToast(`${deleteType} deleted`);
            closeModal('deleteModal');
            if (deleteType === 'lecturer') loadLecturers();
            else if (deleteType === 'student') loadStudents();
            else if (deleteType === 'exam') loadExams();
        } else showToast(result?.error || 'Delete failed', 'error');
        deleteId = null;
        deleteType = null;
    });

    // ============================================
    // INITIALIZATION
    // ============================================
    document.addEventListener('DOMContentLoaded', () => {
        if (localStorage.getItem('theme') === 'dark') document.body.classList.add('dark');
        const urlPage = new URLSearchParams(window.location.search).get('page') || 'dashboard';
        go(urlPage);
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.header-search')) document.getElementById('searchResults').classList
                .remove('active');
        });
    });
    </script>
</body>

</html>
