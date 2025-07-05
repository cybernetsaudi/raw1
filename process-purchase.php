<?php
// File: process-purchase.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database configuration and Auth class
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response
header('Content-Type: application/json');

// Check if user is logged in and has the 'incharge' role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'incharge') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging
$auth = new Auth($db);

// Check if form was submitted via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get and sanitize form data
        $material_id = filter_var($_POST['material_id'] ?? '', FILTER_VALIDATE_INT);
        $quantity = filter_var($_POST['quantity'] ?? '', FILTER_VALIDATE_FLOAT);
        $unit_price = filter_var($_POST['unit_price'] ?? '', FILTER_VALIDATE_FLOAT);
        $total_amount = filter_var($_POST['total_amount'] ?? '', FILTER_VALIDATE_FLOAT);
        $vendor_name = trim($_POST['vendor_name'] ?? '');
        $vendor_contact = trim($_POST['vendor_contact'] ?? '');
        $invoice_number = trim($_POST['invoice_number'] ?? '');
        $purchase_date = trim($_POST['purchase_date'] ?? '');
        $fund_id = filter_var($_POST['fund_id'] ?? '', FILTER_VALIDATE_INT);
        $purchased_by = $_SESSION['user_id'];

        // Validate required fields
        if (!$material_id || !$quantity || $quantity <= 0 || !$unit_price || $unit_price <= 0 || !$total_amount || $total_amount <= 0 || empty($purchase_date)) {
            throw new Exception("Missing or invalid required fields.");
        }

        // Additional validation for calculated total amount to prevent manipulation
        $calculated_total = round($quantity * $unit_price, 2);
        if (abs($calculated_total - $total_amount) > 0.01) { // Allow for small floating point discrepancies
            throw new Exception("Calculated total amount does not match provided total amount. Please recheck inputs.");
        }

        // Validate fund_id if provided
        if ($fund_id) {
            $fund_check_query = "SELECT balance, status FROM funds WHERE id = ?";
            $fund_check_stmt = $db->prepare($fund_check_query);
            $fund_check_stmt->execute([$fund_id]);
            $fund_info = $fund_check_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$fund_info || $fund_info['status'] !== 'active') {
                throw new Exception("Selected fund is invalid or not active.");
            }
            if ($fund_info['balance'] < $total_amount) {
                throw new Exception("Insufficient balance in the selected fund (Available: " . number_format($fund_info['balance'], 2) . ").");
            }
        }

        // Start transaction for atomicity
        $db->beginTransaction();

        // 1. Insert purchase record
        $purchase_query = "INSERT INTO purchases (material_id, quantity, unit_price, total_amount, vendor_name, vendor_contact, invoice_number, purchase_date, purchased_by, fund_id)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $purchase_stmt = $db->prepare($purchase_query);
        $purchase_stmt->execute([
            $material_id, $quantity, $unit_price, $total_amount, $vendor_name, $vendor_contact,
            $invoice_number, $purchase_date, $purchased_by, $fund_id
        ]);
        $purchase_id = $db->lastInsertId();

        // 2. Update raw_materials stock
        $update_stock_query = "UPDATE raw_materials SET stock_quantity = stock_quantity + ? WHERE id = ?";
        $update_stock_stmt = $db->prepare($update_stock_query);
        $update_stock_stmt->execute([$quantity, $material_id]);

        // 3. Record fund usage if a fund was selected
        if ($fund_id) {
            $fund_usage_query = "INSERT INTO fund_usage (fund_id, amount, type, allocation_type, reference_id, used_by, notes)
                                 VALUES (?, ?, 'purchase', 'purchase', ?, ?, ?)";
            $fund_usage_stmt = $db->prepare($fund_usage_query);
            $fund_usage_stmt->execute([$fund_id, $total_amount, $purchase_id, $purchased_by, 'Purchase of ' . $quantity . ' units of material_id ' . $material_id]);

            // Update fund balance
            $update_fund_query = "UPDATE funds SET balance = balance - ? WHERE id = ?";
            $update_fund_stmt = $db->prepare($update_fund_query);
            $update_fund_stmt->execute([$total_amount, $fund_id]);

            // Check if fund balance depleted and update status
            if (($fund_info['balance'] - $total_amount) <= 0) {
                $deplete_fund_query = "UPDATE funds SET status = 'depleted' WHERE id = ?";
                $deplete_fund_stmt = $db->prepare($deplete_fund_query);
                $deplete_fund_stmt->execute([$fund_id]);
            }
        }

        // Commit transaction
        $db->commit();

        // Log activity using Auth class
        $material_name_query = "SELECT name FROM raw_materials WHERE id = ?";
        $material_name_stmt = $db->prepare($material_name_query);
        $material_name_stmt->execute([$material_id]);
        $material_name = $material_name_stmt->fetchColumn();

        $auth->logActivity(
            $_SESSION['user_id'],
            'create',
            'purchases',
            "Recorded new purchase of " . number_format($quantity, 2) . " units of " . htmlspecialchars($material_name) . " for " . number_format($total_amount, 2),
            $purchase_id
        );

        // Return success response
        echo json_encode(['success' => true, 'message' => 'Purchase recorded successfully!', 'purchase_id' => $purchase_id]);
        exit;

    } catch (Exception $e) {
        // Rollback transaction on error
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        // Log the error using Auth class
        $auth->logActivity(
            $_SESSION['user_id'],
            'error',
            'purchases',
            'Failed to record purchase: ' . $e->getMessage(),
            null
        );

        // Return error response
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
} else {
    // Not a POST request
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Please use POST.']);
    exit;
}