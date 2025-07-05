<?php
// Initialize session and error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    session_start();
    $page_title = "Update Manufacturing Batch";
    include_once '../config/database.php';
    include_once '../config/auth.php';
    include_once '../includes/header.php';

    // Check if batch ID is provided
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("Invalid batch ID");
    }

    $batch_id = intval($_GET['id']);

    // Initialize database connection
    $database = new Database();
    $db = $database->getConnection();

    // Get batch details
    $batch_query = "SELECT b.*, p.name as product_name, p.sku as product_sku
                   FROM manufacturing_batches b
                   JOIN products p ON b.product_id = p.id
                   WHERE b.id = ?";
    $batch_stmt = $db->prepare($batch_query);
    $batch_stmt->execute([$batch_id]);

    if ($batch_stmt->rowCount() === 0) {
        throw new Exception("Batch not found");
    }

    $batch = $batch_stmt->fetch(PDO::FETCH_ASSOC);

    // Check if batch is already completed
    if ($batch['status'] === 'completed') {
        throw new Exception("Cannot update a completed batch");
    }

    // Process form submission
    $success_message = '';
    $error_message = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            // Validate inputs
            $new_status = $_POST['status'] ?? '';
            $completion_date = null;
            
            $valid_statuses = ['pending', 'cutting', 'stitching', 'ironing', 'packaging', 'completed'];
            if (!in_array($new_status, $valid_statuses)) {
                throw new Exception("Invalid status selected");
            }
            
            // If status is completed, require completion date
            if ($new_status === 'completed') {
                if (empty($_POST['completion_date'])) {
                    throw new Exception("Completion date is required for completed status");
                }
                $completion_date = $_POST['completion_date'];
            }
            
            // Notes are optional
            $notes = $_POST['notes'] ?? '';
            
            // Start transaction
            $db->beginTransaction();
            
            // Update batch status
            $update_query = "UPDATE manufacturing_batches SET 
                           status = ?, 
                           completion_date = ?, 
                           notes = CONCAT(IFNULL(notes, ''), ?)
                           WHERE id = ?";
            $update_stmt = $db->prepare($update_query);
            
            // Add a timestamp and user to the notes
            $note_addition = "\n\n[" . date('Y-m-d H:i:s') . " - " . $_SESSION['username'] . "] Status updated to " . ucfirst($new_status);
            if (!empty($notes)) {
                $note_addition .= ": " . $notes;
            }
            
            $update_stmt->execute([
                $new_status,
                $completion_date,
                $note_addition,
                $batch_id
            ]);
            
            // If status is completed, update inventory
            if ($new_status === 'completed') {
                // Check if inventory record exists
                $check_query = "SELECT id FROM inventory WHERE product_id = ? AND location = 'manufacturing'";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->execute([$batch['product_id']]);
                
                if ($check_stmt->rowCount() > 0) {
                    // Update existing inventory
                    $inventory_id = $check_stmt->fetch(PDO::FETCH_ASSOC)['id'];
                    $update_inventory = "UPDATE inventory SET 
                                        quantity = quantity + ?, 
                                        updated_at = NOW() 
                                        WHERE id = ?";
                    $update_inv_stmt = $db->prepare($update_inventory);
                    $update_inv_stmt->execute([$batch['quantity_produced'], $inventory_id]);
                } else {
                    // Create new inventory record
                    $insert_inventory = "INSERT INTO inventory 
                                        (product_id, quantity, location, updated_at) 
                                        VALUES (?, ?, 'manufacturing', NOW())";
                    $insert_inv_stmt = $db->prepare($insert_inventory);
                    $insert_inv_stmt->execute([$batch['product_id'], $batch['quantity_produced']]);
                }
            }
            
            // Log the activity
            $activity_query = "INSERT INTO activity_logs 
                              (user_id, action_type, module, description, entity_id) 
                              VALUES (?, 'update', 'manufacturing', ?, ?)";
            $activity_stmt = $db->prepare($activity_query);
            $activity_stmt->execute([
                $_SESSION['user_id'],
                "Updated batch " . $batch['batch_number'] . " status to " . ucfirst($new_status),
                $batch_id
            ]);
            
            // Commit transaction
            $db->commit();
            
            $success_message = "Batch status updated successfully to " . ucfirst($new_status);
            
            // Refresh batch data
            $batch_stmt->execute([$batch_id]);
            $batch = $batch_stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            // Rollback transaction
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            
            $error_message = $e->getMessage();
        }
    }
    
    // Get status options for dropdown
    $status_options = [
        'pending' => 'Pending',
        'cutting' => 'Cutting',
        'stitching' => 'Stitching',
        'ironing' => 'Ironing',
        'packaging' => 'Packaging',
        'completed' => 'Completed'
    ];

} catch (Exception $e) {
    // Log the error
    error_log('Update batch error: ' . $e->getMessage());
    
    // Display user-friendly error
    echo '<div class="error-container">';
    echo '<h2>Error</h2>';
    echo '<p>' . $e->getMessage() . '</p>';
    echo '<a href="manufacturing.php" class="button secondary">Back to Manufacturing Batches</a>';
    echo '</div>';
    
    // Exit to prevent further processing
    include_once '../includes/footer.php';
    exit;
}
?>
<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt; 
    <a href="manufacturing.php">Manufacturing Batches</a> &gt; 
    <a href="view-batch.php?id=<?php echo $batch_id; ?>">View Batch <?php echo htmlspecialchars($batch['batch_number']); ?></a> &gt;
    <span>Update Status</span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>Update Batch Status</h2>
        <span class="status-badge status-<?php echo $batch['status']; ?>"><?php echo ucfirst($batch['status']); ?></span>
    </div>
    <div class="page-actions">
        <a href="view-batch.php?id=<?php echo $batch_id; ?>" class="button secondary">
            <i class="fas fa-arrow-left"></i> Back to Batch Details
        </a>
    </div>
