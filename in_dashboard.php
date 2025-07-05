<?php
session_start();
$page_title = "Incharge Dashboard";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get fund summary
try {
    $fund_query = "SELECT 
                    COUNT(*) as total_funds,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_funds,
                    SUM(CASE WHEN status = 'depleted' THEN 1 ELSE 0 END) as depleted_funds,
                    SUM(balance) as total_balance,
                    SUM(amount) as total_allocated,
                    (SELECT COALESCE(SUM(amount), 0) FROM fund_usage WHERE used_by = ?) as total_used
                FROM funds 
                WHERE to_user_id = ?";
    $fund_stmt = $db->prepare($fund_query);
    $fund_stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    $fund_summary = $fund_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error in fund summary query: " . $e->getMessage());
    $fund_summary = [
        'total_funds' => 0,
        'active_funds' => 0,
        'depleted_funds' => 0,
        'total_balance' => 0,
        'total_allocated' => 0,
        'total_used' => 0
    ];
}

// Calculate fund status
$fund_status = [
    'available' => $fund_summary['total_balance'] ?? 0,
    'used' => $fund_summary['total_used'] ?? 0,
    'allocated' => $fund_summary['total_allocated'] ?? 0,
    'overdraft' => max(0, ($fund_summary['total_used'] ?? 0) - ($fund_summary['total_allocated'] ?? 0))
];

// Get raw materials summary
try {
    $materials_query = "SELECT 
        COUNT(*) as total_materials,
        SUM(stock_quantity) as total_stock,
        COUNT(CASE WHEN stock_quantity <= COALESCE(min_stock_level, 10) THEN 1 END) as low_stock_count
        FROM raw_materials";
    $materials_stmt = $db->prepare($materials_query);
    $materials_stmt->execute();
    $materials = $materials_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error in materials query: " . $e->getMessage());
    $materials = [
        'total_materials' => 0,
        'total_stock' => 0,
        'low_stock_count' => 0
    ];
}

// Get manufacturing batches summary
try {
    $batches_query = "SELECT 
        COUNT(*) as total_batches,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_batches,
        SUM(CASE WHEN status != 'completed' THEN 1 ELSE 0 END) as active_batches,
        SUM(quantity_produced) as total_produced
        FROM manufacturing_batches";
    $batches_stmt = $db->prepare($batches_query);
    $batches_stmt->execute();
    $batches = $batches_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error in batches query: " . $e->getMessage());
    $batches = [
        'total_batches' => 0,
        'completed_batches' => 0,
        'active_batches' => 0,
        'total_produced' => 0
    ];
}

// Get recent material purchases
try {
    $purchases_query = "SELECT p.id, m.name as material_name, p.quantity, p.unit_price, 
                       p.total_amount, p.vendor_name, p.purchase_date 
                       FROM purchases p 
                       JOIN raw_materials m ON p.material_id = m.id 
                       ORDER BY p.purchase_date DESC LIMIT 5";
    $purchases_stmt = $db->prepare($purchases_query);
    $purchases_stmt->execute();
} catch (PDOException $e) {
    error_log("Error in purchases query: " . $e->getMessage());
    $purchases_stmt = null;
}

// Get active manufacturing batches
try {
    $active_batches_query = "SELECT b.id, b.batch_number, p.name as product_name, 
                            b.quantity_produced, b.status, b.start_date, b.expected_completion_date 
                            FROM manufacturing_batches b 
                            JOIN products p ON b.product_id = p.id 
                            WHERE b.status != 'completed'
                            ORDER BY b.expected_completion_date ASC LIMIT 5";
    $active_batches_stmt = $db->prepare($active_batches_query);
    $active_batches_stmt->execute();
} catch (PDOException $e) {
    error_log("Error in active batches query: " . $e->getMessage());
    $active_batches_stmt = null;
}

// Get low stock materials
try {
    $low_stock_query = "SELECT id, name, unit, stock_quantity, COALESCE(min_stock_level, 10) as min_stock_level 
                       FROM raw_materials 
                       WHERE stock_quantity <= COALESCE(min_stock_level, 10) 
                       ORDER BY (stock_quantity / COALESCE(min_stock_level, 10)) ASC LIMIT 5";
    $low_stock_stmt = $db->prepare($low_stock_query);
    $low_stock_stmt->execute();
} catch (PDOException $e) {
    error_log("Error in low stock query: " . $e->getMessage());
    $low_stock_stmt = null;
}

// Get manufacturing status counts for the progress bar
try {
    $status_query = "SELECT status, COUNT(*) as count
                    FROM manufacturing_batches
                    GROUP BY status";
    $status_stmt = $db->prepare($status_query);
    $status_stmt->execute();

    $status_counts = [];
    while($row = $status_stmt->fetch(PDO::FETCH_ASSOC)) {
        $status_counts[$row['status']] = $row['count'];
    }
} catch (PDOException $e) {
    error_log("Error in status counts query: " . $e->getMessage());
    $status_counts = [];
}

// Get all active batches for pipeline visualization
try {
    $pipeline_batches_query = "SELECT b.id, b.batch_number, p.name as product_name,
                             b.quantity_produced, b.status, b.start_date,
                             b.expected_completion_date, b.completion_date
                             FROM manufacturing_batches b
                             JOIN products p ON b.product_id = p.id
                             WHERE b.status != 'completed' OR b.completion_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                             ORDER BY b.expected_completion_date ASC";
    $pipeline_batches_stmt = $db->prepare($pipeline_batches_query);
    $pipeline_batches_stmt->execute();
    $active_batches_all = $pipeline_batches_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error in pipeline batches query: " . $e->getMessage());
    $active_batches_all = [];
}

// Get inventory summary
try {
    $inventory_query = "SELECT location, SUM(quantity) as total_quantity
                       FROM inventory
                       GROUP BY location";
    $inventory_stmt = $db->prepare($inventory_query);
    $inventory_stmt->execute();
    $inventory_summary = [];
    while($row = $inventory_stmt->fetch(PDO::FETCH_ASSOC)) {
        $inventory_summary[$row['location']] = $row['total_quantity'];
    }
} catch (PDOException $e) {
    error_log("Error in inventory summary query: " . $e->getMessage());
    $inventory_summary = [];
}
?>

<!-- Toast Notification Container -->
<div id="db-toastContainer" class="db-toast-container" aria-live="polite"></div>

<!-- Loading Indicator -->
<div id="db-loadingIndicator" class="db-loading-indicator" style="display: none;">
    <div class="db-spinner"></div>
    <p>Loading...</p>
</div>

<div class="db-page-header">
    <h1 class="db-page-title">Dashboard</h1>
    <div class="db-page-actions">
        <div class="db-date-display">
            <i class="fas fa-calendar-alt" aria-hidden="true"></i>
            <span id="db-currentDate"><?php echo date('l, F j, Y'); ?></span>
        </div>
    </div>
</div>

