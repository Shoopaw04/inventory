<?php
class Database
{
    private static ?Database $instance = null;
    private PDO $connection;

    private string $host = 'localhost';
    private string $database = 'inventory_system';
    private string $username = 'root';
    private string $password = '';

    private function __construct()
    {
        try {
            $this->connection = new PDO(
                "mysql:host={$this->host};dbname={$this->database};charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    
    private function __clone(): void
    {
    }

    public function __wakeup(): void
    {
        throw new Exception("Cannot unserialize singleton");
    }
}
?>