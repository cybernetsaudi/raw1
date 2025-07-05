<?php
// File: api/delete-user.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'owner' should be able to delete users.
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
        $user_id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $deleted_by = $_SESSION['user_id'];

        // Validate input.
        if (!$user_id) {
            throw new Exception("Missing or invalid User ID.");
        }

        // Prevent self-deletion.
        if ($user_id == $deleted_by) {
            throw new Exception("You cannot delete your own account.");
        }

        // Prevent deleting the last 'owner' account.
        $owner_count_query = "SELECT COUNT(*) FROM users WHERE role = 'owner' AND is_active = 1";
        $owner_count_stmt = $db->prepare($owner_count_query);
        $owner_count_stmt->execute();
        $active_owner_count = $owner_count_stmt->fetchColumn();

        $user_role_query = "SELECT role FROM users WHERE id = ?";
        $user_role_stmt = $db->prepare($user_role_query);
        $user_role_stmt->execute([$user_id]);
        $target_user_role = $user_role_stmt->fetchColumn();

        if ($target_user_role === 'owner' && $active_owner_count <= 1) {
            throw new Exception("Cannot delete the last active owner account. Please create another owner account first or transfer ownership.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // 1. Check for dependent records across various tables.
        // It's crucial to check ALL tables that have foreign keys referencing `users.id`.
        // This is a complex step, as manual check is required for each FK without CASCADE.
        // If dependent records exist, deletion is prevented.

        $checks = [
            'activity_logs' => ['column' => 'user_id', 'message' => 'activity logs'],
            'customers' => ['column' => 'created_by', 'message' => 'customers'],
            'funds' => ['column' => 'from_user_id', 'message' => 'funds (as source)'],
            'funds_to' => ['column' => 'to_user_id', 'message' => 'funds (as destination)'],
            'fund_returns_returned' => ['column' => 'returned_by', 'message' => 'fund returns (as requester)'],
            'fund_returns_approved' => ['column' => 'approved_by', 'message' => 'fund returns (as approver)'],
            'fund_usage' => ['column' => 'used_by', 'message' => 'fund usage records'],
            'inventory' => ['column' => 'shopkeeper_id', 'message' => 'inventory records (as shopkeeper)'],
            'inventory_transfers_initiated' => ['column' => 'initiated_by', 'message' => 'inventory transfers (as initiator)'],
            'inventory_transfers_confirmed' => ['column' => 'confirmed_by', 'message' => 'inventory transfers (as confirmer)'],
            'inventory_transfers_shopkeeper' => ['column' => 'shopkeeper_id', 'message' => 'inventory transfers (as shopkeeper)'],
            'manufacturing_batches_created' => ['column' => 'created_by', 'message' => 'manufacturing batches (as creator)'],
            'manufacturing_batches_status' => ['column' => 'status_changed_by', 'message' => 'manufacturing batches (as status changer)'],
            'manufacturing_costs' => ['column' => 'recorded_by', 'message' => 'manufacturing costs'],
            'material_usage' => ['column' => 'recorded_by', 'message' => 'material usage records'],
            'notifications' => ['column' => 'user_id', 'message' => 'notifications'], // Note: notifications has ON DELETE CASCADE from user_id.
            'payments' => ['column' => 'recorded_by', 'message' => 'payments'],
            'products' => ['column' => 'created_by', 'message' => 'products'],
            'product_adjustments' => ['column' => 'adjusted_by_user_id', 'message' => 'product adjustments'],
            'purchases' => ['column' => 'purchased_by', 'message' => 'purchases'],
            'quality_control_inspector' => ['column' => 'inspector_id', 'message' => 'quality control records (as inspector)'],
            'quality_control_created' => ['column' => 'created_by', 'message' => 'quality control records (as creator)'],
            'sales' => ['column' => 'created_by', 'message' => 'sales'],
            // Add more tables if they have FKs to users.
        ];

        foreach ($checks as $table_name => $info) {
            $column = $info['column'];
            $message_part = $info['message'];
            
            // Skip checks for tables that have ON DELETE CASCADE if it's fine for them to be deleted.
            // For example, 'notifications' has ON DELETE CASCADE from users, so it's handled automatically.
            if ($table_name === 'notifications') {
                continue; // Skip explicit check if cascade is desired and set up
            }

            $check_query = "SELECT COUNT(*) FROM " . $table_name . " WHERE " . $column . " = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([$user_id]);
            $count = $check_stmt->fetchColumn();

            if ($count > 0) {
                throw new Exception("Cannot delete user. This user has " . $count . " associated " . $message_part . " records. Please reassign or delete them first.");
            }
        }

        // Fetch username and full name for logging before deletion.
        $user_info_query = "SELECT username, full_name FROM users WHERE id = ?";
        $user_info_stmt = $db->prepare($user_info_query);
        $user_info_stmt->execute([$user_id]);
        $user_info = $user_info_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user_info) {
             throw new Exception("User not found."); // Or already deleted
        }
        $username_to_delete = $user_info['username'];
        $full_name_to_delete = $user_info['full_name'];

        // 2. Delete the user record.
        $delete_query = "DELETE FROM users WHERE id = ?";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->execute([$user_id]);

        // 3. Log the activity.
        $auth->logActivity(
            $deleted_by,
            'delete',
            'users',
            'Deleted user: ' . htmlspecialchars($full_name_to_delete) . ' (' . htmlspecialchars($username_to_delete) . ').',
            $user_id
        );

        // Commit transaction.
        $db->commit();

        echo json_encode(['success' => true, 'message' => 'User ' . htmlspecialchars($full_name_to_delete) . ' deleted successfully.']);
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
            'users',
            'Failed to delete user ' . ($user_id ?? 'N/A') . ': ' . $e->getMessage(),
            $user_id ?? null
        );

        echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
        exit;
    }
} else {
    // Not a POST request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
    exit;
}