</div>

<?php if (!empty($success_message)): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i>
    <span><?php echo $success_message; ?></span>
    <span class="alert-close">&times;</span>
</div>
<?php endif; ?>

<?php if (!empty($error_message)): ?>
<div class="alert alert-error">
    <i class="fas fa-exclamation-circle"></i>
    <span><?php echo $error_message; ?></span>
    <span class="alert-close">&times;</span>
</div>
<?php endif; ?>

<div class="update-batch-container">
    <div class="batch-summary-card">
        <div class="card-header">
            <h3>Batch Summary</h3>
        </div>
        <div class="card-content">
            <div class="batch-info">
                <div class="batch-number">
                    <span class="label">Batch Number:</span>
                    <span class="value"><?php echo htmlspecialchars($batch['batch_number']); ?></span>
                </div>
                <div class="product-info">
                    <span class="label">Product:</span>
                    <span class="value">
                        <?php echo htmlspecialchars($batch['product_name']); ?>
                        <span class="sku">(<?php echo htmlspecialchars($batch['product_sku']); ?>)</span>
                    </span>
                </div>
                <div class="quantity-info">
                    <span class="label">Quantity:</span>
                    <span class="value"><?php echo number_format($batch['quantity_produced']); ?> units</span>
                </div>
                <div class="status-info">
                    <span class="label">Current Status:</span>
                    <span class="value">
                        <span class="status-badge status-<?php echo $batch['status']; ?>"><?php echo ucfirst($batch['status']); ?></span>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="update-form-card">
        <div class="card-header">
            <h3>Update Status</h3>
        </div>
        <div class="card-content">
            <form id="updateBatchForm" method="post" action="">
                <div class="form-group">
                    <label for="status">New Status:</label>
                    <select id="status" name="status" required>
                        <?php foreach ($status_options as $value => $label): ?>
                            <?php 
                            // Determine if this option should be disabled
                            $disabled = false;
                            $current_status_index = array_search($batch['status'], array_keys($status_options));
                            $option_index = array_search($value, array_keys($status_options));
                            
                            // Disable options that are more than one step behind the current status
                            if ($option_index < $current_status_index - 1) {
                                $disabled = true;
                            }
                            ?>
                            <option value="<?php echo $value; ?>" 
                                    <?php echo $value === $batch['status'] ? 'selected' : ''; ?>
                                    <?php echo $disabled ? 'disabled' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint">
                        <i class="fas fa-info-circle"></i> 
                        You can only move one step backwards from the current status.
                    </div>
                </div>
                
                <div id="completionDateContainer" class="form-group" style="display: none;">
                    <label for="completion_date">Completion Date:</label>
                    <input type="date" id="completion_date" name="completion_date" 
                           value="<?php echo date('Y-m-d'); ?>">
                    <div class="form-hint">
                        <i class="fas fa-info-circle"></i> 
                        Required when marking a batch as completed.
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="notes">Notes:</label>
                    <textarea id="notes" name="notes" rows="4" placeholder="Add any notes about this status update"></textarea>
                </div>
                
                <div class="status-flow-info">
                    <h4>Manufacturing Process Flow</h4>
                    <div class="status-flow">
                        <div class="flow-step <?php echo $batch['status'] === 'pending' ? 'current' : ($batch['status'] === 'cutting' || $batch['status'] === 'stitching' || $batch['status'] === 'ironing' || $batch['status'] === 'packaging' || $batch['status'] === 'completed' ? 'completed' : ''); ?>">
                            <div class="step-number">1</div>
                            <div class="step-label">Pending</div>
                        </div>
                        <div class="flow-step <?php echo $batch['status'] === 'cutting' ? 'current' : ($batch['status'] === 'stitching' || $batch['status'] === 'ironing' || $batch['status'] === 'packaging' || $batch['status'] === 'completed' ? 'completed' : ''); ?>">
                            <div class="step-number">2</div>
                            <div class="step-label">Cutting</div>
                        </div>
                        <div class="flow-step <?php echo $batch['status'] === 'stitching' ? 'current' : ($batch['status'] === 'ironing' || $batch['status'] === 'packaging' || $batch['status'] === 'completed' ? 'completed' : ''); ?>">
                            <div class="step-number">3</div>
                            <div class="step-label">Stitching</div>
                        </div>
                        <div class="flow-step <?php echo $batch['status'] === 'ironing' ? 'current' : ($batch['status'] === 'packaging' || $batch['status'] === 'completed' ? 'completed' : ''); ?>">
                            <div class="step-number">4</div>
                            <div class="step-label">Ironing</div>
                        </div>
                        <div class="flow-step <?php echo $batch['status'] === 'packaging' ? 'current' : ($batch['status'] === 'completed' ? 'completed' : ''); ?>">
                            <div class="step-number">5</div>
                            <div class="step-label">Packaging</div>
                        </div>
                        <div class="flow-step <?php echo $batch['status'] === 'completed' ? 'current' : ''; ?>">
                            <div class="step-number">6</div>
                            <div class="step-label">Completed</div>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <a href="view-batch.php?id=<?php echo $batch_id; ?>" class="button secondary">Cancel</a>
                    <button type="submit" class="button primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>


