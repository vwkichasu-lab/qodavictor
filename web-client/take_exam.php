<?php
session_start();

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'STUDENT') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../backend-php/config/database.php';

$examId = $_GET['exam_id'] ?? '';
$password = $_POST['exam_password'] ?? '';
$error = '';

if ($examId) {
    global $pdo;
    
    // Get exam details and check enrollment
    $stmt = $pdo->prepare("
        SELECT e.*, COUNT(ce.id) as is_enrolled
        FROM exams e
        LEFT JOIN course_enrollments ce ON e.course_code = ce.course_code 
            AND ce.student_id = ? 
            AND ce.lecturer_id = e.lecturer_id
        WHERE e.id = ? AND e.published = 1
        GROUP BY e.id
    ");
    $stmt->execute([$_SESSION['user_id'], $examId]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$exam) {
        die("Exam not found or not published.");
    }
    
    // Check if student is enrolled in this course
    if ($exam['is_enrolled'] == 0) {
        die("You are not enrolled in this course. You cannot access this exam.");
    }
    
    // Check if password is required and not yet verified
    $passwordVerified = $_SESSION['exam_password_verified_' . $examId] ?? false;
    
    if ($exam['require_password'] && !$passwordVerified) {
        // Show password form if password not provided or incorrect
        if ($password) {
            if (password_verify($password, $exam['exam_password'])) {
                $_SESSION['exam_password_verified_' . $examId] = true;
                $passwordVerified = true;
            } else {
                $error = "❌ Incorrect password. Please try again.";
            }
        }
        
        if (!$passwordVerified) {
            ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Password Required - Qoda</title>
    <link rel="icon" type="image/png" href="../assets/qoda-logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    body {
        font-family: 'Inter', system-ui, sans-serif;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0;
        padding: 20px;
    }

    .password-container {
        background: white;
        border-radius: 20px;
        padding: 40px;
        max-width: 450px;
        width: 100%;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        text-align: center;
    }

    .exam-icon {
        font-size: 64px;
        color: #667eea;
        margin-bottom: 20px;
    }

    h2 {
        color: #333;
        margin-bottom: 10px;
    }

    .exam-title {
        color: #667eea;
        font-size: 18px;
        margin-bottom: 30px;
        padding: 10px;
        background: #f0f0ff;
        border-radius: 10px;
    }

    .input-group {
        margin-bottom: 20px;
        text-align: left;
    }

    label {
        display: block;
        margin-bottom: 8px;
        color: #555;
        font-weight: 600;
    }

    input {
        width: 100%;
        padding: 12px 15px;
        border: 2px solid #e0e0e0;
        border-radius: 10px;
        font-size: 16px;
        transition: all 0.3s;
    }

    input:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    button {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        padding: 12px 30px;
        border-radius: 10px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        width: 100%;
        transition: transform 0.2s;
    }

    button:hover {
        transform: translateY(-2px);
    }

    .error {
        background: #fee;
        color: #c33;
        padding: 10px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-size: 14px;
    }

    .info {
        background: #e3f2fd;
        color: #1976d2;
        padding: 10px;
        border-radius: 8px;
        margin-top: 20px;
        font-size: 13px;
    }
    </style>
</head>

<body>
    <div class="password-container">
        <div class="exam-icon">
            <i class="fas fa-lock"></i>
        </div>
        <h2>Exam Password Required</h2>
        <div class="exam-title">
            <i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($exam['title']); ?>
        </div>

        <?php if ($error): ?>
        <div class="error">
            <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="exam_id" value="<?php echo htmlspecialchars($examId); ?>">
            <div class="input-group">
                <label><i class="fas fa-key"></i> Enter Exam Password</label>
                <input type="password" name="exam_password" placeholder="Enter the exam password" required autofocus>
            </div>
            <button type="submit">
                <i class="fas fa-unlock-alt"></i> Verify & Start Exam
            </button>
        </form>

        <div class="info">
            <i class="fas fa-info-circle"></i> This exam is password protected.
            Please enter the password provided by your lecturer.
        </div>
    </div>
</body>

</html>
<?php
            exit;
        }
    }
    
    // If we get here, student is enrolled and password is verified (if required)
    // Proceed with the actual exam taking interface
    ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Taking Exam: <?php echo htmlspecialchars($exam['title']); ?> - Qoda</title>
    <link rel="icon" type="image/png" href="../assets/qoda-logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    body {
        font-family: 'Inter', system-ui, sans-serif;
        background: #f5f5f5;
        margin: 0;
        padding: 20px;
    }

    .exam-container {
        max-width: 900px;
        margin: 0 auto;
        background: white;
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
    }

    .exam-header {
        border-bottom: 2px solid #e0e0e0;
        padding-bottom: 20px;
        margin-bottom: 20px;
    }

    .exam-title {
        font-size: 24px;
        font-weight: 700;
        color: #333;
    }

    .exam-info {
        display: flex;
        gap: 20px;
        margin-top: 10px;
        color: #666;
        font-size: 14px;
    }

    .question {
        background: #f9f9f9;
        padding: 20px;
        margin-bottom: 20px;
        border-radius: 10px;
        border-left: 4px solid #667eea;
    }

    .timer {
        position: fixed;
        top: 20px;
        right: 20px;
        background: #667eea;
        color: white;
        padding: 10px 20px;
        border-radius: 50px;
        font-weight: 600;
        font-size: 18px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }

    button[type="submit"] {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        padding: 15px 30px;
        border-radius: 10px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        width: 100%;
        margin-top: 20px;
    }
    </style>
</head>

<body>
    <div class="timer" id="timer">
        <i class="fas fa-clock"></i> <span id="timeRemaining">--:--</span>
    </div>

    <div class="exam-container">
        <div class="exam-header">
            <div class="exam-title">
                <i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($exam['title']); ?>
            </div>
            <div class="exam-info">
                <span><i class="fas fa-code-branch"></i> <?php echo htmlspecialchars($exam['course_code']); ?></span>
                <span><i class="fas fa-hourglass-half"></i> <?php echo $exam['duration_minutes']; ?> minutes</span>
            </div>
            <div class="exam-instructions"
                style="margin-top: 15px; padding: 15px; background: #f0f0ff; border-radius: 10px;">
                <?php echo nl2br(htmlspecialchars($exam['instructions'])); ?>
            </div>
        </div>

        <form method="POST" action="submit_exam.php">
            <input type="hidden" name="exam_id" value="<?php echo htmlspecialchars($examId); ?>">

            <?php
                // Load exam questions here
                // This would come from your exam_questions table
                ?>

            <button type="submit">
                <i class="fas fa-paper-plane"></i> Submit Exam
            </button>
        </form>
    </div>

    <script>
    // Timer functionality
    let timeLeft = <?php echo $exam['duration_minutes'] * 60; ?>; // in seconds
    const timerDisplay = document.getElementById('timeRemaining');

    function updateTimer() {
        const minutes = Math.floor(timeLeft / 60);
        const seconds = timeLeft % 60;
        timerDisplay.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;

        if (timeLeft <= 0) {
            document.querySelector('form').submit();
        }
        timeLeft--;
    }

    setInterval(updateTimer, 1000);
    updateTimer();
    </script>
</body>

</html>
<?php
} else {
    die("No exam specified.");
}
?>
