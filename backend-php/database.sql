-- ==============================================
-- CREATE DATABASE AND SELECT IT
-- ==============================================
CREATE DATABASE IF NOT EXISTS qoda_db;
USE qoda_db;

-- ==============================================
-- DROP EXISTING TABLES IF THEY EXIST
-- ==============================================
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS proctor_commands;
DROP TABLE IF EXISTS screen_captures;
DROP TABLE IF EXISTS security_events;
DROP TABLE IF EXISTS student_answers;
DROP TABLE IF EXISTS answers;
DROP TABLE IF EXISTS results;
DROP TABLE IF EXISTS submissions;
DROP TABLE IF EXISTS submission_question_scores;
DROP TABLE IF EXISTS auto_grading_results;
DROP TABLE IF EXISTS activity_log;
DROP TABLE IF EXISTS question_grading_criteria;
DROP TABLE IF EXISTS user_sessions;
DROP TABLE IF EXISTS suspicious_logs;
DROP TABLE IF EXISTS exam_governance_settings;
DROP TABLE IF EXISTS security_settings;
DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS exam_class_access;
DROP TABLE IF EXISTS exam_visibility;
DROP TABLE IF EXISTS course_enrollments;
DROP TABLE IF EXISTS exam_answers;
DROP TABLE IF EXISTS ai_grading_cache;
DROP TABLE IF EXISTS exam_question_grading;
DROP TABLE IF EXISTS exam_submissions;
DROP TABLE IF EXISTS questions;
DROP TABLE IF EXISTS exam_questions;
DROP TABLE IF EXISTS exams;
DROP TABLE IF EXISTS course_pbit102_students;
DROP TABLE IF EXISTS student_courses;
DROP TABLE IF EXISTS courses;
DROP TABLE IF EXISTS admins;
DROP TABLE IF EXISTS lecturers;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ==============================================
-- 1. USERS TABLE (matches all your code)
-- ==============================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(100) NULL UNIQUE,
    userId VARCHAR(100) NULL UNIQUE,
    full_name VARCHAR(255) NOT NULL DEFAULT '',
    fullName VARCHAR(255) NOT NULL DEFAULT '',
    name VARCHAR(255) NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('ADMIN', 'LECTURER', 'STUDENT') NOT NULL DEFAULT 'STUDENT',
    profile_pic VARCHAR(255) NULL,
    profile_picture VARCHAR(255) NULL,
    avatar VARCHAR(255) NULL,
    staff_id VARCHAR(50) NULL,
    department VARCHAR(100) NULL,
    faculty VARCHAR(100) NULL,
    levels_taught VARCHAR(255) NULL,
    classes TEXT NULL,
    courses TEXT NULL,
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    isActive TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_status (status)
);

-- ==============================================
-- 2. STUDENTS TABLE
-- ==============================================
CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    userId INT NULL,
    student_id VARCHAR(50) NULL UNIQUE,
    studentId VARCHAR(50) NULL UNIQUE,
    full_name VARCHAR(255) NOT NULL DEFAULT '',
    fullName VARCHAR(255) NOT NULL DEFAULT '',
    email VARCHAR(255) NULL,
    password VARCHAR(255) NOT NULL DEFAULT '',
    level VARCHAR(20) DEFAULT '100',
    programme VARCHAR(100) NULL,
    department VARCHAR(100) NULL,
    faculty VARCHAR(100) NULL,
    school_name VARCHAR(100) NULL,
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    matric_number VARCHAR(50) NULL,
    enrollment_year YEAR NULL,
    academic_year VARCHAR(20) NULL,
    academicYear VARCHAR(20) NULL,
    date_of_birth DATE NULL,
    contact VARCHAR(50) NULL,
    gender VARCHAR(10) NULL,
    profile_pic VARCHAR(255) NULL,
    lecturer_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (lecturer_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_student_id (student_id),
    INDEX idx_studentId (studentId),
    INDEX idx_level (level),
    INDEX idx_status (status),
    INDEX idx_lecturer (lecturer_id)
);

