<?php
// File: adjust-inventory.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'owner' or 'incharge' should be able to adjust inventory.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'incharge')) {
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
        $product_id = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $change_quantity = filter_var($_POST['change_quantity'] ?? null, FILTER_VALIDATE_INT); // Assuming integer quantity changes
        $location = trim($_POST['location'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        $adjusted_by = $_SESSION['user_id'];

        // Validate inputs.
        if (!$product_id || !is_numeric($change_quantity) || empty($location) || empty($reason)) {
            throw new Exception("Missing or invalid required fields.");
        }

        // Validate location enum.
        $allowed_locations = ['manufacturing', 'wholesale', 'transit'];
        if (!in_array($location, $allowed_locations)) {
            throw new Exception("Invalid inventory location specified.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // Check current inventory to ensure it doesn't go negative if decreasing.
        // This is a basic check. More complex logic might be needed for specific scenarios.
        $current_quantity_query = "SELECT quantity FROM inventory WHERE product_id = ? AND location = ?";
        $current_quantity_stmt = $db->prepare($current_quantity_query);
        $current_quantity_stmt->execute([$product_id, $location]);
        $current_quantity = $current_quantity_stmt->fetchColumn();

        if ($current_quantity === false) {
            // Product not found at this location, treat as 0 for initial adjustment
            $current_quantity = 0;
        }

        $new_quantity = $current_quantity + $change_quantity;

        if ($new_quantity < 0) {
            throw new Exception("Cannot decrease inventory beyond available stock. Current: " . $current_quantity . ", Attempted change: " . $change_quantity . ".");
        }

        // Update inventory.
        $update_query = "INSERT INTO inventory (product_id, quantity, location, updated_at)
                         VALUES (?, ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE quantity = ?, updated_at = NOW()"; // Assumes unique (product_id, location)
        $update_stmt = $db->prepare($update_query);
        $update_stmt->execute([$product_id, $new_quantity, $location, $new_quantity]);

        // Log the activity using Auth class.
        $product_name_query = "SELECT name FROM products WHERE id = ?";
        $product_name_stmt = $db->prepare($product_name_query);
        $product_name_stmt->execute([$product_id]);
        $product_name = $product_name_stmt->fetchColumn();

        $action_description = ($change_quantity > 0 ? "Increased" : "Decreased") . " inventory of " . htmlspecialchars($product_name) . " by " . abs($change_quantity) . " units in " . htmlspecialchars($location) . ". Reason: " . htmlspecialchars($reason);

        $auth->logActivity(
            $adjusted_by,
            'update', // Action type is 'update' for inventory changes
            'inventory',
            $action_description,
            $product_id // Entity ID is the product_id
        );

        // Commit transaction.
        $db->commit();

        // Return success response.
        echo json_encode(['success' => true, 'message' => 'Inventory adjusted successfully.', 'new_quantity' => $new_quantity]);
        exit;

    } catch (Exception $e) {
        // Rollback transaction on error.
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        // Log the error using Auth class.
        $auth->logActivity(
            $_SESSION['user_id'] ?? null, // User ID might be null if unauthorized access check failed earlier
            'error',
            'inventory',
            'Failed to adjust inventory: ' . $e->getMessage(),
            $product_id ?? null // Log product_id if available
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