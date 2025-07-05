<?php
session_start();
$page_title = "Inventory Overview";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get inventory summary
$summary_query = "SELECT 
    SUM(CASE WHEN location = 'manufacturing' THEN 1 ELSE 0 END) as manufacturing_products,
    SUM(CASE WHEN location = 'manufacturing' THEN quantity ELSE 0 END) as manufacturing_quantity,
    SUM(CASE WHEN location = 'wholesale' THEN 1 ELSE 0 END) as wholesale_products,
    SUM(CASE WHEN location = 'wholesale' THEN quantity ELSE 0 END) as wholesale_quantity,
    SUM(CASE WHEN location = 'transit' THEN 1 ELSE 0 END) as transit_products,
    SUM(CASE WHEN location = 'transit' THEN quantity ELSE 0 END) as transit_quantity
    FROM inventory";
$summary_stmt = $db->prepare($summary_query);
$summary_stmt->execute();
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

// Get raw materials summary
$materials_query = "SELECT COUNT(*) as total_materials, SUM(stock_quantity) as total_stock
                   FROM raw_materials";
$materials_stmt = $db->prepare($materials_query);
$materials_stmt->execute();
$materials = $materials_stmt->fetch(PDO::FETCH_ASSOC);

// Get inventory by location with pagination
$location = isset($_GET['location']) ? $_GET['location'] : 'all';
$records_per_page = 10;
$page = isset($_GET['page']) ? $_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Build the where clause based on location filter
$where_clause = "";
$params = array();

if ($location !== 'all') {
    $where_clause = "WHERE i.location = :location";
    $params[':location'] = $location;
}

// Count total records for pagination
$count_query = "SELECT COUNT(*) as total FROM inventory i $where_clause";
$count_stmt = $db->prepare($count_query);
foreach ($params as $param => $value) {
    $count_stmt->bindValue($param, $value);
}
$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get inventory items with pagination - MODIFIED TO INCLUDE PRODUCT_ID
$inventory_query = "SELECT i.id, i.product_id, p.name as product_name, p.sku, i.quantity, i.location, i.updated_at
                   FROM inventory i
                   JOIN products p ON i.product_id = p.id
                   $where_clause
                   ORDER BY p.name
                   LIMIT :offset, :records_per_page";
$inventory_stmt = $db->prepare($inventory_query);
foreach ($params as $param => $value) {
    $inventory_stmt->bindValue($param, $value);
}
$inventory_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$inventory_stmt->bindValue(':records_per_page', $records_per_page, PDO::PARAM_INT);
$inventory_stmt->execute();

// Get recent inventory transfers with shopkeeper names
$transfers_query = "SELECT t.id, p.name as product_name, t.quantity, t.from_location, 
                   t.to_location, t.transfer_date, t.status, u1.username as initiated_by_user,
                   u2.username as shopkeeper_username
                   FROM inventory_transfers t
                   JOIN products p ON t.product_id = p.id
                   JOIN users u1 ON t.initiated_by = u1.id
                   LEFT JOIN users u2 ON t.shopkeeper_id = u2.id
                   ORDER BY t.transfer_date DESC
                   LIMIT 5";
$transfers_stmt = $db->prepare($transfers_query);
$transfers_stmt->execute();

// NEW: Get shopkeepers for dropdown
$shopkeepers_query = "SELECT id, full_name, username FROM users WHERE role = 'shopkeeper' AND is_active = 1";
$shopkeepers_stmt = $db->prepare($shopkeepers_query);
$shopkeepers_stmt->execute();
$shopkeepers = $shopkeepers_stmt->fetchAll(PDO::FETCH_ASSOC);

// NEW: Get product distribution across all locations
$product_distribution_query = "SELECT 
    p.id as product_id,
    p.name as product_name,
    p.sku,
    mb.batch_number,
    COALESCE(SUM(CASE WHEN i.location = 'manufacturing' THEN i.quantity ELSE 0 END), 0) as manufacturing_qty,
    COALESCE(SUM(CASE WHEN i.location = 'transit' THEN i.quantity ELSE 0 END), 0) as transit_qty,
    COALESCE(SUM(CASE WHEN i.location = 'wholesale' THEN i.quantity ELSE 0 END), 0) as wholesale_qty,
    COALESCE((SELECT SUM(si.quantity) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE si.product_id = p.id), 0) as sold_qty
    FROM products p
    LEFT JOIN inventory i ON p.id = i.product_id
    LEFT JOIN manufacturing_batches mb ON p.id = mb.product_id
    GROUP BY p.id, p.name, p.sku
    ORDER BY p.name
    LIMIT 10";
