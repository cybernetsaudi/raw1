<?php
session_start();
$page_title = "Shopkeeper Dashboard";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Get inventory summary
    $inventory_query = "SELECT 
        COUNT(DISTINCT product_id) as total_products,
        SUM(quantity) as total_quantity
        FROM inventory
        WHERE location = 'wholesale'";
    $inventory_stmt = $db->prepare($inventory_query);
    $inventory_stmt->execute();
    $inventory = $inventory_stmt->fetch(PDO::FETCH_ASSOC);

    // Get sales summary
    $sales_query = "SELECT 
        COUNT(*) as total_sales,
        SUM(net_amount) as total_amount,
        COUNT(CASE WHEN payment_status = 'unpaid' OR payment_status = 'partial' THEN 1 END) as pending_payments
        FROM sales";
    $sales_stmt = $db->prepare($sales_query);
    $sales_stmt->execute();
    $sales = $sales_stmt->fetch(PDO::FETCH_ASSOC);

    // Get today's sales
    $today = date('Y-m-d');
    $today_sales_query = "SELECT 
        COUNT(*) as count,
        COALESCE(SUM(net_amount), 0) as amount
        FROM sales 
        WHERE DATE(sale_date) = ?";
    $today_sales_stmt = $db->prepare($today_sales_query);
    $today_sales_stmt->bindParam(1, $today);
    $today_sales_stmt->execute();
    $today_sales = $today_sales_stmt->fetch(PDO::FETCH_ASSOC);
    $today_sales_count = $today_sales['count'];
    $today_sales_amount = $today_sales['amount'];

    // Get pending receivables
    $receivables_query = "SELECT s.id, s.invoice_number, c.name as customer_name, s.sale_date, s.net_amount, 
                         (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE sale_id = s.id) as amount_paid,
                         s.payment_due_date
                         FROM sales s 
                         JOIN customers c ON s.customer_id = c.id 
                         WHERE s.payment_status IN ('unpaid', 'partial')
                         ORDER BY s.payment_due_date ASC LIMIT 5";
    $receivables_stmt = $db->prepare($receivables_query);
    $receivables_stmt->execute();

    // Get inventory transfers with enhanced details for notifications
    $from_location = 'manufacturing';
    $pending_location = 'transit';
    $destination_location = 'wholesale';

    $transfers_query = "SELECT t.id, p.name as product_name, p.sku, t.quantity, 
                       t.from_location, t.to_location, t.transfer_date, t.status, 
                       t.initiated_by, t.product_id,
                       u.username as initiated_by_user
                       FROM inventory_transfers t
                       JOIN products p ON t.product_id = p.id
                       JOIN users u ON t.initiated_by = u.id
                       WHERE t.to_location = :pending_location 
                       AND t.status = 'pending'
                       ORDER BY t.transfer_date DESC";

    $transfers_stmt = $db->prepare($transfers_query);
    $transfers_stmt->bindParam(':pending_location', $pending_location);

    try {
        $transfers_stmt->execute();
        $pending_transfers = $transfers_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
            error_log("Found " . count($pending_transfers) . " pending transfers");
        }
    } catch (PDOException $e) {
        error_log("Error fetching pending transfers: " . $e->getMessage());
        $pending_transfers = [];
    }

    // Get recent inventory transfers (all statuses)
    $all_transfers_query = "SELECT t.id, p.name as product_name, p.sku, t.quantity, t.from_location, 
                           t.to_location, t.transfer_date, t.status, t.product_id
                           FROM inventory_transfers t
                           JOIN products p ON t.product_id = p.id
                           WHERE t.to_location = 'wholesale' OR t.from_location = 'wholesale'
                           ORDER BY t.transfer_date DESC LIMIT 5";
    $all_transfers_stmt = $db->prepare($all_transfers_query);
    $all_transfers_stmt->execute();
    $recent_transfers = $all_transfers_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get recent sales
    $recent_sales_query = "SELECT s.id, s.invoice_number, c.name as customer_name, 
                          s.sale_date, s.net_amount, s.payment_status
                          FROM sales s
                          JOIN customers c ON s.customer_id = c.id
                          ORDER BY s.sale_date DESC LIMIT 5";
    $recent_sales_stmt = $db->prepare($recent_sales_query);
    $recent_sales_stmt->execute();

    // Get notifications for this shopkeeper
    $notifications_query = "SELECT n.id, n.type, n.message, n.related_id, n.is_read, n.created_at,
                           t.product_id, t.quantity, t.from_location, p.name as product_name, p.sku
                           FROM notifications n
                           LEFT JOIN inventory_transfers t ON n.related_id = t.id AND n.type = 'inventory_transfer'
                           LEFT JOIN products p ON t.product_id = p.id
                           WHERE n.user_id = ? AND n.is_read = 0
                           ORDER BY n.created_at DESC";
    $notifications_stmt = $db->prepare($notifications_query);
    $notifications_stmt->execute([$_SESSION['user_id']]);
    $notifications = $notifications_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get low stock items
    $low_stock_query = "SELECT p.name, i.quantity, p.sku
                       FROM inventory i
                       JOIN products p ON i.product_id = p.id
                       WHERE i.location = 'wholesale' AND i.quantity < 10
                       ORDER BY i.quantity ASC
                       LIMIT 5";
    $low_stock_stmt = $db->prepare($low_stock_query);
    $low_stock_stmt->execute();
    $low_stock_items = $low_stock_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* General Styles */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f6fa;
            color: #333;
            margin: 0;
            padding: 0;
        }

        h2 {
            color: #2c3e50;
            margin-bottom: 1.5rem;
        }

        /* Quick Actions Bar */
        .dashboard-quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .action-button {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 1rem;
            background-color: #1a73e8;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.2s;
            text-decoration: none;
            text-align: center;
        }

        .action-button:hover {
            background-color: #1565c0;
            transform: translateY(-2px);
        }

        .action-button:active {
            transform: translateY(0);
        }

        /* Key Metrics */
        .dashboard-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .metric-card {
            background-color: white;
            border-radius: 8px;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .metric-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .sales-icon {
            background-color: #4285f4;
        }

        .inventory-icon {
            background-color: #34a853;
        }

        .payments-icon {
            background-color: #fbbc04;
        }

        .alert-icon {
            background-color: #ea4335;
        }

        .metric-content {
            flex: 1;
        }

        .metric-content h3 {
            margin: 0;
            font-size: 0.875rem;
            color: #6c757d;
        }

        .metric-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #212529;
            margin: 0.25rem 0;
        }

        .metric-label {
            font-size: 0.875rem;
            color: #6c757d;
        }

        /* Pending Transfers Section */
        .pending-transfers {
            margin-bottom: 2rem;
        }

        .pending-transfers h2 {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
            color: #212529;
            font-size: 1.25rem;
        }

        .pending-transfers h2 i {
            margin-right: 0.75rem;
            color: #1a73e8;
        }

        .notification-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background-color: #1a73e8;
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
            height: 20px;
            min-width: 20px;
            padding: 0 6px;
            border-radius: 10px;
            margin-left: 0.75rem;
        }

        .transfers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            animation: fadeIn 0.5s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

