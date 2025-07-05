<?php
// File: completed-batches.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Include header (which usually handles authentication checks).
$page_title = "Completed Batches";
include_once '../includes/header.php';

// Check user authentication and role (e.g., only 'owner' can manage completed batches).
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to view completed batches.";
    header('Location: dashboard.php'); // Redirect to dashboard or login
    exit;
}

// Get database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

// Process form submission (transfer to transit).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'transfer_to_transit') {
    try {
        $batch_id = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
        $product_id = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($_POST['quantity_produced'] ?? null, FILTER_VALIDATE_INT);
        $initiated_by = $_SESSION['user_id'];

        if (!$batch_id || !$product_id || !$quantity || $quantity <= 0) {
            throw new Exception("Missing or invalid transfer details.");
        }

        // Start transaction for atomicity.
        $db->beginTransaction();

        // 1. Update batch status to indicate transfer initiated (optional, but good for tracking)
        // Or confirm it's already 'completed' and not being re-transferred
        $batch_check_query = "SELECT status, batch_number FROM manufacturing_batches WHERE id = ? FOR UPDATE";
        $batch_check_stmt = $db->prepare($batch_check_query);
        $batch_check_stmt->execute([$batch_id]);
        $batch_info = $batch_check_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$batch_info || $batch_info['status'] !== 'completed') {
            throw new Exception("Batch is not in 'completed' status or not found.");
        }
        // Prevent multiple transfers if not intended
        // You might add a field `is_transferred_to_wholesale` in `manufacturing_batches`

        // 2. Create a pending `inventory_transfers` record.
        // from_location 'manufacturing' is implicit for completed batches.
        $insert_transfer_query = "INSERT INTO inventory_transfers
                                 (product_id, quantity, from_location, to_location, transfer_date, initiated_by, shopkeeper_id, status)
                                 VALUES (?, ?, 'manufacturing', 'wholesale', NOW(), ?, ?, 'pending')";
        // Determine shopkeeper for notification/confirmation. Find an active shopkeeper.
        $shopkeeper_id_for_transfer = null;
        $shopkeeper_query = "SELECT id FROM users WHERE role = 'shopkeeper' AND is_active = 1 LIMIT 1";
        $shopkeeper_stmt = $db->query($shopkeeper_query);
        $designated_shopkeeper = $shopkeeper_stmt->fetch(PDO::FETCH_ASSOC);
        if ($designated_shopkeeper) {
            $shopkeeper_id_for_transfer = $designated_shopkeeper['id'];
        }

        $insert_transfer_stmt = $db->prepare($insert_transfer_query);
        $insert_transfer_stmt->execute([
            $product_id,
            $quantity,
            $initiated_by,
            $shopkeeper_id_for_transfer // Null if no shopkeeper found or general wholesale
        ]);
        $transfer_id = $db->lastInsertId();

        // 3. Create a notification for the designated shopkeeper.
        if ($shopkeeper_id_for_transfer) {
            $product_name_query = "SELECT name FROM products WHERE id = ?";
            $product_name_stmt = $db->prepare($product_name_query);
            $product_name_stmt->execute([$product_id]);
            $product_name = $product_name_stmt->fetchColumn();

            $notification_message = "Batch " . htmlspecialchars($batch_info['batch_number']) . " (Product: " . htmlspecialchars($product_name) . ") with " . $quantity . " units completed and transferred to wholesale. Pending your confirmation (Transfer ID: " . $transfer_id . ").";
            $notification_type = "batch_transfer_pending";

            $insert_notification_query = "INSERT INTO notifications (user_id, type, message, related_id) VALUES (?, ?, ?, ?)";
            $insert_notification_stmt = $db->prepare($insert_notification_query);
            $insert_notification_stmt->execute([$shopkeeper_id_for_transfer, $notification_type, $notification_message, $transfer_id]);
        }

        // Log the activity using Auth class.
        $product_name_query = "SELECT name FROM products WHERE id = ?";
        $product_name_stmt = $db->prepare($product_name_query);
        $product_name_stmt->execute([$product_id]);
        $product_name = $product_name_stmt->fetchColumn();

        $auth->logActivity(
            $initiated_by,
            'update',
            'manufacturing_batches',
            "Transferred completed batch " . htmlspecialchars($batch_info['batch_number']) . " (" . htmlspecialchars($product_name) . ", " . $quantity . " units) to wholesale. Transfer ID: " . $transfer_id,
            $batch_id
        );

        // Commit transaction.
        $db->commit();

        $_SESSION['success_message'] = "Batch " . htmlspecialchars($batch_info['batch_number']) . " successfully marked for transfer to wholesale. Shopkeeper notified for confirmation.";
        header('Location: completed-batches.php'); // Redirect to refresh the page
        exit;

    } catch (Exception $e) {
        // Rollback transaction on error.
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        // Log the error using Auth class.
        $auth->logActivity(
            $_SESSION['user_id'] ?? null,
            'error',
            'completed_batches',
            'Failed to transfer completed batch: ' . $e->getMessage(),
            $batch_id ?? null
        );
        $_SESSION['error_message'] = $e->getMessage();
        header('Location: completed-batches.php'); // Redirect back with error
        exit;
    }
}

