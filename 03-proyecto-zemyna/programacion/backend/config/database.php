<?php
class Database {
    private string $host     = "localhost";
    private string $db_name  = "zemyna";
    private string $username = "root";
    private string $password = "";

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
