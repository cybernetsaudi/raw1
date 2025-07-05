<?php
session_start();
$page_title = "Sales Management";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Set up pagination
$records_per_page = 15;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$page = $page < 1 ? 1 : $page; // Ensure page is at least 1
$offset = ($page - 1) * $records_per_page;

// Filter variables
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_customer = isset($_GET['customer_id']) ? $_GET['customer_id'] : '';
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

try {
    // Build the WHERE clause based on filters
    $where_clause = "WHERE 1=1";
    $params = array();

    if (!empty($filter_status)) {
        $where_clause .= " AND s.payment_status = :status";
        $params[':status'] = $filter_status;
    }

    if (!empty($filter_customer)) {
        $where_clause .= " AND s.customer_id = :customer_id";
        $params[':customer_id'] = $filter_customer;
    }

    if (!empty($filter_date_from)) {
        $where_clause .= " AND s.sale_date >= :date_from";
        $params[':date_from'] = $filter_date_from;
    }

    if (!empty($filter_date_to)) {
        $where_clause .= " AND s.sale_date <= :date_to";
        $params[':date_to'] = $filter_date_to;
    }
    
    if (!empty($search_term)) {
        $where_clause .= " AND (s.invoice_number LIKE :search OR c.name LIKE :search)";
        $params[':search'] = "%{$search_term}%";
    }

    // Get total records for pagination
    $count_query = "SELECT COUNT(*) as total FROM sales s 
                   JOIN customers c ON s.customer_id = c.id 
                   $where_clause";
    $count_stmt = $db->prepare($count_query);
    foreach ($params as $param => $value) {
        $count_stmt->bindValue($param, $value);
    }
    $count_stmt->execute();
    $total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_records / $records_per_page);

    // Get sales with pagination and filters
    $query = "SELECT s.id, s.invoice_number, c.name as customer_name, s.sale_date, 
              s.total_amount, s.discount_amount, s.tax_amount, s.shipping_cost, s.net_amount, 
              s.payment_status, s.payment_due_date,
              (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE sale_id = s.id) as amount_paid
              FROM sales s 
              JOIN customers c ON s.customer_id = c.id
              $where_clause
              ORDER BY s.sale_date DESC 
              LIMIT :offset, :records_per_page";

    $stmt = $db->prepare($query);
    foreach ($params as $param => $value) {
        $stmt->bindValue($param, $value);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':records_per_page', $records_per_page, PDO::PARAM_INT);
    $stmt->execute();

    // Get customers for filter dropdown
    $customers_query = "SELECT id, name FROM customers ORDER BY name";
    $customers_stmt = $db->prepare($customers_query);
    $customers_stmt->execute();
    $customers = $customers_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get sales summary
    $summary_query = "SELECT 
                     COUNT(*) as total_sales,
                     SUM(net_amount) as total_amount,
                     SUM(CASE WHEN payment_status = 'paid' THEN net_amount ELSE 0 END) as paid_amount,
                     SUM(CASE WHEN payment_status != 'paid' THEN net_amount ELSE 0 END) as unpaid_amount,
                     COUNT(CASE WHEN payment_status = 'unpaid' THEN 1 END) as unpaid_count,
                     COUNT(CASE WHEN payment_status = 'partial' THEN 1 END) as partial_count,
                     COUNT(CASE WHEN payment_status = 'paid' THEN 1 END) as paid_count
                     FROM sales s $where_clause";
    $summary_stmt = $db->prepare($summary_query);
    foreach ($params as $param => $value) {
        $summary_stmt->bindValue($param, $value);
    }
    $summary_stmt->execute();
    $summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}
?>

<!-- HTML and CSS for the sales management page -->
<div class="page-header">
    <h2>Sales Management</h2>
    <div class="page-actions">
        <a href="add-sale.php" class="button">
            <i class="fas fa-plus"></i> Create New Sale
        </a>
    </div>
</div>

