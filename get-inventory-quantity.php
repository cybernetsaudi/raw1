<?php
// File: get-inventory-quantity.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Any logged-in user with appropriate role can view inventory quantities.
// Assuming 'owner', 'incharge', 'shopkeeper' can access this.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'incharge' && $_SESSION['role'] !== 'shopkeeper')) {
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
        // Get and sanitize input data.
        $product_id = filter_var($_GET['product_id'] ?? null, FILTER_VALIDATE_INT);
        $location = trim($_GET['location'] ?? ''); // Location is optional, if not provided, get all locations

        // Validate inputs.
        if (!$product_id) {
            throw new Exception("Missing or invalid product ID.");
        }

        $query = "SELECT quantity, location, shopkeeper_id FROM inventory WHERE product_id = ?";
        $params = [$product_id];

        if (!empty($location)) {
            // Validate location enum if provided.
            $allowed_locations = ['manufacturing', 'wholesale', 'transit'];
            if (!in_array($location, $allowed_locations)) {
                throw new Exception("Invalid inventory location specified.");
            }
            $query .= " AND location = ?";
            $params[] = $location;
        }

        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $inventory_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($inventory_data)) {
            // Log that inventory was not found, but not as a hard error for specific product/location
            $auth->logActivity(
                $_SESSION['user_id'],
                'read',
                'inventory',
                'Attempted to fetch inventory for product ID ' . $product_id . ' at ' . (empty($location) ? 'all locations' : htmlspecialchars($location)) . ', but no data found.',
                $product_id
            );
            echo json_encode(['success' => true, 'message' => 'No inventory found for this product/location.', 'data' => []]);
            exit;
        }

        // Log the activity using Auth class.
        $product_name_query = "SELECT name FROM products WHERE id = ?";
        $product_name_stmt = $db->prepare($product_name_query);
        $product_name_stmt->execute([$product_id]);
        $product_name = $product_name_stmt->fetchColumn();

        $log_description = "Fetched inventory quantity for product: " . htmlspecialchars($product_name);
        if (!empty($location)) {
            $log_description .= " at " . htmlspecialchars($location);
        }

        $auth->logActivity(
            $_SESSION['user_id'],
            'read',
            'inventory',
            $log_description,
            $product_id
        );

        // Return success response.
        echo json_encode(['success' => true, 'message' => 'Inventory data fetched successfully.', 'data' => $inventory_data]);
        exit;

    } catch (Exception $e) {
        // Log the error using Auth class.
        $auth->logActivity(
            $_SESSION['user_id'] ?? null,
            'error',
            'inventory',
            'Failed to fetch inventory quantity: ' . $e->getMessage(),
            $product_id ?? null
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