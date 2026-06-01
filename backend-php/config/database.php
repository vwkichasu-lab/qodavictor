<?php

/**
 * Database Configuration
 * MySQL Connection using PDO
 */

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'qoda_db';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: ''; // Change this if your MySQL has a password

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'ANSI_QUOTES')");
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Only declare if not already declared
if (!function_exists('getDB')) {
    function getDB()
    {
        global $pdo;
        return $pdo;
    }
}