<!-- Quick Stats -->
<section class="db-quick-stats" aria-labelledby="db-quick-stats-heading">
    <h2 id="db-quick-stats-heading" class="db-section-title db-sr-only">Quick Statistics</h2>
    
    <div class="db-stats-grid">
        <!-- Fund Stats -->
        <div class="db-stat-card db-fund-status">
            <div class="db-stat-icon">
                <i class="fas fa-wallet" aria-hidden="true"></i>
            </div>
            <div class="db-stat-content">
                <div class="db-stat-value"><?php echo formatCurrency($fund_status['available']); ?></div>
                <div class="db-stat-label">Available Funds</div>
            </div>
            <a href="funds.php" class="db-card-link" aria-label="View funds details">
                <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
        
        <div class="db-stat-card db-fund-status <?php echo $fund_status['overdraft'] > 0 ? 'db-warning' : ''; ?>">
            <div class="db-stat-icon">
                <i class="fas fa-hand-holding-usd" aria-hidden="true"></i>
            </div>
            <div class="db-stat-content">
                <div class="db-stat-value"><?php echo formatCurrency($fund_status['used']); ?></div>
                <div class="db-stat-label">Used Funds</div>
                <?php if($fund_status['overdraft'] > 0): ?>
                <div class="db-stat-alert">
                    <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                    Overdraft: <?php echo formatCurrency($fund_status['overdraft']); ?>
                </div>
                <?php endif; ?>
            </div>
            <a href="funds.php" class="db-card-link" aria-label="View funds details">
                <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
        
        <!-- Material Stats -->
        <div class="db-stat-card db-material-status <?php echo $materials['low_stock_count'] > 0 ? 'db-warning' : ''; ?>">
            <div class="db-stat-icon">
                <i class="fas fa-boxes" aria-hidden="true"></i>
            </div>
            <div class="db-stat-content">
                <div class="db-stat-value"><?php echo number_format($materials['total_materials']); ?></div>
                <div class="db-stat-label">Raw Materials</div>
                <?php if($materials['low_stock_count'] > 0): ?>
                <div class="db-stat-alert">
                    <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                    <?php echo $materials['low_stock_count']; ?> items low on stock
                </div>
                <?php endif; ?>
            </div>
            <a href="raw-materials.php" class="db-card-link" aria-label="View raw materials">
                <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
        
        <!-- Manufacturing Stats -->
        <div class="db-stat-card db-manufacturing-status">
            <div class="db-stat-icon">
                <i class="fas fa-industry" aria-hidden="true"></i>
            </div>
            <div class="db-stat-content">
                <div class="db-stat-value"><?php echo number_format($batches['active_batches']); ?></div>
                <div class="db-stat-label">Active Batches</div>
                <div class="db-stat-info">
                    <i class="fas fa-check-circle" aria-hidden="true"></i>
                    <?php echo number_format($batches['completed_batches']); ?> completed
                </div>
            </div>
            <a href="manufacturing.php" class="db-card-link" aria-label="View manufacturing batches">
                <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
        
        <!-- Inventory Stats -->
        <div class="db-stat-card db-inventory-status">
            <div class="db-stat-icon">
                <i class="fas fa-warehouse" aria-hidden="true"></i>
            </div>
            <div class="db-stat-content">
                <div class="db-stat-value"><?php echo number_format($inventory_summary['manufacturing'] ?? 0); ?></div>
                <div class="db-stat-label">Items in Manufacturing</div>
                <div class="db-stat-info">
                    <i class="fas fa-truck" aria-hidden="true"></i>
                    <?php echo number_format($inventory_summary['transit'] ?? 0); ?> in transit
                </div>
            </div>
            <a href="inventory.php" class="db-card-link" aria-label="View inventory">
                <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </div>
</section>

<!-- Fund Usage Chart -->
<section class="db-dashboard-section" aria-labelledby="db-fund-usage-heading">
    <div class="db-card">
        <div class="db-card-header">
            <h2 id="db-fund-usage-heading">Fund Usage Overview</h2>
        </div>
        <div class="db-card-content">
            <div class="db-chart-container">
                <canvas id="db-fundUsageChart" height="300" aria-label="Fund usage pie chart" role="img"></canvas>
                <div id="db-fundChartFallback" class="db-chart-fallback" style="display: none;">
                    <p>Chart visualization is not available. Here's a summary of fund usage:</p>
                    <ul class="db-fallback-list">
                        <li>Available Funds: <?php echo formatCurrency($fund_status['available']); ?></li>
                        <li>Used Funds: <?php echo formatCurrency($fund_status['used']); ?></li>
                        <?php if($fund_status['overdraft'] > 0): ?>
                        <li>Overdraft: <?php echo formatCurrency($fund_status['overdraft']); ?></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            <div class="db-chart-legend">
                <div class="db-legend-item">
                    <span class="db-color-box db-available-color" aria-hidden="true"></span>
                    <span>Available Funds</span>
                </div>
                <div class="db-legend-item">
                    <span class="db-color-box db-used-color" aria-hidden="true"></span>
                    <span>Used Funds</span>
                </div>
                <?php if($fund_status['overdraft'] > 0): ?>
                <div class="db-legend-item">
                    <span class="db-color-box db-overdraft-color" aria-hidden="true"></span>
                    <span>Overdraft</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- Manufacturing Pipeline Visualization -->
<section class="db-dashboard-section" aria-labelledby="db-manufacturing-pipeline-heading">
    <div class="db-card db-full-width">
        <div class="db-card-header">
            <h2 id="db-manufacturing-pipeline-heading">Manufacturing Pipeline</h2>
            <a href="manufacturing.php" class="db-view-all">View All</a>
        </div>
        <div class="db-card-content">
            <!-- Advanced Batch Progress Visualization -->
            <div class="db-batch-status-progress">
                <?php 
                // Define statuses array
                $statuses = ['pending', 'cutting', 'stitching', 'ironing', 'packaging', 'completed'];
                
                // Only show progress bar if we have batches
                if (!empty($status_counts)): 
                    $total_batches = array_sum($status_counts);
                    if ($total_batches > 0):
                ?>
                <div class="db-production-pipeline" role="region" aria-label="Manufacturing pipeline visualization">
                    <div class="db-pipeline-container">
                        <div class="db-pipeline-stages">
                            <?php foreach($statuses as $index => $status): ?>
                            <div class="db-pipeline-stage" data-status="<?php echo $status; ?>">
                                <div class="db-stage-header">
                                    <span class="db-stage-name"><?php echo ucfirst($status); ?></span>
                                    <span class="db-stage-count"><?php echo $status_counts[$status] ?? 0; ?></span>
                                </div>
                                <div class="db-stage-content">
                                    <?php
                                    // Group batches by status
                                    $status_batches = array_filter($active_batches_all, function($batch) use ($status) {
                                        return $batch['status'] === $status;
                                    });
                                    
                                    // Sort batches - urgent ones first
                                    usort($status_batches, function($a, $b) {
                                        // Calculate days until expected completion
                                        $a_days = (strtotime($a['expected_completion_date']) - time()) / (60 * 60 * 24);
                                        $b_days = (strtotime($b['expected_completion_date']) - time()) / (60 * 60 * 24);
                                        
                                        // Urgent batches first (less days remaining)
                                        return $a_days <=> $b_days;
                                    });
                                    
                                    // Display batch balloons (limited to 3 per stage for dashboard)
                                    $count = 0;
                                    foreach($status_batches as $batch_index => $batch):
                                        if ($count >= 3) break; // Limit to 3 balloons per stage
                                        $count++;
                                        
                                        // Calculate if batch is urgent
                                        $days_remaining = (strtotime($batch['expected_completion_date']) - time()) / (60 * 60 * 24);
                                        $urgency_class = '';
                                        $urgency_text = '';
                                        
                                        // Define urgency based on status and days remaining
                                        if ($days_remaining < 0) {
                                            // Past due date
                                            $urgency_class = 'db-batch-overdue';
                                            $urgency_text = 'Overdue by ' . abs(round($days_remaining, 1)) . ' days';
                                        } elseif ($days_remaining < 3) {
                                            // Less than 3 days remaining
                                            $status_index = array_search($batch['status'], $statuses);
                                            $stages_remaining = count($statuses) - $status_index - 1;
                                            
                                            // If many stages remaining but little time
                                            if ($stages_remaining > 1 && $days_remaining < 2) {
                                                $urgency_class = 'db-batch-urgent';
                                                $urgency_text = 'Urgent: ' . round($days_remaining, 1) . ' days left';
                                            } elseif ($stages_remaining > 0) {
                                                $urgency_class = 'db-batch-warning';
                                                $urgency_text = 'Due soon: ' . round($days_remaining, 1) . ' days left';
                                            }
                                        }
                                        
                                        // Generate a unique color based on batch ID
                                        $color_index = $batch['id'] % 8; // 8 different colors
                                        $color_class = 'db-batch-color-' . $color_index;
                                    ?>
                                    <button type="button" 
                                            class="db-batch-balloon <?php echo $color_class . ' ' . $urgency_class; ?>"
                                            aria-label="Batch <?php echo htmlspecialchars($batch['batch_number']); ?>: <?php echo htmlspecialchars($batch['product_name']); ?><?php echo !empty($urgency_text) ? '. ' . $urgency_text : ''; ?>"
                                            data-batch-id="<?php echo $batch['id']; ?>"
                                            data-batch-number="<?php echo htmlspecialchars($batch['batch_number']); ?>"
                                            data-product-name="<?php echo htmlspecialchars($batch['product_name']); ?>"
                                            data-quantity="<?php echo $batch['quantity_produced']; ?>"
                                            data-start-date="<?php echo htmlspecialchars($batch['start_date']); ?>"
                                            data-expected-date="<?php echo htmlspecialchars($batch['expected_completion_date']); ?>"
                                            data-days-remaining="<?php echo round($days_remaining, 1); ?>"
                                            data-status="<?php echo $batch['status']; ?>">
                                        <span class="db-batch-label"><?php echo htmlspecialchars($batch['batch_number']); ?></span>
                                        <?php if (!empty($urgency_class)): ?>
                                        <span class="db-batch-alert" aria-hidden="true"><i class="fas fa-exclamation-triangle"></i></span>
                                        <?php endif; ?>
                                    </button>
                                    <?php endforeach; ?>
                                    
                                    <?php if (count($status_batches) > 3): ?>
                                    <a href="manufacturing.php?status=<?php echo $status; ?>" class="db-more-link" aria-label="View <?php echo count($status_batches) - 3; ?> more <?php echo ucfirst($status); ?> batches">
                                        +<?php echo count($status_batches) - 3; ?> more
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if (empty($status_batches)): ?>
                                    <div class="db-empty-stage">
                                        <span class="db-empty-message">No batches</span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <?php else: ?>
                <div class="db-empty-progress-message">
                    No manufacturing batches available.
                </div>
                <?php endif; else: ?>
                <div class="db-empty-progress-message">
                    No manufacturing batches available.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- Batch Detail Popup -->
