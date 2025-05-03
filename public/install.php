<?php
// 如果已存在config.php，跳转到后台
if (file_exists(__DIR__ . '/../config.php')) {
    header('Location: admin/login.php');
    exit;
}

$error = '';
$success = false;

// 后端密码强度校验函数
function checkPwdStrength($pwd) {
    if (strlen($pwd) < 8) return '密码长度不能少于8位';
    if (!preg_match('/[A-Za-z]/', $pwd) || !preg_match('/[0-9]/', $pwd)) return '密码需包含字母和数字';
    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host = $_POST['db_host'] ?? '';
    $db_name = $_POST['db_name'] ?? '';
    $db_user = $_POST['db_user'] ?? '';
    $db_pass = $_POST['db_pass'] ?? '';
    $db_charset = $_POST['db_charset'] ?? 'utf8mb4';
    $admin_user = $_POST['admin_user'] ?? '';
    $admin_pass = $_POST['admin_pass'] ?? '';
    $admin_session = 'random_image_admin';

    // 先校验密码强度
    $pwdMsg = checkPwdStrength($admin_pass);
    if ($pwdMsg) {
        $error = $pwdMsg;
    }

    // 1. 测试数据库连接
    if (!$error) {
        try {
            $dsn = "mysql:host=$db_host;dbname=$db_name;charset=$db_charset";
            $pdo = new PDO($dsn, $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } catch (Exception $e) {
            $error = '数据库连接失败：' . $e->getMessage();
        }
    }

    // 2. 写入config.php和初始化数据库
    if (!$error) {
        $config_tpl = <<<PHP
<?php
// 数据库配置
\$db_config = [
    'host' => '$db_host',
    'dbname' => '$db_name',
    'username' => '$db_user',
    'password' => '$db_pass',
    'charset' => '$db_charset'
];

// 后台配置
\$admin_config = [
    'username' => '$admin_user',
    'password' => '$admin_pass',
    'session_name' => '$admin_session'
];

// CORS配置
\$config = [
    'allowed_origins' => [
        '*',
    ],
];
PHP;
        $cfg_path = __DIR__ . '/../config.php';
        if (file_put_contents($cfg_path, $config_tpl) === false) {
            $error = '写入config.php失败，请检查目录权限';
        } else {
            // 3. 初始化数据库表
            require_once $cfg_path;
            require_once __DIR__ . '/../db.php';
            $db = new Database();
            $pdo = $db->getConnection();
            // 创建表
            $pdo->exec("CREATE TABLE IF NOT EXISTS apis (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                api_key VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec("CREATE TABLE IF NOT EXISTS images (
                id INT AUTO_INCREMENT PRIMARY KEY,
                api_id INT NOT NULL,
                url VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (api_id) REFERENCES apis(id) ON DELETE CASCADE
            )");
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>API管理系统安装引导</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">
            <div class="card shadow">
                <div class="card-body">
                    <h3 class="mb-4 text-center">API管理系统安装引导</h3>
                    <?php if ($success): ?>
                        <div class="alert alert-success">安装成功！<br>请手动删除 <b>public/install.php</b> 文件。<br><a href="admin/login.php" class="btn btn-success mt-3">进入后台登录</a></div>
                    <?php else: ?>
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>
                        <form method="POST" autocomplete="off">
                            <h5 class="mb-3">数据库信息</h5>
                            <div class="mb-3">
                                <label class="form-label">数据库主机</label>
                                <input type="text" class="form-control" name="db_host" value="localhost:3306" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">数据库名</label>
                                <input type="text" class="form-control" name="db_name" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">数据库用户名</label>
                                <input type="text" class="form-control" name="db_user" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">数据库密码</label>
                                <input type="password" class="form-control" name="db_pass">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">字符集</label>
                                <input type="text" class="form-control" name="db_charset" value="utf8mb4" required>
                            </div>
                            <h5 class="mb-3 mt-4">管理员信息</h5>
                            <div class="mb-3">
                                <label class="form-label">管理员账号</label>
                                <input type="text" class="form-control" name="admin_user" value="admin" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">管理员密码</label>
                                <input type="password" class="form-control" name="admin_pass" required>
                                <div class="form-text text-danger" id="installPwdTip">密码长度不少于8位，需包含字母和数字</div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">开始安装</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function checkPwdStrength(pwd) {
    if (pwd.length < 8) return '密码长度不能少于8位';
    if (!/[A-Za-z]/.test(pwd) || !/[0-9]/.test(pwd)) return '密码需包含字母和数字';
    return '';
}
function bindPwdCheck(inputSelector, tipSelector) {
    var input = document.querySelector(inputSelector);
    var tip = document.querySelector(tipSelector);
    if (!input || !tip) return;
    input.addEventListener('input', function() {
        var msg = checkPwdStrength(input.value);
        tip.textContent = msg ? msg : '密码强度合格';
        tip.style.color = msg ? 'red' : 'green';
    });
}
bindPwdCheck('input[name="admin_pass"]', '#installPwdTip');
</script>
</body>
</html> 