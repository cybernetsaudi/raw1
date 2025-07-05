<?php
// File: toggle-user-activation.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'owner' should be able to toggle user activation.
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// Get database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

// Process POST request.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get and sanitize input data.
        $user_id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $is_active = filter_var($_POST['is_active'] ?? null, FILTER_VALIDATE_INT); // 0 for deactivate, 1 for activate

        // Validate inputs.
        if (!$user_id || !is_numeric($is_active) || ($is_active !== 0 && $is_active !== 1)) {
            throw new Exception("Missing or invalid user ID or activation status.");
        }

        // Prevent a user from deactivating their own account (unless it leads to forced logout)
        if ($user_id == $_SESSION['user_id'] && $is_active === 0) {
            // Allow deactivation, but the logout must be handled immediately.
            // This case is typically handled by `save-user.php` if user edits their own profile.
            // For a separate toggle, it's safer to prevent self-deactivation.
            // Or, if allowed, force a logout upon successful deactivation.
            throw new Exception("You cannot deactivate your own account using this function.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // Fetch current user details for logging and validation.
        $current_user_query = "SELECT username, is_active FROM users WHERE id = ?";
        $current_user_stmt = $db->prepare($current_user_query);
        $current_user_stmt->execute([$user_id]);
        $user_info = $current_user_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user_info) {
            throw new Exception("User not found.");
        }

        $new_status_text = $is_active ? 'active' : 'inactive';
        $old_status_text = $user_info['is_active'] ? 'active' : 'inactive';

        if ($user_info['is_active'] === $is_active) {
            // No change needed
            $db->rollBack(); // No actual transaction took place if no change
            echo json_encode(['success' => true, 'message' => 'User ' . htmlspecialchars($user_info['username']) . ' is already ' . $new_status_text . '.']);
            exit;
        }

        // Update user's activation status.
        $update_query = "UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->execute([$is_active, $user_id]);

        // Log the activity using Auth class.
        $auth->logActivity(
            $_SESSION['user_id'],
            'update',
            'users',
            'Changed activation status of user ' . htmlspecialchars($user_info['username']) . ' from ' . $old_status_text . ' to ' . $new_status_text . '.',
            $user_id
        );

        // Commit transaction.
        $db->commit();

        // Return success response.
        echo json_encode(['success' => true, 'message' => 'User ' . htmlspecialchars($user_info['username']) . ' has been marked as ' . $new_status_text . ' successfully.']);
        exit;

    } catch (Exception $e) {
        // Rollback transaction on error.
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        // Log the error using Auth class.
        $auth->logActivity(
            $_SESSION['user_id'] ?? null,
            'error',
            'users',
            'Failed to toggle user activation: ' . $e->getMessage(),
            $user_id ?? null
        );

        // Return error response.
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
} else {
    // Not a POST request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
    exit;
}