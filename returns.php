<?php
// File: reports.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Include header.
$page_title = "Reports";
include_once '../includes/header.php';

// Check user authentication and role (e.g., only 'owner' can view reports).
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to view reports.";
    header('Location: dashboard.php'); // Redirect to dashboard or login
    exit;
}

// Get database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

$report_type = isset($_GET['type']) ? trim($_GET['type']) : 'financial'; // Default report type
$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : date('Y-m-01'); // Default to start of current month
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : date('Y-m-d'); // Default to current date

$report_data = []; // Data to be displayed/charted

try {
    // Log viewing the page.
    $auth->logActivity(
        $_SESSION['user_id'],
        'read',
        'reports',
        'Viewed reports page (Type: ' . htmlspecialchars($report_type) . ')',
        null
    );

    // Dynamic data fetching based on report type.
    switch ($report_type) {
        case 'financial':
            // Fetch financial summary data within the date range.
            $financial_params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];

            // Total Investment
            $query_investment = "SELECT COALESCE(SUM(amount), 0) FROM funds WHERE type = 'investment' AND created_at BETWEEN ? AND ?";
            $stmt_investment = $db->prepare($query_investment);
            $stmt_investment->execute($financial_params);
            $total_investment = $stmt_investment->fetchColumn();

            // Total Purchases
            $query_purchases = "SELECT COALESCE(SUM(total_amount), 0) FROM purchases WHERE purchase_date BETWEEN ? AND ?";
            $stmt_purchases = $db->prepare($query_purchases);
            $stmt_purchases->execute([$start_date, $end_date]);
            $total_purchases = $stmt_purchases->fetchColumn();

            // Total Manufacturing Costs
            $query_mfg_costs = "SELECT COALESCE(SUM(mc.amount), 0) FROM manufacturing_costs mc JOIN manufacturing_batches mb ON mc.batch_id = mb.id WHERE mb.created_at BETWEEN ? AND ?";
            $stmt_mfg_costs = $db->prepare($query_mfg_costs);
            $stmt_mfg_costs->execute($financial_params);
            $total_manufacturing_costs = $stmt_mfg_costs->fetchColumn();

            // Total Sales Revenue
            $query_sales_revenue = "SELECT COALESCE(SUM(net_amount), 0) FROM sales WHERE sale_date BETWEEN ? AND ?";
            $stmt_sales_revenue = $db->prepare($query_sales_revenue);
            $stmt_sales_revenue->execute([$start_date, $end_date]);
            $total_sales_revenue = $stmt_sales_revenue->fetchColumn();

            // Total Payments Received
            $query_payments_received = "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_date BETWEEN ? AND ?";
            $stmt_payments_received = $db->prepare($query_payments_received);
            $stmt_payments_received->execute([$start_date, $end_date]);
            $total_payments_received = $stmt_payments_received->fetchColumn();

            $report_data['financial'] = [
                'labels' => ['Investment', 'Purchases', 'Mfg Costs', 'Sales Revenue', 'Payments Received'],
                'data' => [
                    $total_investment,
                    $total_purchases,
                    $total_manufacturing_costs,
                    $total_sales_revenue,
                    $total_payments_received
                ]
            ];
            break;

        case 'product_performance':
            // Fetch top 10 products by sales revenue in the period.
            $query_products = "
                SELECT p.name, COALESCE(SUM(si.total_price), 0) AS total_sales_revenue
                FROM products p
                JOIN sale_items si ON p.id = si.product_id
                JOIN sales s ON si.sale_id = s.id
                WHERE s.sale_date BETWEEN ? AND ?
                GROUP BY p.name
                ORDER BY total_sales_revenue DESC
                LIMIT 10
            ";
            $stmt_products = $db->prepare($query_products);
            $stmt_products->execute([$start_date, $end_date]);
            $products_performance = $stmt_products->fetchAll(PDO::FETCH_ASSOC);

            $report_data['product_performance'] = [
                'labels' => array_column($products_performance, 'name'),
                'data' => array_column($products_performance, 'total_sales_revenue')
            ];
            break;

        case 'material_usage':
            // Fetch top 10 materials by quantity used in the period.
            $query_materials = "
                SELECT rm.name, COALESCE(SUM(mu.quantity_used), 0) AS total_quantity_used
                FROM raw_materials rm
                JOIN material_usage mu ON rm.id = mu.material_id
                JOIN manufacturing_batches mb ON mu.batch_id = mb.id
                WHERE mb.created_at BETWEEN ? AND ? -- Assuming batch creation date for material usage period
                GROUP BY rm.name
                ORDER BY total_quantity_used DESC
                LIMIT 10
            ";
            $stmt_materials = $db->prepare($query_materials);
            $stmt_materials->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
            $materials_usage = $stmt_materials->fetchAll(PDO::FETCH_ASSOC);

            $report_data['material_usage'] = [
                'labels' => array_column($materials_usage, 'name'),
                'data' => array_column($materials_usage, 'total_quantity_used')
            ];
            break;

        case 'sales_summary':
            // Fetch sales by payment status over time (e.g., monthly).
            // This is more complex for charting. For simplicity, let's just get count by status.
            $query_sales_status = "
                SELECT payment_status, COUNT(*) AS count, SUM(net_amount) AS total_amount
                FROM sales
                WHERE sale_date BETWEEN ? AND ?
                GROUP BY payment_status
            ";
            $stmt_sales_status = $db->prepare($query_sales_status);
            $stmt_sales_status->execute([$start_date, $end_date]);
            $sales_status = $stmt_sales_status->fetchAll(PDO::FETCH_ASSOC);

            $report_data['sales_summary'] = [
                'labels' => array_column($sales_status, 'payment_status'),
                'counts' => array_column($sales_status, 'count'),
                'amounts' => array_column($sales_status, 'total_amount')
            ];
            break;

        default:
            // Handled by initial validation, but good to have a default.
            break;
    }

} catch (PDOException $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'reports',
        'Database error generating report (' . htmlspecialchars($report_type) . '): ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = "A database error occurred while generating the report. Please try again later.";
    $report_data = []; // Clear data on error
} catch (Exception $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'reports',
        'Error generating report (' . htmlspecialchars($report_type) . '): ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = $e->getMessage();
    $report_data = []; // Clear data on error
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt; <span>Reports</span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>System Reports</h2>
    </div>
    <div class="page-actions">
        <a id="exportReportBtn" class="button primary"
           href="export-report.php?type=<?php echo htmlspecialchars($report_type); ?>&start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>">
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
            <h3>Report Filters</h3>
        </div>
        <div class="card-content">
            <form method="GET" action="reports.php">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label for="type">Report Type:</label>
                        <select id="type" name="type" class="form-control" onchange="this.form.submit()">
                            <option value="financial" <?php echo $report_type == 'financial' ? 'selected' : ''; ?>>Financial Summary</option>
                            <option value="product_performance" <?php echo $report_type == 'product_performance' ? 'selected' : ''; ?>>Product Performance</option>
                            <option value="material_usage" <?php echo $report_type == 'material_usage' ? 'selected' : ''; ?>>Material Usage</option>
                            <option value="sales_summary" <?php echo $report_type == 'sales_summary' ? 'selected' : ''; ?>>Sales Summary</option>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label for="start_date">Start Date:</label>
                        <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div class="form-group col-md-4">
                        <label for="end_date">End Date:</label>
                        <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-12 d-flex align-items-end">
                        <button type="submit" class="button primary">Generate Report</button>
                        <a href="reports.php" class="button secondary ml-2">Clear Filters</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h3>Report Data: <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $report_type))); ?></h3>
        </div>
        <div class="card-content">
            <?php if (!empty($report_data)): ?>
                <div class="chart-container" style="position: relative; height:400px; width:100%">
                    <canvas id="reportChart"></canvas>
                </div>
                <div id="reportTable" class="table-responsive mt-4">
                    <?php if ($report_type === 'financial'): ?>
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr><th>Metric</th><th>Value (Rs.)</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report_data['financial']['labels'] as $index => $label): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($label); ?></td>
                                    <td><?php echo number_format($report_data['financial']['data'][$index], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php elseif ($report_type === 'product_performance' && !empty($products_performance)): ?>
                         <table class="table table-bordered table-hover sortable-table">
                            <thead>
                                <tr>
                                    <th class="sortable">Product Name</th>
                                    <th class="sortable">Total Sales Revenue (Rs.)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($products_performance as $prod): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($prod['name']); ?></td>
                                    <td><?php echo number_format($prod['total_sales_revenue'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php elseif ($report_type === 'material_usage' && !empty($materials_usage)): ?>
                         <table class="table table-bordered table-hover sortable-table">
                            <thead>
                                <tr>
                                    <th class="sortable">Material Name</th>
                                    <th class="sortable">Total Quantity Used</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($materials_usage as $mat): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($mat['name']); ?></td>
                                    <td><?php echo number_format($mat['total_quantity_used'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php elseif ($report_type === 'sales_summary' && !empty($sales_status)): ?>
                         <table class="table table-bordered table-hover sortable-table">
                            <thead>
                                <tr>
                                    <th class="sortable">Payment Status</th>
                                    <th class="sortable">Number of Sales</th>
                                    <th class="sortable">Total Amount (Rs.)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sales_status as $status_sum): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(ucfirst($status_sum['payment_status'])); ?></td>
                                    <td><?php echo number_format($status_sum['count']); ?></td>
                                    <td><?php echo number_format($status_sum['total_amount'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="text-center text-muted">No data available for this report type or date range.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="text-center text-muted">Select a report type and date range to generate a report.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Close alert buttons
    const alertCloseButtons = document.querySelectorAll('.alert-close');
    alertCloseButtons.forEach(button => {
        button.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });

    const reportTypeSelect = document.getElementById('type');
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    const exportReportBtn = document.getElementById('exportReportBtn');

    // Update export button href when filters change
    function updateExportButton() {
        const type = reportTypeSelect.value;
        const start = startDateInput.value;
        const end = endDateInput.value;
        exportReportBtn.href = `export-report.php?type=${type}&start_date=${start}&end_date=${end}`;
    }

    reportTypeSelect.addEventListener('change', updateExportButton);
    startDateInput.addEventListener('change', updateExportButton);
    endDateInput.addEventListener('change', updateExportButton);
    updateExportButton(); // Initial update on load

    // Charting logic
    const reportChartCtx = document.getElementById('reportChart');
    if (reportChartCtx) {
        const reportData = <?php echo json_encode($report_data); ?>;
        const reportType = "<?php echo htmlspecialchars($report_type); ?>";
        let chartConfig = {};

        if (Object.keys(reportData).length > 0) {
            switch (reportType) {
                case 'financial':
                    chartConfig = {
                        type: 'bar',
                        data: {
                            labels: reportData.financial.labels,
                            datasets: [{
                                label: 'Amount (Rs.)',
                                data: reportData.financial.data,
                                backgroundColor: [
                                    'rgba(255, 99, 132, 0.6)',
                                    'rgba(54, 162, 235, 0.6)',
                                    'rgba(255, 206, 86, 0.6)',
                                    'rgba(75, 192, 192, 0.6)',
                                    'rgba(153, 102, 255, 0.6)'
                                ],
                                borderColor: [
                                    'rgba(255, 99, 132, 1)',
                                    'rgba(54, 162, 235, 1)',
                                    'rgba(255, 206, 86, 1)',
                                    'rgba(75, 192, 192, 1)',
                                    'rgba(153, 102, 255, 1)'
                                ],
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Financial Summary'
                                },
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Amount (Rs.)'
                                    }
                                }
                            }
                        }
                    };
                    break;

                case 'product_performance':
                    chartConfig = {
                        type: 'bar',
                        data: {
                            labels: reportData.product_performance.labels,
                            datasets: [{
                                label: 'Sales Revenue (Rs.)',
                                data: reportData.product_performance.data,
                                backgroundColor: 'rgba(75, 192, 192, 0.6)',
                                borderColor: 'rgba(75, 192, 192, 1)',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Top 10 Product Sales Performance'
                                },
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Sales Revenue (Rs.)'
                                    }
                                }
                            }
                        }
                    };
                    break;

                case 'material_usage':
                    chartConfig = {
                        type: 'bar',
                        data: {
                            labels: reportData.material_usage.labels,
                            datasets: [{
                                label: 'Quantity Used',
                                data: reportData.material_usage.data,
                                backgroundColor: 'rgba(153, 102, 255, 0.6)',
                                borderColor: 'rgba(153, 102, 255, 1)',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Top 10 Material Usage'
                                },
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Quantity Used'
                                    }
                                }
                            }
                        }
                    };
                    break;

                case 'sales_summary':
                    chartConfig = {
                        type: 'pie', // Pie chart for payment status
                        data: {
                            labels: reportData.sales_summary.labels,
                            datasets: [{
                                label: 'Number of Sales',
                                data: reportData.sales_summary.counts,
                                backgroundColor: [
                                    'rgba(255, 99, 132, 0.6)', // Unpaid
                                    'rgba(255, 206, 86, 0.6)', // Partial
                                    'rgba(75, 192, 192, 0.6)'  // Paid
                                ],
                                borderColor: [
                                    'rgba(255, 99, 132, 1)',
                                    'rgba(255, 206, 86, 1)',
                                    'rgba(75, 192, 192, 1)'
                                ],
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Sales by Payment Status'
                                }
                            }
                        }
                    };
                    break;
            }
            new Chart(reportChartCtx, chartConfig);
        } else {
            reportChartCtx.style.display = 'none'; // Hide canvas if no data
            document.getElementById('reportTable').innerHTML = '<p class="text-center text-muted">No data available for this report type or date range.</p>';
        }
    }
});
</script>

<?php include_once '../includes/footer.php'; ?>