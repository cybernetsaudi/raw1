<?php
// File: add-payment.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class
include_once '../includes/header.php'; // Include header before any HTML output

// Check user authentication and role (e.g., only 'shopkeeper' can add payments).
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'shopkeeper') {
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to add payments.";
    header('Location: dashboard.php'); // Redirect to dashboard or login
    exit;
}

$page_title = "Add Payment";

// Initialize database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

$sale_id = isset($_GET['sale_id']) ? intval($_GET['sale_id']) : null;
$sale_details = null;
$amount_due = 0;
$total_amount_paid = 0;

try {
    if (!$sale_id) {
        throw new Exception("Missing or invalid Sale ID.");
    }

    // Fetch sale details and current paid amount.
    $sale_query = "SELECT s.id, s.invoice_number, s.total_amount, s.payment_status, s.payment_due_date, c.name AS customer_name
                   FROM sales s
                   JOIN customers c ON s.customer_id = c.id
                   WHERE s.id = ? AND s.created_by = ?"; // Ensure shopkeeper can only access their sales
    $sale_stmt = $db->prepare($sale_query);
    $sale_stmt->execute([$sale_id, $_SESSION['user_id']]);
    $sale_details = $sale_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sale_details) {
        throw new Exception("Sale not found or you do not have permission to view it.");
    }

    // Calculate total amount already paid for this sale.
    $paid_amount_query = "SELECT SUM(amount) FROM payments WHERE sale_id = ?";
    $paid_amount_stmt = $db->prepare($paid_amount_query);
    $paid_amount_stmt->execute([$sale_id]);
    $total_amount_paid = $paid_amount_stmt->fetchColumn() ?: 0;

    $amount_due = $sale_details['total_amount'] - $total_amount_paid;
    if ($amount_due < 0) $amount_due = 0; // Prevent negative due amount display

    // If sale is already fully paid, prevent adding more payments.
    if ($sale_details['payment_status'] === 'paid' && $amount_due <= 0.01) {
        $_SESSION['info_message'] = "This sale (Invoice #" . htmlspecialchars($sale_details['invoice_number']) . ") is already fully paid.";
        header('Location: view-sale.php?id=' . $sale_id);
        exit;
    }

    // Log viewing the page (optional, but good for tracking access to sensitive forms)
    $auth->logActivity(
        $_SESSION['user_id'],
        'read',
        'payments',
        'Accessed Add Payment page for Sale #' . htmlspecialchars($sale_details['invoice_number']),
        $sale_id
    );

} catch (Exception $e) {
    // Log the error.
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'payments',
        'Error loading Add Payment page: ' . $e->getMessage(),
        $sale_id ?? null
    );
    $_SESSION['error_message'] = $e->getMessage();
    header('Location: sales.php'); // Redirect if sale not found or invalid ID
    exit;
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt;
    <a href="sales.php">Sales</a> &gt;
    <a href="view-sale.php?id=<?php echo htmlspecialchars($sale_id); ?>">Sale <?php echo htmlspecialchars($sale_details['invoice_number']); ?></a> &gt;
    <span>Add Payment</span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>Add Payment for Sale #<?php echo htmlspecialchars($sale_details['invoice_number']); ?></h2>
    </div>
    <div class="page-actions">
        <a href="view-sale.php?id=<?php echo htmlspecialchars($sale_id); ?>" class="button secondary">
            <i class="fas fa-arrow-left"></i> Back to Sale
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
if (isset($_SESSION['info_message'])) {
    echo '<div class="alert alert-info"><i class="fas fa-info-circle"></i><span>' . htmlspecialchars($_SESSION['info_message']) . '</span><span class="alert-close">&times;</span></div>';
    unset($_SESSION['info_message']);
}
?>

