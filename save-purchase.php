<?php
// File: api/save-purchase.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'incharge' or 'owner' should be able to save purchases.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'incharge' && $_SESSION['role'] !== 'owner')) {
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
        $purchase_id = filter_var($_POST['purchase_id'] ?? null, FILTER_VALIDATE_INT); // Null for add, ID for edit
        $material_id = filter_var($_POST['material_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
        $unit_price = filter_var($_POST['unit_price'] ?? null, FILTER_VALIDATE_FLOAT);
        $total_amount = filter_var($_POST['total_amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $vendor_name = trim($_POST['vendor_name'] ?? '');
        $vendor_contact = trim($_POST['vendor_contact'] ?? '');
        $invoice_number = trim($_POST['invoice_number'] ?? '');
        $purchase_date = trim($_POST['purchase_date'] ?? '');
        $fund_id = filter_var($_POST['fund_id'] ?? null, FILTER_VALIDATE_INT); // Can be null
        $purchased_by = $_SESSION['user_id'];

        // Store original values for update scenario for rollback/adjustment.
        $original_material_id = null;
        $original_quantity = null;
        $original_total_amount = null;
        $original_fund_id = null;

        // Validate required fields.
        if (!$material_id || !$quantity || $quantity <= 0 || !$unit_price || $unit_price <= 0 || !$total_amount || $total_amount <= 0 || empty($purchase_date)) {
            throw new Exception("Missing or invalid required fields (Material, Quantity, Unit Price, Total Amount, Purchase Date).");
        }

        // Additional validation for calculated total amount to prevent manipulation.
        $calculated_total = round($quantity * $unit_price, 2);
        if (abs($calculated_total - $total_amount) > 0.01) { // Allow for small floating point discrepancies
            throw new Exception("Calculated total amount does not match provided total amount. Please recheck inputs.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        $message = "";
        $action_type = 'create';
        $entity_id_for_log = $purchase_id; // Default for update

        if ($purchase_id) {
            // EDITING an existing purchase.
            // Fetch current purchase details for comparison and rollback.
            $current_purchase_query = "SELECT material_id, quantity, total_amount, fund_id FROM purchases WHERE id = ? FOR UPDATE";
            $current_purchase_stmt = $db->prepare($current_purchase_query);
            $current_purchase_stmt->execute([$purchase_id]);
            $current_purchase = $current_purchase_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$current_purchase) {
                throw new Exception("Purchase record not found for editing.");
            }

            $original_material_id = $current_purchase['material_id'];
            $original_quantity = $current_purchase['quantity'];
            $original_total_amount = $current_purchase['total_amount'];
            $original_fund_id = $current_purchase['fund_id'];

            // 1. Revert original inventory and fund usage (if material/quantity/fund changed).
            if ($original_material_id !== $material_id || $original_quantity !== $quantity) {
                // Return original quantity to old material or revert stock
                $revert_stock_query = "UPDATE raw_materials SET stock_quantity = stock_quantity + ? WHERE id = ?";
                $revert_stock_stmt = $db->prepare($revert_stock_query);
                $revert_stock_stmt->execute([$original_quantity, $original_material_id]);
            }

            if ($original_fund_id && $original_fund_id !== $fund_id) {
                // Revert original fund usage
                $revert_fund_query = "UPDATE funds SET balance = balance + ? WHERE id = ?";
                $revert_fund_stmt = $db->prepare($revert_fund_query);
                $revert_fund_stmt->execute([$original_total_amount, $original_fund_id]);
            }


            // 2. Update purchase record.
            $update_query = "UPDATE purchases SET material_id = ?, quantity = ?, unit_price = ?, total_amount = ?, vendor_name = ?, vendor_contact = ?, invoice_number = ?, purchase_date = ?, fund_id = ?, updated_at = NOW() WHERE id = ?";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->execute([
                $material_id, $quantity, $unit_price, $total_amount, $vendor_name, $vendor_contact,
                $invoice_number, $purchase_date, $fund_id, $purchase_id
            ]);

            $message = "Purchase updated successfully.";
            $action_type = 'update';

        } else {
            // ADDING a new purchase.
            // Validate fund_id if provided for new purchase.
            if ($fund_id) {
                $fund_check_query = "SELECT balance, status FROM funds WHERE id = ? FOR UPDATE"; // Lock fund row
                $fund_check_stmt = $db->prepare($fund_check_query);
                $fund_check_stmt->execute([$fund_id]);
                $fund_info = $fund_check_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$fund_info || $fund_info['status'] !== 'active') {
                    throw new Exception("Selected fund is invalid or not active for use.");
                }
                if ($fund_info['balance'] < $total_amount) {
                    throw new Exception("Insufficient balance in the selected fund (Available: " . number_format($fund_info['balance'], 2) . ").");
                }
            }

            // Insert new purchase record.
            $insert_query = "INSERT INTO purchases (material_id, quantity, unit_price, total_amount, vendor_name, vendor_contact, invoice_number, purchase_date, purchased_by, fund_id)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->execute([
                $material_id, $quantity, $unit_price, $total_amount, $vendor_name, $vendor_contact,
                $invoice_number, $purchase_date, $purchased_by, $fund_id
            ]);
            $entity_id_for_log = $db->lastInsertId();
            $message = "Purchase recorded successfully.";
            $action_type = 'create';
        }

        // 3. Update raw_materials stock.
        // For editing, it's (current stock - original qty + new qty)
        // For adding, it's (current stock + new qty)
        $stock_change_amount = $quantity;
        if ($purchase_id) { // If editing
            $stock_change_amount = $quantity - $original_quantity; // Net change
            if ($original_material_id !== $material_id) { // If material itself changed, original was already reverted
                 $stock_change_amount = $quantity; // Just add the new quantity
            }
        }

        $update_stock_query = "UPDATE raw_materials SET stock_quantity = stock_quantity + ? WHERE id = ?";
        $update_stock_stmt = $db->prepare($update_stock_query);
        $update_stock_stmt->execute([$stock_change_amount, $material_id]);

        // 4. Record/Update fund usage.
        if ($fund_id) {
            // For ADD: Record fund usage.
            if (!$purchase_id) {
                $fund_usage_query = "INSERT INTO fund_usage (fund_id, amount, type, allocation_type, reference_id, used_by, notes)
                                     VALUES (?, ?, 'purchase', 'purchase', ?, ?, ?)";
                $fund_usage_stmt = $db->prepare($fund_usage_query);
                $fund_usage_stmt->execute([$fund_id, $total_amount, $entity_id_for_log, $purchased_by, 'Purchase of ' . $quantity . ' units of material_id ' . $material_id]);

                // Update fund balance
                $update_fund_balance_query = "UPDATE funds SET balance = balance - ? WHERE id = ?";
                $update_fund_balance_stmt = $db->prepare($update_fund_balance_query);
                $update_fund_balance_stmt->execute([$total_amount, $fund_id]);

                // Check if fund balance depleted and update status
                $fund_balance_after_use_query = "SELECT balance FROM funds WHERE id = ?";
                $fund_balance_after_use_stmt = $db->prepare($fund_balance_after_use_query);
                $fund_balance_after_use_stmt->execute([$fund_id]);
                $current_fund_balance = $fund_balance_after_use_stmt->fetchColumn();

                if ($current_fund_balance <= 0) {
                    $deplete_fund_query = "UPDATE funds SET status = 'depleted' WHERE id = ?";
                    $deplete_fund_stmt = $db->prepare($deplete_fund_query);
                    $deplete_fund_stmt->execute([$fund_id]);
                }
            }
            // For EDIT: Fund changed, or amount changed within same fund.
            else {
                // If original fund was different, we already reverted it. Now apply to new fund.
                // If fund is the same, just update the balance net change.
                $fund_balance_change = $total_amount;
                if ($original_fund_id === $fund_id) {
                    $fund_balance_change = $total_amount - $original_total_amount; // Net change for same fund
                }
                
                $update_fund_balance_query = "UPDATE funds SET balance = balance - ? WHERE id = ?";
                $update_fund_balance_stmt = $db->prepare($update_fund_balance_query);
                $update_fund_balance_stmt->execute([$fund_balance_change, $fund_id]);

                 // If this purchase used a fund, try to update the existing fund_usage record
                 // Or create a new one if it didn't exist before or fund changed.
                 $fund_usage_update_query = "UPDATE fund_usage SET amount = ?, used_at = NOW(), notes = ? WHERE fund_id = ? AND reference_id = ? AND type = 'purchase'";
                 $fund_usage_update_stmt = $db->prepare($fund_usage_update_query);
                 $fund_usage_updated = $fund_usage_update_stmt->execute([
                     $total_amount,
                     'Purchase ' . $invoice_number . ' updated.',
                     $fund_id,
                     $purchase_id
                 ]);

                 if (!$fund_usage_updated || $fund_usage_update_stmt->rowCount() === 0) {
                     // If no existing usage record to update (e.g., fund was just added, or reference_id was wrong)
                     // or if fund_id changed, insert a new record
                     $fund_usage_insert_query = "INSERT INTO fund_usage (fund_id, amount, type, allocation_type, reference_id, used_by, notes) VALUES (?, ?, 'purchase', 'purchase', ?, ?, ?)";
                     $fund_usage_insert_stmt = $db->prepare($fund_usage_insert_query);
                     $fund_usage_insert_stmt->execute([
                         $fund_id,
                         $total_amount,
                         $purchase_id,
                         $purchased_by,
                         'Purchase ' . $invoice_number . ' used fund.'
                     ]);
                 }
            }
        }

        // Log activity using Auth class.
        $material_name_query = "SELECT name FROM raw_materials WHERE id = ?";
        $material_name_stmt = $db->prepare($material_name_query);
        $material_name_stmt->execute([$material_id]);
        $material_name = $material_name_stmt->fetchColumn();

        $log_description = ($action_type === 'create' ? 'Recorded new' : 'Updated') . " purchase of " . number_format($quantity, 2) . " units of " . htmlspecialchars($material_name) . " for Rs. " . number_format($total_amount, 2);
        if (!empty($invoice_number)) {
            $log_description .= " (Invoice: " . htmlspecialchars($invoice_number) . ")";
        }

        $auth->logActivity(
            $_SESSION['user_id'],
            $action_type,
            'purchases',
            $log_description,
            $entity_id_for_log
        );

        // Commit transaction.
        $db->commit();

        echo json_encode(['success' => true, 'message' => $message, 'purchase_id' => $entity_id_for_log]);
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
            'purchases',
            'Failed to save purchase: ' . $e->getMessage(),
            $purchase_id ?? null
        );

        echo json_encode(['success' => false, 'message' => 'An error occurred while saving the purchase: ' . $e->getMessage()]);
        exit;
    }
} else {
    // Not a POST request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
    exit;
}