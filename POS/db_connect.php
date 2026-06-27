<?php
// ============================================
// DATA BASE CONN. "TUNG TUNG TUNG SAHUR"
// ============================================

// File: db_connect.php
// Database configuration
$host = 'localhost';
$username = 'root'; // palitan nyo sa inyo
$password = ''; // palitan nyo sa inyo
$database = 'pos_system';

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>