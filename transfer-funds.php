<?php
// File: transfer-funds.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'owner' should be able to transfer funds between users.
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
        $from_user_id = filter_var($_POST['from_user_id'] ?? null, FILTER_VALIDATE_INT);
        $to_user_id = filter_var($_POST['to_user_id'] ?? null, FILTER_VALIDATE_INT);
        $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $description = trim($_POST['description'] ?? '');
        $transfer_date = date('Y-m-d H:i:s'); // Use current timestamp for transfer date
        $initiated_by = $_SESSION['user_id']; // The user performing the transfer

        // Validate inputs.
        if (!$from_user_id || !$to_user_id || !$amount || $amount <= 0 || $from_user_id === $to_user_id) {
            throw new Exception("Missing or invalid transfer details. Amount must be positive, and source/destination users must be different.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // 1. Fetch 'from_user' details and lock their 'funds' records (if applicable).
        // For simplicity, we are creating a new 'fund' record for this transfer.
        // If funds are to be directly transferred from a user's *existing* fund balance,
        // that logic would be added here (e.g., checking and updating user's 'cash on hand' if applicable).
        // As per current schema, 'funds' table records transfers *to* users, not user balances.
        // This 'transfer-funds' API effectively creates a new 'investment' type fund for the to_user.
        // It's more of a 'record fund allocation' than 'transferring existing cash balance'.

        // Fetch user names for logging
        $user_names_query = "SELECT id, full_name FROM users WHERE id IN (?, ?)";
        $user_names_stmt = $db->prepare($user_names_query);
        $user_names_stmt->execute([$from_user_id, $to_user_id]);
        $users_info = $user_names_stmt->fetchAll(PDO::FETCH_KEY_PAIR); // [id => full_name]

        $from_user_name = $users_info[$from_user_id] ?? 'Unknown User';
        $to_user_name = $users_info[$to_user_id] ?? 'Unknown User';

        if (!isset($users_info[$from_user_id]) || !isset($users_info[$to_user_id])) {
            throw new Exception("One or both specified users do not exist.");
        }

        // 2. Insert new 'fund' record for the transfer.
        // Type is 'investment' as it represents a fund provided *to* a user.
        // Balance starts at the transferred amount. Status is 'active'.
        $insert_fund_query = "INSERT INTO funds (amount, from_user_id, to_user_id, description, transfer_date, status, balance, type)
                             VALUES (?, ?, ?, ?, ?, 'active', ?, 'investment')";
        $insert_fund_stmt = $db->prepare($insert_fund_query);
        $insert_fund_stmt->execute([
            $amount,
            $from_user_id,
            $to_user_id,
            $description,
            $transfer_date,
            $amount // Initial balance is the transferred amount
        ]);
        $fund_id = $db->lastInsertId();

        // Log the activity using Auth class.
        $auth->logActivity(
            $initiated_by,
            'create', // Action type is 'create' for new fund record
            'funds',
            "Transferred " . number_format($amount, 2) . " from " . htmlspecialchars($from_user_name) . " to " . htmlspecialchars($to_user_name) . " (Fund ID: " . $fund_id . "). Notes: " . htmlspecialchars($description),
            $fund_id // Entity ID is the new fund ID
        );

        // Commit transaction.
        $db->commit();

        // Return success response.
        echo json_encode(['success' => true, 'message' => 'Funds transferred successfully.', 'fund_id' => $fund_id]);
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
            'Failed to transfer funds: ' . $e->getMessage(),
            null
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