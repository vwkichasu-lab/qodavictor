CREATE TABLE IF NOT EXISTS screen_share_sessions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    exam_id INT NOT NULL,
    session_id VARCHAR(191) NOT NULL,
    is_sharing TINYINT(1) NOT NULL DEFAULT 0,
    started_at DATETIME NULL,
    stopped_at DATETIME NULL,
    last_updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_screen_share_session (student_id, exam_id, session_id),
    INDEX idx_exam_sharing (exam_id, is_sharing, last_updated),
    INDEX idx_student_exam (student_id, exam_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

