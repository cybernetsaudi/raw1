<?php
// File: add-sale.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class
include_once '../includes/header.php'; // Include header before any HTML output

// Check user authentication and role (e.g., only 'shopkeeper' or 'owner' can add/edit sales).
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'shopkeeper' && $_SESSION['role'] !== 'owner')) {
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to manage sales.";
    header('Location: sales.php'); // Redirect to sales list
    exit;
}

$page_title = "Add New Sale";
$sale_id = isset($_GET['id']) ? intval($_GET['id']) : null;
$sale = null; // Will hold sale data if editing
$sale_items_data_for_js = []; // To pass existing items to JS for edit mode

// Initialize database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

// Fetch customers and products for dropdowns.
try {
    $customers_query = "SELECT id, name, phone FROM customers ORDER BY name ASC";
    $customers_stmt = $db->query($customers_query);
    $customers = $customers_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch products that are in 'wholesale' inventory (ready for sale).
    $products_query = "
        SELECT p.id, p.name, p.sku, COALESCE(SUM(i.quantity), 0) as available_stock
        FROM products p
        LEFT JOIN inventory i ON p.id = i.product_id AND i.location = 'wholesale'
        GROUP BY p.id, p.name, p.sku
        HAVING available_stock > 0
        ORDER BY p.name ASC
    ";
    $products_stmt = $db->query($products_query);
    $products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($sale_id) {
        // Fetch existing sale data for editing.
        $sale_query = "SELECT s.*, c.name as customer_name FROM sales s JOIN customers c ON s.customer_id = c.id WHERE s.id = ?";
        // If shopkeeper, ensure they can only edit sales they created
        if ($_SESSION['role'] === 'shopkeeper') {
            $sale_query .= " AND s.created_by = " . $_SESSION['user_id'];
        }
        $sale_stmt = $db->prepare($sale_query);
        $sale_stmt->execute([$sale_id]);
        $sale = $sale_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale) {
            throw new Exception("Sale record not found for editing or you do not have permission to edit it.");
        }
        $page_title = "Edit Sale: #" . htmlspecialchars($sale['invoice_number']);

        // Fetch existing sale items for edit form population.
        $sale_items_query = "SELECT si.*, p.name as product_name, p.sku as product_sku,
                             (SELECT COALESCE(SUM(quantity),0) FROM inventory WHERE product_id = si.product_id AND location = 'wholesale') as available_stock_current_inv
                             FROM sale_items si
                             JOIN products p ON si.product_id = p.id
                             WHERE si.sale_id = ?";
        $sale_items_stmt = $db->prepare($sale_items_query);
        $sale_items_stmt->execute([$sale_id]);
        $sale_items_data_for_js = $sale_items_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Add the current quantities back to available stock conceptually for edit form's validation
        // This is important because the stock was reduced when the sale was made.
        // When editing, the original quantity is 'returned' and new quantity 'taken'.
        // The API `save-sale.php` handles the actual stock manipulation.
        // Here, we ensure the UI validation reflects what's available *if the original items were returned*.
        foreach ($sale_items_data_for_js as &$item) {
            $item['available_stock'] = $item['available_stock_current_inv'] + $item['quantity'];
        }
        unset($item); // Break reference

        // Log viewing the edit page.
        $auth->logActivity(
            $_SESSION['user_id'],
            'read',
            'sales',
            'Accessed Edit Sale page for Invoice: ' . htmlspecialchars($sale['invoice_number']) . ' (ID: ' . $sale_id . ')',
            $sale_id
        );

    } else {
        // Log viewing the add page.
        $auth->logActivity(
            $_SESSION['user_id'],
            'read',
            'sales',
            'Accessed Add New Sale page',
            null
        );
    }

} catch (Exception $e) {
    // Log the error.
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'sales',
        'Error loading sale form: ' . $e->getMessage(),
        $sale_id ?? null
    );
    $_SESSION['error_message'] = $e->getMessage();
    header('Location: sales.php'); // Redirect if error loading data
    exit;
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt;
    <a href="sales.php">Sales</a> &gt;
    <span><?php echo htmlspecialchars($page_title); ?></span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2><?php echo htmlspecialchars($page_title); ?></h2>
    </div>
    <div class="page-actions">
        <a href="sales.php" class="button secondary">
            <i class="fas fa-arrow-left"></i> Back to Sales
        </a>
    </div>
