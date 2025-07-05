<?php
// File: products.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Include header.
$page_title = "Products";
include_once '../includes/header.php';

// Check user authentication and role (e.g., 'owner', 'incharge', 'shopkeeper' can view products).
if (!isset($_SESSION['user_id'])) { // Assuming anyone logged in can view products
    $_SESSION['error_message'] = "Unauthorized access. Please log in.";
    header('Location: index.php'); // Redirect to login page
    exit;
}

// Get database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

// Pagination settings.
$limit = 15;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Filters.
$search_name_sku = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_category = isset($_GET['category']) ? trim($_GET['category']) : '';

$where_clauses = [];
$params = [];

if (!empty($search_name_sku)) {
    $where_clauses[] = "(p.name LIKE ? OR p.sku LIKE ?)";
    $params[] = '%' . $search_name_sku . '%';
    $params[] = '%' . $search_name_sku . '%';
}
if (!empty($filter_category)) {
    $where_clauses[] = "p.category = ?";
    $params[] = $filter_category;
}

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

try {
    // Total records for pagination.
    $count_query = "SELECT COUNT(*) FROM products p " . $where_sql;
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    // Fetch products.
    $query = "SELECT p.*, u.full_name as created_by_name, u.username as created_by_username,
                     COALESCE(SUM(CASE WHEN i.location = 'manufacturing' THEN i.quantity ELSE 0 END), 0) AS manufacturing_stock,
                     COALESCE(SUM(CASE WHEN i.location = 'wholesale' THEN i.quantity ELSE 0 END), 0) AS wholesale_stock,
                     COALESCE(SUM(CASE WHEN i.location = 'transit' THEN i.quantity ELSE 0 END), 0) AS transit_stock
              FROM products p
              LEFT JOIN users u ON p.created_by = u.id
              LEFT JOIN inventory i ON p.id = i.product_id
              " . $where_sql . "
              GROUP BY p.id, p.name, p.description, p.sku, p.category, p.created_by, p.created_at, p.updated_at, p.image_path, u.full_name, u.username
              ORDER BY p.name ASC
              LIMIT ? OFFSET ?";
    $stmt = $db->prepare($query);
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch distinct categories for filter dropdown.
    $distinct_categories_query = "SELECT DISTINCT category FROM products ORDER BY category ASC";
    $distinct_categories_stmt = $db->query($distinct_categories_query);
    $distinct_categories = $distinct_categories_stmt->fetchAll(PDO::FETCH_COLUMN);

    // Log viewing the page.
    $auth->logActivity(
        $_SESSION['user_id'],
        'read',
        'products',
        'Viewed products list page' . (!empty($where_sql) ? ' with filters' : ''),
        null
    );

} catch (PDOException $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'products',
        'Database error fetching products: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = "A database error occurred while fetching products. Please try again later.";
    $products = [];
    $total_pages = 0;
} catch (Exception $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'products',
        'Error fetching products: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = $e->getMessage();
    $products = [];
    $total_pages = 0;
}