-- ==============================================
-- 3. EXAMS TABLE (complete from your code)
-- ==============================================
CREATE TABLE exams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id VARCHAR(50) UNIQUE,
    title VARCHAR(255) NOT NULL DEFAULT '',
    course_code VARCHAR(50) NOT NULL DEFAULT '',
    description TEXT NULL,
    instructions TEXT NOT NULL,
    marking_scheme TEXT NULL,
    duration_minutes INT NOT NULL DEFAULT 180,
    durationMins INT NULL,
    start_datetime DATETIME NULL,
    startAt DATETIME NULL,
    end_datetime DATETIME NULL,
    endAt DATETIME NULL,
    questions LONGTEXT NULL,
    questions_to_answer INT NOT NULL DEFAULT 0,
    shuffle_enabled TINYINT(1) NOT NULL DEFAULT 0,
    grading_mode VARCHAR(20) NOT NULL DEFAULT 'auto',
    published TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(50) DEFAULT 'draft',
    created_by INT NULL,
    lecturer_id INT NULL,
    lecturerId INT NULL,
    courseId INT NULL,
    school_name VARCHAR(255) DEFAULT '',
    faculty_name VARCHAR(255) DEFAULT '',
    department VARCHAR(255) DEFAULT '',
    semester VARCHAR(50) DEFAULT '',
    academicYear VARCHAR(20) NULL,
    exam_type VARCHAR(50) DEFAULT '',
    school_type VARCHAR(50) DEFAULT '',
    level VARCHAR(10) DEFAULT '',
    exam_password VARCHAR(255) NULL,
    exam_code VARCHAR(50) DEFAULT '',
    require_password TINYINT(1) DEFAULT 0,
    auto_grading_enabled TINYINT(1) DEFAULT 0,
    partial_grading_enabled TINYINT(1) DEFAULT 0,
    show_correct_answers TINYINT(1) DEFAULT 0,
    allow_review TINYINT(1) DEFAULT 1,
    total_marks INT DEFAULT 0,
    max_attempts INT DEFAULT 1,
    time_between_attempts INT NULL,
    passing_score DECIMAL(5,2) DEFAULT 50.00,
    show_results TINYINT(1) DEFAULT 1,
    results_published TINYINT(1) DEFAULT 0,
    resultsPublished TINYINT(1) DEFAULT 0,
    results_published_at DATETIME NULL,
    published_at DATETIME NULL,
    publishedAt DATETIME NULL,
    draft_saved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (lecturer_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_course_code (course_code),
    INDEX idx_published (published),
    INDEX idx_status (status),
    INDEX idx_lecturer (lecturer_id),
    INDEX idx_exam_code (exam_code),
    INDEX idx_start_datetime (start_datetime)
);

-- ==============================================
-- 4. EXAM_QUESTIONS TABLE
-- ==============================================
CREATE TABLE exam_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    question_id VARCHAR(50) NULL,
    question_type ENUM('coding', 'essay', 'multiple_choice', 'true_false', 'fill_blank', 'matching') DEFAULT 'coding',
    title VARCHAR(255) NULL,
    text TEXT NOT NULL,
    prompt TEXT NULL,
    marks INT DEFAULT 1,
    difficulty ENUM('easy', 'medium', 'hard') DEFAULT 'medium',
    language VARCHAR(50) DEFAULT 'python',
    starter_code TEXT NULL,
    test_cases JSON NULL,
    expected_output TEXT NULL,
    has_sub_questions TINYINT(1) DEFAULT 0,
    sub_questions JSON NULL,
    grading_mode ENUM('auto', 'manual', 'hybrid') DEFAULT 'auto',
    compulsory TINYINT(1) DEFAULT 0,
    order_number INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    INDEX idx_exam (exam_id),
    INDEX idx_order (order_number),
    INDEX idx_type (question_type)
);

