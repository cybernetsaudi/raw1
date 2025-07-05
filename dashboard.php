<?php
// File: dashboard.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Include header (which usually handles authentication checks).
$page_title = "Dashboard";
include_once '../includes/header.php';

// Check if user is logged in. If not, redirect to login page.
if (!$auth->isLoggedIn()) {
    header('Location: index.php'); // Redirect to login page
    exit;
}

// Get database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Initialize data arrays.
$summary_data = [];
$recent_activities = [];
$recent_batches = [];
$recent_sales = [];
$pending_transfers = [];
$payment_reminders = [];
$min_stock_alerts = [];

try {
    // Log viewing the dashboard.
    $auth->logActivity(
        $user_id,
        'read',
        'dashboard',
        'Viewed ' . ucfirst($user_role) . ' Dashboard',
        null
    );

    // Common data for all roles (or adapted per role).
    // Fetch recent activities (last 5, regardless of role but filtered if specific access)
    $activity_query = "SELECT al.description, al.created_at, u.full_name FROM activity_logs al JOIN users u ON al.user_id = u.id ORDER BY al.created_at DESC LIMIT 5";
    $activity_stmt = $db->query($activity_query);
    $recent_activities = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Data specific to 'owner' role.
    if ($user_role === 'owner') {
        // Financial Summary
        $summary_query = "SELECT
            (SELECT COALESCE(SUM(amount), 0) FROM funds WHERE type = 'investment') AS total_investment,
            (SELECT COALESCE(SUM(total_amount), 0) FROM purchases) AS total_purchases,
            (SELECT COALESCE(SUM(amount), 0) FROM manufacturing_costs) AS total_manufacturing_costs,
            (SELECT COALESCE(SUM(net_amount), 0) FROM sales) AS total_sales_revenue,
            (SELECT COALESCE(SUM(amount), 0) FROM payments) AS total_payments_received,
            (SELECT COALESCE(SUM(CASE WHEN status = 'active' THEN balance ELSE 0 END), 0) FROM funds WHERE type = 'investment') AS active_fund_balance,
            (SELECT COALESCE(SUM(CASE WHEN payment_status != 'paid' THEN net_amount - (SELECT COALESCE(SUM(amount),0) FROM payments WHERE payments.sale_id = sales.id) ELSE 0 END), 0) FROM sales) AS total_receivables
        ";
        $summary_stmt = $db->query($summary_query);
        $summary_data = $summary_stmt->fetch(PDO::FETCH_ASSOC);

        // Raw Material Stock Alerts (below min_stock_level)
        $min_stock_query = "SELECT name, stock_quantity, unit, min_stock_level FROM raw_materials WHERE stock_quantity < min_stock_level";
        $min_stock_stmt = $db->query($min_stock_query);
        $min_stock_alerts = $min_stock_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Recent Completed Batches
        $recent_batches_query = "SELECT mb.batch_number, p.name AS product_name, mb.quantity_produced, mb.completion_date
                                 FROM manufacturing_batches mb
                                 JOIN products p ON mb.product_id = p.id
                                 WHERE mb.status = 'completed'
                                 ORDER BY mb.completion_date DESC LIMIT 5";
        $recent_batches_stmt = $db->query($recent_batches_query);
        $recent_batches = $recent_batches_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Pending Inventory Transfers (for approval/oversight)
        $pending_transfers_query = "SELECT it.id, p.name AS product_name, it.quantity, it.from_location, it.to_location, it.transfer_date
                                    FROM inventory_transfers it
                                    JOIN products p ON it.product_id = p.id
                                    WHERE it.status = 'pending'
                                    ORDER BY it.transfer_date ASC LIMIT 5";
        $pending_transfers_stmt = $db->query($pending_transfers_query);
        $pending_transfers = $pending_transfers_stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($user_role === 'incharge') {
        // In-charge specific data
        // Recent Active Batches
        $recent_batches_query = "SELECT mb.batch_number, p.name AS product_name, mb.quantity_produced, mb.status, mb.start_date, mb.expected_completion_date
                                 FROM manufacturing_batches mb
                                 JOIN products p ON mb.product_id = p.id
                                 WHERE mb.status NOT IN ('completed')
                                 ORDER BY mb.start_date DESC LIMIT 5";
        $recent_batches_stmt = $db->query($recent_batches_query);
        $recent_batches = $recent_batches_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Raw Material Stock Alerts (below min_stock_level)
        $min_stock_query = "SELECT name, stock_quantity, unit, min_stock_level FROM raw_materials WHERE stock_quantity < min_stock_level";
        $min_stock_stmt = $db->query($min_stock_query);
        $min_stock_alerts = $min_stock_stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($user_role === 'shopkeeper') {
        // Shopkeeper specific data
        // Recent Sales by this shopkeeper
        $recent_sales_query = "SELECT s.invoice_number, c.name AS customer_name, s.net_amount, s.sale_date, s.payment_status
                               FROM sales s
                               JOIN customers c ON s.customer_id = c.id
                               WHERE s.created_by = ?
                               ORDER BY s.sale_date DESC LIMIT 5";
        $recent_sales_stmt = $db->prepare($recent_sales_query);
        $recent_sales_stmt->execute([$user_id]);
        $recent_sales = $recent_sales_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Pending Inventory Transfers for this shopkeeper to confirm
        $pending_transfers_query = "SELECT it.id, p.name AS product_name, it.quantity, it.from_location, it.transfer_date
                                    FROM inventory_transfers it
                                    JOIN products p ON it.product_id = p.id
                                    WHERE it.status = 'pending' AND it.shopkeeper_id = ?
                                    ORDER BY it.transfer_date ASC LIMIT 5";
        $pending_transfers_stmt = $db->prepare($pending_transfers_query);
        $pending_transfers_stmt->execute([$user_id]);
        $pending_transfers = $pending_transfers_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Payment Reminders (sales created by this shopkeeper that are unpaid/partial)
        $payment_reminders_query = "
            SELECT
                s.id AS sale_id,
                s.invoice_number,
                c.name AS customer_name,
                (s.total_amount - COALESCE(SUM(p.amount), 0)) AS amount_due,
                s.payment_due_date
            FROM sales s
            JOIN customers c ON s.customer_id = c.id
            LEFT JOIN payments p ON s.id = p.sale_id
            WHERE s.created_by = ? AND s.payment_status IN ('unpaid', 'partial')
            GROUP BY s.id, s.invoice_number, c.name, s.total_amount, s.payment_due_date
            HAVING amount_due > 0
            ORDER BY s.payment_due_date ASC
            LIMIT 5
        ";
        $payment_reminders_stmt = $db->prepare($payment_reminders_query);
        $payment_reminders_stmt->execute([$user_id]);
        $payment_reminders = $payment_reminders_stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    $auth->logActivity(
        $user_id ?? null,
        'error',
        'dashboard',
        'Database error loading dashboard data: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = "A database error occurred while loading dashboard data. Please try again later.";
} catch (Exception $e) {
    $auth->logActivity(
        $user_id ?? null,
        'error',
        'dashboard',
        'Error loading dashboard data: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = $e->getMessage();
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt; <span>Home</span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h2>
        <span class="user-role-badge role-<?php echo htmlspecialchars($user_role); ?>"><?php echo ucfirst(htmlspecialchars($user_role)); ?></span>
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
if (isset($_SESSION['info_message'])) {
    echo '<div class="alert alert-info"><i class="fas fa-info-circle"></i><span>' . htmlspecialchars($_SESSION['info_message']) . '</span><span class="alert-close">&times;</span></div>';
    unset($_SESSION['info_message']);
}
?>

<div class="container-fluid dashboard-content">
    <?php if ($user_role === 'owner'): ?>
        <div class="dashboard-section summary-cards">
            <h3>Financial Overview</h3>
            <div class="card-grid">
                <div class="card summary-card">
                    <div class="card-content">
                        <div class="card-icon"><i class="fas fa-sack-dollar"></i></div>
                        <div class="card-info">
                            <h4>Total Investment</h4>
                            <p>Rs. <?php echo number_format($summary_data['total_investment'] ?? 0, 2); ?></p>
                        </div>
                    </div>
                </div>
                <div class="card summary-card">
                    <div class="card-content">
                        <div class="card-icon"><i class="fas fa-shopping-cart"></i></div>
                        <div class="card-info">
                            <h4>Total Purchases</h4>
                            <p>Rs. <?php echo number_format($summary_data['total_purchases'] ?? 0, 2); ?></p>
                        </div>
                    </div>
                </div>
                <div class="card summary-card">
                    <div class="card-content">
                        <div class="card-icon"><i class="fas fa-industry"></i></div>
                        <div class="card-info">
                            <h4>Total Manufacturing Costs</h4>
                            <p>Rs. <?php echo number_format($summary_data['total_manufacturing_costs'] ?? 0, 2); ?></p>
                        </div>
                    </div>
                </div>
                <div class="card summary-card">
                    <div class="card-content">
                        <div class="card-icon"><i class="fas fa-chart-line"></i></div>
                        <div class="card-info">
                            <h4>Total Sales Revenue</h4>
                            <p>Rs. <?php echo number_format($summary_data['total_sales_revenue'] ?? 0, 2); ?></p>
                        </div>
                    </div>
                </div>
                <div class="card summary-card">
                    <div class="card-content">
                        <div class="card-icon"><i class="fas fa-money-bill-wave"></i></div>
                        <div class="card-info">
                            <h4>Payments Received</h4>
                            <p>Rs. <?php echo number_format($summary_data['total_payments_received'] ?? 0, 2); ?></p>
                        </div>
                    </div>
                </div>
                <div class="card summary-card">
                    <div class="card-content">
                        <div class="card-icon"><i class="fas fa-wallet"></i></div>
                        <div class="card-info">
                            <h4>Active Funds Balance</h4>
                            <p>Rs. <?php echo number_format($summary_data['active_fund_balance'] ?? 0, 2); ?></p>
                        </div>
                    </div>
                </div>
                <div class="card summary-card">
                    <div class="card-content">
                        <div class="card-icon"><i class="fas fa-hand-holding-usd"></i></div>
                        <div class="card-info">
                            <h4>Total Receivables</h4>
                            <p>Rs. <?php echo number_format($summary_data['total_receivables'] ?? 0, 2); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="dashboard-section">
            <div class="card chart-card">
                <div class="card-header">
                    <h3>Financial Trends</h3>
                </div>
                <div class="card-content">
                    <canvas id="financialChart"></canvas>
                    <p class="text-muted text-center mt-3">Chart will be rendered here by JavaScript with fetched data.</p>
                </div>
            </div>
        </div>

    <?php endif; ?>

    <div class="dashboard-section">
        <div class="card recent-activity-card">
            <div class="card-header">
                <h3>Recent Activity</h3>
            </div>
            <div class="card-content">
                <?php if (!empty($recent_activities)): ?>
                    <ul class="activity-list">
                        <?php foreach ($recent_activities as $activity): ?>
                            <li>
                                <span class="activity-description">
                                    <?php echo htmlspecialchars($activity['description']); ?>
                                </span>
                                <span class="activity-meta">
                                    by <?php echo htmlspecialchars($activity['full_name']); ?> on <?php echo date('Y-m-d H:i', strtotime($activity['created_at'])); ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-center text-muted">No recent activities to display.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($min_stock_alerts) && ($_SESSION['role'] === 'owner' || $_SESSION['role'] === 'incharge')): ?>
        <div class="dashboard-section">
            <div class="card alert-card">
                <div class="card-header">
                    <h3><i class="fas fa-exclamation-triangle text-warning"></i> Low Stock Alerts</h3>
                </div>
                <div class="card-content">
                    <ul class="alert-list">
                        <?php foreach ($min_stock_alerts as $alert): ?>
                            <li>
                                Raw Material: <strong><?php echo htmlspecialchars($alert['name']); ?></strong> is critically low.
                                Current stock: <?php echo number_format($alert['stock_quantity'], 2); ?> <?php echo htmlspecialchars($alert['unit']); ?>,
                                Min level: <?php echo number_format($alert['min_stock_level'], 2); ?> <?php echo htmlspecialchars($alert['unit']); ?>.
                                <a href="raw-materials.php" class="alert-action">Order Now</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($recent_batches) && ($_SESSION['role'] === 'owner' || $_SESSION['role'] === 'incharge')): ?>
        <div class="dashboard-section">
            <div class="card recent-batches-card">
                <div class="card-header">
                    <h3>Recent <?php echo ($_SESSION['role'] === 'owner') ? 'Completed' : 'Active'; ?> Batches</h3>
                </div>
                <div class="card-content">
                    <?php if (!empty($recent_batches)): ?>
                        <ul class="list-group">
                            <?php foreach ($recent_batches as $batch): ?>
                                <li class="list-group-item">
                                    <strong>Batch:</strong> <?php echo htmlspecialchars($batch['batch_number']); ?> (<?php echo htmlspecialchars($batch['product_name']); ?>) -
                                    Qty: <?php echo number_format($batch['quantity_produced']); ?>
                                    <?php if ($user_role === 'incharge'): ?>
                                        <span class="status-badge status-<?php echo htmlspecialchars($batch['status']); ?> ml-2"><?php echo ucfirst(htmlspecialchars($batch['status'])); ?></span>
                                    <?php endif; ?>
                                    <br>
                                    <?php if ($user_role === 'owner'): ?>
                                        <small>Completed on: <?php echo htmlspecialchars($batch['completion_date']); ?></small>
                                    <?php else: ?>
                                        <small>Started: <?php echo htmlspecialchars($batch['start_date']); ?>, Expected: <?php echo htmlspecialchars($batch['expected_completion_date']); ?></small>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-center text-muted">No recent batches to display.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($recent_sales) && $_SESSION['role'] === 'shopkeeper'): ?>
        <div class="dashboard-section">
            <div class="card recent-sales-card">
                <div class="card-header">
                    <h3>Recent Sales</h3>
                </div>
                <div class="card-content">
                    <?php if (!empty($recent_sales)): ?>
                        <ul class="list-group">
                            <?php foreach ($recent_sales as $sale): ?>
                                <li class="list-group-item">
                                    <strong>Invoice:</strong> #<?php echo htmlspecialchars($sale['invoice_number']); ?> - Customer: <?php echo htmlspecialchars($sale['customer_name']); ?><br>
                                    Amount: Rs. <?php echo number_format($sale['net_amount'], 2); ?> - Date: <?php echo htmlspecialchars($sale['sale_date']); ?>
                                    <span class="status-badge status-<?php echo htmlspecialchars($sale['payment_status']); ?> ml-2"><?php echo ucfirst(htmlspecialchars($sale['payment_status'])); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-center text-muted">No recent sales to display.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($pending_transfers) && ($_SESSION['role'] === 'owner' || $_SESSION['role'] === 'shopkeeper')): ?>
        <div class="dashboard-section">
            <div class="card pending-transfers-card">
                <div class="card-header">
                    <h3><i class="fas fa-truck text-info"></i> Pending Inventory Transfers</h3>
                </div>
                <div class="card-content">
                    <?php if (!empty($pending_transfers)): ?>
                        <ul class="alert-list">
                            <?php foreach ($pending_transfers as $transfer): ?>
                                <li>
                                    Transfer #<?php echo htmlspecialchars($transfer['id']); ?>:
                                    <strong><?php echo number_format($transfer['quantity']); ?></strong> units of
                                    <strong><?php echo htmlspecialchars($transfer['product_name']); ?></strong>
                                    from <?php echo htmlspecialchars($transfer['from_location']); ?>
                                    to <?php echo htmlspecialchars($transfer['to_location']); ?>.
                                    <small>(Initiated: <?php echo date('Y-m-d', strtotime($transfer['transfer_date'])); ?>)</small>
                                    <?php if ($user_role === 'shopkeeper'): ?>
                                        <a href="inventory-transfers.php" class="alert-action">Confirm Now</a>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-center text-muted">No pending inventory transfers.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($payment_reminders) && $_SESSION['role'] === 'shopkeeper'): ?>
        <div class="dashboard-section">
            <div class="card reminder-card">
                <div class="card-header">
                    <h3><i class="fas fa-bell text-danger"></i> Payment Reminders</h3>
                </div>
                <div class="card-content">
                    <?php if (!empty($payment_reminders)): ?>
                        <ul class="alert-list">
                            <?php foreach ($payment_reminders as $reminder): ?>
                                <li>
                                    Sale #<?php echo htmlspecialchars($reminder['invoice_number']); ?> (Customer: <?php echo htmlspecialchars($reminder['customer_name']); ?>) has Rs. <?php echo number_format($reminder['amount_due'], 2); ?> due.
                                    <?php if ($reminder['payment_due_date']): ?>
                                        (Due: <?php echo htmlspecialchars($reminder['payment_due_date']); ?>)
                                    <?php endif; ?>
                                    <a href="view-sale.php?id=<?php echo htmlspecialchars($reminder['sale_id']); ?>" class="alert-action">View Sale</a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-center text-muted">No payment reminders at the moment.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div> <style>
/* Dashboard Styles */
.dashboard-content {
    display: grid;
    gap: 1.5rem;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
}

.dashboard-section {
    grid-column: span 1; /* Default to single column */
}

/* Owner dashboard layout */
@media (min-width: 992px) {
    .owner .dashboard-section.summary-cards {
        grid-column: span 2;
    }
    .owner .dashboard-section.chart-card {
        grid-column: span 2;
    }
}
@media (min-width: 1200px) {
    .dashboard-content {
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    }
    .owner .dashboard-section.summary-cards {
        grid-column: span 3; /* More columns for wider screens */
    }
    .owner .dashboard-section.chart-card {
        grid-column: span 3;
    }
}


/* Summary Cards */
.card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.summary-card {
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    padding: 1.25rem;
    text-align: center;
    transition: transform 0.2s ease-in-out;
}

.summary-card:hover {
    transform: translateY(-5px);
}

.summary-card .card-content {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.summary-card .card-icon {
    font-size: 2.5rem;
    color: var(--primary, #1a73e8);
    margin-bottom: 0.75rem;
}

.summary-card .card-info h4 {
    margin: 0 0 0.5rem 0;
    font-size: 1rem;
    color: var(--text-secondary, #6c757d);
}

.summary-card .card-info p {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--text-primary, #343a40);
}

/* Chart Card */
.chart-card {
    height: 400px; /* Fixed height for charts */
    display: flex;
    flex-direction: column;
    justify-content: center; /* Center content vertically if not full */
    align-items: center; /* Center content horizontally */
}

.chart-card canvas {
    max-width: 100%;
    max-height: 100%;
}

/* Recent Activity & Alerts */
.recent-activity-card, .alert-card, .recent-batches-card, .recent-sales-card, .pending-transfers-card, .reminder-card {
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    margin-bottom: 1.5rem; /* Add some margin for spacing between cards */
}

.recent-activity-card .card-header, .alert-card .card-header, .recent-batches-card .card-header, .recent-sales-card .card-header, .pending-transfers-card .card-header, .reminder-card .card-header {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.recent-activity-card .card-header h3, .alert-card .card-header h3, .recent-batches-card .card-header h3, .recent-sales-card .card-header h3, .pending-transfers-card .card-header h3, .reminder-card .card-header h3 {
    margin: 0;
    font-size: 1.1rem;
}

.recent-activity-card .card-content, .alert-card .card-content, .recent-batches-card .card-content, .recent-sales-card .card-content, .pending-transfers-card .card-content, .reminder-card .card-content {
    padding: 1.5rem;
}

.activity-list, .alert-list, .list-group {
    list-style: none;
    padding: 0;
    margin: 0;
}

.activity-list li, .alert-list li, .list-group-item {
    padding: 0.75rem 0;
    border-bottom: 1px solid #e9ecef;
    font-size: 0.9rem;
    color: var(--text-primary, #343a40);
}

.activity-list li:last-child, .alert-list li:last-child, .list-group-item:last-child {
    border-bottom: none;
}

.activity-description {
    font-weight: 500;
}

.activity-meta {
    display: block;
    font-size: 0.8rem;
    color: var(--text-secondary, #6c757d);
    margin-top: 0.25rem;
}

.alert-card .fas {
    margin-right: 0.5rem;
}

.alert-action {
    margin-left: 1rem;
    color: var(--primary, #1a73e8);
    text-decoration: none;
}

.alert-action:hover {
    text-decoration: underline;
}

/* User Role Badge */
.user-role-badge {
    display: inline-block;
    padding: 0.2em 0.6em;
    border-radius: 0.25rem;
    font-size: 0.8em;
    font-weight: 600;
    text-transform: uppercase;
    margin-left: 10px;
}

.role-owner {
    background-color: var(--accent, #f0ad4e); /* Yellowish */
    color: white;
}

.role-incharge {
    background-color: var(--info, #5bc0de); /* Light Blue */
    color: white;
}

.role-shopkeeper {
    background-color: var(--success, #28a745); /* Green */
    color: white;
}
</style>

<?php include_once '../includes/footer.php'; ?>