$product_distribution_stmt = $db->prepare($product_distribution_query);
$product_distribution_stmt->execute();

// NEW: Process transfer form submission
$transfer_message = '';
$transfer_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer_inventory'])) {
    try {
        // Validate inputs
        $inventory_id = $_POST['inventory_id'] ?? 0;
        $product_id = $_POST['product_id'] ?? 0;
        $quantity = $_POST['quantity'] ?? 0;
        $shopkeeper_id = $_POST['shopkeeper_id'] ?? 0;
        $from_location = $_POST['from_location'] ?? '';
        
        if (!$inventory_id || !$product_id || !$quantity || !$shopkeeper_id || !$from_location) {
            throw new Exception("All fields are required");
        }
        
        // Check if there's enough inventory
        $check_query = "SELECT quantity FROM inventory WHERE id = ? AND product_id = ? AND location = ?";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute([$inventory_id, $product_id, $from_location]);
        $current_quantity = $check_stmt->fetch(PDO::FETCH_COLUMN);
        
        if (!$current_quantity || $current_quantity < $quantity) {
            throw new Exception("Not enough inventory available for transfer");
        }
        
        // Start transaction
        $db->beginTransaction();
        
        // Reduce quantity from source location
        $reduce_query = "UPDATE inventory SET quantity = quantity - ? WHERE id = ?";
        $reduce_stmt = $db->prepare($reduce_query);
        $reduce_stmt->execute([$quantity, $inventory_id]);
        
        // Create transit inventory or update if exists
        $check_transit_query = "SELECT id FROM inventory WHERE product_id = ? AND location = 'transit'";
        $check_transit_stmt = $db->prepare($check_transit_query);
        $check_transit_stmt->execute([$product_id]);
        
        if ($check_transit_stmt->rowCount() > 0) {
            // Update existing transit inventory
            $update_transit_query = "UPDATE inventory SET quantity = quantity + ? WHERE product_id = ? AND location = 'transit'";
            $update_transit_stmt = $db->prepare($update_transit_query);
            $update_transit_stmt->execute([$quantity, $product_id]);
        } else {
            // Create new transit inventory
            $insert_transit_query = "INSERT INTO inventory (product_id, quantity, location) VALUES (?, ?, 'transit')";
            $insert_transit_stmt = $db->prepare($insert_transit_query);
            $insert_transit_stmt->execute([$product_id, $quantity]);
        }
        
        // Record the transfer
        $transfer_query = "INSERT INTO inventory_transfers 
                          (product_id, quantity, from_location, to_location, initiated_by, status, shopkeeper_id) 
                          VALUES (?, ?, ?, 'transit', ?, 'pending', ?)";
        $transfer_stmt = $db->prepare($transfer_query);
        $transfer_stmt->execute([
            $product_id, 
            $quantity, 
            $from_location,
            $_SESSION['user_id'],
            $shopkeeper_id
        ]);
        
        $transfer_id = $db->lastInsertId();
        
        // Create notification for shopkeeper
        $notification_query = "INSERT INTO notifications 
                              (user_id, type, message, related_id, is_read, created_at) 
                              VALUES (?, 'inventory_transfer', ?, ?, 0, NOW())";
        $notification_stmt = $db->prepare($notification_query);
        $notification_stmt->execute([
            $shopkeeper_id,
            "New inventory shipment of {$quantity} units is on the way",
            $transfer_id
        ]);
        
        // Commit transaction
        $db->commit();
        
        $transfer_message = "Inventory transfer initiated successfully";
        
        // Refresh the page to show updated inventory
        header("Location: inventory.php?location=$location&transfer=success");
        exit;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        
        $transfer_error = $e->getMessage();
    }
}

// Check for transfer success message from redirect
$transfer_success = isset($_GET['transfer']) && $_GET['transfer'] === 'success';
?>