-- ==============================================
-- 5. EXAM_SUBMISSIONS TABLE
-- ==============================================
CREATE TABLE exam_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_id INT NOT NULL,
    student_name VARCHAR(255) NULL,
    student_identifier VARCHAR(100) NULL,
    attempt_number INT DEFAULT 1,
    answers LONGTEXT NULL,
    answers_json JSON NULL,
    total_score DECIMAL(5,2) DEFAULT 0,
    total_marks INT DEFAULT 0,
    percentage DECIMAL(5,2) DEFAULT 0,
    auto_score DECIMAL(5,2) DEFAULT 0,
    manual_score DECIMAL(5,2) DEFAULT 0,
    status VARCHAR(50) DEFAULT 'in_progress',
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    device_info JSON NULL,
    started_at DATETIME NULL,
    submitted_at DATETIME NULL,
    submittedAt DATETIME NULL,
    submission_folder VARCHAR(255) NULL,
    time_spent_seconds INT NULL,
    submitted TINYINT(1) DEFAULT 0,
    graded_at DATETIME NULL,
    graded_by INT NULL,
    manual_feedback TEXT NULL,
    execution_results JSON NULL,
    ai_feedback MEDIUMTEXT NULL,
    auto_graded_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    INDEX idx_exam (exam_id),
    INDEX idx_student (student_id),
    INDEX idx_status (status),
    INDEX idx_submitted_at (submitted_at),
    INDEX idx_student_exam_submitted (student_id, exam_id, submitted_at),
    UNIQUE KEY uk_exam_student_attempt (exam_id, student_id, attempt_number)
);

CREATE TABLE exam_question_grading (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    question_index INT NOT NULL,
    marking_scheme TEXT NULL,
    test_cases JSON NULL,
    ai_score DECIMAL(10,2) DEFAULT 0,
    ai_feedback TEXT NULL,
    manual_score DECIMAL(10,2) NULL,
    manual_feedback TEXT NULL,
    score_source VARCHAR(20) DEFAULT 'ai',
    graded_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (submission_id) REFERENCES exam_submissions(id) ON DELETE CASCADE,
    UNIQUE KEY uq_submission_question (submission_id, question_index),
    INDEX idx_submission (submission_id)
);

CREATE TABLE ai_grading_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consistency_hash CHAR(32) NOT NULL UNIQUE,
    score DECIMAL(10,2) NOT NULL DEFAULT 0,
    feedback TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_consistency_hash (consistency_hash)
);

-- ==============================================
-- 6. EXAM_ANSWERS TABLE
-- ==============================================
CREATE TABLE exam_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    exam_id INT NULL,
    question_id INT NOT NULL,
    question_ref VARCHAR(50) NULL,
    answer TEXT NULL,
    answer_text TEXT NULL,
    code TEXT NULL,
    files JSON NULL,
    auto_score DECIMAL(5,2) DEFAULT 0,
    manual_score DECIMAL(5,2) DEFAULT 0,
    total_score DECIMAL(5,2) DEFAULT 0,
    feedback TEXT NULL,
    marked_by INT NULL,
    marked_at DATETIME NULL,
    graded_by INT NULL,
    graded_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (submission_id) REFERENCES exam_submissions(id) ON DELETE CASCADE,
    FOREIGN KEY (graded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_submission (submission_id),
    INDEX idx_question (question_id)
);

-- ==============================================
-- 7. COURSE_ENROLLMENTS TABLE
-- ==============================================
CREATE TABLE course_enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    course_code VARCHAR(50) NOT NULL,
    course_name VARCHAR(200) NOT NULL,
    lecturer_id INT NOT NULL,
    enrollment_date DATE DEFAULT (CURRENT_DATE),
    enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    semester VARCHAR(20) NULL,
    academic_year VARCHAR(20) NULL,
    status ENUM('active', 'completed', 'dropped') DEFAULT 'active',
    grade VARCHAR(5) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (lecturer_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_student (student_id),
    INDEX idx_course_code (course_code),
    INDEX idx_lecturer (lecturer_id),
    UNIQUE KEY uk_course_student (course_code, student_id)
);

