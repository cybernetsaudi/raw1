<?php
// File: logout.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
// Paths relative to this file's location.
include_once 'config/database.php';
include_once 'config/auth.php';

// Get database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class.
$auth = new Auth($db);

// Call the logout method from the Auth class.
$auth->logout();

// Redirect to the login page (index.php) after logout.
header('Location: index.php');
exit;
?>