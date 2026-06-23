<?php
// exam_landing.php - Exam Pre-Start Landing Page
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../backend-php/config/database.php';

// Check if user is logged in and is a student
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['user_role'] !== 'STUDENT') {
    $preview = isset($_GET['preview']) && $_GET['preview'] == 1;
    if (!$preview) {
        header('Location: lecturer_dashboard.php');
        exit;
    }
}

$examId = isset($_GET['exam_id']) ? $_GET['exam_id'] : null;
$studentId = $_SESSION['user_id'] ?? 'test_student';
$studentName = $_SESSION['user_name'] ?? 'Student';
$passwordError = null;
$accessGranted = false;

if (!$examId) {
    header('Location: student_dashboard.php');
    exit;
}

// Get student data
$studentData = null;
if ($studentId && $studentId !== 'test_student') {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    $studentData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($studentData) {
        $studentName = $studentData['full_name'];
    }
}

// Get exam data
$preview = isset($_GET['preview']) && $_GET['preview'] == 1;
if ($preview) {
    $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
} else {
    $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ? AND published = 1");
}
$stmt->execute([$examId]);
$examData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$examData) {
    header('Location: student_dashboard.php?error=exam_not_found');
    exit;
}

// Check enrollment
if (!$preview && $studentId !== 'test_student') {
    $courseCode = $examData['course_code'];
    $checkStmt = $pdo->prepare("SELECT id FROM course_enrollments WHERE student_id = ? AND course_code = ?");
    $checkStmt->execute([$studentId, $courseCode]);
    if (!$checkStmt->fetch()) {
        header('Location: student_dashboard.php?error=not_enrolled');
        exit;
    }
    
    // Check exam visibility
    $visStmt = $pdo->prepare("SELECT visible FROM exam_visibility WHERE exam_id = ? AND student_id = ?");
    $visStmt->execute([$examId, $studentId]);
    $visibility = $visStmt->fetch(PDO::FETCH_ASSOC);
    if ($visibility && $visibility['visible'] == 0) {
        header('Location: student_dashboard.php?error=exam_hidden');
        exit;
    }
    
    // Check if already submitted
    $subStmt = $pdo->prepare("
        SELECT id, status, submitted_at, submitted
        FROM exam_submissions
        WHERE exam_id = ? AND student_id = ?
        ORDER BY submitted_at DESC, id DESC
        LIMIT 1
    ");
    $subStmt->execute([$examId, $studentId]);
    $submission = $subStmt->fetch(PDO::FETCH_ASSOC);
    $submissionStatus = strtoupper((string)($submission['status'] ?? ''));
    $hasRealSubmission = $submission && (!empty($submission['submitted_at']) || intval($submission['submitted'] ?? 0) === 1);
    if ($hasRealSubmission && in_array($submissionStatus, ['SUBMITTED', 'TIMED_OUT', 'GRADED', 'MARKED', 'AUTO_GRADED', 'MANUALLY_GRADED'], true)) {
        header('Location: student_dashboard.php?error=already_submitted');
        exit;
    }
}

// Handle password verification
$requiresPassword = !empty($examData['exam_password']);

if ($requiresPassword && !$preview) {
    if (isset($_POST['verify_password'])) {
        if (password_verify($_POST['exam_password'], $examData['exam_password'])) {
            $_SESSION['exam_auth_' . $examId] = true;
            $accessGranted = true;
        } else {
            $passwordError = "Incorrect password. Please try again.";
        }
    } elseif (isset($_SESSION['exam_auth_' . $examId]) && $_SESSION['exam_auth_' . $examId] === true) {
        $accessGranted = true;
    }
} else {
    $accessGranted = true;
}

// Calculate exam timing from the database clock so PHP and MySQL cannot disagree.
$dbNow = $pdo->query("SELECT NOW()")->fetchColumn();
$nowTs = strtotime($dbNow ?: date('Y-m-d H:i:s'));
$startTs = !empty($examData['start_datetime']) ? strtotime($examData['start_datetime']) : null;
$durationMins = max(1, (int)($examData['duration_minutes'] ?? 180));
$endTs = !empty($examData['end_datetime']) ? strtotime($examData['end_datetime']) : null;
if ($startTs && (!$endTs || $endTs <= $startTs)) {
    $endTs = $startTs + ($durationMins * 60);
}

$examStatus = 'scheduled';
if ($startTs && $startTs > $nowTs) {
    $examStatus = 'scheduled';
} elseif ($startTs && $startTs <= $nowTs && (!$endTs || $endTs > $nowTs)) {
    $examStatus = 'ongoing';
} elseif ($startTs && $endTs && $endTs <= $nowTs) {
    $examStatus = 'expired';
}

$serverNowMs = $nowTs * 1000;
$startTimeMs = $startTs ? $startTs * 1000 : null;
$endTimeMs = $endTs ? $endTs * 1000 : null;
$targetTimestamp = null;
if ($examStatus === 'scheduled' && $startTs) {
    $targetTimestamp = $startTs;
} elseif ($examStatus === 'ongoing' && $endTs) {
    $targetTimestamp = $endTs;
}
$remainingSeconds = $targetTimestamp ? max(0, $targetTimestamp - $nowTs) : 0;

// Get current theme
$theme = isset($_SESSION['theme']) ? $_SESSION['theme'] : 'dark';

// Instructions for rotation
$instructions = [
    "📝 Read each question carefully before answering",
    "💻 For coding questions, write clean and efficient code",
    "⏱️ Keep an eye on the timer - manage your time wisely",
    "🚫 Do not switch tabs - this will auto-submit your exam",
    "💾 Your answers are auto-saved every 30 seconds",
    "✅ Review all answers before final submission",
    "🎥 Screen sharing is required for proctoring",
    "🔒 Only one attempt is allowed per exam",
    "📊 Test your code before final submission"
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Landing - <?php echo htmlspecialchars($examData['title'] ?? 'Exam'); ?> | Qoda</title>
    <link rel="icon" type="image/png" href="../assets/qoda-logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    :root {
        --bg: #1a1a1a;
        --panel: #2d2d2d;
        --border: #404040;
        --text: #e0e0e0;
        --text-secondary: #a0a0a0;
        --muted: #888888;
        --blue: #3b82f6;
        --blue2: #2563eb;
        --danger: #ef4444;
        --success: #10b981;
        --warning: #f59e0b;
    }

    :root.light-theme {
        --bg: #f3f4f6;
        --panel: #ffffff;
        --border: #e5e7eb;
        --text: #111827;
        --text-secondary: #4b5563;
        --muted: #6b7280;
        --blue: #3b82f6;
        --blue2: #2563eb;
        --danger: #dc2626;
        --success: #059669;
        --warning: #d97706;
    }

    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: var(--bg);
        color: var(--text);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        transition: background 0.3s ease;
    }

    .landing-container {
        max-width: 1200px;
        width: 100%;
        margin: 0 auto;
    }

    /* Header Section */
    .exam-header {
        background: linear-gradient(135deg, var(--blue), var(--blue2));
        border-radius: 24px;
        padding: 30px;
        margin-bottom: 30px;
        text-align: center;
        color: white;
        box-shadow: 0 20px 25px -12px rgba(0, 0, 0, 0.3);
    }

    .school-logo {
        width: 80px;
        height: 80px;
        background: white;
        border-radius: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 15px;
        font-size: 40px;
        font-weight: bold;
        color: var(--blue);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
    }

    .school-logo img {
        width: 60px;
        height: 60px;
        object-fit: contain;
    }

    .school-name {
        font-size: 24px;
        font-weight: 700;
        margin-bottom: 5px;
    }

    .exam-title {
        font-size: 28px;
        font-weight: 800;
        margin: 15px 0 10px;
    }

    .exam-meta {
        display: flex;
        justify-content: center;
        gap: 30px;
        flex-wrap: wrap;
        margin-top: 15px;
        font-size: 14px;
        opacity: 0.9;
    }

    .exam-meta span {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    /* Two Column Layout */
    .two-columns {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 30px;
    }

    @media (max-width: 768px) {
        .two-columns {
            grid-template-columns: 1fr;
        }
    }

    /* Card Styles */
    .card {
        background: var(--panel);
        border-radius: 20px;
        padding: 25px;
        border: 1px solid var(--border);
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    .card-title {
        font-size: 18px;
        font-weight: 700;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid var(--border);
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .card-title i {
        color: var(--blue);
    }

    /* Timer Card */
    .timer-card {
        text-align: center;
        background: linear-gradient(135deg, var(--panel), var(--bg));
    }

    .flip-timer {
        margin: 20px 0;
    }

    .flip-clock {
        display: flex;
        justify-content: center;
        gap: 12px;
        margin-bottom: 20px;
        perspective: 1000px;
    }

    .flip-unit {
        text-align: center;
    }

    .flip-number {
        background: linear-gradient(180deg, #ffffff 0%, #dbe3eb 100%);
        color: #101820;
        font-size: 58px;
        font-weight: 900;
        font-family: 'Arial Black', Impact, monospace;
        line-height: 1;
        border-radius: 14px;
        min-width: 112px;
        height: 108px;
        border: 1px solid rgba(15,23,42,.2);
        box-shadow: 0 12px 28px rgba(15,23,42,.18);
        display: flex;
        align-items: center;
        justify-content: center;
        letter-spacing: 0;
        position: relative;
    }

    .flip-face {
        position: absolute;
        left: 0;
        right: 0;
        height: 50%;
        display: flex;
        justify-content: center;
        overflow: hidden;
        background: linear-gradient(180deg, #f8fafc 0%, #cbd1d8 100%);
    }

    .flip-face span {
        line-height: 110px;
        display: block;
    }

    .flip-top {
        top: 0;
        align-items: flex-start;
        border-radius: 8px 8px 0 0;
        border-bottom: 2px solid #111827;
    }

    .flip-bottom {
        bottom: 0;
        align-items: flex-end;
        border-radius: 0 0 8px 8px;
        background: linear-gradient(180deg, #9aa2ac 0%, #eef2f6 100%);
    }

    .flip-bottom span {
        transform: translateY(-55px);
    }

    .flip-flap {
        position: absolute;
        left: 0;
        right: 0;
        top: 0;
        height: 50%;
        display: flex;
        justify-content: center;
        align-items: flex-start;
        overflow: hidden;
        border-radius: 8px 8px 0 0;
        background: linear-gradient(180deg, #f8fafc 0%, #cbd1d8 100%);
        transform-origin: bottom center;
        backface-visibility: hidden;
        z-index: 4;
        border-bottom: 2px solid #111827;
    }

    .flip-flap span {
        line-height: 110px;
    }

    :root.light-theme .flip-number {
        color: #101820;
    }

    .flip-number::before,
    .flip-number::after {
        display: none;
    }

    .flip-label {
        font-size: 12px;
        color: var(--muted);
        margin-top: 8px;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .timer-status {
        margin-top: 15px;
        padding: 10px;
        border-radius: 12px;
        background: rgba(59, 130, 246, 0.1);
        font-size: 14px;
    }

    .attempt-btn {
        background: linear-gradient(135deg, var(--success), #059669);
        color: white;
        border: none;
        padding: 16px 32px;
        border-radius: 50px;
        font-size: 18px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.3s ease;
        width: 100%;
        margin-top: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }

    .attempt-btn:not(:disabled):hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(16, 185, 129, 0.3);
    }

    .attempt-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .attempt-btn.primary {
        background: linear-gradient(135deg, var(--blue), var(--blue2));
    }

    .attempt-btn.primary:not(:disabled):hover {
        box-shadow: 0 10px 20px rgba(59, 130, 246, 0.3);
    }

    /* Instructions Card */
    .instructions-card {
        background: var(--panel);
    }

    .rotating-instruction {
        background: rgba(59, 130, 246, 0.1);
        border-left: 4px solid var(--blue);
        padding: 20px;
        border-radius: 12px;
        margin: 20px 0;
        font-size: 16px;
        text-align: center;
        transition: opacity 0.5s ease;
    }

    .rules-list {
        list-style: none;
        margin: 20px 0;
    }

    .rules-list li {
        padding: 10px 0;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .rules-list li i {
        width: 24px;
        color: var(--blue);
    }

    /* Password Modal */
    .password-modal {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.8);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }

    .password-modal-content {
        background: var(--panel);
        border-radius: 24px;
        padding: 30px;
        width: 90%;
        max-width: 400px;
        text-align: center;
    }

    .password-modal-content input {
        width: 100%;
        padding: 14px;
        margin: 20px 0;
        border-radius: 12px;
        border: 1px solid var(--border);
        background: var(--bg);
        color: var(--text);
        font-size: 16px;
    }

    .error-message {
        color: var(--danger);
        font-size: 14px;
        margin-top: 10px;
    }

    /* Theme Toggle */
    .theme-toggle {
        position: fixed;
        top: 20px;
        right: 20px;
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 50%;
        width: 44px;
        height: 44px;
        padding: 0;
        cursor: pointer;
        z-index: 100;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 10px 28px rgba(0,0,0,.24);
    }

    .theme-toggle i {
        font-size: 16px;
    }

    /* Animations */
    @keyframes flip {
        0% {
            transform: rotateX(0deg);
        }

        50% {
            transform: rotateX(-92deg);
            filter: brightness(.76);
        }

        100% {
            transform: rotateX(-180deg);
            filter: brightness(1);
        }
    }

    .flip-animation .flip-flap {
        animation: flip 0.72s cubic-bezier(.2,.85,.2,1);
    }

    @keyframes pulse {

        0%,
        100% {
            transform: scale(1);
        }

        50% {
            transform: scale(1.05);
        }
    }

    .pulse-animation {
        animation: pulse 1s infinite;
    }

    /* Avatar / Waiting Area */
    .waiting-avatar {
        text-align: center;
        margin: 30px 0;
    }

    .waiting-avatar i {
        font-size: 80px;
        color: var(--muted);
        opacity: 0.5;
    }

    .waiting-text {
        color: var(--muted);
        font-size: 14px;
        margin-top: 10px;
    }

    .delay-notice {
        background: rgba(245, 158, 11, 0.1);
        border-radius: 12px;
        padding: 15px;
        margin-top: 20px;
        text-align: center;
        font-size: 13px;
        color: var(--warning);
    }
    </style>
</head>

<body class="<?php echo $theme; ?>-theme">
    <div class="theme-toggle" onclick="toggleTheme()" title="Change theme">
        <i class="fas fa-moon" id="themeIcon"></i>
    </div>

    <div class="landing-container">
        <!-- Exam Header -->
        <div class="exam-header">
            <div class="school-logo">
                <?php if (!empty($examData['school_logo'])): ?>
                <img src="<?php echo htmlspecialchars($examData['school_logo']); ?>" alt="School Logo">
                <?php else: ?>
                <span>🏫</span>
                <?php endif; ?>
            </div>
            <div class="school-name"><?php echo htmlspecialchars($examData['school_name'] ?? 'Qoda University'); ?>
            </div>
            <div class="exam-title"><?php echo htmlspecialchars($examData['title']); ?></div>
            <div class="exam-meta">
                <span><i class="fas fa-book"></i> <?php echo htmlspecialchars($examData['course_code']); ?></span>
                <span><i class="fas fa-layer-group"></i>
                    <?php echo htmlspecialchars($examData['level'] ?? 'N/A'); ?></span>
                <span><i class="fas fa-clock"></i> <?php echo $durationMins; ?> minutes</span>
                <span><i class="fas fa-university"></i> Faculty of
                    <?php echo htmlspecialchars($examData['faculty_name'] ?? 'Technology'); ?></span>
                <span><i class="fas fa-calendar"></i>
                    <?php echo htmlspecialchars($examData['semester'] ?? 'Current Semester'); ?></span>
                <span><i class="fas fa-tag"></i>
                    <?php echo htmlspecialchars($examData['exam_type'] ?? 'Examination'); ?></span>
                <span><i class="fas fa-building"></i>
                    <?php echo htmlspecialchars($examData['school_type'] ?? 'Regular'); ?></span>
            </div>
        </div>

        <div class="two-columns">
            <!-- Left Column: Timer & Action -->
            <div class="card timer-card">
                <div class="card-title">
                    <i class="fas fa-hourglass-half"></i>
                    <span>Exam Countdown</span>
                </div>

                <div class="flip-timer">
                    <div class="flip-clock" id="flipClock">
                        <div class="flip-unit">
                            <div class="flip-number" id="flipDays">00</div>
                            <div class="flip-label">Days</div>
                        </div>
                        <div class="flip-unit">
                            <div class="flip-number" id="flipHours">00</div>
                            <div class="flip-label">Hours</div>
                        </div>
                        <div class="flip-unit">
                            <div class="flip-number" id="flipMinutes">00</div>
                            <div class="flip-label">Minutes</div>
                        </div>
                        <div class="flip-unit">
                            <div class="flip-number" id="flipSeconds">00</div>
                            <div class="flip-label">Seconds</div>
                        </div>
                    </div>

                    <div class="timer-status" id="timerStatus">
                        <?php if ($examStatus === 'scheduled'): ?>
                        <i class="fas fa-clock"></i> Your exam will start in:
                        <?php elseif ($examStatus === 'ongoing'): ?>
                        <i class="fas fa-play-circle"></i> Exam is ongoing! Time remaining:
                        <?php else: ?>
                        <i class="fas fa-ban"></i> This exam has expired
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($requiresPassword && !$preview && !$accessGranted): ?>
                <div class="delay-notice">
                    <i class="fas fa-lock"></i> This exam requires a password
                </div>
                <button class="attempt-btn primary" onclick="showPasswordModal()">
                    <i class="fas fa-key"></i> Enter Password
                </button>
                <?php elseif ($examStatus === 'expired'): ?>
                <div class="delay-notice">
                    <i class="fas fa-calendar-times"></i> This exam has expired and cannot be taken.
                </div>
                <a href="student_dashboard.php" class="attempt-btn primary"
                    style="text-decoration: none; display: block; text-align: center;">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <?php elseif ($examStatus === 'ongoing'): ?>
                <button class="attempt-btn" id="attemptBtn" onclick="startExam()"
                    style="text-decoration: none; background: linear-gradient(135deg, #10b981, #059669);">
                    <i class="fas fa-play"></i> Attempt Quiz Now
                </button>
                <?php else: ?>
                <button class="attempt-btn" id="attemptBtn" onclick="startExam()" <?php echo $examStatus === 'ongoing' ? '' : 'disabled'; ?>>
                    <i class="fas fa-spinner fa-spin"></i> Please wait...
                </button>
                <div class="delay-notice" id="delayNotice" style="display: none;">
                    <i class="fas fa-clock"></i> The exam will open when the countdown reaches zero.
                </div>
                <?php endif; ?>
            </div>

            <!-- Right Column: Instructions -->
            <div class="card instructions-card">
                <div class="card-title">
                    <i class="fas fa-info-circle"></i>
                    <span>Exam Guidelines</span>
                </div>

                <div class="rotating-instruction" id="rotatingInstruction">
                    <i class="fas fa-lightbulb"></i> <?php echo $instructions[0]; ?>
                </div>

                <ul class="rules-list">
                    <li><i class="fas fa-user-check"></i> Only one attempt is allowed per exam</li>
                    <li><i class="fas fa-desktop"></i> Screen sharing is required for the entire duration</li>
                    <li><i class="fas fa-ban"></i> Tab switching will auto-submit your exam</li>
                    <li><i class="fas fa-save"></i> Answers are auto-saved every 30 seconds</li>
                    <li><i class="fas fa-chart-line"></i> Test your code using the Run Code feature</li>
                    <li><i class="fas fa-clock"></i> Timer shows remaining time in real-time</li>
                    <li><i class="fas fa-check-circle"></i> Review all answers before final submission</li>
                </ul>

                <div class="waiting-avatar" id="waitingAvatar">
                    <i class="fas fa-user-clock"></i>
                    <div class="waiting-text">Please wait for the exam to start...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Password Modal -->
    <div id="passwordModal" class="password-modal" style="display: none;">
        <div class="password-modal-content">
            <i class="fas fa-lock" style="font-size: 48px; color: var(--blue);"></i>
            <h2 style="margin: 15px 0;">Exam Password Required</h2>
            <p>Please enter the password provided by your lecturer.</p>
            <form method="POST" id="passwordForm">
                <input type="password" name="exam_password" id="examPassword" placeholder="Enter exam password"
                    autofocus>
                <button type="submit" name="verify_password" class="attempt-btn primary" style="margin-top: 0;">
                    <i class="fas fa-unlock-alt"></i> Verify & Continue
                </button>
            </form>
            <?php if ($passwordError): ?>
            <div class="error-message"><?php echo htmlspecialchars($passwordError); ?></div>
            <?php endif; ?>
            <button onclick="closePasswordModal()"
                style="margin-top: 15px; background: none; border: none; color: var(--muted); cursor: pointer;">
                Cancel
            </button>
        </div>
    </div>

    <script>
    // Exam timing data
    const examStatus = '<?php echo $examStatus; ?>';
    const startTimeStr = '<?php echo $examData['start_datetime']; ?>';
    const durationMins = <?php echo $durationMins; ?>;
    const examId = <?php echo json_encode($examId); ?>;
    const requiresPassword = <?php echo $requiresPassword ? 'true' : 'false'; ?>;
    const serverNowMs = <?php echo json_encode($serverNowMs); ?>;
    const startTimeMs = <?php echo json_encode($startTimeMs); ?>;
    const endTimeMs = <?php echo json_encode($endTimeMs); ?>;
    const clientLoadedAtMs = Date.now();
    let remainingSeconds = <?php echo json_encode($remainingSeconds); ?>;

    let targetTime = null;
    let timerInterval = null;
    let instructionIndex = 0;
    let instructionInterval = null;

    // Use server-generated timestamps instead of browser-parsed MySQL dates.
    if (examStatus === 'scheduled' && startTimeMs) {
        targetTime = startTimeMs;
    } else if (examStatus === 'ongoing' && endTimeMs) {
        targetTime = endTimeMs;
    }

    function ensureFlipValue(element) {
        if (!element) return;
        element.dataset.value = element.textContent.trim() || '00';
    }

    function paintFlipNumber(element, value) {
        element.textContent = value;
    }

    function setFlipValue(elementId, newValue) {
        const element = document.getElementById(elementId);
        if (!element) return;
        ensureFlipValue(element);
        const oldValue = element.dataset.value || element.textContent.trim() || '00';
        if (oldValue === newValue) return;
        element.dataset.value = newValue;
        paintFlipNumber(element, newValue);
    }

    function setStaticFlipValue(elementId, value) {
        const element = document.getElementById(elementId);
        if (!element) return;
        element.dataset.value = value;
        element.classList.remove('flip-animation');
        element.textContent = value;
    }

    function getServerNowMs() {
        return serverNowMs + (Date.now() - clientLoadedAtMs);
    }

    function enableAttemptButton() {
        const attemptBtn = document.getElementById('attemptBtn');
        if (!attemptBtn || requiresPassword) return;
        attemptBtn.disabled = false;
        attemptBtn.innerHTML = '<i class="fas fa-play"></i> Attempt Quiz Now';
        attemptBtn.style.background = 'linear-gradient(135deg, #10b981, #059669)';
        const delayNotice = document.getElementById('delayNotice');
        if (delayNotice) delayNotice.style.display = 'none';
    }

    // Update flip clock
    function updateFlipClock() {
        if (examStatus === 'expired') {
            setStaticFlipValue('flipDays', '00');
            setStaticFlipValue('flipHours', '00');
            setStaticFlipValue('flipMinutes', '00');
            setStaticFlipValue('flipSeconds', '00');
            return;
        }

        if (remainingSeconds <= 0) {
            if (examStatus === 'scheduled') {
                window.location.reload();
            } else if (examStatus === 'ongoing') {
                clearInterval(timerInterval);
                const attemptBtn = document.getElementById('attemptBtn');
                if (attemptBtn) {
                    attemptBtn.disabled = true;
                    attemptBtn.innerHTML = "<i class=\"fas fa-hourglass-end\"></i> Time's Up!";
                }
            }
            return;
        }

        const days = Math.floor(remainingSeconds / 86400);
        const hours = Math.floor((remainingSeconds % 86400) / 3600);
        const minutes = Math.floor((remainingSeconds % 3600) / 60);
        const seconds = remainingSeconds % 60;

        const newDays = days.toString().padStart(2, '0');
        const newHours = hours.toString().padStart(2, '0');
        const newMinutes = minutes.toString().padStart(2, '0');
        const newSeconds = seconds.toString().padStart(2, '0');

        setFlipValue('flipDays', newDays);
        setFlipValue('flipHours', newHours);
        setFlipValue('flipMinutes', newMinutes);
        setFlipValue('flipSeconds', newSeconds);

        if (examStatus === 'ongoing') {
            enableAttemptButton();
        }
    }

    // Rotate instructions
    function rotateInstructions() {
        const instructions = <?php echo json_encode($instructions); ?>;
        const instructionEl = document.getElementById('rotatingInstruction');
        if (instructionEl) {
            instructionEl.style.opacity = '0';
            setTimeout(() => {
                instructionIndex = (instructionIndex + 1) % instructions.length;
                instructionEl.innerHTML = `<i class="fas fa-lightbulb"></i> ${instructions[instructionIndex]}`;
                instructionEl.style.opacity = '1';
            }, 500);
        }
    }

    // Start exam function
    function startExam() {
        const attemptBtn = document.getElementById('attemptBtn');
        if (!attemptBtn || attemptBtn.disabled) {
            showToast('Please wait for the exam to start', 'warning');
            return;
        }

        window.location.assign(`exam_interface.php?exam_id=${examId}`);
    }

    // Theme toggle
    function updateThemeIcon() {
        const icon = document.getElementById('themeIcon');
        if (!icon) return;
        icon.className = document.documentElement.classList.contains('light-theme') ? 'fas fa-sun' : 'fas fa-moon';
    }

    function toggleTheme() {
        const root = document.documentElement;
        if (root.classList.contains('light-theme')) {
            root.classList.remove('light-theme');
            localStorage.setItem('theme', 'dark');
        } else {
            root.classList.add('light-theme');
            localStorage.setItem('theme', 'light');
        }
        updateThemeIcon();
    }

    // Password modal functions
    function showPasswordModal() {
        document.getElementById('passwordModal').style.display = 'flex';
    }

    function closePasswordModal() {
        document.getElementById('passwordModal').style.display = 'none';
    }

    // Toast notification
    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.style.cssText = `
                position: fixed;
                bottom: 20px;
                left: 50%;
                transform: translateX(-50%);
                background: ${type === 'error' ? '#ef4444' : type === 'success' ? '#10b981' : '#3b82f6'};
                color: white;
                padding: 12px 24px;
                border-radius: 50px;
                font-size: 14px;
                z-index: 10000;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            `;
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }

    // Load saved theme
    function loadTheme() {
        if (document.body.classList.contains('light-theme')) {
            document.documentElement.classList.add('light-theme');
        }
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'light') {
            document.documentElement.classList.add('light-theme');
        } else if (savedTheme === 'dark') {
            document.documentElement.classList.remove('light-theme');
        }
        updateThemeIcon();
    }

    // Initialize
    function init() {
        loadTheme();
        ['flipDays', 'flipHours', 'flipMinutes', 'flipSeconds'].forEach(id => {
            const element = document.getElementById(id);
            if (element) ensureFlipValue(element);
        });

        updateFlipClock();
        if (targetTime && examStatus !== 'expired') {
            timerInterval = setInterval(() => {
                remainingSeconds = Math.max(0, remainingSeconds - 1);
                updateFlipClock();
            }, 1000);
        }

        // Start instruction rotation
        instructionInterval = setInterval(rotateInstructions, 8000);

        // If exam is ongoing and no password required, enable button directly
        if (examStatus === 'ongoing' && !requiresPassword) {
            const attemptBtn = document.getElementById('attemptBtn');
            if (attemptBtn) {
                attemptBtn.disabled = false;
                attemptBtn.innerHTML = '<i class="fas fa-play"></i> Attempt Quiz Now';
                attemptBtn.style.background = 'linear-gradient(135deg, #10b981, #059669)';
            }
        }

        // Show password modal if needed (already handled by PHP)
        if (requiresPassword && !
            <?php echo isset($_SESSION['exam_auth_' . $examId]) && $_SESSION['exam_auth_' . $examId] ? 'true' : 'false'; ?>
            ) {
            showPasswordModal();
        }
    }

    init();
    </script>
</body>

</html>