-- ==============================================
-- 8. EXAM_VISIBILITY TABLE
-- ==============================================
CREATE TABLE exam_visibility (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_id INT NOT NULL,
    visible TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    UNIQUE KEY uk_exam_student_visibility (exam_id, student_id),
    INDEX idx_visibility_student (student_id, visible)
);

-- ==============================================
-- 9. EXAM_CLASS_ACCESS TABLE
-- ==============================================
CREATE TABLE exam_class_access (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    class_code VARCHAR(50) NOT NULL,
    class_name VARCHAR(255) NULL,
    class_type VARCHAR(50) NULL,
    access_granted TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    INDEX idx_exam (exam_id),
    INDEX idx_class_code (class_code)
);

-- ==============================================
-- 10. AUDIT_LOGS TABLE
-- ==============================================
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_id INT NULL,
    userId INT NULL,
    actor_role VARCHAR(50) NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(50) NULL,
    entityType VARCHAR(50) NULL,
    target_id INT NULL,
    entityId INT NULL,
    description TEXT NULL,
    old_data JSON NULL,
    new_data JSON NULL,
    ip_address VARCHAR(45) NULL,
    ipAddress VARCHAR(45) NULL,
    user_agent TEXT NULL,
    userAgent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_actor (actor_id),
    INDEX idx_action (action),
    INDEX idx_created (created_at)
);

-- ==============================================
-- 11. SYSTEM_SETTINGS TABLE
-- ==============================================
CREATE TABLE system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    setting_type ENUM('text', 'number', 'boolean', 'json') DEFAULT 'text',
    description TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (setting_key)
);

-- ==============================================
-- 12. SECURITY_SETTINGS TABLE
-- ==============================================
CREATE TABLE security_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_name VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    description TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (setting_name)
);

-- ==============================================
-- 13. EXAM_GOVERNANCE_SETTINGS TABLE
-- ==============================================
CREATE TABLE exam_governance_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_name VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    description TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (setting_name)
);

-- ==============================================
-- 14. SUSPICIOUS_LOGS TABLE
-- ==============================================
CREATE TABLE suspicious_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    exam_id INT NOT NULL,
    session_id VARCHAR(255) NULL,
    event_type VARCHAR(50) NOT NULL,
    details TEXT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    resolved TINYINT(1) DEFAULT 0,
    resolved_by INT NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    INDEX idx_student (student_id),
    INDEX idx_exam (exam_id),
    INDEX idx_severity (severity),
    INDEX idx_created (created_at)
);

-- ==============================================
-- 15. USER_SESSIONS TABLE
-- ==============================================
CREATE TABLE user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token VARCHAR(255) NOT NULL UNIQUE,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    is_active TINYINT(1) DEFAULT 1,
    last_activity DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (session_token),
    INDEX idx_user (user_id),
    INDEX idx_active (is_active)
);

-- ==============================================
-- 16. QUESTION_GRADING_CRITERIA TABLE
-- ==============================================
CREATE TABLE question_grading_criteria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    exam_id INT NULL,
    criteria TEXT NOT NULL,
    max_points INT NOT NULL,
    keywords JSON NULL,
    rubric JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE,
    INDEX idx_question (question_id)
);

-- ==============================================
-- 17. ACTIVITY_LOG TABLE
-- ==============================================
CREATE TABLE activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    userId INT NULL,
    userRole VARCHAR(50) NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entityType VARCHAR(50) NULL,
    entity_id INT NULL,
    entityId INT NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    ipAddress VARCHAR(45) NULL,
    user_agent TEXT NULL,
    userAgent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_created (created_at)
);

