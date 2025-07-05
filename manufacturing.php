<?php
// File: manufacturing.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Include header.
$page_title = "Manufacturing Batches";
include_once '../includes/header.php';

// Check user authentication and role.
// Only 'owner' or 'incharge' should manage manufacturing.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'incharge')) {
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to view manufacturing batches.";
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
$filter_product_id = isset($_GET['product_id']) && is_numeric($_GET['product_id']) ? intval($_GET['product_id']) : null;
$filter_batch_number = isset($_GET['batch_number']) ? trim($_GET['batch_number']) : '';
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$filter_start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$filter_end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

$where_clauses = [];
$params = [];

// Exclude 'completed' status by default unless specifically filtered for it.
if (empty($filter_status) || $filter_status !== 'completed') {
    $where_clauses[] = "mb.status != 'completed'";
}

if ($filter_product_id) {
    $where_clauses[] = "mb.product_id = ?";
    $params[] = $filter_product_id;
}
if (!empty($filter_batch_number)) {
    $where_clauses[] = "mb.batch_number LIKE ?";
    $params[] = '%' . $filter_batch_number . '%';
}
if (!empty($filter_status) && in_array($filter_status, ['pending','cutting','stitching','ironing','packaging','completed'])) {
    // If 'completed' is specifically selected, remove the 'not completed' filter.
    if ($filter_status === 'completed') {
        $where_clauses = array_filter($where_clauses, function($clause) {
            return $clause !== "mb.status != 'completed'";
        });
    }
    $where_clauses[] = "mb.status = ?";
    $params[] = $filter_status;
}
if (!empty($filter_start_date)) {
    $where_clauses[] = "mb.start_date >= ?";
    $params[] = $filter_start_date;
}
if (!empty($filter_end_date)) {
    $where_clauses[] = "mb.completion_date <= ?"; // Assuming completion_date for end date filter
    $params[] = $filter_end_date;
}

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

try {
    // Total records for pagination.
    $count_query = "SELECT COUNT(*) FROM manufacturing_batches mb " . $where_sql;
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    // Fetch manufacturing batches.
    $query = "SELECT mb.*, p.name as product_name, p.sku as product_sku,
                     u.full_name as created_by_name,
                     u_status.full_name as status_changed_by_name
              FROM manufacturing_batches mb
              JOIN products p ON mb.product_id = p.id
              JOIN users u ON mb.created_by = u.id
              LEFT JOIN users u_status ON mb.status_changed_by = u_status.id
              " . $where_sql . "
              ORDER BY mb.created_at DESC
              LIMIT ? OFFSET ?";
    $stmt = $db->prepare($query);
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch products for filter dropdown.
    $products_filter_query = "SELECT id, name, sku FROM products ORDER BY name ASC";
    $products_filter_stmt = $db->query($products_filter_query);
    $products_filter = $products_filter_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Log viewing the page.
    $auth->logActivity(
        $_SESSION['user_id'],
        'read',
        'manufacturing',
        'Viewed manufacturing batches list' . (!empty($where_sql) ? ' with filters' : ''),
        null
    );

} catch (PDOException $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'manufacturing',
        'Database error fetching manufacturing batches: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = "A database error occurred while fetching manufacturing batches. Please try again later.";
    $batches = [];
    $total_pages = 0;
} catch (Exception $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'manufacturing',
        'Error fetching manufacturing batches: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = $e->getMessage();
    $batches = [];
    $total_pages = 0;
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt; <span>Manufacturing</span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>Manufacturing Batches</h2>
    </div>
    <div class="page-actions">
        <a href="add-batch.php" class="button primary">
            <i class="fas fa-plus"></i> Add New Batch
        </a>
        <a href="completed-batches.php" class="button secondary">
            <i class="fas fa-check-double"></i> View Completed Batches
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
            <h3>Filter Batches</h3>
        </div>
        <div class="card-content">
            <form method="GET" action="manufacturing.php">
                <div class="form-row">
                    <div class="form-group col-md-3">
                        <label for="product_id">Product:</label>
                        <select id="product_id" name="product_id" class="form-control">
                            <option value="">All Products</option>
                            <?php foreach ($products_filter as $product): ?>
                                <option value="<?php echo htmlspecialchars($product['id']); ?>" <?php echo $filter_product_id == $product['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($product['name'] . ' (' . $product['sku'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <label for="batch_number">Batch Number:</label>
                        <input type="text" id="batch_number" name="batch_number" class="form-control" value="<?php echo htmlspecialchars($filter_batch_number); ?>" placeholder="Search by Batch Number">
                    </div>
                    <div class="form-group col-md-3">
                        <label for="status">Status:</label>
                        <select id="status" name="status" class="form-control">
                            <option value="">All Active</option>
                            <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="cutting" <?php echo $filter_status == 'cutting' ? 'selected' : ''; ?>>Cutting</option>
                            <option value="stitching" <?php echo $filter_status == 'stitching' ? 'selected' : ''; ?>>Stitching</option>
                            <option value="ironing" <?php echo $filter_status == 'ironing' ? 'selected' : ''; ?>>Ironing</option>
                            <option value="packaging" <?php echo $filter_status == 'packaging' ? 'selected' : ''; ?>>Packaging</option>
                            <option value="completed" <?php echo $filter_status == 'completed' ? 'selected' : ''; ?>>Completed</option>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <label for="start_date">Start Date:</label>
                        <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($filter_start_date); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-3">
                        <label for="end_date">Expected Completion Date By:</label>
                        <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($filter_end_date); ?>">
                    </div>
                    <div class="form-group col-md-9 d-flex align-items-end">
                        <button type="submit" class="button primary">Apply Filters</button>
                        <a href="manufacturing.php" class="button secondary ml-2">Clear Filters</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h3>Manufacturing Batch Records (Total: <?php echo $total_records; ?>)</h3>
        </div>
        <div class="card-content">
            <div class="table-responsive">
                <table class="table table-hover sortable-table">
                    <thead>
                        <tr>
                            <th class="sortable">ID</th>
                            <th class="sortable">Batch Number</th>
                            <th class="sortable">Product</th>
                            <th class="sortable">Quantity</th>
                            <th class="sortable">Status</th>
                            <th class="sortable">Started On</th>
                            <th class="sortable">Expected Completion</th>
                            <th class="sortable">Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($batches) > 0): ?>
                            <?php foreach ($batches as $batch): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($batch['id']); ?></td>
                                    <td><?php echo htmlspecialchars($batch['batch_number']); ?></td>
                                    <td><?php echo htmlspecialchars($batch['product_name']); ?> (<?php echo htmlspecialchars($batch['product_sku']); ?>)</td>
                                    <td><?php echo number_format($batch['quantity_produced']); ?></td>
                                    <td><span class="status-badge status-<?php echo htmlspecialchars($batch['status']); ?>"><?php echo ucfirst(htmlspecialchars($batch['status'])); ?></span></td>
                                    <td><?php echo htmlspecialchars($batch['start_date']); ?></td>
                                    <td><?php echo htmlspecialchars($batch['expected_completion_date']); ?></td>
                                    <td><?php echo htmlspecialchars($batch['created_by_name']); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view-batch.php?id=<?php echo htmlspecialchars($batch['id']); ?>" class="button button-sm button-info" title="View Batch Details">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <a href="add-cost.php?batch_id=<?php echo htmlspecialchars($batch['id']); ?>" class="button button-sm button-warning" title="Add Costs">
                                                <i class="fas fa-dollar-sign"></i> Add Cost
                                            </a>
                                            </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center">No manufacturing batches found.</td>
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

<?php include_once '../includes/footer.php'; ?>