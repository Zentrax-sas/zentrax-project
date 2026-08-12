<?php
require_once __DIR__ . '/parse_env.php';
loadEnvFile(__DIR__ . '/../../.env');

class Database {
    private string $host;
    private string $db_name;
    private string $username;
    private string $password;

    public function __construct() {
        $this->host = getenv('DB_HOST') ?: 'localhost';
        $this->db_name = getenv('DB_NAME') ?: 'zemyna';
        $this->username = getenv('DB_USER') ?: 'root';
        $this->password = getenv('DB_PASS') ?: '';
    }

    public function getConnection(): ?PDO {
        try {
            $dsn  = "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4";
            $conn = new PDO($dsn, $this->username, $this->password);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $conn;
        } catch (PDOException $e) {
            return null;
        }
    }
}