-- ==============================================
-- 18. SUBMISSIONS TABLE (for compatibility)
-- ==============================================
CREATE TABLE submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    examId INT NULL,
    student_id INT NOT NULL,
    studentId INT NULL,
    answers LONGTEXT NULL,
    score DECIMAL(5,2) DEFAULT 0,
    totalScore DECIMAL(10,2) DEFAULT 0,
    maxScore DECIMAL(10,2) DEFAULT 0,
    percentage DECIMAL(5,2) DEFAULT 0,
    auto_score DECIMAL(5,2) DEFAULT 0,
    manual_score DECIMAL(5,2) DEFAULT 0,
    status VARCHAR(50) DEFAULT 'PENDING',
    submitted TINYINT(1) DEFAULT 0,
    startedAt DATETIME NULL,
    submitted_at DATETIME NULL,
    submittedAt DATETIME NULL,
    submission_folder VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    INDEX idx_exam (exam_id),
    INDEX idx_student (student_id),
    INDEX idx_status (status)
);

-- ==============================================
-- 19. RESULTS TABLE
-- ==============================================
CREATE TABLE results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    examId INT NULL,
    student_id INT NOT NULL,
    studentId INT NULL,
    submission_id INT NULL,
    submissionId INT NULL,
    total_score DECIMAL(5,2) NOT NULL,
    totalScore DECIMAL(10,2) DEFAULT 0,
    maxScore DECIMAL(10,2) DEFAULT 0,
    percentage DECIMAL(5,2) NOT NULL,
    grade VARCHAR(5) NULL,
    grade_point DECIMAL(3,2) NULL,
    published TINYINT(1) DEFAULT 0,
    publishedBy INT NULL,
    published_at DATETIME NULL,
    publishedAt DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (submission_id) REFERENCES exam_submissions(id) ON DELETE SET NULL,
    INDEX idx_exam (exam_id),
    INDEX idx_student (student_id),
    INDEX idx_published (published)
);

-- ==============================================
-- 20. NOTIFICATIONS TABLE
-- ==============================================
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    link VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_read (is_read)
);

-- ==============================================
-- 21. COMPATIBILITY TABLES FOR LEGACY API ROUTES
-- ==============================================
CREATE TABLE courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    lecturerId INT NULL,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_lecturerId (lecturerId)
);

CREATE TABLE lecturers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userId INT NOT NULL,
    lecturerId VARCHAR(100) NULL UNIQUE,
    department VARCHAR(100) NULL,
    title VARCHAR(100) NULL,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (userId) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uk_lecturer_user (userId),
    INDEX idx_userId (userId)
);

CREATE TABLE admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userId INT NOT NULL,
    adminId VARCHAR(100) NULL UNIQUE,
    superAdmin TINYINT(1) DEFAULT 0,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (userId) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uk_admin_user (userId),
    INDEX idx_userId (userId)
);

CREATE TABLE student_courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    studentId INT NOT NULL,
    courseId INT NOT NULL,
    enrolledAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_student_course (studentId, courseId),
    INDEX idx_studentId (studentId),
    INDEX idx_courseId (courseId)
);

CREATE TABLE questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    examId INT NOT NULL,
    type VARCHAR(50) DEFAULT 'CODING',
    text TEXT NOT NULL,
    marks DECIMAL(10,2) DEFAULT 1,
    `order` INT DEFAULT 0,
    imageUrl VARCHAR(255) NULL,
    options JSON NULL,
    correctAnswer JSON NULL,
    starterCode TEXT NULL,
    testCases JSON NULL,
    rubric JSON NULL,
    keywords JSON NULL,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (examId) REFERENCES exams(id) ON DELETE CASCADE,
    INDEX idx_examId (examId),
    INDEX idx_order (`order`)
);

