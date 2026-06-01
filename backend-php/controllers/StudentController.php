<?php
/**
 * Student Controller
 */

class StudentController {
    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    /**
     * Get all students (admin/lecturer only)
     */
    public function index() {
        $user = authenticate();

        try {
            $stmt = $this->db->prepare("
                SELECT s.*, u.name, u.email, u.isActive
                FROM students s
                JOIN users u ON s.userId = u.id
                ORDER BY u.name
            ");
            $stmt->execute();
            $students = $stmt->fetchAll();

            successResponse($students);
        } catch (PDOException $e) {
            errorResponse('Failed to fetch students: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get student profile
     */
    public function profile() {
        $user = authenticate();

        try {
            $stmt = $this->db->prepare("
                SELECT s.*, u.name, u.email, u.avatar
                FROM students s
                JOIN users u ON s.userId = u.id
                WHERE s.userId = ?
            ");
            $stmt->execute([$user->userId]);
            $student = $stmt->fetch();

            if (!$student) {
                errorResponse('Student not found', 404);
            }

            // Get enrolled courses
            $stmt = $this->db->prepare("
                SELECT c.*, sc.enrolledAt
                FROM student_courses sc
                JOIN courses c ON sc.courseId = c.id
                WHERE sc.studentId = ?
            ");
            $stmt->execute([$student['id']]);
            $student['courses'] = $stmt->fetchAll();

            successResponse($student);
        } catch (PDOException $e) {
            errorResponse('Failed to fetch profile: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get student's courses
     */
    public function courses() {
        $user = authenticate();

        try {
            $stmt = $this->db->prepare("
                SELECT s.id as student_id, c.*
                FROM student_courses sc
                JOIN students s ON sc.studentId = s.id
                JOIN courses c ON sc.courseId = c.id
                WHERE s.userId = ?
            ");
            $stmt->execute([$user->userId]);
            $courses = $stmt->fetchAll();

            successResponse($courses);
        } catch (PDOException $e) {
            errorResponse('Failed to fetch courses: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Enroll student in course
     */
    public function enroll() {
        $user = authorize(['ADMIN', 'LECTURER']);
        $input = $_REQUEST;

        if (empty($input['studentId']) || empty($input['courseId'])) {
            errorResponse('Student ID and course ID are required', 400);
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO student_courses (studentId, courseId, enrolledAt)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE enrolledAt = enrolledAt
            ");
            $stmt->execute([$input['studentId'], $input['courseId']]);

            successResponse(null, 'Student enrolled successfully');
        } catch (PDOException $e) {
            errorResponse('Failed to enroll student: ' . $e->getMessage(), 500);
        }
    }
}