<div class="dashboard-stats">
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($materials['total_materials']); ?></div>
        <div class="stat-label">Raw Materials</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($materials['total_stock'], 2); ?></div>
        <div class="stat-label">Raw Stock</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($summary['manufacturing_quantity'] + $summary['wholesale_quantity'] + $summary['transit_quantity']); ?></div>
        <div class="stat-label">Finished Products</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($summary['wholesale_quantity']); ?></div>
        <div class="stat-label">Wholesale Stock</div>
    </div>
</div>

<!-- Success/Error Messages -->
<?php if ($transfer_success): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i>
    <span>Inventory transfer initiated successfully. The shopkeeper will be notified.</span>
    <button type="button" class="close-alert">&times;</button>
</div>
<?php endif; ?>

<?php if ($transfer_error): ?>
<div class="alert alert-error">
    <i class="fas fa-exclamation-circle"></i>
    <span><?php echo $transfer_error; ?></span>
    <button type="button" class="close-alert">&times;</button>
</div>
<?php endif; ?>

<div class="dashboard-grid">
    <div class="dashboard-card full-width">
        <div class="card-header">
            <h2>Inventory Distribution</h2>
        </div>
        <div class="card-content">
            <div class="inventory-distribution">
                <div class="distribution-chart">
                    <canvas id="inventoryChart" height="200"></canvas>
                </div>
                <div class="distribution-stats">
                    <div class="location-stat">
                        <h3>Manufacturing</h3>
                        <div class="stat-number"><?php echo number_format($summary['manufacturing_quantity']); ?></div>
                        <div class="stat-label">items</div>
                    </div>
                    <div class="location-stat">
                        <h3>Transit</h3>
                        <div class="stat-number"><?php echo number_format($summary['transit_quantity']); ?></div>
                        <div class="stat-label">items</div>
                    </div>
                    <div class="location-stat">
                        <h3>Wholesale</h3>
                        <div class="stat-number"><?php echo number_format($summary['wholesale_quantity']); ?></div>
                        <div class="stat-label">items</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- NEW: Product Distribution Table -->
    <div class="dashboard-card full-width">
        <div class="card-header">
            <h2>Product Distribution</h2>
            <div class="card-actions">
                <button type="button" class="button small" id="viewAllProductsBtn">
                    <i class="fas fa-list"></i> View All Products
                </button>
            </div>
        </div>
        <div class="card-content">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Batch #</th>
                            <th>Manufacturing</th>
                            <th>Transit</th>
                            <th>Wholesale</th>
                            <th>Sold</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($product = $product_distribution_stmt->fetch(PDO::FETCH_ASSOC)): 
                            $total_inventory = $product['manufacturing_qty'] + $product['transit_qty'] + $product['wholesale_qty'] + $product['sold_qty'];
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($product['product_name']); ?></td>
                            <td><?php echo htmlspecialchars($product['sku']); ?></td>
                            <td><?php echo $product['batch_number'] ? htmlspecialchars($product['batch_number']) : 'N/A'; ?></td>
                            <td>
                                <span class="quantity-pill manufacturing">
                                    <?php echo number_format($product['manufacturing_qty']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="quantity-pill transit">
                                    <?php echo number_format($product['transit_qty']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="quantity-pill wholesale">
                                    <?php echo number_format($product['wholesale_qty']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="quantity-pill sold">
                                    <?php echo number_format($product['sold_qty']); ?>
                                </span>
                            </td>
                            <td>
                                <strong><?php echo number_format($total_inventory); ?></strong>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        
                        <?php if($product_distribution_stmt->rowCount() === 0): ?>
                        <tr>
                            <td colspan="8" class="no-records">No products found in inventory.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="dashboard-card full-width">
        <div class="card-header">
            <h2>Inventory Items</h2>
            <div class="card-filters">
                <label for="location-filter">Location:</label>
                <select id="location-filter" onchange="window.location.href='?location='+this.value">
                    <option value="all" <?php echo $location === 'all' ? 'selected' : ''; ?>>All Locations</option>
                    <option value="manufacturing" <?php echo $location === 'manufacturing' ? 'selected' : ''; ?>>Manufacturing</option>
                    <option value="transit" <?php echo $location === 'transit' ? 'selected' : ''; ?>>Transit</option>
                    <option value="wholesale" <?php echo $location === 'wholesale' ? 'selected' : ''; ?>>Wholesale</option>
                </select>
            </div>
        </div>
        <div class="card-content">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Quantity</th>
                            <th>Location</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($item = $inventory_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                            <td><?php echo htmlspecialchars($item['sku']); ?></td>
                            <td><?php echo number_format($item['quantity']); ?></td>
                            <td>
                                <span class="location-badge location-<?php echo $item['location']; ?>">
                                    <?php echo ucfirst($item['location']); ?>
                                </span>
                            </td>
                            <td><?php echo date('M j, Y, g:i a', strtotime($item['updated_at'])); ?></td>
                            <td>
                                <?php if ($item['location'] === 'manufacturing'): ?>
                                    <?php if ($item['quantity'] > 0 && !empty($shopkeepers)): ?>
                                        <button type="button" class="button small transfer-btn" 
                                                data-id="<?php echo $item['id']; ?>"
                                                data-product-id="<?php echo $item['product_id']; ?>"
                                                data-product="<?php echo htmlspecialchars($item['product_name']); ?>"
                                                data-quantity="<?php echo $item['quantity']; ?>"
                                                data-location="<?php echo $item['location']; ?>">
                                            <i class="fas fa-exchange-alt"></i> Transfer
                                        </button>
                                    <?php elseif ($item['quantity'] == 0): ?>
                                        <span class="status-badge status-transferred">All Transferred</span>
                                    <?php else: ?>
                                        <span class="status-badge status-pending">No Shopkeepers</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        
                        <?php if($inventory_stmt->rowCount() === 0): ?>
                        <tr>
                            <td colspan="6" class="no-records">No inventory items found.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if($total_pages > 1): ?>
            <div class="pagination">
                <?php 
                // Build pagination query string with location filter
                $pagination_query = $location !== 'all' ? '&location=' . $location : '';
                ?>
                
                <?php if($page > 1): ?>
                    <a href="?page=1<?php echo $pagination_query; ?>" class="pagination-link">&laquo; First</a>
                    <a href="?page=<?php echo $page - 1; ?><?php echo $pagination_query; ?>" class="pagination-link">&laquo; Previous</a>
                <?php endif; ?>
                
                <?php
                // Determine the range of page numbers to display
                $range = 2; // Number of pages to show on either side of the current page
                $start_page = max(1, $page - $range);
                $end_page = min($total_pages, $page + $range);
                
                // Always show first page button
                if($start_page > 1) {
                    echo '<a href="?page=1' . $pagination_query . '" class="pagination-link">1</a>';
                    if($start_page > 2) {
                        echo '<span class="pagination-ellipsis">...</span>';
                    }
                }
                
                // Display the range of pages
                for($i = $start_page; $i <= $end_page; $i++) {
                    if($i == $page) {
                        echo '<span class="pagination-link current">' . $i . '</span>';
                    } else {
                        echo '<a href="?page=' . $i . $pagination_query . '" class="pagination-link">' . $i . '</a>';
                    }
                }
                
                // Always show last page button
                if($end_page < $total_pages) {
                    if($end_page < $total_pages - 1) {
                        echo '<span class="pagination-ellipsis">...</span>';
                    }
                    echo '<a href="?page=' . $total_pages . $pagination_query . '" class="pagination-link">' . $total_pages . '</a>';
                }
                ?>
                
                <?php if($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?><?php echo $pagination_query; ?>" class="pagination-link">Next &raquo;</a>
                    <a href="?page=<?php echo $total_pages; ?><?php echo $pagination_query; ?>" class="pagination-link">Last &raquo;</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="dashboard-card full-width">
        <div class="card-header">
            <h2>Recent Inventory Transfers</h2>
        </div>
        <div class="card-content">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Shopkeeper</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Initiated By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($transfer = $transfers_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($transfer['product_name']); ?></td>
                            <td><?php echo number_format($transfer['quantity']); ?></td>
                            <td>
                                <span class="location-badge location-<?php echo $transfer['from_location']; ?>">
                                    <?php echo ucfirst($transfer['from_location']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="location-badge location-<?php echo $transfer['to_location']; ?>">
                                    <?php echo ucfirst($transfer['to_location']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($transfer['shopkeeper_username']): ?>
                                    <span class="user-badge">
                                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($transfer['shopkeeper_username']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">Not assigned</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('M j, Y', strtotime($transfer['transfer_date'])); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $transfer['status']; ?>">
                                    <?php echo ucfirst($transfer['status']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($transfer['initiated_by_user']); ?></td>
                        </tr>
                        <?php endwhile; ?>
                        
                        <?php if($transfers_stmt->rowCount() === 0): ?>
                        <tr>
                            <td colspan="8" class="no-records">No recent transfers found.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Transfer Inventory Modal -->
<div id="transferModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" id="closeTransferModal">&times;</span>
        <h2>Transfer Inventory to Shopkeeper</h2>
        
        <form id="transferForm" method="post" action="">
            <input type="hidden" name="transfer_inventory" value="1">
            <input type="hidden" id="inventory_id" name="inventory_id" value="">
            <input type="hidden" id="product_id" name="product_id" value="">
            <input type="hidden" id="from_location" name="from_location" value="">
            
            <div class="form-group">
                <label for="product_name">Product:</label>
                <input type="text" id="product_name" readonly>
            </div>
            
            <div class="form-group">
                <label for="available_quantity">Available Quantity:</label>
                <input type="text" id="available_quantity" readonly>
            </div>
            
            <div class="form-group">
                <label for="quantity">Quantity to Transfer:</label>
                <input type="number" id="quantity" name="quantity" min="1" required>
                <div class="form-hint">Cannot exceed available quantity</div>
            </div>
            
            <div class="form-group">
                <label for="shopkeeper_id">Shopkeeper:</label>
                <select id="shopkeeper_id" name="shopkeeper_id" required>
                    <option value="">Select Shopkeeper</option>
                    <?php foreach ($shopkeepers as $shopkeeper): ?>
                    <option value="<?php echo $shopkeeper['id']; ?>"><?php echo htmlspecialchars($shopkeeper['full_name']); ?> (<?php echo htmlspecialchars($shopkeeper['username']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="shipping-info">
                <h3>Transfer Information</h3>
                <p>The inventory will be moved to transit status until the shopkeeper confirms receipt.</p>
                <p>A notification will be sent to the shopkeeper about this transfer.</p>
            </div>
            
            <div class="form-actions">
                <button type="button" class="button secondary" id="cancelTransfer">Cancel</button>
                <button type="submit" class="button primary">Transfer Inventory</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Render inventory distribution chart
    const ctx = document.getElementById('inventoryChart').getContext('2d');
    const inventoryChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Manufacturing', 'Transit', 'Wholesale'],
            datasets: [{
                data: [
                    <?php echo $summary['manufacturing_quantity']; ?>,
                    <?php echo $summary['transit_quantity']; ?>,
                    <?php echo $summary['wholesale_quantity']; ?>
                ],
                backgroundColor: [
                    '#4285f4',
                    '#fbbc04',
                    '#34a853'
                ],
                borderColor: 'white',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = Math.round((value / total) * 100);
                            return `${label}: ${value} items (${percentage}%)`;
                        }
                    }
                }
            },
            cutout: '60%',
            animation: {
                animateScale: true,
                animateRotate: true
            }
        }
    });
    
    // Transfer Modal Functionality
    const transferModal = document.getElementById('transferModal');
    const closeTransferModal = document.getElementById('closeTransferModal');
    const cancelTransfer = document.getElementById('cancelTransfer');
    const transferButtons = document.querySelectorAll('.transfer-btn');
    
    // Form elements
    const inventoryIdInput = document.getElementById('inventory_id');
    const productIdInput = document.getElementById('product_id');
    const fromLocationInput = document.getElementById('from_location');
    const productNameInput = document.getElementById('product_name');
    const availableQuantityInput = document.getElementById('available_quantity');
    const quantityInput = document.getElementById('quantity');
    
    // Open transfer modal
    transferButtons.forEach(button => {
        button.addEventListener('click', function() {
            const inventoryId = this.getAttribute('data-id');
            const productId = this.getAttribute('data-product-id');
            const productName = this.getAttribute('data-product');
            const quantity = this.getAttribute('data-quantity');
            const location = this.getAttribute('data-location');
            
            // Set form values
            inventoryIdInput.value = inventoryId;
            productIdInput.value = productId;
            fromLocationInput.value = location;
            productNameInput.value = productName;
            availableQuantityInput.value = quantity;
            quantityInput.value = '';
            quantityInput.max = quantity;
            
            // Open modal
            transferModal.style.display = 'block';
            // Focus on the quantity input for better user experience
            setTimeout(() => quantityInput.focus(), 100);
        });
    });
    
    // Close modal
    if (closeTransferModal) {
        closeTransferModal.addEventListener('click', function() {
            transferModal.style.display = 'none';
        });
    }
    
        if (cancelTransfer) {
        cancelTransfer.addEventListener('click', function() {
            transferModal.style.display = 'none';
        });
    }
    
    // Close when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target === transferModal) {
            transferModal.style.display = 'none';
        }
    });
    
    // Form validation
    const transferForm = document.getElementById('transferForm');
    if (transferForm) {
        transferForm.addEventListener('submit', function(event) {
            const quantity = parseInt(quantityInput.value);
            const availableQuantity = parseInt(availableQuantityInput.value);
            
            if (isNaN(quantity) || quantity <= 0) {
                event.preventDefault();
                alert('Please enter a valid quantity');
                quantityInput.focus();
                return;
            }
            
            if (quantity > availableQuantity) {
                event.preventDefault();
                alert('Transfer quantity cannot exceed available quantity');
                quantityInput.focus();
                return;
            }
            
            // Show loading state
            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        });
    }
    
    // Quantity input validation
    if (quantityInput) {
        quantityInput.addEventListener('input', function() {
            const quantity = parseInt(this.value);
            const availableQuantity = parseInt(availableQuantityInput.value);
            
            if (quantity > availableQuantity) {
                this.setCustomValidity('Transfer quantity cannot exceed available quantity');
            } else {
                this.setCustomValidity('');
            }
        });
    }
    
    // View All Products button
    const viewAllProductsBtn = document.getElementById('viewAllProductsBtn');
    if (viewAllProductsBtn) {
        viewAllProductsBtn.addEventListener('click', function() {
            // You can implement a view with all products here
            // For now, just alert the user
            alert('Full product distribution view is under development');
        });
    }
    
    // Alert close buttons
    const alertCloseButtons = document.querySelectorAll('.close-alert');
    alertCloseButtons.forEach(button => {
        button.addEventListener('click', function() {
            const alert = this.parentElement;
            // Add fade-out animation
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            // Remove from DOM after animation completes
            setTimeout(() => {
                alert.style.display = 'none';
            }, 300);
        });
    });
    
    // Automatically dismiss success alerts after 5 seconds
    const successAlerts = document.querySelectorAll('.alert-success');
    successAlerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 300);
        }, 5000);
    });
    
    // Make tables responsive with horizontal scroll on small screens
    const makeTablesResponsive = () => {
        const tables = document.querySelectorAll('.data-table');
        tables.forEach(table => {
            const wrapper = table.parentElement;
            if (!wrapper.classList.contains('table-responsive')) {
                const tableWidth = table.offsetWidth;
                const containerWidth = wrapper.offsetWidth;
                
                if (tableWidth > containerWidth) {
                    wrapper.style.overflowX = 'auto';
                }
            }
        });
    };
    
    // Run responsive tables check on load and resize
    makeTablesResponsive();
    window.addEventListener('resize', makeTablesResponsive);
    
    // Log view activity
    if (typeof logUserActivity === 'function') {
        logUserActivity('read', 'inventory', 'Viewed inventory overview');
    }
});
</script>