CREATE TABLE answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submissionId INT NOT NULL,
    questionId INT NOT NULL,
    answerText LONGTEXT NULL,
    answerData JSON NULL,
    score DECIMAL(10,2) DEFAULT 0,
    maxScore DECIMAL(10,2) DEFAULT 0,
    feedback TEXT NULL,
    autoGraded TINYINT(1) DEFAULT 0,
    gradedBy INT NULL,
    gradedAt DATETIME NULL,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_submissionId (submissionId),
    INDEX idx_questionId (questionId),
    UNIQUE KEY uk_submission_question (submissionId, questionId)
);

CREATE TABLE student_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    question_id INT NOT NULL,
    answer LONGTEXT NULL,
    auto_score DECIMAL(10,2) DEFAULT 0,
    manual_score DECIMAL(10,2) DEFAULT 0,
    feedback TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_submission (submission_id),
    INDEX idx_question (question_id)
);

CREATE TABLE security_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    studentId INT NULL,
    userId INT NULL,
    examId INT NULL,
    eventType VARCHAR(100) NOT NULL,
    severity VARCHAR(20) DEFAULT 'LOW',
    description TEXT NULL,
    metadata JSON NULL,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_userId (userId),
    INDEX idx_examId (examId),
    INDEX idx_eventType (eventType),
    INDEX idx_createdAt (createdAt)
);

CREATE TABLE screen_captures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL DEFAULT '',
    image_data LONGTEXT NULL,
    capture_type VARCHAR(30) NOT NULL DEFAULT 'live',
    notes TEXT NULL,
    captured_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_exam_student (exam_id, student_id),
    INDEX idx_captured_at (captured_at)
);

CREATE TABLE proctor_commands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_id INT NOT NULL,
    command_type ENUM('warning', 'lock', 'unlock') NOT NULL,
    message TEXT NULL,
    handled TINYINT(1) DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    handled_at DATETIME NULL,
    INDEX idx_exam_student_handled (exam_id, student_id, handled),
    INDEX idx_created_at (created_at)
);

CREATE TABLE submission_question_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    question_id VARCHAR(50) NOT NULL,
    score DECIMAL(10,2) DEFAULT 0,
    feedback TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_submission_question_score (submission_id, question_id),
    INDEX idx_submission (submission_id),
    FOREIGN KEY (submission_id) REFERENCES exam_submissions(id) ON DELETE CASCADE
);

CREATE TABLE auto_grading_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    question_id INT NULL,
    score DECIMAL(10,2) DEFAULT 0,
    max_score DECIMAL(10,2) DEFAULT 0,
    feedback TEXT NULL,
    raw_result JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_submission (submission_id)
);

-- ==============================================
-- INSERT DEFAULT LECTURER ACCOUNT
-- ==============================================

-- Default credentials:
-- Lecturer: PULC/IT/00001 / PULC/IT/00001

INSERT INTO users (
    user_id,
    userId,
    full_name,
    fullName,
    name,
    email,
    password,
    role,
    status,
    staff_id,
    department,
    faculty
) VALUES (
    'PULC/IT/00001',
    'PULC/IT/00001',
    'Demo Lecturer',
    'Demo Lecturer',
    'Demo Lecturer',
    'lecturer@qoda.test',
    '$2y$12$hKTqABtUqjpFLWIOpiQeeeNrf99Kp44Ycrb1X5Pwb3vtatVZ5kMde',
    'LECTURER',
    'Active',
    'PULC/IT/00001',
    'Computer Science',
    'Technology'
);

INSERT INTO lecturers (userId, lecturerId, department, title)
SELECT id, user_id, department, 'Lecturer'
FROM users
WHERE user_id = 'PULC/IT/00001';

-- Default student credentials:
-- Student: PUSE/22210033 / PUSE/22210033

