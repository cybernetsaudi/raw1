<?php
// File: api/adjust-product-quantity.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'owner' or 'incharge' should be able to adjust finished product quantities.
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
        $batch_id = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
        $product_id = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $original_quantity_produced = filter_var($_POST['original_quantity_produced'] ?? null, FILTER_VALIDATE_INT);
        $adjusted_quantity = filter_var($_POST['adjusted_quantity'] ?? null, FILTER_VALIDATE_INT);
        $reason = trim($_POST['reason'] ?? '');
        $adjusted_by = $_SESSION['user_id'];

        // Validate inputs.
        if (!$batch_id || !$product_id || !is_numeric($original_quantity_produced) || !is_numeric($adjusted_quantity) || $adjusted_quantity < 0 || empty($reason)) {
            throw new Exception("Missing or invalid required fields. Adjusted quantity must be non-negative.");
        }
        if ($original_quantity_produced < 0) { // Should not happen for 'quantity_produced'
            throw new Exception("Original quantity produced cannot be negative.");
        }

        // Prevent adjustment if quantities are the same.
        if ($original_quantity_produced === $adjusted_quantity) {
            echo json_encode(['success' => true, 'message' => 'No adjustment needed, quantities are the same.']);
            exit;
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // 1. Fetch batch details and lock the row.
        $batch_query = "SELECT batch_number, quantity_produced, status FROM manufacturing_batches WHERE id = ? FOR UPDATE";
        $batch_stmt = $db->prepare($batch_query);
        $batch_stmt->execute([$batch_id]);
        $batch_info = $batch_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$batch_info) {
            throw new Exception("Manufacturing batch not found.");
        }
        if ($batch_info['status'] !== 'completed') {
            throw new Exception("Only completed batches can have their final product quantity adjusted.");
        }
        if ($batch_info['quantity_produced'] !== $original_quantity_produced) {
             // This indicates a mismatch if the original_quantity_produced passed from frontend is outdated.
             // You might want to update the frontend to use the latest quantity or re-fetch.
             throw new Exception("Original quantity mismatch. Current batch quantity is " . $batch_info['quantity_produced'] . ".");
        }

        $quantity_difference = $adjusted_quantity - $original_quantity_produced;

        // 2. Update `quantity_produced` in `manufacturing_batches`.
        $update_batch_query = "UPDATE manufacturing_batches SET quantity_produced = ?, updated_at = NOW() WHERE id = ?";
        $update_batch_stmt = $db->prepare($update_batch_query);
        $update_batch_stmt->execute([$adjusted_quantity, $batch_id]);

        // 3. Adjust product quantity in `inventory`.
        // This assumes the product from this batch has been transferred to 'wholesale' or 'transit' inventory.
        // We need to find where this product quantity is currently logged (typically wholesale or transit after completion).
        // It should update the existing product in the relevant location.
        $product_in_inventory_query = "SELECT quantity, location FROM inventory WHERE product_id = ? AND (location = 'wholesale' OR location = 'transit') FOR UPDATE";
        $product_in_inventory_stmt = $db->prepare($product_in_inventory_query);
        $product_in_inventory_stmt->execute([$product_id]);
        $inventory_record = $product_in_inventory_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$inventory_record) {
             // If product from this batch isn't in wholesale/transit,
             // it might be a new product, or an edge case.
             // For simplicity, we can create/update it in 'wholesale' if it doesn't exist.
             $location_to_adjust = 'wholesale'; // Default to wholesale
             $current_inventory_quantity = 0;
        } else {
            $location_to_adjust = $inventory_record['location'];
            $current_inventory_quantity = $inventory_record['quantity'];
        }

        $new_inventory_quantity = $current_inventory_quantity + $quantity_difference;

        if ($new_inventory_quantity < 0) {
            throw new Exception("Adjusted quantity would result in negative inventory for product (current: " . $current_inventory_quantity . ", change: " . $quantity_difference . ").");
        }

        $update_inventory_query = "INSERT INTO inventory (product_id, quantity, location, updated_at)
                                   VALUES (?, ?, ?, NOW())
                                   ON DUPLICATE KEY UPDATE quantity = ?, updated_at = NOW()"; // Assumes unique (product_id, location)
        $update_inventory_stmt = $db->prepare($update_inventory_query);
        $update_inventory_stmt->execute([$product_id, $new_inventory_quantity, $location_to_adjust, $new_inventory_quantity]);

        // 4. Insert record into `product_adjustments` table.
        $insert_adjustment_query = "INSERT INTO product_adjustments (batch_id, product_id, original_quantity, adjusted_quantity, reason, adjusted_by_user_id)
                                    VALUES (?, ?, ?, ?, ?, ?)";
        $insert_adjustment_stmt = $db->prepare($insert_adjustment_query);
        $insert_adjustment_stmt->execute([$batch_id, $product_id, $original_quantity_produced, $adjusted_quantity, $reason, $adjusted_by]);
        $adjustment_id = $db->lastInsertId();

        // 5. Log the activity using Auth class.
        $product_name_query = "SELECT name FROM products WHERE id = ?";
        $product_name_stmt = $db->prepare($product_name_query);
        $product_name_stmt->execute([$product_id]);
        $product_name = $product_name_stmt->fetchColumn();

        $action_description = "Adjusted final quantity for batch " . htmlspecialchars($batch_info['batch_number']) .
                              " (" . htmlspecialchars($product_name) . ") from " . $original_quantity_produced .
                              " to " . $adjusted_quantity . ". Reason: " . htmlspecialchars($reason) . ".";

        $auth->logActivity(
            $adjusted_by,
            'update',
            'product_adjustments', // Module specifically for product adjustments
            $action_description,
            $adjustment_id // Entity ID is the product_adjustment ID
        );

        // Commit transaction.
        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Product quantity adjusted successfully. New quantity: ' . $adjusted_quantity . '.',
            'new_quantity_produced' => $adjusted_quantity,
            'adjustment_id' => $adjustment_id
        ]);
        exit;

    } catch (Exception $e) {
        // Rollback transaction on error.
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        // Log the error.
        $auth->logActivity(
            $_SESSION['user_id'] ?? null,
            'error',
            'product_adjustments',
            'Failed to adjust product quantity for batch ' . ($batch_id ?? 'N/A') . ': ' . $e->getMessage(),
            $batch_id ?? null
        );

        echo json_encode(['success' => false, 'message' => 'An error occurred while adjusting product quantity: ' . $e->getMessage()]);
        exit;
    }
} else {
    // Not a POST request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
    exit;
}