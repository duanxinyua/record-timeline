<?php
// 诊断文件 - 用于检测后端环境是否正常
// 访问方式：https://api.hetao.us/check.php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");

$result = [
    'status' => 'ok',
    'php_version' => PHP_VERSION,
    'time' => date('Y-m-d H:i:s'),
    'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
];

// 检查 config.php 是否存在
$result['config_exists'] = file_exists(__DIR__ . '/config.php');

// 检查 .env 是否存在
$result['env_exists'] = file_exists(__DIR__ . '/.env');

// 检查 db.php 是否存在
$result['db_exists'] = file_exists(__DIR__ . '/db.php');

// 检查 timeline.db 是否存在
$result['database_exists'] = file_exists(__DIR__ . '/timeline.db');

// 检查 uploads 目录
$result['uploads_dir_exists'] = is_dir(__DIR__ . '/uploads');
$result['uploads_dir_writable'] = is_writable(__DIR__ . '/uploads');

// 检查 SQLite 扩展
$result['sqlite_available'] = extension_loaded('pdo_sqlite');

// 尝试加载 config.php
try {
    $config = require __DIR__ . '/config.php';
    $result['config_loaded'] = true;
    $result['api_secret_set'] = !empty($config['api_secret']) && $config['api_secret'] !== 'peanut-change-me';
    $result['cors_origins'] = $config['cors_allowed_origins'] ?? 'not set';
} catch (Throwable $e) {
    $result['config_loaded'] = false;
    $result['config_error'] = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
