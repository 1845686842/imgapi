<?php
if (!file_exists(__DIR__ . '/../config.php')) {
    header('Location: install.php');
    exit;
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

// 设置响应头
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . implode(',', $config['allowed_origins']));
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// 处理OPTIONS请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 获取请求头中的API密钥
$headers = getallheaders();
$api_key = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : '';

// 验证API密钥
if (empty($api_key) || $api_key !== $config['api_key']) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid API key'
    ]);
    exit;
}

// 获取随机图片
function getRandomImage() {
    global $config;
    $randomIndex = array_rand($config['images']);
    return $config['images'][$randomIndex];
}

// 处理请求
try {
    $response = [
        'status' => 'success',
        'data' => [
            'image_url' => getRandomImage(),
            'timestamp' => time()
        ]
    ];
    
    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal server error'
    ]);
} 