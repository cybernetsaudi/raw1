<?php
// File: approve-fund-return.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'owner' should be able to approve fund returns.
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
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
        $return_id = filter_var($_POST['return_id'] ?? null, FILTER_VALIDATE_INT);
        $notes = trim($_POST['notes'] ?? '');
        $approved_by = $_SESSION['user_id'];

        // Validate inputs.
        if (!$return_id) {
            throw new Exception("Missing or invalid return ID.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // Fetch fund return details
        $return_query = "SELECT fr.sale_id, fr.amount, fr.status, s.customer_id, s.invoice_number
                         FROM fund_returns fr
                         JOIN sales s ON fr.sale_id = s.id
                         WHERE fr.id = ? FOR UPDATE"; // Use FOR UPDATE to lock the row
        $return_stmt = $db->prepare($return_query);
        $return_stmt->execute([$return_id]);
        $return_details = $return_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$return_details) {
            throw new Exception("Fund return request not found.");
        }
        if ($return_details['status'] !== 'pending') {
            throw new Exception("Fund return request is not in 'pending' status and cannot be approved.");
        }

        // 1. Update fund_returns status to 'approved'
        $update_return_query = "UPDATE fund_returns
                               SET status = 'approved', approved_by = ?, approved_at = NOW(), notes = CONCAT(IFNULL(notes, ''), '\nApproved: ', ?)
                               WHERE id = ?";
        $update_return_stmt = $db->prepare($update_return_query);
        $update_return_stmt->execute([$approved_by, $notes, $return_id]);

        // 2. Adjust sales payment status (if needed, e.g., if sale was 'partial' and now fully returned)
        // This part needs careful consideration based on business logic for returns affecting payment status.
        // For simplicity, we'll assume it doesn't directly change payment status to 'unpaid' if it was partially paid
        // through other means. The `fund_returns` table is specifically for returning *funds from sales*,
        // not directly voiding sales.
        // You might need to add logic here to adjust `sales.net_amount` or `sales.total_amount` if returns decrease the overall sale value.
        // For now, we are just approving the *return of funds*, which is tracked separately.

        // 3. Log the activity using Auth class.
        $auth->logActivity(
            $approved_by,
            'update', // Action type is 'update' for approval
            'fund_returns',
            "Approved fund return request (ID: " . $return_id . ") for Sale #" . htmlspecialchars($return_details['invoice_number']) . ". Amount: " . number_format($return_details['amount'], 2) . ". Notes: " . htmlspecialchars($notes),
            $return_id // Entity ID is the fund_return ID
        );

        // Commit transaction.
        $db->commit();

        // Return success response.
        echo json_encode(['success' => true, 'message' => 'Fund return request approved successfully.']);
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
            'fund_returns',
            'Failed to approve fund return request: ' . $e->getMessage(),
            $return_id ?? null
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