INSERT INTO students (
    student_id,
    studentId,
    full_name,
    fullName,
    email,
    password,
    level,
    programme,
    department,
    faculty,
    status,
    lecturer_id
)
SELECT
    'PUSE/22210033',
    'PUSE/22210033',
    'Demo Student',
    'Demo Student',
    'student@qoda.test',
    '$2y$10$.qOvckQyasCwO.B0fmRo5eZp/XnyOXlp2BPzTuzI7ycScZ/edHozC',
    '200',
    'Information Technology',
    'IT',
    'Technology',
    'Active',
    id
FROM users
WHERE user_id = 'PULC/IT/00001';

INSERT INTO course_enrollments (
    student_id,
    course_code,
    course_name,
    lecturer_id,
    semester,
    academic_year
)
SELECT
    s.id,
    'PBIT102',
    'Programming Fundamentals',
    u.id,
    'First Semester',
    '2024/2025'
FROM students s
JOIN users u ON u.user_id = 'PULC/IT/00001'
WHERE s.student_id = 'PUSE/22210033';

-- ==============================================
-- DEFAULT SYSTEM SETTINGS
-- ==============================================
INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES
('system_name', 'QODA Examination System', 'text', 'System name'),
('institution_name', 'QODA University', 'text', 'Institution name'),
('academic_year', '2024/2025', 'text', 'Current academic year'),
('semester', 'First Semester', 'text', 'Current semester'),
('timezone', 'Africa/Lagos', 'text', 'System timezone'),
('maintenance_mode', '0', 'boolean', 'Maintenance mode flag'),
('default_pass_mark', '50', 'number', 'Default passing score'),
('session_timeout', '30', 'number', 'Session timeout in minutes'),
('live_proctoring_enabled', '1', 'boolean', 'Enable live proctoring');

-- ==============================================
-- DEFAULT SECURITY SETTINGS
-- ==============================================
INSERT INTO security_settings (setting_name, setting_value, description) VALUES
('two_factor_auth', '0', 'Enable two-factor authentication for admin accounts'),
('session_timeout', '30', 'Session timeout in minutes'),
('max_login_attempts', '5', 'Maximum failed login attempts before lockout'),
('lockout_duration', '15', 'Lockout duration in minutes after max attempts'),
('password_expiry_days', '90', 'Password expiry in days'),
('require_strong_password', '1', 'Require strong passwords'),
('ip_whitelist_enabled', '0', 'Restrict access to specific IP addresses'),
('audit_log_retention_days', '365', 'Audit log retention in days'),
('suspicious_activity_alerts', '1', 'Send alerts for suspicious activity');

-- ==============================================
-- DEFAULT EXAM GOVERNANCE SETTINGS
-- ==============================================
INSERT INTO exam_governance_settings (setting_name, setting_value, description) VALUES
('max_attempts', '1', 'Maximum number of exam attempts allowed'),
('time_between_attempts', '24', 'Hours required between attempts'),
('require_approval', '1', 'Require admin approval for retakes'),
('passing_threshold', '50', 'Minimum passing percentage'),
('grace_period', '5', 'Grace period in minutes for late submissions'),
('show_results_immediately', '1', 'Show exam results immediately after submission'),
('allow_review', '1', 'Allow students to review their answers after exam'),
('min_time_before_submit', '5', 'Minimum minutes before allowing submission');

-- ==============================================
-- CREATE INDEXES FOR PERFORMANCE
-- ==============================================
CREATE INDEX idx_exams_published_start ON exams(published, start_datetime);
CREATE INDEX idx_exams_course_lecturer ON exams(course_code, lecturer_id);
CREATE INDEX idx_submissions_exam_status ON exam_submissions(exam_id, status);
CREATE INDEX idx_submissions_student_exam ON exam_submissions(student_id, exam_id);
CREATE INDEX idx_course_enrollments_lecturer ON course_enrollments(lecturer_id);
CREATE INDEX idx_answers_submission_question ON exam_answers(submission_id, question_id);

