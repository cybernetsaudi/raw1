<?php
// File: get-material.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Assuming 'owner' and 'incharge' can view material details.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'incharge')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// Get database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

// Process GET request.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Get and sanitize input data.
        $material_id = filter_var($_GET['material_id'] ?? null, FILTER_VALIDATE_INT);

        // Validate inputs.
        if (!$material_id) {
            throw new Exception("Missing or invalid material ID.");
        }

        // Fetch material details.
        $material_query = "SELECT id, name, description, unit, stock_quantity, min_stock_level FROM raw_materials WHERE id = ?";
        $material_stmt = $db->prepare($material_query);
        $material_stmt->execute([$material_id]);
        $material = $material_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$material) {
            throw new Exception("Raw material not found.");
        }

        // Fetch purchase history for the material.
        $purchase_history_query = "SELECT purchase_date, quantity, unit_price, total_amount, vendor_name FROM purchases WHERE material_id = ? ORDER BY purchase_date DESC LIMIT 10";
        $purchase_history_stmt = $db->prepare($purchase_history_query);
        $purchase_history_stmt->execute([$material_id]);
        $purchase_history = $purchase_history_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch usage history for the material.
        $usage_history_query = "
            SELECT mu.recorded_date, mu.quantity_used, mb.batch_number, mb.product_id, p.name AS product_name
            FROM material_usage mu
            JOIN manufacturing_batches mb ON mu.batch_id = mb.id
            JOIN products p ON mb.product_id = p.id
            WHERE mu.material_id = ?
            ORDER BY mu.recorded_date DESC
            LIMIT 10
        ";
        $usage_history_stmt = $db->prepare($usage_history_query);
        $usage_history_stmt->execute([$material_id]);
        $usage_history = $usage_history_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Log the activity using Auth class.
        $auth->logActivity(
            $_SESSION['user_id'],
            'read',
            'raw_materials',
            'Fetched details for raw material: ' . htmlspecialchars($material['name']),
            $material_id
        );

        // Return success response.
        echo json_encode([
            'success' => true,
            'data' => [
                'material' => $material,
                'purchase_history' => $purchase_history,
                'usage_history' => $usage_history
            ],
            'message' => 'Material data fetched successfully.'
        ]);
        exit;

    } catch (Exception $e) {
        // Log the error using Auth class.
        $auth->logActivity(
            $_SESSION['user_id'] ?? null,
            'error',
            'raw_materials',
            'Failed to fetch material details: ' . $e->getMessage(),
            $material_id ?? null
        );

        // Return error response.
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
} else {
    // Not a GET request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only GET requests are allowed.']);
    exit;
}