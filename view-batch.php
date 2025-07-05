<?php
// File: view-batch.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Include header.
$page_title = "View Manufacturing Batch";
include_once '../includes/header.php';

// Check user authentication and role (e.g., 'owner' or 'incharge' can view batches).
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'incharge')) {
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to view manufacturing batches.";
    header('Location: manufacturing.php'); // Redirect to manufacturing list
    exit;
}

// Get database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

$batch_id = isset($_GET['id']) ? intval($_GET['id']) : null;
$batch = null;
$materials_used = [];
$manufacturing_costs = [];
$quality_checks = [];
$total_material_cost_est = 0;
$total_mfg_cost = 0;
$total_batch_cost_est = 0;
$cost_per_unit_est = 0;

try {
    if (!$batch_id) {
        throw new Exception("Missing or invalid Batch ID.");
    }

    // Fetch batch details.
    $batch_query = "SELECT mb.*, p.name as product_name, p.sku as product_sku,
                           u_created.full_name as created_by_name,
                           u_status_changed.full_name as status_changed_by_name
                    FROM manufacturing_batches mb
                    JOIN products p ON mb.product_id = p.id
                    JOIN users u_created ON mb.created_by = u_created.id
                    LEFT JOIN users u_status_changed ON mb.status_changed_by = u_status_changed.id
                    WHERE mb.id = ?";
    $batch_stmt = $db->prepare($batch_query);
    $batch_stmt->execute([$batch_id]);
    $batch = $batch_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$batch) {
        throw new Exception("Manufacturing batch not found.");
    }

    // Fetch materials used for this batch.
    $materials_used_query = "SELECT mu.*, rm.name as material_name, rm.unit as material_unit
                             FROM material_usage mu
                             JOIN raw_materials rm ON mu.material_id = rm.id
                             WHERE mu.batch_id = ? ORDER BY mu.recorded_date ASC";
    $materials_used_stmt = $db->prepare($materials_used_query);
    $materials_used_stmt->execute([$batch_id]);
    $materials_used = $materials_used_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate estimated total material cost (using avg from last 5 purchases).
    // This part is duplicated from get-batch-costing.php for display.
    $material_cost_query = "
        SELECT
            SUM(mu.quantity_used * (
                SELECT AVG(p.unit_price)
                FROM purchases p
                WHERE p.material_id = mu.material_id
                ORDER BY p.purchase_date DESC
                LIMIT 5
            )) AS total_material_cost
        FROM material_usage mu
        WHERE mu.batch_id = ?
    ";
    $material_cost_stmt = $db->prepare($material_cost_query);
    $material_cost_stmt->execute([$batch_id]);
    $total_material_cost_est = $material_cost_stmt->fetchColumn() ?: 0;


    // Fetch manufacturing costs for this batch.
    $manufacturing_costs_query = "SELECT * FROM manufacturing_costs WHERE batch_id = ? ORDER BY recorded_date ASC";
    $manufacturing_costs_stmt = $db->prepare($manufacturing_costs_query);
    $manufacturing_costs_stmt->execute([$batch_id]);
    $manufacturing_costs = $manufacturing_costs_stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_mfg_cost = array_sum(array_column($manufacturing_costs, 'amount'));

    // Calculate total batch cost and cost per unit.
    $total_batch_cost_est = $total_material_cost_est + $total_mfg_cost;
    if ($batch['quantity_produced'] > 0) {
        $cost_per_unit_est = $total_batch_cost_est / $batch['quantity_produced'];
    }

    // Fetch quality checks for this batch.
    $quality_checks_query = "SELECT qc.*, u.full_name as inspector_name
                             FROM quality_control qc
                             LEFT JOIN users u ON qc.inspector_id = u.id
                             WHERE qc.batch_id = ? ORDER BY qc.inspection_date DESC";
    $quality_checks_stmt = $db->prepare($quality_checks_query);
    $quality_checks_stmt->execute([$batch_id]);
    $quality_checks = $quality_checks_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get list of users for dropdowns (e.g., status changed by, inspector).
    $users_query = "SELECT id, full_name, role FROM users ORDER BY full_name ASC";
    $users_stmt = $db->query($users_query);
    $all_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Log viewing the page.
    $auth->logActivity(
        $_SESSION['user_id'],
        'read',
        'manufacturing_batches',
        'Viewed details for batch: ' . htmlspecialchars($batch['batch_number']),
        $batch_id
    );

} catch (Exception $e) {
    // Log the error.
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'manufacturing_batches',
        'Error loading batch details: ' . $e->getMessage(),
        $batch_id ?? null
    );
    $_SESSION['error_message'] = $e->getMessage();
    header('Location: manufacturing.php'); // Redirect if batch not found or invalid ID
    exit;
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt;
    <a href="manufacturing.php">Manufacturing Batches</a> &gt;
    <span>Batch <?php echo htmlspecialchars($batch['batch_number']); ?></span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>Batch Details: <?php echo htmlspecialchars($batch['batch_number']); ?></h2>
        <span class="status-badge status-<?php echo htmlspecialchars($batch['status']); ?>"><?php echo ucfirst(htmlspecialchars($batch['status'])); ?></span>
    </div>
    <div class="page-actions">
        <a href="manufacturing.php" class="button secondary">
            <i class="fas fa-arrow-left"></i> Back to Batches
        </a>
        <?php if ($_SESSION['role'] === 'incharge' || $_SESSION['role'] === 'owner'): ?>
            <a href="add-cost.php?batch_id=<?php echo htmlspecialchars($batch['id']); ?>" class="button button-warning">
                <i class="fas fa-dollar-sign"></i> Add Cost
            </a>
            <button type="button" class="button primary" id="updateStatusBtn"
                    data-batch-id="<?php echo htmlspecialchars($batch['id']); ?>"
                    data-current-status="<?php echo htmlspecialchars($batch['status']); ?>"
                    title="Update Batch Status">
                <i class="fas fa-sync-alt"></i> Update Status
            </button>
            <?php if ($batch['status'] === 'completed' && ($_SESSION['role'] === 'incharge' || $_SESSION['role'] === 'owner')): ?>
                <button type="button" class="button button-info" id="adjustQuantityBtn"
                        data-batch-id="<?php echo htmlspecialchars($batch['id']); ?>"
                        data-product-id="<?php echo htmlspecialchars($batch['product_id']); ?>"
                        data-current-quantity="<?php echo htmlspecialchars($batch['quantity_produced']); ?>"
                        title="Adjust Final Product Quantity">
                    <i class="fas fa-pencil-alt"></i> Adjust Final Quantity
                </button>
            <?php endif; ?>
            <?php if ($_SESSION['role'] === 'owner'): ?>
                <button type="button" class="button button-danger" id="deleteBatchBtn"
                        data-batch-id="<?php echo htmlspecialchars($batch['id']); ?>"
                        data-batch-number="<?php echo htmlspecialchars($batch['batch_number']); ?>"
                        title="Delete Batch and Rollback Materials">
                    <i class="fas fa-trash"></i> Delete Batch
                </button>
            <?php endif; ?>
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
    <div class="row">
        <div class="col-lg-6 col-md-12">
            <div class="card batch-info-card">
                <div class="card-header">
                    <h3>General Information</h3>
                </div>
                <div class="card-content">
                    <p><strong>Product:</strong> <?php echo htmlspecialchars($batch['product_name']); ?> (SKU: <?php echo htmlspecialchars($batch['product_sku']); ?>)</p>
                    <p><strong>Quantity Produced:</strong> <?php echo number_format($batch['quantity_produced']); ?> units</p>
                    <p><strong>Current Status:</strong> <span class="status-badge status-<?php echo htmlspecialchars($batch['status']); ?>"><?php echo ucfirst(htmlspecialchars($batch['status'])); ?></span></p>
                    <p><strong>Started On:</strong> <?php echo htmlspecialchars($batch['start_date']); ?></p>
                    <p><strong>Expected Completion:</strong> <?php echo htmlspecialchars($batch['expected_completion_date']); ?></p>
                    <p><strong>Actual Completion:</strong> <?php echo htmlspecialchars($batch['completion_date'] ?: 'N/A'); ?></p>
                    <p><strong>Created By:</strong> <?php echo htmlspecialchars($batch['created_by_name']); ?></p>
                    <p><strong>Created At:</strong> <?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($batch['created_at']))); ?></p>
                    <p><strong>Last Updated:</strong> <?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($batch['updated_at']))); ?></p>
                    <p><strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($batch['notes'] ?: 'N/A')); ?></p>
                    <?php if (!empty($batch['status_change_notes'])): ?>
                        <p><strong>Status Change History:</strong> <br> <?php echo nl2br(htmlspecialchars($batch['status_change_notes'])); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6 col-md-12">
            <div class="card batch-costing-card">
                <div class="card-header">
                    <h3>Estimated Costing Overview</h3>
                </div>
                <div class="card-content">
                    <p><strong>Total Material Cost (Est.):</strong> Rs. <?php echo number_format($total_material_cost_est, 2); ?></p>
                    <p><strong>Total Manufacturing Costs:</strong> Rs. <?php echo number_format($total_mfg_cost, 2); ?></p>
                    <hr>
                    <p><strong>Total Batch Cost (Est.):</strong> Rs. <?php echo number_format($total_batch_cost_est, 2); ?></p>
                    <p><strong>Cost Per Unit (Est.):</strong> Rs. <?php echo number_format($cost_per_unit_est, 2); ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-12">
            <div class="card materials-used-card">
                <div class="card-header">
                    <h3>Materials Used</h3>
                </div>
                <div class="card-content">
                    <?php if (!empty($materials_used)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Material Name</th>
                                        <th>Quantity Used</th>
                                        <th>Recorded By</th>
                                        <th>Recorded Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($materials_used as $material_item): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($material_item['material_name']); ?> (<?php echo htmlspecialchars($material_item['material_unit']); ?>)</td>
                                            <td><?php echo number_format($material_item['quantity_used'], 2); ?></td>
                                            <td>
                                                <?php
                                                    $recorded_by_user = array_filter($all_users, fn($u) => $u['id'] == $material_item['recorded_by']);
                                                    echo htmlspecialchars(reset($recorded_by_user)['full_name'] ?? 'N/A');
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($material_item['recorded_date']))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center text-muted">No raw materials recorded for this batch yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-12">
            <div class="card manufacturing-costs-card">
                <div class="card-header">
                    <h3>Manufacturing Costs Details</h3>
                </div>
                <div class="card-content">
                    <?php if (!empty($manufacturing_costs)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Cost Type</th>
                                        <th>Amount</th>
                                        <th>Description</th>
                                        <th>Recorded By</th>
                                        <th>Recorded Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($manufacturing_costs as $cost): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($cost['cost_type']); ?></td>
                                            <td>Rs. <?php echo number_format($cost['amount'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($cost['description'] ?: 'N/A'); ?></td>
                                            <td>
                                                <?php
                                                    $recorded_by_user = array_filter($all_users, fn($u) => $u['id'] == $cost['recorded_by']);
                                                    echo htmlspecialchars(reset($recorded_by_user)['full_name'] ?? 'N/A');
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($cost['recorded_date']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center text-muted">No specific manufacturing costs recorded for this batch yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-12">
            <div class="card quality-checks-card">
                <div class="card-header">
                    <h3>Quality Control Checks</h3>
                </div>
                <div class="card-content">
                    <?php if (!empty($quality_checks)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Inspector</th>
                                        <th>Check Date</th>
                                        <th>Status</th>
                                        <th>Defects Found</th>
                                        <th>Defect Description</th>
                                        <th>Remedial Action</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($quality_checks as $qc): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($qc['inspector_name'] ?: 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($qc['check_date'] ?: date('Y-m-d', strtotime($qc['inspection_date']))); ?></td>
                                            <td><span class="status-badge status-<?php echo htmlspecialchars($qc['status']); ?>"><?php echo ucfirst(htmlspecialchars($qc['status'])); ?></span></td>
                                            <td><?php echo htmlspecialchars($qc['defects_found']); ?></td>
                                            <td><?php echo htmlspecialchars($qc['defect_description'] ?: 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($qc['remedial_action'] ?: 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($qc['notes'] ?: 'N/A'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center text-muted">No quality control checks recorded for this batch yet.
                        <?php if ($_SESSION['role'] === 'incharge' || $_SESSION['role'] === 'owner'): ?>
                            <a href="add-quality-check.php?batch_id=<?php echo htmlspecialchars($batch['id']); ?>" class="button button-sm primary ml-2">Add New QC Check</a>
                        <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="updateStatusModal" class="modal">
    <div class="modal-content">
        <span class="close-button">&times;</span>
        <h3>Update Batch Status</h3>
        <form id="updateStatusForm" method="post" action="../api/update-batch-status.php">
            <input type="hidden" id="status_batch_id" name="batch_id" value="<?php echo htmlspecialchars($batch['id']); ?>">
            <div class="form-group">
                <label for="new_status">New Status:</label>
                <select id="new_status" name="new_status" class="form-control" required>
                    <option value="">Select New Status</option>
                    <option value="pending" <?php echo $batch['status'] == 'pending' ? 'selected disabled' : ''; ?>>Pending</option>
                    <option value="cutting" <?php echo $batch['status'] == 'cutting' ? 'selected disabled' : ''; ?>>Cutting</option>
                    <option value="stitching" <?php echo $batch['status'] == 'stitching' ? 'selected disabled' : ''; ?>>Stitching</option>
                    <option value="ironing" <?php echo $batch['status'] == 'ironing' ? 'selected disabled' : ''; ?>>Ironing</option>
                    <option value="packaging" <?php echo $batch['status'] == 'packaging' ? 'selected disabled' : ''; ?>>Packaging</option>
                    <option value="completed" <?php echo $batch['status'] == 'completed' ? 'selected disabled' : ''; ?>>Completed</option>
                </select>
                <small class="form-text text-muted">Current status is "<?php echo ucfirst(htmlspecialchars($batch['status'])); ?>".</small>
            </div>
            <div class="form-group">
                <label for="status_change_notes">Notes for Status Change (Optional):</label>
                <textarea id="status_change_notes" name="status_change_notes" rows="3" placeholder="Enter notes about this status change"></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="button secondary close-button">Cancel</button>
                <button type="submit" class="button primary">Update Status</button>
            </div>
        </form>
    </div>
</div>

<div id="adjustQuantityModal" class="modal">
    <div class="modal-content">
        <span class="close-button">&times;</span>
        <h3>Adjust Final Product Quantity</h3>
        <form id="adjustQuantityForm" method="post" action="../api/adjust-product-quantity.php">
            <input type="hidden" id="adjust_batch_id" name="batch_id" value="<?php echo htmlspecialchars($batch['id']); ?>">
            <input type="hidden" id="adjust_product_id" name="product_id" value="<?php echo htmlspecialchars($batch['product_id']); ?>">
            <div class="form-group">
                <label>Batch Number:</label>
                <p><?php echo htmlspecialchars($batch['batch_number']); ?></p>
            </div>
            <div class="form-group">
                <label>Product:</label>
                <p><?php echo htmlspecialchars($batch['product_name']); ?> (SKU: <?php echo htmlspecialchars($batch['product_sku']); ?>)</p>
            </div>
            <div class="form-group">
                <label for="original_quantity_produced">Original Quantity Produced:</label>
                <input type="number" id="original_quantity_produced" name="original_quantity_produced" value="<?php echo htmlspecialchars($batch['quantity_produced']); ?>" readonly>
            </div>
            <div class="form-group">
                <label for="adjusted_quantity">Adjusted Quantity:</label>
                <input type="number" id="adjusted_quantity" name="adjusted_quantity" min="0" required value="<?php echo htmlspecialchars($batch['quantity_produced']); ?>">
                <small class="form-text text-muted">Enter the final adjusted quantity (e.g., accounting for damaged pieces, or excess).</small>
            </div>
            <div class="form-group">
                <label for="adjustment_reason">Reason for Adjustment:</label>
                <textarea id="adjustment_reason" name="reason" rows="3" required placeholder="e.g., 5 units damaged during packaging, +2 units found from rework."></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="button secondary close-button">Cancel</button>
                <button type="submit" class="button primary">Save Adjustment</button>
            </div>
        </form>
    </div>
</div>

<div id="deleteBatchModal" class="modal">
    <div class="modal-content">
        <span class="close-button">&times;</span>
        <h3>Confirm Batch Deletion</h3>
        <form id="deleteBatchForm" method="post" action="../api/delete-batch.php">
            <input type="hidden" id="delete_batch_id" name="batch_id">
            <p>Are you absolutely sure you want to delete Batch: <strong id="deleteBatchNumber"></strong>?</p>
            <p><strong>WARNING:</strong> This action is irreversible. All associated materials used in this batch will be returned to inventory. Related costs and quality checks will be removed.</p>
            <div class="form-group">
                <label for="delete_reason">Reason for Deletion (Required):</label>
                <textarea id="delete_reason" name="reason" rows="3" required placeholder="e.g., Batch created in error, major production defect."></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="button secondary close-button">Cancel</button>
                <button type="submit" class="button button-danger">Confirm Delete</button>
            </div>
        </form>
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

    // --- Update Status Modal Logic (existing, slightly refined) ---
    const updateStatusModal = document.getElementById('updateStatusModal');
    const updateStatusBtn = document.getElementById('updateStatusBtn');
    const updateStatusCloseButtons = updateStatusModal.querySelectorAll('.close-button');
    const updateStatusForm = document.getElementById('updateStatusForm');
    const newStatusSelect = document.getElementById('new_status');
    const currentBatchStatus = updateStatusBtn ? updateStatusBtn.dataset.currentStatus : ''; // Get status from button

    if (updateStatusBtn) {
        updateStatusBtn.addEventListener('click', function() {
            // Reset select options first
            Array.from(newStatusSelect.options).forEach(option => {
                option.disabled = false; // Enable all first
                option.selected = (option.value === currentBatchStatus); // Select current status
            });

            // Disable options that are not valid transitions or are the current status
            const validTransitions = {
                'pending': ['cutting'],
                'cutting': ['stitching'],
                'stitching': ['ironing'],
                'ironing': ['packaging'],
                'packaging': ['completed'],
                'completed': []
            };
            const possibleNextStatuses = validTransitions[currentBatchStatus] || [];

            Array.from(newStatusSelect.options).forEach(option => {
                if (option.value === currentBatchStatus) {
                    option.disabled = true; // Cannot select current status
                } else if (!possibleNextStatuses.includes(option.value) && "<?php echo $_SESSION['role']; ?>" !== 'owner') {
                    // Disable if not a valid next step and not an owner
                    option.disabled = true;
                }
            });

            // If current status is 'completed', all others should be disabled
            if (currentBatchStatus === 'completed') {
                 Array.from(newStatusSelect.options).forEach(option => {
                    if (option.value !== 'completed') {
                        option.disabled = true;
                    }
                });
            }


            updateStatusForm.reset(); // Clear form fields
            updateStatusModal.style.display = 'block';
            updateStatusModal.classList.add('show-modal');
            // Clear validation messages
            document.querySelectorAll('#updateStatusForm .invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('#updateStatusForm .validation-error').forEach(el => el.remove());
        });
    }

    updateStatusCloseButtons.forEach(btn => btn.addEventListener('click', () => {
        updateStatusModal.classList.remove('show-modal');
        setTimeout(() => { updateStatusModal.style.display = 'none'; }, 300);
    }));
    window.addEventListener('click', function(event) {
        if (event.target == updateStatusModal) {
            updateStatusModal.classList.remove('show-modal');
            setTimeout(() => { updateStatusModal.style.display = 'none'; }, 300);
        }
    });

    if (updateStatusForm) {
        updateStatusForm.addEventListener('submit', function(event) {
            event.preventDefault();

            let isValid = true;
            document.querySelectorAll('#updateStatusForm .invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('#updateStatusForm .validation-error').forEach(el => el.remove());

            if (!newStatusSelect.value || newStatusSelect.value === currentBatchStatus) {
                showValidationError(newStatusSelect, 'Please select a new status different from the current one.');
                isValid = false;
            }

            if (!isValid) return;

            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner"></span> Updating...';

            const formData = new FormData(this);
            fetch(this.action, { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    submitButton.disabled = false;
                    submitButton.innerHTML = 'Update Status';
                    if (typeof showToast === 'function') { // Use showToast for notifications
                        showToast(data.success ? 'success' : 'error', data.message);
                    } else {
                        alert(data.message); // Fallback
                    }
                    if (data.success) {
                        updateStatusModal.classList.remove('show-modal');
                        setTimeout(() => {
                            updateStatusModal.style.display = 'none';
                            window.location.reload(); // Reload page to reflect status change
                        }, 300);
                    }
                })
                .catch(error => {
                    submitButton.disabled = false;
                    submitButton.innerHTML = 'Update Status';
                    console.error('Error updating status:', error);
                    if (typeof showToast === 'function') {
                        showToast('error', 'An unexpected error occurred while updating status.');
                    } else {
                        alert('An unexpected error occurred.');
                    }
                });
        });
    }

    // --- Adjust Quantity Modal Logic ---
    const adjustQuantityModal = document.getElementById('adjustQuantityModal');
    const adjustQuantityBtn = document.getElementById('adjustQuantityBtn');
    const adjustQuantityCloseButtons = adjustQuantityModal.querySelectorAll('.close-button');
    const adjustQuantityForm = document.getElementById('adjustQuantityForm');
    const adjustedQuantityInput = document.getElementById('adjusted_quantity');
    const adjustmentReasonInput = document.getElementById('adjustment_reason');

    if (adjustQuantityBtn) {
        adjustQuantityBtn.addEventListener('click', function() {
            adjustQuantityForm.reset();
            adjustedQuantityInput.value = this.dataset.currentQuantity; // Pre-fill with current quantity
            adjustQuantityModal.style.display = 'block';
            adjustQuantityModal.classList.add('show-modal');
            // Clear validation messages
            document.querySelectorAll('#adjustQuantityForm .invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('#adjustQuantityForm .validation-error').forEach(el => el.remove());
        });
    }
    adjustQuantityCloseButtons.forEach(btn => btn.addEventListener('click', () => {
        adjustQuantityModal.classList.remove('show-modal');
        setTimeout(() => { adjustQuantityModal.style.display = 'none'; }, 300);
    }));
    window.addEventListener('click', function(event) {
        if (event.target == adjustQuantityModal) {
            closeModal(); // Call close function that also handles removal of show-modal
            setTimeout(() => { adjustQuantityModal.style.display = 'none'; }, 300);
        }
    });

    if (adjustQuantityForm) {
        adjustQuantityForm.addEventListener('submit', function(event) {
            event.preventDefault();

            let isValid = true;
            document.querySelectorAll('#adjustQuantityForm .invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('#adjustQuantityForm .validation-error').forEach(el => el.remove());

            if (!adjustedQuantityInput.value || parseFloat(adjustedQuantityInput.value) < 0) {
                showValidationError(adjustedQuantityInput, 'Adjusted quantity must be a non-negative number.');
                isValid = false;
            }
            if (!adjustmentReasonInput.value.trim()) {
                showValidationError(adjustmentReasonInput, 'Reason for adjustment is required.');
                isValid = false;
            }

            if (!isValid) return;

            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner"></span> Saving...';

            const formData = new FormData(this);
            fetch(this.action, { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    submitButton.disabled = false;
                    submitButton.innerHTML = 'Save Adjustment';
                    if (typeof showToast === 'function') {
                        showToast(data.success ? 'success' : 'error', data.message);
                    } else {
                        alert(data.message);
                    }
                    if (data.success) {
                        adjustQuantityModal.classList.remove('show-modal');
                        setTimeout(() => {
                            adjustQuantityModal.style.display = 'none';
                            window.location.reload(); // Reload page to reflect new quantity
                        }, 300);
                    }
                })
                .catch(error => {
                    submitButton.disabled = false;
                    submitButton.innerHTML = 'Save Adjustment';
                    console.error('Error adjusting quantity:', error);
                    if (typeof showToast === 'function') {
                        showToast('error', 'An unexpected error occurred while adjusting quantity.');
                    } else {
                        alert('An unexpected error occurred.');
                    }
                });
        });
    }

    // --- Delete Batch Modal Logic ---
    const deleteBatchModal = document.getElementById('deleteBatchModal');
    const deleteBatchBtn = document.getElementById('deleteBatchBtn');
    const deleteBatchCloseButtons = deleteBatchModal.querySelectorAll('.close-button');
    const deleteBatchForm = document.getElementById('deleteBatchForm');
    const deleteBatchNumberDisplay = document.getElementById('deleteBatchNumber');
    const deleteBatchIdInput = document.getElementById('delete_batch_id');
    const deleteReasonInput = document.getElementById('delete_reason');

    if (deleteBatchBtn) {
        deleteBatchBtn.addEventListener('click', function() {
            deleteBatchNumberDisplay.textContent = this.dataset.batchNumber;
            deleteBatchIdInput.value = this.dataset.batchId;
            deleteReasonInput.value = ''; // Clear reason field
            deleteBatchModal.style.display = 'block';
            deleteBatchModal.classList.add('show-modal');
            // Clear validation messages
            document.querySelectorAll('#deleteBatchForm .invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('#deleteBatchForm .validation-error').forEach(el => el.remove());
        });
    }
    deleteBatchCloseButtons.forEach(btn => btn.addEventListener('click', () => {
        deleteBatchModal.classList.remove('show-modal');
        setTimeout(() => { deleteBatchModal.style.display = 'none'; }, 300);
    }));
    window.addEventListener('click', function(event) {
        if (event.target == deleteBatchModal) {
            closeModal(); // Call close function that also handles removal of show-modal
            setTimeout(() => { deleteBatchModal.style.display = 'none'; }, 300);
        }
    });

    if (deleteBatchForm) {
        deleteBatchForm.addEventListener('submit', function(event) {
            event.preventDefault();

            let isValid = true;
            document.querySelectorAll('#deleteBatchForm .invalid-input').forEach(el => el.classList.remove('invalid-input'));
            document.querySelectorAll('#deleteBatchForm .validation-error').forEach(el => el.remove());

            if (!deleteReasonInput.value.trim()) {
                showValidationError(deleteReasonInput, 'Reason for deletion is required.');
                isValid = false;
            }

            if (!isValid) return;

            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner"></span> Deleting...';

            const formData = new FormData(this);
            fetch(this.action, { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    submitButton.disabled = false;
                    submitButton.innerHTML = 'Confirm Delete';
                    if (typeof showToast === 'function') {
                        showToast(data.success ? 'success' : 'error', data.message);
                    } else {
                        alert(data.message);
                    }
                    if (data.success) {
                        deleteBatchModal.classList.remove('show-modal');
                        setTimeout(() => {
                            deleteBatchModal.style.display = 'none';
                            // Redirect to manufacturing list after successful deletion
                            window.location.href = 'manufacturing.php';
                        }, 300);
                    }
                })
                .catch(error => {
                    submitButton.disabled = false;
                    submitButton.innerHTML = 'Confirm Delete';
                    console.error('Error deleting batch:', error);
                    if (typeof showToast === 'function') {
                        showToast('error', 'An unexpected error occurred while deleting batch.');
                    } else {
                        alert('An unexpected error occurred.');
                    }
                });
        });
    }

    // --- Print Invoice functionality (client-side print, can be enhanced with server-side PDF later) ---
    const printSaleBtn = document.getElementById('printSaleBtn'); // This button should be in view-sale.php, but copied from there if exists.
    if (printSaleBtn) {
        printSaleBtn.addEventListener('click', function() {
            // Updated to link to the new PDF generation API
            const saleId = <?php echo htmlspecialchars($sale_id); ?>; // Assuming sale_id is available in PHP context
            window.open(`../api/generate-invoice-pdf.php?sale_id=${saleId}`, '_blank');
        });
    }
    // --- Email Invoice functionality (placeholder, will require backend email sending) ---
    const emailSaleBtn = document.getElementById('emailSaleBtn'); // This button should be in view-sale.php
    if (emailSaleBtn) {
        emailSaleBtn.addEventListener('click', function() {
            const saleId = this.dataset.saleId; // Get sale_id from button's data attribute
            if (confirm('Are you sure you want to email this invoice?')) {
                // Show loading state (e.g., disable button, add spinner)
                this.disabled = true;
                this.innerHTML = '<span class="spinner"></span> Sending...';

                fetch('../api/email-invoice.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `sale_id=${saleId}`
                })
                .then(response => response.json())
                .then(data => {
                    this.disabled = false;
                    this.innerHTML = '<i class="fas fa-envelope"></i> Email Invoice'; // Reset button

                    if (typeof showToast === 'function') {
                        showToast(data.success ? 'success' : 'error', data.message);
                    } else {
                        alert(data.message);
                    }
                })
                .catch(error => {
                    this.disabled = false;
                    this.innerHTML = '<i class="fas fa-envelope"></i> Email Invoice';
                    console.error('Error emailing invoice:', error);
                    if (typeof showToast === 'function') {
                        showToast('error', 'An unexpected error occurred while emailing invoice.');
                    } else {
                        alert('An unexpected error occurred.');
                    }
                });
            }
        });
    }

    // Helper functions for validation (consider moving to utils.js later for global use)
    function showValidationError(element, message) {
        const existingError = element.parentElement.querySelector('.validation-error');
        if (existingError) { existingError.remove(); }
        element.classList.add('invalid-input');
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
            if (errorElement) { errorElement.remove(); }
        });
    });
});
</script>

<?php include_once '../includes/footer.php'; ?>