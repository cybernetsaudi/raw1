<?php
// File: save-customer.php

// Ensure session is started at the very beginning of the script.
// This simplifies session management across all files.
session_start();

// Include database config and Auth class
include_once '../config/database.php';
include_once '../config/auth.php';

// Set content type to JSON for consistent API response format
header('Content-Type: application/json');

// Ensure user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'shopkeeper') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Initialize auth for activity logging
$auth = new Auth($db);

// Process POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get form data and sanitize/trim
        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $created_by = $_SESSION['user_id']; // Use the logged-in user's ID

        // Validate data
        if (empty($name)) {
            throw new Exception("Customer name is required.");
        }
        if (empty($phone)) {
            throw new Exception("Phone number is required.");
        }
        // Validate email format if provided and not empty
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format.");
        }
        // Validate phone number (basic validation for digits and length)
        if (!preg_match('/^[0-9]{10,15}$/', $phone)) {
            throw new Exception("Phone number must be 10-15 digits and contain only numbers.");
        }

        // Start transaction for atomicity
        $db->beginTransaction();

        $message = ""; // Initialize message variable

        if ($customer_id) {
            // Update existing customer
            // Verify ownership/permission for update
            $check_query = "SELECT id FROM customers WHERE id = ? AND created_by = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([$customer_id, $created_by]);

            if ($check_stmt->rowCount() === 0) {
                throw new Exception("You don't have permission to edit this customer or customer doesn't exist.");
            }

            $query = "UPDATE customers
                     SET name = :name, email = :email, phone = :phone, address = :address, updated_at = NOW()
                     WHERE id = :customer_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':address', $address);
            $stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
            $stmt->execute();

            // Log activity
            $auth->logActivity(
                $_SESSION['user_id'],
                'update',
                'customers',
                "Updated customer: " . htmlspecialchars($name),
                $customer_id
            );

            $message = "Customer updated successfully.";
        } else {
            // Create new customer
            // Check if a customer with the same phone number already exists
            $check_query = "SELECT id FROM customers WHERE phone = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([$phone]);

            if ($check_stmt->rowCount() > 0) {
                throw new Exception("A customer with this phone number already exists.");
            }

            $query = "INSERT INTO customers (name, email, phone, address, created_by, created_at, updated_at)
                     VALUES (:name, :email, :phone, :address, :created_by, NOW(), NOW())";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':address', $address);
            $stmt->bindParam(':created_by', $created_by, PDO::PARAM_INT);
            $stmt->execute();

            $customer_id = $db->lastInsertId();

            // Log activity
            $auth->logActivity(
                $_SESSION['user_id'],
                'create',
                'customers',
                "Created new customer: " . htmlspecialchars($name),
                $customer_id
            );

            $message = "Customer created successfully.";
        }

        // Commit transaction
        $db->commit();

        // Return JSON success response with customer details
        echo json_encode([
            'success' => true,
            'message' => $message,
            'customer' => [ // Return updated/created customer data
                'id' => $customer_id,
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'address' => $address
            ]
        ]);
        exit;

    } catch (Exception $e) {
        // Rollback transaction on error
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        // Log the error using Auth class
        $auth->logActivity(
            $_SESSION['user_id'],
            'error',
            'customers',
            'Failed to save customer: ' . $e->getMessage(),
            $customer_id
        );

        // Return JSON error response
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
} else {
    // Handle non-POST requests to this API endpoint
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method. Only POST requests are allowed.'
    ]);
    exit;
}