<style>
:root {
    --primary: #1a73e8;
    --primary-dark: #0d47a1;
    --secondary: #6c757d;
    --success: #34a853;
    --warning: #fbbc04;
    --danger: #ea4335;
    --info: #4285f4;
    --light: #f8f9fa;
    --dark: #343a40;
    --white: #ffffff;
    --border: #e0e0e0;
    --text-primary: #212529;
    --text-secondary: #6c757d;
    --text-muted: #8c98a4;
    --surface: #f8f9fa;
    --border-radius-sm: 4px;
    --border-radius-md: 8px;
    --shadow-sm: 0 2px 4px rgba(0, 0, 0, 0.1);
    --shadow-md: 0 4px 8px rgba(0, 0, 0, 0.1);
    --font-size-xs: 0.75rem;
    --font-size-sm: 0.875rem;
    --font-size-md: 1rem;
    --font-size-lg: 1.25rem;
    --font-size-xl: 1.5rem;
}

/* Inventory specific styles */
.inventory-distribution {
    display: flex;
    flex-wrap: wrap;
    gap: 2rem;
    align-items: center;
    margin-bottom: 1rem;
}

.distribution-chart {
    flex: 1;
    min-width: 300px;
    height: 300px;
    position: relative;
}

.distribution-stats {
    flex: 1;
    min-width: 300px;
    display: flex;
    justify-content: space-around;
    text-align: center;
}

