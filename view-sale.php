<?php
// File: view-sale.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Include header.
$page_title = "View Sale";
include_once '../includes/header.php';

// Check user authentication and role (e.g., 'shopkeeper' or 'owner' can view sales).
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'shopkeeper' && $_SESSION['role'] !== 'owner')) {
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to view sales.";
    header('Location: sales.php'); // Redirect to sales list
    exit;
}

// Get database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

$sale_id = isset($_GET['id']) ? intval($_GET['id']) : null;
$sale = null;
$sale_items = [];
$payments = [];
$total_paid_amount = 0;
$amount_due = 0;

try {
    if (!$sale_id) {
        throw new Exception("Missing or invalid Sale ID.");
    }

    // Fetch sale details.
    $sale_query = "SELECT s.*, c.name as customer_name, c.phone as customer_phone, c.email as customer_email, c.address as customer_address,
                          u.full_name as created_by_name, u.username as created_by_username
                   FROM sales s
                   JOIN customers c ON s.customer_id = c.id
                   LEFT JOIN users u ON s.created_by = u.id
                   WHERE s.id = ?";
    // If shopkeeper, ensure they can only view sales they created
    if ($_SESSION['role'] === 'shopkeeper') {
        $sale_query .= " AND s.created_by = " . $_SESSION['user_id'];
    }
    $sale_stmt = $db->prepare($sale_query);
    $sale_stmt->execute([$sale_id]);
    $sale = $sale_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sale) {
        throw new Exception("Sale record not found or you do not have permission to view it.");
    }

    // Fetch sale items.
    $sale_items_query = "SELECT si.*, p.name as product_name, p.sku as product_sku
                         FROM sale_items si
                         JOIN products p ON si.product_id = p.id
                         WHERE si.sale_id = ?";
    $sale_items_stmt = $db->prepare($sale_items_query);
    $sale_items_stmt->execute([$sale_id]);
    $sale_items = $sale_items_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch payments for this sale.
    $payments_query = "SELECT py.*, u.full_name as recorded_by_name
                       FROM payments py
                       LEFT JOIN users u ON py.recorded_by = u.id
                       WHERE py.sale_id = ? ORDER BY py.payment_date ASC";
    $payments_stmt = $db->prepare($payments_query);
    $payments_stmt->execute([$sale_id]);
    $payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_paid_amount = array_sum(array_column($payments, 'amount'));
    $amount_due = $sale['net_amount'] - $total_paid_amount;
    if ($amount_due < 0) $amount_due = 0; // Ensure it's not negative if overpaid or adjustments occurred

    // Log viewing the page.
    $auth->logActivity(
        $_SESSION['user_id'],
        'read',
        'sales',
        'Viewed details for sale: ' . htmlspecialchars($sale['invoice_number']),
        $sale_id
    );

} catch (Exception $e) {
    // Log the error.
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'sales',
        'Error loading sale details: ' . $e->getMessage(),
        $sale_id ?? null
    );
    $_SESSION['error_message'] = $e->getMessage();
    header('Location: sales.php'); // Redirect if sale not found or invalid ID
    exit;
}

