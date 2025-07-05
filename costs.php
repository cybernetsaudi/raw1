<?php
// File: costs.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Include header (which usually handles authentication checks).
$page_title = "Manufacturing Costs";
include_once '../includes/header.php';

// Check user authentication and role (e.g., 'owner' or 'incharge' can view costs).
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'incharge')) {
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to view manufacturing costs.";
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
$filter_batch_number = isset($_GET['batch_number']) ? trim($_GET['batch_number']) : '';
$filter_cost_type = isset($_GET['cost_type']) ? trim($_GET['cost_type']) : '';
$filter_start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$filter_end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

$where_clauses = [];
$params = [];

if (!empty($filter_batch_number)) {
    $where_clauses[] = "mb.batch_number LIKE ?";
    $params[] = '%' . $filter_batch_number . '%';
}
if (!empty($filter_cost_type)) {
    $where_clauses[] = "mc.cost_type = ?";
    $params[] = $filter_cost_type;
}
if (!empty($filter_start_date)) {
    $where_clauses[] = "mc.recorded_date >= ?";
    $params[] = $filter_start_date;
}
if (!empty($filter_end_date)) {
    $where_clauses[] = "mc.recorded_date <= ?";
    $params[] = $filter_end_date;
}

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

try {
    // Total records for pagination.
    $count_query = "SELECT COUNT(*)
                    FROM manufacturing_costs mc
                    JOIN manufacturing_batches mb ON mc.batch_id = mb.id
                    " . $where_sql;
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    // Fetch manufacturing costs.
    $query = "SELECT mc.*, mb.batch_number, mb.id as batch_full_id, u.full_name as recorded_by_name
              FROM manufacturing_costs mc
              JOIN manufacturing_batches mb ON mc.batch_id = mb.id
              LEFT JOIN users u ON mc.recorded_by = u.id
              " . $where_sql . "
              ORDER BY mc.recorded_date DESC, mc.id DESC
              LIMIT ? OFFSET ?";
    $stmt = $db->prepare($query);
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $costs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch distinct cost types for filter dropdown.
    $distinct_cost_types_query = "SELECT DISTINCT cost_type FROM manufacturing_costs ORDER BY cost_type ASC";
    $distinct_cost_types_stmt = $db->query($distinct_cost_types_query);
    $distinct_cost_types = $distinct_cost_types_stmt->fetchAll(PDO::FETCH_COLUMN);

    // Define cost types for display mapping (from add-cost.php)
    $cost_types_display = [
        'labor' => 'Labor', 'material' => 'Material', 'packaging' => 'Packaging', 'zipper' => 'Zipper',
        'sticker' => 'Sticker', 'logo' => 'Logo', 'tag' => 'Tag', 'misc' => 'Miscellaneous',
        'overhead' => 'Overhead', 'electricity' => 'Electricity', 'maintenance' => 'Maintenance', 'other' => 'Other'
    ];


    // Log viewing the page.
    $auth->logActivity(
        $_SESSION['user_id'],
        'read',
        'manufacturing_costs',
        'Viewed manufacturing costs page' . (!empty($where_sql) ? ' with filters' : ''),
        null
    );

} catch (PDOException $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'manufacturing_costs',
        'Database error fetching manufacturing costs: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = "A database error occurred while fetching manufacturing costs. Please try again later.";
    $costs = [];
    $total_pages = 0;
} catch (Exception $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'manufacturing_costs',
        'Error fetching manufacturing costs: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = $e->getMessage();
    $costs = [];
    $total_pages = 0;
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt; <span>Manufacturing Costs</span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>Manufacturing Costs Overview</h2>
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
            <h3>Filter Costs</h3>
        </div>
        <div class="card-content">
            <form method="GET" action="costs.php">
                <div class="form-row">
                    <div class="form-group col-md-3">
                        <label for="batch_number">Batch Number:</label>
                        <input type="text" id="batch_number" name="batch_number" class="form-control" value="<?php echo htmlspecialchars($filter_batch_number); ?>" placeholder="Search by Batch Number">
                    </div>
                    <div class="form-group col-md-3">
                        <label for="cost_type">Cost Type:</label>
                        <select id="cost_type" name="cost_type" class="form-control">
                            <option value="">All Types</option>
                            <?php foreach ($distinct_cost_types as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $filter_cost_type == $type ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $cost_types_display[$type] ?? $type))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <label for="start_date">Start Date:</label>
                        <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($filter_start_date); ?>">
                    </div>
                    <div class="form-group col-md-3">
                        <label for="end_date">End Date:</label>
                        <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($filter_end_date); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-12 d-flex align-items-end">
                        <button type="submit" class="button primary">Apply Filters</button>
                        <a href="costs.php" class="button secondary ml-2">Clear Filters</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h3>Manufacturing Cost Records (Total: <?php echo $total_records; ?>)</h3>
        </div>
        <div class="card-content">
            <div class="table-responsive">
                <table class="table table-hover sortable-table">
                    <thead>
                        <tr>
                            <th class="sortable">ID</th>
                            <th class="sortable">Batch Number</th>
                            <th class="sortable">Cost Type</th>
                            <th class="sortable">Amount</th>
                            <th class="sortable">Description</th>
                            <th class="sortable">Recorded By</th>
                            <th class="sortable">Recorded Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($costs) > 0): ?>
                            <?php foreach ($costs as $cost): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($cost['id']); ?></td>
                                    <td>
                                        <a href="view-batch.php?id=<?php echo htmlspecialchars($cost['batch_full_id']); ?>">
                                            <?php echo htmlspecialchars($cost['batch_number']); ?>
                                        </a>
                                    </td>
                                    <td><span class="cost-type cost-<?php echo htmlspecialchars($cost['cost_type']); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $cost_types_display[$cost['cost_type']] ?? $cost['cost_type']))); ?></span></td>
                                    <td>Rs. <?php echo number_format($cost['amount'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($cost['description'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($cost['recorded_by_name'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($cost['recorded_date']); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center">No manufacturing costs found.</td>
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