.location-stat {
    padding: 1rem;
    border-radius: var(--border-radius-md);
    background-color: var(--surface);
    min-width: 120px;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.location-stat:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-md);
}

.location-stat h3 {
    margin-top: 0;
    margin-bottom: 0.5rem;
    color: var(--text-secondary);
    font-size: var(--font-size-md);
}

.location-stat .stat-number {
    font-size: var(--font-size-xl);
    font-weight: bold;
    margin-bottom: 0.25rem;
}

.location-stat .stat-label {
    color: var(--text-secondary);
    font-size: var(--font-size-sm);
}

.location-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: var(--font-size-sm);
    font-weight: 500;
}

.location-manufacturing {
    background-color: rgba(66, 133, 244, 0.1);
    color: #4285f4;
}

.location-transit {
    background-color: rgba(251, 188, 4, 0.1);
    color: #b06000;
}

.location-wholesale {
    background-color: rgba(52, 168, 83, 0.1);
    color: #34a853;
}

/* User badge styling */
.user-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    background-color: rgba(26, 115, 232, 0.1);
    color: #1a73e8;
    font-size: var(--font-size-sm);
    font-weight: 500;
}

.card-filters {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.card-filters label {
    font-size: var(--font-size-sm);
    font-weight: 500;
}

.card-filters select {
    padding: 0.25rem 0.5rem;
    border-radius: var(--border-radius-sm);
    border: 1px solid var(--border);
    font-size: var(--font-size-sm);
    background-color: white;
}

/* Status badge styles */
.status-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: var(--font-size-sm);
    font-weight: 500;
    text-align: center;
    min-width: 80px;
}

