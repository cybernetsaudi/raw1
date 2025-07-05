<?php
// File: add-cost.php

// Ensure session is started at the very beginning, before any output or other logic.
session_start();

// DEVELOPMENT ONLY: Remove these lines in production environment for security.
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

// Include necessary configuration and authentication classes.
include_once '../config/database.php';
include_once '../config/auth.php';

// Check user authentication and role (e.g., 'incharge' or 'owner' should be able to add costs)
// Assuming only 'incharge' or 'owner' can add costs as per typical roles.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'incharge' && $_SESSION['role'] !== 'owner')) {
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to add costs.";
    header('Location: manufacturing.php'); // Redirect to a suitable page
    exit;
}

$page_title = "Add Manufacturing Cost";
// Include header after session_start and initial checks, but before HTML output.
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging
$auth = new Auth($db);

$batch_id = null;
try {
    // Check if batch ID is provided in GET request
    if (!isset($_GET['batch_id']) || !is_numeric($_GET['batch_id'])) {
        throw new Exception("Invalid or missing batch ID provided.");
    }
    $batch_id = intval($_GET['batch_id']);

    // Get batch details
    $batch_query = "SELECT b.*, p.name as product_name, p.sku as product_sku
                   FROM manufacturing_batches b
                   JOIN products p ON b.product_id = p.id
                   WHERE b.id = ?";
    $batch_stmt = $db->prepare($batch_query);
    $batch_stmt->execute([$batch_id]);

    if ($batch_stmt->rowCount() === 0) {
        throw new Exception("Manufacturing batch not found.");
    }
    $batch = $batch_stmt->fetch(PDO::FETCH_ASSOC);

    // Check if batch is already completed (cannot add costs to completed batches)
    if ($batch['status'] === 'completed') {
        throw new Exception("Cannot add costs to a completed batch (Batch Number: " . htmlspecialchars($batch['batch_number']) . ").");
    }

    // Define cost types (consider fetching from DB if dynamic)
    $cost_types = [
        'labor' => 'Labor',
        'material' => 'Material',
        'packaging' => 'Packaging',
        'zipper' => 'Zipper',
        'sticker' => 'Sticker',
        'logo' => 'Logo',
        'tag' => 'Tag',
        'misc' => 'Miscellaneous',
        'overhead' => 'Overhead', // Added from manufacturing_costs table enum
        'electricity' => 'Electricity', // Added from manufacturing_costs table enum
        'maintenance' => 'Maintenance', // Added from manufacturing_costs table enum
        'other' => 'Other' // Added from manufacturing_costs table enum
    ];

    // Process form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            // Validate inputs
            $cost_type = $_POST['cost_type'] ?? '';
            $amount = $_POST['amount'] ?? '';
            $description = $_POST['description'] ?? '';

            // Server-side validation
            if (!array_key_exists($cost_type, $cost_types)) {
                throw new Exception("Invalid cost type selected.");
            }
            if (!is_numeric($amount) || $amount <= 0) {
                throw new Exception("Amount must be a positive number.");
            }
            $amount = round(floatval($amount), 2); // Ensure amount is float and rounded to 2 decimal places

            // Start transaction
            $db->beginTransaction();

            // Insert cost record
            $cost_query = "INSERT INTO manufacturing_costs
                          (batch_id, cost_type, amount, description, recorded_by, recorded_date)
                          VALUES (?, ?, ?, ?, ?, NOW())";
            $cost_stmt = $db->prepare($cost_query);
            $cost_stmt->execute([
                $batch_id,
                $cost_type,
                $amount,
                $description,
                $_SESSION['user_id']
            ]);

            // Log the activity using the Auth class
            $auth->logActivity(
                $_SESSION['user_id'],
                'create',
                'manufacturing_costs', // Changed module to be more specific
                "Added " . htmlspecialchars($cost_types[$cost_type]) . " cost of " . number_format($amount, 2) . " to batch " . htmlspecialchars($batch['batch_number']),
                $batch_id // Entity ID is the batch_id
            );

            // Commit transaction
            $db->commit();

            $_SESSION['success_message'] = "Cost added successfully for batch " . htmlspecialchars($batch['batch_number']) . ".";
            header('Location: add-cost.php?batch_id=' . $batch_id); // Redirect to prevent re-submission
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
                'manufacturing_costs',
                'Failed to add cost to batch ' . htmlspecialchars($batch['batch_number']) . ': ' . $e->getMessage(),
                $batch_id
            );

            $_SESSION['error_message'] = $e->getMessage();
            header('Location: add-cost.php?batch_id=' . $batch_id); // Redirect back with error
            exit;
        }
    }

    // After POST processing (or on initial GET), fetch/re-fetch existing costs for display
    $costs_query = "SELECT cost_type, SUM(amount) as total_amount
                   FROM manufacturing_costs
                   WHERE batch_id = ?
                   GROUP BY cost_type";
    $costs_stmt = $db->prepare($costs_query);
    $costs_stmt->execute([$batch_id]);
    $existing_costs = $costs_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate total existing cost
    $total_existing_cost = 0;
    foreach ($existing_costs as $cost) {
        $total_existing_cost += $cost['total_amount'];
    }

} catch (Exception $e) {
    // Catch errors from initial GET checks or if batch is not found/completed
    // Log the error
    $auth->logActivity(
        $_SESSION['user_id'],
        'error',
        'manufacturing_costs',
        'Error accessing add cost page: ' . $e->getMessage(),
        $batch_id // Will be null if batch_id was invalid
    );

    $_SESSION['error_message'] = $e->getMessage();
    header('Location: manufacturing.php'); // Redirect to main manufacturing page
    exit;
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt;
    <a href="manufacturing.php">Manufacturing Batches</a> &gt;
    <a href="view-batch.php?id=<?php echo htmlspecialchars($batch_id); ?>">View Batch <?php echo htmlspecialchars($batch['batch_number']); ?></a> &gt;
    <span>Add Cost</span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>Add Manufacturing Cost</h2>
        <span class="status-badge status-<?php echo htmlspecialchars($batch['status']); ?>"><?php echo ucfirst(htmlspecialchars($batch['status'])); ?></span>
    </div>
    <div class="page-actions">
        <a href="view-batch.php?id=<?php echo htmlspecialchars($batch_id); ?>" class="button secondary">
            <i class="fas fa-arrow-left"></i> Back to Batch Details
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

<div class="add-cost-container">
    <div class="batch-summary-card">
        <div class="card-header">
            <h3>Batch Information</h3>
        </div>
        <div class="card-content">
            <div class="batch-info">
                <div class="batch-number">
                    <span class="label">Batch Number:</span>
                    <span class="value"><?php echo htmlspecialchars($batch['batch_number']); ?></span>
                </div>
                <div class="product-info">
                    <span class="label">Product:</span>
                    <span class="value">
                        <?php echo htmlspecialchars($batch['product_name']); ?>
                        <span class="sku">(<?php echo htmlspecialchars($batch['product_sku']); ?>)</span>
                    </span>
                </div>
                <div class="quantity-info">
                    <span class="label">Quantity:</span>
                    <span class="value"><?php echo number_format($batch['quantity_produced']); ?> units</span>
                </div>
                <div class="status-info">
                    <span class="label">Status:</span>
                    <span class="value">
                        <span class="status-badge status-<?php echo htmlspecialchars($batch['status']); ?>"><?php echo ucfirst(htmlspecialchars($batch['status'])); ?></span>
                    </span>
                </div>
            </div>

            <?php if (!empty($existing_costs)): ?>
            <div class="cost-summary">
                <h4>Existing Costs</h4>
                <div class="cost-summary-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Cost Type</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($existing_costs as $cost): ?>
                            <tr>
                                <td><span class="cost-type cost-<?php echo htmlspecialchars($cost['cost_type']); ?>"><?php echo htmlspecialchars($cost_types[$cost['cost_type']]); ?></span></td>
                                <td class="amount-cell"><?php echo number_format($cost['total_amount'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td><strong>Total</strong></td>
                                <td class="amount-cell"><strong><?php echo number_format($total_existing_cost, 2); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?php if ($batch['quantity_produced'] > 0): ?>
                <div class="cost-per-unit">
                    <span class="label">Cost Per Unit:</span>
                    <span class="value"><?php echo number_format($total_existing_cost / $batch['quantity_produced'], 2); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="add-cost-form-card">
        <div class="card-header">
            <h3>Add New Cost</h3>
        </div>
        <div class="card-content">
            <form id="addCostForm" method="post" action="">
                <div class="form-group">
                    <label for="cost_type">Cost Type:</label>
                    <select id="cost_type" name="cost_type" required>
                        <option value="">Select Cost Type</option>
                        <?php foreach ($cost_types as $value => $label): ?>
                            <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="amount">Amount:</label>
                    <div class="amount-input-container">
                        <span class="currency-symbol">Rs.</span>
                        <input type="number" id="amount" name="amount" step="0.01" min="0.01" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="description">Description (Optional):</label>
                    <textarea id="description" name="description" rows="3" placeholder="Enter any additional details about this cost"></textarea>
                </div>

                <div class="form-actions">
                    <a href="view-batch.php?id=<?php echo htmlspecialchars($batch_id); ?>" class="button secondary">Cancel</a>
                    <button type="submit" class="button primary">Add Cost</button>
                </div>
            </form>
        </div>
    </div>

    <div class="cost-types-card">
        <div class="card-header">
            <h3>Cost Type Guide</h3>
        </div>
        <div class="card-content">
            <div class="cost-types-guide">
                <div class="cost-type-item">
                    <span class="cost-type cost-labor">Labor</span>
                    <p>Costs related to workers' wages, salaries, and other personnel expenses.</p>
                </div>
                <div class="cost-type-item">
                    <span class="cost-type cost-material">Material</span>
                    <p>Costs for raw materials not tracked in the system (misc fabrics, threads, etc.).</p>
                </div>
                <div class="cost-type-item">
                    <span class="cost-type cost-packaging">Packaging</span>
                    <p>Costs for boxes, bags, wrapping materials, and other packaging supplies.</p>
                </div>
                <div class="cost-type-item">
                    <span class="cost-type cost-zipper">Zipper</span>
                    <p>Costs specifically for zippers used in the production.</p>
                </div>
                <div class="cost-type-item">
                    <span class="cost-type cost-sticker">Sticker</span>
                    <p>Costs for stickers, labels, and other adhesive markings.</p>
                </div>
                <div class="cost-type-item">
                    <span class="cost-type cost-logo">Logo</span>
                    <p>Costs for logo printing, embroidery, or application.</p>
                </div>
                <div class="cost-type-item">
                    <span class="cost-type cost-tag">Tag</span>
                    <p>Costs for hang tags, care labels, and other product tags.</p>
                </div>
                <div class="cost-type-item">
                    <span class="cost-type cost-misc">Miscellaneous</span>
                    <p>Other costs that don't fit into the categories above.</p>
                </div>
                <div class="cost-type-item">
                    <span class="cost-type cost-overhead">Overhead</span>
                    <p>Indirect costs not directly tied to production, like rent or utilities.</p>
                </div>
                <div class="cost-type-item">
                    <span class="cost-type cost-electricity">Electricity</span>
                    <p>Costs related to electricity consumption in the manufacturing process.</p>
                </div>
                <div class="cost-type-item">
                    <span class="cost-type cost-maintenance">Maintenance</span>
                    <p>Costs for maintaining machinery and facilities.</p>
                </div>
                <div class="cost-type-item">
                    <span class="cost-type cost-other">Other</span>
                    <p>Any other manufacturing costs not covered by the defined categories.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* ... (Existing CSS code remains unchanged) ... */
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Close alert buttons (now handling session messages)
    const alertCloseButtons = document.querySelectorAll('.alert-close');
    alertCloseButtons.forEach(button => {
        button.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });

    // Form validation and submission handling
    const addCostForm = document.getElementById('addCostForm');
    if (addCostForm) {
        addCostForm.addEventListener('submit', function(event) {
            let isValid = true;

            // Clear previous validation errors
            document.querySelectorAll('.invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('.validation-error').forEach(el => el.remove());

            // Validate cost type
            const costType = document.getElementById('cost_type');
            if (!costType.value) {
                showValidationError(costType, 'Please select a cost type.');
                isValid = false;
            }

            // Validate amount
            const amount = document.getElementById('amount');
            if (!amount.value || parseFloat(amount.value) <= 0) {
                showValidationError(amount, 'Please enter a valid amount greater than zero.');
                isValid = false;
            }

            if (!isValid) {
                event.preventDefault(); // Prevent form submission if validation fails
            } else {
                // Show loading state on button
                const submitButton = this.querySelector('button[type="submit"]');
                submitButton.disabled = true;
                submitButton.innerHTML = '<span class="spinner"></span> Adding...';

                // No client-side activity logging here, as server-side will handle it on redirect
            }
        });
    }

    // Helper function to show validation errors (can be moved to utils.js later)
    function showValidationError(element, message) {
        // Add error class to element
        element.classList.add('invalid-input');

        // Create and append error message
        const errorElement = document.createElement('div');
        errorElement.className = 'validation-error';
        errorElement.textContent = message;

        // Handle the special case of amount input with currency symbol
        if (element.id === 'amount') {
            element.parentElement.parentElement.appendChild(errorElement);
        } else {
            element.parentElement.appendChild(errorElement);
        }

        // Focus the element
        element.focus();
    }

    // Remove validation error when input changes
    const formInputs = document.querySelectorAll('#addCostForm input, #addCostForm select, #addCostForm textarea');
    formInputs.forEach(input => {
        input.addEventListener('input', function() {
            this.classList.remove('invalid-input');

            // Find and remove any validation error
            let errorElement;
            if (this.id === 'amount') {
                errorElement = this.parentElement.parentElement.querySelector('.validation-error');
            } else {
                errorElement = this.parentElement.querySelector('.validation-error');
            }

            if (errorElement) {
                errorElement.remove();
            }
        });
    });

    <?php if (isset($_SESSION['success_message']) && !empty($_SESSION['success_message'])): ?>
    // This script block will only run if there was a success message on page load (after redirect)
    // You might want to remove this specific highlight or integrate it with a general toast system later.
    const costRows = document.querySelectorAll('.cost-summary-table tbody tr:not(.total-row)');
    if (costRows.length > 0) {
        // Assuming the latest added cost would be at the top or needs specific identification.
        // For simplicity, this example highlights the first non-total row.
        // In a real application, you might pass the ID of the newly added cost
        // via session and find it to highlight specifically.
        costRows[0].classList.add('highlight-row');
    }
    <?php endif; ?>
});
</script>

<?php include_once '../includes/footer.php'; ?>