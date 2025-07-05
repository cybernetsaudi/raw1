<?php
// File: export-report.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Check user authentication and role (e.g., only 'owner' can export reports).
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    // Redirect with an error message.
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to export reports.";
    header('Location: reports.php'); // Redirect back to reports page or dashboard
    exit;
}

// Get database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

try {
    // Get report type and date range from GET parameters.
    $report_type = isset($_GET['type']) ? trim($_GET['type']) : '';
    $start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
    $end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

    if (empty($report_type)) {
        throw new Exception("Report type is required.");
    }
    if (!in_array($report_type, ['financial', 'product_performance', 'material_usage', 'sales_summary'])) {
        throw new Exception("Invalid report type specified.");
    }

    $filename = $report_type . "_report_" . date('Y-m-d_H-i-s') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    // Add UTF-8 BOM (Byte Order Mark) for Excel compatibility.
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    $log_description = "Exported " . htmlspecialchars(str_replace('_', ' ', $report_type)) . " report";
    if (!empty($start_date) && !empty($end_date)) {
        $log_description .= " for dates " . htmlspecialchars($start_date) . " to " . htmlspecialchars($end_date);
    }

    // Determine data to fetch based on report type.
    switch ($report_type) {
        case 'financial':
            fputcsv($output, ['Metric', 'Value (Rs.)']);
            // Re-fetch financial data (similar to dashboard/financial.php)
            $financial_data = [];
            // Use date range for financial calculations
            $financial_where = "";
            $financial_params = [];
            if (!empty($start_date) && !empty($end_date)) {
                $financial_where = " WHERE created_at BETWEEN ? AND ?";
                $financial_params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
            }

            // Total Investment
            $query = "SELECT COALESCE(SUM(amount), 0) FROM funds WHERE type = 'investment'" . $financial_where;
            $stmt = $db->prepare($query);
            $stmt->execute($financial_params);
            $total_investment = $stmt->fetchColumn();

            // Total Purchases
            $query = "SELECT COALESCE(SUM(total_amount), 0) FROM purchases" . $financial_where;
            $stmt = $db->prepare($query);
            $stmt->execute($financial_params);
            $total_purchases = $stmt->fetchColumn();

            // Total Manufacturing Costs
            $query = "SELECT COALESCE(SUM(amount), 0) FROM manufacturing_costs mc JOIN manufacturing_batches mb ON mc.batch_id = mb.id" . $financial_where;
            $stmt = $db->prepare($query);
            $stmt->execute($financial_params);
            $total_manufacturing_costs = $stmt->fetchColumn();

            // Total Sales Revenue
            $query = "SELECT COALESCE(SUM(net_amount), 0) FROM sales" . $financial_where;
            $stmt = $db->prepare($query);
            $stmt->execute($financial_params);
            $total_sales_revenue = $stmt->fetchColumn();

            // Total Payments Received
            $query = "SELECT COALESCE(SUM(amount), 0) FROM payments" . $financial_where;
            $stmt = $db->prepare($query);
            $stmt->execute($financial_params);
            $total_payments_received = $stmt->fetchColumn();

            fputcsv($output, ['Total Investment', number_format($total_investment, 2)]);
            fputcsv($output, ['Total Purchases', number_format($total_purchases, 2)]);
            fputcsv($output, ['Total Manufacturing Costs', number_format($total_manufacturing_costs, 2)]);
            fputcsv($output, ['Total Sales Revenue', number_format($total_sales_revenue, 2)]);
            fputcsv($output, ['Total Payments Received', number_format($total_payments_received, 2)]);

            break;

        case 'product_performance':
            fputcsv($output, ['Product Name', 'SKU', 'Total Produced', 'Total Sold', 'Total Sales Revenue (Rs.)', 'Avg. Unit Cost (Rs.)', 'Avg. Unit Price (Rs.)', 'Estimated Profit per Unit (Rs.)']);
            $query = "
                SELECT
                    p.name AS product_name,
                    p.sku,
                    COALESCE(SUM(mb.quantity_produced), 0) AS total_produced,
                    COALESCE(SUM(si.quantity), 0) AS total_sold,
                    COALESCE(SUM(si.total_price), 0) AS total_sales_revenue,
                    COALESCE(AVG(total_cost_per_batch.total_cost / NULLIF(total_cost_per_batch.quantity_produced, 0)), 0) AS avg_unit_cost,
                    COALESCE(AVG(si.unit_price), 0) AS avg_unit_price
                FROM products p
                LEFT JOIN manufacturing_batches mb ON p.id = mb.product_id
                LEFT JOIN sale_items si ON p.id = si.product_id
                LEFT JOIN (
                    SELECT mc.batch_id,
                           SUM(mc.amount) + COALESCE(SUM(mu.quantity_used * (
                               SELECT AVG(pr.unit_price) FROM purchases pr WHERE pr.material_id = mu.material_id LIMIT 5
                           )), 0) AS total_cost,
                           mb_inner.quantity_produced
                    FROM manufacturing_costs mc
                    JOIN manufacturing_batches mb_inner ON mc.batch_id = mb_inner.id
                    LEFT JOIN material_usage mu ON mc.batch_id = mu.batch_id
                    GROUP BY mc.batch_id, mb_inner.quantity_produced
                ) AS total_cost_per_batch ON mb.id = total_cost_per_batch.batch_id
                GROUP BY p.id, p.name, p.sku
                ORDER BY total_sales_revenue DESC;
            "; // Note: Average unit cost calculation is complex and might need specific date range. This is a simplified aggregate.
            $stmt = $db->prepare($query);
            $stmt->execute();
            $products_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($products_data as $product) {
                $estimated_profit_per_unit = $product['avg_unit_price'] - $product['avg_unit_cost'];
                fputcsv($output, [
                    $product['product_name'],
                    $product['sku'],
                    number_format($product['total_produced']),
                    number_format($product['total_sold']),
                    number_format($product['total_sales_revenue'], 2),
                    number_format($product['avg_unit_cost'], 2),
                    number_format($product['avg_unit_price'], 2),
                    number_format($estimated_profit_per_unit, 2)
                ]);
            }
            break;

        case 'material_usage':
            fputcsv($output, ['Material Name', 'Unit', 'Total Quantity Purchased', 'Total Quantity Used in Production', 'Current Stock']);
            $query = "
                SELECT
                    rm.name AS material_name,
                    rm.unit,
                    COALESCE(SUM(p.quantity), 0) AS total_purchased,
                    COALESCE(SUM(mu.quantity_used), 0) AS total_used,
                    rm.stock_quantity AS current_stock
                FROM raw_materials rm
                LEFT JOIN purchases p ON rm.id = p.material_id
                LEFT JOIN material_usage mu ON rm.id = mu.material_id
                GROUP BY rm.id, rm.name, rm.unit, rm.stock_quantity
                ORDER BY rm.name ASC;
            ";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $materials_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($materials_data as $material) {
                fputcsv($output, [
                    $material['material_name'],
                    $material['unit'],
                    number_format($material['total_purchased'], 2),
                    number_format($material['total_used'], 2),
                    number_format($material['current_stock'], 2)
                ]);
            }
            break;

        case 'sales_summary':
            fputcsv($output, ['Invoice Number', 'Sale Date', 'Customer Name', 'Total Amount (Rs.)', 'Discount (Rs.)', 'Tax (Rs.)', 'Shipping (Rs.)', 'Net Amount (Rs.)', 'Payment Status']);
            $sales_where = "";
            $sales_params = [];
            if (!empty($start_date) && !empty($end_date)) {
                $sales_where = " WHERE s.sale_date BETWEEN ? AND ?";
                $sales_params = [$start_date, $end_date];
            }

            $query = "
                SELECT s.invoice_number, s.sale_date, c.name AS customer_name,
                       s.total_amount, s.discount_amount, s.tax_amount, s.shipping_cost, s.net_amount, s.payment_status
                FROM sales s
                JOIN customers c ON s.customer_id = c.id
                " . $sales_where . "
                ORDER BY s.sale_date DESC;
            ";
            $stmt = $db->prepare($query);
            $stmt->execute($sales_params);
            $sales_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($sales_data as $sale) {
                fputcsv($output, [
                    $sale['invoice_number'],
                    $sale['sale_date'],
                    $sale['customer_name'],
                    number_format($sale['total_amount'], 2),
                    number_format($sale['discount_amount'], 2),
                    number_format($sale['tax_amount'], 2),
                    number_format($sale['shipping_cost'], 2),
                    number_format($sale['net_amount'], 2),
                    ucfirst($sale['payment_status'])
                ]);
            }
            break;

        default:
            throw new Exception("Unhandled report type.");
    }

    fclose($output);

    // Log the export activity.
    $auth->logActivity(
        $_SESSION['user_id'],
        'export',
        'reports',
        $log_description,
        null
    );
    exit; // Exit after sending file.

} catch (PDOException $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'reports',
        'Database error during report export (' . htmlspecialchars($report_type) . '): ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = "Failed to export report due to a database error: " . $e->getMessage();
    header('Location: reports.php'); // Redirect back with error.
    exit;
} catch (Exception $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'reports',
        'Error during report export (' . htmlspecialchars($report_type) . '): ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = "An error occurred during report export: " . $e->getMessage();
    header('Location: reports.php'); // Redirect back with error.
    exit;
}
?>