// Pagination settings.
$limit = 15;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Filters (if any for completed batches).
$where_clauses = ["mb.status = 'completed'"]; // Always filter for completed batches
$params = [];

// Example filter by product ID or batch number if needed.
$filter_product_id = isset($_GET['product_id']) && is_numeric($_GET['product_id']) ? intval($_GET['product_id']) : null;
$filter_batch_number = isset($_GET['batch_number']) ? trim($_GET['batch_number']) : '';

if ($filter_product_id) {
    $where_clauses[] = "mb.product_id = ?";
    $params[] = $filter_product_id;
}
if (!empty($filter_batch_number)) {
    $where_clauses[] = "mb.batch_number LIKE ?";
    $params[] = '%' . $filter_batch_number . '%';
}

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

try {
    // Total records for pagination.
    $count_query = "SELECT COUNT(*) FROM manufacturing_batches mb " . $where_sql;
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    // Fetch completed batches.
    $query = "SELECT mb.*, p.name as product_name, p.sku as product_sku,
                     u.full_name as created_by_name
              FROM manufacturing_batches mb
              JOIN products p ON mb.product_id = p.id
              JOIN users u ON mb.created_by = u.id
              " . $where_sql . "
              ORDER BY mb.completion_date DESC, mb.created_at DESC
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
        'manufacturing_batches',
        'Viewed completed manufacturing batches page' . (!empty($where_sql) ? ' with filters' : ''),
        null
    );

} catch (PDOException $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'completed_batches',
        'Database error fetching completed batches: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = "A database error occurred while fetching completed batches. Please try again later.";
    $batches = [];
    $total_pages = 0;
} catch (Exception $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'completed_batches',
        'Error fetching completed batches: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = $e->getMessage();
    $batches = [];
    $total_pages = 0;
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt; <span>Completed Batches</span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>Completed Manufacturing Batches</h2>
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
            <h3>Filter Completed Batches</h3>
        </div>
        <div class="card-content">
            <form method="GET" action="completed-batches.php">
                <div class="form-row">
                    <div class="form-group col-md-4">
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
                    <div class="form-group col-md-4">
                        <label for="batch_number">Batch Number:</label>
                        <input type="text" id="batch_number" name="batch_number" class="form-control" value="<?php echo htmlspecialchars($filter_batch_number); ?>" placeholder="Search by Batch Number">
                    </div>
                    <div class="form-group col-md-4 d-flex align-items-end">
                        <button type="submit" class="button primary">Apply Filters</button>
                        <a href="completed-batches.php" class="button secondary ml-2">Clear Filters</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h3>Completed Batch Records (Total: <?php echo $total_records; ?>)</h3>
        </div>
        <div class="card-content">
            <div class="table-responsive">
                <table class="table table-hover sortable-table">
                    <thead>
                        <tr>
                            <th class="sortable">Batch ID</th>
                            <th class="sortable">Batch Number</th>
                            <th class="sortable">Product</th>
                            <th class="sortable">Quantity Produced</th>
                            <th class="sortable">Completion Date</th>
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
                                    <td><?php echo htmlspecialchars($batch['completion_date']); ?></td>
                                    <td><?php echo htmlspecialchars($batch['created_by_name']); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view-batch.php?id=<?php echo htmlspecialchars($batch['id']); ?>" class="button button-sm button-info" title="View Batch Details">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <form method="POST" action="completed-batches.php" style="display:inline-block;"
                                                  onsubmit="return confirm('Are you sure you want to mark Batch <?php echo htmlspecialchars($batch['batch_number']); ?> for transfer to wholesale? This will update inventory and notify the shopkeeper.');">
                                                <input type="hidden" name="action" value="transfer_to_transit">
                                                <input type="hidden" name="batch_id" value="<?php echo htmlspecialchars($batch['id']); ?>">
                                                <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($batch['product_id']); ?>">
                                                <input type="hidden" name="quantity_produced" value="<?php echo htmlspecialchars($batch['quantity_produced']); ?>">
                                                <button type="submit" class="button button-sm button-success" title="Mark for Transfer to Wholesale">
                                                    <i class="fas fa-truck"></i> Transfer to Wholesale
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center">No completed batches found.</td>
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