/* Fix card flipping issues */
.transfer-wrapper {
    perspective: 1000px;
    height: 220px;
    cursor: pointer;
    position: relative;
}

.transfer-card {
    position: relative;
    width: 100%;
    height: 100%;
    transition: transform 0.6s;
    transform-style: preserve-3d;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    border-radius: 15px;
    will-change: transform;
}

.card-front, 
.card-back {
    position: absolute;
    width: 100%;
    height: 100%;
    -webkit-backface-visibility: hidden;
    backface-visibility: hidden;
    border-radius: 15px;
    padding: 20px;
    background: white;
    display: flex;
    flex-direction: column;
}

.card-front {
    z-index: 2;
}

.card-back {
    transform: rotateY(180deg);
    display: flex;
    align-items: center;
    justify-content: center;
}

.transfer-wrapper.flipped .transfer-card {
    transform: rotateY(180deg);
}

/* Fix action button z-index */
.action-buttons {
    display: flex;
    flex-direction: column;
    gap: 15px;
    width: 80%;
    z-index: 3;
}

/* Add a visual cue that cards are clickable */
.transfer-wrapper::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 30px;
    height: 30px;
    background: rgba(26, 115, 232, 0.1);
    border-radius: 0 15px 0 15px;
    z-index: 4;
    transition: all 0.3s ease;
}

.transfer-wrapper:hover::after {
    background: rgba(26, 115, 232, 0.2);
}