</div>

<?php
// Display success or error messages from session
if (isset($_SESSION['success_message'])) {
    echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i><span>' . htmlspecialchars($_SESSION['success_message']) . '</span><span class="alert-close">&times;</span></div>';
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    echo '<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span>' . htmlspecialchars($_SESSION['error_message']) . '</span><span class="alert-close">&times;</span></div>';
    unset($_SESSION['error_message']);
}
?>

<div class="container-fluid">
    <div class="card add-sale-card">
        <div class="card-header">
            <h3>Sale Details</h3>
        </div>
        <div class="card-content">
            <form id="saleForm" method="post" action="../api/save-sale.php">
                <input type="hidden" name="sale_id" value="<?php echo htmlspecialchars($sale['id'] ?? ''); ?>">

                <div class="form-group">
                    <label for="customer_id">Customer:</label>
                    <select id="customer_id" name="customer_id" required <?php echo $sale_id ? 'disabled' : ''; ?>>
                        <option value="">Select Customer</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo htmlspecialchars($customer['id']); ?>"
                                <?php echo (isset($sale['customer_id']) && $sale['customer_id'] == $customer['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($customer['name']); ?> (<?php echo htmlspecialchars($customer['phone']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($sale_id): ?>
                        <input type="hidden" name="customer_id" value="<?php echo htmlspecialchars($sale['customer_id']); ?>">
                        <small class="form-text text-muted">Customer cannot be changed for existing sales.</small>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="sale_date">Sale Date:</label>
                    <input type="date" id="sale_date" name="sale_date" value="<?php echo htmlspecialchars($sale['sale_date'] ?? date('Y-m-d')); ?>" required>
                </div>

                <h4>Sale Items</h4>
                <div id="saleItemsContainer">
                    <?php if (!empty($sale_items_data_for_js)): ?>
                        <?php foreach ($sale_items_data_for_js as $index => $item): ?>
                            <div class="sale-item-row" data-item-index="<?php echo $index; ?>">
                                <div class="form-row">
                                    <div class="form-group col-md-4">
                                        <label for="product_id_<?php echo $index; ?>">Product:</label>
                                        <select id="product_id_<?php echo $index; ?>" name="sale_items[<?php echo $index; ?>][product_id]" class="product-select" required>
                                            <option value="">Select Product</option>
                                            <?php foreach ($products as $product): ?>
                                                <option value="<?php echo htmlspecialchars($product['id']); ?>"
                                                    <?php echo ($item['product_id'] == $product['id']) ? 'selected' : ''; ?>
                                                    data-stock="<?php echo htmlspecialchars($product['available_stock']); ?>">
                                                    <?php echo htmlspecialchars($product['name']); ?> (SKU: <?php echo htmlspecialchars($product['sku']); ?>) [Stock: <?php echo htmlspecialchars($product['available_stock']); ?>]
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted available-stock-text">Available: <?php echo htmlspecialchars($item['available_stock']); ?></small>
                                    </div>
                                    <div class="form-group col-md-2">
                                        <label for="quantity_<?php echo $index; ?>">Quantity:</label>
                                        <input type="number" id="quantity_<?php echo $index; ?>" name="sale_items[<?php echo $index; ?>][quantity]" class="item-quantity" step="1" min="1" required value="<?php echo htmlspecialchars($item['quantity']); ?>">
                                    </div>
                                    <div class="form-group col-md-3">
                                        <label for="unit_price_<?php echo $index; ?>">Unit Price (Rs.):</label>
                                        <input type="number" id="unit_price_<?php echo $index; ?>" name="sale_items[<?php echo $index; ?>][unit_price]" class="item-unit-price" step="0.01" min="0.01" required value="<?php echo htmlspecialchars($item['unit_price']); ?>">
                                    </div>
                                    <div class="form-group col-md-2">
                                        <label for="total_price_<?php echo $index; ?>">Total Price (Rs.):</label>
                                        <input type="number" id="total_price_<?php echo $index; ?>" name="sale_items[<?php echo $index; ?>][total_price]" class="item-total-price" step="0.01" readonly value="<?php echo htmlspecialchars($item['total_price']); ?>">
                                    </div>
                                    <div class="form-group col-md-1 d-flex align-items-end">
                                        <button type="button" class="button button-danger remove-item-btn"><i class="fas fa-times"></i></button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="sale-item-row" data-item-index="0">
                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label for="product_id_0">Product:</label>
                                    <select id="product_id_0" name="sale_items[0][product_id]" class="product-select" required>
                                        <option value="">Select Product</option>
                                        <?php foreach ($products as $product): ?>
                                            <option value="<?php echo htmlspecialchars($product['id']); ?>" data-stock="<?php echo htmlspecialchars($product['available_stock']); ?>">
                                                <?php echo htmlspecialchars($product['name']); ?> (SKU: <?php echo htmlspecialchars($product['sku']); ?>) [Stock: <?php echo htmlspecialchars($product['available_stock']); ?>]
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="form-text text-muted available-stock-text">Available: 0</small>
                                </div>
                                <div class="form-group col-md-2">
                                    <label for="quantity_0">Quantity:</label>
                                    <input type="number" id="quantity_0" name="sale_items[0][quantity]" class="item-quantity" step="1" min="1" required>
                                </div>
                                <div class="form-group col-md-3">
                                    <label for="unit_price_0">Unit Price (Rs.):</label>
                                    <input type="number" id="unit_price_0" name="sale_items[0][unit_price]" class="item-unit-price" step="0.01" min="0.01" required>
                                </div>
                                <div class="form-group col-md-2">
                                    <label for="total_price_0">Total Price (Rs.):</label>
                                    <input type="number" id="total_price_0" name="sale_items[0][total_price]" class="item-total-price" step="0.01" readonly>
                                </div>
                                <div class="form-group col-md-1 d-flex align-items-end">
                                    <button type="button" class="button button-danger remove-item-btn"><i class="fas fa-times"></i></button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <button type="button" id="addSaleItemBtn" class="button secondary"><i class="fas fa-plus"></i> Add Another Item</button>

                <hr class="my-4">

                <div class="form-group">
                    <label for="total_amount_display">Subtotal (Rs.):</label>
                    <input type="number" id="total_amount_display" name="total_amount_display" step="0.01" readonly placeholder="Calculated automatically">
                    <input type="hidden" id="total_amount_hidden" name="total_amount"> </div>

                <div class="form-group">
                    <label for="discount_amount">Discount Amount (Rs.):</label>
                    <input type="number" id="discount_amount" name="discount_amount" step="0.01" min="0" value="<?php echo htmlspecialchars($sale['discount_amount'] ?? '0.00'); ?>">
                </div>

                <div class="form-group">
                    <label for="tax_amount">Tax Amount (Rs.):</label>
                    <input type="number" id="tax_amount" name="tax_amount" step="0.01" min="0" value="<?php echo htmlspecialchars($sale['tax_amount'] ?? '0.00'); ?>">
                </div>

                <div class="form-group">
                    <label for="shipping_cost">Shipping Cost (Rs.):</label>
                    <input type="number" id="shipping_cost" name="shipping_cost" step="0.01" min="0" value="<?php echo htmlspecialchars($sale['shipping_cost'] ?? '0.00'); ?>">
                </div>

                <div class="form-group">
                    <label for="net_amount_display">Net Amount (Rs.):</label>
                    <input type="number" id="net_amount_display" name="net_amount_display" step="0.01" readonly placeholder="Calculated automatically">
                    <input type="hidden" id="net_amount_hidden" name="net_amount"> </div>

                <div class="form-group">
                    <label for="notes">Notes (Optional):</label>
                    <textarea id="notes" name="notes" rows="3" placeholder="Any special instructions or details about the sale"><?php echo htmlspecialchars($sale['notes'] ?? ''); ?></textarea>
                </div>

                <div class="form-actions">
                    <a href="sales.php" class="button secondary">Cancel</a>
                    <button type="submit" class="button primary">Save Sale</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Close alert buttons
    const alertCloseButtons = document.querySelectorAll('.alert-close');
    alertCloseButtons.forEach(button => {
        button.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });

    const saleItemsContainer = document.getElementById('saleItemsContainer');
    const addSaleItemBtn = document.getElementById('addSaleItemBtn');
    // PHP-rendered products data (includes available_stock)
    const productsData = <?php echo json_encode($products); ?>;
    // PHP-rendered existing sale items (for edit mode)
    const initialSaleItems = <?php echo json_encode($sale_items_data_for_js); ?>;

    let itemIndex = initialSaleItems.length > 0 ? initialSaleItems.length -1 : 0; // Initialize index based on existing items

    const discountAmountInput = document.getElementById('discount_amount');
    const taxAmountInput = document.getElementById('tax_amount');
    const shippingCostInput = document.getElementById('shipping_cost');
    const totalAmountDisplay = document.getElementById('total_amount_display');
    const totalAmountHidden = document.getElementById('total_amount_hidden');
    const netAmountDisplay = document.getElementById('net_amount_display');
    const netAmountHidden = document.getElementById('net_amount_hidden');

    // Function to get product stock from productsData (PHP-passed array)
    function getProductStock(productId) {
        const product = productsData.find(p => p.id == productId);
        return product ? parseInt(product.available_stock) : 0;
    }

    // Function to update available stock text and max quantity
    function updateProductStockInfo(productSelectElement) {
        const row = productSelectElement.closest('.sale-item-row');
        const availableStockText = row.querySelector('.available-stock-text');
        const quantityInput = row.querySelector('.item-quantity');
        const productId = productSelectElement.value;

        const stock = getProductStock(productId);
        availableStockText.textContent = `Available: ${stock}`;
        quantityInput.setAttribute('max', stock); // Set max quantity based on stock
        quantityInput.value = ''; // Clear quantity when product changes
        updateItemRowTotal(quantityInput); // Recalculate item total
    }

    // Function to calculate total for an item row
    function updateItemRowTotal(inputElement) {
        const row = inputElement.closest('.sale-item-row');
        if (!row) return;

        const productSelect = row.querySelector('.product-select');
        const quantityInput = row.querySelector('.item-quantity');
        const unitPriceInput = row.querySelector('.item-unit-price');
        const totalInput = row.querySelector('.item-total-price');

        const quantity = parseFloat(quantityInput.value) || 0;
        const unitPrice = parseFloat(unitPriceInput.value) || 0;
        const productId = productSelect.value;
        const stock = getProductStock(productId);

        // Client-side validation for quantity against stock
        if (quantity > stock) {
            showValidationError(quantityInput, `Quantity exceeds available stock (${stock}).`);
        } else {
            removeValidationError(quantityInput);
        }

        totalInput.value = (quantity * unitPrice).toFixed(2);
        calculateOverallTotals();
    }

    // Function to calculate overall totals (subtotal, net amount)
    function calculateOverallTotals() {
        let subtotal = 0;
        document.querySelectorAll('.item-total-price').forEach(input => {
            subtotal += parseFloat(input.value) || 0;
        });

        const discount = parseFloat(discountAmountInput.value) || 0;
        const tax = parseFloat(taxAmountInput.value) || 0;
        const shipping = parseFloat(shippingCostInput.value) || 0;

        let netAmount = subtotal - discount + tax + shipping;

        totalAmountDisplay.value = subtotal.toFixed(2);
        totalAmountHidden.value = subtotal.toFixed(2);
        netAmountDisplay.value = netAmount.toFixed(2);
        netAmountHidden.value = netAmount.toFixed(2);
    }

    // Function to add a new sale item row
    function addSaleItemRow(itemData = null) {
        itemIndex++; // Increment for unique IDs

        const newRowHtml = `
            <div class="sale-item-row" data-item-index="${itemIndex}">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label for="product_id_${itemIndex}">Product:</label>
                        <select id="product_id_${itemIndex}" name="sale_items[${itemIndex}][product_id]" class="product-select" required>
                            <option value="">Select Product</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?php echo htmlspecialchars($product['id']); ?>" data-stock="<?php echo htmlspecialchars($product['available_stock']); ?>">
                                    <?php echo htmlspecialchars($product['name']); ?> (SKU: <?php echo htmlspecialchars($product['sku']); ?>) [Stock: <?php echo htmlspecialchars($product['available_stock']); ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted available-stock-text">Available: 0</small>
                    </div>
                    <div class="form-group col-md-2">
                        <label for="quantity_${itemIndex}">Quantity:</label>
                        <input type="number" id="quantity_${itemIndex}" name="sale_items[${itemIndex}][quantity]" class="item-quantity" step="1" min="1" required>
                    </div>
                    <div class="form-group col-md-3">
                        <label for="unit_price_${itemIndex}">Unit Price (Rs.):</label>
                        <input type="number" id="unit_price_${itemIndex}" name="sale_items[${itemIndex}][unit_price]" class="item-unit-price" step="0.01" min="0.01" required>
                    </div>
                    <div class="form-group col-md-2">
                        <label for="total_price_${itemIndex}">Total Price (Rs.):</label>
                        <input type="number" id="total_price_${itemIndex}" name="sale_items[${itemIndex}][total_price]" class="item-total-price" step="0.01" readonly>
                    </div>
                    <div class="form-group col-md-1 d-flex align-items-end">
                        <button type="button" class="button button-danger remove-item-btn"><i class="fas fa-times"></i></button>
                    </div>
                </div>
            </div>
        `;
        saleItemsContainer.insertAdjacentHTML('beforeend', newRowHtml);
        const newRow = saleItemsContainer.lastElementChild;
        attachRowListeners(newRow); // Attach listeners to the new row

        if (itemData) {
            newRow.querySelector('.product-select').value = itemData.product_id;
            newRow.querySelector('.item-quantity').value = itemData.quantity;
            newRow.querySelector('.item-unit-price').value = itemData.unit_price;
            newRow.querySelector('.item-total-price').value = itemData.total_price;
            newRow.querySelector('.available-stock-text').textContent = `Available: ${itemData.available_stock}`;
            newRow.querySelector('.item-quantity').setAttribute('max', itemData.available_stock); // Set max based on stock
        } else {
            // For brand new row, update stock info based on default selected product or '0'
            updateProductStockInfo(newRow.querySelector('.product-select'));
        }
        calculateOverallTotals();
    }

    // Function to attach listeners to a row
    function attachRowListeners(row) {
        row.querySelector('.product-select').addEventListener('change', (e) => updateProductStockInfo(e.target));
        row.querySelector('.item-quantity').addEventListener('input', (e) => updateItemRowTotal(e.target));
        row.querySelector('.item-unit-price').addEventListener('input', (e) => updateItemRowTotal(e.target));
        row.querySelector('.remove-item-btn').addEventListener('click', function() {
            row.remove();
            calculateOverallTotals(); // Recalculate totals after removing
            // If all rows are removed, add an empty one back
            if (saleItemsContainer.children.length === 0) {
                 addSaleItemRow();
            }
        });
    }

    // Initial setup for existing rows on load (for edit mode)
    if (initialSaleItems.length > 0) {
        // Remove the default empty row added by PHP if existing items are present
        if (saleItemsContainer.querySelector('.sale-item-row[data-item-index="0"]')) {
             saleItemsContainer.querySelector('.sale-item-row[data-item-index="0"]').remove();
        }
        initialSaleItems.forEach(item => addSaleItemRow(item));
    } else {
        // If no initial items (add mode), ensure at least one empty row
        if (saleItemsContainer.children.length === 0) {
            addSaleItemRow();
        } else {
            // Already one default row, just attach listeners if it's there
            attachRowListeners(saleItemsContainer.querySelector('.sale-item-row[data-item-index="0"]'));
        }
    }


    // Event listener for "Add Another Item" button
    addSaleItemBtn.addEventListener('click', addSaleItemRow);

    // Event listeners for overall total calculation inputs
    discountAmountInput.addEventListener('input', calculateOverallTotals);
    taxAmountInput.addEventListener('input', calculateOverallTotals);
    shippingCostInput.addEventListener('input', calculateOverallTotals);

    // Initial calculation (important for edit mode to populate totals)
    calculateOverallTotals();

    // Client-side form validation for the whole form
    const saleForm = document.getElementById('saleForm');
    if (saleForm) {
        saleForm.addEventListener('submit', function(event) {
            event.preventDefault(); // Prevent default form submission

            let isValid = true;

            // Clear previous validation errors
            document.querySelectorAll('.invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('.validation-error').forEach(el => el.remove());

            // Validate Customer and Sale Date
            if (!document.getElementById('customer_id').value) {
                showValidationError(document.getElementById('customer_id'), 'Please select a customer.');
                isValid = false;
            }
            if (!document.getElementById('sale_date').value) {
                showValidationError(document.getElementById('sale_date'), 'Sale date is required.');
                isValid = false;
            }

            // Validate at least one sale item
            const saleItemRows = document.querySelectorAll('.sale-item-row');
            if (saleItemRows.length === 0) {
                if (typeof showToast === 'function') {
                    showToast('error', 'Please add at least one sale item.');
                } else {
                    alert('Please add at least one sale item.');
                }
                isValid = false;
            }

            // Validate each sale item row
            saleItemRows.forEach(row => {
                const productSelect = row.querySelector('.product-select');
                const quantityInput = row.querySelector('.item-quantity');
                const unitPriceInput = row.querySelector('.item-unit-price');

                if (!productSelect.value) {
                    showValidationError(productSelect, 'Product is required.');
                    isValid = false;
                }
                if (!quantityInput.value || parseFloat(quantityInput.value) <= 0) {
                    showValidationError(quantityInput, 'Quantity must be positive.');
                    isValid = false;
                }
                if (!unitPriceInput.value || parseFloat(unitPriceInput.value) <= 0) {
                    showValidationError(unitPriceInput, 'Unit Price must be positive.');
                    isValid = false;
                }
                // Re-run stock validation for each item
                const productStock = getProductStock(productSelect.value);
                if (parseFloat(quantityInput.value) > productStock) {
                    showValidationError(quantityInput, `Quantity exceeds available stock (${productStock}).`);
                    isValid = false;
                }
            });

            // Validate overall amounts (e.g., net amount not negative)
            if (parseFloat(netAmountHidden.value) < 0) {
                showValidationError(netAmountDisplay, 'Net amount cannot be negative. Adjust discounts/taxes.');
                isValid = false;
            }
            if (parseFloat(totalAmountHidden.value) <= 0 && saleItemRows.length > 0) {
                showValidationError(totalAmountDisplay, 'Sale subtotal must be greater than zero.');
                isValid = false;
            }


            if (!isValid) {
                return; // Stop if validation fails
            }

            // Show loading state
            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner"></span> Saving...';

            const formData = new FormData(this); // Get form data
            const actionUrl = this.action; // Get form action URL

            fetch(actionUrl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                submitButton.disabled = false;
                submitButton.innerHTML = 'Save Sale'; // Reset button text

                if (typeof showToast === 'function') {
                    showToast(data.success ? 'success' : 'error', data.message);
                } else {
                    alert(data.message);
                }
                if (data.success) {
                    // Redirect to view the newly created/updated sale
                    window.location.href = 'view-sale.php?id=' + data.sale_id;
                }
            })
            .catch(error => {
                submitButton.disabled = false;
                submitButton.innerHTML = 'Save Sale';
                console.error('Error saving sale:', error);
                if (typeof showToast === 'function') {
                    showToast('error', 'An unexpected error occurred while saving the sale.');
                } else {
                    alert('An unexpected error occurred.');
                }
            });
        });
    }

    // Helper functions for validation (can be moved to utils.js later)
    function showValidationError(element, message) {
        const existingError = element.closest('.form-group').querySelector('.validation-error');
        if (existingError) { existingError.remove(); }
        element.classList.add('invalid-input');
        const errorElement = document.createElement('div');
        errorElement.className = 'validation-error';
        errorElement.textContent = message;
        element.closest('.form-group').appendChild(errorElement);
        element.focus();
    }

    // Remove validation error when input changes
    document.querySelectorAll('#saleForm input, #saleForm select, #saleForm textarea').forEach(input => {
        input.addEventListener('input', function() {
            this.classList.remove('invalid-input');
            const errorElement = this.closest('.form-group').querySelector('.validation-error');
            if (errorElement) { errorElement.remove(); }
        });
    });
});
</script>

<?php include_once '../includes/footer.php'; ?>