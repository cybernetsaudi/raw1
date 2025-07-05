<?php
// File: save-payment.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'shopkeeper' should be able to save payments.
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'shopkeeper') {
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
        $sale_id = filter_var($_POST['sale_id'] ?? null, FILTER_VALIDATE_INT);
        $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $payment_date = trim($_POST['payment_date'] ?? '');
        $payment_method = trim($_POST['payment_method'] ?? '');
        $reference_number = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $recorded_by = $_SESSION['user_id'];

        // Validate inputs.
        if (!$sale_id || !$amount || $amount <= 0 || empty($payment_date) || empty($payment_method)) {
            throw new Exception("Missing or invalid required fields.");
        }

        // Validate payment_method enum.
        $allowed_payment_methods = ['cash', 'bank_transfer', 'check', 'other'];
        if (!in_array($payment_method, $allowed_payment_methods)) {
            throw new Exception("Invalid payment method specified.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // 1. Fetch sale details and lock the row for update.
        $sale_query = "SELECT total_amount, payment_status, created_by, invoice_number
                       FROM sales WHERE id = ? FOR UPDATE";
        $sale_stmt = $db->prepare($sale_query);
        $sale_stmt->execute([$sale_id]);
        $sale_details = $sale_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale_details) {
            throw new Exception("Sale not found.");
        }
        // Ensure the shopkeeper has permission for this sale (e.g., created by them)
        if ($sale_details['created_by'] !== $recorded_by) {
            throw new Exception("You do not have permission to record payments for this sale.");
        }
        if ($sale_details['payment_status'] === 'paid') {
            throw new Exception("This sale is already fully paid.");
        }

        // 2. Calculate current paid amount for the sale.
        $paid_amount_query = "SELECT COALESCE(SUM(amount), 0) AS current_paid_amount FROM payments WHERE sale_id = ?";
        $paid_amount_stmt = $db->prepare($paid_amount_query);
        $paid_amount_stmt->execute([$sale_id]);
        $current_paid_amount = $paid_amount_stmt->fetchColumn();

        $new_total_paid = $current_paid_amount + $amount;
        $remaining_due = $sale_details['total_amount'] - $new_total_paid;

        // Determine new payment status.
        $new_payment_status = 'unpaid';
        if ($remaining_due <= 0.01) { // Allow for tiny float discrepancies
            $new_payment_status = 'paid';
        } elseif ($new_total_paid > 0) {
            $new_payment_status = 'partial';
        }

        // Prevent overpayment
        if ($new_total_paid > $sale_details['total_amount'] + 0.01) {
            throw new Exception("Payment amount exceeds the remaining amount due for this sale.");
        }

        // 3. Insert payment record.
        $payment_query = "INSERT INTO payments (sale_id, amount, payment_date, payment_method, reference_number, notes, recorded_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?)";
        $payment_stmt = $db->prepare($payment_query);
        $payment_stmt->execute([
            $sale_id, $amount, $payment_date, $payment_method, $reference_number, $notes, $recorded_by
        ]);
        $payment_id = $db->lastInsertId();

        // 4. Update sale's payment_status.
        $update_sale_query = "UPDATE sales SET payment_status = ? WHERE id = ?";
        $update_sale_stmt = $db->prepare($update_sale_query);
        $update_sale_stmt->execute([$new_payment_status, $sale_id]);

        // Commit transaction.
        $db->commit();

        // Log the activity using Auth class.
        $auth->logActivity(
            $recorded_by,
            'create', // Action type is 'create' for new payment
            'payments',
            "Recorded " . htmlspecialchars($payment_method) . " payment of " . number_format($amount, 2) . " for Sale #" . htmlspecialchars($sale_details['invoice_number']) . ". New status: " . $new_payment_status . ".",
            $payment_id // Entity ID is the payment ID
        );

        // Return success response.
        echo json_encode([
            'success' => true,
            'message' => 'Payment recorded successfully.',
            'payment_id' => $payment_id,
            'new_payment_status' => $new_payment_status,
            'remaining_due' => round($remaining_due, 2)
        ]);
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
            'payments',
            'Failed to save payment: ' . $e->getMessage(),
            $sale_id ?? null
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