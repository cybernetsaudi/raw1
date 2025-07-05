<?php
// File: record-fund-usage.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'incharge' should be able to record fund usage.
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'incharge') {
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
        $fund_id = filter_var($_POST['fund_id'] ?? null, FILTER_VALIDATE_INT);
        $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $type = trim($_POST['type'] ?? ''); // 'purchase', 'manufacturing_cost', 'other'
        $reference_id = filter_var($_POST['reference_id'] ?? null, FILTER_VALIDATE_INT); // ID of associated purchase/cost
        $notes = trim($_POST['notes'] ?? '');
        $used_by = $_SESSION['user_id'];

        // Validate inputs.
        if (!$fund_id || !$amount || $amount <= 0 || empty($type) || !$reference_id) {
            throw new Exception("Missing or invalid required fields.");
        }

        // Validate type enum.
        $allowed_types = ['purchase', 'manufacturing_cost', 'other'];
        if (!in_array($type, $allowed_types)) {
            throw new Exception("Invalid fund usage type specified.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // 1. Check fund balance and status.
        $fund_check_query = "SELECT balance, status FROM funds WHERE id = ? FOR UPDATE"; // Lock fund row
        $fund_check_stmt = $db->prepare($fund_check_query);
        $fund_check_stmt->execute([$fund_id]);
        $fund_info = $fund_check_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fund_info || $fund_info['status'] !== 'active') {
            throw new Exception("Selected fund is invalid or not active.");
        }
        if ($fund_info['balance'] < $amount) {
            throw new Exception("Insufficient balance in the selected fund. Available: " . number_format($fund_info['balance'], 2) . ", Attempted usage: " . number_format($amount, 2) . ".");
        }

        // 2. Insert fund_usage record.
        $fund_usage_query = "INSERT INTO fund_usage (fund_id, amount, type, reference_id, used_by, notes, allocation_type)
                             VALUES (?, ?, ?, ?, ?, ?, ?)";
        $fund_usage_stmt = $db->prepare($fund_usage_query);
        // Note: `allocation_type` is an enum in `fund_usage` but not explicitly set in the original code.
        // Assuming `allocation_type` is same as `type` for now unless clarified.
        $fund_usage_stmt->execute([$fund_id, $amount, $type, $reference_id, $used_by, $notes, $type]);

        // 3. Update fund balance.
        $update_fund_query = "UPDATE funds SET balance = balance - ? WHERE id = ?";
        $update_fund_stmt = $db->prepare($update_fund_query);
        $update_fund_stmt->execute([$amount, $fund_id]);

        // 4. Update fund status to 'depleted' if balance reaches zero or below.
        if (($fund_info['balance'] - $amount) <= 0) {
            $deplete_fund_query = "UPDATE funds SET status = 'depleted' WHERE id = ?";
            $deplete_fund_stmt = $db->prepare($deplete_fund_query);
            $deplete_fund_stmt->execute([$fund_id]);
        }

        // Commit transaction.
        $db->commit();

        // Log the activity using Auth class.
        $auth->logActivity(
            $used_by,
            'create', // Action type is 'create' for usage record
            'funds', // Module can be 'funds' or 'fund_usage'
            "Recorded usage of " . number_format($amount, 2) . " from Fund ID " . $fund_id . " for " . htmlspecialchars($type) . " (Ref ID: " . $reference_id . ").",
            $fund_id // Entity ID is the fund ID
        );

        // Return success response.
        echo json_encode(['success' => true, 'message' => 'Fund usage recorded successfully.']);
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
            'funds',
            'Failed to record fund usage: ' . $e->getMessage(),
            $fund_id ?? null
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