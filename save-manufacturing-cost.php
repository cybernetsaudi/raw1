<?php
// File: save-manufacturing-cost.php

// Ensure session is started at the very beginning of the script.
session_start();

// DEVELOPMENT ONLY: Remove these lines in production environment for security.
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'incharge' or 'owner' should be able to save manufacturing costs.
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
        $batch_id = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
        $cost_type = trim($_POST['cost_type'] ?? '');
        $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $description = trim($_POST['description'] ?? '');
        $recorded_by = $_SESSION['user_id'];
        $recorded_date = date('Y-m-d'); // Use current date for recording

        // Validate inputs.
        if (!$batch_id || !$amount || $amount <= 0 || empty($cost_type)) {
            throw new Exception("Missing or invalid required fields.");
        }

        // Validate cost_type enum (matching `manufacturing_costs` table enum).
        $allowed_cost_types = ['labor','material','packaging','zipper','sticker','logo','tag','misc','overhead','electricity','maintenance','other'];
        if (!in_array($cost_type, $allowed_cost_types)) {
            throw new Exception("Invalid cost type specified.");
        }

        // Check if batch exists and is not completed
        $batch_check_query = "SELECT batch_number, status FROM manufacturing_batches WHERE id = ?";
        $batch_check_stmt = $db->prepare($batch_check_query);
        $batch_check_stmt->execute([$batch_id]);
        $batch_info = $batch_check_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$batch_info) {
            throw new Exception("Batch not found.");
        }
        if ($batch_info['status'] === 'completed') {
            throw new Exception("Cannot add costs to a completed batch (" . htmlspecialchars($batch_info['batch_number']) . ").");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // Insert manufacturing cost record.
        $insert_cost_query = "INSERT INTO manufacturing_costs
                             (batch_id, cost_type, amount, description, recorded_by, recorded_date)
                             VALUES (?, ?, ?, ?, ?, ?)";
        $insert_cost_stmt = $db->prepare($insert_cost_query);
        $insert_cost_stmt->execute([$batch_id, $cost_type, $amount, $description, $recorded_by, $recorded_date]);
        $cost_id = $db->lastInsertId();

        // Log the activity using Auth class.
        $auth->logActivity(
            $recorded_by,
            'create', // Action type is 'create' for new cost record
            'manufacturing_costs',
            "Added " . htmlspecialchars($cost_type) . " cost of " . number_format($amount, 2) . " to batch " . htmlspecialchars($batch_info['batch_number']),
            $cost_id // Entity ID is the manufacturing_cost ID
        );

        // Commit transaction.
        $db->commit();

        // Handle redirect_url if present (for traditional form submission fallback).
        // For consistent API, this part should ideally be removed if frontend strictly uses AJAX.
        if (isset($_POST['redirect_url']) && !empty($_POST['redirect_url'])) {
            $_SESSION['success_message'] = "Cost added successfully!";
            header('Location: ' . $_POST['redirect_url']);
            exit;
        } else {
            // Return success JSON response for AJAX.
            echo json_encode(['success' => true, 'message' => 'Manufacturing cost saved successfully.', 'cost_id' => $cost_id]);
            exit;
        }

    } catch (Exception $e) {
        // Rollback transaction on error.
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        // Log the error using Auth class.
        $auth->logActivity(
            $_SESSION['user_id'] ?? null,
            'error',
            'manufacturing_costs',
            'Failed to save manufacturing cost: ' . $e->getMessage(),
            $batch_id ?? null
        );

        // Handle redirect_url for error fallback.
        if (isset($_POST['redirect_url']) && !empty($_POST['redirect_url'])) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ' . $_POST['redirect_url']);
            exit;
        } else {
            // Return error JSON response for AJAX.
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }
} else {
    // Not a POST request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
    exit;
}