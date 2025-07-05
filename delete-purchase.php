<?php
// File: api/delete-purchase.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'incharge' or 'owner' should be able to delete purchases.
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
        $purchase_id = filter_var($_POST['purchase_id'] ?? null, FILTER_VALIDATE_INT);
        $deleted_by = $_SESSION['user_id'];

        // Validate input.
        if (!$purchase_id) {
            throw new Exception("Missing or invalid Purchase ID.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // 1. Fetch purchase details and related material info.
        $purchase_info_query = "SELECT p.material_id, p.quantity, rm.stock_quantity, rm.name AS material_name, p.invoice_number, p.fund_id, p.total_amount
                                FROM purchases p
                                JOIN raw_materials rm ON p.material_id = rm.id
                                WHERE p.id = ? FOR UPDATE"; // Lock rows for consistency
        $purchase_info_stmt = $db->prepare($purchase_info_query);
        $purchase_info_stmt->execute([$purchase_id]);
        $purchase_info = $purchase_info_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$purchase_info) {
             throw new Exception("Purchase record not found."); // Or already deleted
        }

        $material_id = $purchase_info['material_id'];
        $purchased_quantity = $purchase_info['quantity'];
        $current_stock_quantity = $purchase_info['stock_quantity'];
        $material_name = $purchase_info['material_name'];
        $fund_id = $purchase_info['fund_id'];
        $total_amount = $purchase_info['total_amount'];

        // 2. Check if the materials from this purchase have been used.
        // This is complex. A simple check is to see if current stock is less than
        // what it would be if this purchase was reverted.
        // More robust: track `material_usage` against specific `purchase` IDs (requires schema change).
        // For now: check if rolling back this quantity would make stock negative or require more than current stock.
        // If current stock is less than `(original stock before this purchase - amount used)`, it's a problem.
        // Simplest: Check if *any* of this material has been used in `material_usage`.
        $used_in_production_query = "SELECT COALESCE(SUM(quantity_used), 0) FROM material_usage WHERE material_id = ?";
        $used_in_production_stmt = $db->prepare($used_in_production_query);
        $used_in_production_stmt->execute([$material_id]);
        $total_used_of_material = $used_in_production_stmt->fetchColumn();

        // This check is tricky: if you delete a purchase, how do you know if *this specific quantity*
        // was consumed? FIFO/LIFO logic is needed. Without that, the safest is to prevent if *any* has been used.
        // A direct link from material_usage to purchases.id would be ideal.
        // For now, if the current stock_quantity + purchased_quantity is less than original quantity from that purchase
        // (if we track original stock before this purchase), then it implies usage.
        // Safest approach: if current `stock_quantity` is less than `purchased_quantity`, it implies some usage.
        // If `current_stock_quantity - purchased_quantity` would be negative, it implies over-usage.
        // Or if there's any record in `material_usage` for this material, prevent deletion of purchase.
        // Let's refine this. If `material_usage` exists for this material, deletion is problematic for exact quantity tracking.
        // A more lenient approach: if `current_stock_quantity` is less than `purchased_quantity`, it means
        // some of this material *must* have been used. So, prevent.

        if ($current_stock_quantity < $purchased_quantity) {
             throw new Exception("Cannot delete purchase. " . htmlspecialchars($material_name) . " (ID: " . $material_id . ") stock is less than the purchased quantity. It implies some material has been used or transferred. Please adjust inventory manually if needed before deleting.");
        }

        // 3. Revert `raw_materials` stock (add back `purchased_quantity`).
        $update_raw_materials_query = "UPDATE raw_materials SET stock_quantity = stock_quantity - ? WHERE id = ?";
        $update_raw_materials_stmt = $db->prepare($update_raw_materials_query);
        $update_raw_materials_stmt->execute([$purchased_quantity, $material_id]);

        // 4. Revert `fund_usage` (if this purchase used a fund).
        if ($fund_id) {
            // Revert fund balance (add back `total_amount`).
            $update_fund_query = "UPDATE funds SET balance = balance + ? WHERE id = ?";
            $update_fund_stmt = $db->prepare($update_fund_query);
            $update_fund_stmt->execute([$total_amount, $fund_id]);

            // Mark fund as 'active' again if it was 'depleted' by this purchase and now has balance
            // This needs to be carefully handled to avoid re-activating depleted funds that are truly depleted by other uses.
            // Simplest: Just ensure balance is updated. Status will be managed by `record-fund-usage`.
            // More complex: Delete fund_usage record (but that loses audit trail). Best to simply adjust fund balance.
            // If the fund_usage record itself needs to be removed/marked invalid, that's another step.
            // For now, just update the fund balance.
        }

        // 5. Delete the purchase record.
        $delete_query = "DELETE FROM purchases WHERE id = ?";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->execute([$purchase_id]);

        // 6. Log the activity.
        $auth->logActivity(
            $deleted_by,
            'delete',
            'purchases',
            'Deleted purchase ID: ' . $purchase_id . ' (Material: ' . htmlspecialchars($material_name) . ', Quantity: ' . number_format($purchased_quantity, 2) . ').',
            $purchase_id
        );

        // Commit transaction.
        $db->commit();

        echo json_encode(['success' => true, 'message' => 'Purchase ' . htmlspecialchars($purchase_info['invoice_number'] ?: $purchase_id) . ' deleted successfully and material/fund reverted.']);
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
            'Failed to delete purchase ' . ($purchase_id ?? 'N/A') . ': ' . $e->getMessage(),
            $purchase_id ?? null
        );

        echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
        exit;
    }
} else {
    // Not a POST request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
    exit;
}