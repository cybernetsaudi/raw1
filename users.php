<?php
// File: users.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Include header.
$page_title = "User Management";
include_once '../includes/header.php';

// Check user authentication and role (e.g., only 'owner' can manage users).
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to manage users.";
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
$search_name_username_email = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_role = isset($_GET['role']) ? trim($_GET['role']) : '';
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : ''; // 'active', 'inactive'

$where_clauses = [];
$params = [];

if (!empty($search_name_username_email)) {
    $where_clauses[] = "(full_name LIKE ? OR username LIKE ? OR email LIKE ?)";
    $params[] = '%' . $search_name_username_email . '%';
    $params[] = '%' . $search_name_username_email . '%';
    $params[] = '%' . $search_name_username_email . '%';
}
if (!empty($filter_role)) {
    $where_clauses[] = "role = ?";
    $params[] = $filter_role;
}
if (!empty($filter_status)) {
    if ($filter_status === 'active') {
        $where_clauses[] = "is_active = 1";
    } elseif ($filter_status === 'inactive') {
        $where_clauses[] = "is_active = 0";
    }
}

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

try {
    // Total records for pagination.
    $count_query = "SELECT COUNT(*) FROM users " . $where_sql;
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    // Fetch users.
    $query = "SELECT id, username, full_name, email, role, phone, is_active, created_at FROM users "
             . $where_sql . " ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $stmt = $db->prepare($query);
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Log viewing the page.
    $auth->logActivity(
        $_SESSION['user_id'],
        'read',
        'users',
        'Viewed user management page' . (!empty($where_sql) ? ' with filters' : ''),
        null
    );

} catch (PDOException $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'users',
        'Database error fetching users: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = "A database error occurred while fetching users. Please try again later.";
    $users = [];
    $total_pages = 0;
} catch (Exception $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'users',
        'Error fetching users: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = $e->getMessage();
    $users = [];
    $total_pages = 0;
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt; <span>User Management</span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>User Management</h2>
    </div>
    <div class="page-actions">
        <button type="button" class="button primary" id="addUserBtn">
            <i class="fas fa-plus"></i> Add New User
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
            <h3>Filter Users</h3>
        </div>
        <div class="card-content">
            <form method="GET" action="users.php">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label for="search">Search:</label>
                        <input type="text" id="search" name="search" class="form-control" value="<?php echo htmlspecialchars($search_name_username_email); ?>" placeholder="Name, Username, or Email">
                    </div>
                    <div class="form-group col-md-3">
                        <label for="role">Role:</label>
                        <select id="role" name="role" class="form-control">
                            <option value="">All Roles</option>
                            <option value="owner" <?php echo $filter_role == 'owner' ? 'selected' : ''; ?>>Owner</option>
                            <option value="incharge" <?php echo $filter_role == 'incharge' ? 'selected' : ''; ?>>Incharge</option>
                            <option value="shopkeeper" <?php echo $filter_role == 'shopkeeper' ? 'selected' : ''; ?>>Shopkeeper</option>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <label for="status">Status:</label>
                        <select id="status" name="status" class="form-control">
                            <option value="">All Statuses</option>
                            <option value="active" <?php echo $filter_status == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $filter_status == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="form-group col-md-2 d-flex align-items-end">
                        <button type="submit" class="button primary">Apply Filters</button>
                        <a href="users.php" class="button secondary ml-2">Clear Filters</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h3>User Records (Total: <?php echo $total_records; ?>)</h3>
        </div>
        <div class="card-content">
            <div class="table-responsive">
                <?php if (count($users) > 0): ?>
                <table class="table table-hover sortable-table">
                    <thead>
                        <tr>
                            <th class="sortable">ID</th>
                            <th class="sortable">Username</th>
                            <th class="sortable">Full Name</th>
                            <th class="sortable">Email</th>
                            <th class="sortable">Role</th>
                            <th class="sortable">Phone</th>
                            <th class="sortable">Status</th>
                            <th class="sortable">Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['id']); ?></td>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><span class="user-role-badge role-<?php echo htmlspecialchars($user['role']); ?>"><?php echo ucfirst(htmlspecialchars($user['role'])); ?></span></td>
                                <td><?php echo htmlspecialchars($user['phone'] ?: 'N/A'); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                                        <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($user['created_at']))); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="button button-sm button-primary edit-user-btn"
                                                data-id="<?php echo htmlspecialchars($user['id']); ?>"
                                                data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                                data-full-name="<?php echo htmlspecialchars($user['full_name']); ?>"
                                                data-email="<?php echo htmlspecialchars($user['email']); ?>"
                                                data-role="<?php echo htmlspecialchars($user['role']); ?>"
                                                data-phone="<?php echo htmlspecialchars($user['phone']); ?>"
                                                data-is-active="<?php echo htmlspecialchars($user['is_active']); ?>"
                                                title="Edit User">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <?php if ($user['id'] !== $_SESSION['user_id']): // Cannot deactivate/delete self ?>
                                            <button type="button" class="button button-sm <?php echo $user['is_active'] ? 'button-danger' : 'button-success'; ?> toggle-active-btn"
                                                    data-id="<?php echo htmlspecialchars($user['id']); ?>"
                                                    data-current-status="<?php echo htmlspecialchars($user['is_active']); ?>"
                                                    data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                                    title="<?php echo $user['is_active'] ? 'Deactivate User' : 'Activate User'; ?>">
                                                <i class="fas fa-power-off"></i> <?php echo $user['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                            </button>
                                            <button type="button" class="button button-sm button-danger delete-user-btn"
                                                    data-id="<?php echo htmlspecialchars($user['id']); ?>"
                                                    data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                                    data-full-name="<?php echo htmlspecialchars($user['full_name']); ?>"
                                                    title="Delete User">
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
                        <p>No users found. Click "Add New User" to get started!</p>
                        <button type="button" class="button primary mt-3" id="emptyStateAddUserBtn">
                            <i class="fas fa-plus"></i> Add New User
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

<div id="userModal" class="modal">
    <div class="modal-content">
        <span class="close-button">&times;</span>
        <h3 id="userModalTitle">Add New User</h3>
        <form id="userForm" method="post" action="../api/save-user.php">
            <input type="hidden" id="user_id" name="user_id">
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="full_name">Full Name:</label>
                <input type="text" id="full_name" name="full_name" required>
            </div>
            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" required>
            </div>
            <div class="form-group">
                <label for="role">Role:</label>
                <select id="role" name="role" required>
                    <option value="">Select Role</option>
                    <option value="owner">Owner</option>
                    <option value="incharge">Incharge</option>
                    <option value="shopkeeper">Shopkeeper</option>
                </select>
            </div>
            <div class="form-group">
                <label for="phone">Phone (Optional):</label>
                <input type="text" id="phone" name="phone">
            </div>
            <div class="form-group" id="passwordGroup">
                <label for="password">Password (Leave blank to keep current):</label>
                <input type="password" id="password" name="password" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="is_active">Status:</label>
                <select id="is_active" name="is_active" required>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
            <div class="form-actions">
                <button type="button" class="button secondary close-button">Cancel</button>
                <button type="submit" class="button primary">Save User</button>
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

    const userModal = document.getElementById('userModal');
    const closeButtons = userModal.querySelectorAll('.close-button');
    const addUserBtn = document.getElementById('addUserBtn');
    const emptyStateAddUserBtn = document.getElementById('emptyStateAddUserBtn');
    const editUserBtns = document.querySelectorAll('.edit-user-btn');
    const toggleActiveBtns = document.querySelectorAll('.toggle-active-btn');
    const deleteUserBtns = document.querySelectorAll('.delete-user-btn'); // Select new delete buttons
    const userForm = document.getElementById('userForm');
    const userModalTitle = document.getElementById('userModalTitle');
    const passwordGroup = document.getElementById('passwordGroup');
    const passwordInput = document.getElementById('password');

    // Function to open modal
    function openUserModal() {
        userModal.style.display = 'block';
        userModal.classList.add('show-modal');
    }

    // Function to close modal
    function closeUserModal() {
        userModal.classList.remove('show-modal');
        setTimeout(() => {
            userModal.style.display = 'none';
            userForm.reset();
            document.querySelectorAll('.invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('.validation-error').forEach(el => el.remove());
        }, 300);
    }

    // Attach click listeners to close buttons
    closeButtons.forEach(btn => {
        btn.addEventListener('click', closeUserModal);
    });

    // Close modal if clicking outside
    window.addEventListener('click', function(event) {
        if (event.target == userModal) {
            closeUserModal();
        }
    });

    // Open add user modal
    if (addUserBtn) {
        addUserBtn.addEventListener('click', function() {
            userModalTitle.textContent = 'Add New User';
            userForm.querySelector('#user_id').value = ''; // Clear ID for new user
            userForm.reset(); // Clear form fields
            passwordGroup.style.display = 'block'; // Show password field for new user
            passwordInput.setAttribute('required', 'required'); // Make password required for new user
            openUserModal();
        });
    }

    // Open add user modal from empty state button
    if (emptyStateAddUserBtn) {
        emptyStateAddUserBtn.addEventListener('click', function() {
            userModalTitle.textContent = 'Add New User';
            userForm.querySelector('#user_id').value = '';
            userForm.reset();
            passwordGroup.style.display = 'block';
            passwordInput.setAttribute('required', 'required');
            openUserModal();
        });
    }

    // Open edit user modal
    editUserBtns.forEach(button => {
        button.addEventListener('click', function() {
            userModalTitle.textContent = 'Edit User';
            userForm.querySelector('#user_id').value = this.dataset.id;
            userForm.querySelector('#username').value = this.dataset.username;
            userForm.querySelector('#full_name').value = this.dataset.fullName;
            userForm.querySelector('#email').value = this.dataset.email;
            userForm.querySelector('#role').value = this.dataset.role;
            userForm.querySelector('#phone').value = this.dataset.phone;
            userForm.querySelector('#is_active').value = this.dataset.isActive;
            passwordGroup.style.display = 'block'; // Show password field
            passwordInput.removeAttribute('required'); // Password not required for edit (only if changing)
            passwordInput.value = ''; // Clear password field on load
            openUserModal();
        });
    });

    // Handle user form submission via AJAX (existing, but ensure showToast is used)
    if (userForm) {
        userForm.addEventListener('submit', function(event) {
            event.preventDefault(); // Prevent default form submission

            let isValid = true;
            // Clear previous validation errors
            document.querySelectorAll('.invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('.validation-error').forEach(el => el.remove());

            // Client-side validation
            const usernameInput = document.getElementById('username');
            const fullNameInput = document.getElementById('full_name');
            const emailInput = document.getElementById('email');
            const roleSelect = document.getElementById('role');
            const phoneInput = document.getElementById('phone');

            if (!usernameInput.value.trim()) { showValidationError(usernameInput, 'Username is required.'); isValid = false; }
            if (!fullNameInput.value.trim()) { showValidationError(fullNameInput, 'Full Name is required.'); isValid = false; }
            if (!emailInput.value.trim()) { showValidationError(emailInput, 'Email is required.'); isValid = false; }
            else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailInput.value.trim())) { showValidationError(emailInput, 'Invalid email format.'); isValid = false; }
            if (!roleSelect.value) { showValidationError(roleSelect, 'Role is required.'); isValid = false; }

            // Password validation for new user or if entered for update
            if (passwordInput.hasAttribute('required') && !passwordInput.value.trim()) {
                showValidationError(passwordInput, 'Password is required for new users.');
                isValid = false;
            } else if (!passwordInput.hasAttribute('required') && passwordInput.value.trim() && passwordInput.value.length < 6) {
                // If password is being changed, enforce a minimum length (example)
                showValidationError(passwordInput, 'Password must be at least 6 characters long.');
                isValid = false;
            }

            if (phoneInput.value.trim() && !/^[0-9]{10,15}$/.test(phoneInput.value.trim())) {
                showValidationError(phoneInput, 'Phone number must be 10-15 digits if provided.');
                isValid = false;
            }


            if (!isValid) return;

            // Show loading state
            const submitButton = userForm.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner"></span> Saving...';

            const formData = new FormData(this);
            fetch(this.action, { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    submitButton.disabled = false;
                    submitButton.innerHTML = 'Save User';

                    if (typeof showToast === 'function') {
                        showToast(data.success ? 'success' : 'error', data.message);
                    } else {
                        alert(data.message);
                    }
                    if (data.success) {
                        closeUserModal();
                        window.location.reload(); // Reload page to show updated list
                    }
                })
                .catch(error => {
                    submitButton.disabled = false;
                    submitButton.innerHTML = 'Save User';
                    console.error('Error saving user:', error);
                    if (typeof showToast === 'function') {
                        showToast('error', 'An unexpected error occurred while saving the user.');
                    } else {
                        alert('An unexpected error occurred.');
                    }
                });
        });
    }

    // Handle toggle active/inactive status via AJAX (existing, but ensure showToast is used)
    toggleActiveBtns.forEach(button => {
        button.addEventListener('click', function() {
            const userId = this.dataset.id;
            const currentStatus = parseInt(this.dataset.currentStatus);
            const newStatus = currentStatus === 1 ? 0 : 1;
            const username = this.dataset.username;
            const actionText = newStatus === 1 ? 'activate' : 'deactivate';

            if (confirm(`Are you sure you want to ${actionText} user ${username}?`)) {
                // Show loading state
                button.disabled = true;
                button.innerHTML = '<span class="spinner"></span>'; // Or specific icon

                fetch('../api/toggle-user-activation.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `user_id=${userId}&is_active=${newStatus}`
                })
                .then(response => response.json())
                .then(data => {
                    button.disabled = false;
                    button.innerHTML = `<i class="fas fa-power-off"></i> ${currentStatus === 1 ? 'Deactivate' : 'Activate'}`; // Reset text

                    if (typeof showToast === 'function') {
                        showToast(data.success ? 'success' : 'error', data.message);
                    } else {
                        alert(data.message);
                    }
                    if (data.success) {
                        window.location.reload(); // Reload to reflect status change
                    }
                })
                .catch(error => {
                    button.disabled = false;
                    button.innerHTML = `<i class="fas fa-power-off"></i> ${currentStatus === 1 ? 'Deactivate' : 'Activate'}`;
                    console.error('Error toggling user status:', error);
                    if (typeof showToast === 'function') {
                        showToast('error', 'An unexpected error occurred while toggling user status.');
                    } else {
                        alert('An unexpected error occurred.');
                    }
                });
            }
        });
    });

    // --- Delete User Logic ---
    deleteUserBtns.forEach(button => {
        button.addEventListener('click', function() {
            const userId = this.dataset.id;
            const username = this.dataset.username;
            const fullName = this.dataset.fullName;

            if (confirm(`Are you sure you want to delete user "${fullName} (${username})"? This action is irreversible and will fail if there are associated records (e.g., sales, purchases, batches, etc.).`)) {
                // Show loading state
                button.disabled = true;
                button.innerHTML = '<span class="spinner"></span>'; // Or specific icon

                fetch('../api/delete-user.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `user_id=${userId}`
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
                    console.error('Error deleting user:', error);
                    if (typeof showToast === 'function') {
                        showToast('error', 'An unexpected error occurred while deleting the user.');
                    } else {
                        alert('An unexpected error occurred.');
                    }
                });
            }
        });
    });

    // Helper functions for validation (can be moved to utils.js later for global use)
    function showValidationError(element, message) {
        const formGroup = element.closest('.form-group');
        const existingError = formGroup ? formGroup.querySelector('.validation-error') : null;
        if (existingError) { existingError.remove(); }
        element.classList.add('invalid-input');
        const errorElement = document.createElement('div');
        errorElement.className = 'validation-error';
        errorElement.textContent = message;
        if (formGroup) {
            formGroup.appendChild(errorElement);
        } else {
            element.parentElement.appendChild(errorElement);
        }
        element.focus();
    }

    // Remove validation error when input changes
    document.querySelectorAll('.modal input, .modal select').forEach(input => {
        input.addEventListener('input', function() {
            this.classList.remove('invalid-input');
            const formGroup = this.closest('.form-group');
            const errorElement = formGroup ? formGroup.querySelector('.validation-error') : null;
            if (errorElement) { errorElement.remove(); }
        });
    });
});
</script>

<?php include_once '../includes/footer.php'; ?>