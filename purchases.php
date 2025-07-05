<?php
// File: purchases.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Include header.
$page_title = "Purchases";
include_once '../includes/header.php';

// Check user authentication and role (e.g., 'incharge' or 'owner' can view/manage purchases).
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'incharge' && $_SESSION['role'] !== 'owner')) {
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to view purchases.";
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
$filter_material_id = isset($_GET['material_id']) && is_numeric($_GET['material_id']) ? intval($_GET['material_id']) : null;
$filter_vendor_name = isset($_GET['vendor_name']) ? trim($_GET['vendor_name']) : '';
$filter_fund_id = isset($_GET['fund_id']) && is_numeric($_GET['fund_id']) ? intval($_GET['fund_id']) : null;
$filter_start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$filter_end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

$where_clauses = [];
$params = [];

if ($filter_material_id) {
    $where_clauses[] = "p.material_id = ?";
    $params[] = $filter_material_id;
}
if (!empty($filter_vendor_name)) {
    $where_clauses[] = "p.vendor_name LIKE ?";
    $params[] = '%' . $filter_vendor_name . '%';
}
if ($filter_fund_id) {
    $where_clauses[] = "p.fund_id = ?";
    $params[] = $filter_fund_id;
}
if (!empty($filter_start_date)) {
    $where_clauses[] = "p.purchase_date >= ?";
    $params[] = $filter_start_date;
}
if (!empty($filter_end_date)) {
    $where_clauses[] = "p.purchase_date <= ?";
    $params[] = $filter_end_date;
}

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

try {
    // Total records for pagination.
    $count_query = "SELECT COUNT(*)
                    FROM purchases p
                    JOIN raw_materials rm ON p.material_id = rm.id
                    " . $where_sql;
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    // Fetch purchases.
    $query = "SELECT p.*, rm.name as material_name, rm.unit as material_unit,
                     u.full_name as purchased_by_name,
                     f.description as fund_description, f.balance as fund_balance
              FROM purchases p
              JOIN raw_materials rm ON p.material_id = rm.id
              LEFT JOIN users u ON p.purchased_by = u.id
              LEFT JOIN funds f ON p.fund_id = f.id
              " . $where_sql . "
              ORDER BY p.purchase_date DESC, p.id DESC
              LIMIT ? OFFSET ?";
    $stmt = $db->prepare($query);
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch raw materials for filter dropdown.
    $materials_filter_query = "SELECT id, name, unit FROM raw_materials ORDER BY name ASC";
    $materials_filter_stmt = $db->query($materials_filter_query);
    $materials_filter = $materials_filter_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch funds for filter dropdown.
    $funds_filter_query = "SELECT id, description, balance FROM funds ORDER BY description ASC";
    $funds_filter_stmt = $db->query($funds_filter_query);
    $funds_filter = $funds_filter_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Log viewing the page.
    $auth->logActivity(
        $_SESSION['user_id'],
        'read',
        'purchases',
        'Viewed purchases list page' . (!empty($where_sql) ? ' with filters' : ''),
        null
    );

} catch (PDOException $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'purchases',
        'Database error fetching purchases: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = "A database error occurred while fetching purchases. Please try again later.";
    $purchases = [];
    $total_pages = 0;
} catch (Exception $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'purchases',
        'Error fetching purchases: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = $e->getMessage();
    $purchases = [];
    $total_pages = 0;
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt; <span>Purchases</span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>Raw Material Purchases</h2>
    </div>
    <div class="page-actions">
        <a href="add-purchase.php" class="button primary">
            <i class="fas fa-plus"></i> Add New Purchase
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
    <div class="filter-card card">
        <div class="card-header">
            <h3>Filter Purchases</h3>
        </div>
        <div class="card-content">
            <form method="GET" action="purchases.php">
                <div class="form-row">
                    <div class="form-group col-md-3">
                        <label for="material_id">Raw Material:</label>
                        <select id="material_id" name="material_id" class="form-control">
                            <option value="">All Materials</option>
                            <?php foreach ($materials_filter as $material): ?>
                                <option value="<?php echo htmlspecialchars($material['id']); ?>" <?php echo $filter_material_id == $material['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($material['name'] . ' (' . $material['unit'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <label for="vendor_name">Vendor Name:</label>
                        <input type="text" id="vendor_name" name="vendor_name" class="form-control" value="<?php echo htmlspecialchars($filter_vendor_name); ?>" placeholder="Search Vendor">
                    </div>
                    <div class="form-group col-md-3">
                        <label for="fund_id">Fund Used:</label>
                        <select id="fund_id" name="fund_id" class="form-control">
                            <option value="">All Funds</option>
                            <?php foreach ($funds_filter as $fund): ?>
                                <option value="<?php echo htmlspecialchars($fund['id']); ?>" <?php echo $filter_fund_id == $fund['id'] ? 'selected' : ''; ?>>
                                    Fund #<?php echo htmlspecialchars($fund['id']); ?> (<?php echo htmlspecialchars($fund['description']); ?>)
                                </option>
                            <?php endforeach; ?>
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
                        <a href="purchases.php" class="button secondary ml-2">Clear Filters</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h3>Purchase Records (Total: <?php echo $total_records; ?>)</h3>
        </div>
        <div class="card-content">
            <div class="table-responsive">
                <table class="table table-hover sortable-table">
                    <thead>
                        <tr>
                            <th class="sortable">ID</th>
                            <th class="sortable">Material</th>
                            <th class="sortable">Quantity</th>
                            <th class="sortable">Unit Price</th>
                            <th class="sortable">Total Amount</th>
                            <th class="sortable">Vendor</th>
                            <th class="sortable">Invoice No.</th>
                            <th class="sortable">Purchase Date</th>
                            <th class="sortable">Purchased By</th>
                            <th class="sortable">Fund Used</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($purchases) > 0): ?>
                            <?php foreach ($purchases as $purchase): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($purchase['id']); ?></td>
                                    <td><?php echo htmlspecialchars($purchase['material_name']); ?> (<?php echo htmlspecialchars($purchase['material_unit']); ?>)</td>
                                    <td><?php echo number_format($purchase['quantity'], 2); ?></td>
                                    <td>Rs. <?php echo number_format($purchase['unit_price'], 2); ?></td>
                                    <td>Rs. <?php echo number_format($purchase['total_amount'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($purchase['vendor_name'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($purchase['invoice_number'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($purchase['purchase_date']); ?></td>
                                    <td><?php echo htmlspecialchars($purchase['purchased_by_name'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($purchase['fund_id'] ? 'Fund #' . $purchase['fund_id'] : 'N/A'); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="add-purchase.php?id=<?php echo htmlspecialchars($purchase['id']); ?>" class="button button-sm button-primary" title="Edit Purchase">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <button type="button" class="button button-sm button-danger delete-purchase-btn"
                                                    data-id="<?php echo htmlspecialchars($purchase['id']); ?>"
                                                    data-invoice="<?php echo htmlspecialchars($purchase['invoice_number'] ?: 'N/A'); ?>"
                                                    title="Delete Purchase">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" class="text-center">No purchase records found.</td>
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

    // --- Delete Purchase Logic ---
    const deletePurchaseBtns = document.querySelectorAll('.delete-purchase-btn');
    deletePurchaseBtns.forEach(button => {
        button.addEventListener('click', function() {
            const purchaseId = this.dataset.id;
            const invoiceNumber = this.dataset.invoice;

            if (confirm(`Are you sure you want to delete Purchase #${purchaseId} (Invoice: ${invoiceNumber})? This will revert material stock and fund usage. This action is irreversible.`)) {
                // Show loading state
                button.disabled = true;
                button.innerHTML = '<span class="spinner"></span>'; // Or specific icon

                fetch('../api/delete-purchase.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `purchase_id=${purchaseId}`
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
                    console.error('Error deleting purchase:', error);
                    if (typeof showToast === 'function') {
                        showToast('error', 'An unexpected error occurred while deleting the purchase.');
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