<?php
// File: funds.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Include header.
$page_title = "Fund Management";
include_once '../includes/header.php';

// Check user authentication and role (e.g., 'owner' or 'incharge' can manage funds).
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'incharge')) {
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to manage funds.";
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
$filter_fund_type = isset($_GET['type']) ? trim($_GET['type']) : '';
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$filter_from_user = isset($_GET['from_user']) && is_numeric($_GET['from_user']) ? intval($_GET['from_user']) : null;
$filter_to_user = isset($_GET['to_user']) && is_numeric($_GET['to_user']) ? intval($_GET['to_user']) : null;
$filter_start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$filter_end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

$where_clauses = [];
$params = [];

if (!empty($filter_fund_type)) {
    $where_clauses[] = "f.type = ?";
    $params[] = $filter_fund_type;
}
if (!empty($filter_status)) {
    $where_clauses[] = "f.status = ?";
    $params[] = $filter_status;
}
if ($filter_from_user) {
    $where_clauses[] = "f.from_user_id = ?";
    $params[] = $filter_from_user;
}
if ($filter_to_user) {
    $where_clauses[] = "f.to_user_id = ?";
    $params[] = $filter_to_user;
}
if (!empty($filter_start_date)) {
    $where_clauses[] = "f.transfer_date >= ?";
    $params[] = $filter_start_date . ' 00:00:00';
}
if (!empty($filter_end_date)) {
    $where_clauses[] = "f.transfer_date <= ?";
    $params[] = $filter_end_date . ' 23:59:59';
}

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

try {
    // Total records for pagination.
    $count_query = "SELECT COUNT(*) FROM funds f " . $where_sql;
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    // Fetch funds data (using the fund_balances view for aggregated data)
    $query = "SELECT fb.* FROM fund_balances fb " . $where_sql . " ORDER BY fb.transfer_date DESC LIMIT ? OFFSET ?";
    // Note: The fund_balances view does not have 'created_at', it has 'transfer_date'.
    // If filtering by transfer_date for fund_balances, ensure parameters align.
    // `fund_balances` view has `transfer_date` - used for sorting and filtering.
    // The where_sql must be adapted for view columns if they differ from table columns.
    // The current $where_clauses assume `funds` table columns. For `fund_balances` view,
    // columns are `fund_id`, `original_amount`, `current_balance`, `status`, `type`,
    // `from_user`, `to_user`, `used_amount`, `transfer_date`.
    // We need to map filters to view columns.
    $view_where_clauses = [];
    $view_params = [];

    if (!empty($filter_fund_type)) { $view_where_clauses[] = "fb.type = ?"; $view_params[] = $filter_fund_type; }
    if (!empty($filter_status)) { $view_where_clauses[] = "fb.status = ?"; $view_params[] = $filter_status; }
    if ($filter_from_user) { $view_where_clauses[] = "f.from_user_id = ?"; $view_params[] = $filter_from_user; } // This would need a join to funds table
    if ($filter_to_user) { $view_where_clauses[] = "f.to_user_id = ?"; $view_params[] = $filter_to_user; } // This would need a join to funds table
    if (!empty($filter_start_date)) { $view_where_clauses[] = "fb.transfer_date >= ?"; $view_params[] = $filter_start_date . ' 00:00:00'; }
    if (!empty($filter_end_date)) { $view_where_clauses[] = "fb.transfer_date <= ?"; $view_params[] = $filter_end_date . ' 23:59:59'; }

    $view_where_sql = count($view_where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $view_where_clauses) : '';

    $funds_query = "SELECT fb.* FROM fund_balances fb " . $view_where_sql . " ORDER BY fb.transfer_date DESC LIMIT ? OFFSET ?";
    $funds_stmt = $db->prepare($funds_query);
    $funds_stmt->execute(array_merge($view_params, [$limit, $offset]));
    $funds = $funds_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all users for filter dropdowns (from_user, to_user).
    $users_query = "SELECT id, full_name, role FROM users ORDER BY full_name ASC";
    $users_stmt = $db->query($users_query);
    $users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Log viewing the page.
    $auth->logActivity(
        $_SESSION['user_id'],
        'read',
        'funds',
        'Viewed fund management page' . (!empty($view_where_sql) ? ' with filters' : ''),
        null
    );

} catch (PDOException $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'funds',
        'Database error fetching funds: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = "A database error occurred while fetching funds. Please try again later.";
    $funds = [];
    $total_pages = 0;
} catch (Exception $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'funds',
        'Error fetching funds: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = $e->getMessage();
    $funds = [];
    $total_pages = 0;
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt; <span>Fund Management</span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>Fund Management</h2>
    </div>
    <div class="page-actions">
        <?php if ($_SESSION['role'] === 'owner'): ?>
            <button type="button" class="button primary" id="transferFundsBtn">
                <i class="fas fa-exchange-alt"></i> Transfer Funds
            </button>
        <?php endif; ?>
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
            <h3>Filter Funds</h3>
        </div>
        <div class="card-content">
            <form method="GET" action="funds.php">
                <div class="form-row">
                    <div class="form-group col-md-3">
                        <label for="type">Fund Type:</label>
                        <select id="type" name="type" class="form-control">
                            <option value="">All Types</option>
                            <option value="investment" <?php echo $filter_fund_type == 'investment' ? 'selected' : ''; ?>>Investment</option>
                            <option value="return" <?php echo $filter_fund_type == 'return' ? 'selected' : ''; ?>>Return</option>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <label for="status">Status:</label>
                        <select id="status" name="status" class="form-control">
                            <option value="">All Statuses</option>
                            <option value="active" <?php echo $filter_status == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="depleted" <?php echo $filter_status == 'depleted' ? 'selected' : ''; ?>>Depleted</option>
                            <option value="returned" <?php echo $filter_status == 'returned' ? 'selected' : ''; ?>>Returned</option>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <label for="from_user">From User:</label>
                        <select id="from_user" name="from_user" class="form-control">
                            <option value="">All</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo htmlspecialchars($user['id']); ?>" <?php echo $filter_from_user == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['full_name'] . ' (' . $user['role'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <label for="to_user">To User:</label>
                        <select id="to_user" name="to_user" class="form-control">
                            <option value="">All</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo htmlspecialchars($user['id']); ?>" <?php echo $filter_to_user == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['full_name'] . ' (' . $user['role'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-3">
                        <label for="start_date">Start Date:</label>
                        <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($filter_start_date); ?>">
                    </div>
                    <div class="form-group col-md-3">
                        <label for="end_date">End Date:</label>
                        <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($filter_end_date); ?>">
                    </div>
                    <div class="form-group col-md-6 d-flex align-items-end">
                        <button type="submit" class="button primary">Apply Filters</button>
                        <a href="funds.php" class="button secondary ml-2">Clear Filters</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h3>Fund Records (Total: <?php echo $total_records; ?>)</h3>
        </div>
        <div class="card-content">
            <div class="table-responsive">
                <table class="table table-hover sortable-table">
                    <thead>
                        <tr>
                            <th class="sortable">ID</th>
                            <th class="sortable">Type</th>
                            <th class="sortable">Original Amount</th>
                            <th class="sortable">Current Balance</th>
                            <th class="sortable">Used Amount</th>
                            <th class="sortable">Status</th>
                            <th class="sortable">From User</th>
                            <th class="sortable">To User</th>
                            <th class="sortable">Transfer Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($funds) > 0): ?>
                            <?php foreach ($funds as $fund): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($fund['fund_id']); ?></td>
                                    <td><span class="status-badge status-<?php echo htmlspecialchars($fund['type']); ?>"><?php echo ucfirst(htmlspecialchars($fund['type'])); ?></span></td>
                                    <td>Rs. <?php echo number_format($fund['original_amount'], 2); ?></td>
                                    <td>Rs. <?php echo number_format($fund['current_balance'], 2); ?></td>
                                    <td>Rs. <?php echo number_format($fund['used_amount'], 2); ?></td>
                                    <td><span class="status-badge status-<?php echo htmlspecialchars($fund['status']); ?>"><?php echo ucfirst(htmlspecialchars($fund['status'])); ?></span></td>
                                    <td><?php echo htmlspecialchars($fund['from_user']); ?></td>
                                    <td><?php echo htmlspecialchars($fund['to_user']); ?></td>
                                    <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($fund['transfer_date']))); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($fund['status'] === 'active' && $fund['current_balance'] > 0 && $_SESSION['role'] === 'incharge'): ?>
                                                <button type="button" class="button button-sm button-warning use-fund-btn"
                                                        data-id="<?php echo htmlspecialchars($fund['fund_id']); ?>"
                                                        data-balance="<?php echo htmlspecialchars($fund['current_balance']); ?>"
                                                        data-to-user="<?php echo htmlspecialchars($fund['to_user']); ?>"
                                                        title="Record Fund Usage">
                                                    <i class="fas fa-hand-holding-usd"></i> Use Fund
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($fund['type'] === 'investment' && $_SESSION['role'] === 'owner'): ?>
                                                <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center">No fund records found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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