// Helper function for category color (can be moved to utils.js later)
function getCategoryColor($category) {
    switch ($category) {
        case 'Apparel': return 'status-primary';
        case 'Accessories': return 'status-info';
        case 'Footwear': return 'status-warning';
        case 'Textile': return 'status-secondary';
        default: return 'status-default';
    }
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt; <span>Products</span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>Product Catalog</h2>
    </div>
    <div class="page-actions">
        <a href="add-product.php" class="button primary">
            <i class="fas fa-plus"></i> Add New Product
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
            <h3>Filter Products</h3>
        </div>
        <div class="card-content">
            <form method="GET" action="products.php">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label for="search">Search:</label>
                        <input type="text" id="search" name="search" class="form-control" value="<?php echo htmlspecialchars($search_name_sku); ?>" placeholder="Name or SKU">
                    </div>
                    <div class="form-group col-md-4">
                        <label for="category">Category:</label>
                        <select id="category" name="category" class="form-control">
                            <option value="">All Categories</option>
                            <?php foreach ($distinct_categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $filter_category == $cat ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-4 d-flex align-items-end">
                        <button type="submit" class="button primary">Apply Filters</button>
                        <a href="products.php" class="button secondary ml-2">Clear Filters</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h3>Product Records (Total: <?php echo $total_records; ?>)</h3>
        </div>
        <div class="card-content">
            <div class="table-responsive">
                <?php if (count($products) > 0): ?>
                <table class="table table-hover sortable-table">
                    <thead>
                        <tr>
                            <th class="sortable">ID</th>
                            <th class="sortable">Image</th>
                            <th class="sortable">Name</th>
                            <th class="sortable">SKU</th>
                            <th class="sortable">Category</th>
                            <th class="sortable">Manuf. Stock</th>
                            <th class="sortable">Wholesale Stock</th>
                            <th class="sortable">Transit Stock</th>
                            <th class="sortable">Created By</th>
                            <th class="sortable">Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($product['id']); ?></td>
                                <td>
                                    <?php if (!empty($product['image_path'])): ?>
                                        <img src="../<?php echo htmlspecialchars($product['image_path']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" style="width: 50px; height: auto; border-radius: 4px;">
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td><?php echo htmlspecialchars($product['sku']); ?></td>
                                <td><span class="status-badge <?php echo getCategoryColor($product['category']); ?>"><?php echo htmlspecialchars($product['category']); ?></span></td>
                                <td><?php echo number_format($product['manufacturing_stock']); ?></td>
                                <td><?php echo number_format($product['wholesale_stock']); ?></td>
                                <td><?php echo number_format($product['transit_stock']); ?></td>
                                <td><?php echo htmlspecialchars($product['created_by_name'] ? $product['created_by_name'] . ' (' . $product['created_by_username'] . ')' : 'System'); ?></td>
                                <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($product['created_at']))); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="add-product.php?id=<?php echo htmlspecialchars($product['id']); ?>" class="button button-sm button-primary" title="Edit Product">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <button type="button" class="button button-sm button-danger delete-product-btn"
                                                data-id="<?php echo htmlspecialchars($product['id']); ?>"
                                                data-name="<?php echo htmlspecialchars($product['name']); ?>"
                                                title="Delete Product">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="text-center p-4">
                        <p>No products found. Click "Add New Product" to get started!</p>
                        <a href="add-product.php" class="button primary mt-3">
                            <i class="fas fa-plus"></i> Add New Product
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Close alert buttons (existing logic)
    const alertCloseButtons = document.querySelectorAll('.alert-close');
    alertCloseButtons.forEach(button => {
        button.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });

    // --- Delete Product Logic ---
    const deleteProductBtns = document.querySelectorAll('.delete-product-btn');
    deleteProductBtns.forEach(button => {
        button.addEventListener('click', function() {
            const productId = this.dataset.id;
            const productName = this.dataset.name;

            if (confirm(`Are you sure you want to delete product "${productName}" (ID: ${productId})? This action is irreversible.`)) {
                // Show loading state (e.g., disable button, add spinner)
                button.disabled = true;
                button.innerHTML = '<span class="spinner"></span>'; // Or specific icon

                fetch('../api/delete-product.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `product_id=${productId}`
                })
                .then(response => response.json())
                .then(data => {
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-trash"></i> Delete'; // Reset button text

                    if (typeof showToast === 'function') { // Use showToast for notifications
                        showToast(data.success ? 'success' : 'error', data.message);
                    } else {
                        alert(data.message); // Fallback
                    }
                    if (data.success) {
                        window.location.reload(); // Reload page to reflect changes
                    }
                })
                .catch(error => {
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-trash"></i> Delete';
                    console.error('Error deleting product:', error);
                    if (typeof showToast === 'function') {
                        showToast('error', 'An unexpected error occurred while deleting the product.');
                    } else {
                        alert('An unexpected error occurred.');
                    }
                });
            }
        });
    });

    // Helper functions (can be moved to utils.js later for global use)
    // Placeholder for spinner CSS in your global stylesheet
    /*
    .spinner {
        border: 2px solid #f3f3f3;
        border-top: 2px solid #3498db;
        border-radius: 50%;
        width: 1em;
        height: 1em;
        animation: spin 1s linear infinite;
        display: inline-block;
        vertical-align: middle;
        margin-right: 0.5em;
    }
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    */
});
</script>

<?php include_once '../includes/footer.php'; ?>