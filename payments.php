<?php
// File: payments.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Include header.
$page_title = "Payments";
include_once '../includes/header.php';

// Check user authentication and role (e.g., 'shopkeeper' or 'owner' can view/manage payments).
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'shopkeeper' && $_SESSION['role'] !== 'owner')) {
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to view payments.";
    header('Location: dashboard.php'); // Redirect to dashboard or login
    exit;
}

// Get database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

// Pagination settings.
$limit = 15;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Filters.
$filter_sale_id = isset($_GET['sale_id']) && is_numeric($_GET['sale_id']) ? intval($_GET['sale_id']) : null;
$filter_customer_id = isset($_GET['customer_id']) && is_numeric($_GET['customer_id']) ? intval($_GET['customer_id']) : null;
$filter_method = isset($_GET['method']) ? trim($_GET['method']) : '';
$filter_start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$filter_end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$search_ref_number = isset($_GET['search_ref']) ? trim($_GET['search_ref']) : '';

$where_clauses = [];
$params = [];

if ($filter_sale_id) {
    $where_clauses[] = "p.sale_id = ?";
    $params[] = $filter_sale_id;
}
if ($filter_customer_id) {
    $where_clauses[] = "s.customer_id = ?";
    $params[] = $filter_customer_id;
}
if (!empty($filter_method)) {
    $where_clauses[] = "p.payment_method = ?";
    $params[] = $filter_method;
}
if (!empty($filter_start_date)) {
    $where_clauses[] = "p.payment_date >= ?";
    $params[] = $filter_start_date;
}
if (!empty($filter_end_date)) {
    $where_clauses[] = "p.payment_date <= ?";
    $params[] = $filter_end_date;
}
if (!empty($search_ref_number)) {
    $where_clauses[] = "p.reference_number LIKE ?";
    $params[] = '%' . $search_ref_number . '%';
}

// If shopkeeper, only show payments for sales they created.
if ($_SESSION['role'] === 'shopkeeper') {
    $where_clauses[] = "s.created_by = ?";
    $params[] = $_SESSION['user_id'];
}

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

try {
    // Total records for pagination.
    $count_query = "SELECT COUNT(*)
                    FROM payments p
                    JOIN sales s ON p.sale_id = s.id
                    JOIN customers c ON s.customer_id = c.id
                    " . $where_sql;
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    // Fetch payments.
    $query = "SELECT p.*, s.invoice_number, s.total_amount AS sale_total_amount, s.payment_status AS sale_payment_status,
                     c.name as customer_name, u.full_name as recorded_by_name
              FROM payments p
              JOIN sales s ON p.sale_id = s.id
              JOIN customers c ON s.customer_id = c.id
              LEFT JOIN users u ON p.recorded_by = u.id
              " . $where_sql . "
              ORDER BY p.payment_date DESC, p.id DESC
              LIMIT ? OFFSET ?";
    $stmt = $db->prepare($query);
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch sales for filter dropdown (sales that have payments).
    $sales_filter_query = "SELECT DISTINCT s.id, s.invoice_number FROM sales s JOIN payments p ON s.id = p.sale_id ORDER BY s.invoice_number ASC";
    // If shopkeeper, only show their sales
    if ($_SESSION['role'] === 'shopkeeper') {
        $sales_filter_query .= " WHERE s.created_by = " . $_SESSION['user_id'];
    }
    $sales_filter_stmt = $db->query($sales_filter_query);
    $sales_filter = $sales_filter_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch customers for filter dropdown (customers with payments).
    $customers_filter_query = "SELECT DISTINCT c.id, c.name FROM customers c JOIN sales s ON c.id = s.customer_id JOIN payments p ON s.id = p.sale_id ORDER BY c.name ASC";
    // If shopkeeper, only show customers related to their sales
    if ($_SESSION['role'] === 'shopkeeper') {
        $customers_filter_query .= " WHERE s.created_by = " . $_SESSION['user_id'];
    }
    $customers_filter_stmt = $db->query($customers_filter_query);
    $customers_filter = $customers_filter_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Log viewing the page.
    $auth->logActivity(
        $_SESSION['user_id'],
        'read',
        'payments',
        'Viewed payments list page' . (!empty($where_sql) ? ' with filters' : ''),
        null
    );

} catch (PDOException $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'payments',
        'Database error fetching payments: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = "A database error occurred while fetching payments. Please try again later.";
    $payments = [];
    $total_pages = 0;
} catch (Exception $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'payments',
        'Error fetching payments: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = $e->getMessage();
    $payments = [];
    $total_pages = 0;
}