<div id="db-batchDetailPopup" class="db-batch-detail-popup" role="dialog" aria-labelledby="db-popupBatchNumber" aria-modal="true" tabindex="-1" style="display: none;">
    <div class="db-popup-content">
        <div class="db-popup-header">
            <h3 id="db-popupBatchNumber"></h3>
            <button type="button" class="db-close-popup" aria-label="Close popup">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <div class="db-popup-body">
            <div class="db-detail-row">
                <span class="db-detail-label">Product:</span>
                <span id="db-popupProductName" class="db-detail-value"></span>
            </div>
            <div class="db-detail-row">
                <span class="db-detail-label">Quantity:</span>
                <span id="db-popupQuantity" class="db-detail-value"></span>
            </div>
            <div class="db-detail-row">
                <span class="db-detail-label">Start Date:</span>
                <span id="db-popupStartDate" class="db-detail-value"></span>
            </div>
            <div class="db-detail-row">
                <span class="db-detail-label">Expected Completion:</span>
                <span id="db-popupExpectedDate" class="db-detail-value"></span>
            </div>
            <div class="db-detail-row">
                <span class="db-detail-label">Time Remaining:</span>
                <span id="db-popupTimeRemaining" class="db-detail-value"></span>
            </div>
            <div class="db-detail-row">
                <span class="db-detail-label">Status:</span>
                <span id="db-popupStatus" class="db-detail-value"></span>
            </div>
        </div>
        <div class="db-popup-actions">
            <a id="db-popupViewLink" href="#" class="db-button db-small">
                <i class="fas fa-eye" aria-hidden="true"></i> View Details
            </a>
            <a id="db-popupUpdateLink" href="#" class="db-button db-primary db-small">
                <i class="fas fa-edit" aria-hidden="true"></i> Update Status
            </a>
        </div>
    </div>
</div>

<div class="db-dashboard-grid">
    <!-- Recent Material Purchases -->
    <div class="db-card">
        <div class="db-card-header">
            <h2>Recent Material Purchases</h2>
            <a href="purchases.php" class="db-view-all">View All</a>
        </div>
        <div class="db-card-content">
            <div class="db-table-responsive">
                <table class="db-data-table" aria-label="Recent Material Purchases">
                    <thead>
                        <tr>
                            <th scope="col">Material</th>
                            <th scope="col">Quantity</th>
                            <th scope="col">Amount</th>
                            <th scope="col">Vendor</th>
                            <th scope="col">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($purchases_stmt && $purchases_stmt->rowCount() > 0): ?>
                            <?php while($purchase = $purchases_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                            <tr>
                                <td data-label="Material"><?php echo htmlspecialchars($purchase['material_name']); ?></td>
                                <td data-label="Quantity"><?php echo number_format($purchase['quantity'], 2); ?></td>
                                <td data-label="Amount" class="db-amount-cell"><?php echo formatCurrency($purchase['total_amount']); ?></td>
                                <td data-label="Vendor"><?php echo htmlspecialchars($purchase['vendor_name']); ?></td>
                                <td data-label="Date"><?php echo date('M j, Y', strtotime($purchase['purchase_date'])); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="db-no-data">No recent purchases found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Low Stock Materials -->
    <div class="db-card">
        <div class="db-card-header">
            <h2>Low Stock Materials</h2>
            <a href="raw-materials.php" class="db-view-all">View All</a>
        </div>
        <div class="db-card-content">
            <div class="db-table-responsive">
                <table class="db-data-table" aria-label="Low Stock Materials">
                    <thead>
                        <tr>
                            <th scope="col">Material</th>
                            <th scope="col">Current Stock</th>
                            <th scope="col">Min Level</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($low_stock_stmt && $low_stock_stmt->rowCount() > 0): ?>
                            <?php while($material = $low_stock_stmt->fetch(PDO::FETCH_ASSOC)): 
                                $stock_ratio = $material['stock_quantity'] / $material['min_stock_level'];
                                $status_class = $stock_ratio <= 0.25 ? 'db-critical' : ($stock_ratio <= 0.5 ? 'db-warning' : 'db-low');
                            ?>
                            <tr>
                                <td data-label="Material"><?php echo htmlspecialchars($material['name']); ?></td>
                                <td data-label="Current Stock" class="db-stock-level <?php echo $status_class; ?>">
                                    <?php echo number_format($material['stock_quantity'], 2); ?> <?php echo htmlspecialchars($material['unit']); ?>
                                </td>
                                <td data-label="Min Level"><?php echo number_format($material['min_stock_level'], 2); ?> <?php echo htmlspecialchars($material['unit']); ?></td>
                                <td data-label="Action">
                                    <a href="add-purchase.php?material_id=<?php echo $material['id']; ?>" class="db-button db-small db-success">
                                        <i class="fas fa-shopping-cart" aria-hidden="true"></i> Purchase
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                                                        <tr>
                                <td colspan="4" class="db-no-data">No materials are low on stock</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Active Manufacturing Batches -->
    <div class="db-card db-full-width">
        <div class="db-card-header">
            <h2>Active Manufacturing Batches</h2>
            <a href="manufacturing.php" class="db-view-all">View All</a>
        </div>
        <div class="db-card-content">
            <div class="db-table-responsive">
                <table class="db-data-table" aria-label="Active Manufacturing Batches">
                    <thead>
                        <tr>
                            <th scope="col">Batch #</th>
                            <th scope="col">Product</th>
                            <th scope="col">Quantity</th>
                            <th scope="col">Status</th>
                            <th scope="col">Expected Completion</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Reset the statement to fetch results again
                        if($active_batches_stmt): 
                            $active_batches_stmt->execute();
                            if($active_batches_stmt->rowCount() > 0):
                                while($batch = $active_batches_stmt->fetch(PDO::FETCH_ASSOC)): 
                                    // Calculate days until expected completion
                                    $days_remaining = (strtotime($batch['expected_completion_date']) - time()) / (60 * 60 * 24);
                                    $days_class = '';
                                    
                                    if ($days_remaining < 0) {
                                        $days_class = 'db-overdue';
                                    } elseif ($days_remaining < 3) {
                                        $days_class = 'db-urgent';
                                    } elseif ($days_remaining < 7) {
                                        $days_class = 'db-warning';
                                    }
                        ?>
                        <tr>
                            <td data-label="Batch #"><?php echo htmlspecialchars($batch['batch_number']); ?></td>
                            <td data-label="Product"><?php echo htmlspecialchars($batch['product_name']); ?></td>
                            <td data-label="Quantity"><?php echo number_format($batch['quantity_produced']); ?></td>
                            <td data-label="Status">
                                <span class="db-status-badge db-status-<?php echo $batch['status']; ?>"><?php echo ucfirst($batch['status']); ?></span>
                            </td>
                            <td data-label="Expected Completion" class="<?php echo $days_class; ?>">
                                <?php echo date('M j, Y', strtotime($batch['expected_completion_date'])); ?>
                                <?php if ($days_remaining < 0): ?>
                                    <span class="db-days-indicator">
                                        <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
                                        <?php echo abs(round($days_remaining)); ?> days overdue
                                    </span>
                                <?php elseif ($days_remaining < 7): ?>
                                    <span class="db-days-indicator">
                                        <i class="fas fa-clock" aria-hidden="true"></i>
                                        <?php echo round($days_remaining); ?> days left
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Actions">
                                <div class="db-action-buttons">
                                    <a href="view-batch.php?id=<?php echo $batch['id']; ?>" class="db-button db-small">
                                        <i class="fas fa-eye" aria-hidden="true"></i> View
                                    </a>
                                    <a href="update-batch.php?id=<?php echo $batch['id']; ?>" class="db-button db-small db-primary">
                                        <i class="fas fa-edit" aria-hidden="true"></i> Update
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                            <tr>
                                <td colspan="6" class="db-no-data">No active batches found</td>
                            </tr>
                        <?php endif; else: ?>
                            <tr>
                                <td colspan="6" class="db-no-data">Error loading active batches</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="db-current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<style>
/* Dashboard Styles with Namespaced Classes */
:root {
  --db-primary: #4361ee;
  --db-primary-dark: #3a56d4;
  --db-primary-light: #eef2ff;
  --db-success: #2ec4b6;
  --db-success-dark: #21a99d;
  --db-warning: #ff9f1c;
  --db-warning-dark: #e58e19;
  --db-danger: #e63946;
  --db-danger-dark: #d33241;
  --db-text-primary: #212529;
  --db-text-secondary: #6c757d;
  --db-border: #dee2e6;
  --db-background: #f8f9fa;
  --db-surface: #ffffff;
  --db-shadow-sm: 0 2px 4px rgba(0, 0, 0, 0.08);
  --db-shadow-md: 0 4px 8px rgba(0, 0, 0, 0.12);
  --db-radius-sm: 4px;
  --db-radius-md: 8px;
  --db-transition: all 0.2s ease-in-out;
  
  /* Status Colors */
  --db-status-pending: #ff9f1c;
  --db-status-cutting: #4361ee;
  --db-status-stitching: #673ab7;
  --db-status-ironing: #e63946;
  --db-status-packaging: #ff7043;
  --db-status-completed: #2ec4b6;
}

/* Page Header */
.db-page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
}

