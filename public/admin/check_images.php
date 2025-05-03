<?php
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';

// 检查登录状态
if (!isset($_SESSION[$admin_config['session_name']])) {
    http_response_code(401);
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

// 获取API ID
$api_id = intval($_POST['api_id'] ?? 0);
if ($api_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => '无效的API ID']);
    exit;
}

// 设置脚本执行时间限制
set_time_limit(300); // 5分钟

// 获取该API下的所有图片
$stmt = $pdo->prepare("SELECT id, url FROM images WHERE api_id = ?");
$stmt->execute([$api_id]);
$images = $stmt->fetchAll();

$invalid = [];
$total = count($images);
$checked = 0;

foreach ($images as $image) {
    $checked++;
    try {
        // 使用cURL检查链接是否有效
        $ch = curl_init($image['url']);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ]);
        
        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // 如果HTTP状态码不是200，或者cURL执行失败，则认为链接失效
        if (!$success || $httpCode !== 200) {
            $invalid[] = [
                'id' => $image['id'],
                'url' => $image['url'],
                'error' => $error ?: "HTTP状态码: $httpCode"
            ];
        }
    } catch (Exception $e) {
        $invalid[] = [
            'id' => $image['id'],
            'url' => $image['url'],
            'error' => $e->getMessage()
        ];
    }
}

// 返回结果
echo json_encode([
    'status' => 'success',
    'invalid' => $invalid,
    'total' => $total,
    'checked' => $checked
]); 