<?php
// File: financial.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Include header.
$page_title = "Financial Overview";
include_once '../includes/header.php';

// Check user authentication and role (e.g., only 'owner' can view financial data).
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to view financial overview.";
    header('Location: dashboard.php'); // Redirect to dashboard or login
    exit;
}

// DEVELOPMENT ONLY: Remove these lines in production environment for security.
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

// Get database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

$financial_summary = [];
$total_raw_materials_value = 0;
$total_wip_value = 0;
$total_wholesale_inventory_value = 0;
$total_finished_goods_inventory_value = 0;

try {
    // Log viewing the page.
    $auth->logActivity(
        $_SESSION['user_id'],
        'read',
        'financial',
        'Viewed financial overview page',
        null
    );

    // Date range for financial data (optional filter for future expansion)
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;

    $date_filter_sql = '';
    $date_filter_params = [];
    if ($start_date && $end_date) {
        $date_filter_sql = " WHERE created_at BETWEEN ? AND ?";
        $date_filter_params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
    }

    // --- Financial Summary Metrics ---
    // Total Investment
    $query = "SELECT COALESCE(SUM(amount), 0) FROM funds WHERE type = 'investment'" . $date_filter_sql;
    $stmt = $db->prepare($query);
    $stmt->execute($date_filter_params);
    $total_investment = $stmt->fetchColumn();

    // Total Purchases
    $query = "SELECT COALESCE(SUM(total_amount), 0) FROM purchases";
    $stmt = $db->prepare($query);
    // Purchases table has purchase_date, not created_at, need to adjust filter
    if ($start_date && $end_date) {
        $query .= " WHERE purchase_date BETWEEN ? AND ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$start_date, $end_date]);
    } else {
        $stmt = $db->query($query);
    }
    $total_purchases = $stmt->fetchColumn();

    // Total Manufacturing Costs (from manufacturing_costs table)
    $query = "SELECT COALESCE(SUM(mc.amount), 0) FROM manufacturing_costs mc JOIN manufacturing_batches mb ON mc.batch_id = mb.id";
    // Using batch created_at for filtering manufacturing costs by overall batch creation period
    if ($start_date && $end_date) {
        $query .= " WHERE mb.created_at BETWEEN ? AND ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
    } else {
        $stmt = $db->query($query);
    }
    $total_manufacturing_costs = $stmt->fetchColumn();

    // Total Sales Revenue
    $query = "SELECT COALESCE(SUM(net_amount), 0) FROM sales";
    if ($start_date && $end_date) {
        $query .= " WHERE sale_date BETWEEN ? AND ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$start_date, $end_date]);
    } else {
        $stmt = $db->query($query);
    }
    $total_sales_revenue = $stmt->fetchColumn();

    // Total Payments Received
    $query = "SELECT COALESCE(SUM(amount), 0) FROM payments";
    if ($start_date && $end_date) {
        $query .= " WHERE payment_date BETWEEN ? AND ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$start_date, $end_date]);
    } else {
        $stmt = $db->query($query);
    }
    $total_payments_received = $stmt->fetchColumn();

    // Cash on Hand (simplified: Total Investment - Total Purchases - Total Manufacturing Costs)
    // This is a rough estimation; actual cash on hand would involve a dedicated cash account.
    $cash_on_hand = $total_investment - $total_purchases - $total_manufacturing_costs + $total_payments_received;

    // Total Receivables (Unpaid/Partial Sales)
    $receivables_query = "
        SELECT COALESCE(SUM(s.net_amount - COALESCE(p_sum.paid_amount, 0)), 0)
        FROM sales s
        LEFT JOIN (
            SELECT sale_id, SUM(amount) AS paid_amount
            FROM payments
            GROUP BY sale_id
        ) p_sum ON s.id = p_sum.sale_id
        WHERE s.payment_status IN ('unpaid', 'partial');
    ";
    $receivables_stmt = $db->query($receivables_query);
    $total_receivables = $receivables_stmt->fetchColumn();

    // --- Inventory Valuation ---
    // Raw Materials Value (Current stock * avg unit price from last 5 purchases)
    $raw_materials_value_query = "
        SELECT COALESCE(SUM(rm.stock_quantity * (
            SELECT AVG(p.unit_price) FROM purchases p WHERE p.material_id = rm.id ORDER BY p.purchase_date DESC LIMIT 5
        )), 0)
        FROM raw_materials rm;
    ";
    $raw_materials_value_stmt = $db->query($raw_materials_value_query);
    $total_raw_materials_value = $raw_materials_value_stmt->fetchColumn();

    // Work-in-Progress (WIP) Value
    // WIP calculation is complex. This attempts to sum up costs for non-completed batches.
    // It's a rough estimate: (material cost + manufacturing costs) for pending/in-progress batches.
    $wip_value_query = "
        SELECT COALESCE(SUM(
            (SELECT COALESCE(SUM(mc.amount), 0) FROM manufacturing_costs mc WHERE mc.batch_id = mb.id) +
            (SELECT COALESCE(SUM(mu.quantity_used * (
                SELECT AVG(p.unit_price) FROM purchases p WHERE p.material_id = mu.material_id ORDER BY p.purchase_date DESC LIMIT 5
            )), 0) FROM material_usage mu WHERE mu.batch_id = mb.id)
        ), 0) AS wip_value
        FROM manufacturing_batches mb
        WHERE mb.status NOT IN ('completed');
    ";
    $wip_value_stmt = $db->query($wip_value_query);
    $total_wip_value = $wip_value_stmt->fetchColumn();

    // Finished Goods (Wholesale Inventory) Value
    // Assuming wholesale inventory is valued at (Avg Unit Cost * 1.3 markup) as a rough estimate for selling value
    // Or, should be valued at production cost. Let's use production cost.
    $wholesale_inventory_value_query = "
        SELECT COALESCE(SUM(i.quantity * COALESCE(
            (SELECT SUM(mc.amount) + COALESCE(SUM(mu.quantity_used * (SELECT AVG(pr.unit_price) FROM purchases pr WHERE pr.material_id = mu.material_id ORDER BY pr.purchase_date DESC LIMIT 5)), 0)
             FROM manufacturing_costs mc
             LEFT JOIN material_usage mu ON mc.batch_id = mu.batch_id
             WHERE mc.batch_id = i.batch_id
             GROUP BY mc.batch_id) / NULLIF(mb.quantity_produced, 0), 0
        )), 0)
        FROM inventory i
        JOIN manufacturing_batches mb ON i.batch_id = mb.id
        WHERE i.location = 'wholesale';
    ";
    $wholesale_inventory_value_stmt = $db->query($wholesale_inventory_value_query);
    $total_wholesale_inventory_value = $wholesale_inventory_value_stmt->fetchColumn();

    // Total Inventory Value (Raw Materials + WIP + Wholesale)
    $total_finished_goods_inventory_value = $total_raw_materials_value + $total_wip_value + $total_wholesale_inventory_value;


    $financial_summary = [
        'total_investment' => $total_investment,
        'total_purchases' => $total_purchases,
        'total_manufacturing_costs' => $total_manufacturing_costs,
        'total_sales_revenue' => $total_sales_revenue,
        'total_payments_received' => $total_payments_received,
        'cash_on_hand' => $cash_on_hand,
        'total_receivables' => $total_receivables,
        'total_raw_materials_value' => $total_raw_materials_value,
        'total_wip_value' => $total_wip_value,
        'total_wholesale_inventory_value' => $total_wholesale_inventory_value,
        'total_finished_goods_inventory_value' => $total_finished_goods_inventory_value
    ];

} catch (PDOException $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'financial',
        'Database error loading financial overview: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = "A database error occurred while fetching financial data. Please try again later.";
} catch (Exception $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'financial',
        'Error loading financial overview: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = $e->getMessage();
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt; <span>Financial Overview</span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>Financial Overview</h2>
    </div>
    <div class="page-actions">
        <a href="reports.php?type=financial" class="button secondary">
            <i class="fas fa-chart-bar"></i> View Financial Reports
        </a>
        <a href="export-report.php?type=financial" class="button primary">
            <i class="fas fa-download"></i> Export Financial Data
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

<div class="container-fluid financial-content">
    <div class="dashboard-section summary-cards">
        <h3>Key Financial Metrics</h3>
        <div class="card-grid">
            <div class="card summary-card">
                <div class="card-content">
                    <div class="card-icon"><i class="fas fa-coins"></i></div>
                    <div class="card-info">
                        <h4>Cash on Hand (Est.)</h4>
                        <p>Rs. <?php echo number_format($financial_summary['cash_on_hand'] ?? 0, 2); ?></p>
                    </div>
                </div>
            </div>
            <div class="card summary-card">
                <div class="card-content">
                    <div class="card-icon"><i class="fas fa-hand-holding-usd"></i></div>
                    <div class="card-info">
                        <h4>Total Receivables</h4>
                        <p>Rs. <?php echo number_format($financial_summary['total_receivables'] ?? 0, 2); ?></p>
                    </div>
                </div>
            </div>
            <div class="card summary-card">
                <div class="card-content">
                    <div class="card-icon"><i class="fas fa-boxes"></i></div>
                    <div class="card-info">
                        <h4>Raw Materials Value</h4>
                        <p>Rs. <?php echo number_format($financial_summary['total_raw_materials_value'] ?? 0, 2); ?></p>
                    </div>
                </div>
            </div>
            <div class="card summary-card">
                <div class="card-content">
                    <div class="card-icon"><i class="fas fa-cogs"></i></div>
                    <div class="card-info">
                        <h4>Work-in-Progress Value (Est.)</h4>
                        <p>Rs. <?php echo number_format($financial_summary['total_wip_value'] ?? 0, 2); ?></p>
                    </div>
                </div>
            </div>
            <div class="card summary-card">
                <div class="card-content">
                    <div class="card-icon"><i class="fas fa-warehouse"></i></div>
                    <div class="card-info">
                        <h4>Wholesale Inventory Value (Est.)</h4>
                        <p>Rs. <?php echo number_format($financial_summary['total_wholesale_inventory_value'] ?? 0, 2); ?></p>
                    </div>
                </div>
            </div>
            <div class="card summary-card">
                <div class="card-content">
                    <div class="card-icon"><i class="fas fa-cubes"></i></div>
                    <div class="card-info">
                        <h4>Total Finished Goods Value (Est.)</h4>
                        <p>Rs. <?php echo number_format($financial_summary['total_finished_goods_inventory_value'] ?? 0, 2); ?></p>
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
                <p class="text-muted text-center mt-3">Financial trend charts will be displayed here.</p>
            </div>
        </div>
    </div>
</div>

<?php include_once '../includes/footer.php'; ?>