.db-page-title {
  margin: 0;
  font-size: 1.75rem;
  color: var(--db-text-primary);
}

.db-page-actions {
  display: flex;
  gap: 0.75rem;
}

.db-date-display {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
  color: var(--db-text-secondary);
  background-color: var(--db-surface);
  padding: 0.5rem 1rem;
  border-radius: 2rem;
  box-shadow: var(--db-shadow-sm);
}

.db-date-display i {
  color: var(--db-primary);
}

/* Accessibility */
.db-sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border-width: 0;
}

/* Quick Stats Section */
.db-quick-stats {
  margin-bottom: 1.5rem;
}

.db-stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 1.5rem;
}

.db-stat-card {
  background-color: var(--db-surface);
  border-radius: var(--db-radius-md);
  box-shadow: var(--db-shadow-sm);
  padding: 1.5rem;
  display: flex;
  align-items: center;
  gap: 1.25rem;
  transition: var(--db-transition);
  position: relative;
  overflow: hidden;
}

.db-stat-card::after {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  width: 6px;
  height: 100%;
  opacity: 0.8;
}

.db-stat-card.db-fund-status::after {
  background-color: var(--db-primary);
}

.db-stat-card.db-material-status::after {
  background-color: var(--db-success);
}

.db-stat-card.db-manufacturing-status::after {
  background-color: var(--db-warning);
}

.db-stat-card.db-inventory-status::after {
  background-color: var(--db-primary);
}

.db-stat-card.db-warning::after {
  background-color: var(--db-warning);
}

.db-stat-card:hover {
  transform: translateY(-3px);
  box-shadow: var(--db-shadow-md);
}

.db-stat-icon {
  width: 50px;
  height: 50px;
  border-radius: var(--db-radius-md);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.5rem;
  color: white;
}

.db-stat-card.db-fund-status .db-stat-icon {
  background-color: var(--db-primary);
}

.db-stat-card.db-material-status .db-stat-icon {
  background-color: var(--db-success);
}

.db-stat-card.db-manufacturing-status .db-stat-icon {
  background-color: var(--db-warning);
}

.db-stat-card.db-inventory-status .db-stat-icon {
  background-color: var(--db-primary);
}

.db-stat-card.db-warning .db-stat-icon {
  background-color: var(--db-warning);
}

.db-stat-content {
  flex: 1;
}

.db-stat-value {
  font-size: 1.5rem;
  font-weight: 600;
  color: var(--db-text-primary);
  margin-bottom: 0.25rem;
}

.db-stat-label {
  font-size: 0.9rem;
  color: var(--db-text-secondary);
  margin-bottom: 0.5rem;
}

.db-stat-alert {
  font-size: 0.75rem;
  color: var(--db-warning-dark);
  display: flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.25rem 0.5rem;
  background-color: rgba(255, 159, 28, 0.1);
  border-radius: 1rem;
  max-width: fit-content;
}

.db-stat-info {
  font-size: 0.75rem;
  color: var(--db-text-secondary);
  display: flex;
  align-items: center;
  gap: 0.25rem;
}

.db-card-link {
  position: absolute;
  top: 1rem;
  right: 1rem;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background-color: var(--db-background);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--db-text-secondary);
  transition: var(--db-transition);
}

.db-card-link:hover {
  background-color: var(--db-primary-light);
  color: var(--db-primary);
  transform: scale(1.1);
  text-decoration: none;
}

/* Dashboard Sections */
.db-dashboard-section {
  margin-bottom: 1.5rem;
}

/* Dashboard Grid */
.db-dashboard-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
  gap: 1.5rem;
  margin-bottom: 1.5rem;
}

.db-dashboard-grid .db-card.db-full-width {
  grid-column: 1 / -1;
}

/* Card Component */
.db-card {
  background-color: var(--db-surface);
  border-radius: var(--db-radius-md);
  box-shadow: var(--db-shadow-sm);
  margin-bottom: 1.5rem;
  overflow: hidden;
  transition: box-shadow var(--db-transition);
}

.db-card:hover {
  box-shadow: var(--db-shadow-md);
}

.db-card.db-full-width {
  width: 100%;
}

.db-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1.25rem 1.5rem;
  background-color: var(--db-primary-light);
  border-bottom: 1px solid var(--db-border);
}

.db-card-header h2, .db-card-header h3 {
  margin: 0;
  font-size: 1.25rem;
  color: var(--db-primary);
}

.db-card-content {
  padding: 1.5rem;
}

/* Chart Container */
.db-chart-container {
  position: relative;
  height: 300px;
  margin-bottom: 1rem;
}

.db-chart-legend {
  display: flex;
  justify-content: center;
  gap: 1.5rem;
  flex-wrap: wrap;
}

.db-legend-item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.db-color-box {
  width: 16px;
  height: 16px;
  border-radius: 3px;
}

.db-available-color {
  background-color: #4CAF50;
}

.db-used-color {
  background-color: #2196F3;
}

.db-overdraft-color {
  background-color: #F44336;
}

/* Chart fallback */
.db-chart-fallback {
  padding: 1.5rem;
  background-color: var(--db-background);
  border-radius: var(--db-radius-md);
  border: 1px dashed var(--db-border);
}

.db-fallback-list {
  padding-left: 1.5rem;
  margin-top: 1rem;
  margin-bottom: 0;
}

.db-fallback-list li {
  margin-bottom: 0.5rem;
}

/* View All Link */
.db-view-all {
  color: var(--db-primary);
  text-decoration: none;
  font-size: 0.875rem;
  font-weight: 500;
  display: flex;
  align-items: center;
  gap: 0.25rem;
  transition: color 0.15s ease;
}

