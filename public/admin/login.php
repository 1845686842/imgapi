<?php
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($username === $admin_config['username'] && $password === $admin_config['password']) {
        $_SESSION[$admin_config['session_name']] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = '用户名或密码错误';
    }
}

// 如果已经登录，直接跳转到首页
if (isset($_SESSION[$admin_config['session_name']])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>后台登录</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center mt-5">
            <div class="col-md-6 col-lg-4">
                <div class="card shadow">
                    <div class="card-body">
                        <h3 class="text-center mb-4">后台登录</h3>
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        <form method="POST">
                            <div class="mb-3">
                                <label for="username" class="form-label">用户名</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">密码</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password" name="password" required>
                                    <button class="btn btn-outline-secondary toggle-pwd" type="button" tabindex="-1">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">登录</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
<script>
document.querySelectorAll('.toggle-pwd').forEach(function(btn) {
    btn.onclick = function() {
        const input = btn.parentElement.querySelector('input');
        if (input.type === 'password') {
            input.type = 'text';
            btn.querySelector('i').classList.remove('bi-eye');
            btn.querySelector('i').classList.add('bi-eye-slash');
        } else {
            input.type = 'password';
            btn.querySelector('i').classList.remove('bi-eye-slash');
            btn.querySelector('i').classList.add('bi-eye');
        }
    };
});
</script>
</html> 