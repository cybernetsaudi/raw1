<?php
// File: export-logs.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once '../config/database.php';
include_once '../config/auth.php'; // Include Auth class

// Check user authentication and role (e.g., only 'owner' or 'incharge' can export logs).
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'incharge')) {
    // Redirect with an error message.
    $_SESSION['error_message'] = "Unauthorized access. You do not have permission to export activity logs.";
    header('Location: activity-logs.php'); // Redirect back to logs page or dashboard
    exit;
}

// Get database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class for activity logging.
$auth = new Auth($db);

try {
    // Filters from GET parameters.
    $filter_user_id = isset($_GET['user_id']) && is_numeric($_GET['user_id']) ? intval($_GET['user_id']) : null;
    $filter_action_type = isset($_GET['action_type']) ? trim($_GET['action_type']) : '';
    $filter_module = isset($_GET['module']) ? trim($_GET['module']) : '';
    $filter_start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
    $filter_end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
    $search_description = isset($_GET['search_description']) ? trim($_GET['search_description']) : '';

    $where_clauses = [];
    $params = [];

    if ($filter_user_id) {
        $where_clauses[] = "al.user_id = ?";
        $params[] = $filter_user_id;
    }
    if (!empty($filter_action_type)) {
        $where_clauses[] = "al.action_type = ?";
        $params[] = $filter_action_type;
    }
    if (!empty($filter_module)) {
        $where_clauses[] = "al.module = ?";
        $params[] = $filter_module;
    }
    if (!empty($filter_start_date)) {
        $where_clauses[] = "al.created_at >= ?";
        $params[] = $filter_start_date . ' 00:00:00';
    }
    if (!empty($filter_end_date)) {
        $where_clauses[] = "al.created_at <= ?";
        $params[] = $filter_end_date . ' 23:59:59';
    }
    if (!empty($search_description)) {
        $where_clauses[] = "al.description LIKE ?";
        $params[] = '%' . $search_description . '%';
    }

    $where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

    // Fetch activity logs for export.
    $query = "SELECT al.id, u.full_name as user_name, al.action_type, al.module, al.description, al.entity_id, al.ip_address, al.user_agent, al.created_at
              FROM activity_logs al
              LEFT JOIN users u ON al.user_id = u.id
              " . $where_sql . "
              ORDER BY al.created_at DESC"; // No LIMIT for export
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare CSV data.
    $filename = "activity_logs_" . date('Y-m-d_H-i-s') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    // Add UTF-8 BOM (Byte Order Mark) for Excel compatibility.
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Define CSV headers.
    fputcsv($output, [
        'ID', 'User Name', 'Action Type', 'Module', 'Description', 'Entity ID', 'IP Address', 'User Agent', 'Created At'
    ]);

    // Add data rows.
    foreach ($logs as $log) {
        fputcsv($output, [
            $log['id'],
            $log['user_name'] ?: 'System/N/A',
            ucfirst(str_replace('_', ' ', $log['action_type'])),
            ucfirst(str_replace('_', ' ', $log['module'])),
            $log['description'],
            $log['entity_id'] ?: 'N/A',
            $log['ip_address'] ?: 'N/A',
            $log['user_agent'] ?: 'N/A',
            date('Y-m-d H:i:s', strtotime($log['created_at']))
        ]);
    }

    fclose($output);

    // Log the export activity.
    $auth->logActivity(
        $_SESSION['user_id'],
        'export',
        'activity_logs',
        'Exported activity logs to CSV' . (!empty($where_sql) ? ' with filters' : ''),
        null
    );
    exit; // Exit after sending file

} catch (PDOException $e) {
    // Log the error.
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'activity_logs',
        'Database error during log export: ' . $e->getMessage(),
        null
    );
    // Set a session error message and redirect if export fails before headers sent.
    $_SESSION['error_message'] = "Failed to export activity logs due to a database error. Please try again.";
    header('Location: activity-logs.php');
    exit;
} catch (Exception $e) {
    // Log other errors.
    $auth->logActivity(
        $_SESSION['user_id'] ?? null,
        'error',
        'activity_logs',
        'Error during log export: ' . $e->getMessage(),
        null
    );
    $_SESSION['error_message'] = "An error occurred during log export: " . $e->getMessage();
    header('Location: activity-logs.php');
    exit;
}
?>