/* Ensure buttons are clickable */
.confirm-btn, 
.details-btn {
    position: relative;
    z-index: 5;
    cursor: pointer;
}

        .transfer-wrapper.flipped .transfer-card {
            transform: rotateY(180deg);
        }

        .transfer-status {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 12px;
            z-index: 3;
        }

        .transfer-product {
            font-weight: 600;
            font-size: 1.2rem;
            margin: 30px 0 15px;
            color: #212529;
            text-align: center;
        }

        .product-sku {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 5px;
            font-weight: normal;
            text-align: center;
        }

        .transfer-quantity {
            font-size: 1.5rem;
            font-weight: bold;
            margin: 10px 0;
            text-align: center;
        }

        .transfer-date,
        .transfer-from {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #6c757d;
            font-size: 0.9rem;
            margin: 8px 0;
        }

        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 15px;
            width: 80%;
        }

        .confirm-btn, 
        .details-btn {
            padding: 12px 20px;
            border-radius: 25px;
            border: none;
            font-size: 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .confirm-btn {
            background: linear-gradient(135deg, #34a853 0%, #4caf50 100%);
            color: white;
        }

        .details-btn {
            background: transparent;
            color: #212529;
            border: 2px solid #dee2e6;
        }

        .confirm-btn:hover,
        .details-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .card-hint {
            position: absolute;
            bottom: 10px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 0.8rem;
            color: #6c757d;
            opacity: 0.7;
        }

        .transfer-wrapper:hover .card-hint {
            opacity: 1;
        }

        /* Confirmation Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s;
        }

        .modal-content {
            background-color: #fff;
            margin: 10% auto;
            max-width: 500px;
            width: 90%;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            animation: slideIn 0.3s;
        }

        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.25rem;
            color: #212529;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            line-height: 1;
            color: #6c757d;
            cursor: pointer;
            padding: 0;
            transition: color 0.2s;
        }

        .close-modal:hover {
            color: #212529;
        }

        .confirmation-content {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .confirmation-icon {
            width: 70px;
            height: 70px;
            background-color: rgba(52, 168, 83, 0.1);
            color: #34a853;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1rem;
        }

        .confirmation-content ul {
            text-align: left;
            margin: 1rem 0;
            padding-left: 1.5rem;
        }

        .confirmation-content li {
            margin-bottom: 0.5rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            font-size: 1rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-group textarea:focus {
            border-color: #1a73e8;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.25);
            outline: none;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        /* Animation styles */
        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Status badge styles */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            text-align: center;
        }

        .status-unpaid {
            background-color: rgba(234, 67, 53, 0.1);
            color: #ea4335;
        }

        .status-partial {
            background-color: rgba(251, 188, 4, 0.1);
            color: #fbbc04;
        }

        .status-paid {
            background-color: rgba(52, 168, 83, 0.1);
            color: #34a853;
        }

        .status-pending {
            background-color: rgba(251, 188, 4, 0.1);
            color: #fbbc04;
        }

        .status-confirmed {
            background-color: rgba(52, 168, 83, 0.1);
            color: #34a853;
        }

        .status-cancelled {
            background-color: rgba(234, 67, 53, 0.1);
            color: #ea4335;
        }

        .status-upcoming {
            background-color: rgba(66, 133, 244, 0.1);
            color: #4285f4;
        }

        .status-overdue {
            background-color: rgba(234, 67, 53, 0.1);
            color: #ea4335;
        }

        .status-low-stock {
            background-color: rgba(251, 188, 4, 0.1);
            color: #fbbc04;
        }

        .status-out-of-stock {
            background-color: rgba(234, 67, 53, 0.1);
            color: #ea4335;
        }

        /* Error message styling */
        .error-message {
            color: #ea4335;
            background-color: rgba(234, 67, 53, 0.1);
            border: 1px solid rgba(234, 67, 53, 0.2);
            border-radius: 4px;
            padding: 0.75rem;
            margin: 0.75rem 0;
            font-size: 0.9rem;
            display: none;
        }

        /* Processing state */
        .transfer-wrapper.processing {
            pointer-events: none;
            opacity: 0.7;
        }

        /* Shake animation for error feedback */
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }

        .shake-animation {
            animation: shake 0.5s ease-in-out;
        }

        /* Toast notification styles */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 350px;
        }

        .toast {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: toast-in 0.3s ease-out forwards;
            opacity: 0;
            transform: translateX(50px);
        }

        .toast-hiding {
            animation: toast-out 0.3s ease-in forwards;
        }

        .toast-icon {
            flex-shrink: 0;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background-color: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .toast-content {
            flex: 1;
            font-size: 14px;
        }

        .toast-close {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            color: #6c757d;
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        .toast-close:hover {
            opacity: 1;
        }

        .toast-info {
            border-left: 4px solid #1a73e8;
        }

        .toast-info .toast-icon {
            color: #1a73e8;
        }

        .toast-success {
            border-left: 4px solid #34a853;
        }

        .toast-success .toast-icon {
            color: #34a853;
        }

        .toast-warning {
            border-left: 4px solid #fbbc04;
        }

        .toast-warning .toast-icon {
            color: #fbbc04;
        }

        .toast-error {
            border-left: 4px solid #ea4335;
        }

        .toast-error .toast-icon {
            color: #ea4335;
        }

        @keyframes toast-in {
            from { opacity: 0; transform: translateX(50px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @keyframes toast-out {
            from { opacity: 1; transform: translateX(0); }
            to { opacity: 0; transform: translateX(50px); }
        }

        /* Responsive adjustments */
        @media (max-width: 992px) {
            .transfers-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .dashboard-quick-actions,
            .dashboard-metrics {
                grid-template-columns: 1fr;
            }
            
            .transfers-grid {
                grid-template-columns: 1fr;
            }
            
            .transfer-wrapper {
                height: 200px;
            }
            
            .modal-content {
                margin: 20% auto;
                width: 95%;
            }
            
            .form-actions {
                flex-direction: column-reverse;
                gap: 0.5rem;
            }
            
            .form-actions button {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .toast-container {
                top: auto;
                bottom: 20px;
                left: 20px;
                right: 20px;
                max-width: none;
            }
        }

        /* Accessibility improvements */
        @media (prefers-reduced-motion: reduce) {
            .alert,
            .metric-card:hover,
            .modal,
            .modal-content,
            .toast,
            .toast-hiding {
                animation: none;
                transition: none;
            }
            
            .metric-card:hover {
                transform: none;
            }
        }

        /* Animation for notification count update */
        @keyframes countPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.3); }
            100% { transform: scale(1); }
        }

        .count-updated {
            animation: countPulse 0.5s ease-in-out;
        }

        /* Screen reader only class */
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
    
    
    /* Add button loading state styles */
.action-button.loading {
    position: relative;
    color: transparent !important;
    pointer-events: none;
}

.action-button.loading::after {
    content: "";
    position: absolute;
    width: 16px;
    height: 16px;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    margin: auto;
    border: 3px solid transparent;
    border-top-color: currentColor;
    border-radius: 50%;
    animation: button-loading-spinner 0.6s linear infinite;
}

@keyframes button-loading-spinner {
    from {
        transform: rotate(0turn);
    }
    to {
        transform: rotate(1turn);
    }
}

/* Add removal animation for tiles */
.transfer-tile.removing {
    animation: remove-tile 0.5s ease forwards;
}

@keyframes remove-tile {
    0% {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
    100% {
        opacity: 0;
        transform: scale(0.8) translateY(-20px);
    }
}
    /* Replace card flip styles with these tile styles */
.transfers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.transfer-tile {
    background-color: white;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    padding: 20px;
    position: relative;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.transfer-tile:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
}

.transfer-status-badge {
    position: absolute;
    top: 15px;
    right: 15px;
    font-size: 0.75rem;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 12px;
}

.transfer-status-badge.pending {
    background-color: rgba(251, 188, 4, 0.1);
    color: #fbbc04;
}

.transfer-header {
    margin-top: 10px;
    text-align: center;
}

.transfer-product-name {
    font-size: 1.2rem;
    font-weight: 600;
    color: #212529;
    margin: 0 0 5px 0;
}

.product-sku {
    font-size: 0.85rem;
    color: #6c757d;
    font-weight: normal;
}

.transfer-details {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 10px 0;
}

.detail-item {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 0.95rem;
}

.detail-item i {
    color: #6c757d;
    width: 20px;
    text-align: center;
}

.transfer-actions {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: auto;
}

.action-button {
    padding: 10px;
    border-radius: 8px;
    border: none;
    font-size: 0.95rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s ease;
}

.btn-confirm {
    background-color: #34a853;
    color: white;
}

.btn-confirm:hover {
    background-color: #2d9249;
}

.btn-details {
    background-color: #f1f3f5;
    color: #495057;
}

.btn-details:hover {
    background-color: #e9ecef;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .transfers-grid {
        grid-template-columns: 1fr;
    }
}
    </style>
</head>
<body>
    <!-- Quick Actions Bar -->
    <div class="dashboard-quick-actions">
        <a href="add-sale.php" class="action-button">
            <i class="fas fa-plus"></i>
            New Sale
        </a>
        <a href="customers.php" class="action-button">
            <i class="fas fa-user"></i>
            Manage Customers
        </a>
        <a href="inventory.php" class="action-button">
            <i class="fas fa-boxes"></i>
            View Inventory
        </a>
        <a href="payments.php" class="action-button">
            <i class="fas fa-money-bill"></i>
            Manage Payments
        </a>
    </div>

    <!-- Key Metrics -->
    <div class="dashboard-metrics">
        <div class="metric-card">
            <div class="metric-icon sales-icon">
                <i class="fas fa-shopping-cart"></i>
            </div>
            <div class="metric-content">
                <h3>Today's Sales</h3>
                <div class="metric-value"><?php echo $today_sales_count; ?></div>
                <div class="metric-label">Total: $<?php echo number_format($today_sales_amount, 2); ?></div>
            </div>
        </div>
        
        <div class="metric-card">
            <div class="metric-icon inventory-icon">
                <i class="fas fa-box"></i>
            </div>
            <div class="metric-content">
                <h3>Inventory Items</h3>
                <div class="metric-value"><?php echo number_format($inventory['total_products']); ?></div>
                <div class="metric-label">Quantity: <?php echo number_format($inventory['total_quantity']); ?></div>
            </div>
        </div>
        
        <div class="metric-card">
            <div class="metric-icon payments-icon">
                <i class="fas fa-hand-holding-usd"></i>
            </div>
            <div class="metric-content">
                <h3>Pending Payments</h3>
                <div class="metric-value"><?php echo number_format($sales['pending_payments']); ?></div>
                <div class="metric-label">From <?php echo number_format($sales['total_sales']); ?> sales</div>
            </div>
        </div>
        
        <div class="metric-card">
            <div class="metric-icon alert-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="metric-content">
                <h3>Low Stock Items</h3>
                <div class="metric-value"><?php echo count($low_stock_items); ?></div>
                <div class="metric-label">Need attention</div>
            </div>
        </div>
    </div>

    <!-- Pending Inventory Transfers Section -->
    <?php if (!empty($pending_transfers)): ?>
<!-- Replace the existing transfer card HTML -->
<div class="transfers-grid">
    <?php foreach($pending_transfers as $transfer): ?>
    <div class="transfer-tile" data-transfer-id="<?php echo $transfer['id']; ?>">
        <div class="transfer-status-badge pending">Pending Receipt</div>
        
        <div class="transfer-header">
            <h3 class="transfer-product-name"><?php echo htmlspecialchars($transfer['product_name']); ?></h3>
            <div class="product-sku">SKU: <?php echo htmlspecialchars($transfer['sku']); ?></div>
        </div>
        
        <div class="transfer-details">
            <div class="detail-item">
                <i class="fas fa-cubes"></i>
                <span><?php echo number_format($transfer['quantity']); ?> units</span>
            </div>
            <div class="detail-item">
                <i class="fas fa-calendar-alt"></i>
                <span><?php echo date('M j, Y', strtotime($transfer['transfer_date'])); ?></span>
            </div>
            <div class="detail-item">
                <i class="fas fa-warehouse"></i>
                <span>From: <?php echo ucfirst($transfer['from_location']); ?></span>
            </div>
        </div>
        
        <div class="transfer-actions">
                <button type="button" 
            class="btn-confirm action-button" 
            data-transfer-id="<?php echo $transfer['id']; ?>"
            data-product-id="<?php echo $transfer['product_id']; ?>"
            data-quantity="<?php echo $transfer['quantity']; ?>">
        <i class="fas fa-check-circle"></i> Confirm Receipt
    </button>
    <button type="button" 
            class="btn-details action-button"
            data-transfer-id="<?php echo $transfer['id']; ?>">
        <i class="fas fa-info-circle"></i> View Details
    </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>    <?php endif; ?>

    <!-- Low Stock Alert Section -->
    <?php if(count($low_stock_items) > 0): ?>
    <div class="dashboard-card alert-card">
        <div class="card-header">
            <h2><i class="fas fa-exclamation-triangle"></i> Low Stock Alert</h2>
        </div>
        <div class="card-content">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Current Stock</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($low_stock_items as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                        <td><?php echo htmlspecialchars($item['sku']); ?></td>
                        <td><?php echo number_format($item['quantity']); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo $item['quantity'] <= 0 ? 'out-of-stock' : 'low-stock'; ?>">
                                <?php echo $item['quantity'] <= 0 ? 'Out of Stock' : 'Low Stock'; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="card-footer">
                <a href="inventory.php" class="button secondary">View All Inventory</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent Sales Section -->
    <div class="dashboard-grid">
        <div class="dashboard-card">
            <div class="card-header">
                <h2>Recent Sales</h2>
                <a href="sales.php" class="view-all">View All</a>
            </div>
            <div class="card-content">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Customer</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($recent_sales_stmt->rowCount() > 0): ?>
                            <?php while($sale = $recent_sales_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                            <tr>
                                <td><a href="view-sale.php?id=<?php echo $sale['id']; ?>"><?php echo htmlspecialchars($sale['invoice_number']); ?></a></td>
                                <td><?php echo htmlspecialchars($sale['customer_name']); ?></td>
                                <td><?php echo htmlspecialchars($sale['sale_date']); ?></td>
                                <td><?php echo number_format($sale['net_amount'], 2); ?></td>
                                <td><span class="status-badge status-<?php echo strtolower($sale['payment_status']); ?>"><?php echo ucfirst($sale['payment_status']); ?></span></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="no-records">No recent sales found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Pending Receivables Section -->
        <div class="dashboard-card">
            <div class="card-header">
                <h2>Pending Receivables</h2>
                <a href="payments.php" class="view-all">View All</a>
            </div>
            <div class="card-content">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Customer</th>
                            <th>Amount Due</th>
                            <th>Due Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($receivables_stmt->rowCount() > 0): ?>
                            <?php while($receivable = $receivables_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                            <tr>
                                <td><a href="view-sale.php?id=<?php echo $receivable['id']; ?>"><?php echo htmlspecialchars($receivable['invoice_number']); ?></a></td>
                                <td><?php echo htmlspecialchars($receivable['customer_name']); ?></td>
                                <td><?php echo number_format($receivable['net_amount'] - $receivable['amount_paid'], 2); ?></td>
                                <td><?php echo htmlspecialchars($receivable['payment_due_date']); ?></td>
                                <td>
                                    <?php 
                                    $today = new DateTime();
                                    $due_date = new DateTime($receivable['payment_due_date']);
                                    $status = 'upcoming';
                                    if($today > $due_date) {
                                        $status = 'overdue';
                                    }
                                    ?>
                                    <span class="status-badge status-<?php echo $status; ?>">
                                        <?php echo $status === 'overdue' ? 'Overdue' : 'Upcoming'; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="no-records">No pending receivables found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Recent Inventory Transfers Section -->
        <div class="dashboard-card full-width">
            <div class="card-header">
                <h2>Recent Inventory Transfers</h2>
                <a href="inventory.php" class="view-all">View All</a>
            </div>
            <div class="card-content">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(!empty($recent_transfers)): ?>
                            <?php foreach($recent_transfers as $transfer): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($transfer['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($transfer['quantity']); ?></td>
                                <td><?php echo ucfirst(htmlspecialchars($transfer['from_location'])); ?></td>
                                <td><?php echo ucfirst(htmlspecialchars($transfer['to_location'])); ?></td>
                                <td><?php echo date('M j, Y', strtotime($transfer['transfer_date'])); ?></td>
                                <td><span class="status-badge status-<?php echo strtolower($transfer['status']); ?>"><?php echo ucfirst($transfer['status']); ?></span></td>
                                <td>
                                    <?php if($transfer['status'] == 'pending' && $transfer['to_location'] == 'wholesale'): ?>
                                    <button class="button small confirm-receipt-btn" 
                                            data-transfer-id="<?php echo $transfer['id']; ?>"
                                            data-product-id="<?php echo $transfer['product_id']; ?>"
                                            data-quantity="<?php echo $transfer['quantity']; ?>">
                                        Confirm
                                    </button>
                                    <?php else: ?>
                                    <span>-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="no-records">No recent inventory transfers found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Confirm Receipt Modal -->
    <div id="confirmReceiptModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Confirm Inventory Receipt</h2>
                <button class="close-modal" id="closeConfirmModal">&times;</button>
            </div>
            
            <div class="modal-body">
                <div class="confirmation-content">
                    <div class="confirmation-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <p>You are confirming receipt of inventory shipment. This will:</p>
                    <ul>
                        <li>Add the items to your shop inventory</li>
                        <li>Mark the transfer as completed</li>
                        <li>Remove this notification</li>
                    </ul>
                    <p>Please verify that you have physically received these items before confirming.</p>
                </div>
                
                <form id="confirmReceiptForm" method="post" action="../api/confirm-receipt.php">
                    <input type="hidden" id="transfer_id" name="transfer_id" value="">
                    <input type="hidden" id="product_id" name="product_id" value="">
                    <input type="hidden" id="quantity" name="quantity" value="">
                    <input type="hidden" id="notification_id" name="notification_id" value="">
                    
                    <div class="form-group">
                        <label for="notes">Notes (Optional):</label>
                        <textarea id="notes" name="notes" rows="2" placeholder="Any notes about the received shipment..."></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="button secondary" id="cancelConfirm">Cancel</button>
                        <button type="submit" class="button primary" id="submitConfirmBtn">
                            <i class="fas fa-check-circle"></i> Confirm Receipt
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Hidden user ID for JS activity logging -->
    <input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

    <script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing inventory management functionality');
    
    // Cache DOM elements
    const confirmReceiptModal = document.getElementById('confirmReceiptModal');
    const closeConfirmModal = document.getElementById('closeConfirmModal');
    const cancelConfirm = document.getElementById('cancelConfirm');
    const confirmReceiptForm = document.getElementById('confirmReceiptForm');
    const transferIdInput = document.getElementById('transfer_id');
    const productIdInput = document.getElementById('product_id');
    const quantityInput = document.getElementById('quantity');
    const notificationIdInput = document.getElementById('notification_id');
    
    // Select action buttons using data attributes (for tile-based design)
    const confirmButtons = document.querySelectorAll('.btn-confirm');
    const detailButtons = document.querySelectorAll('.btn-details');
    
    // Debug output
    console.log('Found confirm buttons:', confirmButtons.length);
    console.log('Found detail buttons:', detailButtons.length);
    console.log('Modal exists:', !!confirmReceiptModal);
    
    // ===== Event Listeners =====
    
    // Attach click handlers to confirm buttons
    confirmButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const transferId = this.getAttribute('data-transfer-id');
            const productId = this.getAttribute('data-product-id');
            const quantity = this.getAttribute('data-quantity');
            
            console.log('Confirm button clicked:', transferId, productId, quantity);
            confirmReceipt(transferId, productId, quantity);
        });
    });
    
    // Attach click handlers to detail buttons
    detailButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const transferId = this.getAttribute('data-transfer-id');
            
            console.log('Details button clicked:', transferId);
            viewTransferDetails(transferId);
        });
    });
    
    // Close alert buttons
    const closeAlertButtons = document.querySelectorAll('.close-alert');
    closeAlertButtons.forEach(button => {
        button.addEventListener('click', function() {
            const alert = this.closest('.alert');
            alert.style.opacity = '0';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 300);
        });
    });
    
    // Modal close events
    if (closeConfirmModal) {
        closeConfirmModal.addEventListener('click', closeConfirmModalHandler);
    }
    
    if (cancelConfirm) {
        cancelConfirm.addEventListener('click', closeConfirmModalHandler);
    }
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target === confirmReceiptModal) {
            closeConfirmModalHandler();
        }
    });
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && confirmReceiptModal && 
            confirmReceiptModal.style.display === 'block') {
            closeConfirmModalHandler();
        }
    });
    
    // Form submission with error handling
    if (confirmReceiptForm) {
        confirmReceiptForm.addEventListener('submit', handleFormSubmission);
    }
    
    // ===== Functions =====
    
    /**
     * Opens the confirmation modal for inventory receipt
     * @param {string} transferId - The ID of the transfer
     * @param {string} productId - The ID of the product
     * @param {string} quantity - The quantity being transferred
     * @param {string} notificationId - Optional notification ID
     */
    window.confirmReceipt = function(transferId, productId, quantity, notificationId = '') {
        if (!confirmReceiptModal) {
            console.error('Error: Confirmation modal not found in the DOM');
            showToast('System error: Could not open confirmation dialog', 'error');
            return;
        }
        
        // Set form values
        if (transferIdInput) transferIdInput.value = transferId;
        if (productIdInput) productIdInput.value = productId;
        if (quantityInput) quantityInput.value = quantity;
        if (notificationIdInput) notificationIdInput.value = notificationId || '';
        
        // Show modal
        confirmReceiptModal.style.display = 'block';
        document.body.style.overflow = 'hidden';
        
        // Focus first interactive element for accessibility
        setTimeout(() => {
            const firstFocusable = confirmReceiptModal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            if (firstFocusable) {
                firstFocusable.focus();
            }
        }, 100);
        
        // Announce to screen readers
        announceToScreenReader('Confirmation dialog opened');
    };
    
    /**
     * Navigates to the transfer details page
     * @param {string} transferId - The ID of the transfer to view
     */
    window.viewTransferDetails = function(transferId) {
        console.log('Viewing details for transfer:', transferId);
        window.location.href = `view-transfer.php?id=${transferId}`;
    };
    
    /**
     * Closes the confirmation modal
     */
    function closeConfirmModalHandler() {
        if (!confirmReceiptModal) return;
        
        confirmReceiptModal.style.display = 'none';
        document.body.style.overflow = '';
        
        // Announce to screen readers
        announceToScreenReader('Dialog closed');
    }
    
    /**
     * Handles form submission for confirming inventory receipt
     * @param {Event} event - The form submission event
     */
    function handleFormSubmission(event) {
        event.preventDefault();
        
        // Clear previous error messages
        const errorMessage = document.querySelector('.error-message');
        if (errorMessage) {
            errorMessage.style.display = 'none';
        }
        
        // Show loading state
        const submitButton = document.getElementById('submitConfirmBtn');
        if (!submitButton) {
            console.error('Submit button not found');
            return;
        }
        
        setButtonLoading(submitButton, true);
        
        // Submit form via fetch API
        fetch(this.action, {
            method: 'POST',
            body: new FormData(this),
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(data => {
                    throw new Error(data.message || `Server error: ${response.status}`);
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Log the activity
                logUserActivity(
                    'update', 
                    'inventory', 
                    `Confirmed receipt of inventory transfer #${transferIdInput.value}`
                );
                
                // Close modal
                closeConfirmModalHandler();
                
                // Show success toast
                showToast(`Successfully added ${data.quantity} units to inventory`, 'success');
                
                // Update UI to reflect the change
                updateTransferUI(transferIdInput.value);
                
                // Reload page after a short delay to update all data
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            } else {
                throw new Error(data.message || 'Failed to process the transfer');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            
            // Show error toast
            showToast(error.message || 'An error occurred', 'error');
            
            // Display error message in form
            displayErrorInForm(confirmReceiptForm, error.message);
        })
        .finally(() => {
            // Reset button state
            setButtonLoading(submitButton, false);
        });
    }
    
    /**
     * Updates the UI after a successful transfer confirmation
     * @param {string} transferId - The ID of the confirmed transfer
     */
    window.updateTransferUI = function(transferId) {
        // First try to find the tile in the new design
        const transferTile = document.querySelector(`.transfer-tile[data-transfer-id="${transferId}"]`);
        if (transferTile) {
            // Add removing animation
            transferTile.classList.add('removing');
            
            setTimeout(() => {
                transferTile.remove();
                updateNotificationCount();
            }, 500);
        }
        
        // Also update any table rows if present (for backward compatibility)
        const tableRow = document.querySelector(`tr .confirm-receipt-btn[data-transfer-id="${transferId}"]`);
        if (tableRow) {
            const row = tableRow.closest('tr');
            const statusCell = row.querySelector('td:nth-child(6)');
            
            if (statusCell) {
                const badge = statusCell.querySelector('.status-badge');
                if (badge) {
                    badge.className = 'status-badge status-confirmed';
                    badge.textContent = 'Confirmed';
                }
            }
            
            const actionCell = tableRow.closest('td');
            if (actionCell) {
                actionCell.innerHTML = '<span class="status-badge status-confirmed"><i class="fas fa-check-circle"></i> Confirmed</span>';
            }
        }
    };
    
    /**
     * Updates the notification count in the header
     */
    function updateNotificationCount() {
        const countElement = document.querySelector('.notification-count');
        if (!countElement) return;
        
        // Count remaining tiles in the new design
        const remainingTransfers = document.querySelectorAll('.transfer-tile').length;
        
        if (remainingTransfers === 0) {
            const transfersSection = document.querySelector('.pending-transfers');
            if (transfersSection) {
                transfersSection.style.transition = 'all 0.5s ease';
                transfersSection.style.opacity = '0';
                transfersSection.style.height = '0';
                
                setTimeout(() => {
                    transfersSection.remove();
                }, 500);
            }
        } else {
            countElement.textContent = remainingTransfers;
            countElement.classList.add('count-updated');
            setTimeout(() => {
                countElement.classList.remove('count-updated');
            }, 1000);
        }
    }
    
    /**
     * Sets a button to loading state
     * @param {HTMLElement} button - The button element
     * @param {boolean} isLoading - Whether the button is in loading state
     */
    function setButtonLoading(button, isLoading) {
        if (isLoading) {
            // Store original text
            button.dataset.originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            button.disabled = true;
            button.classList.add('loading');
        } else {
            // Restore original text if available
            if (button.dataset.originalText) {
                button.innerHTML = button.dataset.originalText;
            }
            button.disabled = false;
            button.classList.remove('loading');
        }
    }
    
    /**
     * Displays an error message in a form
     * @param {HTMLElement} form - The form element
     * @param {string} message - The error message to display
     */
    function displayErrorInForm(form, message) {
        let errorDiv = form.querySelector('.error-message');
        
        if (!errorDiv) {
            errorDiv = document.createElement('div');
            errorDiv.className = 'error-message';
            const formActions = form.querySelector('.form-actions');
            if (formActions) {
                formActions.insertAdjacentElement('beforebegin', errorDiv);
            } else {
                form.appendChild(errorDiv);
            }
        }
        
        errorDiv.textContent = message || 'An error occurred. Please try again.';
        errorDiv.style.display = 'block';
        
        // Add shake animation to form for visual feedback
        form.classList.add('shake-animation');
        setTimeout(() => {
            form.classList.remove('shake-animation');
        }, 500);
    }
    
    /**
     * Shows a toast notification
     * @param {string} message - The message to display
     * @param {string} type - The type of toast (info, success, warning, error)
     */
    window.showToast = function(message, type = 'info') {
        let toastContainer = document.querySelector('.toast-container');
        
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'toast-container';
            document.body.appendChild(toastContainer);
        }
        
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'polite');
        
        let icon = 'info-circle';
        if (type === 'success') icon = 'check-circle';
        if (type === 'error') icon = 'exclamation-circle';
        if (type === 'warning') icon = 'exclamation-triangle';
        
        toast.innerHTML = `
            <div class="toast-icon"><i class="fas fa-${icon}" aria-hidden="true"></i></div>
            <div class="toast-content">${message}</div>
            <button class="toast-close" aria-label="Close notification"><i class="fas fa-times" aria-hidden="true"></i></button>
        `;
        
        toastContainer.appendChild(toast);
        
        // Add event listener to close button
        toast.querySelector('.toast-close').addEventListener('click', function() {
            toast.classList.add('toast-hiding');
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.remove();
                    
                    if (toastContainer.children.length === 0) {
                        toastContainer.remove();
                    }
                }
            }, 300);
        });
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            if (toast.parentNode) {
                toast.classList.add('toast-hiding');
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.remove();
                        
                        if (toastContainer.children.length === 0) {
                            toastContainer.remove();
                        }
                    }
                }, 300);
            }
        }, 5000);
    };
    
    /**
     * Announces messages to screen readers
     * @param {string} message - The message to announce
     */
    function announceToScreenReader(message) {
        let announcer = document.getElementById('sr-announcer');
        
        if (!announcer) {
            announcer = document.createElement('div');
            announcer.id = 'sr-announcer';
            announcer.className = 'sr-only';
            announcer.setAttribute('aria-live', 'polite');
            announcer.setAttribute('aria-atomic', 'true');
            document.body.appendChild(announcer);
        }
        
        announcer.textContent = message;
        
        setTimeout(() => {
            announcer.textContent = '';
        }, 3000);
    }
});

