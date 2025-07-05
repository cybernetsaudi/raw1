<?php
// File: save-material.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'incharge' or 'owner' should be able to save materials.
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
        $material_id = filter_var($_POST['material_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $unit = trim($_POST['unit'] ?? '');
        $stock_quantity = filter_var($_POST['stock_quantity'] ?? null, FILTER_VALIDATE_FLOAT);
        $min_stock_level = filter_var($_POST['min_stock_level'] ?? null, FILTER_VALIDATE_FLOAT);
        $logged_in_user_id = $_SESSION['user_id'];

        // Validate inputs.
        if (empty($name) || empty($unit) || !is_numeric($stock_quantity) || $stock_quantity < 0 || !is_numeric($min_stock_level) || $min_stock_level < 0) {
            throw new Exception("Missing or invalid required fields.");
        }

        // Validate unit enum.
        $allowed_units = ['meter', 'kg', 'piece'];
        if (!in_array($unit, $allowed_units)) {
            throw new Exception("Invalid unit specified. Must be meter, kg, or piece.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        $message = ""; // Initialize message variable
        $entity_id = $material_id; // For logging

        if ($material_id) {
            // Update existing material.
            // Check if material name already exists for another material
            $check_name_query = "SELECT id FROM raw_materials WHERE name = ? AND id != ?";
            $check_name_stmt = $db->prepare($check_name_query);
            $check_name_stmt->execute([$name, $material_id]);
            if ($check_name_stmt->rowCount() > 0) {
                throw new Exception("A raw material with this name already exists.");
            }

            $query = "UPDATE raw_materials
                     SET name = ?, description = ?, unit = ?, stock_quantity = ?, min_stock_level = ?, updated_at = NOW()
                     WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$name, $description, $unit, $stock_quantity, $min_stock_level, $material_id]);
            $message = "Raw material updated successfully.";
            $action_type = 'update';
        } else {
            // Create new material.
            // Check if material name already exists
            $check_name_query = "SELECT id FROM raw_materials WHERE name = ?";
            $check_name_stmt = $db->prepare($check_name_query);
            $check_name_stmt->execute([$name]);
            if ($check_name_stmt->rowCount() > 0) {
                throw new Exception("A raw material with this name already exists.");
            }

            $query = "INSERT INTO raw_materials (name, description, unit, stock_quantity, min_stock_level, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
            $stmt = $db->prepare($query);
            $stmt->execute([$name, $description, $unit, $stock_quantity, $min_stock_level]);
            $entity_id = $db->lastInsertId(); // Get ID of newly created material
            $message = "Raw material added successfully.";
            $action_type = 'create';
        }

        // Log the activity using Auth class.
        $auth->logActivity(
            $logged_in_user_id,
            $action_type,
            'raw_materials',
            ($action_type === 'create' ? 'Added new' : 'Updated') . ' raw material: ' . htmlspecialchars($name),
            $entity_id
        );

        // Commit transaction.
        $db->commit();

        // Return success response.
        echo json_encode(['success' => true, 'message' => $message, 'material_id' => $entity_id]);
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
            'raw_materials',
            'Failed to save raw material: ' . $e->getMessage(),
            $material_id ?? null
        );

        // Return error response.
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
} else {
    // Not a POST request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
    exit;
}