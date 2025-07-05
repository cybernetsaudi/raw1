<?php
// File: index.php

// Ensure session is started at the very beginning of the script.
session_start();

// Include database config and Auth class.
include_once 'config/database.php'; // Path from index.php
include_once 'config/auth.php';     // Path from index.php

// Initialize database connection.
$database = new Database();
$db = $database->getConnection();

// Instantiate Auth class.
$auth = new Auth($db);

// If user is already logged in, redirect to dashboard.
if ($auth->isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$page_title = "Login";
// No header.php or footer.php for login page, typically it's a standalone view.

$error_message = '';

// Process login form submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error_message = "Please enter both username and password.";
    } else {
        if ($auth->login($username, $password)) {
            // Login successful, redirect to dashboard.
            header('Location: dashboard.php');
            exit;
        } else {
            // Login failed.
            $error_message = "Invalid username or password, or account is inactive.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Garment Manufacturing System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h2>Garment Manufacturing System</h2>
            <p>Login to your account</p>
        </div>
        <div class="login-form-wrapper">
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error_message); ?></span>
                    <span class="alert-close">&times;</span>
                </div>
            <?php endif; ?>
            <form action="index.php" method="POST" class="login-form">
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" id="username" name="username" required autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                </div>
                <button type="submit" class="button primary login-button">Login</button>
            </form>
        </div>
        <div class="login-footer">
            <p>&copy; <?php echo date('Y'); ?> Garment Manufacturing System. All rights reserved.</p>
        </div>
    </div>
    <script>
        // Client-side script for alerts (can be centralized later)
        document.addEventListener('DOMContentLoaded', function() {
            const alertCloseButtons = document.querySelectorAll('.alert-close');
            alertCloseButtons.forEach(button => {
                button.addEventListener('click', function() {
                    this.parentElement.style.display = 'none';
                });
            });
        });
    </script>
</body>
</html>