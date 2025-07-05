<?php
// File: raw-materials.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Include header.
$page_title = "Raw Materials";
include_once '../includes/header.php';

// Check user authentication and role (e.g., 'owner' or 'incharge' can manage raw materials).
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'incharge')) {
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to view raw materials.";
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
$search_name = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_unit = isset($_GET['unit']) ? trim($_GET['unit']) : '';
$filter_stock_status = isset($_GET['stock_status']) ? trim($_GET['stock_status']) : ''; // 'low', 'in_stock'

$where_clauses = [];
$params = [];

if (!empty($search_name)) {
    $where_clauses[] = "rm.name LIKE ?";
    $params[] = '%' . $search_name . '%';
}
if (!empty($filter_unit)) {
    $where_clauses[] = "rm.unit = ?";
    $params[] = $filter_unit;
}
if (!empty($filter_stock_status)) {
    if ($filter_stock_status === 'low') {
        $where_clauses[] = "rm.stock_quantity < rm.min_stock_level";
    } elseif ($filter_stock_status === 'in_stock') {
        $where_clauses[] = "rm.stock_quantity >= rm.min_stock_level";
    }
}

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

try {
    // Total records for pagination.
    $count_query = "SELECT COUNT(*) FROM raw_materials rm " . $where_sql;
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    // Fetch raw materials.
    $query = "SELECT rm.* FROM raw_materials rm " . $where_sql . " ORDER BY rm.name ASC LIMIT ? OFFSET ?";
    $stmt = $db->prepare($query);
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch distinct units for filter dropdown.
    $distinct_units_query = "SELECT DISTINCT unit FROM raw_materials ORDER BY unit ASC";
    $distinct_units_stmt = $db->query($distinct_units_query);
    $distinct_units = $distinct_units_stmt->fetchAll(PDO::FETCH_COLUMN);

    // Log viewing the page.
    $auth->logActivity(
        $_SESSION['user_id'],
        'read',
        'raw_materials',
        'Viewed raw materials list page' . (!empty($where_sql) ? ' with filters' : ''),
        null
    );

} catch (PDOException $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'raw_materials',
        'Database error fetching raw materials: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = "A database error occurred while fetching raw materials. Please try again later.";
    $materials = [];
    $total_pages = 0;
} catch (Exception $e) {
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'raw_materials',
        'Error fetching raw materials: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = $e->getMessage();
    $materials = [];
    $total_pages = 0;
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt; <span>Raw Materials</span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>Raw Material Management</h2>
    </div>
    <div class="page-actions">
        <button type="button" class="button primary" id="addMaterialBtn">
            <i class="fas fa-plus"></i> Add New Material
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
            <h3>Filter Raw Materials</h3>
        </div>
        <div class="card-content">
            <form method="GET" action="raw-materials.php">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label for="search">Search Name:</label>
                        <input type="text" id="search" name="search" class="form-control" value="<?php echo htmlspecialchars($search_name); ?>" placeholder="Material Name">
                    </div>
                    <div class="form-group col-md-3">
                        <label for="unit">Unit:</label>
                        <select id="unit" name="unit" class="form-control">
                            <option value="">All Units</option>
                            <?php foreach ($distinct_units as $unit): ?>
                                <option value="<?php echo htmlspecialchars($unit); ?>" <?php echo $filter_unit == $unit ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst($unit)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <label for="stock_status">Stock Status:</label>
                        <select id="stock_status" name="stock_status" class="form-control">
                            <option value="">All</option>
                            <option value="in_stock" <?php echo $filter_stock_status == 'in_stock' ? 'selected' : ''; ?>>In Stock</option>
                            <option value="low" <?php echo $filter_stock_status == 'low' ? 'selected' : ''; ?>>Low Stock</option>
                        </select>
                    </div>
                    <div class="form-group col-md-2 d-flex align-items-end">
                        <button type="submit" class="button primary">Apply Filters</button>
                        <a href="raw-materials.php" class="button secondary ml-2">Clear Filters</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h3>Raw Material Records (Total: <?php echo $total_records; ?>)</h3>
        </div>
        <div class="card-content">
            <div class="table-responsive">
                <?php if (count($materials) > 0): ?>
                <table class="table table-hover sortable-table">
                    <thead>
                        <tr>
                            <th class="sortable">ID</th>
                            <th class="sortable">Name</th>
                            <th class="sortable">Description</th>
                            <th class="sortable">Unit</th>
                            <th class="sortable">Stock Quantity</th>
                            <th class="sortable">Min Stock Level</th>
                            <th class="sortable">Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($materials as $material): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($material['id']); ?></td>
                                <td><?php echo htmlspecialchars($material['name']); ?></td>
                                <td><?php echo htmlspecialchars($material['description'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($material['unit'])); ?></td>
                                <td><?php echo number_format($material['stock_quantity'], 2); ?></td>
                                <td><?php echo number_format($material['min_stock_level'], 2); ?></td>
                                <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($material['created_at']))); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="button button-sm button-primary edit-material-btn"
                                                data-id="<?php echo htmlspecialchars($material['id']); ?>"
                                                data-name="<?php echo htmlspecialchars($material['name']); ?>"
                                                data-description="<?php echo htmlspecialchars($material['description']); ?>"
                                                data-unit="<?php echo htmlspecialchars($material['unit']); ?>"
                                                data-stock-quantity="<?php echo htmlspecialchars($material['stock_quantity']); ?>"
                                                data-min-stock-level="<?php echo htmlspecialchars($material['min_stock_level']); ?>"
                                                title="Edit Material">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button type="button" class="button button-sm button-danger delete-material-btn"
                                                data-id="<?php echo htmlspecialchars($material['id']); ?>"
                                                data-name="<?php echo htmlspecialchars($material['name']); ?>"
                                                title="Delete Material">
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
                        <p>No raw materials found. Click "Add New Material" to get started!</p>
                        <button type="button" class="button primary mt-3" id="emptyStateAddMaterialBtn">
                            <i class="fas fa-plus"></i> Add New Material
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

<div id="materialModal" class="modal">
    <div class="modal-content">
        <span class="close-button">&times;</span>
        <h3 id="materialModalTitle">Add New Raw Material</h3>
        <form id="materialForm" method="post" action="../api/save-material.php">
            <input type="hidden" id="material_id" name="material_id">
            <div class="form-group">
                <label for="material_name">Material Name:</label>
                <input type="text" id="material_name" name="name" required>
            </div>
            <div class="form-group">
                <label for="material_description">Description (Optional):</label>
                <textarea id="material_description" name="description" rows="3"></textarea>
            </div>
            <div class="form-group">
                <label for="material_unit">Unit:</label>
                <select id="material_unit" name="unit" required>
                    <option value="">Select Unit</option>
                    <option value="meter">Meter</option>
                    <option value="kg">Kilogram (Kg)</option>
                    <option value="piece">Piece</option>
                </select>
            </div>
            <div class="form-group">
                <label for="material_stock_quantity">Stock Quantity:</label>
                <input type="number" id="material_stock_quantity" name="stock_quantity" step="0.01" min="0" required>
            </div>
            <div class="form-group">
                <label for="material_min_stock_level">Minimum Stock Level:</label>
                <input type="number" id="material_min_stock_level" name="min_stock_level" step="0.01" min="0" value="0.00" required>
            </div>
            <div class="form-actions">
                <button type="button" class="button secondary close-button">Cancel</button>
                <button type="submit" class="button primary">Save Material</button>
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

    const materialModal = document.getElementById('materialModal');
    const closeButtons = materialModal.querySelectorAll('.close-button');
    const addMaterialBtn = document.getElementById('addMaterialBtn');
    const emptyStateAddMaterialBtn = document.getElementById('emptyStateAddMaterialBtn');
    const editMaterialBtns = document.querySelectorAll('.edit-material-btn');
    const deleteMaterialBtns = document.querySelectorAll('.delete-material-btn'); // Select new delete buttons
    const materialForm = document.getElementById('materialForm');
    const materialModalTitle = document.getElementById('materialModalTitle');

    // Function to open modal
    function openMaterialModal() {
        materialModal.style.display = 'block';
        materialModal.classList.add('show-modal'); // For animation
    }

    // Function to close modal
    function closeMaterialModal() {
        materialModal.classList.remove('show-modal'); // For animation
        setTimeout(() => {
            materialModal.style.display = 'none';
            // Clear form and validation errors on close
            materialForm.reset();
            document.querySelectorAll('.invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('.validation-error').forEach(el => el.remove());
        }, 300); // Match animation duration
    }

    // Attach click listeners to close buttons
    closeButtons.forEach(btn => {
        btn.addEventListener('click', closeMaterialModal);
    });

    // Close modal if clicking outside
    window.addEventListener('click', function(event) {
        if (event.target == materialModal) {
            closeMaterialModal();
        }
    });

    // Open add material modal
    if (addMaterialBtn) {
        addMaterialBtn.addEventListener('click', function() {
            materialModalTitle.textContent = 'Add New Raw Material';
            materialForm.querySelector('#material_id').value = ''; // Clear ID for new material
            materialForm.reset(); // Clear form fields
            openMaterialModal();
        });
    }

    // Open add material modal from empty state button
    if (emptyStateAddMaterialBtn) {
        emptyStateAddMaterialBtn.addEventListener('click', function() {
            materialModalTitle.textContent = 'Add New Raw Material';
            materialForm.querySelector('#material_id').value = '';
            materialForm.reset();
            openMaterialModal();
        });
    }

    // Open edit material modal
    editMaterialBtns.forEach(button => {
        button.addEventListener('click', function() {
            materialModalTitle.textContent = 'Edit Raw Material';
            materialForm.querySelector('#material_id').value = this.dataset.id;
            materialForm.querySelector('#material_name').value = this.dataset.name;
            materialForm.querySelector('#material_description').value = this.dataset.description;
            materialForm.querySelector('#material_unit').value = this.dataset.unit;
            materialForm.querySelector('#material_stock_quantity').value = this.dataset.stockQuantity;
            materialForm.querySelector('#material_min_stock_level').value = this.dataset.minStockLevel;
            openMaterialModal();
        });
    });

    // Handle material form submission via AJAX (existing, but ensure showToast is used)
    if (materialForm) {
        materialForm.addEventListener('submit', function(event) {
            event.preventDefault(); // Prevent default form submission

            let isValid = true;
            // Clear previous validation errors
            document.querySelectorAll('.invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('.validation-error').forEach(el => el.remove());

            // Client-side validation
            const nameInput = document.getElementById('material_name');
            const unitSelect = document.getElementById('material_unit');
            const stockQuantityInput = document.getElementById('material_stock_quantity');
            const minStockLevelInput = document.getElementById('material_min_stock_level');

            if (!nameInput.value.trim()) {
                showValidationError(nameInput, 'Material name is required.', nameInput);
                isValid = false;
            }
            if (!unitSelect.value) {
                showValidationError(unitSelect, 'Unit is required.', unitSelect);
                isValid = false;
            }
            if (!stockQuantityInput.value || parseFloat(stockQuantityInput.value) < 0) {
                showValidationError(stockQuantityInput, 'Stock quantity must be a non-negative number.', stockQuantityInput);
                isValid = false;
            }
            if (!minStockLevelInput.value || parseFloat(minStockLevelInput.value) < 0) {
                showValidationError(minStockLevelInput, 'Minimum stock level must be a non-negative number.', minStockLevelInput);
                isValid = false;
            }

            if (!isValid) {
                return; // Stop if validation fails
            }

            // Show loading state
            const submitButton = materialForm.querySelector('button[type="submit"]');
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
                submitButton.innerHTML = 'Save Material'; // Reset button text

                if (typeof showToast === 'function') {
                    showToast(data.success ? 'success' : 'error', data.message);
                } else {
                    alert(data.message);
                }
                if (data.success) {
                    closeMaterialModal();
                    window.location.reload(); // Reload page to show updated list
                }
            })
            .catch(error => {
                submitButton.disabled = false;
                submitButton.innerHTML = 'Save Material';
                console.error('Error saving material:', error);
                if (typeof showToast === 'function') {
                    showToast('error', 'An unexpected error occurred while saving the material.');
                } else {
                    alert('An unexpected error occurred.');
                }
            });
        });
    }

    // --- Delete Material Logic ---
    deleteMaterialBtns.forEach(button => {
        button.addEventListener('click', function() {
            const materialId = this.dataset.id;
            const materialName = this.dataset.name;

            if (confirm(`Are you sure you want to delete raw material "${materialName}" (ID: ${materialId})? This action is irreversible and will fail if there are associated purchases or material usage records, or if stock is not zero.`)) {
                // Show loading state
                button.disabled = true;
                button.innerHTML = '<span class="spinner"></span>'; // Or specific icon

                fetch('../api/delete-material.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `material_id=${materialId}`
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
                    console.error('Error deleting material:', error);
                    if (typeof showToast === 'function') {
                        showToast('error', 'An unexpected error occurred while deleting the material.');
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
            element.parentElement.appendChild(errorElement);
        }
        element.focus();
    }

    // Remove validation error when input changes
    const formInputs = materialForm.querySelectorAll('input, select, textarea');
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