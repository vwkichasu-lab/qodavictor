<?php
/**
 * Audit Controller
 */

class AuditController {
    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    /**
     * Get audit logs
     */
    public function index() {
        $user = authenticate();

        try {
            $stmt = $this->db->prepare("
                SELECT al.*, u.name as user_name
                FROM audit_logs al
                LEFT JOIN users u ON al.userId = u.id
                ORDER BY al.createdAt DESC
                LIMIT 100
            ");
            $stmt->execute();
            $logs = $stmt->fetchAll();

            successResponse($logs);
        } catch (PDOException $e) {
            errorResponse('Failed to fetch logs: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Log audit event
     */
    public function log() {
        $user = authenticate();
        $input = $_REQUEST;

        if (empty($input['action'])) {
            errorResponse('Action is required', 400);
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO audit_logs (userId, action, entityType, entityId, details, ipAddress, userAgent, createdAt)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $user->userId,
                $input['action'],
                $input['entityType'] ?? null,
                $input['entityId'] ?? null,
                json_encode($input['details'] ?? null),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            successResponse(null, 'Log recorded');
        } catch (PDOException $e) {
            errorResponse('Failed to log: ' . $e->getMessage(), 500);
        }
    }
}