-- ==============================================
-- DONE
-- ==============================================
SELECT 'Database created successfully!' AS Status;
SELECT COUNT(*) AS LecturerCount FROM users WHERE role = 'LECTURER';

SET @db_name = DATABASE();

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE exams ADD COLUMN end_datetime DATETIME NULL AFTER start_datetime',
        'SELECT "end_datetime already exists"'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'exams'
      AND COLUMN_NAME = 'end_datetime'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE exams ADD COLUMN draft_saved_at DATETIME NULL AFTER publishedAt',
        'SELECT "draft_saved_at already exists"'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'exams'
      AND COLUMN_NAME = 'draft_saved_at'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE exams
SET duration_minutes = 180
WHERE duration_minutes IS NULL OR duration_minutes <= 0;

ALTER TABLE exams
MODIFY duration_minutes INT NOT NULL DEFAULT 180;

UPDATE exams
SET end_datetime = DATE_ADD(start_datetime, INTERVAL duration_minutes MINUTE)
WHERE start_datetime IS NOT NULL
  AND (end_datetime IS NULL OR end_datetime <= start_datetime);

UPDATE exams
SET end_datetime = DATE_ADD(start_datetime, INTERVAL duration_minutes MINUTE)
WHERE start_datetime IS NOT NULL
  AND end_datetime IS NOT NULL
  AND TIMESTAMPDIFF(MINUTE, start_datetime, end_datetime) < duration_minutes;

UPDATE exams
SET status = 'published'
WHERE published = 1
  AND UPPER(status) = 'DRAFT';

UPDATE exams e
SET
    e.start_datetime = DATE_ADD(NOW(), INTERVAL 1 MINUTE),
    e.end_datetime = DATE_ADD(DATE_ADD(NOW(), INTERVAL 1 MINUTE), INTERVAL e.duration_minutes MINUTE)
WHERE e.published = 1
  AND e.start_datetime IS NOT NULL
  AND e.end_datetime <= NOW()
  AND NOT EXISTS (
      SELECT 1
      FROM exam_submissions es
      WHERE es.exam_id = e.id
        AND (
            es.submitted_at IS NOT NULL
            OR COALESCE(es.submitted, 0) = 1
            OR UPPER(es.status) IN ('SUBMITTED', 'GRADED', 'MARKED', 'AUTO_GRADED', 'MANUALLY_GRADED')
        )
  );

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE exam_visibility ADD INDEX idx_visibility_student (student_id, visible)',
        'SELECT "idx_visibility_student already exists"'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'exam_visibility'
      AND INDEX_NAME = 'idx_visibility_student'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE exam_submissions ADD INDEX idx_student_exam_submitted (student_id, exam_id, submitted_at)',
        'SELECT "idx_student_exam_submitted already exists"'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'exam_submissions'
      AND INDEX_NAME = 'idx_student_exam_submitted'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE exam_submissions
SET status = 'IN_PROGRESS', submitted = 0, submittedAt = NULL
WHERE submitted_at IS NULL
  AND UPPER(status) = 'SUBMITTED';


CREATE TABLE IF NOT EXISTS screen_captures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL DEFAULT '',
    image_data LONGTEXT NULL,
    capture_type VARCHAR(30) NOT NULL DEFAULT 'live',
    notes TEXT NULL,
    captured_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_exam_student (exam_id, student_id),
    INDEX idx_captured_at (captured_at)
);

CREATE TABLE IF NOT EXISTS proctor_commands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_id INT NOT NULL,
    command_type ENUM('warning', 'lock', 'unlock') NOT NULL,
    message TEXT NULL,
    handled TINYINT(1) DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    handled_at DATETIME NULL,
    INDEX idx_exam_student_handled (exam_id, student_id, handled),
    INDEX idx_created_at (created_at)
);

ALTER TABLE proctor_commands
MODIFY command_type ENUM('warning', 'lock', 'unlock') NOT NULL;
