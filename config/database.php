<?php
declare(strict_types=1);

/**
 * Database Connection Singleton
 * PDO with MySQL 8.0+ - Hostinger shared hosting compatible
 */

class Database
{
    private static ?Database $instance = null;
    private ?PDO $pdo = null;

    // --- CONFIGURATION (override via environment or app.php constants) ---
    private string $host = 'localhost';
    private string $dbname = 'stamgast_db';
    private string $username = 'root';
    private string $password = '';
    private string $charset = 'utf8mb4';
    private int $port = 3306;

    private function __construct()
    {
        // Allow overrides from constants if defined
        if (defined('DB_HOST'))     $this->host     = DB_HOST;
        if (defined('DB_NAME'))     $this->dbname   = DB_NAME;
        if (defined('DB_USER'))     $this->username  = DB_USER;
        if (defined('DB_PASS'))     $this->password  = DB_PASS;
        if (defined('DB_CHARSET'))  $this->charset   = DB_CHARSET;
        if (defined('DB_PORT'))     $this->port      = (int) DB_PORT;

        $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->dbname};charset={$this->charset}";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::MYSQL_ATTR_FOUND_ROWS   => true,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->charset} COLLATE {$this->charset}_unicode_ci",
        ];

        try {
            $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
        } catch (\PDOException $e) {
            // Never expose DB credentials in production
            if (defined('APP_DEBUG') && APP_DEBUG === true) {
                throw new \RuntimeException('Database connection failed: ' . $e->getMessage());
            }
            throw new \RuntimeException('Database connection failed. Please try again later.');
        }
    }

    /**
     * Get the singleton Database instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get the PDO connection
     */
    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    /**
     * Prevent cloning of the singleton
     */
    private function __clone() {}

    /**
     * Prevent unserialization of the singleton
     */
    public function __wakeup()
    {
        throw new \RuntimeException('Cannot unserialize singleton');
    }
}
