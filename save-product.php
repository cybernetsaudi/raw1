<?php
// File: api/save-product.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'owner' or 'incharge' should be able to save (add/edit) products.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'incharge')) {
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
        $product_id = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT); // Null for add, ID for edit
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $sku = trim($_POST['sku'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $created_by = $_SESSION['user_id'];
        $image_path = null; // Initialize image path

        // Server-side validation for required fields.
        if (empty($name) || empty($sku) || empty($category)) {
            throw new Exception("Product Name, SKU, and Category are required.");
        }

        // Validate SKU uniqueness (for add and for edit, check against other products).
        $check_sku_query = "SELECT id FROM products WHERE sku = ?";
        $sku_params = [$sku];
        if ($product_id) { // If editing, exclude current product's ID from uniqueness check
            $check_sku_query .= " AND id != ?";
            $sku_params[] = $product_id;
        }
        $check_sku_stmt = $db->prepare($check_sku_query);
        $check_sku_stmt->execute($sku_params);
        if ($check_sku_stmt->rowCount() > 0) {
            throw new Exception("SKU '" . htmlspecialchars($sku) . "' already exists. Please use a unique SKU.");
        }

        // Handle image upload.
        // This logic is mostly copied from add-product.php, but adjusted for API context.
        if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/products/'; // Path relative to this API file
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true); // Create directory if it doesn't exist
            }

            $file_tmp_name = $_FILES['product_image']['tmp_name'];
            $file_name = basename($_FILES['product_image']['name']);
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
            $max_file_size = 5 * 1024 * 1024; // 5 MB

            // Validate file type and size.
            if (!in_array($file_ext, $allowed_ext)) {
                throw new Exception("Invalid image file type. Only JPG, JPEG, PNG, GIF are allowed.");
            }
            if ($_FILES['product_image']['size'] > $max_file_size) {
                throw new Exception("Image file size exceeds 5MB limit.");
            }

            // Generate unique file name.
            $unique_file_name = uniqid('product_', true) . '.' . $file_ext;
            $destination_path = $upload_dir . $unique_file_name;

            if (move_uploaded_file($file_tmp_name, $destination_path)) {
                $image_path = 'uploads/products/' . $unique_file_name; // Path to store in DB
            } else {
                throw new Exception("Failed to upload image.");
            }
        } elseif (isset($_FILES['product_image']) && $_FILES['product_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            // Handle other upload errors.
            throw new Exception("Image upload error: " . $_FILES['product_image']['error']);
        } elseif ($product_id && empty($_FILES['product_image']) && isset($_POST['current_image_path']) && !empty($_POST['current_image_path'])) {
            // If editing and no new file is uploaded, keep existing image path
            $image_path = $_POST['current_image_path'];
        }


        // Start transaction for atomicity.
        $db->beginTransaction();

        $message = "";
        $action_type = 'create';
        $entity_id_for_log = $product_id; // For update, will be set for create

        if ($product_id) {
            // Update existing product.
            $update_query = "UPDATE products
                             SET name = ?, description = ?, sku = ?, category = ?, updated_at = NOW()";
            $update_params = [$name, $description, $sku, $category];

            // If a new image was uploaded, update image_path
            if (!is_null($image_path)) {
                $update_query .= ", image_path = ?";
                $update_params[] = $image_path;
            }

            $update_query .= " WHERE id = ?";
            $update_params[] = $product_id;

            $update_stmt = $db->prepare($update_query);
            $update_stmt->execute($update_params);
            $message = "Product updated successfully.";
            $action_type = 'update';
        } else {
            // Create new product.
            $insert_query = "INSERT INTO products (name, description, sku, category, created_by, created_at, updated_at, image_path)
                             VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?)";
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->execute([$name, $description, $sku, $category, $created_by, $image_path]);
            $entity_id_for_log = $db->lastInsertId();
            $message = "Product added successfully.";
            $action_type = 'create';
        }

        // Log the activity using Auth class.
        $auth->logActivity(
            $_SESSION['user_id'],
            $action_type,
            'products',
            ($action_type === 'create' ? 'Added new' : 'Updated') . ' product: ' . htmlspecialchars($name) . ' (SKU: ' . htmlspecialchars($sku) . ')',
            $entity_id_for_log
        );

        // Commit transaction.
        $db->commit();

        echo json_encode(['success' => true, 'message' => $message, 'product_id' => $entity_id_for_log]);
        exit;

    } catch (Exception $e) {
        // Rollback transaction on error.
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        // If an image was uploaded but transaction failed, clean up the file.
        if (isset($destination_path) && file_exists($destination_path)) {
            unlink($destination_path);
        }

        // Log the error.
        $auth->logActivity(
            $_SESSION['user_id'] ?? null,
            'error',
            'products',
            'Failed to save product: ' . $e->getMessage(),
            $product_id ?? null
        );

        echo json_encode(['success' => false, 'message' => 'An error occurred while saving the product: ' . $e->getMessage()]);
        exit;
    }
} else {
    // Not a POST request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
    exit;
}