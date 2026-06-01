<?php
/**
 * Security Controller
 */

class SecurityController {
    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    /**
     * Get security events
     */
    public function events() {
        $user = authenticate();

        try {
            $stmt = $this->db->prepare("
                SELECT se.*, u.name as user_name, e.title as exam_title
                FROM security_events se
                LEFT JOIN users u ON se.userId = u.id
                LEFT JOIN exams e ON se.examId = e.id
                ORDER BY se.createdAt DESC
                LIMIT 100
            ");
            $stmt->execute();
            $events = $stmt->fetchAll();

            successResponse($events);
        } catch (PDOException $e) {
            errorResponse('Failed to fetch events: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Log security event
     */
    public function logEvent() {
        $user = authenticate();
        $input = $_REQUEST;

        if (empty($input['eventType'])) {
            errorResponse('Event type is required', 400);
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO security_events (studentId, userId, examId, eventType, severity, description, metadata, createdAt)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $input['studentId'] ?? null,
                $user->userId,
                $input['examId'] ?? null,
                $input['eventType'],
                $input['severity'] ?? 'LOW',
                $input['description'] ?? '',
                json_encode($input['metadata'] ?? null)
            ]);

            successResponse(null, 'Event logged');
        } catch (PDOException $e) {
            errorResponse('Failed to log event: ' . $e->getMessage(), 500);
        }
    }
}
