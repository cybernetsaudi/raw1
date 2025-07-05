<?php
// File: quick-add-product.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Check user authentication and role (e.g., 'shopkeeper' or 'incharge' can quick add products).
// This file is likely called by an AJAX modal or iframe, so we will return JSON.
// If it's meant to be a full page redirect, the output and redirect logic needs to match that.
// Assuming it's an API-like handler for a quick add form.
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'shopkeeper' && $_SESSION['role'] !== 'incharge')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// Get database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

// Process form submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get and sanitize input data.
        $name = trim($_POST['name'] ?? '');
        $sku = trim($_POST['sku'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? ''); // Optional
        $created_by = $_SESSION['user_id'];

        // Server-side validation for required fields.
        if (empty($name) || empty($sku) || empty($category)) {
            throw new Exception("Product Name, SKU, and Category are required.");
        }

        // Validate SKU uniqueness.
        $check_sku_query = "SELECT id FROM products WHERE sku = ?";
        $check_sku_stmt = $db->prepare($check_sku_query);
        $check_sku_stmt->execute([$sku]);
        if ($check_sku_stmt->rowCount() > 0) {
            throw new Exception("SKU '" . htmlspecialchars($sku) . "' already exists. Please use a unique SKU.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // Insert product record.
        // Assuming no image upload for quick add. If needed, this file should match add-product.php.
        $insert_query = "INSERT INTO products (name, description, sku, category, created_by, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
        $insert_stmt = $db->prepare($insert_query);
        $insert_stmt->execute([
            $name, $description, $sku, $category, $created_by
        ]);
        $product_id = $db->lastInsertId();

        // Log the activity using Auth class.
        $auth->logActivity(
            $_SESSION['user_id'],
            'create',
            'products',
            'Quick-added new product: ' . htmlspecialchars($name) . ' (SKU: ' . htmlspecialchars($sku) . ')',
            $product_id
        );

        // Commit transaction.
        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Product quick-added successfully!',
            'product_id' => $product_id,
            'product_name' => htmlspecialchars($name),
            'product_sku' => htmlspecialchars($sku),
            'product_category' => htmlspecialchars($category)
        ]);
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
            'products',
            'Failed to quick-add product: ' . $e->getMessage(),
            null
        );

        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
} else {
    // Not a POST request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
    exit;
}