<?php
// File: log-activity.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database configuration and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Get database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
// Note: This file itself *is* the logging endpoint, so it will use the Auth class.
$auth = new Auth($db);

// Process POST request.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get data from POST request.
        // It's client-side JS calling this, so data might be in JSON body or form data.
        // Assuming JSON body for cleaner API calls.
        $input = file_get_contents('php://input');
        $data = json_decode($input);

        // Validate necessary data.
        if (!isset($data->action_type) || !isset($data->module) || !isset($data->description)) {
            throw new Exception("Missing required logging parameters.");
        }

        // Sanitize inputs for logging.
        $user_id = $_SESSION['user_id'] ?? null; // Logged-in user ID
        $action_type = htmlspecialchars(trim($data->action_type));
        $module = htmlspecialchars(trim($data->module));
        $description = htmlspecialchars(trim($data->description));
        $entity_id = filter_var($data->entity_id ?? null, FILTER_VALIDATE_INT);

        // Use the existing logActivity method
        $auth->logActivity($user_id, $action_type, $module, $description, $entity_id);

        echo json_encode(['success' => true, 'message' => 'Activity logged successfully.']);
        exit;

    } catch (Exception $e) {
        // Log this internal error, but don't expose too much detail to client for security.
        error_log("Error in log-activity.php: " . $e->getMessage());

        echo json_encode(['success' => false, 'message' => 'Failed to log activity.']);
        exit;
    }
} else {
    // Not a POST request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
    exit;
}