<?php
// File: api/delete-customer.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'shopkeeper' or 'owner' should be able to delete customers.
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
        $customer_id = filter_var($_POST['customer_id'] ?? null, FILTER_VALIDATE_INT);
        $deleted_by = $_SESSION['user_id'];

        // Validate input.
        if (!$customer_id) {
            throw new Exception("Missing or invalid Customer ID.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // 1. Check for dependent records (e.g., sales).
        $check_sales_query = "SELECT COUNT(*) FROM sales WHERE customer_id = ?";
        $check_sales_stmt = $db->prepare($check_sales_query);
        $check_sales_stmt->execute([$customer_id]);
        $sales_count = $check_sales_stmt->fetchColumn();

        if ($sales_count > 0) {
            throw new Exception("Cannot delete customer. This customer has " . $sales_count . " associated sales records. Please delete or reassign sales first.");
        }
        
        // Optional: Check for fund_returns if a customer can be 'returned by' or 'approved by'
        // Based on schema, fund_returns refers to sales, not customers directly.
        // Customers are referenced by sales, so checking sales is sufficient.

        // Optional: Verify customer ownership if shopkeeper role
        if ($_SESSION['role'] === 'shopkeeper') {
            $check_owner_query = "SELECT created_by FROM customers WHERE id = ?";
            $check_owner_stmt = $db->prepare($check_owner_query);
            $check_owner_stmt->execute([$customer_id]);
            $customer_owner_id = $check_owner_stmt->fetchColumn();

            if ($customer_owner_id !== $deleted_by) {
                throw new Exception("You do not have permission to delete this customer.");
            }
        }

        // Fetch customer name for logging before deletion.
        $customer_name_query = "SELECT name FROM customers WHERE id = ?";
        $customer_name_stmt = $db->prepare($customer_name_query);
        $customer_name_stmt->execute([$customer_id]);
        $customer_name = $customer_name_stmt->fetchColumn();

        if (!$customer_name) {
             throw new Exception("Customer not found."); // Or already deleted
        }

        // 2. Delete the customer record.
        $delete_query = "DELETE FROM customers WHERE id = ?";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->execute([$customer_id]);

        // 3. Log the activity.
        $auth->logActivity(
            $deleted_by,
            'delete',
            'customers',
            'Deleted customer: ' . htmlspecialchars($customer_name) . ' (ID: ' . $customer_id . ').',
            $customer_id
        );

        // Commit transaction.
        $db->commit();

        echo json_encode(['success' => true, 'message' => 'Customer ' . htmlspecialchars($customer_name) . ' deleted successfully.']);
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
            'customers',
            'Failed to delete customer ' . ($customer_id ?? 'N/A') . ': ' . $e->getMessage(),
            $customer_id ?? null
        );

        echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
        exit;
    }
} else {
    // Not a POST request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
    exit;
}