<?php
// File: save-user.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'owner' should be able to save user accounts.
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
        $username = trim($_POST['username'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = trim($_POST['role'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $is_active = isset($_POST['is_active']) ? (intval($_POST['is_active']) === 1) : 1; // Default to active if not provided

        // Validate required fields.
        if (empty($username) || empty($full_name) || empty($email) || empty($role)) {
            throw new Exception("Username, Full Name, Email, and Role are required.");
        }

        // Validate email format.
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format.");
        }

        // Validate role enum.
        $allowed_roles = ['owner', 'incharge', 'shopkeeper'];
        if (!in_array($role, $allowed_roles)) {
            throw new Exception("Invalid role specified. Must be owner, incharge, or shopkeeper.");
        }

        // Validate phone number if provided (optional)
        if (!empty($phone) && !preg_match('/^[0-9]{10,15}$/', $phone)) {
            throw new Exception("Phone number must be 10-15 digits if provided.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        $message = "";
        $action_type = 'create';
        $entity_id_for_log = $user_id; // Default for update, will be set for create

        if ($user_id) {
            // Update existing user.
            // Ensure username/email uniqueness for *other* users.
            $check_username_email_query = "SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?";
            $check_username_email_stmt = $db->prepare($check_username_email_query);
            $check_username_email_stmt->execute([$username, $email, $user_id]);
            if ($check_username_email_stmt->rowCount() > 0) {
                throw new Exception("Username or email already exists for another user.");
            }

            $update_query = "UPDATE users SET username = ?, full_name = ?, email = ?, role = ?, phone = ?, is_active = ?, updated_at = NOW() WHERE id = ?";
            $params = [$username, $full_name, $email, $role, $phone, $is_active, $user_id];

            // Only update password if provided
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $update_query = "UPDATE users SET username = ?, password = ?, full_name = ?, email = ?, role = ?, phone = ?, is_active = ?, updated_at = NOW() WHERE id = ?";
                array_splice($params, 1, 0, [$hashed_password]); // Insert hashed password at second position
            }

            $update_stmt = $db->prepare($update_query);
            $update_stmt->execute($params);
            $message = "User updated successfully.";
            $action_type = 'update';

            // If the user's own role is being changed, or they are being deactivated, ensure security.
            if ($user_id == $_SESSION['user_id'] && $role !== $_SESSION['role']) {
                // If current user is changing their own role, update session immediately
                $_SESSION['role'] = $role;
            }
            if ($user_id == $_SESSION['user_id'] && $is_active === 0) {
                // If current user is deactivating their own account, force logout
                $auth->logout(); // This will destroy session and log activity
                echo json_encode(['success' => true, 'message' => 'Your account has been deactivated. You have been logged out.']);
                exit;
            }

        } else {
            // Create new user.
            // Check username/email uniqueness.
            $check_username_email_query = "SELECT id FROM users WHERE username = ? OR email = ?";
            $check_username_email_stmt = $db->prepare($check_username_email_query);
            $check_username_email_stmt->execute([$username, $email]);
            if ($check_username_email_stmt->rowCount() > 0) {
                throw new Exception("Username or email already exists.");
            }

            // Password is required for new user.
            if (empty($password)) {
                throw new Exception("Password is required for new users.");
            }
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $insert_query = "INSERT INTO users (username, password, full_name, email, role, phone, is_active, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->execute([$username, $hashed_password, $full_name, $email, $role, $phone, $is_active]);
            $entity_id_for_log = $db->lastInsertId(); // Get ID of newly created user
            $message = "User created successfully.";
            $action_type = 'create';
        }

        // Log the activity using Auth class.
        $auth->logActivity(
            $_SESSION['user_id'],
            $action_type,
            'users',
            ($action_type === 'create' ? 'Created new user: ' : 'Updated user: ') . htmlspecialchars($username) . ' (Role: ' . htmlspecialchars($role) . ')',
            $entity_id_for_log
        );

        // Commit transaction.
        $db->commit();

        // Return success response.
        echo json_encode(['success' => true, 'message' => $message, 'user_id' => $entity_id_for_log]);
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
            'Failed to save user: ' . $e->getMessage(),
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