<div id="transferFundsModal" class="modal">
    <div class="modal-content">
        <span class="close-button">&times;</span>
        <h3>Transfer Funds</h3>
        <form id="transferFundsForm" method="post" action="../api/transfer-funds.php">
            <div class="form-group">
                <label for="transfer_from_user">From User:</label>
                <select id="transfer_from_user" name="from_user_id" required>
                    <option value="">Select User</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo htmlspecialchars($user['id']); ?>">
                            <?php echo htmlspecialchars($user['full_name']); ?> (<?php echo htmlspecialchars(ucfirst($user['role'])); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="transfer_to_user">To User:</label>
                <select id="transfer_to_user" name="to_user_id" required>
                    <option value="">Select User</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo htmlspecialchars($user['id']); ?>">
                            <?php echo htmlspecialchars($user['full_name']); ?> (<?php echo htmlspecialchars(ucfirst($user['role'])); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="transfer_amount">Amount:</label>
                <input type="number" id="transfer_amount" name="amount" step="0.01" min="0.01" required>
            </div>
            <div class="form-group">
                <label for="transfer_description">Description (Optional):</label>
                <textarea id="transfer_description" name="description" rows="3"></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="button secondary close-button">Cancel</button>
                <button type="submit" class="button primary">Transfer</button>
            </div>
        </form>
    </div>
</div>

<div id="useFundModal" class="modal">
    <div class="modal-content">
        <span class="close-button">&times;</span>
        <h3>Record Fund Usage</h3>
        <form id="useFundForm" method="post" action="../api/record-fund-usage.php">
            <input type="hidden" id="use_fund_id" name="fund_id">
            <div class="form-group">
                <label>Fund ID: <span id="display_use_fund_id"></span></label>
            </div>
            <div class="form-group">
                <label>Available Balance: Rs. <span id="display_available_balance"></span></label>
                <input type="hidden" id="max_use_amount" value="">
            </div>
            <div class="form-group">
                <label for="use_amount">Amount to Use:</label>
                <input type="number" id="use_amount" name="amount" step="0.01" min="0.01" required>
            </div>
            <div class="form-group">
                <label for="use_type">Usage Type:</label>
                <select id="use_type" name="type" required>
                    <option value="">Select Type</option>
                    <option value="purchase">Purchase</option>
                    <option value="manufacturing_cost">Manufacturing Cost</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="form-group">
                <label for="use_reference_id">Reference ID (Purchase ID, Cost ID, etc.):</label>
                <input type="number" id="use_reference_id" name="reference_id" min="1" required>
                <small class="form-text text-muted">Enter the ID of the related transaction (e.g., Purchase ID for a purchase, Manufacturing Cost ID for a cost).</small>
            </div>
            <div class="form-group">
                <label for="use_notes">Notes (Optional):</label>
                <textarea id="use_notes" name="notes" rows="3"></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="button secondary close-button">Cancel</button>
                <button type="submit" class="button primary">Record Usage</button>
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

    // --- Transfer Funds Modal Logic (for Owner) ---
    const transferFundsModal = document.getElementById('transferFundsModal');
    const transferFundsBtn = document.getElementById('transferFundsBtn');
    const transferFundsCloseButtons = transferFundsModal.querySelectorAll('.close-button');
    const transferFundsForm = document.getElementById('transferFundsForm');
    const transferFromUser = document.getElementById('transfer_from_user');
    const transferToUser = document.getElementById('transfer_to_user');
    const transferAmount = document.getElementById('transfer_amount');

    if (transferFundsBtn) {
        transferFundsBtn.addEventListener('click', function() {
            transferFundsForm.reset();
            transferFundsModal.style.display = 'block';
            transferFundsModal.classList.add('show-modal');
            // Clear validation messages
            document.querySelectorAll('#transferFundsForm .invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('#transferFundsForm .validation-error').forEach(el => el.remove());
        });
    }

    transferFundsCloseButtons.forEach(btn => btn.addEventListener('click', () => {
        transferFundsModal.classList.remove('show-modal');
        setTimeout(() => { transferFundsModal.style.display = 'none'; }, 300);
    }));
    window.addEventListener('click', function(event) {
        if (event.target == transferFundsModal) {
            transferFundsModal.classList.remove('show-modal');
            setTimeout(() => { transferFundsModal.style.display = 'none'; }, 300);
        }
    });

    if (transferFundsForm) {
        transferFundsForm.addEventListener('submit', function(event) {
            event.preventDefault(); // Prevent default form submission

            let isValid = true;
            // Clear previous validation errors
            document.querySelectorAll('#transferFundsForm .invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('#transferFundsForm .validation-error').forEach(el => el.remove());

            // Client-side validation
            if (!transferFromUser.value) { showValidationError(transferFromUser, 'Please select a source user.'); isValid = false; }
            if (!transferToUser.value) { showValidationError(transferToUser, 'Please select a destination user.'); isValid = false; }
            if (transferFromUser.value === transferToUser.value && transferFromUser.value !== "") {
                showValidationError(transferToUser, 'Source and destination users cannot be the same.');
                isValid = false;
            }
            if (!transferAmount.value || parseFloat(transferAmount.value) <= 0) {
                showValidationError(transferAmount, 'Please enter a valid amount greater than zero.'); isValid = false;
            }

            if (!isValid) return;

            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner"></span> Transferring...';

            const formData = new FormData(this);
            fetch(this.action, { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    submitButton.disabled = false;
                    submitButton.innerHTML = 'Transfer';
                    if (typeof showToast === 'function') {
                        showToast(data.success ? 'success' : 'error', data.message);
                    } else {
                        alert(data.message);
                    }
                    if (data.success) {
                        transferFundsModal.classList.remove('show-modal');
                        setTimeout(() => {
                            transferFundsModal.style.display = 'none';
                            window.location.reload();
                        }, 300);
                    }
                })
                .catch(error => {
                    submitButton.disabled = false;
                    submitButton.innerHTML = 'Transfer';
                    console.error('Error:', error);
                    if (typeof showToast === 'function') {
                        showToast('error', 'An unexpected error occurred. Please try again.');
                    } else {
                        alert('An unexpected error occurred.');
                    }
                });
        });
    }

    // --- Use Fund Modal Logic (for Incharge) ---
    const useFundModal = document.getElementById('useFundModal');
    const useFundCloseButtons = useFundModal.querySelectorAll('.close-button');
    const useFundForm = document.getElementById('useFundForm');
    const displayUseFundId = document.getElementById('display_use_fund_id');
    const displayAvailableBalance = document.getElementById('display_available_balance');
    const useFundIdInput = document.getElementById('use_fund_id');
    const maxUseAmountInput = document.getElementById('max_use_amount');
    const useAmountInput = document.getElementById('use_amount');

    document.querySelectorAll('.use-fund-btn').forEach(button => {
        button.addEventListener('click', function() {
            const fundId = this.dataset.id;
            const balance = parseFloat(this.dataset.balance);

            displayUseFundId.textContent = fundId;
            displayAvailableBalance.textContent = balance.toFixed(2);
            useFundIdInput.value = fundId;
            maxUseAmountInput.value = balance.toFixed(2);
            useAmountInput.setAttribute('max', balance.toFixed(2)); // Set max amount
            useFundForm.reset(); // Clear previous form data
            useAmountInput.value = ''; // Ensure amount is empty

            useFundModal.style.display = 'block';
            useFundModal.classList.add('show-modal');
            // Clear validation messages
            document.querySelectorAll('#useFundForm .invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('#useFundForm .validation-error').forEach(el => el.remove());
        });
    });

    useFundCloseButtons.forEach(btn => btn.addEventListener('click', () => {
        useFundModal.classList.remove('show-modal');
        setTimeout(() => { useFundModal.style.display = 'none'; }, 300);
    }));
    window.addEventListener('click', function(event) {
        if (event.target == useFundModal) {
            useFundModal.classList.remove('show-modal');
            setTimeout(() => { useFundModal.style.display = 'none'; }, 300);
        }
    });

    if (useFundForm) {
        useFundForm.addEventListener('submit', function(event) {
            event.preventDefault();

            let isValid = true;
            // Clear previous validation errors
            document.querySelectorAll('#useFundForm .invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('#useFundForm .validation-error').forEach(el => el.remove());

            // Client-side validation
            const amountToUse = parseFloat(useAmountInput.value);
            const availableBalance = parseFloat(maxUseAmountInput.value);

            if (!useAmountInput.value || amountToUse <= 0) {
                showValidationError(useAmountInput, 'Amount must be greater than zero.'); isValid = false;
            } else if (amountToUse > availableBalance + 0.01) { // Allow slight float tolerance
                showValidationError(useAmountInput, `Amount exceeds available balance (Rs. ${availableBalance.toFixed(2)}).`); isValid = false;
            }
            if (!document.getElementById('use_type').value) {
                showValidationError(document.getElementById('use_type'), 'Usage type is required.'); isValid = false;
            }
            if (!document.getElementById('use_reference_id').value || parseInt(document.getElementById('use_reference_id').value) <= 0) {
                showValidationError(document.getElementById('use_reference_id'), 'Reference ID must be a positive number.'); isValid = false;
            }

            if (!isValid) return;

            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner"></span> Recording...';

            const formData = new FormData(this);
            fetch(this.action, { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    submitButton.disabled = false;
                    submitButton.innerHTML = 'Record Usage';
                    if (typeof showToast === 'function') {
                        showToast(data.success ? 'success' : 'error', data.message);
                    } else {
                        alert(data.message);
                    }
                    if (data.success) {
                        useFundModal.classList.remove('show-modal');
                        setTimeout(() => {
                            useFundModal.style.display = 'none';
                            window.location.reload();
                        }, 300);
                    }
                })
                .catch(error => {
                    submitButton.disabled = false;
                    submitButton.innerHTML = 'Record Usage';
                    console.error('Error:', error);
                    if (typeof showToast === 'function') {
                        showToast('error', 'An unexpected error occurred. Please try again.');
                    } else {
                        alert('An unexpected error occurred.');
                    }
                });
        });
    }

    // Helper functions for validation (can be moved to utils.js later)
    function showValidationError(element, message) {
        // Remove any existing error message
        const existingError = element.parentElement.querySelector('.validation-error');
        if (existingError) {
            existingError.remove();
        }

        // Add error class to element
        element.classList.add('invalid-input');

        // Create and append error message
        const errorElement = document.createElement('div');
        errorElement.className = 'validation-error';
        errorElement.textContent = message;

        element.parentElement.appendChild(errorElement);
        element.focus();
    }

    // Remove validation error when input changes
    document.querySelectorAll('.modal input, .modal select, .modal textarea').forEach(input => {
        input.addEventListener('input', function() {
            this.classList.remove('invalid-input');
            const errorElement = this.parentElement.querySelector('.validation-error');
            if (errorElement) {
                errorElement.remove();
            }
        });
    });
});
</script>

<?php include_once '../includes/footer.php'; ?>