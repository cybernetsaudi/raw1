<?php
// File: view-purchase.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Include header.
$page_title = "View Purchase";
include_once '../includes/header.php';

// Check user authentication and role (e.g., 'incharge' or 'owner' can view purchases).
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'incharge' && $_SESSION['role'] !== 'owner')) {
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to view purchases.";
    header('Location: purchases.php'); // Redirect to purchases list
    exit;
}

// Get database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

$purchase_id = isset($_GET['id']) ? intval($_GET['id']) : null;
$purchase = null;

try {
    if (!$purchase_id) {
        throw new Exception("Missing or invalid Purchase ID.");
    }

    // Fetch purchase details.
    $purchase_query = "SELECT p.*, rm.name as material_name, rm.unit as material_unit,
                              u_purchased.full_name as purchased_by_name, u_purchased.username as purchased_by_username,
                              f.description as fund_description, f.balance as fund_balance, f.type as fund_type
                       FROM purchases p
                       JOIN raw_materials rm ON p.material_id = rm.id
                       LEFT JOIN users u_purchased ON p.purchased_by = u_purchased.id
                       LEFT JOIN funds f ON p.fund_id = f.id
                       WHERE p.id = ?";
    $purchase_stmt = $db->prepare($purchase_query);
    $purchase_stmt->execute([$purchase_id]);
    $purchase = $purchase_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$purchase) {
        throw new Exception("Purchase record not found.");
    }

    // Log viewing the page.
    $auth->logActivity(
        $_SESSION['user_id'],
        'read',
        'purchases',
        'Viewed details for purchase ID: ' . htmlspecialchars($purchase['id']) . ' (Invoice: ' . htmlspecialchars($purchase['invoice_number']) . ')',
        $purchase_id
    );

} catch (Exception $e) {
    // Log the error.
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'purchases',
        'Error loading purchase details: ' . $e->getMessage(),
        $purchase_id ?? null
    );
    $_SESSION['error_message'] = $e->getMessage();
    header('Location: purchases.php'); // Redirect if purchase not found or invalid ID
    exit;
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt;
    <a href="purchases.php">Purchases</a> &gt;
    <span>Purchase #<?php echo htmlspecialchars($purchase['id']); ?></span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>Purchase Details: #<?php echo htmlspecialchars($purchase['id']); ?></h2>
        <?php if ($purchase['invoice_number']): ?>
            <small>Invoice: <?php echo htmlspecialchars($purchase['invoice_number']); ?></small>
        <?php endif; ?>
    </div>
    <div class="page-actions">
        <a href="purchases.php" class="button secondary">
            <i class="fas fa-arrow-left"></i> Back to Purchases
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
    <div class="card purchase-details-card">
        <div class="card-header">
            <h3>Purchase Information</h3>
        </div>
        <div class="card-content">
            <div class="row">
                <div class="col-md-6">
                    <p><strong>Raw Material:</strong> <?php echo htmlspecialchars($purchase['material_name']); ?> (Unit: <?php echo htmlspecialchars(ucfirst($purchase['material_unit'])); ?>)</p>
                    <p><strong>Quantity:</strong> <?php echo number_format($purchase['quantity'], 2); ?> <?php echo htmlspecialchars($purchase['material_unit']); ?></p>
                    <p><strong>Unit Price:</strong> Rs. <?php echo number_format($purchase['unit_price'], 2); ?></p>
                    <p><strong>Total Amount:</strong> Rs. <?php echo number_format($purchase['total_amount'], 2); ?></p>
                    <p><strong>Purchase Date:</strong> <?php echo htmlspecialchars($purchase['purchase_date']); ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>Vendor Name:</strong> <?php echo htmlspecialchars($purchase['vendor_name'] ?: 'N/A'); ?></p>
                    <p><strong>Vendor Contact:</strong> <?php echo htmlspecialchars($purchase['vendor_contact'] ?: 'N/A'); ?></p>
                    <p><strong>Invoice Number:</strong> <?php echo htmlspecialchars($purchase['invoice_number'] ?: 'N/A'); ?></p>
                    <p><strong>Purchased By:</strong> <?php echo htmlspecialchars($purchase['purchased_by_name'] ?: 'N/A'); ?></p>
                    <p><strong>Recorded At:</strong> <?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($purchase['created_at']))); ?></p>
                    <?php if ($purchase['fund_id']): ?>
                        <p><strong>Fund Used:</strong> <a href="funds.php?fund_id=<?php echo htmlspecialchars($purchase['fund_id']); ?>">Fund #<?php echo htmlspecialchars($purchase['fund_id']); ?></a> (<?php echo htmlspecialchars($purchase['fund_description']); ?>)</p>
                        <p><strong>Fund Type:</strong> <?php echo htmlspecialchars(ucfirst($purchase['fund_type'])); ?></p>
                        <p><strong>Fund Balance After Use:</strong> Rs. <?php echo number_format($purchase['fund_balance'], 2); ?> (This fund's current balance after all uses)</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include_once '../includes/footer.php'; ?>