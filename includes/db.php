<?php
/**
 * Database Connection - PDO
 * Home Chore Management System (ChoreQuest)
 */

// Consistent timezone
date_default_timezone_set('UTC');

$host = 'sql100.infinityfree.com';
$dbname = 'if0_41711753_chore_system_db';
$username = 'if0_41711753';
$password = 'yKEirkdw2FwS';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
