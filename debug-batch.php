<?php
// File: api/debug-batch.php
header('Content-Type: application/json');

// Enable error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Start output buffering to capture any PHP errors
ob_start();

// Log all request data
$log_data = [
    'POST' => $_POST,
    'FILES' => $_FILES,
    'SERVER' => [
        'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'],
        'CONTENT_TYPE' => $_SERVER['CONTENT_TYPE'] ?? 'Not set',
        'CONTENT_LENGTH' => $_SERVER['CONTENT_LENGTH'] ?? 'Not set',
        'HTTP_USER_AGENT' => $_SERVER['HTTP_USER_AGENT'] ?? 'Not set'
    ],
    'SESSION' => isset($_SESSION) ? 'Session exists' : 'No session',
    'RAW_INPUT' => file_get_contents('php://input')
];

// Log to server
error_log('Debug batch data: ' . print_r($log_data, true));

// Check for nested arrays in POST data
$materialData = [];
if (isset($_POST['materials']) && is_array($_POST['materials'])) {
    $materialData = $_POST['materials'];
} else {
    // Try to detect materials data in flat format
    $materialsFound = false;
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'materials') === 0) {
            $materialsFound = true;
            break;
        }
    }
    
    if ($materialsFound) {
        $materialData = 'Materials data found in flat format. May need to adjust parsing.';
    }
}

// Get any PHP errors or warnings
$errors = ob_get_clean();

// Return comprehensive debug info
echo json_encode([
    'success' => true,
    'message' => 'Debug data received successfully',
    'post_data' => $_POST,
    'material_data' => $materialData,
    'errors' => $errors ? $errors : 'No PHP errors',
    'note' => 'This is a debug endpoint. Check server logs for complete data.'
]);
?>