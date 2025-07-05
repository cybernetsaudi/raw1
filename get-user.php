<?php
// File: get-user.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and database connection.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class for logging

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'owner' should be able to fetch user details this way.
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// Get database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

// Process GET request.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Get and sanitize input data.
        $user_id = filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT);

        // Validate inputs.
        if (!$user_id) {
            throw new Exception("Missing or invalid user ID.");
        }

        // Fetch user details, excluding sensitive password hash.
        $query = "SELECT id, username, full_name, email, role, phone, is_active FROM users WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new Exception("User not found.");
        }

        // Log the activity using Auth class.
        $auth->logActivity(
            $_SESSION['user_id'],
            'read',
            'users',
            'Fetched details for user: ' . htmlspecialchars($user['username']),
            $user_id
        );

        // Return success response.
        echo json_encode([
            'success' => true,
            'message' => 'User data fetched successfully.',
            'user' => $user
        ]);
        exit;

    } catch (Exception $e) {
        // Log the error using Auth class.
        $auth->logActivity(
            $_SESSION['user_id'] ?? null,
            'error',
            'users',
            'Failed to fetch user details: ' . $e->getMessage(),
            $user_id ?? null
        );

        // Return error response.
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
} else {
    // Not a GET request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only GET requests are allowed.']);
    exit;
}