<style>
/* Update Batch Page Styles */
.breadcrumb {
    margin-bottom: 1rem;
    font-size: 0.9rem;
    color: var(--text-secondary, #6c757d);
}



.breadcrumb a {
    color: var(--primary, #1a73e8);
    text-decoration: none;
}



.breadcrumb a:hover {
    text-decoration: underline;
}



.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}



.page-title {
    display: flex;
    align-items: center;
    gap: 1rem;
}



.page-title h2 {
    margin: 0;
}



.page-actions {
    display: flex;
    gap: 0.5rem;
}



/* Alert Styles */
.alert {
    padding: 1rem;
    border-radius: 4px;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}



.alert-success {
    background-color: #d4edda;
    border-left: 4px solid #28a745;
    color: #155724;
}



.alert-error {
    background-color: #f8d7da;
    border-left: 4px solid #dc3545;
    color: #721c24;
}



.alert i {
    font-size: 1.25rem;
}



.alert-close {
    margin-left: auto;
    cursor: pointer;
    font-size: 1.25rem;
    opacity: 0.7;
}



.alert-close:hover {
    opacity: 1;
}



/* Update Batch Container */
.update-batch-container {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 1.5rem;
}



/* Card Styles */
.batch-summary-card,
.update-form-card {
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}



.card-header {
    padding: 1rem 1.5rem;
    background-color: #f8f9fa;
    border-bottom: 1px solid #e9ecef;
}



.card-header h3 {
    margin: 0;
    font-size: 1.1rem;
}



.card-content {
    padding: 1.5rem;
}



/* Batch Summary Styles */
.batch-info > div {
    margin-bottom: 1rem;
    display: flex;
    flex-direction: column;
}



.batch-info > div:last-child {
    margin-bottom: 0;
}



.batch-info .label {
    font-size: 0.9rem;
    color: var(--text-secondary, #6c757d);
    margin-bottom: 0.25rem;
}



.batch-info .value {
    font-weight: 500;
}



.batch-info .sku {
    font-weight: normal;
    color: var(--text-secondary, #6c757d);
    margin-left: 0.5rem;
}



/* Form Styles */
.form-group {
    margin-bottom: 1.5rem;
}



.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
}



.form-group select,
.form-group input[type="date"],
.form-group textarea {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--border, #dee2e6);
    border-radius: 4px;
    font-size: 1rem;
}



.form-group select:focus,
.form-group input[type="date"]:focus,
.form-group textarea:focus {
    border-color: var(--primary, #1a73e8);
    outline: none;
    box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.25);
}



.form-hint {
    font-size: 0.85rem;
    color: var(--text-secondary, #6c757d);
    margin-top: 0.5rem;
}



/* Status Flow Styles */
.status-flow-info {
    margin-bottom: 1.5rem;
    padding: 1rem;
    background-color: #f8f9fa;
    border-radius: 4px;
}



.status-flow-info h4 {
    margin-top: 0;
    margin-bottom: 1rem;
    font-size: 1rem;
}



.status-flow {
    display: flex;
    justify-content: space-between;
    position: relative;
}



.status-flow::before {
    content: '';
    position: absolute;
    top: 20px;
    left: 30px;
    right: 30px;
    height: 2px;
    background-color: #e9ecef;
    z-index: 1;
}



.flow-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    z-index: 2;
}



.step-number {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background-color: white;
    border: 2px solid #e9ecef;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    margin-bottom: 0.5rem;
}



.step-label {
    font-size: 0.8rem;
    text-align: center;
}



.flow-step.completed .step-number {
    background-color: var(--primary, #1a73e8);
    border-color: var(--primary, #1a73e8);
    color: white;
}



.flow-step.current .step-number {
    border-color: var(--primary, #1a73e8);
    border-width: 3px;
    color: var(--primary, #1a73e8);
    font-weight: 700;
}



/* Form Actions */
.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    margin-top: 2rem;
}



/* Status Badge */
.status-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: 0.8rem;
    font-weight: 500;
    text-transform: capitalize;
}



.status-pending { background-color: #fff3cd; color: #856404; }
.status-cutting { background-color: #cce5ff; color: #004085; }
.status-stitching { background-color: #d1c4e9; color: #4527a0; }
.status-ironing { background-color: #f8d7da; color: #721c24; }
.status-packaging { background-color: #fff3e0; color: #e65100; }
.status-completed { background-color: #d4edda; color: #155724; }



/* Error Container */
.error-container {
    background-color: #f8d7da;
    color: #721c24;
    padding: 2rem;
    border-radius: 8px;
    text-align: center;
    margin: 2rem 0;
}



.error-container h2 {
    margin-top: 0;
}



.error-container .button {
    margin-top: 1rem;
}



/* Button Styles */
.button {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    border-radius: 4px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    border: none;
    text-decoration: none;
}



.button.primary {
    background-color: var(--primary, #1a73e8);
    color: white;
}



.button.primary:hover {
    background-color: var(--primary-dark, #0d47a1);
}



.button.secondary {
    background-color: #f8f9fa;
    color: var(--text-secondary, #6c757d);
    border: 1px solid #dee2e6;
}



.button.secondary:hover {
    background-color: #e9ecef;
}



/* Responsive Styles */
@media (max-width: 992px) {
    .update-batch-container {
        grid-template-columns: 1fr;
    }
    
    .status-flow {
        overflow-x: auto;
        padding-bottom: 0.5rem;
    }
    
    .flow-step {
        margin: 0 0.5rem;
    }
}



@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions .button {
        width: 100%;
    }
    
    .status-flow::before {
        display: none;
    }
    
    .status-flow {
        flex-direction: column;
        gap: 1rem;
    }
    
    .flow-step {
        flex-direction: row;
        align-items: center;
        gap: 1rem;
    }
    
    .step-number {
        margin-bottom: 0;
    }
}
</style>



<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle status change to show/hide completion date
    const statusSelect = document.getElementById('status');
    const completionDateContainer = document.getElementById('completionDateContainer');
    
    function updateCompletionDateVisibility() {
        if (statusSelect.value === 'completed') {
            completionDateContainer.style.display = 'block';
            document.getElementById('completion_date').setAttribute('required', 'required');
        } else {
            completionDateContainer.style.display = 'none';
            document.getElementById('completion_date').removeAttribute('required');
        }
    }
    
    // Initialize on page load
    updateCompletionDateVisibility();
    
    // Update whenever status changes
    statusSelect.addEventListener('change', updateCompletionDateVisibility);
    
    // Close alert buttons
    const alertCloseButtons = document.querySelectorAll('.alert-close');
    alertCloseButtons.forEach(button => {
        button.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });
    
    // Form validation
    const updateBatchForm = document.getElementById('updateBatchForm');
    if (updateBatchForm) {
        updateBatchForm.addEventListener('submit', function(event) {
            let isValid = true;
            
            // Validate status
            if (!statusSelect.value) {
                showValidationError(statusSelect, 'Please select a status');
                isValid = false;
            }
            
            // Validate completion date if status is completed
            if (statusSelect.value === 'completed') {
                const completionDate = document.getElementById('completion_date');
                if (!completionDate.value) {
                    showValidationError(completionDate, 'Completion date is required when status is completed');
                    isValid = false;
                }
            }
            
            if (!isValid) {
                event.preventDefault();
            } else {
                // Show loading state
                const submitButton = this.querySelector('button[type="submit"]');
                submitButton.disabled = true;
                submitButton.innerHTML = '<span class="spinner"></span> Updating...';
                
                // Log activity
                if (typeof logUserActivity === 'function') {
                    logUserActivity(
                        'update', 
                        'manufacturing', 
                        'Updated batch status for <?php echo htmlspecialchars($batch['batch_number']); ?>'
                    );
                }
            }
        });
    }
    
    // Helper function to show validation errors
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
        
        // Focus the element
        element.focus();
    }
});
</script>



<?php include_once '../includes/footer.php'; ?>