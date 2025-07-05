<?php
// File: get-batch-costing.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Assuming 'owner' and 'incharge' can view batch costing.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'incharge')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// DEVELOPMENT ONLY: Remove these lines in production environment.
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

// Get database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

// Process GET request.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Get and sanitize input data.
        $batch_id = filter_var($_GET['batch_id'] ?? null, FILTER_VALIDATE_INT);

        // Validate inputs.
        if (!$batch_id) {
            throw new Exception("Missing or invalid batch ID.");
        }

        // Fetch batch details.
        $batch_query = "SELECT id, quantity_produced, status, batch_number FROM manufacturing_batches WHERE id = ?";
        $batch_stmt = $db->prepare($batch_query);
        $batch_stmt->execute([$batch_id]);
        $batch = $batch_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$batch) {
            throw new Exception("Batch not found.");
        }

        // Fetch total manufacturing costs for the batch.
        $total_costs_query = "SELECT SUM(amount) AS total_manufacturing_cost FROM manufacturing_costs WHERE batch_id = ?";
        $total_costs_stmt = $db->prepare($total_costs_query);
        $total_costs_stmt->execute([$batch_id]);
        $total_manufacturing_cost = $total_costs_stmt->fetchColumn() ?: 0;

        // Fetch material usage cost.
        // This query sums up the cost of materials used for a batch.
        // It tries to find the average unit price from the last 5 purchases of each material.
        // NOTE: This logic for average unit price might not align with standard accounting (e.g., FIFO/LIFO).
        // For more accurate costing, consider implementing a proper inventory costing method.
        $material_cost_query = "
            SELECT
                SUM(mu.quantity_used * (
                    SELECT AVG(p.unit_price)
                    FROM purchases p
                    WHERE p.material_id = mu.material_id
                    ORDER BY p.purchase_date DESC
                    LIMIT 5
                )) AS total_material_cost
            FROM material_usage mu
            WHERE mu.batch_id = ?
        ";
        $material_cost_stmt = $db->prepare($material_cost_query);
        $material_cost_stmt->execute([$batch_id]);
        $total_material_cost = $material_cost_stmt->fetchColumn() ?: 0;

        // Calculate total batch cost.
        $total_batch_cost = $total_manufacturing_cost + $total_material_cost;

        // Calculate cost per unit.
        $cost_per_unit = 0;
        if ($batch['quantity_produced'] > 0) {
            $cost_per_unit = $total_batch_cost / $batch['quantity_produced'];
        }

        // Log the activity using Auth class.
        $auth->logActivity(
            $_SESSION['user_id'],
            'read',
            'manufacturing_batches',
            'Viewed costing for batch: ' . htmlspecialchars($batch['batch_number']),
            $batch_id
        );

        // Return success response.
        echo json_encode([
            'success' => true,
            'data' => [
                'batch_id' => $batch_id,
                'batch_number' => htmlspecialchars($batch['batch_number']),
                'quantity_produced' => $batch['quantity_produced'],
                'total_manufacturing_cost' => round($total_manufacturing_cost, 2),
                'total_material_cost' => round($total_material_cost, 2),
                'total_batch_cost' => round($total_batch_cost, 2),
                'cost_per_unit' => round($cost_per_unit, 2)
            ],
            'message' => 'Batch costing fetched successfully.'
        ]);
        exit;

    } catch (Exception $e) {
        // Log the error using Auth class.
        $auth->logActivity(
            $_SESSION['user_id'] ?? null,
            'error',
            'manufacturing_batches',
            'Failed to fetch batch costing: ' . $e->getMessage(),
            $batch_id ?? null
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