// Helper function for payment method color (can be moved to utils.js later)
function getMethodColor($method) {
    switch ($method) {
        case 'cash': return 'status-cash';
        case 'bank_transfer': return 'status-bank';
        case 'check': return 'status-check';
        case 'other': return 'status-other';
        default: return '';
    }
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt; <span>Payments</span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>Payments Received</h2>
    </div>
    <div class="page-actions">
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
    <div class="filter-card card">
        <div class="card-header">
            <h3>Filter Payments</h3>
        </div>
        <div class="card-content">
            <form method="GET" action="payments.php">
                <div class="form-row">
                    <div class="form-group col-md-3">
                        <label for="sale_id">Sale Invoice:</label>
                        <select id="sale_id" name="sale_id" class="form-control">
                            <option value="">All Sales</option>
                            <?php foreach ($sales_filter as $sale): ?>
                                <option value="<?php echo htmlspecialchars($sale['id']); ?>" <?php echo $filter_sale_id == $sale['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sale['invoice_number']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <label for="customer_id">Customer:</label>
                        <select id="customer_id" name="customer_id" class="form-control">
                            <option value="">All Customers</option>
                            <?php foreach ($customers_filter as $customer): ?>
                                <option value="<?php echo htmlspecialchars($customer['id']); ?>" <?php echo $filter_customer_id == $customer['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($customer['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <label for="method">Payment Method:</label>
                        <select id="method" name="method" class="form-control">
                            <option value="">All Methods</option>
                            <option value="cash" <?php echo $filter_method == 'cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="bank_transfer" <?php echo $filter_method == 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                            <option value="check" <?php echo $filter_method == 'check' ? 'selected' : ''; ?>>Check</option>
                            <option value="other" <?php echo $filter_method == 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <label for="search_ref">Reference Number:</label>
                        <input type="text" id="search_ref" name="search_ref" class="form-control" value="<?php echo htmlspecialchars($search_ref_number); ?>" placeholder="Search Reference No.">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-3">
                        <label for="start_date">Start Date:</label>
                        <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($filter_start_date); ?>">
                    </div>
                    <div class="form-group col-md-3">
                        <label for="end_date">End Date:</label>
                        <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($filter_end_date); ?>">
                    </div>
                    <div class="form-group col-md-6 d-flex align-items-end">
                        <button type="submit" class="button primary">Apply Filters</button>
                        <a href="payments.php" class="button secondary ml-2">Clear Filters</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h3>Payment Records (Total: <?php echo $total_records; ?>)</h3>
        </div>
        <div class="card-content">
            <div class="table-responsive">
                <table class="table table-hover sortable-table">
                    <thead>
                        <tr>
                            <th class="sortable">ID</th>
                            <th class="sortable">Sale Invoice</th>
                            <th class="sortable">Customer</th>
                            <th class="sortable">Amount</th>
                            <th class="sortable">Date</th>
                            <th class="sortable">Method</th>
                            <th class="sortable">Reference No.</th>
                            <th class="sortable">Recorded By</th>
                            <th>Notes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($payments) > 0): ?>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($payment['id']); ?></td>
                                    <td>
                                        <a href="view-sale.php?id=<?php echo htmlspecialchars($payment['sale_id']); ?>">
                                            <?php echo htmlspecialchars($payment['invoice_number']); ?>
                                        </a>
                                        <br><span class="status-badge status-<?php echo htmlspecialchars($payment['sale_payment_status']); ?>"><?php echo ucfirst(htmlspecialchars($payment['sale_payment_status'])); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($payment['customer_name']); ?></td>
                                    <td>Rs. <?php echo number_format($payment['amount'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                                    <td><span class="status-badge <?php echo getMethodColor($payment['payment_method']); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $payment['payment_method']))); ?></span></td>
                                    <td><?php echo htmlspecialchars($payment['reference_number'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($payment['recorded_by_name'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($payment['notes'] ?: 'N/A'); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($_SESSION['role'] === 'shopkeeper' && $payment['recorded_by'] === $_SESSION['user_id'] || $_SESSION['role'] === 'owner'): ?>
                                                <button type="button" class="button button-sm button-danger void-payment-btn"
                                                        data-id="<?php echo htmlspecialchars($payment['id']); ?>"
                                                        data-sale-invoice="<?php echo htmlspecialchars($payment['invoice_number']); ?>"
                                                        data-amount="<?php echo htmlspecialchars(number_format($payment['amount'], 2)); ?>"
                                                        title="Void Payment">
                                                    <i class="fas fa-ban"></i> Void
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center">No payments found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="voidPaymentModal" class="modal">
    <div class="modal-content">
        <span class="close-button">&times;</span>
        <h3 id="voidModalTitle">Void Payment</h3>
        <form id="voidPaymentForm" method="post" action="../api/void-payment.php">
            <input type="hidden" id="void_payment_id" name="payment_id">
            <p>Are you sure you want to void this payment for Sale: <strong id="voidSaleInvoice"></strong> (Amount: <strong id="voidAmount"></strong>)?</p>
            <p><strong>WARNING:</strong> Voiding this payment will revert the sale's payment status and remaining balance.</p>
            <div class="form-group">
                <label for="void_reason">Reason for Void (Required):</label>
                <textarea id="void_reason" name="reason" rows="4" required placeholder="e.g., Payment cancelled by customer, payment error."></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="button secondary close-button">Cancel</button>
                <button type="submit" class="button button-danger">Confirm Void</button>
            </div>
        </form>
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

    const voidPaymentModal = document.getElementById('voidPaymentModal');
    const voidPaymentBtns = document.querySelectorAll('.void-payment-btn');
    const voidPaymentCloseButtons = voidPaymentModal.querySelectorAll('.close-button');
    const voidPaymentForm = document.getElementById('voidPaymentForm');
    const voidSaleInvoiceDisplay = document.getElementById('voidSaleInvoice');
    const voidAmountDisplay = document.getElementById('voidAmount');
    const voidPaymentIdInput = document.getElementById('void_payment_id');
    const voidReasonInput = document.getElementById('void_reason');

    // Function to open void payment modal
    function openVoidPaymentModal() {
        voidPaymentModal.style.display = 'block';
        voidPaymentModal.classList.add('show-modal');
    }

    // Function to close void payment modal
    function closeVoidPaymentModal() {
        voidPaymentModal.classList.remove('show-modal');
        setTimeout(() => {
            voidPaymentModal.style.display = 'none';
            voidPaymentForm.reset();
            // Clear validation errors
            document.querySelectorAll('#voidPaymentForm .invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('#voidPaymentForm .validation-error').forEach(el => el.remove());
        }, 300);
    }

    // Attach click listeners to close buttons
    voidPaymentCloseButtons.forEach(btn => {
        btn.addEventListener('click', closeVoidPaymentModal);
    });

    // Close modal if clicking outside
    window.addEventListener('click', function(event) {
        if (event.target == voidPaymentModal) {
            closeVoidPaymentModal();
        }
    });

    // Open void payment modal
    voidPaymentBtns.forEach(button => {
        button.addEventListener('click', function() {
            voidPaymentIdInput.value = this.dataset.id;
            voidSaleInvoiceDisplay.textContent = this.dataset.saleInvoice;
            voidAmountDisplay.textContent = this.dataset.amount;
            voidReasonInput.value = ''; // Clear reason field
            openVoidPaymentModal();
        });
    });

    // Handle void payment form submission via AJAX
    if (voidPaymentForm) {
        voidPaymentForm.addEventListener('submit', function(event) {
            event.preventDefault();

            let isValid = true;
            // Clear previous validation errors
            document.querySelectorAll('#voidPaymentForm .invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('#voidPaymentForm .validation-error').forEach(el => el.remove());

            if (!voidReasonInput.value.trim()) {
                showValidationError(voidReasonInput, 'Reason for voiding is required.');
                isValid = false;
            }

            if (!isValid) return;

            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner"></span> Voiding...';

            const formData = new FormData(this);
            fetch(this.action, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                submitButton.disabled = false;
                submitButton.innerHTML = 'Confirm Void';

                if (typeof showToast === 'function') {
                    showToast(data.success ? 'success' : 'error', data.message);
                } else {
                    alert(data.message);
                }
                if (data.success) {
                    closeVoidPaymentModal();
                    window.location.reload(); // Reload page to reflect changes
                }
            })
            .catch(error => {
                submitButton.disabled = false;
                submitButton.innerHTML = 'Confirm Void';
                console.error('Error voiding payment:', error);
                if (typeof showToast === 'function') {
                    showToast('error', 'An unexpected error occurred while voiding the payment.');
                } else {
                    alert('An unexpected error occurred.');
                }
            });
        });
    }

    // Helper functions for validation (can be moved to utils.js later)
    function showValidationError(element, message) {
        const formGroup = element.closest('.form-group');
        const existingError = formGroup ? formGroup.querySelector('.validation-error') : null;
        if (existingError) { existingError.remove(); }

        element.classList.add('invalid-input');

        const errorElement = document.createElement('div');
        errorElement.className = 'validation-error';
        errorElement.textContent = message;

        if (formGroup) {
            formGroup.appendChild(errorElement);
        } else {
            element.parentElement.appendChild(errorElement);
        }
        element.focus();
    }

    // Remove validation error when input changes
    voidPaymentForm.querySelectorAll('input, select, textarea').forEach(input => {
        input.addEventListener('input', function() {
            this.classList.remove('invalid-input');
            const formGroup = this.closest('.form-group');
            const errorElement = formGroup ? formGroup.querySelector('.validation-error') : null;
            if (errorElement) { errorElement.remove(); }
        });
    });
});
</script>

<?php include_once '../includes/footer.php'; ?>