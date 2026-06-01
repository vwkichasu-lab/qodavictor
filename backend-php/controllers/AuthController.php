<?php
/**
 * Authentication Controller
 */

class AuthController {
    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    /**
     * Register a new user
     */
    public function register() {
        $input = $_REQUEST;
        
        $required = ['userId', 'name', 'email', 'password'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                errorResponse("Missing required field: $field", 400);
            }
        }

        $userId = $input['userId'];
        $name = $input['name'];
        $email = $input['email'];
        $password = $input['password'];
        $role = strtoupper($input['role'] ?? 'LECTURER');

        if ($role !== 'LECTURER') {
            errorResponse('Only lecturers can register. Students must be created by a lecturer.', 403);
        }

        try {
            $this->db->beginTransaction();

            // Check if user exists
            $stmt = $this->db->prepare('SELECT id FROM users WHERE "userId" = ? OR email = ?');
            $stmt->execute([$userId, $email]);
            if ($stmt->fetch()) {
                errorResponse('User already exists', 409);
            }

            // Hash password
            $hashedPassword = hashPassword($password);

            // Create user
            $stmt = $this->db->prepare('
                INSERT INTO users ("userId", name, email, password, role, "createdAt", "updatedAt")
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ');
            $stmt->execute([$userId, $name, $email, $hashedPassword, $role]);
            $userIdDb = $this->db->lastInsertId();

            $lecturerId = $input['lecturerId'] ?? $userId;
            $department = $input['department'] ?? '';
            $title = $input['title'] ?? 'Lecturer';

            $stmt = $this->db->prepare('
                INSERT INTO lecturers ("userId", "lecturerId", department, title, "createdAt", "updatedAt")
                VALUES (?, ?, ?, ?, NOW(), NOW())
            ');
            $stmt->execute([$userIdDb, $lecturerId, $department, $title]);

            $this->db->commit();

            successResponse([
                'id' => $userIdDb,
                'userId' => $userId,
                'name' => $name,
                'email' => $email,
                'role' => $role
            ], 'User registered successfully', 201);

        } catch (PDOException $e) {
            $this->db->rollBack();
            errorResponse('Failed to register user: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Login user
     */
    public function login() {
        $input = $_REQUEST;

        if (empty($input['userId']) || empty($input['password'])) {
            errorResponse('userId and password are required', 400);
        }

        $userIdOrEmail = $input['userId'];

        try {
            // Find user
            $stmt = $this->db->prepare('
                SELECT u.*, 
                       s.id as student_id, s.level as student_level, s."academicYear",
                       l.id as lecturer_id, l.department as lecturer_department,
                       a.id as admin_id, a."superAdmin"
                FROM users u
                LEFT JOIN students s ON u.id = s."userId"
                LEFT JOIN lecturers l ON u.id = l."userId"
                LEFT JOIN admins a ON u.id = a."userId"
                WHERE u."userId" = ? OR u.email = ?
            ');
            $stmt->execute([$userIdOrEmail, $userIdOrEmail]);
            $user = $stmt->fetch();

            if (!$user) {
                errorResponse('Invalid credentials', 401);
            }

            // Verify password
            if (!verifyPassword($input['password'], $user['password'])) {
                errorResponse('Invalid credentials', 401);
            }

            if ($user['role'] === 'ADMIN') {
                errorResponse('Admin login has been disabled. Please use a lecturer account.', 403);
            }

            // Generate token
            $jwt = new JwtHandler();
            $token = $jwt->sign($user['id'], $user['role']);

            // Prepare profile data
            $profile = null;
            if ($user['role'] === 'STUDENT') {
                $profile = [
                    'id' => $user['student_id'],
                    'level' => $user['student_level'],
                    'academicYear' => $user['academicYear']
                ];
            } elseif ($user['role'] === 'LECTURER') {
                $profile = [
                    'id' => $user['lecturer_id'],
                    'department' => $user['lecturer_department']
                ];
            } elseif ($user['role'] === 'ADMIN') {
                $profile = [
                    'id' => $user['admin_id'],
                    'superAdmin' => (bool)$user['superAdmin']
                ];
            }

            successResponse([
                'token' => $token,
                'user' => [
                    'id' => $user['id'],
                    'userId' => $user['userId'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'profile' => $profile
                ]
            ], 'Login successful');

        } catch (PDOException $e) {
            errorResponse('Failed to login: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get current user
     */
    public function me() {
        $user = authenticate();

        try {
            $stmt = $this->db->prepare('
                SELECT u.*, 
                       s.id as student_id, s."studentId" as student_number, s.level as student_level, s."academicYear", s.department as student_department,
                       l.id as lecturer_id, l."lecturerId" as lecturer_number, l.department as lecturer_department, l.title,
                       a.id as admin_id, a."adminId" as admin_number, a."superAdmin"
                FROM users u
                LEFT JOIN students s ON u.id = s."userId"
                LEFT JOIN lecturers l ON u.id = l."userId"
                LEFT JOIN admins a ON u.id = a."userId"
                WHERE u.id = ?
            ');
            $stmt->execute([$user->userId]);
            $userData = $stmt->fetch();

            if (!$userData) {
                errorResponse('User not found', 404);
            }

            $profile = null;
            if ($userData['role'] === 'STUDENT') {
                $profile = [
                    'id' => $userData['student_id'],
                    'studentId' => $userData['student_number'],
                    'level' => $userData['student_level'],
                    'academicYear' => $userData['academicYear'],
                    'department' => $userData['student_department']
                ];
            } elseif ($userData['role'] === 'LECTURER') {
                $profile = [
                    'id' => $userData['lecturer_id'],
                    'lecturerId' => $userData['lecturer_number'],
                    'department' => $userData['lecturer_department'],
                    'title' => $userData['title']
                ];
            } elseif ($userData['role'] === 'ADMIN') {
                $profile = [
                    'id' => $userData['admin_id'],
                    'adminId' => $userData['admin_number'],
                    'superAdmin' => (bool)$userData['superAdmin']
                ];
            }

            successResponse([
                'id' => $userData['id'],
                'userId' => $userData['userId'],
                'name' => $userData['name'],
                'email' => $userData['email'],
                'role' => $userData['role'],
                'profile' => $profile
            ]);

        } catch (PDOException $e) {
            errorResponse('Failed to get user: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Change password
     */
    public function changePassword() {
        $user = authenticate();
        $input = $_REQUEST;

        if (empty($input['currentPassword']) || empty($input['newPassword'])) {
            errorResponse('Current and new password are required', 400);
        }

        try {
            $stmt = $this->db->prepare('SELECT password FROM users WHERE id = ?');
            $stmt->execute([$user->userId]);
            $userData = $stmt->fetch();

            if (!verifyPassword($input['currentPassword'], $userData['password'])) {
                errorResponse('Current password is incorrect', 400);
            }

            $hashedPassword = hashPassword($input['newPassword']);
            $stmt = $this->db->prepare('UPDATE users SET password = ?, "updatedAt" = NOW() WHERE id = ?');
            $stmt->execute([$hashedPassword, $user->userId]);

            successResponse(null, 'Password changed successfully');

        } catch (PDOException $e) {
            errorResponse('Failed to change password: ' . $e->getMessage(), 500);
        }
    }
}