/**
 * Logs user activity to the server
 * @param {string} actionType - The type of action
 * @param {string} module - The module where the action occurred
 * @param {string} description - Description of the action
 */
function logUserActivity(actionType, module, description) {
    const userId = document.getElementById('current-user-id')?.value;
    
    if (!userId) {
        console.warn('User ID not found, cannot log activity');
        return;
    }
    
    fetch('../api/log-activity.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            user_id: userId,
            action_type: actionType,
            module: module,
            description: description
        })
    })
    .catch(error => {
        console.error('Error logging activity:', error);
    });
}
    // Helper function to format time ago
    function timeAgo(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const seconds = Math.floor((now - date) / 1000);
        
        let interval = Math.floor(seconds / 31536000);
        if (interval >= 1) {
            return interval + " year" + (interval === 1 ? "" : "s") + " ago";
        }
        
        interval = Math.floor(seconds / 2592000);
        if (interval >= 1) {
            return interval + " month" + (interval === 1 ? "" : "s") + " ago";
        }
        
        interval = Math.floor(seconds / 86400);
        if (interval >= 1) {
            return interval + " day" + (interval === 1 ? "" : "s") + " ago";
        }
        
        interval = Math.floor(seconds / 3600);
        if (interval >= 1) {
            return interval + " hour" + (interval === 1 ? "" : "s") + " ago";
        }
        
        interval = Math.floor(seconds / 60);
        if (interval >= 1) {
            return interval + " minute" + (interval === 1 ? "" : "s") + " ago";
        }
        
        return "just now";
    }
    </script>
</body>
</html>