<?php
// File: api/delete-batch.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'owner' should be able to delete batches with rollback.
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
        $batch_id = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
        $reason = trim($_POST['reason'] ?? ''); // Reason for deletion is required

        // Validate inputs.
        if (!$batch_id) {
            throw new Exception("Missing or invalid Batch ID.");
        }
        if (empty($reason)) {
            throw new Exception("Reason for batch deletion is required.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // 1. Fetch batch details and materials used FOR UPDATE.
        // Lock the batch and related material_usage records to prevent race conditions.
        $batch_query = "SELECT mb.batch_number, mb.status, rm.id AS material_id, mu.quantity_used
                        FROM manufacturing_batches mb
                        JOIN material_usage mu ON mb.id = mu.batch_id
                        JOIN raw_materials rm ON mu.material_id = rm.id
                        WHERE mb.id = ? FOR UPDATE"; // Lock rows in manufacturing_batches and material_usage
        $batch_stmt = $db->prepare($batch_query);
        $batch_stmt->execute([$batch_id]);
        $batch_data = $batch_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($batch_data)) {
            // Batch might exist but have no materials, or truly not exist.
            // Check batch existence separately if no materials are found.
            $check_batch_exists_query = "SELECT batch_number, status FROM manufacturing_batches WHERE id = ?";
            $check_batch_exists_stmt = $db->prepare($check_batch_exists_query);
            $check_batch_exists_stmt->execute([$batch_id]);
            $single_batch_info = $check_batch_exists_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$single_batch_info) {
                throw new Exception("Manufacturing batch not found.");
            }
            $batch_number = $single_batch_info['batch_number'];
        } else {
            $batch_number = $batch_data[0]['batch_number'];
        }

        // Prevent deletion of 'completed' batches if products have already been transferred to inventory.
        // This logic depends on whether you want to allow full rollback including product inventory.
        // For simplicity, let's allow it, but ensure product inventory is handled.
        // If the batch status is 'completed' and its products are already in 'wholesale'/'transit' inventory,
        // those products should be removed from inventory first or a specific rollback for finished goods should occur.
        // For now, focusing on rolling back raw materials. Product inventory adjustment on deletion of a completed batch
        // would be a separate consideration.
        if (isset($single_batch_info) && $single_batch_info['status'] === 'completed') {
            // Option 1: Prevent deletion
            // throw new Exception("Cannot delete a completed batch. Adjust finished product quantity separately if needed.");
            // Option 2: Allow deletion, but note the impact on finished goods inventory
            // If the original product_id and quantity produced from manufacturing_batches is stored,
            // you might need to *decrease* product inventory here.
            // This requires fetching product_id and quantity_produced from the batch:
            $get_product_info_query = "SELECT product_id, quantity_produced FROM manufacturing_batches WHERE id = ?";
            $get_product_info_stmt = $db->prepare($get_product_info_query);
            $get_product_info_stmt->execute([$batch_id]);
            $product_info_for_batch = $get_product_info_stmt->fetch(PDO::FETCH_ASSOC);

            if ($product_info_for_batch && $product_info_for_batch['quantity_produced'] > 0) {
                // If products were produced and are in inventory (e.g., 'wholesale' or 'transit'), decrease them.
                // This assumes `inventory` might hold products from a completed batch.
                // The most reliable way is to fetch where the specific batch's products are.
                $remove_product_from_inv_query = "
                    UPDATE inventory
                    SET quantity = quantity - ?
                    WHERE product_id = ? AND (location = 'wholesale' OR location = 'transit' OR location = 'manufacturing');
                "; // Consider specific location, or just remove from any relevant stock
                $remove_product_from_inv_stmt = $db->prepare($remove_product_from_inv_query);
                $remove_product_from_inv_stmt->execute([
                    $product_info_for_batch['quantity_produced'],
                    $product_info_for_batch['product_id']
                ]);
            }
        }


        // 2. Return used raw materials to inventory.
        // Iterate through fetched batch data (materials used)
        foreach ($batch_data as $row) {
            $material_id = $row['material_id'];
            $quantity_used = $row['quantity_used'];

            $update_raw_materials_query = "UPDATE raw_materials SET stock_quantity = stock_quantity + ? WHERE id = ?";
            $update_raw_materials_stmt = $db->prepare($update_raw_materials_query);
            $update_raw_materials_stmt->execute([$quantity_used, $material_id]);

            // Log individual material rollback (optional, but detailed)
            $auth->logActivity(
                $_SESSION['user_id'],
                'update',
                'raw_materials',
                'Rolled back ' . $quantity_used . ' units of material ID ' . $material_id . ' (for batch ' . $batch_number . ') due to batch deletion. Reason: ' . $reason,
                $material_id
            );
        }

        // 3. Delete the manufacturing batch record.
        // Due to ON DELETE CASCADE constraints set in DB, `material_usage`, `quality_control`
        // and `manufacturing_costs` records related to this batch will be automatically deleted.
        // However, `product_adjustments` would also need ON DELETE CASCADE if products are deleted.
        // (Currently, product_adjustments only links to batch_id, it should cascade).
        // Let's add ON DELETE CASCADE to product_adjustments table for batch_id if not already done.
        // (This was already covered by the previous DB changes, so it should cascade).

        $delete_batch_query = "DELETE FROM manufacturing_batches WHERE id = ?";
        $delete_batch_stmt = $db->prepare($delete_batch_query);
        $delete_batch_stmt->execute([$batch_id]);

        // 4. Log the batch deletion activity.
        $auth->logActivity(
            $_SESSION['user_id'],
            'delete',
            'manufacturing_batches',
            'Deleted manufacturing batch: ' . $batch_number . '. Reason: ' . $reason,
            $batch_id
        );

        // Commit transaction.
        $db->commit();

        echo json_encode(['success' => true, 'message' => 'Batch ' . htmlspecialchars($batch_number) . ' deleted and materials rolled back successfully.']);
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
            'manufacturing_batches',
            'Failed to delete batch ' . ($batch_id ?? 'N/A') . ': ' . $e->getMessage(),
            $batch_id ?? null
        );

        echo json_encode(['success' => false, 'message' => 'An error occurred while deleting the batch: ' . $e->getMessage()]);
        exit;
    }
} else {
    // Not a POST request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
    exit;
}