.status-pending {
    background-color: #fff3cd;
    color: #856404;
}

.status-confirmed {
    background-color: #d4edda;
    color: #155724;
}

.status-cancelled {
    background-color: #f8d7da;
    color: #721c24;
}

.status-transferred {
    background-color: #e8f0fe;
    color: #1967d2;
}

/* NEW: Quantity pill styling */
.quantity-pill {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: var(--font-size-sm);
    font-weight: 500;
    min-width: 40px;
    text-align: center;
}

.quantity-pill.manufacturing {
    background-color: rgba(66, 133, 244, 0.1);
    color: #4285f4;
}

.quantity-pill.transit {
    background-color: rgba(251, 188, 4, 0.1);
    color: #b06000;
}

.quantity-pill.wholesale {
    background-color: rgba(52, 168, 83, 0.1);
    color: #34a853;
}

.quantity-pill.sold {
    background-color: rgba(234, 67, 53, 0.1);
    color: #c5221f;
}

/* Alert styles */
.alert {
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    animation: fadeIn 0.3s ease-out;
    transition: opacity 0.3s ease, transform 0.3s ease;
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

.alert span {
    flex: 1;
}

.close-alert {
    background: none;
    border: none;
    font-size: 1.25rem;
    line-height: 1;
    cursor: pointer;
    color: inherit;
    opacity: 0.7;
}

.close-alert:hover {
    opacity: 1;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Modal styles */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    overflow-y: auto;
    padding: 20px;
}

.modal-content {
    background-color: white;
    margin: 50px auto;
    padding: 2rem;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    max-width: 500px;
    width: 100%;
    position: relative;
    animation: modalFadeIn 0.3s ease-out;
}

@keyframes modalFadeIn {
    from { opacity: 0; transform: translateY(-30px); }
    to { opacity: 1; transform: translateY(0); }
}

.close-modal {
    position: absolute;
    top: 1rem;
    right: 1.5rem;
    font-size: 1.5rem;
    color: #6c757d;
    cursor: pointer;
    transition: color 0.2s;
}

.close-modal:hover {
    color: #343a40;
}

/* Card actions */
.card-actions {
    display: flex;
    gap: 0.5rem;
}

/* Form styles */
.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 1rem;
}