// Helper function for payment method color (duplicate from payments.php, should be centralized in utils.php)
function getMethodColor($method) {
    switch ($method) {
        case 'cash': return 'status-cash';
        case 'bank_transfer': return 'status-bank';
        case 'check': return 'status-check';
        case 'other': return 'status-other';
        default: return '';
    }
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt;
    <a href="sales.php">Sales</a> &gt;
    <span>Sale #<?php echo htmlspecialchars($sale['invoice_number']); ?></span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>Sale Details: #<?php echo htmlspecialchars($sale['invoice_number']); ?></h2>
    </div>
    <div class="page-actions">
        <a href="sales.php" class="button secondary">
            <i class="fas fa-arrow-left"></i> Back to Sales
        </a>
        <?php if ($sale['payment_status'] !== 'paid' && $_SESSION['role'] === 'shopkeeper'): ?>
            <a href="add-payment.php?sale_id=<?php echo htmlspecialchars($sale['id']); ?>" class="button primary">
                <i class="fas fa-money-bill-wave"></i> Add Payment
            </a>
        <?php endif; ?>
        <button type="button" class="button button-info" id="printSaleBtn">
            <i class="fas fa-print"></i> Print Invoice
        </button>
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
    <div class="row">
        <div class="col-lg-6 col-md-12">
            <div class="card sale-info-card">
                <div class="card-header">
                    <h3>Sale Information</h3>
                </div>
                <div class="card-content">
                    <p><strong>Sale Date:</strong> <?php echo htmlspecialchars($sale['sale_date']); ?></p>
                    <p><strong>Total Amount:</strong> Rs. <?php echo number_format($sale['total_amount'], 2); ?></p>
                    <p><strong>Discount:</strong> Rs. <?php echo number_format($sale['discount_amount'], 2); ?></p>
                    <p><strong>Tax:</strong> Rs. <?php echo number_format($sale['tax_amount'], 2); ?></p>
                    <p><strong>Shipping Cost:</strong> Rs. <?php echo number_format($sale['shipping_cost'], 2); ?></p>
                    <hr>
                    <p><strong>Net Amount:</strong> Rs. <?php echo number_format($sale['net_amount'], 2); ?></p>
                    <p><strong>Payment Status:</strong> <span class="status-badge status-<?php echo htmlspecialchars($sale['payment_status']); ?>"><?php echo ucfirst(htmlspecialchars($sale['payment_status'])); ?></span></p>
                    <?php if ($sale['payment_due_date']): ?>
                        <p><strong>Payment Due Date:</strong> <?php echo htmlspecialchars($sale['payment_due_date']); ?></p>
                    <?php endif; ?>
                    <p><strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($sale['notes'] ?: 'N/A')); ?></p>
                    <p><strong>Created By:</strong> <?php echo htmlspecialchars($sale['created_by_name'] ?: 'N/A'); ?></p>
                    <p><strong>Created At:</strong> <?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($sale['created_at']))); ?></p>
                </div>
            </div>
        </div>
        <div class="col-lg-6 col-md-12">
            <div class="card customer-info-card">
                <div class="card-header">
                    <h3>Customer Information</h3>
                </div>
                <div class="card-content">
                    <p><strong>Customer Name:</strong> <?php echo htmlspecialchars($sale['customer_name']); ?></p>
                    <p><strong>Phone:</strong> <?php echo htmlspecialchars($sale['customer_phone'] ?: 'N/A'); ?></p>
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($sale['customer_email'] ?: 'N/A'); ?></p>
                    <p><strong>Address:</strong> <?php echo nl2br(htmlspecialchars($sale['customer_address'] ?: 'N/A')); ?></p>
                    <a href="view-customer.php?id=<?php echo htmlspecialchars($sale['customer_id']); ?>" class="button button-sm secondary mt-2">View Customer Profile</a>
                </div>
            </div>
            <div class="card payment-summary-card mt-4">
                <div class="card-header">
                    <h3>Payment Summary</h3>
                </div>
                <div class="card-content">
                    <p><strong>Net Amount:</strong> Rs. <?php echo number_format($sale['net_amount'], 2); ?></p>
                    <p><strong>Total Paid:</strong> Rs. <?php echo number_format($total_paid_amount, 2); ?></p>
                    <hr>
                    <p><strong>Amount Due:</strong> <span class="amount-due">Rs. <?php echo number_format($amount_due, 2); ?></span></p>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-12">
            <div class="card sale-items-card">
                <div class="card-header">
                    <h3>Sale Items</h3>
                </div>
                <div class="card-content">
                    <?php if (!empty($sale_items)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Product Name</th>
                                        <th>SKU</th>
                                        <th>Quantity</th>
                                        <th>Unit Price</th>
                                        <th>Total Price</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sale_items as $item): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                            <td><?php echo htmlspecialchars($item['product_sku']); ?></td>
                                            <td><?php echo number_format($item['quantity']); ?></td>
                                            <td>Rs. <?php echo number_format($item['unit_price'], 2); ?></td>
                                            <td>Rs. <?php echo number_format($item['total_price'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center text-muted">No items found for this sale.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-12">
            <div class="card payments-received-card">
                <div class="card-header">
                    <h3>Payments Received</h3>
                </div>
                <div class="card-content">
                    <?php if (!empty($payments)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Payment ID</th>
                                        <th>Amount</th>
                                        <th>Date</th>
                                        <th>Method</th>
                                        <th>Reference No.</th>
                                        <th>Recorded By</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $payment): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($payment['id']); ?></td>
                                            <td>Rs. <?php echo number_format($payment['amount'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                                            <td><span class="status-badge <?php echo getMethodColor($payment['payment_method']); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $payment['payment_method']))); ?></span></td>
                                            <td><?php echo htmlspecialchars($payment['reference_number'] ?: 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($payment['recorded_by_name'] ?: 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($payment['notes'] ?: 'N/A'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center text-muted">No payments received for this sale yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Close alert buttons
    const alertCloseButtons = document.querySelectorAll('.alert-close');
    alertCloseButtons.forEach(button => {
        button.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });

    // Print Invoice functionality (client-side print, can be enhanced with server-side PDF later)
    const printSaleBtn = document.getElementById('printSaleBtn');
    if (printSaleBtn) {
        printSaleBtn.addEventListener('click', function() {
            window.print(); // Triggers browser print dialog
        });
    }

    // Email Invoice functionality (placeholder, will require backend email sending)
    /*
    const emailSaleBtn = document.getElementById('emailSaleBtn');
    if (emailSaleBtn) {
        emailSaleBtn.addEventListener('click', function() {
            const saleId = this.dataset.saleId;
            if (confirm('Are you sure you want to email this invoice?')) {
                // Here you would typically make an AJAX call to a backend endpoint
                // e.g., fetch('../api/email-invoice.php', { method: 'POST', body: `sale_id=${saleId}` })
                // .then(response => response.json())
                // .then(data => { showToast(data.success ? 'success' : 'error', data.message); })
                // .catch(error => { showToast('error', 'Failed to send email.'); });
                if (typeof showToast === 'function') {
                    showToast('info', 'Email functionality is not yet fully implemented on the server-side.');
                } else {
                    alert('Email functionality is not yet fully implemented.');
                }
            }
        });
    }
    */
});
</script>

<?php include_once '../includes/footer.php'; ?>