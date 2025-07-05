<?php
// File: add-purchase.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class
include_once '../includes/header.php'; // Include header before any HTML output

// Check user authentication and role (e.g., only 'incharge' or 'owner' can add/edit purchases).
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'incharge' && $_SESSION['role'] !== 'owner')) {
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to manage purchases.";
    header('Location: purchases.php'); // Redirect to purchases list
    exit;
}

$page_title = "Add New Purchase";
$purchase_id = isset($_GET['id']) ? intval($_GET['id']) : null;
$purchase = null; // Will hold purchase data if editing

// Initialize database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

// Fetch necessary data for forms (materials, funds).
try {
    $materials_query = "SELECT id, name, unit FROM raw_materials ORDER BY name ASC";
    $materials_stmt = $db->query($materials_query);
    $materials = $materials_stmt->fetchAll(PDO::FETCH_ASSOC);

    $funds_query = "SELECT id, amount, balance, description FROM funds WHERE status = 'active' AND type = 'investment' ORDER BY id DESC";
    $funds_stmt = $db->query($funds_query);
    $active_funds = $funds_stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($purchase_id) {
        // Fetch existing purchase data for editing.
        $purchase_query = "SELECT p.*, rm.unit as material_unit FROM purchases p JOIN raw_materials rm ON p.material_id = rm.id WHERE p.id = ?";
        $purchase_stmt = $db->prepare($purchase_query);
        $purchase_stmt->execute([$purchase_id]);
        $purchase = $purchase_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$purchase) {
            throw new Exception("Purchase record not found for editing.");
        }
        $page_title = "Edit Purchase: #" . htmlspecialchars($purchase['id']);

        // Log viewing the edit page.
        $auth->logActivity(
            $_SESSION['user_id'],
            'read',
            'purchases',
            'Accessed Edit Purchase page for ID: ' . $purchase_id . ' (Invoice: ' . htmlspecialchars($purchase['invoice_number'] ?? 'N/A') . ')',
            $purchase_id
        );

    } else {
        // Log viewing the add page.
        $auth->logActivity(
            $_SESSION['user_id'],
            'read',
            'purchases',
            'Accessed Add New Purchase page',
            null
        );
    }

} catch (Exception $e) {
    // Log the error.
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'purchases',
        'Error loading purchase form: ' . $e->getMessage(),
        $purchase_id ?? null
    );
    $_SESSION['error_message'] = $e->getMessage();
    header('Location: purchases.php'); // Redirect if error loading data
    exit;
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt;
    <a href="purchases.php">Purchases</a> &gt;
    <span><?php echo htmlspecialchars($page_title); ?></span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2><?php echo htmlspecialchars($page_title); ?></h2>
    </div>
    <div class="page-actions">
        <a href="purchases.php" class="button secondary">
            <i class="fas fa-arrow-left"></i> Back to Purchases
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
    <div class="card add-purchase-card">
        <div class="card-header">
            <h3>Purchase Details</h3>
        </div>
        <div class="card-content">
            <form id="purchaseForm" method="post" action="../api/save-purchase.php">
                <input type="hidden" name="purchase_id" value="<?php echo htmlspecialchars($purchase['id'] ?? ''); ?>">

                <div class="form-group">
                    <label for="material_id">Raw Material:</label>
                    <select id="material_id" name="material_id" required>
                        <option value="">Select Raw Material</option>
                        <?php foreach ($materials as $material): ?>
                            <option value="<?php echo htmlspecialchars($material['id']); ?>"
                                <?php echo (isset($purchase['material_id']) && $purchase['material_id'] == $material['id']) ? 'selected' : ''; ?>
                                data-unit="<?php echo htmlspecialchars($material['unit']); ?>">
                                <?php echo htmlspecialchars($material['name'] . ' (' . $material['unit'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="quantity">Quantity:</label>
                    <input type="number" id="quantity" name="quantity" step="0.01" min="0.01" required placeholder="e.g., 100.5" value="<?php echo htmlspecialchars($purchase['quantity'] ?? ''); ?>">
                    <span id="materialUnit" class="form-text text-muted"></span>
                </div>

                <div class="form-group">
                    <label for="unit_price">Unit Price (Rs.):</label>
                    <input type="number" id="unit_price" name="unit_price" step="0.01" min="0.01" required placeholder="e.g., 50.00" value="<?php echo htmlspecialchars($purchase['unit_price'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="total_amount">Total Amount (Rs.):</label>
                    <input type="number" id="total_amount" name="total_amount" step="0.01" min="0.01" required readonly placeholder="Calculated automatically" value="<?php echo htmlspecialchars($purchase['total_amount'] ?? ''); ?>">
                    <small class="form-text text-muted">Calculated automatically (Quantity x Unit Price).</small>
                </div>

                <div class="form-group">
                    <label for="vendor_name">Vendor Name (Optional):</label>
                    <input type="text" id="vendor_name" name="vendor_name" placeholder="e.g., Fabric Supplier Co." value="<?php echo htmlspecialchars($purchase['vendor_name'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="vendor_contact">Vendor Contact (Optional):</label>
                    <input type="text" id="vendor_contact" name="vendor_contact" placeholder="e.g., +923001234567" value="<?php echo htmlspecialchars($purchase['vendor_contact'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="invoice_number">Invoice Number (Optional):</label>
                    <input type="text" id="invoice_number" name="invoice_number" placeholder="e.g., INV-2023-001" value="<?php echo htmlspecialchars($purchase['invoice_number'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="purchase_date">Purchase Date:</label>
                    <input type="date" id="purchase_date" name="purchase_date" value="<?php echo htmlspecialchars($purchase['purchase_date'] ?? date('Y-m-d')); ?>" required>
                </div>

                <div class="form-group">
                    <label for="fund_id">Fund Used (Optional):</label>
                    <select id="fund_id" name="fund_id">
                        <option value="">Select Fund (if applicable)</option>
                        <?php foreach ($active_funds as $fund): ?>
                            <option value="<?php echo htmlspecialchars($fund['id']); ?>"
                                <?php echo (isset($purchase['fund_id']) && $purchase['fund_id'] == $fund['id']) ? 'selected' : ''; ?>>
                                Fund #<?php echo htmlspecialchars($fund['id']); ?> (Rs. <?php echo number_format($fund['balance'], 2); ?> available)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-text text-muted">Only active investment funds are shown.</small>
                </div>

                <div class="form-actions">
                    <a href="purchases.php" class="button secondary">Cancel</a>
                    <button type="submit" class="button primary">Save Purchase</button>
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

    const materialSelect = document.getElementById('material_id');
    const quantityInput = document.getElementById('quantity');
    const unitPriceInput = document.getElementById('unit_price');
    const totalAmountInput = document.getElementById('total_amount');
    const materialUnitSpan = document.getElementById('materialUnit');
    const purchaseForm = document.getElementById('purchaseForm');

    // Store material units from PHP for dynamic display
    const materialsData = <?php echo json_encode($materials); ?>;
    const materialUnits = {};
    materialsData.forEach(material => {
        materialUnits[material.id] = material.unit;
    });

    // Function to calculate total amount
    function calculateTotal() {
        const quantity = parseFloat(quantityInput.value);
        const unitPrice = parseFloat(unitPriceInput.value);

        if (!isNaN(quantity) && !isNaN(unitPrice) && quantity > 0 && unitPrice > 0) {
            totalAmountInput.value = (quantity * unitPrice).toFixed(2);
        } else {
            totalAmountInput.value = '';
        }
    }

    // Function to update material unit display
    function updateMaterialUnit() {
        const selectedMaterialId = materialSelect.value;
        if (selectedMaterialId && materialUnits[selectedMaterialId]) {
            materialUnitSpan.textContent = 'Unit: ' + materialUnits[selectedMaterialId];
        } else {
            materialUnitSpan.textContent = '';
        }
    }

    // Event listeners for calculations and unit display
    materialSelect.addEventListener('change', updateMaterialUnit);
    quantityInput.addEventListener('input', calculateTotal);
    unitPriceInput.addEventListener('input', calculateTotal);

    // Initial call to set unit display and total if values are pre-filled (for edit mode)
    updateMaterialUnit();
    calculateTotal();

    // Client-side form validation and AJAX submission
    if (purchaseForm) {
        purchaseForm.addEventListener('submit', function(event) {
            event.preventDefault(); // Prevent default form submission

            let isValid = true;
            // Clear previous validation errors
            document.querySelectorAll('.invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('.validation-error').forEach(el => el.remove());

            // Validate required fields and positive values
            if (!materialSelect.value) {
                showValidationError(materialSelect, 'Please select a raw material.');
                isValid = false;
            }
            if (!quantityInput.value || parseFloat(quantityInput.value) <= 0) {
                showValidationError(quantityInput, 'Please enter a valid quantity greater than zero.');
                isValid = false;
            }
            if (!unitPriceInput.value || parseFloat(unitPriceInput.value) <= 0) {
                showValidationError(unitPriceInput, 'Please enter a valid unit price greater than zero.');
                isValid = false;
            }
            if (!document.getElementById('purchase_date').value) {
                showValidationError(document.getElementById('purchase_date'), 'Purchase date is required.');
                isValid = false;
            }

            // Ensure total amount is calculated and valid
            if (!totalAmountInput.value || parseFloat(totalAmountInput.value) <= 0) {
                showValidationError(totalAmountInput, 'Total amount must be calculated and greater than zero.');
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
                submitButton.innerHTML = 'Save Purchase'; // Reset button text

                if (typeof showToast === 'function') { // Use showToast for notifications
                    showToast(data.success ? 'success' : 'error', data.message);
                } else {
                    alert(data.message); // Fallback
                }
                if (data.success) {
                    // Redirect to purchases list after successful save
                    window.location.href = 'purchases.php';
                }
            })
            .catch(error => {
                submitButton.disabled = false;
                submitButton.innerHTML = 'Save Purchase';
                console.error('Error saving purchase:', error);
                if (typeof showToast === 'function') {
                    showToast('error', 'An unexpected error occurred while saving the purchase.');
                } else {
                    alert('An unexpected error occurred.');
                }
            });
        });
    }

    // Helper functions for validation (can be moved to utils.js later for global use)
    function showValidationError(element, message) {
        const existingError = element.parentElement.querySelector('.validation-error');
        if (existingError) { existingError.remove(); }
        element.classList.add('invalid-input');
        const errorElement = document.createElement('div');
        errorElement.className = 'validation-error';
        errorElement.textContent = message;
        element.parentElement.appendChild(errorElement);
        element.focus();
    }

    // Remove validation error when input changes
    const formInputs = document.querySelectorAll('#purchaseForm input, #purchaseForm select');
    formInputs.forEach(input => {
        input.addEventListener('input', function() {
            this.classList.remove('invalid-input');
            const errorElement = this.parentElement.querySelector('.validation-error');
            if (errorElement) { errorElement.remove(); }
        });
    });
});
</script>

<?php include_once '../includes/footer.php'; ?>