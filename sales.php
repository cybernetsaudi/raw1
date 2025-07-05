<?php
// File: sales.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Include header.
$page_title = "Sales";
include_once '../includes/header.php';

// Check user authentication and role (e.g., 'shopkeeper' or 'owner' can view/manage sales).
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'shopkeeper' && $_SESSION['role'] !== 'owner')) {
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to view sales.";
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
$filter_invoice_number = isset($_GET['invoice']) ? trim($_GET['invoice']) : '';
$filter_customer_id = isset($_GET['customer_id']) && is_numeric($_GET['customer_id']) ? intval($_GET['customer_id']) : null;
$filter_payment_status = isset($_GET['payment_status']) ? trim($_GET['payment_status']) : '';
$filter_start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$filter_end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

$where_clauses = [];
$params = [];

if (!empty($filter_invoice_number)) {
    $where_clauses[] = "s.invoice_number LIKE ?";
    $params[] = '%' . $filter_invoice_number . '%';
}
if ($filter_customer_id) {
    $where_clauses[] = "s.customer_id = ?";
    $params[] = $filter_customer_id;
}
if (!empty($filter_payment_status)) {
    $where_clauses[] = "s.payment_status = ?";
    $params[] = $filter_payment_status;
}
if (!empty($filter_start_date)) {
    $where_clauses[] = "s.sale_date >= ?";
    $params[] = $filter_start_date;
}
if (!empty($filter_end_date)) {
    $where_clauses[] = "s.sale_date <= ?";
    $params[] = $filter_end_date;
}

// If user is shopkeeper, only show sales they created.
if ($_SESSION['role'] === 'shopkeeper') {
    $where_clauses[] = "s.created_by = ?";
    $params[] = $_SESSION['user_id'];
}

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

try {
    // Total records for pagination.
    $count_query = "SELECT COUNT(*)
                    FROM sales s
                    JOIN customers c ON s.customer_id = c.id
                    " . $where_sql;
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    // Fetch sales.
    $query = "SELECT s.*, c.name as customer_name, c.phone as customer_phone,
                     u.full_name as created_by_name
              FROM sales s
              JOIN customers c ON s.customer_id = c.id
              LEFT JOIN users u ON s.created_by = u.id
              " . $where_sql . "
              ORDER BY s.sale_date DESC, s.id DESC
              LIMIT ? OFFSET ?";
    $stmt = $db->prepare($query);
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch customers for filter dropdown.
    $customers_filter_query = "SELECT id, name FROM customers ORDER BY name ASC";
    // If shopkeeper, only show their customers who have sales.
    if ($_SESSION['role'] === 'shopkeeper') {
        $customers_filter_query = "SELECT DISTINCT c.id, c.name FROM customers c JOIN sales s ON c.id = s.customer_id WHERE s.created_by = ? ORDER BY c.name ASC";
        $customers_filter_stmt = $db->prepare($customers_filter_query);
        $customers_filter_stmt->execute([$_SESSION['user_id']]);
        $customers_filter = $customers_filter_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $customers_filter_stmt = $db->query($customers_filter_query);
        $customers_filter = $customers_filter_stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    // Log viewing the page.
    $auth->logActivity(
        $_SESSION['user_id'],
        'read',
        'sales',
        'Viewed sales list page' . (!empty($where_sql) ? ' with filters' : ''),
        null
    );

} catch (PDOException $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'sales',
        'Database error fetching sales: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = "A database error occurred while fetching sales. Please try again later.";
    $sales = [];
    $total_pages = 0;
} catch (Exception $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'sales',
        'Error fetching sales: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = $e->getMessage();
    $sales = [];
    $total_pages = 0;
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt; <span>Sales</span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>Sales Records</h2>
    </div>
    <div class="page-actions">
        <?php if ($_SESSION['role'] === 'shopkeeper'): ?>
            <a href="add-sale.php" class="button primary">
                <i class="fas fa-plus"></i> Add New Sale
            </a>
        <?php endif; ?>
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
            <h3>Filter Sales</h3>
        </div>
        <div class="card-content">
            <form method="GET" action="sales.php">
                <div class="form-row">
                    <div class="form-group col-md-3">
                        <label for="invoice">Invoice Number:</label>
                        <input type="text" id="invoice" name="invoice" class="form-control" value="<?php echo htmlspecialchars($filter_invoice_number); ?>" placeholder="Search Invoice No.">
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
                        <label for="payment_status">Payment Status:</label>
                        <select id="payment_status" name="payment_status" class="form-control">
                            <option value="">All Statuses</option>
                            <option value="unpaid" <?php echo $filter_payment_status == 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                            <option value="partial" <?php echo $filter_payment_status == 'partial' ? 'selected' : ''; ?>>Partial</option>
                            <option value="paid" <?php echo $filter_payment_status == 'paid' ? 'selected' : ''; ?>>Paid</option>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <label for="start_date">Start Date:</label>
                        <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($filter_start_date); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-3">
                        <label for="end_date">End Date:</label>
                        <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($filter_end_date); ?>">
                    </div>
                    <div class="form-group col-md-9 d-flex align-items-end">
                        <button type="submit" class="button primary">Apply Filters</button>
                        <a href="sales.php" class="button secondary ml-2">Clear Filters</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h3>Sales Records (Total: <?php echo $total_records; ?>)</h3>
        </div>
        <div class="card-content">
            <div class="table-responsive">
                <table class="table table-hover sortable-table">
                    <thead>
                        <tr>
                            <th class="sortable">ID</th>
                            <th class="sortable">Invoice No.</th>
                            <th class="sortable">Customer</th>
                            <th class="sortable">Sale Date</th>
                            <th class="sortable">Total Amount</th>
                            <th class="sortable">Net Amount</th>
                            <th class="sortable">Payment Status</th>
                            <th class="sortable">Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($sales) > 0): ?>
                            <?php foreach ($sales as $sale): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($sale['id']); ?></td>
                                    <td><a href="view-sale.php?id=<?php echo htmlspecialchars($sale['id']); ?>"><?php echo htmlspecialchars($sale['invoice_number']); ?></a></td>
                                    <td><?php echo htmlspecialchars($sale['customer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($sale['sale_date']); ?></td>
                                    <td>Rs. <?php echo number_format($sale['total_amount'], 2); ?></td>
                                    <td>Rs. <?php echo number_format($sale['net_amount'], 2); ?></td>
                                    <td><span class="status-badge status-<?php echo htmlspecialchars($sale['payment_status']); ?>"><?php echo ucfirst(htmlspecialchars($sale['payment_status'])); ?></span></td>
                                    <td><?php echo htmlspecialchars($sale['created_by_name'] ?: 'N/A'); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view-sale.php?id=<?php echo htmlspecialchars($sale['id']); ?>" class="button button-sm button-info" title="View Sale Details">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <?php if ($sale['payment_status'] !== 'paid' && $_SESSION['role'] === 'shopkeeper'): ?>
                                                <a href="add-payment.php?sale_id=<?php echo htmlspecialchars($sale['id']); ?>" class="button button-sm button-success" title="Add Payment">
                                                    <i class="fas fa-money-bill-wave"></i> Add Payment
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($_SESSION['role'] === 'shopkeeper' && $sale['created_by'] === $_SESSION['user_id'] || $_SESSION['role'] === 'owner'): ?>
                                                <a href="add-sale.php?id=<?php echo htmlspecialchars($sale['id']); ?>" class="button button-sm button-primary" title="Edit Sale">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <button type="button" class="button button-sm button-danger delete-sale-btn"
                                                        data-id="<?php echo htmlspecialchars($sale['id']); ?>"
                                                        data-invoice="<?php echo htmlspecialchars($sale['invoice_number']); ?>"
                                                        title="Delete Sale">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center">No sales records found.</td>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Close alert buttons (existing logic)
    const alertCloseButtons = document.querySelectorAll('.alert-close');
    alertCloseButtons.forEach(button => {
        button.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });

    // --- Delete Sale Logic ---
    const deleteSaleBtns = document.querySelectorAll('.delete-sale-btn');
    deleteSaleBtns.forEach(button => {
        button.addEventListener('click', function() {
            const saleId = this.dataset.id;
            const invoiceNumber = this.dataset.invoice;

            if (confirm(`Are you sure you want to delete Sale #${invoiceNumber} (ID: ${saleId})? This will revert inventory. This action is irreversible if payments exist.`)) {
                // Show loading state
                button.disabled = true;
                button.innerHTML = '<span class="spinner"></span>'; // Or specific icon

                fetch('../api/delete-sale.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `sale_id=${saleId}`
                })
                .then(response => response.json())
                .then(data => {
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-trash"></i> Delete'; // Reset button text

                    if (typeof showToast === 'function') { // Use showToast for notifications
                        showToast(data.success ? 'success' : 'error', data.message);
                    } else {
                        alert(data.message); // Fallback
                    }
                    if (data.success) {
                        window.location.reload(); // Reload page to reflect changes
                    }
                })
                .catch(error => {
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-trash"></i> Delete';
                    console.error('Error deleting sale:', error);
                    if (typeof showToast === 'function') {
                        showToast('error', 'An unexpected error occurred while deleting the sale.');
                    } else {
                        alert('An unexpected error occurred.');
                    }
                });
            }
        });
    });
});
</script>

<?php include_once '../includes/footer.php'; ?>