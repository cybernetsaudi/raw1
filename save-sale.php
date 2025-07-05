<?php
// File: api/save-sale.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Set content type to JSON for consistent API response.
header('Content-Type: application/json');

// Check user authentication and role.
// Only 'shopkeeper' or 'owner' should be able to save sales.
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
        // Get and sanitize form data.
        $sale_id = filter_var($_POST['sale_id'] ?? null, FILTER_VALIDATE_INT); // Null for add, ID for edit
        $customer_id = filter_var($_POST['customer_id'] ?? null, FILTER_VALIDATE_INT);
        $sale_date = trim($_POST['sale_date'] ?? '');
        $discount_amount = filter_var($_POST['discount_amount'] ?? 0, FILTER_VALIDATE_FLOAT);
        $tax_amount = filter_var($_POST['tax_amount'] ?? 0, FILTER_VALIDATE_FLOAT);
        $shipping_cost = filter_var($_POST['shipping_cost'] ?? 0, FILTER_VALIDATE_FLOAT);
        $notes = trim($_POST['notes'] ?? '');
        $created_by = $_SESSION['user_id'];
        $sale_items_data = $_POST['sale_items'] ?? []; // Array of product_id, quantity, unit_price

        // Server-side validation.
        if (!$customer_id || empty($sale_date) || empty($sale_items_data)) {
            throw new Exception("Customer, Sale Date, and at least one Sale Item are required.");
        }
        if ($discount_amount < 0 || $tax_amount < 0 || $shipping_cost < 0) {
            throw new Exception("Discount, Tax, and Shipping costs cannot be negative.");
        }

        $total_amount = 0; // Sum of all item prices before discounts/taxes
        $net_amount = 0;   // Final amount after all calculations
        $processed_sale_items = []; // To hold validated items
        $original_sale_items = []; // To hold original items for edit rollback

        // For editing, fetch original sale items to compare and revert stock if needed.
        if ($sale_id) {
            $original_items_query = "SELECT product_id, quantity FROM sale_items WHERE sale_id = ?";
            $original_items_stmt = $db->prepare($original_items_query);
            $original_items_stmt->execute([$sale_id]);
            $original_sale_items = $original_items_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Also check if sale has payments. If it does, prevent editing items that impact quantity/price.
            // Editing a sale with payments is highly problematic for accounting integrity.
            $check_payments_query = "SELECT COUNT(*) FROM payments WHERE sale_id = ?";
            $check_payments_stmt = $db->prepare($check_payments_query);
            $check_payments_stmt->execute([$sale_id]);
            if ($check_payments_stmt->fetchColumn() > 0) {
                throw new Exception("Cannot edit sale with existing payments. Please void all payments first if you need to modify sale items or amounts.");
            }
        }


        // Calculate total amounts and validate sale items, checking stock.
        foreach ($sale_items_data as $item) {
            $product_id = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            $unit_price = filter_var($item['unit_price'] ?? null, FILTER_VALIDATE_FLOAT);

            if (!$product_id || !$quantity || $quantity <= 0 || !$unit_price || $unit_price <= 0) {
                throw new Exception("Invalid sale item data provided (Product, Quantity, Unit Price must be positive numbers).");
            }

            $item_total_price = round($quantity * $unit_price, 2);
            $total_amount += $item_total_price;

            // Determine stock impact for this item based on add/edit.
            $stock_to_check_against = $quantity;
            if ($sale_id) { // If editing
                $original_qty_for_this_product = 0;
                foreach ($original_sale_items as $original_item) {
                    if ($original_item['product_id'] === $product_id) {
                        $original_qty_for_this_product = $original_item['quantity'];
                        break;
                    }
                }
                $stock_to_check_against = $quantity - $original_qty_for_this_product; // Net change needed from stock
            }

            // Check product stock in 'wholesale' location (adjusting for previous quantity if editing).
            if ($stock_to_check_against > 0) { // Only check if quantity is increasing or new item added
                $stock_check_query = "SELECT quantity FROM inventory WHERE product_id = ? AND location = 'wholesale' FOR UPDATE"; // Lock row
                $stock_check_stmt = $db->prepare($stock_check_query);
                $stock_check_stmt->execute([$product_id]);
                $available_stock = $stock_check_stmt->fetchColumn() ?: 0;

                if ($available_stock < $stock_to_check_against) {
                    $product_name_q = "SELECT name FROM products WHERE id = ?";
                    $product_name_s = $db->prepare($product_name_q);
                    $product_name_s->execute([$product_id]);
                    $product_name_for_error = $product_name_s->fetchColumn() ?: 'Unknown Product';
                    throw new Exception("Insufficient stock for " . htmlspecialchars($product_name_for_error) . ". Available: " . $available_stock . ", Needed: " . $stock_to_check_against . ".");
                }
            }

            $processed_sale_items[] = [
                'product_id' => $product_id,
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'total_price' => $item_total_price
            ];
        }

        $net_amount = $total_amount - $discount_amount + $tax_amount + $shipping_cost;
        if ($net_amount < 0) {
            throw new Exception("Net amount cannot be negative. Please check discount, tax, and shipping values.");
        }


        $message = "";
        $action_type = 'create';
        $entity_id_for_log = $sale_id; // Default for update

        // Start transaction for atomicity.
        $db->beginTransaction();

        if ($sale_id) {
            // EDITING an existing sale.
            // Revert stock for original items (before processing new items).
            $revert_inventory_query = "UPDATE inventory SET quantity = quantity + ? WHERE product_id = ? AND location = 'wholesale'";
            $revert_inventory_stmt = $db->prepare($revert_inventory_query);
            foreach ($original_sale_items as $original_item) {
                $revert_inventory_stmt->execute([$original_item['quantity'], $original_item['product_id']]);
            }

            // Update `sales` table.
            $update_sale_query = "UPDATE sales SET customer_id = ?, sale_date = ?, total_amount = ?, discount_amount = ?, tax_amount = ?, shipping_cost = ?, net_amount = ?, notes = ?, updated_at = NOW() WHERE id = ?";
            $update_sale_stmt = $db->prepare($update_sale_query);
            $update_sale_stmt->execute([
                $customer_id, $sale_date, $total_amount, $discount_amount, $tax_amount, $shipping_cost, $net_amount, $notes, $sale_id
            ]);

            // Delete old `sale_items` and insert new ones (simpler than complex update logic).
            $delete_sale_items_query = "DELETE FROM sale_items WHERE sale_id = ?";
            $delete_sale_items_stmt = $db->prepare($delete_sale_items_query);
            $delete_sale_items_stmt->execute([$sale_id]);

            $message = "Sale updated successfully.";
            $action_type = 'update';

        } else {
            // ADDING a new sale.
            // Generate unique invoice number.
            $invoice_number = 'INV-' . date('Ymd') . '-' . substr(uniqid(), -4);

            // Insert into `sales` table.
            $insert_sale_query = "INSERT INTO sales (invoice_number, customer_id, sale_date, total_amount, discount_amount, tax_amount, shipping_cost, net_amount, payment_status, notes, created_by)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'unpaid', ?, ?)";
            $insert_sale_stmt = $db->prepare($insert_sale_query);
            $insert_sale_stmt->execute([
                $invoice_number, $customer_id, $sale_date, $total_amount, $discount_amount, $tax_amount, $shipping_cost, $net_amount, $notes, $created_by
            ]);
            $entity_id_for_log = $db->lastInsertId();
            $message = "Sale recorded successfully.";
            $action_type = 'create';
        }

        // Insert/re-insert into `sale_items` table and update inventory (for processed items).
        $insert_sale_item_query = "INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)";
        $update_inventory_query = "UPDATE inventory SET quantity = quantity - ? WHERE product_id = ? AND location = 'wholesale'"; // Decrease inventory

        foreach ($processed_sale_items as $item) {
            $insert_sale_item_stmt = $db->prepare($insert_sale_item_query);
            $insert_sale_item_stmt->execute([
                $entity_id_for_log, $item['product_id'], $item['quantity'], $item['unit_price'], $item['total_price']
            ]);

            $update_inventory_stmt = $db->prepare($update_inventory_query);
            $update_inventory_stmt->execute([$item['quantity'], $item['product_id']]);
        }


        // Log the activity using Auth class.
        $invoice_display = $sale_id ? (new \PDOStatement($db))->query("SELECT invoice_number FROM sales WHERE id = " . $sale_id)->fetchColumn() : $invoice_number; // Get invoice for log
        $auth->logActivity(
            $_SESSION['user_id'],
            $action_type,
            'sales',
            ($action_type === 'create' ? 'Created new' : 'Updated') . ' sale: ' . htmlspecialchars($invoice_display) . ' for customer ID ' . $customer_id . '. Net: Rs. ' . number_format($net_amount, 2),
            $entity_id_for_log
        );

        // Commit transaction.
        $db->commit();

        echo json_encode(['success' => true, 'message' => $message, 'sale_id' => $entity_id_for_log]);
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
            'sales',
            'Failed to save sale: ' . $e->getMessage(),
            $sale_id ?? null
        );

        echo json_encode(['success' => false, 'message' => 'An error occurred while saving the sale: ' . $e->getMessage()]);
        exit;
    }
} else {
    // Not a POST request.
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
    exit;
}