<?php
require_once 'config.php';

class Database {
    private $pdo;
    
    public function __construct() {
        global $db_config;
        try {
            $dsn = "mysql:host={$db_config['host']};dbname={$db_config['dbname']};charset={$db_config['charset']}";
            $this->pdo = new PDO($dsn, $db_config['username'], $db_config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } catch (PDOException $e) {
            die("数据库连接失败: " . $e->getMessage());
        }
    }
    
    public function getConnection() {
        return $this->pdo;
    }
}

// 创建数据库表
function createTables() {
    $db = new Database();
    $pdo = $db->getConnection();
    
    // 创建API表
    $pdo->exec("CREATE TABLE IF NOT EXISTS apis (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        api_key VARCHAR(255) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // 创建图片表
    $pdo->exec("CREATE TABLE IF NOT EXISTS images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        api_id INT NOT NULL,
        url VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (api_id) REFERENCES apis(id) ON DELETE CASCADE
    )");
}

// 初始化数据库
createTables(); 