<!-- Sales Summary Cards -->
<div class="sales-summary">
    <div class="summary-cards">
        <div class="summary-card">
            <div class="card-icon total-icon">
                <i class="fas fa-file-invoice"></i>
            </div>
            <div class="card-content">
                <div class="card-title">Total Sales</div>
                <div class="card-value"><?php echo number_format($summary['total_sales']); ?></div>
                <div class="card-subtitle">$<?php echo number_format($summary['total_amount'], 2); ?></div>
            </div>
        </div>
        
        <div class="summary-card">
            <div class="card-icon paid-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="card-content">
                <div class="card-title">Paid</div>
                <div class="card-value"><?php echo number_format($summary['paid_count']); ?></div>
                <div class="card-subtitle">$<?php echo number_format($summary['paid_amount'], 2); ?></div>
            </div>
        </div>
        
        <div class="summary-card">
            <div class="card-icon partial-icon">
                <i class="fas fa-clock"></i>
            </div>
            <div class="card-content">
                <div class="card-title">Partial</div>
                <div class="card-value"><?php echo number_format($summary['partial_count']); ?></div>
            </div>
        </div>
        
        <div class="summary-card">
            <div class="card-icon unpaid-icon">
                <i class="fas fa-exclamation-circle"></i>
            </div>
            <div class="card-content">
                <div class="card-title">Unpaid</div>
                <div class="card-value"><?php echo number_format($summary['unpaid_count']); ?></div>
                <div class="card-subtitle">$<?php echo number_format($summary['unpaid_amount'], 2); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="filter-container">
    <button id="toggleFiltersBtn" class="button secondary toggle-filters-btn">
        <i class="fas fa-filter"></i> Filters
        <?php if(!empty($filter_status) || !empty($filter_customer) || !empty($filter_date_from) || !empty($filter_date_to) || !empty($search_term)): ?>
        <span class="filter-badge"><?php echo countActiveFilters($filter_status, $filter_customer, $filter_date_from, $filter_date_to, $search_term); ?></span>
        <?php endif; ?>
    </button>
    
    <form id="saleFilterForm" method="get" class="filter-form">
        <div class="filter-row">
            <div class="filter-group search-group">
                <label for="search">Search:</label>
                <div class="search-input-container">
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="Invoice # or Customer Name">
                    <button type="submit" class="search-button">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            
            <div class="filter-group">
                <label for="status">Payment Status:</label>
                <select id="status" name="status">
                    <option value="">All Statuses</option>
                    <option value="unpaid" <?php echo $filter_status === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                    <option value="partial" <?php echo $filter_status === 'partial' ? 'selected' : ''; ?>>Partial</option>
                    <option value="paid" <?php echo $filter_status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="customer_id">Customer:</label>
                <select id="customer_id" name="customer_id">
                    <option value="">All Customers</option>
                    <?php foreach($customers as $customer): ?>
                    <option value="<?php echo $customer['id']; ?>" <?php echo $filter_customer == $customer['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($customer['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="filter-row">
            <div class="filter-group">
                <label for="date_from">From Date:</label>
                <div class="input-with-icon">
                    <i class="fas fa-calendar"></i>
                    <input type="date" id="date_from" name="date_from" value="<?php echo $filter_date_from; ?>">
                </div>
            </div>
            
            <div class="filter-group">
                <label for="date_to">To Date:</label>
                <div class="input-with-icon">
                    <i class="fas fa-calendar"></i>
                    <input type="date" id="date_to" name="date_to" value="<?php echo $filter_date_to; ?>">
                </div>
            </div>
            
            <div class="filter-actions">
                <button type="submit" class="button">Apply Filters</button>
                <a href="sales.php" class="button secondary">Reset</a>
            </div>
        </div>
    </form>
</div>

<!-- Sales List -->
<div class="dashboard-card full-width">
    <div class="card-header">
        <h3>Sales List</h3>
        <div class="pagination-info">
            Showing <?php echo min(($page - 1) * $records_per_page + 1, $total_records); ?> to 
            <?php echo min($page * $records_per_page, $total_records); ?> of 
            <?php echo $total_records; ?> records
        </div>
    </div>
    <div class="card-content">
        <?php if($stmt->rowCount() > 0): ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Customer</th>
                        <th>Sale Date</th>
                        <th>Net Amount</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Due Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                        <?php 
                        $balance = $row['net_amount'] - $row['amount_paid'];
                        $today = new DateTime();
                        $due_date = new DateTime($row['payment_due_date']);
                        $is_overdue = ($row['payment_status'] !== 'paid' && $today > $due_date);
                        ?>
                    <tr class="<?php echo $is_overdue ? 'overdue-row' : ''; ?>">
                        <td>
                            <a href="view-sale.php?id=<?php echo $row['id']; ?>" class="invoice-link">
                                <?php echo htmlspecialchars($row['invoice_number']); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                        <td data-label="Date"><?php echo date('M j, Y', strtotime($row['sale_date'])); ?></td>
                        <td data-label="Amount"><?php echo number_format($row['net_amount'], 2); ?></td>
                        <td data-label="Paid"><?php echo number_format($row['amount_paid'], 2); ?></td>
                        <td data-label="Balance" class="<?php echo $balance > 0 ? 'balance-due' : ''; ?>">
                            <?php echo number_format($balance, 2); ?>
                        </td>
                        <td data-label="Status">
                            <span class="status-badge status-<?php echo $row['payment_status']; ?>">
                                <?php echo ucfirst($row['payment_status']); ?>
                            </span>
                        </td>
                        <td data-label="Due Date">
                            <?php if($row['payment_status'] !== 'paid'): ?>
                                <span class="<?php echo $is_overdue ? 'overdue-date' : ''; ?>">
                                    <?php echo date('M j, Y', strtotime($row['payment_due_date'])); ?>
                                    <?php if($is_overdue): ?>
                                        <span class="overdue-indicator" title="Overdue">
                                            <i class="fas fa-exclamation-circle"></i>
                                        </span>
                                    <?php endif; ?>
                                </span>
                            <?php else: ?>
                                <span class="paid-indicator">Paid</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <a href="view-sale.php?id=<?php echo $row['id']; ?>" class="button small" aria-label="View sale details">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if($row['payment_status'] !== 'paid'): ?>
                                <a href="add-payment.php?sale_id=<?php echo $row['id']; ?>" class="button small primary" aria-label="Add payment">
                                    <i class="fas fa-money-bill"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="fas fa-file-invoice-dollar"></i>
            </div>
            <h3>No Sales Found</h3>
            <p>No sales match your search criteria. Try adjusting your filters or create a new sale.</p>
            <a href="add-sale.php" class="button">Create New Sale</a>
        </div>
        <?php endif; ?>
        
        <!-- Pagination -->
        <?php if($total_pages > 1): ?>
        <div class="pagination">
            <?php 
            // Build pagination query string with filters
            $pagination_query = '';
            if(!empty($filter_status)) $pagination_query .= '&status=' . urlencode($filter_status);
            if(!empty($filter_customer)) $pagination_query .= '&customer_id=' . urlencode($filter_customer);
            if(!empty($filter_date_from)) $pagination_query .= '&date_from=' . urlencode($filter_date_from);
            if(!empty($filter_date_to)) $pagination_query .= '&date_to=' . urlencode($filter_date_to);
            if(!empty($search_term)) $pagination_query .= '&search=' . urlencode($search_term);
            ?>
            
            <?php if($page > 1): ?>
                <a href="?page=1<?php echo $pagination_query; ?>" class="pagination-link" aria-label="First page">
                    <i class="fas fa-angle-double-left"></i>
                </a>
                <a href="?page=<?php echo $page - 1; ?><?php echo $pagination_query; ?>" class="pagination-link" aria-label="Previous page">
                    <i class="fas fa-angle-left"></i>
                </a>
            <?php endif; ?>
            
            <?php
            // Determine the range of page numbers to display
            $range = 2; // Number of pages to show on either side of the current page
            $start_page = max(1, $page - $range);
            $end_page = min($total_pages, $page + $range);
            
            // Always show first page button
            if($start_page > 1) {
                echo '<a href="?page=1' . $pagination_query . '" class="pagination-link">1</a>';
                if($start_page > 2) {
                    echo '<span class="pagination-ellipsis">...</span>';
                }
            }
            
            // Display the range of pages
            for($i = $start_page; $i <= $end_page; $i++) {
                if($i == $page) {
                    echo '<span class="pagination-link current" aria-current="page">' . $i . '</span>';
                } else {
                    echo '<a href="?page=' . $i . $pagination_query . '" class="pagination-link">' . $i . '</a>';
                }
            }
            
            // Always show last page button
            if($end_page < $total_pages) {
                if($end_page < $total_pages - 1) {
                    echo '<span class="pagination-ellipsis">...</span>';
                }
                echo '<a href="?page=' . $total_pages . $pagination_query . '" class="pagination-link">' . $total_pages . '</a>';
            }
            ?>
            
            <?php if($page < $total_pages): ?>
                <a href="?page=<?php echo $page + 1; ?><?php echo $pagination_query; ?>" class="pagination-link" aria-label="Next page">
                    <i class="fas fa-angle-right"></i>
                </a>
                <a href="?page=<?php echo $total_pages; ?><?php echo $pagination_query; ?>" class="pagination-link" aria-label="Last page">
                    <i class="fas fa-angle-double-right"></i>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<style>
/* Alert Styles */
.alert {
    padding: 1rem;
    margin-bottom: 1.5rem;
    border-radius: var(--border-radius-md, 8px);
    display: flex;
    align-items: center;
    animation: fadeIn 0.3s ease-in-out;
}

.alert i {
    margin-right: 0.75rem;
    font-size: 1.25rem;
}

.alert-success {
    background-color: rgba(52, 168, 83, 0.1);
    color: #34a853;
    border: 1px solid rgba(52, 168, 83, 0.3);
}

.alert-error {
    background-color: rgba(234, 67, 53, 0.1);
    color: #ea4335;
    border: 1px solid rgba(234, 67, 53, 0.3);
}

.close-alert {
    margin-left: auto;
    background: none;
    border: none;
    font-size: 1.25rem;
    cursor: pointer;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.close-alert:hover {
    opacity: 1;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Sales summary styles */
.sales-summary {
    margin-bottom: 1.5rem;
}

.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.summary-card {
    background-color: white;
    border-radius: var(--border-radius-md, 8px);
    box-shadow: var(--shadow-sm, 0 1px 3px rgba(0,0,0,0.1));
    padding: 1.25rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: transform 0.2s, box-shadow 0.2s;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md, 0 4px 6px rgba(0,0,0,0.1));
}

.card-icon {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
}

.total-icon {
    background-color: #4285f4;
}

.paid-icon {
    background-color: #34a853;
}

.partial-icon {
    background-color: #fbbc04;
}

.unpaid-icon {
    background-color: #ea4335;
}

.card-content {
    flex: 1;
}

.card-title {
    font-size: var(--font-size-sm, 0.875rem);
    color: var(--text-secondary, #6c757d);
    margin-bottom: 0.25rem;
}

.card-value {
    font-size: var(--font-size-xl, 1.5rem);
    font-weight: bold;
    margin-bottom: 0.25rem;
}

.card-subtitle {
    font-size: var(--font-size-sm, 0.875rem);
    color: var(--text-secondary, #6c757d);
}

/* Filter styles */
.filter-container {
    margin-bottom: 1.5rem;
}

.toggle-filters-btn {
    position: relative;
    margin-bottom: 1rem;
}

.filter-badge {
    position: absolute;
    top: -8px;
    right: -8px;
    background-color: var(--primary, #1a73e8);
    color: white;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: bold;
}

.filter-form {
    background-color: white;
    border-radius: var(--border-radius-md, 8px);
    box-shadow: var(--shadow-sm, 0 1px 3px rgba(0,0,0,0.1));
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    overflow: hidden;
    transition: max-height 0.3s ease-in-out, opacity 0.3s ease-in-out, margin 0.3s ease-in-out, padding 0.3s ease-in-out;
}

.filter-form.collapsed {
    max-height: 0;
    opacity: 0;
    margin: 0;
    padding-top: 0;
    padding-bottom: 0;
}

.filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1rem;
}

.filter-row:last-child {
    margin-bottom: 0;
}

.filter-group {
    flex: 1;
    min-width: 200px;
}

.filter-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: var(--text-secondary, #6c757d);
}

.filter-actions {
    display: flex;
    gap: 0.5rem;
    align-items: flex-end;
}

.search-group {
    flex: 2;
}

.search-input-container {
    position: relative;
}

.search-input-container input {
    width: 100%;
    padding-right: 40px;
}

.search-button {
    position: absolute;
    right: 0;
    top: 0;
    bottom: 0;
    width: 40px;
    background: none;
    border: none;
    color: var(--text-secondary, #6c757d);
    cursor: pointer;
    transition: color 0.2s;
}

.search-button:hover {
    color: var(--primary, #1a73e8);
}

/* Input with icon */
.input-with-icon {
    position: relative;
}

.input-with-icon i {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-secondary, #6c757d);
}

.input-with-icon input {
    padding-left: 35px;
}

/* Table styles */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    background-color: var(--surface, #f5f5f5);
    font-weight: 600;
    text-align: left;
    padding: 0.75rem 1rem;
    color: var(--text-secondary, #6c757d);
    border-bottom: 2px solid var(--border, #e0e0e0);
    position: sticky;
    top: 0;
    z-index: 10;
}

.data-table td {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border, #e0e0e0);
    vertical-align: middle;
}

.data-table tbody tr:hover {
    background-color: rgba(0, 0, 0, 0.02);
}

.invoice-link {
    color: var(--primary, #1a73e8);
    text-decoration: none;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
}

.invoice-link:hover {
    text-decoration: underline;
}

.status-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 50px;
    font-size: 0.75rem;
    font-weight: 600;
    text-align: center;
}

.status-unpaid {
    background-color: rgba(234, 67, 53, 0.1);
    color: #ea4335;
}

.status-partial {
    background-color: rgba(251, 188, 4, 0.1);
    color: #fbbc04;
}

.status-paid {
    background-color: rgba(52, 168, 83, 0.1);
    color: #34a853;
}

.overdue-row {
    background-color: rgba(234, 67, 53, 0.05);
}

.overdue-date {
    color: #ea4335;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.overdue-indicator {
    color: #ea4335;
}

.paid-indicator {
    color: #34a853;
    font-style: italic;
}

.balance-due {
    color: #ea4335;
    font-weight: 500;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
}

/* Empty state styles */
.empty-state {
    text-align: center;
    padding: 3rem 1rem;
}

.empty-state-icon {
    font-size: 3rem;
    color: var(--text-secondary, #6c757d);
    margin-bottom: 1rem;
    opacity: 0.5;
}

.empty-state h3 {
    margin-bottom: 0.5rem;
    color: var(--text-primary, #212529);
}

.empty-state p {
    color: var(--text-secondary, #6c757d);
    max-width: 500px;
    margin: 0 auto 1.5rem;
}

/* Pagination styles */
.pagination {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 1.5rem;
}

.pagination-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    padding: 0 0.5rem;
    background-color: white;
    border: 1px solid var(--border, #e0e0e0);
    border-radius: var(--border-radius-sm, 4px);
    color: var(--text-primary, #212529);
    text-decoration: none;
    transition: all 0.2s;
}

.pagination-link:hover {
    background-color: var(--surface, #f5f5f5);
    border-color: var(--border-dark, #c0c0c0);
}

.pagination-link.current {
    background-color: var(--primary, #1a73e8);
    border-color: var(--primary, #1a73e8);
    color: white;
    font-weight: 500;
}

.pagination-ellipsis {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
}

/* Responsive adjustments */
@media (max-width: 992px) {
    .summary-cards {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .filter-row {
        flex-direction: column;
    }
    
    .filter-group {
        width: 100%;
    }
    
    .filter-actions {
        flex-direction: row;
        width: 100%;
    }
}

@media (max-width: 768px) {
    .summary-cards {
        grid-template-columns: 1fr;
    }
    
    .data-table, .data-table tbody, .data-table tr, .data-table td {
        display: block;
    }
    
    .data-table thead {
        display: none;
    }
    
    .data-table tbody tr {
        margin-bottom: 1rem;
        border: 1px solid var(--border, #e0e0e0);
        border-radius: var(--border-radius-md, 8px);
        padding: 1rem;
        position: relative;
    }
    
    .data-table td {
        padding: 0.5rem 0;
        border: none;
        text-align: right;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .data-table td::before {
        content: attr(data-label);
        font-weight: 600;
        margin-right: 1rem;
        text-align: left;
        flex: 1;
    }
    
    .data-table td:last-child {
        border-bottom: none;
    }
    
    .action-buttons {
        justify-content: flex-end;
    }
    
    .pagination {
        gap: 0.25rem;
    }
    
    .pagination-link {
        min-width: 32px;
        height: 32px;
        font-size: 0.875rem;
    }
}

/* Accessibility improvements */
@media (prefers-reduced-motion: reduce) {
    .summary-card:hover {
        transform: none;
    }
    
    .alert {
        animation: none;
    }
    
    .filter-form {
        transition: none;
    }
}

/* Replace the dark mode section with this improved version */
@media (prefers-color-scheme: dark) {
    /* Only apply dark mode if user has explicitly enabled it */
    body.dark-mode-enabled .summary-card, 
    body.dark-mode-enabled .filter-form, 
    body.dark-mode-enabled .dashboard-card, 
    body.dark-mode-enabled .details-section {
        background-color: #2d2d2d;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
    }
    
    /* Add a class to control dark mode instead of applying it automatically */
    body:not(.dark-mode-enabled) .summary-card,
    body:not(.dark-mode-enabled) .filter-form,
    body:not(.dark-mode-enabled) .dashboard-card,
    body:not(.dark-mode-enabled) .details-section {
        background-color: #ffffff;
    }
    
    /* Make sure light backgrounds are enforced for these elements */
    .modal-content,
    .toast,
    .product-details,
    .pagination-link {
        background-color: #ffffff !important;
    }
    
    .data-table th {
        background-color: #f5f5f5 !important;
    }
}
/* High contrast mode support */
@media (forced-colors: active) {
    .status-badge, .card-icon {
        border: 1px solid;
    }
    
    .pagination-link.current {
        border: 2px solid;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Close alert buttons
    const closeAlertButtons = document.querySelectorAll('.close-alert');
    closeAlertButtons.forEach(button => {
        button.addEventListener('click', function() {
            const alert = this.closest('.alert');
            alert.style.opacity = '0';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 300);
        });
    });

    // Toggle filters visibility
    const toggleFiltersBtn = document.getElementById('toggleFiltersBtn');
    const filterForm = document.getElementById('saleFilterForm');
    
    // Check if filters should be shown by default (if any filter is active)
    const hasActiveFilters = <?php echo (!empty($filter_status) || !empty($filter_customer) || !empty($filter_date_from) || !empty($filter_date_to) || !empty($search_term)) ? 'true' : 'false'; ?>;
    
    if (!hasActiveFilters) {
        filterForm.classList.add('collapsed');
    }
    
    toggleFiltersBtn.addEventListener('click', function() {
        filterForm.classList.toggle('collapsed');
        
        // Change button text based on state
        if (filterForm.classList.contains('collapsed')) {
            this.innerHTML = '<i class="fas fa-filter"></i> Show Filters';
            if (hasActiveFilters) {
                this.innerHTML += ' <span class="filter-badge"><?php echo countActiveFilters($filter_status, $filter_customer, $filter_date_from, $filter_date_to, $search_term); ?></span>';
            }
        } else {
            this.innerHTML = '<i class="fas fa-times"></i> Hide Filters';
        }
        
        // Announce to screen readers
        announceToScreenReader(filterForm.classList.contains('collapsed') ? 'Filters hidden' : 'Filters shown');
    });
    
    // Date range validation
    const dateFromInput = document.getElementById('date_from');
    const dateToInput = document.getElementById('date_to');
    
    if (dateFromInput && dateToInput) {
        // Set max date to today
        const today = new Date().toISOString().split('T')[0];
        dateFromInput.setAttribute('max', today);
        dateToInput.setAttribute('max', today);
        
        dateFromInput.addEventListener('change', function() {
            if (dateToInput.value && this.value > dateToInput.value) {
                showToast('From date cannot be later than To date', 'warning');
                this.value = dateToInput.value;
            }
            dateToInput.setAttribute('min', this.value);
        });
        
        dateToInput.addEventListener('change', function() {
            if (dateFromInput.value && this.value < dateFromInput.value) {
                showToast('To date cannot be earlier than From date', 'warning');
                this.value = dateFromInput.value;
            }
            dateFromInput.setAttribute('max', this.value || today);
        });
    }
    
    // Add sort functionality to table headers
    const sortableHeaders = document.querySelectorAll('.data-table th[data-sort]');
    sortableHeaders.forEach(header => {
        header.addEventListener('click', function() {
            const sortField = this.getAttribute('data-sort');
            let sortDirection = this.getAttribute('data-direction') || 'asc';
            
            // Toggle sort direction
            sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
            this.setAttribute('data-direction', sortDirection);
            
            // Update URL with sort parameters
            const url = new URL(window.location.href);
            url.searchParams.set('sort', sortField);
            url.searchParams.set('direction', sortDirection);
            window.location.href = url.toString();
        });
    });
    
    // Highlight overdue rows
    const overdueRows = document.querySelectorAll('.overdue-row');
    overdueRows.forEach(row => {
        row.setAttribute('title', 'This invoice is overdue');
    });
    
    // Add keyboard navigation for table rows
    const tableRows = document.querySelectorAll('.data-table tbody tr');
    tableRows.forEach(row => {
        // Make rows focusable
        row.setAttribute('tabindex', '0');
        
        // Add keyboard event for Enter key
        row.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                const viewLink = this.querySelector('.invoice-link');
                if (viewLink) {
                    viewLink.click();
                }
            }
        });
    });
    
    // Add responsive table data-labels
    if (window.innerWidth <= 768) {
        const tableCells = document.querySelectorAll('.data-table tbody td');
        tableCells.forEach(cell => {
            if (!cell.hasAttribute('data-label')) {
                const headerIndex = Array.from(cell.parentNode.children).indexOf(cell);
                const headerText = document.querySelector('.data-table thead th:nth-child(' + (headerIndex + 1) + ')').textContent.trim();
                cell.setAttribute('data-label', headerText);
            }
        });
    }
    
    // Log view activity
    if (typeof logUserActivity === 'function') {
        logUserActivity('read', 'sales', 'Viewed sales list');
    }
    
    // Toast notification system
    window.showToast = function(message, type = 'info') {
        // Check if toast container exists, if not create it
        let toastContainer = document.querySelector('.toast-container');
        
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'toast-container';
            document.body.appendChild(toastContainer);
        }
        
        // Create toast element
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'polite');
        
        // Set icon based on type
        let icon = 'info-circle';
        if (type === 'success') icon = 'check-circle';
        if (type === 'error') icon = 'exclamation-circle';
        if (type === 'warning') icon = 'exclamation-triangle';
        
        toast.innerHTML = `
            <div class="toast-icon"><i class="fas fa-${icon}" aria-hidden="true"></i></div>
            <div class="toast-content">${message}</div>
            <button class="toast-close" aria-label="Close notification"><i class="fas fa-times" aria-hidden="true"></i></button>
        `;
        
        // Add to container
        toastContainer.appendChild(toast);
        
        // Add event listener to close button
        toast.querySelector('.toast-close').addEventListener('click', function() {
            toast.classList.add('toast-hiding');
            setTimeout(() => {
                toast.remove();
                
                // Remove container if empty
                if (toastContainer.children.length === 0) {
                    toastContainer.remove();
                }
            }, 300);
        });
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            if (toast.parentNode) {
                toast.classList.add('toast-hiding');
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.remove();
                        
                        // Remove container if empty
                        if (toastContainer.children.length === 0) {
                            toastContainer.remove();
                        }
                    }
                }, 300);
            }
        }, 5000);
    }
    
    // Helper function to announce messages to screen readers
    function announceToScreenReader(message) {
        let announcer = document.getElementById('sr-announcer');
        
        if (!announcer) {
            announcer = document.createElement('div');
            announcer.id = 'sr-announcer';
            announcer.className = 'sr-only';
            announcer.setAttribute('aria-live', 'polite');
            announcer.setAttribute('aria-atomic', 'true');
            document.body.appendChild(announcer);
        }
        
        announcer.textContent = message;
        
        setTimeout(() => {
            announcer.textContent = '';
        }, 3000);
    }
});

// Activity logging function
function logUserActivity(actionType, module, description) {
    const userId = document.getElementById('current-user-id').value;
    
    if (!userId) return;
    
    fetch('../api/log-activity.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            user_id: userId,
            action_type: actionType,
            module: module,
            description: description
        })
    })
    .catch(error => {
        console.error('Error logging activity:', error);
    });
}
</script>

<!-- Toast notification styles -->
<style>
.toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-width: 350px;
}

.toast {
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    animation: toast-in 0.3s ease-out forwards;
    opacity: 0;
    transform: translateX(50px);
}

.toast-hiding {
    animation: toast-out 0.3s ease-in forwards;
}

.toast-icon {
    flex-shrink: 0;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background-color: #f8f9fa;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}

.toast-content {
    flex: 1;
    font-size: 14px;
}

.toast-close {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 14px;
    color: #6c757d;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.toast-close:hover {
    opacity: 1;
}

.toast-info {
    border-left: 4px solid #1a73e8;
}

.toast-info .toast-icon {
    color: #1a73e8;
}

.toast-success {
    border-left: 4px solid #34a853;
}

.toast-success .toast-icon {
    color: #34a853;
}

.toast-warning {
    border-left: 4px solid #fbbc04;
}

.toast-warning .toast-icon {
    color: #fbbc04;
}

.toast-error {
    border-left: 4px solid #ea4335;
}

.toast-error .toast-icon {
    color: #ea4335;
}

@keyframes toast-in {
    from {
        opacity: 0;
        transform: translateX(50px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes toast-out {
    from {
        opacity: 1;
        transform: translateX(0);
    }
    to {
        opacity: 0;
        transform: translateX(50px);
    }
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

@media (max-width: 480px) {
    .toast-container {
        top: auto;
        bottom: 20px;
        left: 20px;
        right: 20px;
        max-width: none;
    }
}

@media (prefers-reduced-motion: reduce) {
    .toast, .toast-hiding {
        animation: none;
        opacity: 1;
        transform: translateX(0);
    }
}
</style>

<?php
// Helper function to count active filters
function countActiveFilters($status, $customer, $date_from, $date_to, $search) {
    $count = 0;
    if (!empty($status)) $count++;
    if (!empty($customer)) $count++;
    if (!empty($date_from)) $count++;
    if (!empty($date_to)) $count++;
    if (!empty($search)) $count++;
    return $count;
}
?>