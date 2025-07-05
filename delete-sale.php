<?php
// File: api/delete-sale.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'shopkeeper' (for their own sales) or 'owner' (for all sales) should be able to delete sales.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'shopkeeper' && $_SESSION['role'] !== 'owner')) {
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
        $sale_id = filter_var($_POST['sale_id'] ?? null, FILTER_VALIDATE_INT);
        $deleted_by = $_SESSION['user_id'];

        // Validate input.
        if (!$sale_id) {
            throw new Exception("Missing or invalid Sale ID.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // 1. Fetch sale details and check ownership if shopkeeper.
        $sale_info_query = "SELECT invoice_number, created_by FROM sales WHERE id = ?";
        $sale_info_stmt = $db->prepare($sale_info_query);
        $sale_info_stmt->execute([$sale_id]);
        $sale_info = $sale_info_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale_info) {
             throw new Exception("Sale record not found."); // Or already deleted
        }
        $invoice_number = $sale_info['invoice_number'];
        $sale_creator_id = $sale_info['created_by'];

        if ($_SESSION['role'] === 'shopkeeper' && $sale_creator_id !== $deleted_by) {
            throw new Exception("You do not have permission to delete this sale.");
        }

        // 2. Check for dependent records: `payments`, `fund_returns`, `sale_items`.
        // If sale has associated payments.
        $check_payments_query = "SELECT COUNT(*) FROM payments WHERE sale_id = ?";
        $check_payments_stmt = $db->prepare($check_payments_query);
        $check_payments_stmt->execute([$sale_id]);
        $payments_count = $check_payments_stmt->fetchColumn();

        if ($payments_count > 0) {
            throw new Exception("Cannot delete sale. It has " . $payments_count . " associated payment records. Please void payments first.");
        }

        // If sale has associated fund returns.
        $check_fund_returns_query = "SELECT COUNT(*) FROM fund_returns WHERE sale_id = ?";
        $check_fund_returns_stmt = $db->prepare($check_fund_returns_query);
        $check_fund_returns_stmt->execute([$sale_id]);
        $fund_returns_count = $check_fund_returns_stmt->fetchColumn();

        if ($fund_returns_count > 0) {
            throw new Exception("Cannot delete sale. It has " . $fund_returns_count . " associated fund return requests. Please manage them first.");
        }

        // IMPORTANT: Revert inventory for `sale_items` *before* deleting `sale_items`
        // Fetch sale items to revert inventory
        $sale_items_to_revert_query = "SELECT product_id, quantity FROM sale_items WHERE sale_id = ? FOR UPDATE"; // Lock items
        $sale_items_to_revert_stmt = $db->prepare($sale_items_to_revert_query);
        $sale_items_to_revert_stmt->execute([$sale_id]);
        $items_to_revert = $sale_items_to_revert_stmt->fetchAll(PDO::FETCH_ASSOC);

        $update_inventory_query = "UPDATE inventory SET quantity = quantity + ? WHERE product_id = ? AND location = 'wholesale'"; // Assuming sales are from wholesale
        $update_inventory_stmt = $db->prepare($update_inventory_query);

        foreach ($items_to_revert as $item) {
            $update_inventory_stmt->execute([$item['quantity'], $item['product_id']]);
        }

        // Now, delete `sale_items` (as it is dependent on `sales` via `sale_id`).
        // The schema for `sale_items` foreign key `sale_items_ibfk_1` has no `ON DELETE CASCADE`.
        // So we must manually delete sale items first.
        $delete_sale_items_query = "DELETE FROM sale_items WHERE sale_id = ?";
        $delete_sale_items_stmt = $db->prepare($delete_sale_items_query);
        $delete_sale_items_stmt->execute([$sale_id]);

        // 3. Delete the sales record.
        $delete_sale_query = "DELETE FROM sales WHERE id = ?";
        $delete_sale_stmt = $db->prepare($delete_sale_query);
        $delete_sale_stmt->execute([$sale_id]);

        // 4. Log the activity.
        $auth->logActivity(
            $deleted_by,
            'delete',
            'sales',
            'Deleted sale: ' . htmlspecialchars($invoice_number) . ' (ID: ' . $sale_id . '). Inventory reverted.',
            $sale_id
        );

        // Commit transaction.
        $db->commit();

        echo json_encode(['success' => true, 'message' => 'Sale ' . htmlspecialchars($invoice_number) . ' deleted and inventory reverted successfully.']);
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
            'sales',
            'Failed to delete sale ' . ($sale_id ?? 'N/A') . ': ' . $e->getMessage(),
            $sale_id ?? null
        );

        echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
        exit;
    }
} else {
    // Not a POST request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
    exit;
}