.form-group input:focus,
.form-group select:focus {
    border-color: #4285f4;
    box-shadow: 0 0 0 3px rgba(66, 133, 244, 0.25);
    outline: none;
}

.form-group input[readonly] {
    background-color: #f8f9fa;
    cursor: not-allowed;
}

.form-hint {
    font-size: 0.85rem;
    color: #6c757d;
    margin-top: 0.5rem;
}

/* Shipping info section */
.shipping-info {
    background-color: #f8f9fa;
    border-radius: 8px;
    padding: 1rem;
    margin: 1.5rem 0;
    border-left: 4px solid #6c757d;
}

.shipping-info h3 {
    margin-top: 0;
    font-size: 1rem;
    color: #495057;
}

.shipping-info p {
    margin: 0.5rem 0 0;
    font-size: 0.9rem;
    color: #6c757d;
}

.shipping-info p:last-child {
    margin-bottom: 0;
}

/* Form actions */
.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    margin-top: 2rem;
}

.button {
    padding: 0.5rem 1rem;
    border-radius: 4px;
    font-weight: 500;
    cursor: pointer;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s;
}

.button.primary {
    background-color: #4285f4;
    color: white;
}

.button.primary:hover {
    background-color: #3367d6;
}

.button.secondary {
    background-color: #f8f9fa;
    color: #495057;
    border: 1px solid #ced4da;
}

