<?php
/**
 * Qoda API - Main Entry Point
 * PHP Backend for Online Exam System
 */

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// CORS headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Content-Type: application/json");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Load configuration files
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/response.php';
require_once __DIR__ . '/config/auth.php';

// Get request method and URI
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = str_replace('/api', '', $uri); // Remove /api prefix
$uri = trim($uri, '/');

// Get request body
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}
$_REQUEST = array_merge($_REQUEST, $input);

// Simple router
$routes = [
    // Auth routes (no authentication required)
    'auth/login' => ['AuthController', 'login'],
    'auth/register' => ['AuthController', 'register'],
    'auth/me' => ['AuthController', 'me'],
    'auth/change-password' => ['AuthController', 'changePassword'],
    
    // Student routes
    'students' => ['StudentController', 'index'],
    'students/profile' => ['StudentController', 'profile'],
    'students/courses' => ['StudentController', 'courses'],
    
    // Exam routes
    'exams' => ['ExamController', 'index'],
    'exams/upcoming' => ['ExamController', 'upcoming'],
    'exams/available' => ['ExamController', 'available'],
    'exams/stats' => ['ExamController', 'stats'],
    
    // Submission routes
    'submissions' => ['SubmissionController', 'index'],
    'submissions/start' => ['SubmissionController', 'start'],
    'submissions/submit' => ['SubmissionController', 'submit'],
    'submissions/auto-save' => ['SubmissionController', 'autoSave'],
    
    // Grading routes
    'grading' => ['GradingController', 'index'],
    'grading/grade' => ['GradingController', 'grade'],
    'grading/auto-grade' => ['GradingController', 'autoGrade'],
    
    // Results routes
    'results' => ['ResultController', 'index'],
    'results/release' => ['ResultController', 'release'],
    
    // Security routes
    'security/events' => ['SecurityController', 'events'],
    'security/log' => ['SecurityController', 'logEvent'],
    
    // Audit routes
    'audit' => ['AuditController', 'index'],
    'audit/log' => ['AuditController', 'log'],
    
    // Health check
    'health' => ['HealthController', 'index'],
];

// Match route
$matched = false;
foreach ($routes as $route => $handler) {
    if (fnmatch($route, $uri, FNM_PATHNAME) || $uri === $route || preg_match('#^' . str_replace(['*'], ['.*'], $route) . '$#', $uri)) {
        $matched = true;
        list($controller, $action) = $handler;
        
        // Load controller
        $controllerFile = __DIR__ . '/controllers/' . $controller . '.php';
        if (file_exists($controllerFile)) {
            require_once $controllerFile;
            $controllerInstance = new $controller();
            $controllerInstance->$action();
        } else {
            errorResponse('Controller not found', 404);
        }
        break;
    }
}

if (!$matched) {
    // Check for ID-based routes like /exams/:id
    if (preg_match('#^exams/([^/]+)$#', $uri, $matches)) {
        require_once __DIR__ . '/controllers/ExamController.php';
        $controller = new ExamController();
        $controller->show($matches[1]);
    } elseif (preg_match('#^exams/([^/]+)/questions$#', $uri, $matches)) {
        require_once __DIR__ . '/controllers/ExamController.php';
        $controller = new ExamController();
        $controller->addQuestion($matches[1]);
    } elseif (preg_match('#^submissions/([^/]+)$#', $uri, $matches)) {
        require_once __DIR__ . '/controllers/SubmissionController.php';
        $controller = new SubmissionController();
        $controller->show($matches[1]);
    } else {
        errorResponse('Route not found', 404);
    }
}
