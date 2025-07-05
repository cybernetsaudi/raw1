<?php
// File: save-batch.php

// Ensure session is started at the very beginning
session_start();

// DEVELOPMENT ONLY: Remove these lines in production environment for security
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

// Include necessary configuration and authentication classes
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response
header('Content-Type: application/json');

// Check user authentication and role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'incharge') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for logging
$auth = new Auth($db);

// Process form data
$product_id = $_POST['product_id'] ?? null;
$quantity = $_POST['quantity_produced'] ?? null;
$start_date = $_POST['start_date'] ?? null;
$expected_completion_date = $_POST['expected_completion_date'] ?? null;
$notes = $_POST['notes'] ?? '';
$created_by = $_SESSION['user_id'];

// Validate required fields
if (!$product_id || !$quantity || !$start_date || !$expected_completion_date) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    // Log the incomplete attempt
    $auth->logActivity(
        $_SESSION['user_id'],
        'error',
        'manufacturing',
        'Attempted to create batch with missing fields.',
        null
    );
    exit;
}

// Generate batch number
$batch_number = 'BATCH-' . date('Ymd') . '-' . rand(1000, 9999);

// Start transaction for atomicity
$db->beginTransaction();

try {
    // Insert batch record
    $batch_query = "INSERT INTO manufacturing_batches 
                   (batch_number, product_id, quantity_produced, status, start_date, 
                   expected_completion_date, notes, created_by) 
                   VALUES (?, ?, ?, 'pending', ?, ?, ?, ?)";
    $batch_stmt = $db->prepare($batch_query);
    $batch_stmt->execute([
        $batch_number,
        $product_id,
        $quantity,
        $start_date,
        $expected_completion_date,
        $notes,
        $created_by
    ]);

    $batch_id = $db->lastInsertId();

    // Process materials - handle both array format and flat format
    $materials = [];

    // Check if materials is submitted as an array directly (e.g., from modern JS fetch)
    if (isset($_POST['materials']) && is_array($_POST['materials'])) {
        $materials = $_POST['materials'];
    }
    // Check if materials are submitted in a flat format (e.g., from traditional HTML form)
    else {
        $material_index = 0;
        $flat_materials = [];

        // Keep checking for material entries until we don't find any more
        while (isset($_POST["materials[{$material_index}][material_id]"]) ||
               isset($_POST["materials[{$material_index}][quantity]"])) {

            $material_id = $_POST["materials[{$material_index}][material_id]"] ?? null;
            $quantity_used = $_POST["materials[{$material_index}][quantity]"] ?? null;

            if ($material_id && $quantity_used) {
                $flat_materials[] = [
                    'material_id' => $material_id,
                    'quantity' => $quantity_used
                ];
            }
            $material_index++;
        }
        if (!empty($flat_materials)) {
            $materials = $flat_materials;
        }
    }

    // Process the materials array
    if (!empty($materials)) {
        foreach ($materials as $material) {
            // Basic validation for material data
            if (!isset($material['material_id']) || !isset($material['quantity']) ||
                !is_numeric($material['material_id']) || !is_numeric($material['quantity']) ||
                $material['quantity'] <= 0) {
                throw new Exception("Invalid material data provided.");
            }

            $material_id = intval($material['material_id']);
            $quantity_used = floatval($material['quantity']);

            // Check if we have enough material in stock
            $stock_check = "SELECT stock_quantity FROM raw_materials WHERE id = ?";
            $stock_stmt = $db->prepare($stock_check);
            $stock_stmt->execute([$material_id]);
            $available_stock = $stock_stmt->fetchColumn();

            if ($available_stock < $quantity_used) {
                throw new Exception("Insufficient stock for material ID " . $material_id .
                                  ". Available: " . $available_stock . ", Required: " . $quantity_used . ".");
            }

            // Insert material usage
            // Note: `quantity_required` column in `material_usage` table. Assuming it represents `quantity_used`.
            $material_query = "INSERT INTO material_usage
                              (batch_id, material_id, quantity_used, quantity_required, recorded_by)
                              VALUES (?, ?, ?, ?, ?)";
            $material_stmt = $db->prepare($material_query);
            $material_stmt->execute([
                $batch_id,
                $material_id,
                $quantity_used, // quantity_used
                $quantity_used, // quantity_required (assuming same for now, adjust if logic differs)
                $created_by
            ]);

            // Update material inventory
            $update_query = "UPDATE raw_materials
                           SET stock_quantity = stock_quantity - ?
                           WHERE id = ?";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->execute([
                $quantity_used,
                $material_id
            ]);
        }
    } else {
        // Log activity for batches created without materials (can be a warning)
        $auth->logActivity(
            $_SESSION['user_id'],
            'warning', // Use a 'warning' action type for this case
            'manufacturing',
            'Batch created without materials. Batch Number: ' . $batch_number,
            $batch_id
        );
    }

    // Commit transaction
    $db->commit();

    // Log successful batch creation
    $auth->logActivity(
        $_SESSION['user_id'],
        'create',
        'manufacturing',
        'Created new manufacturing batch: ' . $batch_number,
        $batch_id
    );

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Batch created successfully.',
        'batch_id' => $batch_id,
        'batch_number' => $batch_number
    ]);
    exit;

} catch (Exception $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) { // Check if transaction is active before rolling back
        $db->rollBack();
    }

    // Log the error using Auth class
    $auth->logActivity(
        $_SESSION['user_id'],
        'error',
        'manufacturing',
        'Failed to create batch: ' . $e->getMessage(),
        null
    );

    // Return error response
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while creating the batch: ' . $e->getMessage()
    ]);
    exit;
}