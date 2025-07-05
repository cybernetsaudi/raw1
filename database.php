
<?php
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;
    
    public function __construct() {
        // Use global config if available
        $this->host = isset($GLOBALS['DB_HOST']) ? $GLOBALS['DB_HOST'] : "localhost";
        $this->db_name = isset($GLOBALS['DB_NAME']) ? $GLOBALS['DB_NAME'] : "u950050130_raw";
        $this->username = isset($GLOBALS['DB_USER']) ? $GLOBALS['DB_USER'] : "u950050130_raw";
        $this->password = isset($GLOBALS['DB_PASS']) ? $GLOBALS['DB_PASS'] : "!@#Acc3ss931!@#";
    }
    
    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->exec("set names utf8");
        } catch(PDOException $exception) {
            echo "Connection error: " . $exception->getMessage();
        }
        
        return $this->conn;
    }
}
?>