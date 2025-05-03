<?php
session_start();
if (!file_exists(__DIR__ . '/../../config.php')) {
    header('Location: ../install.php');
    exit;
}
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';

// 检查登录状态
if (!isset($_SESSION[$admin_config['session_name']])) {
    header('Location: login.php');
    exit;
}

$db = new Database();
$pdo = $db->getConnection();

// 允许的action
$allowed_actions = ['add_api', 'delete_api', 'reset_key', 'add_image', 'delete_image', 'delete_invalid', 'delete_duplicate', 'check_image_duplicate', 'change_password'];

// 处理API添加和其他操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], $allowed_actions)) {
    if ($_POST['action'] === 'add_api') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $api_key = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare("INSERT INTO apis (name, api_key) VALUES (?, ?)");
            $stmt->execute([$name, $api_key]);
        }
        // 添加重定向
        if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'fetch') {
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
    } elseif ($_POST['action'] === 'delete_api') {
        $api_id = intval($_POST['api_id'] ?? 0);
        if ($api_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM apis WHERE id = ?");
            $stmt->execute([$api_id]);
        }
        // 添加重定向
        if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'fetch') {
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
    } elseif ($_POST['action'] === 'reset_key') {
        $api_id = intval($_POST['api_id'] ?? 0);
        if ($api_id > 0) {
            $new_key = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare("UPDATE apis SET api_key = ? WHERE id = ?");
            $stmt->execute([$new_key, $api_id]);
        }
        // 添加重定向
        if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'fetch') {
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
    } elseif ($_POST['action'] === 'add_image') {
        $api_id = intval($_POST['api_id'] ?? 0);
        $force = isset($_POST['force']) && $_POST['force'] == '1';
        $urls = preg_split('/\r?\n/', trim($_POST['url'] ?? ''));
        $duplicates = [];
        $added = [];
        foreach ($urls as $url) {
            $url = trim($url);
            if ($api_id > 0 && filter_var($url, FILTER_VALIDATE_URL)) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM images WHERE api_id = ? AND url = ?");
                $stmt->execute([$api_id, $url]);
                $exists = $stmt->fetchColumn() > 0;
                if ($exists && !$force) {
                    $duplicates[] = $url;
                    continue;
                }
                if (!$exists || $force) {
                    $stmt = $pdo->prepare("INSERT INTO images (api_id, url) VALUES (?, ?)");
                    $stmt->execute([$api_id, $url]);
                    $added[] = $url;
                }
            }
        }
        // AJAX请求时返回JSON
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'fetch') {
            if (!empty($duplicates) && !$force) {
                echo json_encode(['status'=>'duplicate','duplicates'=>$duplicates,'added'=>$added]); exit;
            } else {
                echo json_encode(['status'=>'success','added'=>$added]); exit;
            }
        } else {
            // 非AJAX请求时重定向
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
    } elseif ($_POST['action'] === 'delete_image') {
        $image_id = intval($_POST['image_id'] ?? 0);
        if ($image_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM images WHERE id = ?");
            $stmt->execute([$image_id]);
        }
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'fetch') {
            echo json_encode(['status'=>'success']); exit;
        } else {
            // 非AJAX请求时重定向
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
    } elseif ($_POST['action'] === 'delete_invalid') {
        $ids = isset($_POST['ids']) ? explode(',', $_POST['ids']) : [];
        $ids = array_filter($ids, function($v){ return ctype_digit($v) && $v > 0; });
        error_log('批量删除图片ids: ' . print_r($ids, true));
        if (!empty($ids)) {
            if (count($ids) === 1) {
                $sql = "DELETE FROM images WHERE id = ?";
            } else {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $sql = "DELETE FROM images WHERE id IN ($placeholders)";
            }
            error_log('批量删除SQL: ' . $sql);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($ids);
        }
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'fetch') {
            echo json_encode(['status'=>'success']); exit;
        } else {
            // 非AJAX请求时重定向
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
    } elseif ($_POST['action'] === 'delete_duplicate') {
        $api_id = intval($_POST['api_id'] ?? 0);
        $do_delete = isset($_POST['do_delete']) && $_POST['do_delete'] == '1';
        $deleted = 0;
        $to_delete = [];
        if ($api_id > 0) {
            // 查找重复url的id（只保留每组的最小id）
            $sql = "SELECT url, GROUP_CONCAT(id ORDER BY id ASC) as ids, COUNT(*) as cnt FROM images WHERE api_id = ? GROUP BY url HAVING cnt > 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$api_id]);
            foreach ($stmt->fetchAll() as $row) {
                $ids = explode(',', $row['ids']);
                array_shift($ids); // 保留最小id，其他为重复
                foreach ($ids as $id) {
                    $to_delete[] = ['id' => $id, 'url' => $row['url']];
                }
            }
            if ($do_delete && !empty($to_delete)) {
                $del_ids = array_column($to_delete, 'id');
                $in = implode(',', array_fill(0, count($del_ids), '?'));
                $sql_del = "DELETE FROM images WHERE id IN ($in)";
                $stmt = $pdo->prepare($sql_del);
                $stmt->execute($del_ids);
                $deleted = $stmt->rowCount();
            }
        }
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'fetch') {
            if (!$do_delete) {
                echo json_encode(['status'=>'preview','to_delete'=>$to_delete]); exit;
            } else {
                echo json_encode(['status'=>'success','deleted'=>$deleted]); exit;
            }
        } else {
            // 非AJAX请求时重定向
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
    } elseif ($_POST['action'] === 'check_image_duplicate') {
        $api_id = intval($_POST['api_id'] ?? 0);
        $urls = preg_split('/\r?\n/', trim($_POST['url'] ?? ''));
        $duplicates = [];
        $uniques = [];
        foreach ($urls as $url) {
            $url = trim($url);
            if ($api_id > 0 && filter_var($url, FILTER_VALIDATE_URL)) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM images WHERE api_id = ? AND url = ?");
                $stmt->execute([$api_id, $url]);
                $exists = $stmt->fetchColumn() > 0;
                if ($exists) {
                    $duplicates[] = $url;
                } else {
                    $uniques[] = $url;
                }
            }
        }
        echo json_encode(['status'=>'checked','duplicates'=>$duplicates,'uniques'=>$uniques]); exit;
    } elseif ($_POST['action'] === 'change_password') {
        $old = $_POST['old_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $new2 = $_POST['new_password2'] ?? '';
        $pwdMsg = checkPwdStrength($new);
        if ($pwdMsg) {
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'fetch') {
                echo json_encode(['status'=>'error','msg'=>$pwdMsg]); exit;
            } else {
                $change_pwd_error = $pwdMsg;
            }
        }
        if ($old === $admin_config['password'] && $new && $new === $new2) {
            // 只替换$admin_config数组里的password字段，支持多行
            $config_path = __DIR__ . '/../../config.php';
            $config_code = file_get_contents($config_path);
            $config_code = preg_replace_callback(
                '/(\$admin_config\s*=\s*\[[^\]]*)[\'\"]password[\'\"]\s*=>\s*[\'\"][^\'\"]*[\'\"]/',
                function($matches) use ($new) {
                    return preg_replace(
                        '/([\'\"]password[\'\"]\s*=>\s*)[\'\"][^\'\"]*[\'\"]/',
                        '${1}\'' . addslashes($new) . '\'',
                        $matches[0]
                    );
                },
                $config_code,
                1
            );
            file_put_contents($config_path, $config_code);
            clearstatcache();
            $admin_config['password'] = $new;
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'fetch') {
                echo json_encode(['status'=>'success']); exit;
            } else {
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }
        }
    }
}

