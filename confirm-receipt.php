<?php
// File: confirm-receipt.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'shopkeeper' should confirm receipts.
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'shopkeeper') {
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
        $transfer_id = filter_var($_POST['transfer_id'] ?? null, FILTER_VALIDATE_INT);
        $notes = trim($_POST['notes'] ?? '');
        $confirmed_by = $_SESSION['user_id'];
        $shopkeeper_id = $_SESSION['user_id']; // The shopkeeper confirming is the one logged in

        // Validate inputs.
        if (!$transfer_id) {
            throw new Exception("Missing or invalid transfer ID.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // 1. Fetch inventory transfer details and lock the row.
        $transfer_query = "SELECT it.product_id, it.quantity, it.from_location, it.to_location, it.status, it.shopkeeper_id,
                                 p.name AS product_name
                           FROM inventory_transfers it
                           JOIN products p ON it.product_id = p.id
                           WHERE it.id = ? FOR UPDATE"; // Lock the row
        $transfer_stmt = $db->prepare($transfer_query);
        $transfer_stmt->execute([$transfer_id]);
        $transfer_details = $transfer_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transfer_details) {
            throw new Exception("Inventory transfer record not found.");
        }
        if ($transfer_details['status'] !== 'pending') {
            throw new Exception("Transfer is not in 'pending' status and cannot be confirmed.");
        }
        // Ensure the current shopkeeper is the one designated for this transfer
        if ($transfer_details['shopkeeper_id'] !== $shopkeeper_id) {
             throw new Exception("You are not authorized to confirm this specific transfer.");
        }

        // 2. Update inventory_transfers status.
        $update_transfer_query = "UPDATE inventory_transfers
                                 SET status = 'confirmed', confirmed_by = ?, confirmation_date = NOW()
                                 WHERE id = ?";
        $update_transfer_stmt = $db->prepare($update_transfer_query);
        $update_transfer_stmt->execute([$confirmed_by, $transfer_id]);

        // 3. Update destination inventory (quantity increase).
        $update_inventory_query = "INSERT INTO inventory (product_id, quantity, location, shopkeeper_id, updated_at)
                                   VALUES (?, ?, ?, ?, NOW())
                                   ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), updated_at = NOW()";
        $update_inventory_stmt = $db->prepare($update_inventory_query);
        // If the location is 'wholesale', shopkeeper_id should be null for general wholesale stock
        // If the location is a shopkeeper's specific stock, then shopkeeper_id should be set
        // Based on `inventory` table schema, `shopkeeper_id` is nullable.
        $dest_shopkeeper_id = ($transfer_details['to_location'] === 'wholesale') ? null : $shopkeeper_id;

        $update_inventory_stmt->execute([
            $transfer_details['product_id'],
            $transfer_details['quantity'],
            $transfer_details['to_location'],
            $dest_shopkeeper_id
        ]);

        // 4. Log the activity using Auth class.
        $auth->logActivity(
            $confirmed_by,
            'update', // Action type is 'update' (of transfer status)
            'inventory_transfers',
            "Confirmed receipt of " . $transfer_details['quantity'] . " units of " . htmlspecialchars($transfer_details['product_name']) .
            " from " . htmlspecialchars($transfer_details['from_location']) . " to " . htmlspecialchars($transfer_details['to_location']) .
            " (Transfer ID: " . $transfer_id . "). Notes: " . htmlspecialchars($notes),
            $transfer_id // Entity ID is the transfer ID
        );

        // Commit transaction.
        $db->commit();

        // Return success response.
        echo json_encode(['success' => true, 'message' => 'Inventory receipt confirmed successfully.']);
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
            'Failed to confirm inventory receipt: ' . $e->getMessage(),
            $transfer_id ?? null
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