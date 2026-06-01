<?php
/**
 * Result Controller
 */

class ResultController {
    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    /**
     * Get results
     */
    public function index() {
        $user = authenticate();

        try {
            if ($user->role === 'STUDENT') {
                $stmt = $this->db->prepare("
                    SELECT r.*, e.title as exam_title, c.code as course_code
                    FROM results r
                    JOIN exams e ON r.examId = e.id
                    JOIN courses c ON e.courseId = c.id
                    WHERE r.studentId = ? AND r.published = true
                    ORDER BY r.createdAt DESC
                ");
                $stmt->execute([$user->userId]);
            } else {
                $stmt = $this->db->prepare("
                    SELECT r.*, e.title as exam_title, c.code as course_code,
                           u.name as student_name, u.userId as student_number
                    FROM results r
                    JOIN exams e ON r.examId = e.id
                    JOIN courses c ON e.courseId = c.id
                    JOIN users u ON r.studentId = u.id
                    ORDER BY r.createdAt DESC
                ");
                $stmt->execute();
            }

            $results = $stmt->fetchAll();
            successResponse($results);
        } catch (PDOException $e) {
            errorResponse('Failed to fetch results: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Release results
     */
    public function release() {
        $user = authorize(['LECTURER', 'ADMIN']);
        $input = $_REQUEST;

        if (empty($input['examId'])) {
            errorResponse('Exam ID is required', 400);
        }

        try {
            $stmt = $this->db->prepare("
                UPDATE results 
                SET published = true, publishedAt = NOW(), publishedBy = ?
                WHERE examId = ?
            ");
            $stmt->execute([$user->userId, $input['examId']]);

            // Also update exam
            $stmt = $this->db->prepare("UPDATE exams SET resultsPublished = true WHERE id = ?");
            $stmt->execute([$input['examId']]);

            successResponse(null, 'Results released successfully');
        } catch (PDOException $e) {
            errorResponse('Failed to release results: ' . $e->getMessage(), 500);
        }
    }
}
