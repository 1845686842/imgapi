<?php
if (!file_exists(__DIR__ . '/../config.php')) {
    header('Location: install.php');
    exit;
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

// 获取API密钥
$api_key = $_GET['api_key'] ?? '';

// 验证API密钥
if (empty($api_key)) {
    die('Missing API key');
}

try {
    $db = new Database();
    $pdo = $db->getConnection();

    // 验证API密钥并获取API信息
    $stmt = $pdo->prepare("SELECT id FROM apis WHERE api_key = ?");
    $stmt->execute([$api_key]);
    $api = $stmt->fetch();

    if (!$api) {
        die('Invalid API key');
    }

    // 获取随机图片
    $stmt = $pdo->prepare("SELECT url FROM images WHERE api_id = ? ORDER BY RAND() LIMIT 1");
    $stmt->execute([$api['id']]);
    $image = $stmt->fetch();
    
    if (!$image) {
        die('No images found');
    }
    
    // 直接重定向到图片URL
    header('Location: ' . $image['url']);
    exit;
    
} catch (Exception $e) {
    die('Server error');
} 