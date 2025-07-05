<?php
// File: api/void-payment.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'shopkeeper' (for their own sales' payments) or 'owner' (for all payments) should be able to void payments.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'shopkeeper' && $_SESSION['role'] !== 'owner')) {
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
        $payment_id = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT);
        $reason = trim($_POST['reason'] ?? ''); // Reason for voiding is required
        $voided_by = $_SESSION['user_id'];

        // Validate inputs.
        if (!$payment_id) {
            throw new Exception("Missing or invalid Payment ID.");
        }
        if (empty($reason)) {
            throw new Exception("Reason for voiding payment is required.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // 1. Fetch payment details and related sale details, locking rows.
        $payment_query = "SELECT py.id, py.sale_id, py.amount, py.payment_method, py.reference_number, py.notes AS payment_notes,
                                 s.invoice_number, s.net_amount AS sale_net_amount, s.payment_status AS sale_current_payment_status, s.created_by AS sale_created_by
                          FROM payments py
                          JOIN sales s ON py.sale_id = s.id
                          WHERE py.id = ? FOR UPDATE"; // Lock payment and sale row
        $payment_stmt = $db->prepare($payment_query);
        $payment_stmt->execute([$payment_id]);
        $payment_details = $payment_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment_details) {
            throw new Exception("Payment record not found.");
        }

        // Check permission: shopkeeper can only void payments for their own sales.
        if ($_SESSION['role'] === 'shopkeeper' && $payment_details['sale_created_by'] !== $voided_by) {
            throw new Exception("You do not have permission to void this payment.");
        }

        $sale_id = $payment_details['sale_id'];
        $payment_amount = $payment_details['amount'];
        $invoice_number = $payment_details['invoice_number'];

        // For simplicity, we are deleting the payment record and reverting the sale status.
        // A more robust solution might mark payment as `voided` in a `payments` table with a `status` column,
        // or move to a `payments_archive` table, to keep audit trail.
        // For now, hard delete of payment, and adjusting sale status.

        // 2. Delete the payment record.
        $delete_payment_query = "DELETE FROM payments WHERE id = ?";
        $delete_payment_stmt = $db->prepare($delete_payment_query);
        $delete_payment_stmt->execute([$payment_id]);

        // 3. Re-calculate total paid amount for the sale after this payment is removed.
        $recalculate_paid_query = "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE sale_id = ?";
        $recalculate_paid_stmt = $db->prepare($recalculate_paid_query);
        $recalculate_paid_stmt->execute([$sale_id]);
        $new_total_paid_for_sale = $recalculate_paid_stmt->fetchColumn();

        $new_remaining_due = $payment_details['sale_net_amount'] - $new_total_paid_for_sale;

        // Determine new sale payment status based on remaining amount.
        $new_sale_payment_status = 'unpaid';
        if ($new_remaining_due <= 0.01) { // Allow for tiny float discrepancies
            $new_sale_payment_status = 'paid';
        } elseif ($new_total_paid_for_sale > 0) {
            $new_sale_payment_status = 'partial';
        }

        // 4. Update the sale's payment status.
        $update_sale_query = "UPDATE sales SET payment_status = ? WHERE id = ?";
        $update_sale_stmt = $db->prepare($update_sale_query);
        $update_sale_stmt->execute([$new_sale_payment_status, $sale_id]);

        // 5. Log the activity.
        $auth->logActivity(
            $voided_by,
            'delete', // Use 'delete' or 'void' action type
            'payments',
            'Voided payment ID: ' . $payment_id . ' (Amount: ' . number_format($payment_amount, 2) . ') for Sale #' . htmlspecialchars($invoice_number) . '. Reason: ' . htmlspecialchars($reason) . '. Sale payment status reverted to: ' . $new_sale_payment_status . '.',
            $payment_id
        );

        // Commit transaction.
        $db->commit();

        echo json_encode(['success' => true, 'message' => 'Payment ID ' . $payment_id . ' for Sale #' . htmlspecialchars($invoice_number) . ' voided successfully.']);
        exit;

    } catch (Exception $e) {
        // Rollback transaction on error.
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        // Log the error.
        $auth->logActivity(
            $_SESSION['user_id'] ?? null,
            'error',
            'payments',
            'Failed to void payment ' . ($payment_id ?? 'N/A') . ': ' . $e->getMessage(),
            $payment_id ?? null
        );

        echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
        exit;
    }
} else {
    // Not a POST request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
    exit;
}