<div class="container-fluid">
    <div class="card add-payment-card">
        <div class="card-header">
            <h3>Payment Details</h3>
        </div>
        <div class="card-content">
            <div class="sale-summary">
                <p><strong>Customer:</strong> <?php echo htmlspecialchars($sale_details['customer_name']); ?></p>
                <p><strong>Total Sale Amount:</strong> Rs. <?php echo number_format($sale_details['total_amount'], 2); ?></p>
                <p><strong>Amount Paid:</strong> Rs. <?php echo number_format($total_amount_paid, 2); ?></p>
                <p><strong>Amount Due:</strong> <span class="amount-due">Rs. <?php echo number_format($amount_due, 2); ?></span></p>
                <p><strong>Payment Status:</strong> <span class="status-badge status-<?php echo htmlspecialchars($sale_details['payment_status']); ?>"><?php echo ucfirst(htmlspecialchars($sale_details['payment_status'])); ?></span></p>
                <?php if ($sale_details['payment_due_date']): ?>
                    <p><strong>Payment Due Date:</strong> <?php echo htmlspecialchars($sale_details['payment_due_date']); ?></p>
                <?php endif; ?>
            </div>

            <form id="addPaymentForm" method="post" action="../api/save-payment.php">
                <input type="hidden" name="sale_id" value="<?php echo htmlspecialchars($sale_id); ?>">

                <div class="form-group">
                    <label for="amount">Amount:</label>
                    <div class="amount-input-container">
                        <span class="currency-symbol">Rs.</span>
                        <input type="number" id="amount" name="amount" step="0.01" min="0.01" max="<?php echo number_format($amount_due, 2, '.', ''); ?>" required>
                    </div>
                    <small class="form-text text-muted">Max amount is remaining due: Rs. <?php echo number_format($amount_due, 2); ?></small>
                </div>

                <div class="form-group">
                    <label for="payment_date">Payment Date:</label>
                    <input type="date" id="payment_date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <div class="form-group">
                    <label for="payment_method">Payment Method:</label>
                    <select id="payment_method" name="payment_method" required>
                        <option value="">Select Method</option>
                        <option value="cash">Cash</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="check">Check</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <div class="form-group" id="referenceNumberGroup" style="display: none;">
                    <label for="reference_number">Reference Number (e.g., Check #, Transaction ID):</label>
                    <input type="text" id="reference_number" name="reference_number">
                </div>

                <div class="form-group">
                    <label for="notes">Notes (Optional):</label>
                    <textarea id="notes" name="notes" rows="3"></textarea>
                </div>

                <div class="form-actions">
                    <a href="view-sale.php?id=<?php echo htmlspecialchars($sale_id); ?>" class="button secondary">Cancel</a>
                    <button type="submit" class="button primary">Record Payment</button>
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

    // Toggle visibility of Reference Number field based on Payment Method
    const paymentMethodSelect = document.getElementById('payment_method');
    const referenceNumberGroup = document.getElementById('referenceNumberGroup');
    const referenceNumberInput = document.getElementById('reference_number');

    function updateReferenceFieldVisibility() {
        const selectedMethod = paymentMethodSelect.value;
        if (selectedMethod === 'bank_transfer' || selectedMethod === 'check') {
            referenceNumberGroup.style.display = 'block';
            referenceNumberInput.setAttribute('required', 'required'); // Make required for these methods
        } else {
            referenceNumberGroup.style.display = 'none';
            referenceNumberInput.removeAttribute('required');
            referenceNumberInput.value = ''; // Clear value if hidden
        }
    }

    paymentMethodSelect.addEventListener('change', updateReferenceFieldVisibility);
    updateReferenceFieldVisibility(); // Call on load to set initial state

    // Client-side form validation
    const addPaymentForm = document.getElementById('addPaymentForm');
    if (addPaymentForm) {
        addPaymentForm.addEventListener('submit', function(event) {
            let isValid = true;

            // Clear previous validation errors
            document.querySelectorAll('.invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('.validation-error').forEach(el => el.remove());

            // Validate amount
            const amountInput = document.getElementById('amount');
            const amountValue = parseFloat(amountInput.value);
            const maxAmount = parseFloat(amountInput.getAttribute('max'));

            if (!amountInput.value || isNaN(amountValue) || amountValue <= 0) {
                showValidationError(amountInput, 'Please enter a valid amount greater than zero.');
                isValid = false;
            } else if (amountValue > maxAmount + 0.01) { // Allow slight float tolerance
                showValidationError(amountInput, 'Amount cannot exceed the remaining due amount.');
                isValid = false;
            }

            // Validate payment date
            const paymentDateInput = document.getElementById('payment_date');
            if (!paymentDateInput.value) {
                showValidationError(paymentDateInput, 'Payment date is required.');
                isValid = false;
            }

            // Validate payment method
            if (!paymentMethodSelect.value) {
                showValidationError(paymentMethodSelect, 'Please select a payment method.');
                isValid = false;
            }

            // Validate reference number if required
            if (referenceNumberInput.hasAttribute('required') && !referenceNumberInput.value.trim()) {
                showValidationError(referenceNumberInput, 'Reference number is required for the selected method.');
                isValid = false;
            }

            if (!isValid) {
                event.preventDefault(); // Stop form submission
            } else {
                // Show loading state on button
                const submitButton = this.querySelector('button[type="submit"]');
                submitButton.disabled = true;
                submitButton.innerHTML = '<span class="spinner"></span> Recording...'; // Add a spinner/loading text

                // Since this form posts to ../api/save-payment.php (an API endpoint),
                // the AJAX call or fetch operation should be handled here.
                // The current setup uses a traditional form submit, which will be caught by the server.
                // For a visually beautiful and robust system, we would typically use fetch API here
                // and handle success/error with toast messages from utils.js
                // For now, keeping the traditional form submit as it's the existing pattern.
            }
        });
    }

    // Helper functions for validation (can be moved to utils.js later)
    function showValidationError(element, message) {
        // Remove any existing error message
        const existingError = element.parentElement.querySelector('.validation-error');
        if (existingError) {
            existingError.remove();
        }

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

    function removeValidationError(element) {
        element.classList.remove('invalid-input');
        let errorElement;
        if (element.id === 'amount') {
            errorElement = element.parentElement.parentElement.querySelector('.validation-error');
        } else {
            errorElement = element.parentElement.querySelector('.validation-error');
        }
        if (errorElement) {
            errorElement.remove();
        }
    }

    // Remove validation error when input changes
    const formInputs = document.querySelectorAll('#addPaymentForm input, #addPaymentForm select, #addPaymentForm textarea');
    formInputs.forEach(input => {
        input.addEventListener('input', function() {
            removeValidationError(this);
        });
    });

    // Basic spinner CSS (add to your components.css or style.css)
    /*
    .spinner {
        border: 2px solid #f3f3f3;
        border-top: 2px solid #3498db;
        border-radius: 50%;
        width: 1em;
        height: 1em;
        animation: spin 1s linear infinite;
        display: inline-block;
        vertical-align: middle;
        margin-right: 0.5em;
    }
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    */
});
</script>

<?php include_once '../includes/footer.php'; ?>