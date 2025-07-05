<?php
session_start();
$page_title = "Business Reports";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Set default date range (last 30 days)
$end_date = date('Y-m-d');
$start_date = date('Y-m-d', strtotime('-30 days'));

// Get date range from request if provided
if(isset($_GET['start_date']) && !empty($_GET['start_date'])) {
    $start_date = $_GET['start_date'];
}
if(isset($_GET['end_date']) && !empty($_GET['end_date'])) {
    $end_date = $_GET['end_date'];
}

// Get report type
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'sales';

// Sales by date report
$sales_data = [];
if($report_type === 'sales' || $report_type === 'all') {
    $sales_query = "SELECT DATE(sale_date) as date, COUNT(*) as count, SUM(net_amount) as total_amount 
                   FROM sales 
                   WHERE sale_date BETWEEN ? AND ?
                   GROUP BY DATE(sale_date)
                   ORDER BY date";
    $sales_stmt = $db->prepare($sales_query);
    $sales_stmt->bindParam(1, $start_date);
    $sales_stmt->bindParam(2, $end_date);
    $sales_stmt->execute();
    $sales_data = $sales_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Purchases by date report
$purchases_data = [];
if($report_type === 'purchases' || $report_type === 'all') {
    $purchases_query = "SELECT DATE(purchase_date) as date, COUNT(*) as count, SUM(total_amount) as total_amount 
                       FROM purchases 
                       WHERE purchase_date BETWEEN ? AND ?
                       GROUP BY DATE(purchase_date)
                       ORDER BY date";
    $purchases_stmt = $db->prepare($purchases_query);
    $purchases_stmt->bindParam(1, $start_date);
    $purchases_stmt->bindParam(2, $end_date);
    $purchases_stmt->execute();
    $purchases_data = $purchases_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Manufacturing by date report
$manufacturing_data = [];
if($report_type === 'manufacturing' || $report_type === 'all') {
    $manufacturing_query = "SELECT DATE(start_date) as date, COUNT(*) as count, SUM(quantity_produced) as total_produced 
                           FROM manufacturing_batches 
                           WHERE start_date BETWEEN ? AND ?
                           GROUP BY DATE(start_date)
                           ORDER BY date";
    $manufacturing_stmt = $db->prepare($manufacturing_query);
    $manufacturing_stmt->bindParam(1, $start_date);
    $manufacturing_stmt->bindParam(2, $end_date);
    $manufacturing_stmt->execute();
    $manufacturing_data = $manufacturing_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Top selling products
$top_products_data = [];
if($report_type === 'products' || $report_type === 'all') {
    $top_products_query = "SELECT p.name, SUM(si.quantity) as total_quantity, SUM(si.total_price) as total_amount 
                          FROM sale_items si
                          JOIN products p ON si.product_id = p.id
                          JOIN sales s ON si.sale_id = s.id
                          WHERE s.sale_date BETWEEN ? AND ?
                          GROUP BY si.product_id
                          ORDER BY total_quantity DESC
                          LIMIT 10";
    $top_products_stmt = $db->prepare($top_products_query);
    $top_products_stmt->bindParam(1, $start_date);
    $top_products_stmt->bindParam(2, $end_date);
    $top_products_stmt->execute();
    $top_products_data = $top_products_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Financial summary
$financial_summary = [];
if($report_type === 'financial' || $report_type === 'all') {
    $financial_query = "SELECT 
        (SELECT COALESCE(SUM(total_amount), 0) FROM purchases WHERE purchase_date BETWEEN ? AND ?) as total_purchases,
        (SELECT COALESCE(SUM(amount), 0) FROM manufacturing_costs WHERE recorded_date BETWEEN ? AND ?) as total_manufacturing_costs,
        (SELECT COALESCE(SUM(net_amount), 0) FROM sales WHERE sale_date BETWEEN ? AND ?) as total_sales,
        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_date BETWEEN ? AND ?) as total_payments";
    $financial_stmt = $db->prepare($financial_query);
    $financial_stmt->bindParam(1, $start_date);
    $financial_stmt->bindParam(2, $end_date);
    $financial_stmt->bindParam(3, $start_date);
    $financial_stmt->bindParam(4, $end_date);
    $financial_stmt->bindParam(5, $start_date);
    $financial_stmt->bindParam(6, $end_date);
    $financial_stmt->bindParam(7, $start_date);
    $financial_stmt->bindParam(8, $end_date);
    $financial_stmt->execute();
    $financial_summary = $financial_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate profit
    $total_cost = $financial_summary['total_purchases'] + $financial_summary['total_manufacturing_costs'];
    $financial_summary['total_cost'] = $total_cost;
    $financial_summary['profit'] = $financial_summary['total_sales'] - $total_cost;
    $financial_summary['profit_margin'] = $financial_summary['total_sales'] > 0 ? ($financial_summary['profit'] / $financial_summary['total_sales'] * 100) : 0;
}
?>

<div class="reports-container">
    <div class="reports-header">
        <h2>Business Reports</h2>
        <div class="report-filters">
            <form id="reportForm" method="get" class="filter-form">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="report_type">Report Type:</label>
                        <select id="report_type" name="report_type" onchange="this.form.submit()">
                            <option value="all" <?php echo $report_type === 'all' ? 'selected' : ''; ?>>All Reports</option>
                            <option value="sales" <?php echo $report_type === 'sales' ? 'selected' : ''; ?>>Sales</option>
                            <option value="purchases" <?php echo $report_type === 'purchases' ? 'selected' : ''; ?>>Purchases</option>
                            <option value="manufacturing" <?php echo $report_type === 'manufacturing' ? 'selected' : ''; ?>>Manufacturing</option>
                            <option value="products" <?php echo $report_type === 'products' ? 'selected' : ''; ?>>Top Products</option>
                            <option value="financial" <?php echo $report_type === 'financial' ? 'selected' : ''; ?>>Financial Summary</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="start_date">Start Date:</label>
                        <input type="date" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="end_date">End Date:</label>
                        <input type="date" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="button">Apply Filters</button>
                        <button type="button" id="exportReportBtn" class="button secondary">Export Report</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <div class="reports-content">
        <?php if($report_type === 'all' || $report_type === 'financial'): ?>
        <div class="report-section">
            <h3>Financial Summary (<?php echo $start_date; ?> to <?php echo $end_date; ?>)</h3>
            <?php if(!empty($financial_summary)): ?>
            <div class="financial-summary">
                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-value"><?php echo number_format($financial_summary['total_sales'], 2); ?></div>
                        <div class="summary-label">Total Sales</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-value"><?php echo number_format($financial_summary['total_cost'], 2); ?></div>
                        <div class="summary-label">Total Costs</div>
                    </div>
                    <div class="summary-card profit">
                        <div class="summary-value"><?php echo number_format($financial_summary['profit'], 2); ?></div>
                        <div class="summary-label">Profit</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-value"><?php echo number_format($financial_summary['profit_margin'], 2); ?>%</div>
                        <div class="summary-label">Profit Margin</div>
                    </div>
                </div>
                
                <div class="chart-container">
                    <canvas id="financialChart" height="250"></canvas>
                </div>
            </div>
            <?php else: ?>
            <p class="no-data">No financial data available for the selected period.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if($report_type === 'all' || $report_type === 'sales'): ?>
        <div class="report-section">
            <h3>Sales Report (<?php echo $start_date; ?> to <?php echo $end_date; ?>)</h3>
            <?php if(!empty($sales_data)): ?>
            <div class="chart-container">
                <canvas id="salesChart" height="250"></canvas>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Number of Sales</th>
                            <th>Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($sales_data as $sale): ?>
                        <tr>
                            <td><?php echo $sale['date']; ?></td>
                            <td><?php echo $sale['count']; ?></td>
                            <td><?php echo number_format($sale['total_amount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p class="no-data">No sales data available for the selected period.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if($report_type === 'all' || $report_type === 'purchases'): ?>
        <div class="report-section">
            <h3>Purchases Report (<?php echo $start_date; ?> to <?php echo $end_date; ?>)</h3>
            <?php if(!empty($purchases_data)): ?>
            <div class="chart-container">
                <canvas id="purchasesChart" height="250"></canvas>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Number of Purchases</th>
                            <th>Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($purchases_data as $purchase): ?>
                        <tr>
                            <td><?php echo $purchase['date']; ?></td>
                            <td><?php echo $purchase['count']; ?></td>
                            <td><?php echo number_format($purchase['total_amount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p class="no-data">No purchase data available for the selected period.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if($report_type === 'all' || $report_type === 'manufacturing'): ?>
        <div class="report-section">
            <h3>Manufacturing Report (<?php echo $start_date; ?> to <?php echo $end_date; ?>)</h3>
            <?php if(!empty($manufacturing_data)): ?>
            <div class="chart-container">
                <canvas id="manufacturingChart" height="250"></canvas>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Number of Batches</th>
                            <th>Total Produced</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($manufacturing_data as $batch): ?>
                        <tr>
                            <td><?php echo $batch['date']; ?></td>
                            <td><?php echo $batch['count']; ?></td>
                            <td><?php echo number_format($batch['total_produced']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p class="no-data">No manufacturing data available for the selected period.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if($report_type === 'all' || $report_type === 'products'): ?>
        <div class="report-section">
            <h3>Top Selling Products (<?php echo $start_date; ?> to <?php echo $end_date; ?>)</h3>
            <?php if(!empty($top_products_data)): ?>
            <div class="chart-container">
                <canvas id="productsChart" height="300"></canvas>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity Sold</th>
                            <th>Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($top_products_data as $product): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($product['name']); ?></td>
                            <td><?php echo number_format($product['total_quantity']); ?></td>
                            <td><?php echo number_format($product['total_amount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p class="no-data">No product sales data available for the selected period.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Set up Chart.js defaults
    Chart.defaults.font.family = getComputedStyle(document.body).getPropertyValue('--font-family');
    Chart.defaults.color = getComputedStyle(document.body).getPropertyValue('--text-secondary');
    
    // Financial Summary Chart
    <?php if(($report_type === 'all' || $report_type === 'financial') && !empty($financial_summary)): ?>
    const financialCtx = document.getElementById('financialChart').getContext('2d');
    const financialChart = new Chart(financialCtx, {
        type: 'bar',
        data: {
            labels: ['Sales', 'Purchases', 'Manufacturing Costs', 'Profit'],
            datasets: [{
                label: 'Amount',
                data: [
                    <?php echo $financial_summary['total_sales']; ?>,
                    <?php echo $financial_summary['total_purchases']; ?>,
                    <?php echo $financial_summary['total_manufacturing_costs']; ?>,
                    <?php echo $financial_summary['profit']; ?>
                ],
                backgroundColor: [
                    'rgba(75, 192, 192, 0.5)',
                    'rgba(255, 99, 132, 0.5)',
                    'rgba(255, 159, 64, 0.5)',
                    'rgba(40, 167, 69, 0.5)'
                ],
                borderColor: [
                    'rgb(75, 192, 192)',
                    'rgb(255, 99, 132)',
                    'rgb(255, 159, 64)',
                    'rgb(40, 167, 69)'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                label += new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(context.parsed.y);
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumSignificantDigits: 3 }).format(value);
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>
    
    // Sales Chart
    <?php if(($report_type === 'all' || $report_type === 'sales') && !empty($sales_data)): ?>
    const salesCtx = document.getElementById('salesChart').getContext('2d');
    const salesChart = new Chart(salesCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_column($sales_data, 'date')); ?>,
            datasets: [{
                label: 'Sales Amount',
                data: <?php echo json_encode(array_column($sales_data, 'total_amount')); ?>,
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                borderColor: 'rgba(75, 192, 192, 1)',
                borderWidth: 2,
                tension: 0.1,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                label += new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(context.parsed.y);
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumSignificantDigits: 3 }).format(value);
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>
    
    // Purchases Chart
    <?php if(($report_type === 'all' || $report_type === 'purchases') && !empty($purchases_data)): ?>
    const purchasesCtx = document.getElementById('purchasesChart').getContext('2d');
    const purchasesChart = new Chart(purchasesCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_column($purchases_data, 'date')); ?>,
            datasets: [{
                label: 'Purchase Amount',
                data: <?php echo json_encode(array_column($purchases_data, 'total_amount')); ?>,
                backgroundColor: 'rgba(255, 99, 132, 0.2)',
                borderColor: 'rgba(255, 99, 132, 1)',
                borderWidth: 2,
                tension: 0.1,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                label += new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(context.parsed.y);
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumSignificantDigits: 3 }).format(value);
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>
    
    // Manufacturing Chart
    <?php if(($report_type === 'all' || $report_type === 'manufacturing') && !empty($manufacturing_data)): ?>
    const manufacturingCtx = document.getElementById('manufacturingChart').getContext('2d');
    const manufacturingChart = new Chart(manufacturingCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($manufacturing_data, 'date')); ?>,
            datasets: [{
                label: 'Units Produced',
                data: <?php echo json_encode(array_column($manufacturing_data, 'total_produced')); ?>,
                backgroundColor: 'rgba(54, 162, 235, 0.5)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                label += new Intl.NumberFormat('en-US').format(context.parsed.y) + ' units';
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return new Intl.NumberFormat('en-US').format(value);
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>
    
    // Top Products Chart
    <?php if(($report_type === 'all' || $report_type === 'products') && !empty($top_products_data)): ?>
    const productsCtx = document.getElementById('productsChart').getContext('2d');
    const productsChart = new Chart(productsCtx, {
        type: 'horizontalBar',
        data: {
            labels: <?php echo json_encode(array_column($top_products_data, 'name')); ?>,
            datasets: [{
                label: 'Quantity Sold',
                data: <?php echo json_encode(array_column($top_products_data, 'total_quantity')); ?>,
                backgroundColor: Array(<?php echo count($top_products_data); ?>).fill().map((_, i) => `hsl(${i * 30}, 70%, 60%)`),
                borderWidth: 0
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = 'Quantity Sold: ';
                            if (context.parsed.x !== null) {
                                label += new Intl.NumberFormat('en-US').format(context.parsed.x) + ' units';
                            }
                            return label;
                        },
                        afterLabel: function(context) {
                            const index = context.dataIndex;
                            const amount = <?php echo json_encode(array_column($top_products_data, 'total_amount')); ?>[index];
                            return 'Total Amount: ' + new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return new Intl.NumberFormat('en-US').format(value);
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>
    
    // Export report functionality
    document.getElementById('exportReportBtn').addEventListener('click', function() {
        // Get current filter parameters
        const reportType = document.getElementById('report_type').value;
        const startDate = document.getElementById('start_date').value;
        const endDate = document.getElementById('end_date').value;
        
        // Redirect to export endpoint
        window.location.href = `../api/export-report.php?report_type=${reportType}&start_date=${startDate}&end_date=${endDate}`;
    });
    
    // Log activity
    if (typeof logUserActivity === 'function') {
        logUserActivity('read', 'reports', 'Viewed business reports');
    }
});
</script>

<style>
/* Reports specific styles */
.reports-container {
    margin-bottom: 2rem;
}

.reports-header {
    margin-bottom: 1.5rem;
}

.report-filters {
    background-color: var(--surface);
    padding: 1rem;
    border-radius: var(--border-radius-md);
    margin-bottom: 1.5rem;
}

.report-section {
    background-color: white;
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-sm);
    padding: 1.5rem;
    margin-bottom: 2rem;
}

.report-section h3 {
    margin-top: 0;
    margin-bottom: 1.5rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border);
    color: var(--primary);
}

.financial-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 2rem;
    margin-bottom: 1.5rem;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
    flex: 1;
    min-width: 300px;
}

.summary-card {
    background-color: var(--surface);
    padding: 1rem;
    border-radius: var(--border-radius-sm);
    text-align: center;
}

.summary-card.profit {
    grid-column: span 2;
    background-color: rgba(40, 167, 69, 0.1);
    color: #28a745;
}

.summary-value {
    font-size: var(--font-size-xl);
    font-weight: bold;
    margin-bottom: 0.25rem;
}

.summary-label {
    color: var(--text-secondary);
    font-size: var(--font-size-sm);
}

.chart-container {
    flex: 2;
    min-width: 500px;
    height: 250px;
    position: relative;
}

.table-container {
    margin-top: 1.5rem;
    overflow-x: auto;
}

.no-data {
    text-align: center;
    padding: 2rem;
    color: var(--text-secondary);
    background-color: var(--surface);
    border-radius: var(--border-radius-sm);
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .financial-summary {
        flex-direction: column;
        gap: 1.5rem;
    }
    
    .chart-container {
        min-width: 100%;
        height: 200px;
    }
    
    .filter-row {
        flex-direction: column;
    }
    
    .filter-group {
        width: 100%;
    }
    
    .filter-actions {
        flex-direction: column;
        width: 100%;
    }
    
    .filter-actions .button {
        width: 100%;
        margin-bottom: 0.5rem;
    }
}
</style>

<?php include_once '../includes/footer.php'; ?>