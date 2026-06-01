<?php
/**
 * Health Check Controller
 */

class HealthController {
    public function index() {
        $status = [
            'status' => 'OK',
            'timestamp' => date('c'),
            'version' => '2.0.0'
        ];

        // Check database connection
        try {
            $db = getDB();
            $db->query('SELECT 1');
            $status['database'] = 'connected';
        } catch (Exception $e) {
            $status['database'] = 'disconnected';
            $status['database_error'] = $e->getMessage();
        }

        successResponse($status);
    }
}
