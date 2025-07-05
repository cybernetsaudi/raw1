<?php
// File: transfer-inventory.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'owner' or 'incharge' should be able to initiate inventory transfers.
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
        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
        $from_location = trim($_POST['from_location'] ?? '');
        $to_location = trim($_POST['to_location'] ?? '');
        $shopkeeper_id = filter_var($_POST['shopkeeper_id'] ?? null, FILTER_VALIDATE_INT); // Only relevant if 'to_location' is specific to a shopkeeper
        $initiated_by = $_SESSION['user_id'];

        // Validate inputs.
        if (!$product_id || !$quantity || $quantity <= 0 || empty($from_location) || empty($to_location) || $from_location === $to_location) {
            throw new Exception("Missing or invalid transfer details. Quantity must be positive, and locations must be different.");
        }

        // Validate location enums.
        $allowed_locations = ['manufacturing', 'wholesale', 'transit'];
        if (!in_array($from_location, $allowed_locations) || !in_array($to_location, $allowed_locations)) {
            throw new Exception("Invalid 'from' or 'to' location specified.");
        }

        // Validate shopkeeper_id if `to_location` is 'wholesale' or specific shopkeeper stock
        // The `inventory` table has `shopkeeper_id` for specific shopkeeper stock in 'wholesale' location.
        // If `to_location` is 'wholesale' AND a `shopkeeper_id` is provided, it implies transferring to a specific shopkeeper's wholesale stock.
        // If `to_location` is 'wholesale' AND `shopkeeper_id` is NULL, it implies general wholesale stock.
        // For simplicity, let's assume `shopkeeper_id` is required if `to_location` is 'wholesale'.
        // This might need adjustment based on exact inventory model.
        if ($to_location === 'wholesale' && !$shopkeeper_id) {
            throw new Exception("Shopkeeper ID is required when transferring to wholesale location for specific allocation.");
        }
        // If shopkeeper_id is provided, validate it exists
        if ($shopkeeper_id) {
            $user_check_query = "SELECT id FROM users WHERE id = ? AND role = 'shopkeeper'";
            $user_check_stmt = $db->prepare($user_check_query);
            $user_check_stmt->execute([$shopkeeper_id]);
            if ($user_check_stmt->rowCount() === 0) {
                throw new Exception("Invalid Shopkeeper ID provided.");
            }
        }


        // Start transaction for atomicity.
        $db->beginTransaction();

        // 1. Check if product exists in 'from_location' and has sufficient quantity.
        $current_quantity_query = "SELECT quantity FROM inventory WHERE product_id = ? AND location = ? FOR UPDATE"; // Lock source inventory row
        $current_quantity_stmt = $db->prepare($current_quantity_query);
        $current_quantity_stmt->execute([$product_id, $from_location]);
        $current_quantity = $current_quantity_stmt->fetchColumn();

        if ($current_quantity === false || $current_quantity < $quantity) {
            throw new Exception("Insufficient stock in " . htmlspecialchars($from_location) . ". Available: " . ($current_quantity ?: 0) . ", Required: " . $quantity . ".");
        }

        // 2. Decrease quantity in `from_location`.
        $decrease_query = "UPDATE inventory SET quantity = quantity - ?, updated_at = NOW() WHERE product_id = ? AND location = ?";
        $decrease_stmt = $db->prepare($decrease_query);
        $decrease_stmt->execute([$quantity, $product_id, $from_location]);

        // 3. Create a pending `inventory_transfers` record.
        // The `confirmed_by` and `confirmation_date` will be set by the shopkeeper (or relevant receiver)
        // This record indicates goods are 'in transit'.
        $insert_transfer_query = "INSERT INTO inventory_transfers
                                 (product_id, quantity, from_location, to_location, transfer_date, initiated_by, shopkeeper_id, status)
                                 VALUES (?, ?, ?, ?, NOW(), ?, ?, 'pending')";
        $insert_transfer_stmt = $db->prepare($insert_transfer_query);
        $insert_transfer_stmt->execute([$product_id, $quantity, $from_location, $to_location, $initiated_by, $shopkeeper_id]);
        $transfer_id = $db->lastInsertId();

        // 4. Create a notification for the shopkeeper (if applicable).
        if ($shopkeeper_id) {
            $product_name_query = "SELECT name FROM products WHERE id = ?";
            $product_name_stmt = $db->prepare($product_name_query);
            $product_name_stmt->execute([$product_id]);
            $product_name = $product_name_stmt->fetchColumn();

            $notification_message = "New inventory transfer of " . $quantity . " units of " . htmlspecialchars($product_name) .
                                    " from " . htmlspecialchars($from_location) . " to " . htmlspecialchars($to_location) .
                                    " is pending your confirmation. (Transfer ID: " . $transfer_id . ")";
            $notification_type = "inventory_transfer_pending";

            $insert_notification_query = "INSERT INTO notifications (user_id, type, message, related_id) VALUES (?, ?, ?, ?)";
            $insert_notification_stmt = $db->prepare($insert_notification_query);
            $insert_notification_stmt->execute([$shopkeeper_id, $notification_type, $notification_message, $transfer_id]);
        }

        // Log the activity using Auth class.
        $product_name_query = "SELECT name FROM products WHERE id = ?";
        $product_name_stmt = $db->prepare($product_name_query);
        $product_name_stmt->execute([$product_id]);
        $product_name = $product_name_stmt->fetchColumn();

        $auth->logActivity(
            $initiated_by,
            'create', // Action type is 'create' for new transfer record
            'inventory_transfers',
            "Initiated transfer of " . $quantity . " units of " . htmlspecialchars($product_name) .
            " from " . htmlspecialchars($from_location) . " to " . htmlspecialchars($to_location) .
            " (Transfer ID: " . $transfer_id . ")",
            $transfer_id // Entity ID is the transfer ID
        );

        // Commit transaction.
        $db->commit();

        // Return success response.
        echo json_encode(['success' => true, 'message' => 'Inventory transfer initiated successfully. Awaiting confirmation.', 'transfer_id' => $transfer_id]);
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
            'inventory_transfers',
            'Failed to initiate inventory transfer: ' . $e->getMessage(),
            $product_id ?? null
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