<?php
// File: activity-logs.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Include header (which usually handles authentication checks).
$page_title = "Activity Logs";
include_once '../includes/header.php';

// Check user authentication and role (e.g., 'owner' or 'incharge' can view logs).
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'incharge')) {
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to view activity logs.";
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
$filter_user_id = isset($_GET['user_id']) && is_numeric($_GET['user_id']) ? intval($_GET['user_id']) : null;
$filter_action_type = isset($_GET['action_type']) ? trim($_GET['action_type']) : '';
$filter_module = isset($_GET['module']) ? trim($_GET['module']) : '';
$filter_start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$filter_end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$search_description = isset($_GET['search_description']) ? trim($_GET['search_description']) : '';

$where_clauses = [];
$params = [];

if ($filter_user_id) {
    $where_clauses[] = "al.user_id = ?";
    $params[] = $filter_user_id;
}
if (!empty($filter_action_type)) {
    $where_clauses[] = "al.action_type = ?";
    $params[] = $filter_action_type;
}
if (!empty($filter_module)) {
    $where_clauses[] = "al.module = ?";
    $params[] = $filter_module;
}
if (!empty($filter_start_date)) {
    $where_clauses[] = "al.created_at >= ?";
    $params[] = $filter_start_date . ' 00:00:00';
}
if (!empty($filter_end_date)) {
    $where_clauses[] = "al.created_at <= ?";
    $params[] = $filter_end_date . ' 23:59:59';
}
if (!empty($search_description)) {
    $where_clauses[] = "al.description LIKE ?";
    $params[] = '%' . $search_description . '%';
}

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

try {
    // Total records for pagination.
    $count_query = "SELECT COUNT(*) FROM activity_logs al " . $where_sql;
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    // Fetch activity logs.
    $query = "SELECT al.*, u.full_name as user_name, u.username
              FROM activity_logs al
              LEFT JOIN users u ON al.user_id = u.id
              " . $where_sql . "
              ORDER BY al.created_at DESC
              LIMIT ? OFFSET ?";
    $stmt = $db->prepare($query);
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all users for filter dropdown.
    $users_query = "SELECT id, full_name, username FROM users ORDER BY full_name ASC";
    $users_stmt = $db->query($users_query);
    $users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Log the activity (viewing logs).
    $auth->logActivity(
        $_SESSION['user_id'],
        'read',
        'activity_logs',
        'Viewed activity logs page' . (!empty($where_sql) ? ' with filters' : ''),
        null
    );

} catch (PDOException $e) {
    // Log the error using Auth class.
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'activity_logs',
        'Database error fetching activity logs: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = "A database error occurred while fetching activity logs. Please try again later.";
    $activities = []; // Ensure activities array is empty to prevent further errors
    $total_pages = 0;
} catch (Exception $e) {
    // Log other errors.
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'activity_logs',
        'Error fetching activity logs: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = $e->getMessage();
    $activities = []; // Ensure activities array is empty
    $total_pages = 0;
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt; <span>Activity Logs</span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>Activity Logs</h2>
    </div>
    <div class="page-actions">
        <a href="export-logs.php?<?php echo http_build_query($_GET); ?>" class="button primary">
            <i class="fas fa-download"></i> Export to CSV
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
            <h3>Filter Logs</h3>
        </div>
        <div class="card-content">
            <form method="GET" action="activity-logs.php">
                <div class="form-row">
                    <div class="form-group col-md-3">
                        <label for="user_id">User:</label>
                        <select id="user_id" name="user_id" class="form-control">
                            <option value="">All Users</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo htmlspecialchars($user['id']); ?>" <?php echo $filter_user_id == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['full_name'] . ' (' . $user['username'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <label for="action_type">Action Type:</label>
                        <select id="action_type" name="action_type" class="form-control">
                            <option value="">All Types</option>
                            <?php
                            // Fetch distinct action types from DB or define statically if known
                            $distinct_action_types_query = "SELECT DISTINCT action_type FROM activity_logs ORDER BY action_type ASC";
                            $distinct_action_types_stmt = $db->query($distinct_action_types_query);
                            $distinct_action_types = $distinct_action_types_stmt->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($distinct_action_types as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $filter_action_type == $type ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $type))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <label for="module">Module:</label>
                        <select id="module" name="module" class="form-control">
                            <option value="">All Modules</option>
                            <?php
                            // Fetch distinct modules from DB or define statically if known
                            $distinct_modules_query = "SELECT DISTINCT module FROM activity_logs ORDER BY module ASC";
                            $distinct_modules_stmt = $db->query($distinct_modules_query);
                            $distinct_modules = $distinct_modules_stmt->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($distinct_modules as $module): ?>
                                <option value="<?php echo htmlspecialchars($module); ?>" <?php echo $filter_module == $module ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $module))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <label for="search_description">Search Description:</label>
                        <input type="text" id="search_description" name="search_description" class="form-control" value="<?php echo htmlspecialchars($search_description); ?>" placeholder="Search text in description">
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
                    <div class="form-group col-md-3 d-flex align-items-end">
                        <button type="submit" class="button primary">Apply Filters</button>
                        <a href="activity-logs.php" class="button secondary ml-2">Clear Filters</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h3>Activity Log Records (Total: <?php echo $total_records; ?>)</h3>
        </div>
        <div class="card-content">
            <div class="table-responsive">
                <table class="table table-hover sortable-table">
                    <thead>
                        <tr>
                            <th class="sortable">ID</th>
                            <th class="sortable">User</th>
                            <th class="sortable">Action Type</th>
                            <th class="sortable">Module</th>
                            <th class="sortable">Description</th>
                            <th class="sortable">Entity ID</th>
                            <th class="sortable">IP Address</th>
                            <th class="sortable">User Agent</th>
                            <th class="sortable">Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($activities) > 0): ?>
                            <?php foreach ($activities as $activity): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($activity['id']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($activity['user_name'] ? $activity['user_name'] : 'System/N/A'); ?>
                                        <?php echo htmlspecialchars($activity['username'] ? ' (' . $activity['username'] . ')' : ''); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $activity['action_type']))); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $activity['module']))); ?></td>
                                    <td><?php echo htmlspecialchars($activity['description']); ?></td>
                                    <td><?php echo htmlspecialchars($activity['entity_id'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($activity['ip_address'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($activity['user_agent'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($activity['created_at']))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center">No activity logs found.</td>
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