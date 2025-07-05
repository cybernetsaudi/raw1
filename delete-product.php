<?php
// File: api/delete-product.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'owner' or 'incharge' should be able to delete products.
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
        $deleted_by = $_SESSION['user_id'];

        // Validate input.
        if (!$product_id) {
            throw new Exception("Missing or invalid Product ID.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // 1. Check for dependent records: `sale_items`, `inventory`, `manufacturing_batches`, `product_adjustments`.
        // If product appears in `sale_items`
        $check_sale_items_query = "SELECT COUNT(*) FROM sale_items WHERE product_id = ?";
        $check_sale_items_stmt = $db->prepare($check_sale_items_query);
        $check_sale_items_stmt->execute([$product_id]);
        $sale_items_count = $check_sale_items_stmt->fetchColumn();
        if ($sale_items_count > 0) {
            throw new Exception("Cannot delete product. It has " . $sale_items_count . " associated sale items. Please delete or reassign sales first.");
        }

        // If product appears in `inventory` with quantity > 0
        $check_inventory_query = "SELECT SUM(quantity) FROM inventory WHERE product_id = ?";
        $check_inventory_stmt = $db->prepare($check_inventory_query);
        $check_inventory_stmt->execute([$product_id]);
        $inventory_quantity = $check_inventory_stmt->fetchColumn();
        if ($inventory_quantity > 0) {
            throw new Exception("Cannot delete product. It has " . $inventory_quantity . " units in inventory. Please adjust inventory to zero first.");
        }

        // If product appears in `manufacturing_batches` (even if completed, for history)
        $check_batches_query = "SELECT COUNT(*) FROM manufacturing_batches WHERE product_id = ?";
        $check_batches_stmt = $db->prepare($check_batches_query);
        $check_batches_stmt->execute([$product_id]);
        $batches_count = $check_batches_stmt->fetchColumn();
        if ($batches_count > 0) {
            throw new Exception("Cannot delete product. It has " . $batches_count . " associated manufacturing batches. Please delete or unlink batches first.");
        }

        // If product appears in `product_adjustments`
        $check_adjustments_query = "SELECT COUNT(*) FROM product_adjustments WHERE product_id = ?";
        $check_adjustments_stmt = $db->prepare($check_adjustments_query);
        $check_adjustments_stmt->execute([$product_id]);
        $adjustments_count = $check_adjustments_stmt->fetchColumn();
        if ($adjustments_count > 0) {
            // Delete product adjustments as they are history of deleted batches/products
            $delete_adjustments_query = "DELETE FROM product_adjustments WHERE product_id = ?";
            $delete_adjustments_stmt = $db->prepare($delete_adjustments_query);
            $delete_adjustments_stmt->execute([$product_id]);
        }

        // Fetch product name and SKU for logging before deletion.
        $product_info_query = "SELECT name, sku FROM products WHERE id = ?";
        $product_info_stmt = $db->prepare($product_info_query);
        $product_info_stmt->execute([$product_id]);
        $product_info = $product_info_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product_info) {
             throw new Exception("Product not found."); // Or already deleted
        }
        $product_name = $product_info['name'];
        $product_sku = $product_info['sku'];

        // 2. Delete the product record.
        // Assuming no `image_path` cleanup is needed here, or it should be handled manually if file system access.
        $delete_query = "DELETE FROM products WHERE id = ?";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->execute([$product_id]);

        // 3. Log the activity.
        $auth->logActivity(
            $deleted_by,
            'delete',
            'products',
            'Deleted product: ' . htmlspecialchars($product_name) . ' (SKU: ' . htmlspecialchars($product_sku) . ').',
            $product_id
        );

        // Commit transaction.
        $db->commit();

        echo json_encode(['success' => true, 'message' => 'Product ' . htmlspecialchars($product_name) . ' deleted successfully.']);
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
            'products',
            'Failed to delete product ' . ($product_id ?? 'N/A') . ': ' . $e->getMessage(),
            $product_id ?? null
        );

        echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
        exit;
    }
} else {
    // Not a POST request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
    exit;
}