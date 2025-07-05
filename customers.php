<?php
// File: customers.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Include header (which usually handles authentication checks).
$page_title = "Customers";
include_once '../includes/header.php';

// Check user authentication and role (e.g., 'shopkeeper' or 'owner' can view/manage customers).
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'shopkeeper' && $_SESSION['role'] !== 'owner')) {
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to view customers.";
    header('Location: dashboard.php'); // Redirect to dashboard or login
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
$search_name_phone_email = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_created_by = isset($_GET['created_by']) && is_numeric($_GET['created_by']) ? intval($_GET['created_by']) : null;

$where_clauses = [];
$params = [];

if (!empty($search_name_phone_email)) {
    $where_clauses[] = "(c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
    $params[] = '%' . $search_name_phone_email . '%';
    $params[] = '%' . $search_name_phone_email . '%';
    $params[] = '%' . $search_name_phone_email . '%';
}
// If user is shopkeeper, they only see customers they created. Owner sees all.
if ($_SESSION['role'] === 'shopkeeper') {
    $where_clauses[] = "c.created_by = ?";
    $params[] = $_SESSION['user_id'];
} elseif ($filter_created_by) { // Owner can filter by creator
    $where_clauses[] = "c.created_by = ?";
    $params[] = $filter_created_by;
}

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

try {
    // Total records for pagination.
    $count_query = "SELECT COUNT(*) FROM customers c " . $where_sql;
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    // Fetch customers.
    $query = "SELECT c.*, u.full_name as created_by_name, u.username as created_by_username
              FROM customers c
              LEFT JOIN users u ON c.created_by = u.id
              " . $where_sql . "
              ORDER BY c.created_at DESC
              LIMIT ? OFFSET ?";
    $stmt = $db->prepare($query);
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch users for filter dropdown (if owner).
    $creators = [];
    if ($_SESSION['role'] === 'owner') {
        $creators_query = "SELECT id, full_name, username FROM users WHERE role = 'shopkeeper' ORDER BY full_name ASC";
        $creators_stmt = $db->query($creators_query);
        $creators = $creators_stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Log viewing the page.
    $auth->logActivity(
        $_SESSION['user_id'],
        'read',
        'customers',
        'Viewed customers list page' . (!empty($where_sql) ? ' with filters' : ''),
        null
    );

} catch (PDOException $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'customers',
        'Database error fetching customers: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = "A database error occurred while fetching customers. Please try again later.";
    $customers = [];
    $total_pages = 0;
} catch (Exception $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'customers',
        'Error fetching customers: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = $e->getMessage();
    $customers = [];
    $total_pages = 0;
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt; <span>Customers</span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>Customer Management</h2>
    </div>
    <div class="page-actions">
        <button type="button" class="button primary" id="addCustomerBtn">
            <i class="fas fa-plus"></i> Add New Customer
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
    <div class="filter-card card">
        <div class="card-header">
            <h3>Filter Customers</h3>
        </div>
        <div class="card-content">
            <form method="GET" action="customers.php">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label for="search">Search:</label>
                        <input type="text" id="search" name="search" class="form-control" value="<?php echo htmlspecialchars($search_name_phone_email); ?>" placeholder="Name, Phone, or Email">
                    </div>
                    <?php if ($_SESSION['role'] === 'owner'): ?>
                        <div class="form-group col-md-4">
                            <label for="created_by">Created By:</label>
                            <select id="created_by" name="created_by" class="form-control">
                                <option value="">All Shopkeepers</option>
                                <?php foreach ($creators as $creator): ?>
                                    <option value="<?php echo htmlspecialchars($creator['id']); ?>" <?php echo $filter_created_by == $creator['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($creator['full_name'] . ' (' . $creator['username'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="form-group <?php echo ($_SESSION['role'] === 'owner') ? 'col-md-4' : 'col-md-8'; ?> d-flex align-items-end">
                        <button type="submit" class="button primary">Apply Filters</button>
                        <a href="customers.php" class="button secondary ml-2">Clear Filters</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h3>Customer Records (Total: <?php echo $total_records; ?>)</h3>
        </div>
        <div class="card-content">
            <div class="table-responsive">
                <?php if (count($customers) > 0): ?>
                <table class="table table-hover sortable-table">
                    <thead>
                        <tr>
                            <th class="sortable">ID</th>
                            <th class="sortable">Name</th>
                            <th class="sortable">Phone</th>
                            <th class="sortable">Email</th>
                            <th class="sortable">Address</th>
                            <th class="sortable">Created By</th>
                            <th class="sortable">Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $customer): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($customer['id']); ?></td>
                                <td><?php echo htmlspecialchars($customer['name']); ?></td>
                                <td><?php echo htmlspecialchars($customer['phone'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($customer['email'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($customer['address'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($customer['created_by_name'] ? $customer['created_by_name'] . ' (' . $customer['created_by_username'] . ')' : 'System'); ?></td>
                                <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($customer['created_at']))); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="button button-sm button-primary edit-customer-btn"
                                                data-id="<?php echo htmlspecialchars($customer['id']); ?>"
                                                data-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                                data-phone="<?php echo htmlspecialchars($customer['phone']); ?>"
                                                data-email="<?php echo htmlspecialchars($customer['email']); ?>"
                                                data-address="<?php echo htmlspecialchars($customer['address']); ?>"
                                                title="Edit Customer">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <?php if ($_SESSION['role'] === 'shopkeeper' && $customer['created_by'] === $_SESSION['user_id'] || $_SESSION['role'] === 'owner'): ?>
                                            <button type="button" class="button button-sm button-danger delete-customer-btn"
                                                    data-id="<?php echo htmlspecialchars($customer['id']); ?>"
                                                    data-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                                    title="Delete Customer">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="text-center p-4">
                        <p>No customers found. Click "Add New Customer" to get started!</p>
                        <button type="button" class="button primary mt-3" id="emptyStateAddBtn">
                            <i class="fas fa-plus"></i> Add New Customer
                        </button>
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

<div id="customerModal" class="modal">
    <div class="modal-content">
        <span class="close-button">&times;</span>
        <h3 id="modalTitle">Add New Customer</h3>
        <form id="customerForm" method="post" action="../api/save-customer.php">
            <input type="hidden" id="customer_id" name="customer_id">
            <div class="form-group">
                <label for="customer_name">Name:</label>
                <input type="text" id="customer_name" name="name" required>
            </div>
            <div class="form-group">
                <label for="customer_phone">Phone:</label>
                <input type="text" id="customer_phone" name="phone" required>
            </div>
            <div class="form-group">
                <label for="customer_email">Email (Optional):</label>
                <input type="email" id="customer_email" name="email">
            </div>
            <div class="form-group">
                <label for="customer_address">Address (Optional):</label>
                <textarea id="customer_address" name="address" rows="3"></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="button secondary close-button">Cancel</button>
                <button type="submit" class="button primary">Save Customer</button>
            </div>
        </form>
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

    const customerModal = document.getElementById('customerModal');
    const closeButtons = customerModal.querySelectorAll('.close-button');
    const addCustomerBtn = document.getElementById('addCustomerBtn');
    const emptyStateAddBtn = document.getElementById('emptyStateAddBtn');
    const editCustomerBtns = document.querySelectorAll('.edit-customer-btn');
    const deleteCustomerBtns = document.querySelectorAll('.delete-customer-btn'); // Select new delete buttons
    const customerForm = document.getElementById('customerForm');
    const modalTitle = document.getElementById('modalTitle');

    // Function to open modal
    function openModal() {
        customerModal.style.display = 'block';
        customerModal.classList.add('show-modal'); // For animation
    }

    // Function to close modal
    function closeModal() {
        customerModal.classList.remove('show-modal'); // For animation
        setTimeout(() => {
            customerModal.style.display = 'none';
            // Clear form and validation errors on close
            customerForm.reset();
            document.querySelectorAll('.invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('.validation-error').forEach(el => el.remove());
        }, 300); // Match animation duration
    }

    // Attach click listeners to close buttons
    closeButtons.forEach(btn => {
        btn.addEventListener('click', closeModal);
    });

    // Close modal if clicking outside
    window.addEventListener('click', function(event) {
        if (event.target == customerModal) {
            closeModal();
        }
    });

    // Open add customer modal
    if (addCustomerBtn) {
        addCustomerBtn.addEventListener('click', function() {
            modalTitle.textContent = 'Add New Customer';
            customerForm.querySelector('#customer_id').value = ''; // Clear ID for new customer
            customerForm.reset(); // Clear form fields
            openModal();
        });
    }

    // Open add customer modal from empty state button
    if (emptyStateAddBtn) {
        emptyStateAddBtn.addEventListener('click', function() {
            modalTitle.textContent = 'Add New Customer';
            customerForm.querySelector('#customer_id').value = '';
            customerForm.reset();
            openModal();
        });
    }

    // Open edit customer modal
    editCustomerBtns.forEach(button => {
        button.addEventListener('click', function() {
            modalTitle.textContent = 'Edit Customer';
            customerForm.querySelector('#customer_id').value = this.dataset.id;
            customerForm.querySelector('#customer_name').value = this.dataset.name;
            customerForm.querySelector('#customer_phone').value = this.dataset.phone;
            customerForm.querySelector('#customer_email').value = this.dataset.email;
            customerForm.querySelector('#customer_address').value = this.dataset.address;
            openModal();
        });
    });

    // Handle customer form submission via AJAX (existing, but ensure showToast is used)
    if (customerForm) {
        customerForm.addEventListener('submit', function(event) {
            event.preventDefault(); // Prevent default form submission

            let isValid = true;
            // Clear previous validation errors
            document.querySelectorAll('.invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('.validation-error').forEach(el => el.remove());

            // Client-side validation
            const nameInput = document.getElementById('customer_name');
            const phoneInput = document.getElementById('customer_phone');
            const emailInput = document.getElementById('customer_email');

            if (!nameInput.value.trim()) {
                showValidationError(nameInput, 'Customer name is required.', nameInput);
                isValid = false;
            }
            if (!phoneInput.value.trim()) {
                showValidationError(phoneInput, 'Phone number is required.', phoneInput);
                isValid = false;
            } else if (!/^[0-9]{10,15}$/.test(phoneInput.value.trim())) {
                showValidationError(phoneInput, 'Phone number must be 10-15 digits.', phoneInput);
                isValid = false;
            }
            if (emailInput.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailInput.value.trim())) {
                showValidationError(emailInput, 'Invalid email format.', emailInput);
                isValid = false;
            }

            if (!isValid) {
                return; // Stop if validation fails
            }

            // Show loading state
            const submitButton = customerForm.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner"></span> Saving...';

            const formData = new FormData(this); // Get form data
            const actionUrl = this.action; // Get form action URL

            fetch(actionUrl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                submitButton.disabled = false;
                submitButton.innerHTML = 'Save Customer'; // Reset button text

                if (typeof showToast === 'function') {
                    showToast(data.success ? 'success' : 'error', data.message);
                } else {
                    alert(data.message);
                }
                if (data.success) {
                    closeModal();
                    window.location.reload(); // Reload page to show updated list
                }
            })
            .catch(error => {
                submitButton.disabled = false;
                submitButton.innerHTML = 'Save Customer';
                console.error('Error:', error);
                if (typeof showToast === 'function') {
                    showToast('error', 'An unexpected error occurred. Please try again.');
                } else {
                    alert('An unexpected error occurred.');
                }
            });
        });
    }

    // --- Delete Customer Logic ---
    deleteCustomerBtns.forEach(button => {
        button.addEventListener('click', function() {
            const customerId = this.dataset.id;
            const customerName = this.dataset.name;

            if (confirm(`Are you sure you want to delete customer "${customerName}" (ID: ${customerId})? This action is irreversible and will fail if there are associated sales records.`)) {
                // Show loading state
                button.disabled = true;
                button.innerHTML = '<span class="spinner"></span>'; // Or specific icon

                fetch('../api/delete-customer.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `customer_id=${customerId}`
                })
                .then(response => response.json())
                .then(data => {
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-trash"></i> Delete'; // Reset button text

                    if (typeof showToast === 'function') {
                        showToast(data.success ? 'success' : 'error', data.message);
                    } else {
                        alert(data.message);
                    }
                    if (data.success) {
                        window.location.reload(); // Reload page to reflect changes
                    }
                })
                .catch(error => {
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-trash"></i> Delete';
                    console.error('Error deleting customer:', error);
                    if (typeof showToast === 'function') {
                        showToast('error', 'An unexpected error occurred while deleting the customer.');
                    } else {
                        alert('An unexpected error occurred.');
                    }
                });
            }
        });
    });

    // Helper functions for validation (can be moved to utils.js later for global use)
    function showValidationError(element, message) {
        // Find the closest .form-group and then search within it
        const formGroup = element.closest('.form-group');
        const existingError = formGroup ? formGroup.querySelector('.validation-error') : null;
        if (existingError) {
            existingError.remove();
        }

        element.classList.add('invalid-input');

        const errorElement = document.createElement('div');
        errorElement.className = 'validation-error';
        errorElement.textContent = message;

        if (formGroup) {
            formGroup.appendChild(errorElement);
        } else {
            // Fallback if not within a form-group (less ideal)
            element.parentElement.appendChild(errorElement);
        }
        element.focus();
    }

    // Remove validation error when input changes
    const formInputs = customerForm.querySelectorAll('input, select, textarea');
    formInputs.forEach(input => {
        input.addEventListener('input', function() {
            this.classList.remove('invalid-input');
            const formGroup = this.closest('.form-group');
            const errorElement = formGroup ? formGroup.querySelector('.validation-error') : null;
            if (errorElement) {
                errorElement.remove();
            }
        });
    });
});
</script>

<?php include_once '../includes/footer.php'; ?>