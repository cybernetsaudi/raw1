<?php
// File: api/delete-material.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'owner' or 'incharge' should be able to delete raw materials.
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
        $material_id = filter_var($_POST['material_id'] ?? null, FILTER_VALIDATE_INT);
        $deleted_by = $_SESSION['user_id'];

        // Validate input.
        if (!$material_id) {
            throw new Exception("Missing or invalid Material ID.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // 1. Check for dependent records: `purchases`, `material_usage`.
        // Check if material has associated purchases.
        $check_purchases_query = "SELECT COUNT(*) FROM purchases WHERE material_id = ?";
        $check_purchases_stmt = $db->prepare($check_purchases_query);
        $check_purchases_stmt->execute([$material_id]);
        $purchases_count = $check_purchases_stmt->fetchColumn();

        if ($purchases_count > 0) {
            throw new Exception("Cannot delete raw material. It has " . $purchases_count . " associated purchase records. Please delete purchases first.");
        }

        // Check if material has been used in production.
        $check_usage_query = "SELECT COUNT(*) FROM material_usage WHERE material_id = ?";
        $check_usage_stmt = $db->prepare($check_usage_query);
        $check_usage_stmt->execute([$material_id]);
        $usage_count = $check_usage_stmt->fetchColumn();

        if ($usage_count > 0) {
            throw new Exception("Cannot delete raw material. It has " . $usage_count . " associated material usage records. Please delete manufacturing batches using this material first.");
        }

        // Check if stock_quantity is 0 (to prevent deleting material with physical stock).
        $check_stock_query = "SELECT stock_quantity, name FROM raw_materials WHERE id = ?";
        $check_stock_stmt = $db->prepare($check_stock_query);
        $check_stock_stmt->execute([$material_id]);
        $material_info = $check_stock_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$material_info) {
             throw new Exception("Raw material not found."); // Or already deleted
        }
        $material_name = $material_info['name'];
        $current_stock = $material_info['stock_quantity'];

        if ($current_stock > 0) {
            throw new Exception("Cannot delete raw material. Current stock quantity is " . number_format($current_stock, 2) . ". Please adjust stock to zero first.");
        }

        // 2. Delete the raw material record.
        $delete_query = "DELETE FROM raw_materials WHERE id = ?";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->execute([$material_id]);

        // 3. Log the activity.
        $auth->logActivity(
            $deleted_by,
            'delete',
            'raw_materials',
            'Deleted raw material: ' . htmlspecialchars($material_name) . ' (ID: ' . $material_id . ').',
            $material_id
        );

        // Commit transaction.
        $db->commit();

        echo json_encode(['success' => true, 'message' => 'Raw material ' . htmlspecialchars($material_name) . ' deleted successfully.']);
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
            'raw_materials',
            'Failed to delete raw material ' . ($material_id ?? 'N/A') . ': ' . $e->getMessage(),
            $material_id ?? null
        );

        echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
        exit;
    }
} else {
    // Not a POST request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
    exit;
}