.db-view-all:hover {
  text-decoration: underline;
  color: var(--db-primary-dark);
}

.db-view-all::after {
  content: '→';
  font-size: 1rem;
  transition: transform 0.15s ease;
}

.db-view-all:hover::after {
  transform: translateX(3px);
}

/* Manufacturing Pipeline Styles */
.db-production-pipeline {
  background-color: var(--db-surface);
  border-radius: var(--db-radius-md);
  padding: 0.5rem;
  margin-bottom: 1rem;
  overflow: hidden;
}

.db-pipeline-container {
  position: relative;
  padding: 1rem 0;
}

.db-pipeline-stages {
  display: flex;
  justify-content: space-between;
  position: relative;
  min-height: 140px;
}

/* Add a connecting line between stages */
.db-pipeline-stages::before {
  content: '';
  position: absolute;
  top: 30px;
  left: 0;
  right: 0;
  height: 4px;
  background: linear-gradient(to right, 
      var(--db-status-pending) calc(100%/6), 
      var(--db-status-cutting) calc(100%/6), 
      var(--db-status-cutting) calc(100%/3), 
      var(--db-status-stitching) calc(100%/3), 
      var(--db-status-stitching) calc(100%/2), 
      var(--db-status-ironing) calc(100%/2), 
      var(--db-status-ironing) calc(2*100%/3), 
      var(--db-status-packaging) calc(2*100%/3), 
      var(--db-status-packaging) calc(5*100%/6), 
      var(--db-status-completed) calc(5*100%/6), 
      var(--db-status-completed) 100%);
  z-index: 1;
}

.db-pipeline-stage {
  flex: 1;
  display: flex;
  flex-direction: column;
  position: relative;
  z-index: 2;
  padding: 0 0.5rem;
}

.db-stage-header {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-bottom: 1rem;
  position: relative;
}

.db-stage-header::before {
  content: '';
  width: 16px;
  height: 16px;
  border-radius: 50%;
  position: absolute;
  top: -26px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 3;
}

.db-pipeline-stage[data-status="pending"] .db-stage-header::before { background-color: var(--db-status-pending); }
.db-pipeline-stage[data-status="cutting"] .db-stage-header::before { background-color: var(--db-status-cutting); }
.db-pipeline-stage[data-status="stitching"] .db-stage-header::before { background-color: var(--db-status-stitching); }
.db-pipeline-stage[data-status="ironing"] .db-stage-header::before { background-color: var(--db-status-ironing); }
.db-pipeline-stage[data-status="packaging"] .db-stage-header::before { background-color: var(--db-status-packaging); }
.db-pipeline-stage[data-status="completed"] .db-stage-header::before { background-color: var(--db-status-completed); }

.db-stage-name {
  font-weight: 500;
  font-size: 0.875rem;
  margin-bottom: 0.25rem;
}

.db-stage-count {
  font-size: 0.75rem;
  color: var(--db-text-secondary);
  background-color: var(--db-background);
  padding: 0.1rem 0.4rem;
  border-radius: 10px;
}

.db-stage-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  min-height: 80px;
}

/* Batch balloon styles */
.db-batch-balloon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  border-radius: 50%;
  position: relative;
  cursor: pointer;
  box-shadow: var(--db-shadow-sm);
  transition: transform 0.2s, box-shadow 0.2s;
  font-size: 0.75rem;
  font-weight: 500;
  color: white;
  text-align: center;
  border: none;
  background-color: var(--db-primary);
}

.db-batch-balloon:hover, .db-batch-balloon:focus {
  transform: scale(1.1);
  box-shadow: var(--db-shadow-md);
  z-index: 10;
  outline: none;
}

