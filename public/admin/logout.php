<?php
session_start();
require_once __DIR__ . '/../../config.php';

// 清除会话
unset($_SESSION[$admin_config['session_name']]);
session_destroy();

// 重定向到登录页面
header('Location: login.php');
exit; 