// 获取所有API
$apis = $pdo->query("SELECT * FROM apis ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API管理后台</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
    .api-key-group { display: flex; align-items: center; gap: 0.5rem; }
    .api-key-text { font-family: monospace; font-size: 1rem; background: #f8f9fa; border-radius: 4px; padding: 2px 8px; }
    .api-key-box { display: inline-block; font-family: monospace; font-size: 1rem; background: #fff; border: 1px solid #dee2e6; border-radius: 4px; padding: 2px 10px; margin-right: 0.5rem; min-width: 220px; text-align: left; }
    .api-btn-sm { padding: 2px 8px; font-size: 0.9rem; }
    .api-header-flex { display: flex; justify-content: space-between; align-items: center; }
    .api-header-actions { display: flex; align-items: center; gap: 0.5rem; }
    .api-image-list-scroll { max-height: 400px; overflow-y: auto; border: 1px solid #eee; border-radius: 4px; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="#">API管理后台</a>
            <div class="navbar-nav ms-auto align-items-center">
                <button class="btn btn-outline-light btn-sm me-2" id="changePwdBtn" type="button">修改密码</button>
                <a class="nav-link" href="logout.php">退出登录</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- 添加新API -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">添加新API</h5>
            </div>
            <div class="card-body">
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="action" value="add_api">
                    <div class="mb-3">
                        <label for="name" class="form-label">API名称</label>
                        <input type="text" class="form-control" id="name" name="name" required autocomplete="off">
                    </div>
                    <button type="submit" class="btn btn-primary">添加API</button>
                </form>
            </div>
        </div>

        <!-- API列表 -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">API列表</h5>
            </div>
            <div class="card-body">
                <?php foreach ($apis as $api): ?>
                    <div class="card mb-3">
                        <div class="card-header api-header-flex">
                            <span><?php echo htmlspecialchars($api['name']); ?></span>
                            <div class="api-header-actions">
                                <span class="me-1 fw-bold text-secondary">KEY</span>
                                <span class="api-key-box" id="api-key-<?php echo $api['id']; ?>"><?php echo $api['api_key']; ?></span>
                                <form method="POST" class="d-inline" autocomplete="off" style="display:inline;">
                                    <input type="hidden" name="action" value="reset_key">
                                    <input type="hidden" name="api_id" value="<?php echo $api['id']; ?>">
                                    <button type="submit" class="btn btn-warning btn-sm api-btn-sm" title="重置密钥"><i class="bi bi-arrow-clockwise"></i></button>
                                </form>
                                <button class="btn btn-outline-secondary btn-sm api-btn-sm" title="复制API链接" onclick="copyApiUrl('<?php echo $api['api_key']; ?>', this)"><i class="bi bi-clipboard"></i></button>
                                <form method="POST" class="d-inline" autocomplete="off" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_api">
                                    <input type="hidden" name="api_id" value="<?php echo $api['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm api-btn-sm" title="删除"><i class="bi bi-trash"></i></button>
                                </form>
                                <button class="btn btn-outline-info btn-sm api-btn-sm ms-2" type="button" onclick="checkInvalidLinks(<?php echo $api['id']; ?>, this)"><i class="bi bi-search"></i> 检测失效链接</button>
                                <button class="btn btn-outline-warning btn-sm api-btn-sm ms-2" type="button" onclick="deleteDuplicateImages(<?php echo $api['id']; ?>, this)"><i class="bi bi-x-diamond"></i> 删除重复照片</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- 添加图片 -->
                            <form method="POST" class="mb-3 add-image-form" autocomplete="off">
                                <input type="hidden" name="action" value="add_image">
                                <input type="hidden" name="api_id" value="<?php echo $api['id']; ?>">
                                <input type="hidden" name="force" value="0">
                                <div class="input-group mb-2">
                                    <textarea class="form-control" name="url" placeholder="每行一个图片URL，支持批量添加" rows="1" style="resize:vertical;min-height:38px;" required autocomplete="off"></textarea>
                                    <button type="submit" class="btn btn-primary">添加图片</button>
                                </div>
                            </form>

                            <!-- 图片列表 -->
                            <?php
                            $stmt = $pdo->prepare("SELECT * FROM images WHERE api_id = ? ORDER BY created_at DESC");
                            $stmt->execute([$api['id']]);
                            $images = $stmt->fetchAll();
                            ?>
                            <div>
                                <div class="d-flex align-items-center gap-2 my-2">
                                    <button type="button" class="btn btn-link btn-sm text-primary" id="expandBtn-<?php echo $api['id']; ?>">展开全部 (<?php echo count($images); ?>)</button>
                                    <button type="button" id="selectAllBtn-<?php echo $api['id']; ?>" class="btn btn-outline-secondary btn-sm">全选</button>
                                    <button type="button" id="batchDeleteBtn-<?php echo $api['id']; ?>" class="btn btn-danger btn-sm" disabled>批量删除</button>
                                </div>
                                <div class="api-image-list-scroll" id="imageList-<?php echo $api['id']; ?>">
                                    <div class="list-group" id="imageListGroup-<?php echo $api['id']; ?>"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- 检测失效链接弹窗 -->
    <div class="modal fade" id="invalidLinksModal" tabindex="-1" aria-labelledby="invalidLinksModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="invalidLinksModalLabel">失效图片链接检测结果</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body" id="invalidLinksModalBody">
            <!-- 动态填充内容 -->
          </div>
          <div class="modal-footer" id="invalidLinksModalFooter">
            <!-- 动态填充按钮 -->
          </div>
        </div>
      </div>
    </div>

    <!-- 检测重复图片链接弹窗 -->
    <div class="modal fade" id="duplicateLinksModal" tabindex="-1" aria-labelledby="duplicateLinksModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="duplicateLinksModalLabel">检测到重复图片链接</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body" id="duplicateLinksModalBody">
            <!-- 动态填充内容 -->
          </div>
          <div class="modal-footer" id="duplicateLinksModalFooter">
            <!-- 动态填充按钮 -->
          </div>
        </div>
      </div>
    </div>

    <!-- 图片预览模态框 -->
    <div class="modal fade" id="imgPreviewModal" tabindex="-1" aria-labelledby="imgPreviewModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="imgPreviewModalLabel">图片预览</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body text-center">
            <img src="" alt="预览图片" id="imgPreviewModalImg" class="img-fluid" style="max-height:70vh;">
          </div>
        </div>
      </div>
    </div>

    <!-- 图片添加成功模态框 -->
    <div class="modal fade" id="addSuccessModal" tabindex="-1" aria-labelledby="addSuccessModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="addSuccessModalLabel">图片添加成功</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body text-center" id="addSuccessModalBody">
            <!-- 动态填充数量 -->
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-primary" data-bs-dismiss="modal">确定</button>
          </div>
        </div>
      </div>
    </div>

    <!-- 通用操作结果模态框 -->
    <div class="modal fade" id="actionResultModal" tabindex="-1" aria-labelledby="actionResultModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="actionResultModalLabel">操作通知</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body text-center" id="actionResultModalBody">
            <!-- 动态填充内容 -->
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-primary" data-bs-dismiss="modal">确定</button>
          </div>
        </div>
      </div>
    </div>

    <!-- 通用确认操作模态框 -->
    <div class="modal fade" id="actionConfirmModal" tabindex="-1" aria-labelledby="actionConfirmModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="actionConfirmModalLabel">请确认操作</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
          </div>
          <div class="modal-body text-center" id="actionConfirmModalBody">
            <!-- 动态填充内容 -->
          </div>
          <div class="modal-footer justify-content-center">
            <button type="button" class="btn btn-secondary" id="actionConfirmCancelBtn" data-bs-dismiss="modal">取消</button>
            <button type="button" class="btn btn-primary" id="actionConfirmOkBtn">确定</button>
          </div>
        </div>
      </div>
    </div>

    <!-- 修改密码模态框 -->
    <div class="modal fade" id="changePwdModal" tabindex="-1" aria-labelledby="changePwdModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <form id="changePwdForm" autocomplete="off">
            <div class="modal-header">
              <h5 class="modal-title" id="changePwdModalLabel">修改后台密码</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
            </div>
            <div class="modal-body">
              <div class="mb-3">
                <label class="form-label">原密码</label>
                <div class="input-group">
                  <input type="password" class="form-control" name="old_password" required>
                  <button class="btn btn-outline-secondary toggle-pwd" type="button" tabindex="-1"><i class="bi bi-eye"></i></button>
                </div>
              </div>
              <div class="mb-3">
                <label class="form-label">新密码</label>
                <div class="input-group">
                  <input type="password" class="form-control" name="new_password" required>
                  <button class="btn btn-outline-secondary toggle-pwd" type="button" tabindex="-1"><i class="bi bi-eye"></i></button>
                </div>
                <div class="form-text text-danger" id="changePwdTip">密码长度不少于8位，需包含字母和数字</div>
              </div>
              <div class="mb-3">
                <label class="form-label">重复新密码</label>
                <div class="input-group">
                  <input type="password" class="form-control" name="new_password2" required>
                  <button class="btn btn-outline-secondary toggle-pwd" type="button" tabindex="-1"><i class="bi bi-eye"></i></button>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
              <button type="submit" class="btn btn-primary">保存</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function copyApiUrl(apiKey, btn) {
        const apiUrl = `${window.location.protocol}//${window.location.host}/api.php?api_key=${apiKey}`;
        if (navigator.clipboard) {
            navigator.clipboard.writeText(apiUrl).then(() => {
                btn.innerHTML = '<i class="bi bi-check"></i>';
                setTimeout(() => {
                    btn.innerHTML = '<i class="bi bi-clipboard"></i>';
                }, 1500);
            }).catch(() => {
                fallbackCopyTextToClipboard(apiUrl, btn);
            });
        } else {
            fallbackCopyTextToClipboard(apiUrl, btn);
        }
    }
    function fallbackCopyTextToClipboard(text, btn) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            btn.innerHTML = '<i class="bi bi-check"></i>';
            setTimeout(() => {
                btn.innerHTML = '<i class="bi bi-clipboard"></i>';
            }, 1500);
        } catch (err) {
            alert('复制失败，请手动复制');
        }
        document.body.removeChild(textarea);
    }
    function checkInvalidLinks(apiId, btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 检测中...';
        const modalBody = document.getElementById('invalidLinksModalBody');
        modalBody.innerHTML = '<div class="text-center"><div class="spinner-border text-primary mb-3"></div><p>正在检测图片链接，请稍候...</p></div>';
        const modal = new bootstrap.Modal(document.getElementById('invalidLinksModal'));
        modal.show();

        fetch('check_images.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'api_id=' + apiId
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-search"></i> 检测失效链接';
            if (data.status === 'success') {
                if (data.invalid.length === 0) {
                    modalBody.innerHTML = '<div class="alert alert-success">检测完成，共检查 ' + data.checked + ' 个链接，没有发现失效链接！</div>';
                    document.getElementById('invalidLinksModalFooter').innerHTML = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>';
                } else {
                    let html = '<div class="alert alert-danger">检测完成，共检查 ' + data.checked + ' 个链接，发现 ' + data.invalid.length + ' 个失效图片链接：</div>';
                    html += '<ul class="list-group mb-3">';
                    data.invalid.forEach(img => {
                        html += '<li class="list-group-item d-flex justify-content-between align-items-center">'
                            + '<div class="flex-grow-1 me-2">'
                            + '<div class="text-break">' + img.url + '</div>'
                            + '<small class="text-danger">' + (img.error || '链接失效') + '</small>'
                            + '</div>'
                            + '<form method="POST" class="d-inline ms-2" autocomplete="off">'
                            + '<input type="hidden" name="action" value="delete_image">'
                            + '<input type="hidden" name="image_id" value="' + img.id + '">'
                            + '<button type="submit" class="btn btn-danger btn-sm">删除</button>'
                            + '</form>'
                            + '</li>';
                    });
                    html += '</ul>';
                    // 批量删除form
                    let ids = data.invalid.map(img => img.id).join(',');
                    let batchForm = '<form method="POST" class="d-inline" autocomplete="off" onsubmit="return confirm(\'确定要批量删除所有失效图片吗？\');">'
                        + '<input type="hidden" name="action" value="delete_invalid">'
                        + '<input type="hidden" name="ids" value="' + ids + '">'
                        + '<button type="submit" class="btn btn-danger">批量删除全部失效图片</button>'
                        + '</form>';
                    modalBody.innerHTML = html;
                    document.getElementById('invalidLinksModalFooter').innerHTML = batchForm + '<button type="button" class="btn btn-secondary ms-2" data-bs-dismiss="modal">关闭</button>';
                }
            } else {
                modalBody.innerHTML = '<div class="alert alert-danger">检测失败：' + (data.message || '未知错误') + '</div>';
                document.getElementById('invalidLinksModalFooter').innerHTML = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>';
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-search"></i> 检测失效链接';
            modalBody.innerHTML = '<div class="alert alert-danger">检测失败，网络或服务器错误</div>';
            document.getElementById('invalidLinksModalFooter').innerHTML = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>';
        });
    }
    // 批量删除失效图片
    function deleteInvalidImages(apiId) {}
    // 单个删除
    function deleteSingleImage(id, btn) {}
    // textarea自适应高度，最多10行
    document.querySelectorAll('textarea[name="url"]').forEach(function(textarea) {
        textarea.addEventListener('input', autoResizeTextarea);
        textarea.addEventListener('paste', function() {
            setTimeout(() => autoResizeTextarea.call(textarea), 0);
        });
    });
    function autoResizeTextarea() {
        const lines = this.value.split('\n').length;
        this.rows = Math.min(Math.max(lines, 1), 10);
    }
    // 批量添加图片时检测重复，弹窗提示
    document.querySelectorAll('.add-image-form').forEach(function(form) {
        form._submitting = false;
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (form._submitting) return;
            form._submitting = true;
            // 先查重
            const formData = new FormData(form);
            formData.set('action', 'check_image_duplicate');
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'X-Requested-With': 'fetch' },
                body: new URLSearchParams(formData),
                credentials: 'same-origin'
            }).then(res => res.json()).then(data => {
                form._submitting = false;
                if (data.status === 'checked') {
                    // 没有重复，直接添加
                    if (data.duplicates.length === 0) {
                        doAddImages(form, data.uniques, false); // 直接添加
                    } else {
                        // 有重复，弹窗三选一
                        let html = '<div class="alert alert-warning">以下链接已存在，如何处理？</div>';
                        html += '<ul class="list-group mb-3">';
                        data.duplicates.forEach(url => {
                            html += '<li class="list-group-item">' + url + '</li>';
                        });
                        html += '</ul>';
                        document.getElementById('duplicateLinksModalBody').innerHTML = html;
                        document.getElementById('duplicateLinksModalFooter').innerHTML =
                            '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>' +
                            '<button type="button" class="btn btn-success me-2" id="addOnlyNewBtn">仅添加未重复链接</button>' +
                            '<button type="button" class="btn btn-primary" id="forceAddBtn">强制添加</button>';
                        var modal = new bootstrap.Modal(document.getElementById('duplicateLinksModal'));
                        modal.show();
                        const forceBtn = document.getElementById('forceAddBtn');
                        const onlyNewBtn = document.getElementById('addOnlyNewBtn');
                        forceBtn.onclick = function() {
                            forceBtn.disabled = true;
                            onlyNewBtn.disabled = true;
                            modal.hide();
                            // 强制添加全部（包括重复的）
                            doAddImages(form, data.uniques.concat(data.duplicates), true);
                        };
                        onlyNewBtn.onclick = function() {
                            forceBtn.disabled = true;
                            onlyNewBtn.disabled = true;
                            modal.hide();
                            // 只添加未重复
                            doAddImages(form, data.uniques, true);
                        };
                    }
                }
            }).catch(() => { form._submitting = false; });
        });
    });
    // 真正添加图片的函数
    function doAddImages(form, urls, force) {
        if (!urls || urls.length === 0) {
            showActionResultModal('没有可添加的图片链接！', 'warning');
            return;
        }
        form._submitting = true;
        const api_id = form.querySelector('input[name="api_id"]').value;
        const formData = new FormData();
        formData.set('action', 'add_image');
        formData.set('api_id', api_id);
        formData.set('force', force ? '1' : '0');
        formData.set('url', urls.join('\n'));
        fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'X-Requested-With': 'fetch' },
            body: new URLSearchParams(formData),
            credentials: 'same-origin'
        }).then(res => res.json()).then(data => {
            form._submitting = false;
            if (data.status === 'success') {
                form.querySelector('textarea[name="url"]').value = '';
                let count = (data.added && Array.isArray(data.added)) ? data.added.length : urls.length;
                showActionResultModal(`已成功添加 <b>${count}</b> 张图片！`, 'success', () => window.location.reload());
            } else {
                showActionResultModal('添加失败', 'danger');
            }
        }).catch(() => { form._submitting = false; });
    }
    // 删除重复照片AJAX（两步式，先预览再确认删除）
    function deleteDuplicateImages(apiId, btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 检测中...';
        fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
            body: 'action=delete_duplicate&api_id=' + apiId,
            credentials: 'same-origin'
        }).then(res => res.json()).then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-x-diamond"></i> 删除重复照片';
            if (data.status === 'preview') {
                if (data.to_delete.length === 0) {
                    alert('没有检测到重复照片！');
                    return;
                }
                let html = '<div class="alert alert-warning">以下重复照片将被删除（每个链接只保留一张）：</div>';
                html += '<ul class="list-group mb-3">';
                data.to_delete.forEach(item => {
                    html += '<li class="list-group-item">' + item.url + '</li>';
                });
                html += '</ul>';
                document.getElementById('duplicateLinksModalBody').innerHTML = html;
                document.getElementById('duplicateLinksModalFooter').innerHTML =
                    '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>' +
                    '<button type="button" class="btn btn-danger" id="confirmDeleteDupBtn">确认删除</button>';
                var modal = new bootstrap.Modal(document.getElementById('duplicateLinksModal'));
                modal.show();
                document.getElementById('confirmDeleteDupBtn').onclick = function() {
                    modal.hide();
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 删除中...';
                    fetch(window.location.pathname, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
                        body: 'action=delete_duplicate&api_id=' + apiId + '&do_delete=1',
                        credentials: 'same-origin'
                    }).then(res => res.json()).then(data2 => {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="bi bi-x-diamond"></i> 删除重复照片';
                        if (data2.status === 'success') {
                            showActionResultModal('已删除 ' + data2.deleted + ' 张重复照片', 'success', () => location.reload());
                        } else {
                            showActionResultModal('操作失败', 'danger');
                        }
                    });
                };
            } else if (data.status === 'success') {
                showActionResultModal('已删除 ' + data.deleted + ' 张重复照片', 'success', () => location.reload());
            } else {
                showActionResultModal('操作失败', 'danger');
            }
        });
    }
    // 事件委托方式绑定预览按钮
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[id^=imageListGroup-]').forEach(function(group) {
            group.addEventListener('click', function(e) {
                if (e.target && e.target.classList.contains('preview-btn')) {
                    var url = e.target.getAttribute('data-url');
                    var img = document.getElementById('imgPreviewModalImg');
                    img.src = url;
                    var modal = new bootstrap.Modal(document.getElementById('imgPreviewModal'));
                    modal.show();
                }
            });
        });
    });
    </script>
    <script>
    window.allApiImages = {};
    <?php foreach ($apis as $api): ?>
    window.allApiImages[<?php echo $api['id']; ?>] = <?php
        $imgs = $pdo->query("SELECT id, url FROM images WHERE api_id = " . intval($api['id']))->fetchAll();
        echo json_encode($imgs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;
    <?php endforeach; ?>

    function bindBatchEvents(apiId) {
        const batchDeleteBtn = document.getElementById('batchDeleteBtn-' + apiId);
        const selectAllBtn = document.getElementById('selectAllBtn-' + apiId);
        const checkboxes = document.querySelectorAll('.batch-checkbox-' + apiId);
        // 复选框事件
        checkboxes.forEach(cb => {
            cb.addEventListener('change', function() {
                const anyChecked = Array.from(checkboxes).some(c => c.checked);
                batchDeleteBtn.disabled = !anyChecked;
                const allChecked = Array.from(checkboxes).every(c => c.checked);
                selectAllBtn.textContent = allChecked ? '取消全选' : '全选';
            });
        });
        // 全选按钮事件
        selectAllBtn.onclick = function() {
            const allChecked = Array.from(checkboxes).every(c => c.checked);
            checkboxes.forEach(cb => { cb.checked = !allChecked; });
            batchDeleteBtn.disabled = !Array.from(checkboxes).some(c => c.checked);
            selectAllBtn.textContent = allChecked ? '全选' : '取消全选';
        };
        // 批量删除按钮事件
        batchDeleteBtn.onclick = function() {
            const checkedIds = Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.value);
            if (checkedIds.length === 0) return;
            showActionConfirmModal('确定要批量删除选中的图片吗？', function() {
                batchDeleteBtn.disabled = true;
                batchDeleteBtn.textContent = '删除中...';
                fetch(window.location.pathname, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'fetch', 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=delete_invalid&ids=' + encodeURIComponent(checkedIds.join(',')),
                    credentials: 'same-origin'
                }).then(res => res.json ? res.json() : res.text()).then(data => {
                    showActionResultModal('批量删除成功', 'success', () => location.reload());
                }).catch(() => {
                    showActionResultModal('批量删除失败', 'danger');
                    batchDeleteBtn.disabled = false;
                    batchDeleteBtn.textContent = '批量删除';
                });
            });
        };
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[id^=expandBtn-]').forEach(function(expandBtn) {
            const apiId = expandBtn.id.replace('expandBtn-', '');
            const group = document.getElementById('imageListGroup-' + apiId);
            const allItems = window.allApiImages[apiId] || [];
            let expanded = false;
            expandBtn.addEventListener('click', function() {
                if (!expanded) {
                    group.innerHTML = '';
                    allItems.forEach(function(image) {
                        group.innerHTML += `<div class="list-group-item d-flex justify-content-between align-items-center">
                            <div class="form-check me-2"><input class="form-check-input batch-checkbox-${apiId}" type="checkbox" name="ids[]" value="${image.id}"></div>
                            <div class="flex-grow-1 text-break">${image.url.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</div>
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-outline-primary btn-sm me-1 preview-btn" data-url="${image.url.replace(/'/g,'&#39;')}">预览</button>
                                <form method="POST" class="d-inline" autocomplete="off">
                                    <input type="hidden" name="action" value="delete_image">
                                    <input type="hidden" name="image_id" value="${image.id}">
                                    <button type="submit" class="btn btn-danger btn-sm">删除</button>
                                </form>
                            </div>
                        </div>`;
                    });
                    expandBtn.textContent = '收起';
                    expanded = true;
                    bindBatchEvents(apiId); // 关键：每次渲染后绑定事件
                } else {
                    group.innerHTML = '';
                    expandBtn.textContent = `展开全部 (${allItems.length})`;
                    expanded = false;
                }
            });
        });
        // 页面初始时也绑定一次（如果有默认展开的情况）
        <?php foreach ($apis as $api): ?>
        bindBatchEvents(<?php echo $api['id']; ?>);
        <?php endforeach; ?>
    });
    </script>
    <script>
    function showActionResultModal(message, type = 'success', callback) {
        let icon = {
            success: "<i class='bi bi-check-circle-fill text-success'></i>",
            danger: "<i class='bi bi-x-circle-fill text-danger'></i>",
            warning: "<i class='bi bi-exclamation-circle-fill text-warning'></i>",
            info: "<i class='bi bi-info-circle-fill text-info'></i>"
        }[type] || '';
        document.getElementById('actionResultModalBody').innerHTML =
            `<div class='fs-4 mb-2'>${icon} ${message}</div>`;
        var modal = new bootstrap.Modal(document.getElementById('actionResultModal'));
        modal.show();
        if (callback) {
            document.getElementById('actionResultModal').addEventListener('hidden.bs.modal', function handler() {
                document.getElementById('actionResultModal').removeEventListener('hidden.bs.modal', handler);
                callback();
            });
        }
    }
    function showActionConfirmModal(message, onConfirm) {
        document.getElementById('actionConfirmModalBody').innerHTML = `<div class='fs-5 mb-2'>${message}</div>`;
        var modal = new bootstrap.Modal(document.getElementById('actionConfirmModal'));
        modal.show();
        const okBtn = document.getElementById('actionConfirmOkBtn');
        okBtn.onclick = null;
        okBtn.onclick = function() {
            modal.hide();
            if (onConfirm) onConfirm();
        };
    }
    </script>
    <script>
    // 修正事件委托，确保所有动态生成的form删除都能弹出自定义确认弹窗
    // 统一在document上事件委托，判断form[action=delete_image]
    document.addEventListener('submit', function(e) {
        if (e.target && e.target.matches('form') && e.target.querySelector('input[name="action"][value="delete_image"]')) {
            e.preventDefault();
            showActionConfirmModal('确定要删除这张图片吗？', function() {
                const form = e.target;
                const formData = new FormData(form);
                fetch(window.location.pathname, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'fetch' },
                    body: new URLSearchParams(formData),
                    credentials: 'same-origin'
                }).then(res => res.json()).then(data => {
                    if (data.status === 'success') {
                        showActionResultModal('删除成功', 'success', () => window.location.reload());
                    } else {
                        showActionResultModal('删除失败', 'danger');
                    }
                });
            });
        }
    });
    </script>
    <script>
    document.getElementById('changePwdBtn').onclick = function() {
        var modal = new bootstrap.Modal(document.getElementById('changePwdModal'));
        modal.show();
    };
    document.getElementById('changePwdForm').onsubmit = function(e) {
        e.preventDefault();
        const form = this;
        const formData = new FormData(form);
        formData.append('action', 'change_password');
        fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'X-Requested-With': 'fetch' },
            body: new URLSearchParams(formData),
            credentials: 'same-origin'
        }).then(res => res.json()).then(data => {
            // 先关闭修改密码弹窗
            var modal = bootstrap.Modal.getInstance(document.getElementById('changePwdModal'));
            if (modal) modal.hide();
            setTimeout(function() {
                if (data.status === 'success') {
                    showActionResultModal('密码修改成功，请重新登录！', 'success', () => { window.location.href = 'logout.php'; });
                } else {
                    showActionResultModal(data.msg || '密码修改失败', 'danger');
                }
            }, 300);
        });
    };
    </script>
    <script>
    // 密码小眼睛切换明文/密文
    function bindPwdToggle() {
        document.querySelectorAll('#changePwdModal .toggle-pwd').forEach(function(btn) {
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
    }
    document.getElementById('changePwdBtn').onclick = function() {
        var modal = new bootstrap.Modal(document.getElementById('changePwdModal'));
        modal.show();
        setTimeout(bindPwdToggle, 100); // 确保弹窗渲染后绑定
    };
    </script>
    <script>
    // 密码强度校验
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
    bindPwdCheck('#changePwdModal input[name="new_password"]', '#changePwdTip');
    </script>
</body>
</html> 