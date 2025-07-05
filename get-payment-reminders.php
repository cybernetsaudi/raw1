<?php
// File: get-payment-reminders.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and database connection.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class for logging

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'shopkeeper' should access payment reminders.
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'shopkeeper') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// Get database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

// Process GET request.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $shopkeeper_id = $_SESSION['user_id'];

        // Fetch sales that are unpaid or partially paid and associated with this shopkeeper
        // (assuming created_by in sales implies association)
        $query = "
            SELECT
                s.id AS sale_id,
                s.invoice_number,
                c.name AS customer_name,
                s.total_amount,
                COALESCE(SUM(p.amount), 0) AS amount_paid,
                (s.total_amount - COALESCE(SUM(p.amount), 0)) AS amount_due,
                s.payment_due_date,
                s.payment_status,
                s.notes
            FROM sales s
            JOIN customers c ON s.customer_id = c.id
            LEFT JOIN payments p ON s.id = p.sale_id
            WHERE s.created_by = ? AND s.payment_status IN ('unpaid', 'partial')
            GROUP BY s.id, s.invoice_number, c.name, s.total_amount, s.payment_due_date, s.payment_status, s.notes
            HAVING amount_due > 0
            ORDER BY s.payment_due_date ASC
        ";
        $stmt = $db->prepare($query);
        $stmt->execute([$shopkeeper_id]);
        $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Log the activity using Auth class.
        $auth->logActivity(
            $shopkeeper_id,
            'read',
            'payments',
            'Fetched payment reminders for shopkeeper.',
            null // No specific entity ID for fetching a list
        );

        // Return success response.
        echo json_encode([
            'success' => true,
            'message' => 'Payment reminders fetched successfully.',
            'reminders' => $reminders
        ]);
        exit;

    } catch (Exception $e) {
        // Log the error using Auth class.
        $auth->logActivity(
            $_SESSION['user_id'] ?? null,
            'error',
            'payments',
            'Failed to fetch payment reminders: ' . $e->getMessage(),
            null
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