/* Batch color variations */
.db-batch-color-0 { background-color: #4285f4; }
.db-batch-color-1 { background-color: #34a853; }
.db-batch-color-2 { background-color: #ea4335; }
.db-batch-color-3 { background-color: #fbbc04; }
.db-batch-color-4 { background-color: #673ab7; }
.db-batch-color-5 { background-color: #ff7043; }
.db-batch-color-6 { background-color: #03a9f4; }
.db-batch-color-7 { background-color: #8bc34a; }

/* Urgency indicators */
.db-batch-warning {
  border: 2px solid #fbbc04;
  animation: db-pulse-warning 2s infinite;
}

.db-batch-urgent {
  border: 3px solid #ea4335;
  animation: db-pulse-urgent 1.5s infinite;
}

.db-batch-urgent:hover {
  transform: scale(1.2);
}

.db-batch-overdue {
  border: 3px solid #ea4335;
  background-image: repeating-linear-gradient(
      45deg,
      rgba(0, 0, 0, 0),
      rgba(0, 0, 0, 0) 10px,
      rgba(234, 67, 53, 0.2) 10px,
      rgba(234, 67, 53, 0.2) 20px
  );
  animation: db-pulse-urgent 1.5s infinite;
}

.db-batch-overdue:hover {
  transform: scale(1.2);
}

@keyframes db-pulse-warning {
  0% { box-shadow: 0 0 0 0 rgba(251, 188, 4, 0.4); }
  70% { box-shadow: 0 0 0 6px rgba(251, 188, 4, 0); }
  100% { box-shadow: 0 0 0 0 rgba(251, 188, 4, 0); }
}

@keyframes db-pulse-urgent {
  0% { box-shadow: 0 0 0 0 rgba(234, 67, 53, 0.4); }
  70% { box-shadow: 0 0 0 8px rgba(234, 67, 53, 0); }
  100% { box-shadow: 0 0 0 0 rgba(234, 67, 53, 0); }
}

.db-batch-label {
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  padding: 0 4px;
  font-size: 0.7rem;
}

.db-batch-alert {
  position: absolute;
  top: -5px;
  right: -5px;
  background-color: #ea4335;
  color: white;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.6rem;
  border: 1px solid white;
}

/* Empty stage styles */
.db-empty-stage {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 60px;
  padding: 0.5rem;
}

.db-empty-message {
  font-size: 0.875rem;
  color: var(--db-text-secondary);
  font-style: italic;
}

/* More batches link */
.db-more-link {
  font-size: 0.75rem;
  color: var(--db-primary);
  text-decoration: none;
  padding: 3px 8px;
  border-radius: 12px;
  background-color: rgba(67, 97, 238, 0.1);
  transition: background-color 0.15s ease;
}

.db-more-link:hover {
  background-color: rgba(67, 97, 238, 0.2);
  text-decoration: underline;
}

/* Empty progress message */
.db-empty-progress-message {
  text-align: center;
  padding: 1.5rem;
  color: var(--db-text-secondary);
  background-color: var(--db-background);
  border-radius: var(--db-radius-md);
  font-style: italic;
}

/* Batch Detail Popup */
.db-batch-detail-popup {
  position: absolute;
  display: none;
  width: 320px;
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  border-radius: var(--db-radius-md);
  box-shadow: var(--db-shadow-md);
  border: 1px solid rgba(255, 255, 255, 0.18);
  overflow: hidden;
  z-index: 100;
  animation: db-popup-float-in 0.3s ease-out;
  transform-origin: top center;
}

@keyframes db-popup-float-in {
  from { 
    opacity: 0; 
    transform: translateY(10px) scale(0.95); 
  }
  to { 
    opacity: 1; 
    transform: translateY(0) scale(1); 
  }
}

.db-popup-content {
  display: flex;
  flex-direction: column;
}

.db-popup-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem;
  background-color: var(--db-primary-light);
  border-bottom: 1px solid var(--db-border);
}

.db-popup-header h3 {
  margin: 0;
  font-size: 1.125rem;
  color: var(--db-primary);
}

.db-close-popup {
  background: none;
  border: none;
  color: var(--db-text-secondary);
  font-size: 1.25rem;
  line-height: 1;
  cursor: pointer;
  transition: var(--db-transition);
  width: 28px;
  height: 28px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
}

.db-close-popup:hover {
  background-color: rgba(0, 0, 0, 0.1);
  color: var(--db-text-primary);
}

.db-popup-body {
  padding: 1rem;
}

.db-detail-row {
  display: flex;
  margin-bottom: 0.5rem;
}

.db-detail-row:last-child {
  margin-bottom: 0;
}

.db-detail-label {
  width: 45%;
  font-weight: 500;
  color: var(--db-text-secondary);
  font-size: 0.875rem;
}

.db-detail-value {
  width: 55%;
  font-size: 0.875rem;
  color: var(--db-text-primary);
}

.db-popup-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 1rem;
  background-color: var(--db-background);
  border-top: 1px solid var(--db-border);
}

/* Table Styles */
.db-table-responsive {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  margin-bottom: 1rem;
}

.db-data-table {
  width: 100%;
  border-collapse: collapse;
  border-spacing: 0;
}

.db-data-table th,
.db-data-table td {
  padding: 0.75rem 1rem;
  text-align: left;
  border-bottom: 1px solid var(--db-border);
}

.db-data-table th {
  background-color: var(--db-background);
  font-weight: 600;
  color: var(--db-text-secondary);
  white-space: nowrap;
}

.db-data-table tbody tr:hover {
  background-color: var(--db-primary-light);
}

.db-amount-cell {
  font-weight: 500;
  text-align: right;
}

.db-no-data {
  text-align: center;
  padding: 2rem;
  color: var(--db-text-secondary);
  font-style: italic;
  background-color: var(--db-background);
}

/* Status Badge */
.db-status-badge {
  display: inline-block;
  padding: 0.25rem 0.75rem;
  border-radius: 50px;
  font-size: 0.8rem;
  font-weight: 500;
  text-align: center;
  white-space: nowrap;
}

.db-status-pending { 
  background-color: #fff8e1; 
  color: #f57f17; 
}

.db-status-cutting { 
  background-color: #e3f2fd; 
  color: #0d47a1; 
}

.db-status-stitching { 
  background-color: #ede7f6; 
  color: #4527a0; 
}

.db-status-ironing { 
  background-color: #fce4ec; 
  color: #880e4f; 
}

.db-status-packaging { 
  background-color: #fff3e0; 
  color: #e65100; 
}

.db-status-completed { 
  background-color: #e8f5e9; 
  color: #1b5e20; 
}

.db-status-active {
  background-color: #d1e7dd;
  color: #0f5132;
}

.db-status-depleted {
  background-color: #f8d7da;
  color: #842029;
}

.db-status-returned {
  background-color: #cfe2ff;
  color: #084298;
}
/* Status Colors */
.db-overdue {
  color: var(--db-danger);
}

.db-urgent {
  color: var(--db-warning-dark);
}

.db-warning {
  color: var(--db-warning);
}

.db-days-indicator {
  display: block;
  font-size: 0.75rem;
  margin-top: 0.25rem;
}

.db-stock-level {
  font-weight: 500;
}

.db-stock-level.db-critical {
  color: var(--db-danger);
}

.db-stock-level.db-warning {
  color: var(--db-warning-dark);
}

.db-stock-level.db-low {
  color: var(--db-warning);
}

/* Action Buttons */
.db-action-buttons {
  display: flex;
  gap: 0.25rem;
}

/* Button Styles */
.db-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  border-radius: var(--db-radius-sm);
  font-weight: 500;
  cursor: pointer;
  transition: var(--db-transition);
  border: none;
  text-decoration: none;
  font-size: 0.9rem;
}

.db-button.db-primary {
  background-color: var(--db-primary);
  color: white;
}

.db-button.db-primary:hover, .db-button.db-primary:focus {
  background-color: var(--db-primary-dark);
  box-shadow: var(--db-shadow-sm);
}

.db-button.db-secondary {
  background-color: var(--db-background);
  color: var(--db-text-secondary);
  border: 1px solid var(--db-border);
}

.db-button.db-secondary:hover, .db-button.db-secondary:focus {
  background-color: #eaecef;
}

.db-button.db-success {
  background-color: var(--db-success);
  color: white;
}

.db-button.db-success:hover, .db-button.db-success:focus {
  background-color: var(--db-success-dark);
}

.db-button.db-small {
  padding: 0.25rem 0.5rem;
  font-size: 0.8rem;
}

.db-button:disabled {
  opacity: 0.65;
  cursor: not-allowed;
}

/* Toast Notification Styles */
.db-toast-container {
  position: fixed;
  top: 1rem;
  right: 1rem;
  z-index: 1100;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  max-width: 350px;
}

.db-toast {
  background-color: var(--db-surface);
  border-radius: var(--db-radius-sm);
  box-shadow: var(--db-shadow-md);
  padding: 1rem;
  animation: db-toastFadeIn 0.3s ease-out;
  border-left: 4px solid var(--db-primary);
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
}

.db-toast.db-success {
  border-left-color: var(--db-success);
}

.db-toast.db-warning {
  border-left-color: var(--db-warning);
}

.db-toast.db-error {
  border-left-color: var(--db-danger);
}

.db-toast-icon {
  font-size: 1.25rem;
  margin-top: 0.125rem;
}

.db-toast-content {
  flex: 1;
}

.db-toast-title {
  font-weight: 600;
  margin-bottom: 0.25rem;
}

.db-toast-message {
  color: var(--db-text-secondary);
  font-size: 0.9rem;
}

.db-toast-close {
  background: none;
  border: none;
  color: var(--db-text-secondary);
  cursor: pointer;
  font-size: 1rem;
  padding: 0.25rem;
  display: flex;
  align-items: center;
  justify-content: center;
}

.db-toast.db-success .db-toast-icon {
  color: var(--db-success);
}

.db-toast.db-warning .db-toast-icon {
  color: var(--db-warning);
}

.db-toast.db-error .db-toast-icon {
  color: var(--db-danger);
}

@keyframes db-toastFadeIn {
  from { opacity: 0; transform: translateX(20px); }
  to { opacity: 1; transform: translateX(0); }
}

/* Loading Indicator */
.db-loading-indicator {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background-color: rgba(0, 0, 0, 0.5);
  z-index: 2000;
  color: white;
}

.db-spinner {
  width: 50px;
  height: 50px;
  border: 5px solid rgba(255, 255, 255, 0.3);
  border-radius: 50%;
  border-top-color: white;
  animation: db-spin 1s linear infinite;
  margin-bottom: 1rem;
}

@keyframes db-spin {
  to { transform: rotate(360deg); }
}

/* Responsive Adjustments */
@media (max-width: 992px) {
  .db-dashboard-grid {
    grid-template-columns: 1fr;
  }
  
  .db-pipeline-stages {
    overflow-x: auto;
    justify-content: flex-start;
    padding-bottom: 0.5rem;
    -webkit-overflow-scrolling: touch;
  }
  
  .db-pipeline-stage {
    min-width: 100px;
    flex-shrink: 0;
  }
  
  .db-pipeline-container::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 30px;
    height: 100%;
    background: linear-gradient(to right, rgba(255,255,255,0), rgba(255,255,255,0.8));
    pointer-events: none;
  }
}

@media (max-width: 768px) {
  .db-page-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 1rem;
  }
  
  .db-stats-grid {
    grid-template-columns: 1fr;
  }
  
  .db-chart-legend {
    flex-direction: column;
    align-items: center;
  }
  
  .db-action-buttons {
    flex-direction: column;
  }
  
  .db-action-buttons .db-button {
    width: 100%;
    margin-bottom: 0.25rem;
  }
  
  .db-popup-actions {
    flex-direction: column;
  }
  
  .db-popup-actions .db-button {
    width: 100%;
  }
  
  /* Responsive tables */
  .db-data-table {
    border: 0;
  }
  
  .db-data-table thead {
    border: none;
    clip: rect(0 0 0 0);
    height: 1px;
    margin: -1px;
    overflow: hidden;
    padding: 0;
    position: absolute;
    width: 1px;
  }
  
  .db-data-table tr {
    border-bottom: 3px solid var(--db-border);
    display: block;
    margin-bottom: 0.625rem;
  }
  
  .db-data-table td {
    border-bottom: 1px solid var(--db-border);
    display: block;
    font-size: 0.875rem;
    text-align: right;
    position: relative;
    padding-left: 50%;
  }
  
  .db-data-table td::before {
    content: attr(data-label);
    position: absolute;
    left: 0.75rem;
    width: 45%;
    padding-right: 10px;
    white-space: nowrap;
    text-align: left;
    font-weight: 500;
    color: var(--db-text-secondary);
  }
  
  .db-data-table td.db-amount-cell {
    text-align: right;
  }
}

@media (max-width: 576px) {
  .db-date-display {
    width: 100%;
    justify-content: center;
  }
  
  .db-popup-content {
    width: calc(100vw - 40px);
    max-width: 320px;
  }
}

/* Accessibility Enhancements */
@media (prefers-reduced-motion: reduce) {
  *, ::before, ::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
    scroll-behavior: auto !important;
  }
  
  .db-stat-card:hover {
    transform: none;
  }
  
  .db-batch-balloon, 
  .db-batch-warning, 
  .db-batch-urgent, 
  .db-batch-overdue,
  .db-card-link:hover {
    animation: none !important;
    transition: none !important;
    transform: none !important;
  }
  
  .db-batch-detail-popup {
    animation: none !important;
  }
  
  .db-view-all:hover::after {
    transform: none;
  }
}

/* Focus styles for keyboard navigation */
button:focus,
a:focus,
input:focus,
select:focus,
textarea:focus,
.db-batch-balloon:focus,
.db-close-popup:focus,
[tabindex]:focus {
  outline: 3px solid rgba(67, 97, 238, 0.5);
  outline-offset: 2px;
}

button:focus:not(:focus-visible),
a:focus:not(:focus-visible),
input:focus:not(:focus-visible),
select:focus:not(:focus-visible),
textarea:focus:not(:focus-visible),
.db-batch-balloon:focus:not(:focus-visible),
.db-close-popup:focus:not(:focus-visible),
[tabindex]:focus:not(:focus-visible) {
  outline: none;
}

/* Print styles */
@media print {
  .db-page-actions,
  .db-card-link,
  .db-view-all,
  .db-action-buttons,
  .db-batch-balloon,
  .db-batch-detail-popup,
  .db-toast-container,
  .db-loading-indicator {
    display: none !important;
  }
  
  .db-card {
    box-shadow: none;
    border: 1px solid #ccc;
    break-inside: avoid;
    page-break-inside: avoid;
  }
  
  .db-production-pipeline {
    display: none;
  }
  
  .db-stat-card {
    break-inside: avoid;
    page-break-inside: avoid;
    box-shadow: none;
    border: 1px solid #ccc;
  }
  
  .db-stat-card:hover {
    transform: none;
    box-shadow: none;
  }
  
  .db-data-table th {
    background-color: #f1f3f4 !important;
    color: black !important;
  }
  
  body {
    font-size: 12pt;
    background-color: white;
  }
}
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>
<script src="../assets/js/utils.js"></script>
<script>
/**
 * Dashboard JavaScript
 * Provides interactive features for the dashboard with focus on accessibility,
 * error handling, and user experience
 */
document.addEventListener('DOMContentLoaded', function() {
  // Initialize components
  initFundUsageChart();
  initBatchPopup();
  makeTablesResponsive();
  
  // Log page view
  logUserActivity('read', 'dashboard', 'Viewed dashboard');
  
  /**
   * Initialize fund usage chart with error handling and accessibility
   */
  function initFundUsageChart() {
    const chartCanvas = document.getElementById('db-fundUsageChart');
    const chartFallback = document.getElementById('db-fundChartFallback');
    
    if (!chartCanvas) return;
    
    // Check if Chart.js is available
    if (typeof Chart === 'undefined') {
      console.error('Chart.js library not loaded');
      if (chartFallback) {
        chartFallback.style.display = 'block';
      }
      chartCanvas.style.display = 'none';
      return;
    }
    
    try {
      // Get fund data from the page
      const availableFunds = parseFloat(document.querySelector('.db-fund-status:first-child .db-stat-value').textContent.replace(/[^\d.-]/g, '')) || 0;
      const usedFunds = parseFloat(document.querySelector('.db-fund-status:nth-child(2) .db-stat-value').textContent.replace(/[^\d.-]/g, '')) || 0;
      
      // Check if there's an overdraft alert
      const overdraftElement = document.querySelector('.db-fund-status .db-stat-alert');
      let overdraft = 0;
      
      if (overdraftElement) {
        overdraft = parseFloat(overdraftElement.textContent.replace(/[^\d.-]/g, '')) || 0;
      }
      
      // Create the chart
      new Chart(chartCanvas, {
        type: 'doughnut',
        data: {
          labels: ['Available Funds', 'Used Funds', overdraft > 0 ? 'Overdraft' : null].filter(Boolean),
          datasets: [{
            data: [
              availableFunds,
              usedFunds,
              overdraft
            ].filter(amount => amount > 0),
            backgroundColor: [
              '#4CAF50',  // Green for available
              '#2196F3',  // Blue for used
              '#F44336'   // Red for overdraft
            ].slice(0, overdraft > 0 ? 3 : 2),
            borderWidth: 1,
            borderColor: '#ffffff'
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '70%',
          plugins: {
            legend: {
              display: false
            },
            tooltip: {
              callbacks: {
                label: function(context) {
                  const label = context.label || '';
                  const value = context.formattedValue;
                  return `${label}: Rs.${value}`;
                }
              }
            }
          },
          animation: {
            animateScale: true,
            animateRotate: true
          }
        }
      });
    } catch (error) {
      console.error('Error initializing fund usage chart:', error);
      if (chartFallback) {
        chartFallback.style.display = 'block';
      }
      chartCanvas.style.display = 'none';
    }
  }
  
  /**
   * Initialize batch popup functionality with keyboard accessibility
   */
  function initBatchPopup() {
    const batchBalloons = document.querySelectorAll('.db-batch-balloon');
    const popup = document.getElementById('db-batchDetailPopup');
    
    if (!popup || batchBalloons.length === 0) return;
    
    // Initialize popup elements
    const popupBatchNumber = document.getElementById('db-popupBatchNumber');
    const popupProductName = document.getElementById('db-popupProductName');
    const popupQuantity = document.getElementById('db-popupQuantity');
    const popupStartDate = document.getElementById('db-popupStartDate');
    const popupExpectedDate = document.getElementById('db-popupExpectedDate');
    const popupTimeRemaining = document.getElementById('db-popupTimeRemaining');
    const popupStatus = document.getElementById('db-popupStatus');
    const popupViewLink = document.getElementById('db-popupViewLink');
    const popupUpdateLink = document.getElementById('db-popupUpdateLink');
    const closePopupBtn = document.querySelector('.db-close-popup');
    
    // Track currently focused balloon for keyboard navigation
    let lastFocusedBalloon = null;
    
    // Add click event to each batch balloon
    batchBalloons.forEach(balloon => {
      balloon.addEventListener('click', function(e) {
        e.stopPropagation();
        openBatchPopup(this);
      });
      
      // Add keyboard support
      balloon.addEventListener('keydown', function(e) {
        // Open popup on Enter or Space
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          openBatchPopup(this);
        }
      });
    });
    
    // Function to open popup and set data
    function openBatchPopup(balloon) {
      // Store reference to the balloon that triggered the popup
      lastFocusedBalloon = balloon;
      
      // Get batch data from data attributes
      const batchId = balloon.getAttribute('data-batch-id');
      const batchNumber = balloon.getAttribute('data-batch-number');
      const productName = balloon.getAttribute('data-product-name');
      const quantity = balloon.getAttribute('data-quantity');
      const startDate = balloon.getAttribute('data-start-date');
      const expectedDate = balloon.getAttribute('data-expected-date');
      const daysRemaining = parseFloat(balloon.getAttribute('data-days-remaining'));
      const status = balloon.getAttribute('data-status');
      
      // Format time remaining text with enhanced styling
      let timeRemainingText;
      if (daysRemaining < 0) {
        timeRemainingText = `<span class="db-overdue">${Math.abs(daysRemaining).toFixed(1)} days overdue</span>`;
      } else if (daysRemaining < 1) {
        timeRemainingText = `<span class="db-urgent">${(daysRemaining * 24).toFixed(1)} hours remaining</span>`;
      } else if (daysRemaining < 3) {
        timeRemainingText = `<span class="db-warning">${daysRemaining.toFixed(1)} days remaining</span>`;
      } else {
        timeRemainingText = `<span>${daysRemaining.toFixed(1)} days remaining</span>`;
      }
      
      // Add status badge
      const statusBadge = `<span class="db-status-badge db-status-${status}">${capitalizeFirstLetter(status)}</span>`;
      
      // Update popup content
      popupBatchNumber.textContent = batchNumber;
      popupProductName.textContent = productName;
      popupQuantity.textContent = formatNumber(quantity) + ' units';
      popupStartDate.textContent = formatDate(startDate);
      popupExpectedDate.textContent = formatDate(expectedDate);
      popupTimeRemaining.innerHTML = timeRemainingText;
      popupStatus.innerHTML = statusBadge;
      
      // Update action links
      popupViewLink.href = `view-batch.php?id=${batchId}`;
      popupUpdateLink.href = `update-batch.php?id=${batchId}`;
      
      // Position and show popup
      const balloonRect = balloon.getBoundingClientRect();
      const scrollTop = window.scrollY || document.documentElement.scrollTop;
      
      // Calculate position for popup (centered below the balloon)
      const balloonCenterX = balloonRect.left + (balloonRect.width / 2);
      const popupWidth = 320; // Width from CSS
      let leftPosition = balloonCenterX - (popupWidth / 2);
      
      // Ensure popup stays within viewport horizontally
      const viewportWidth = window.innerWidth;
      if (leftPosition < 20) leftPosition = 20;
      if (leftPosition + popupWidth > viewportWidth - 20) leftPosition = viewportWidth - popupWidth - 20;
      
      // Position popup below or above the balloon based on available space
      const popupHeight = 280; // Approximate height based on content
      const viewportHeight = window.innerHeight;
      const spaceBelow = viewportHeight - (balloonRect.bottom - window.scrollY);
      
      let topPosition;
      if (spaceBelow >= popupHeight + 20 || spaceBelow >= balloonRect.top - window.scrollY) {
        // Position below the balloon
        topPosition = balloonRect.bottom + scrollTop + 15;
      } else {
        // Position above the balloon
        topPosition = balloonRect.top + scrollTop - popupHeight - 15;
      }
      
      popup.style.left = `${leftPosition}px`;
      popup.style.top = `${topPosition}px`;
      
      // Show popup with animation
      popup.style.display = 'block';
      
      // Set focus to the popup for keyboard accessibility
      popup.focus();
      
      // Make popup focusable
      popup.setAttribute('tabindex', '-1');
    }
    
    // Close popup when clicking close button
    if (closePopupBtn) {
      closePopupBtn.addEventListener('click', closePopup);
    }
    
    // Close popup when clicking outside
    document.addEventListener('click', function(e) {
      if (popup.style.display === 'block' && !popup.contains(e.target) && !Array.from(batchBalloons).includes(e.target)) {
        closePopup();
      }
    });
    
    // Close popup on Escape key
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && popup.style.display === 'block') {
        closePopup();
      }
    });
    
    // Trap focus within popup when open
    popup.addEventListener('keydown', function(e) {
      if (e.key === 'Tab') {
        const focusableElements = popup.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];
        
        // Shift+Tab on first element should focus last element
        if (e.shiftKey && document.activeElement === firstElement) {
          e.preventDefault();
          lastElement.focus();
        }
        // Tab on last element should focus first element
        else if (!e.shiftKey && document.activeElement === lastElement) {
          e.preventDefault();
          firstElement.focus();
        }
      }
    });
    
    function closePopup() {
      // Hide popup with animation
      popup.style.display = 'none';
      
      // Return focus to the balloon that triggered the popup
      if (lastFocusedBalloon) {
        lastFocusedBalloon.focus();
      }
    }
  }
  
  /**
   * Make tables responsive for mobile devices
   */
  function makeTablesResponsive() {
    const tables = document.querySelectorAll('.db-data-table');
    
    tables.forEach(table => {
      const headerCells = table.querySelectorAll('thead th');
      const headerTexts = Array.from(headerCells).map(cell => cell.textContent.trim());
      
      const rows = table.querySelectorAll('tbody tr');
      
      rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        
        cells.forEach((cell, index) => {
          if (headerTexts[index]) {
            // Only set data-label if it doesn't already exist
            if (!cell.hasAttribute('data-label')) {
              cell.setAttribute('data-label', headerTexts[index]);
            }
          }
        });
      });
    });
  }
  
  /**
   * Format a number with thousands separators
   * @param {number|string} number - The number to format
   * @returns {string} - Formatted number string
   */
  function formatNumber(number) {
    return new Intl.NumberFormat().format(number);
  }
  
  /**
   * Format a date in a user-friendly format
   * @param {string} dateString - The date string to format
   * @returns {string} - Formatted date string
   */
  function formatDate(dateString) {
    try {
      const options = { year: 'numeric', month: 'short', day: 'numeric' };
      return new Date(dateString).toLocaleDateString(undefined, options);
    } catch (error) {
      console.error('Error formatting date:', error);
      return dateString; // Return original string if parsing fails
    }
  }
  
  /**
   * Capitalize the first letter of a string
   * @param {string} string - The string to capitalize
   * @returns {string} - Capitalized string
   */
  function capitalizeFirstLetter(string) {
    return string.charAt(0).toUpperCase() + string.slice(1);
  }
});
</script>

