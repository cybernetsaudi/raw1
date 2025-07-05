<?php
// File: includes/header.php

// This file is included AFTER session_start() in every page that uses it.
// Therefore, session_start() should NOT be called here.
// ini_set('display_errors', 1); // Should be managed by central config for production
// error_reporting(E_ALL); // Should be managed by central config for production

// Include database config and Auth class if not already included by the calling script.
// It's assumed the main page (e.g., dashboard.php) will include these and instantiate $db and $auth.
// If this header is used on pages without direct DB connection, these includes might need to be conditional
// or removed, and $auth passed. For now, assuming pages including header have DB/Auth setup.
if (!isset($database) || !isset($db)) {
    include_once '../config/database.php';
    $database = new Database();
    $db = $database->getConnection();
}
if (!isset($auth)) {
    include_once '../config/auth.php';
    $auth = new Auth($db);
}

// Redirect to login if not logged in (unless on login/index page itself).
// This check is often put at the top of every secured page, not just in the header.
// Assuming each page properly handles its own authentication check before including the header.
// Example: if (!$auth->isLoggedIn()) { header('Location: index.php'); exit; }

// Generate CSRF token for forms (to be done in a later dedicated step)
// $csrf_token = generate_csrf_token(); // Function to be created

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title ?? 'Dashboard'); ?> - Garment Manufacturing System</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <?php if (isset($is_dashboard_page) && $is_dashboard_page): ?>
        <link rel="stylesheet" href="../assets/css/dashboard-styles.css">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <?php if (isset($_SESSION['user_id'])): ?>
        <input type="hidden" id="current-user-id" value="<?php echo htmlspecialchars($_SESSION['user_id']); ?>">
    <?php endif; ?>

    <div id="toast-container"></div>

    <div class="sidebar">
        <div class="sidebar-header">
            <h3>Garment System</h3>
        </div>
        <ul class="sidebar-menu">
            <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <?php if (isset($_SESSION['role']) && ($_SESSION['role'] === 'owner' || $_SESSION['role'] === 'incharge')): ?>
            <li><a href="raw-materials.php"><i class="fas fa-boxes"></i> Raw Materials</a></li>
            <li><a href="purchases.php"><i class="fas fa-shopping-basket"></i> Purchases</a></li>
            <li><a href="manufacturing.php"><i class="fas fa-industry"></i> Manufacturing</a></li>
            <?php endif; ?>
            <li><a href="products.php"><i class="fas fa-tshirt"></i> Products</a></li>
            <?php if (isset($_SESSION['role']) && ($_SESSION['role'] === 'shopkeeper' || $_SESSION['role'] === 'owner')): ?>
            <li><a href="sales.php"><i class="fas fa-chart-line"></i> Sales</a></li>
            <li><a href="payments.php"><i class="fas fa-receipt"></i> Payments</a></li>
            <li><a href="customers.php"><i class="fas fa-users"></i> Customers</a></li>
            <?php endif; ?>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'owner'): ?>
            <li><a href="funds.php"><i class="fas fa-money-bill-wave"></i> Funds</a></li>
            <li><a href="fund-returns.php"><i class="fas fa-undo-alt"></i> Fund Returns</a></li>
            <li><a href="reports.php"><i class="fas fa-file-alt"></i> Reports</a></li>
            <li><a href="users.php"><i class="fas fa-user-shield"></i> Users</a></li>
            <li><a href="activity-logs.php"><i class="fas fa-history"></i> Activity Logs</a></li>
            <?php endif; ?>
        </ul>
    </div>
    <div class="main-content">
        <div class="navbar">
            <div class="navbar-left">
                </div>
            <div class="navbar-right">
                <span class="user-info">Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Guest'); ?> (<?php echo htmlspecialchars(ucfirst($_SESSION['role'] ?? '')); ?>)</span>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
        <div class="content-wrapper">
            ```

---

#### 13. Modifying `footer.php` (Adding Loading Overlay and JS Includes)

We will update `footer.php` to include:
* The HTML structure for the global loading overlay, used by `showLoading()` and `hideLoading()` in `utils.js`.
* Proper inclusion of `utils.js` and `script.js`.

```php
<?php
// File: includes/footer.php

// Close the main-content and content-wrapper divs opened in header.php
?>
        </div> </div> <div id="loading-overlay">
        <div class="spinner-border" role="status"></div>
        <div class="loading-text">Loading...</div>
    </div>

    <script src="../assets/js/utils.js"></script>
    <script src="../assets/js/script.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // This global listener can catch alert-close buttons from initial PHP-rendered alerts
            // that are not yet replaced by showToast.
            const alertCloseButtons = document.querySelectorAll('.alert-close');
            alertCloseButtons.forEach(button => {
                button.addEventListener('click', function() {
                    this.parentElement.style.display = 'none';
                });
            });

            // Any other global DOMContentLoaded initialization not handled by utils.js
        });
    </script>
</body>
</html>