.button.secondary:hover {
    background-color: #e9ecef;
}

.button.small {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

/* Table responsive */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

/* Text utility classes */
.text-muted {
    color: var(--text-muted);
    font-style: italic;
}

/* No records message */
.no-records {
    text-align: center;
    padding: 2rem;
    color: var(--text-secondary);
    font-style: italic;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .inventory-distribution {
        flex-direction: column;
        gap: 1rem;
    }
    
    .distribution-chart {
        width: 100%;
        max-height: 250px;
    }
    
    .distribution-stats {
        width: 100%;
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .location-stat {
        flex: 1;
        min-width: calc(33% - 1rem);
    }
    
    .modal-content {
        margin: 20px auto;
        padding: 1.5rem;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions .button {
        width: 100%;
    }
    
    .card-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.75rem;
    }
    
    .data-table th, 
    .data-table td {
        padding: 0.5rem;
    }
}

@media (max-width: 576px) {
    .distribution-stats {
        flex-direction: column;
    }
    
    .location-stat {
        width: 100%;
    }
    
    .card-filters {
        width: 100%;
    }
    
    .card-filters select {
        flex: 1;
    }
    
    .button.small {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
    }
    
    .status-badge,
    .location-badge {
        padding: 0.15rem 0.35rem;
        font-size: 0.75rem;
        min-width: 60px;
    }
}

/* Accessibility enhancements */
@media (prefers-reduced-motion: reduce) {
    .alert,
    .modal-content,
    .button,
    .location-stat {
        animation: none !important;
        transition: none !important;
    }
}

/* Focus styles for better keyboard navigation */
.button:focus,
select:focus,
input:focus {
    outline: 2px solid #4285f4;
    outline-offset: 2px;
}

.close-modal:focus,
.close-alert:focus {
    outline: 2px solid #4285f4;
    outline-offset: 2px;
}

/* High contrast mode support */
@media (forced-colors: active) {
    .button,
    .status-badge,
    .location-badge {
        border: 1px solid;
    }
}
</style>

<?php include_once '../includes/footer.php'; ?>