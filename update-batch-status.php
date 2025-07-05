<?php
// File: update-batch-status.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'owner' or 'incharge' should be able to update batch status.
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
        $new_status = trim($_POST['new_status'] ?? '');
        $status_change_notes = trim($_POST['status_change_notes'] ?? '');
        $status_changed_by = $_SESSION['user_id'];

        // Validate inputs.
        if (!$batch_id || empty($new_status)) {
            throw new Exception("Missing required fields (Batch ID, New Status).");
        }

        // Validate new status enum.
        $allowed_statuses = ['pending','cutting','stitching','ironing','packaging','completed'];
        if (!in_array($new_status, $allowed_statuses)) {
            throw new Exception("Invalid batch status specified.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // 1. Fetch current batch details and lock the row.
        $batch_query = "SELECT id, batch_number, status, product_id, quantity_produced FROM manufacturing_batches WHERE id = ? FOR UPDATE";
        $batch_stmt = $db->prepare($batch_query);
        $batch_stmt->execute([$batch_id]);
        $batch_info = $batch_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$batch_info) {
            throw new Exception("Manufacturing batch not found.");
        }

        $old_status = $batch_info['status'];
        $batch_number = $batch_info['batch_number'];
        $product_id = $batch_info['product_id'];
        $quantity_produced = $batch_info['quantity_produced'];

        // Prevent changing to the same status.
        if ($old_status === $new_status) {
            $db->rollBack(); // No actual change needed, rollback transaction.
            echo json_encode(['success' => true, 'message' => 'Batch is already in ' . htmlspecialchars($new_status) . ' status.']);
            exit;
        }

        // Define valid status transitions (example logic, adjust as per your workflow).
        // This makes the workflow more controlled. Owner can override if needed.
        $valid_transitions = [
            'pending' => ['cutting'],
            'cutting' => ['stitching'],
            'stitching' => ['ironing'],
            'ironing' => ['packaging'],
            'packaging' => ['completed'],
            'completed' => [] // Cannot change status from completed normally
        ];

        // Check for valid transition, unless the user is 'owner' (can override flow).
        if ($_SESSION['role'] !== 'owner' && (!isset($valid_transitions[$old_status]) || !in_array($new_status, $valid_transitions[$old_status]))) {
            throw new Exception("Invalid status transition from " . htmlspecialchars($old_status) . " to " . htmlspecialchars($new_status) . ".");
        }

        // 2. Update batch status in `manufacturing_batches` table.
        // Append notes for status change to existing notes for historical tracking within the batch itself.
        $new_notes_entry = "\n[Status change by " . $_SESSION['full_name'] . " on " . date('Y-m-d H:i:s') . "] From " . $old_status . " to " . $new_status . ". Notes: " . $status_change_notes;
        $update_batch_query = "UPDATE manufacturing_batches
                               SET status = ?, status_changed_by = ?, status_changed_at = NOW(),
                                   status_change_notes = CONCAT(IFNULL(status_change_notes, ''), ?)
                               WHERE id = ?";
        $update_batch_stmt = $db->prepare($update_batch_query);
        $update_batch_stmt->execute([$new_status, $status_changed_by, $new_notes_entry, $batch_id]);

        // If status becomes 'completed', move products to inventory and create notification for shopkeeper.
        if ($new_status === 'completed') {
            // Check if quantity_produced is actually set
            if ($quantity_produced <= 0) {
                 throw new Exception("Cannot complete batch with zero or negative quantity produced. Update quantity first.");
            }

            // Move products from 'manufacturing' to 'transit' inventory location
            // This is a transfer, but it's directly moving from manufacturing (conceptually, not an 'inventory' record yet)
            // to transit in the `inventory` table.
            $update_inventory_query = "INSERT INTO inventory (product_id, quantity, location, updated_at)
                                       VALUES (?, ?, 'transit', NOW())
                                       ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), updated_at = NOW()";
            $update_inventory_stmt = $db->prepare($update_inventory_query);
            $update_inventory_stmt->execute([$product_id, $quantity_produced]);

            // Create notification for shopkeeper to confirm receipt (similar to inventory transfer)
            // Find shopkeepers (e.g., all shopkeepers, or a specific one responsible for transit stock)
            // For now, let's assume it's for all active shopkeepers or a designated one.
            // If there's a specific shopkeeper for 'transit', fetch that user's ID.
            // For simplicity, let's log to all shopkeepers or the one that 'created' it if applicable.
            // Or, more accurately, we create a pending transfer and let `confirm-receipt.php` handle it.
            // Let's create an `inventory_transfers` record with status 'pending' to be confirmed by a shopkeeper.

            $transfer_to_shopkeeper_id = null; // This should be determined by your business logic (e.g., default shopkeeper for wholesale)
                                              // For now, we'll mark it as pending for 'wholesale' general location if no specific shopkeeper is designated.
            // Example: Find a random active shopkeeper to notify
            $shopkeeper_query = "SELECT id FROM users WHERE role = 'shopkeeper' AND is_active = 1 LIMIT 1";
            $shopkeeper_stmt = $db->query($shopkeeper_query);
            $designated_shopkeeper = $shopkeeper_stmt->fetch(PDO::FETCH_ASSOC);

            if ($designated_shopkeeper) {
                $transfer_to_shopkeeper_id = $designated_shopkeeper['id'];
            }

            // Create a pending inventory transfer for the completed products
            $insert_transfer_query = "INSERT INTO inventory_transfers
                                     (product_id, quantity, from_location, to_location, transfer_date, initiated_by, shopkeeper_id, status)
                                     VALUES (?, ?, 'manufacturing', 'wholesale', NOW(), ?, ?, 'pending')";
            $insert_transfer_stmt = $db->prepare($insert_transfer_query);
            $insert_transfer_stmt->execute([
                $product_id,
                $quantity_produced,
                $status_changed_by, // The user who completed the batch
                $transfer_to_shopkeeper_id // Shopkeeper to confirm (can be null if general wholesale)
            ]);
            $transfer_id = $db->lastInsertId();

            // Notify the shopkeeper (if a specific one was found)
            if ($transfer_to_shopkeeper_id) {
                $product_name_query = "SELECT name FROM products WHERE id = ?";
                $product_name_stmt = $db->prepare($product_name_query);
                $product_name_stmt->execute([$product_id]);
                $product_name = $product_name_stmt->fetchColumn();

                $notification_message = "New batch of " . $quantity_produced . " units of " . htmlspecialchars($product_name) .
                                        " has been completed and transferred to wholesale. Awaiting your confirmation (Transfer ID: " . $transfer_id . ").";
                $notification_type = "batch_completed_transfer";

                $insert_notification_query = "INSERT INTO notifications (user_id, type, message, related_id) VALUES (?, ?, ?, ?)";
                $insert_notification_stmt = $db->prepare($insert_notification_query);
                $insert_notification_stmt->execute([$transfer_to_shopkeeper_id, $notification_type, $notification_message, $transfer_id]);
            }
        }


        // Log the activity using Auth class.
        $auth->logActivity(
            $status_changed_by,
            'update',
            'manufacturing_batches',
            "Changed batch " . htmlspecialchars($batch_number) . " status from " . htmlspecialchars($old_status) . " to " . htmlspecialchars($new_status) . ". Notes: " . htmlspecialchars($status_change_notes),
            $batch_id
        );

        // Commit transaction.
        $db->commit();

        echo json_encode(['success' => true, 'message' => 'Batch status updated successfully.']);
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
            'manufacturing_batches',
            'Failed to update batch status: ' . $e->getMessage(),
            $batch_id ?? null
        );

        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
} else {
    // Not a POST request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
    exit;
}