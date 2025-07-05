<?php
// File: config/auth.php

class Auth {
    private $conn;
    private $table_name = "users";
    private $activity_log_table = "activity_logs"; // Ensure this matches your table name

    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Attempts to log in a user.
     * @param string $username The user's username.
     * @param string $password The user's plain text password.
     * @return bool True on successful login, false otherwise.
     */
    public function login($username, $password) {
        // No session_start() here; assumes it's already started by the calling script.
        // Check if a user with the given username exists and is active
        $query = "SELECT id, password, full_name, email, role, is_active FROM " . $this->table_name . " WHERE username = ? LIMIT 0,1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $username);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $id = $row['id'];
            $hashed_password = $row['password'];
            $full_name = $row['full_name'];
            $email = $row['email'];
            $role = $row['role'];
            $is_active = $row['is_active'];

            // Verify password and active status
            if (password_verify($password, $hashed_password) && $is_active) {
                // Set session variables
                $_SESSION['user_id'] = $id;
                $_SESSION['username'] = $username;
                $_SESSION['full_name'] = $full_name;
                $_SESSION['email'] = $email;
                $_SESSION['role'] = $role;
                $_SESSION['logged_in'] = true;

                // Log activity
                $this->logActivity($id, 'login', 'authentication', 'User logged in successfully.', $id);
                return true;
            }
        }

        // Log failed login attempt
        $this->logActivity(null, 'login_failed', 'authentication', 'Failed login attempt for username: ' . $username, null);
        return false;
    }

    /**
     * Checks if a user is currently logged in.
     * @return bool True if logged in, false otherwise.
     */
    public function isLoggedIn() {
        // No session_start() here; assumes it's already started by the calling script.
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }

    /**
     * Retrieves the role of the currently logged-in user.
     * @return string|null The user's role or null if not logged in.
     */
    public function getUserRole() {
        // No session_start() here; assumes it's already started by the calling script.
        return $this->isLoggedIn() ? $_SESSION['role'] : null;
    }

    /**
     * Logs out the current user.
     */
    public function logout() {
        // No session_start() here; assumes it's already started by the calling script.
        if ($this->isLoggedIn()) {
            $user_id = $_SESSION['user_id'];
            // Log activity before destroying session
            $this->logActivity($user_id, 'logout', 'authentication', 'User logged out.', $user_id);

            // Unset all session variables
            $_SESSION = array();

            // Destroy the session
            session_destroy();
        }
    }

    /**
     * Logs user activity into the database.
     * @param int|null $user_id The ID of the user performing the action. Can be null for system/unauthenticated actions.
     * @param string $action_type Type of action (e.g., 'login', 'create', 'update', 'delete', 'error').
     * @param string $module The module where the action occurred (e.g., 'users', 'products', 'sales').
     * @param string $description A detailed description of the action.
     * @param int|null $entity_id The ID of the entity affected by the action (e.g., product_id, customer_id).
     */
    public function logActivity($user_id, $action_type, $module, $description, $entity_id = null) {
        $query = "INSERT INTO " . $this->activity_log_table . "
                  (user_id, action_type, module, description, entity_id, ip_address, user_agent)
                  VALUES (?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($query);

        // Get IP address and User Agent (safely handle if not available in CLI or certain contexts)
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'CLI/Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        // Ensure user_id is integer or null
        $user_id = filter_var($user_id, FILTER_VALIDATE_INT) !== false ? $user_id : null;

        $stmt->bindParam(1, $user_id, PDO::PARAM_INT);
        $stmt->bindParam(2, $action_type);
        $stmt->bindParam(3, $module);
        $stmt->bindParam(4, $description);
        $stmt->bindParam(5, $entity_id, PDO::PARAM_INT);
        $stmt->bindParam(6, $ip_address);
        $stmt->bindParam(7, $user_agent);

        $stmt->execute();
    }
}