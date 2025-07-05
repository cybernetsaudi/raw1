<?php
session_start();
$page_title = "Fund Returns";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Ensure user is a shopkeeper
if($_SESSION['role'] !== 'shopkeeper') {
    header('Location: ../index.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();

// Get recent sales that can be used for fund returns
$sales_query = "SELECT s.id, s.invoice_number, s.sale_date, s.net_amount, c.name as customer_name,
                (SELECT COALESCE(SUM(amount), 0) FROM fund_returns WHERE sale_id = s.id) as returned_amount
                FROM sales s 
                JOIN customers c ON s.customer_id = c.id 
                WHERE s.shopkeeper_id = ? 
                AND s.net_amount > (SELECT COALESCE(SUM(amount), 0) FROM fund_returns WHERE sale_id = s.id)
                ORDER BY s.sale_date DESC";
$sales_stmt = $db->prepare($sales_query);
$sales_stmt->execute([$_SESSION['user_id']]);
$sales = $sales_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent fund returns
$returns_query = "SELECT fr.*, s.invoice_number, s.net_amount, s.sale_date, c.name as customer_name
                  FROM fund_returns fr 
                  JOIN sales s ON fr.sale_id = s.id 
                  JOIN customers c ON s.customer_id = c.id 
                  WHERE fr.returned_by = ? 
                  ORDER BY fr.returned_at DESC";
$returns_stmt = $db->prepare($returns_query);
$returns_stmt->execute([$_SESSION['user_id']]);
$returns = $returns_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container">
    <div class="page-header">
        <h1>Fund Returns</h1>
        <p>Manage your fund returns from sales to the owner</p>
    </div>

    <?php if(isset($_GET['success'])): ?>
    <div class="alert alert-success">
        <?php if($_GET['success'] == 1): ?>
        Fund return request submitted successfully.
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if(isset($_GET['error'])): ?>
    <div class="alert alert-error">
        <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h2>Submit New Return</h2>
                </div>
                <div class="card-body">
                    <form id="returnForm" class="form">
                        <div class="form-group">
                            <label for="sale_id">Select Sale:</label>
                            <select id="sale_id" name="sale_id" class="form-control" required>
                                <option value="">Choose a sale...</option>
                                <?php foreach($sales as $sale): ?>
                                <option value="<?php echo $sale['id']; ?>" 
                                        data-amount="<?php echo $sale['net_amount'] - $sale['returned_amount']; ?>">
                                    Invoice #<?php echo $sale['invoice_number']; ?> - 
                                    <?php echo date('M j, Y', strtotime($sale['sale_date'])); ?> - 
                                    <?php echo number_format($sale['net_amount'] - $sale['returned_amount'], 2); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="amount">Amount to Return:</label>
                            <input type="number" id="amount" name="amount" class="form-control" step="0.01" min="0" required>
                        </div>

                        <div class="form-group">
                            <label for="notes">Notes:</label>
                            <textarea id="notes" name="notes" class="form-control" rows="3"></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary">Submit Return Request</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h2>Recent Returns</h2>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Sale</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($returns as $return): ?>
                                <tr>
                                    <td><?php echo date('M j, Y', strtotime($return['returned_at'])); ?></td>
                                    <td>Invoice #<?php echo $return['invoice_number']; ?></td>
                                    <td><?php echo number_format($return['amount'], 2); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $return['status']; ?>">
                                            <?php echo ucfirst($return['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <?php if(empty($returns)): ?>
                                <tr>
                                    <td colspan="4" class="text-center">No fund returns found</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const saleSelect = document.getElementById('sale_id');
    const amountInput = document.getElementById('amount');
    const returnForm = document.getElementById('returnForm');

    // Update amount input when sale is selected
    saleSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if(selectedOption.value) {
            amountInput.value = selectedOption.dataset.amount;
            amountInput.max = selectedOption.dataset.amount;
        } else {
            amountInput.value = '';
            amountInput.max = '';
        }
    });

    // Handle form submission
    returnForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const formData = {
            sale_id: saleSelect.value,
            amount: amountInput.value,
            notes: document.getElementById('notes').value
        };

        try {
            const response = await fetch('../api/return-funds.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(formData)
            });

            const data = await response.json();

            if(data.success) {
                window.location.href = 'fund-returns.php?success=1';
            } else {
                window.location.href = 'fund-returns.php?error=' + encodeURIComponent(data.message);
            }
        } catch(error) {
            window.location.href = 'fund-returns.php?error=' + encodeURIComponent('An error occurred while submitting the return request.');
        }
    });
});
</script>

<style>
.badge {
    padding: 0.5em 0.75em;
    border-radius: 4px;
    font-size: 0.875rem;
    font-weight: 500;
}

.badge-pending {
    background-color: #fef3c7;
    color: #92400e;
}

.badge-approved {
    background-color: #dcfce7;
    color: #166534;
}

.badge-rejected {
    background-color: #fee2e2;
    color: #991b1b;
}

.form-group {
    margin-bottom: 1rem;
}

.form-control {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid #d1d5db;
    border-radius: 4px;
}

.btn-primary {
    background-color: #2563eb;
    color: white;
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

.btn-primary:hover {
    background-color: #1d4ed8;
}

.alert {
    padding: 1rem;
    border-radius: 4px;
    margin-bottom: 1rem;
}

.alert-success {
    background-color: #dcfce7;
    color: #166534;
    border: 1px solid #86efac;
}

.alert-error {
    background-color: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}
</style>

<?php include_once '../includes/footer.php'; ?>