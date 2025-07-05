<?php
// File: save-quality-check.php

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
// Only 'incharge' or 'owner' should be able to save quality checks.
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
        $inspector_id = $_SESSION['user_id']; // The inspector is the logged-in user
        $check_date = trim($_POST['check_date'] ?? date('Y-m-d')); // Default to current date if not provided
        $status = trim($_POST['status'] ?? ''); // 'passed', 'failed', 'pending_rework'
        $defects_found = filter_var($_POST['defects_found'] ?? 0, FILTER_VALIDATE_INT);
        $defect_description = trim($_POST['defect_description'] ?? '');
        $remedial_action = trim($_POST['remedial_action'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        // Validate inputs.
        if (!$batch_id || empty($status)) {
            throw new Exception("Missing required fields (Batch ID, Status).");
        }

        // Validate status enum.
        $allowed_statuses = ['passed', 'failed', 'pending_rework'];
        if (!in_array($status, $allowed_statuses)) {
            throw new Exception("Invalid quality check status specified.");
        }

        // Validate defects_found (must be non-negative)
        if ($defects_found < 0) {
            throw new Exception("Defects found cannot be negative.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // Check if batch exists.
        $batch_check_query = "SELECT batch_number FROM manufacturing_batches WHERE id = ?";
        $batch_check_stmt = $db->prepare($batch_check_query);
        $batch_check_stmt->execute([$batch_id]);
        $batch_info = $batch_check_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$batch_info) {
            throw new Exception("Manufacturing batch not found.");
        }

        // Insert quality control record.
        // Note: The schema has `inspection_date` as TIMESTAMP DEFAULT current_timestamp()
        // and `check_date` as DATE. Using `check_date` for user-provided date.
        $insert_qc_query = "INSERT INTO quality_control
                           (batch_id, inspector_id, created_by, check_date, status, defects_found, defect_description, remedial_action, notes)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $insert_qc_stmt = $db->prepare($insert_qc_query);
        $insert_qc_stmt->execute([
            $batch_id,
            $inspector_id, // inspector_id
            $inspector_id, // created_by (assuming inspector created the record)
            $check_date,
            $status,
            $defects_found,
            $defect_description,
            $remedial_action,
            $notes
        ]);
        $qc_id = $db->lastInsertId();

        // Log the activity using Auth class.
        $auth->logActivity(
            $inspector_id,
            'create', // Action type is 'create' for new QC record
            'quality_control',
            "Recorded quality check for batch " . htmlspecialchars($batch_info['batch_number']) . " with status: " . htmlspecialchars($status) . ". Defects: " . $defects_found . ".",
            $qc_id // Entity ID is the quality_control ID
        );

        // Commit transaction.
        $db->commit();

        // If 'redirect_url' is provided, redirect. Otherwise, return JSON.
        // For consistent API behavior, ideally remove the redirect_url logic
        // if frontend exclusively uses AJAX. Keeping for now as per existing pattern.
        if (isset($_POST['redirect_url']) && !empty($_POST['redirect_url'])) {
            $_SESSION['success_message'] = "Quality check saved successfully!";
            header('Location: ' . $_POST['redirect_url']);
            exit;
        } else {
            echo json_encode(['success' => true, 'message' => 'Quality check saved successfully.', 'qc_id' => $qc_id]);
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
            'quality_control',
            'Failed to save quality check for batch ' . ($batch_id ?? 'N/A') . ': ' . $e->getMessage(),
            $batch_id ?? null
        );

        // Handle redirect_url for error fallback.
        if (isset($_POST['redirect_url']) && !empty($_POST['redirect_url'])) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ' . $_POST['redirect_url']);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }
} else {
    // Not a POST request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
    exit;
}