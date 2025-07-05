<?php
// File: mark-reminder-contacted.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'shopkeeper' should mark reminders as contacted.
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
        $notes = trim($_POST['notes'] ?? '');
        $contacted_by = $_SESSION['user_id'];

        // Validate inputs.
        if (!$sale_id) {
            throw new Exception("Missing or invalid sale ID.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // Fetch sale details
        $sale_query = "SELECT invoice_number, notes FROM sales WHERE id = ? AND created_by = ? FOR UPDATE"; // Lock row and ensure ownership
        $sale_stmt = $db->prepare($sale_query);
        $sale_stmt->execute([$sale_id, $contacted_by]);
        $sale_details = $sale_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale_details) {
            throw new Exception("Sale not found or you do not have permission to modify it.");
        }

        // Update sale notes to include the new contact note.
        // We will append to notes in a more structured way or add a separate contact log.
        // For now, let's append with a timestamp for better readability than just CONCAT.
        $new_note_entry = "\n[Contacted by " . $_SESSION['full_name'] . " on " . date('Y-m-d H:i:s') . "]: " . $notes;
        $updated_notes = $sale_details['notes'] . $new_note_entry;

        $update_query = "UPDATE sales SET notes = ? WHERE id = ?";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->execute([$updated_notes, $sale_id]);

        // Log the activity using Auth class.
        $auth->logActivity(
            $contacted_by,
            'update', // Action type is 'update' for notes
            'sales',
            "Marked payment reminder as contacted for Sale #" . htmlspecialchars($sale_details['invoice_number']) . ". Notes: " . htmlspecialchars($notes),
            $sale_id // Entity ID is the sale ID
        );

        // Commit transaction.
        $db->commit();

        // Return success response.
        echo json_encode(['success' => true, 'message' => 'Payment reminder marked as contacted successfully.']);
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
            'sales',
            'Failed to mark payment reminder as contacted: ' . $e->getMessage(),
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