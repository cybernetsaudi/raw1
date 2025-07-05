<?php
// File: return-funds.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'owner' or 'incharge' should be able to record fund returns to investors.
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
        $fund_id = filter_var($_POST['fund_id'] ?? null, FILTER_VALIDATE_INT);
        $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $notes = trim($_POST['notes'] ?? '');
        $returned_by = $_SESSION['user_id'];

        // Validate inputs.
        if (!$fund_id || !$amount || $amount <= 0) {
            throw new Exception("Missing or invalid required fields.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // 1. Check fund existence (ensure it's a valid active investment fund to return from).
        // This is distinct from fund_returns table which tracks returns FROM sales.
        // This is returning funds back to original investors or sources.
        $fund_check_query = "SELECT balance, from_user_id, to_user_id, amount AS original_amount, type FROM funds WHERE id = ? FOR UPDATE"; // Lock fund row
        $fund_check_stmt = $db->prepare($fund_check_query);
        $fund_check_stmt->execute([$fund_id]);
        $fund_info = $fund_check_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fund_info || $fund_info['type'] !== 'investment') { // Only return investment funds
            throw new Exception("Selected fund is invalid or not an investment fund.");
        }
        // You might want to check if the current balance is sufficient if this is a 'withdrawal' from an active fund.
        // If this is a 'return' to an investor, it might not directly relate to current balance, but original amount.
        // For simplicity, we'll assume it's a 'return' type of transaction.

        // 2. Insert a new 'fund' record of type 'return'
        // This creates a separate entry to record the return transaction, linked back to the original fund if needed.
        $insert_return_fund_query = "INSERT INTO funds (amount, from_user_id, to_user_id, description, type, reference_id, status, balance)
                                     VALUES (?, ?, ?, ?, 'return', ?, 'returned', 0)"; // Amount is returned, balance for this record is 0
        $insert_return_fund_stmt = $db->prepare($insert_return_fund_query);
        $insert_return_fund_stmt->execute([
            $amount,
            $fund_info['to_user_id'], // Fund goes from current holder (to_user)
            $fund_info['from_user_id'], // back to original investor (from_user)
            $notes,
            $fund_id // Reference the original fund
        ]);
        $new_return_fund_id = $db->lastInsertId();

        // You might also want to update the status of the *original* fund if it's fully returned.
        // For simplicity, we just create a new record for the return transaction.
        // If the original fund's balance needs to decrease, that would be done here as well.
        // For now, let's assume this simply logs a return.

        // Commit transaction.
        $db->commit();

        // Log the activity using Auth class.
        $auth->logActivity(
            $returned_by,
            'create', // Action type is 'create' for a new return record
            'funds',
            "Recorded return of " . number_format($amount, 2) . " for original Fund ID " . $fund_id . ". Notes: " . htmlspecialchars($notes),
            $new_return_fund_id // Entity ID is the new fund record for the return
        );

        // Return success response.
        echo json_encode(['success' => true, 'message' => 'Fund return recorded successfully.']);
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
            'Failed to record fund return: ' . $e->getMessage(),
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