<?php
// Helper function to format currency
function formatCurrency($amount, $currency = 'Rs.') {
    return $currency . number_format($amount, 2);
}
?>
<!-- At the end of your file, before footer include -->
<script>
  // Force application of critical dashboard styles
  document.addEventListener('DOMContentLoaded', function() {
    // Define critical styles that must be applied
    const criticalStyles = `
      /* Root variables with higher specificity */
      html body {
        --db-primary: #4361ee !important;
        --db-primary-dark: #3a56d4 !important;
        --db-primary-light: #eef2ff !important;
        --db-success: #2ec4b6 !important;
        --db-warning: #ff9f1c !important;
        --db-danger: #e63946 !important;
        --db-surface: #ffffff !important;
        --db-background: #f8f9fa !important;
        --db-border: #dee2e6 !important;
        --db-shadow-sm: 0 2px 4px rgba(0, 0, 0, 0.08) !important;
        --db-shadow-md: 0 4px 8px rgba(0, 0, 0, 0.12) !important;
      }
      
      /* Critical component styles */
      html body .db-card {
        background-color: var(--db-surface) !important;
        border-radius: 8px !important;
        box-shadow: var(--db-shadow-sm) !important;
        margin-bottom: 1.5rem !important;
        overflow: hidden !important;
      }
      
      html body .db-card-header {
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        padding: 1.25rem 1.5rem !important;
        background-color: var(--db-primary-light) !important;
        border-bottom: 1px solid var(--db-border) !important;
      }
      
      html body .db-card-header h2 {
        margin: 0 !important;
        font-size: 1.25rem !important;
        color: var(--db-primary) !important;
      }
      
      html body .db-card-content {
        padding: 1.5rem !important;
      }
      
      /* Add any other critical styles here */
    `;
    
    // Create and append style element
    const styleElement = document.createElement('style');
    styleElement.id = 'db-critical-styles';
    styleElement.innerHTML = criticalStyles;
    document.head.appendChild(styleElement);
    
    console.log('Dashboard critical styles applied with version: <?php echo time(); ?>');
    
    // Add a visible indicator for debugging
    const debugIndicator = document.createElement('div');
    debugIndicator.style.position = 'fixed';
    debugIndicator.style.bottom = '10px';
    debugIndicator.style.left = '10px';
    debugIndicator.style.background = '#4361ee';
    debugIndicator.style.color = 'white';
    debugIndicator.style.padding = '5px 10px';
    debugIndicator.style.borderRadius = '4px';
    debugIndicator.style.fontSize = '12px';
    debugIndicator.style.zIndex = '9999';
    debugIndicator.textContent = 'Dashboard styles applied';
    document.body.appendChild(debugIndicator);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
      debugIndicator.remove();
    }, 5000);
  });
</script>

<?php include_once '../includes/footer.php'; ?>