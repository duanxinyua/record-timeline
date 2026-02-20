<?php
// index.php
// 极简 API 路由器和入口点

// 引入原生自动加载器
require_once __DIR__ . '/src/autoload.php';

use App\Utils\HttpUtils;
use App\Models\TimelineItem;
use App\Models\AppConfig;
use App\Controllers\ItemController;
use App\Controllers\UploadController;
use App\Controllers\ConfigController;

// 加载配置
$config = require __DIR__ . '/config.php';

// ==================== CORS 与错误处理 ====================
HttpUtils::setCorsHeaders($config);

// 禁用错误输出，设置自定义 JSON 错误处理器
ini_set('display_errors', 0);
error_reporting(E_ALL);

set_error_handler(function($errno, $errstr, $errfile, $errline) use ($config) {
    http_response_code(500);
    if (!empty($config['production'])) {
        echo json_encode(['error' => '服务器内部错误']);
        error_log("PHP Error ($errno): $errstr in $errfile:$errline");
    } else {
        echo json_encode(['error' => "服务器错误 ($errno): $errstr in $errfile:$errline"]);
    }
    exit;
});

register_shutdown_function(function() use ($config) {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        if (!empty($config['production'])) {
            echo json_encode(['error' => '服务器内部错误']);
            error_log("Fatal Error: {$error['message']} in {$error['file']}:{$error['line']}");
        } else {
            echo json_encode(['error' => "致命错误: {$error['message']} in {$error['file']}:{$error['line']}"]);
        }
    }
});

// 加载数据库连接
require_once __DIR__ . '/db.php';

// ==================== 路由解析与防呆验证 ====================
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// 处理静态文件（开发环境 php -S 使用）
if (preg_match('/\.(?:png|jpg|jpeg|gif|webp|bmp|mp4|mov|webm)$/', $uri)) {
    $file = __DIR__ . $uri;
    if (file_exists($file)) {
        $mimeTypes = [
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
            'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm'
        ];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        readfile($file);
        exit;
    }
}

/**
 * 验证 API Key
 */
function verifyKey($config) {
    $key = null;
    if (isset($_SERVER['HTTP_X_API_KEY'])) {
        $key = $_SERVER['HTTP_X_API_KEY'];
    } elseif (function_exists('getallheaders')) {
        $headers = array_change_key_case(getallheaders(), CASE_LOWER);
        if (isset($headers['x-api-key'])) {
            $key = $headers['x-api-key'];
        }
    }

    if (!$key || !hash_equals($config['api_secret'], $key)) {
        HttpUtils::jsonResponse(["detail" => "无效或缺失的 API Key"], 403);
    }
}

// 根目录保活检测
if ($uri === '/' || $uri === '/index.php') {
    HttpUtils::jsonResponse(["message" => "Peanut Timeline Backend (PHP refactored) is Running!"]);
}

// ============== 实例化核心组件 ==============
$itemModel = new TimelineItem($pdo);
$configModel = new AppConfig($pdo);

$itemController = new ItemController($itemModel, $config);
$uploadController = new UploadController($config);
$configController = new ConfigController($configModel);

// ============== 路由分发器 ==============

// 白名单无需 Token 或其他特殊处理可在此过滤
// 其他全部通过 verifyKey 检查
verifyKey($config);

if ($uri === '/verify-key' && $method === 'GET') {
    HttpUtils::jsonResponse(["ok" => true]);
}

// 业务路由
if ($uri === '/upload' && $method === 'POST') {
    $uploadController->handleUpload();
}
elseif (($uri === '/items/' || $uri === '/items') && $method === 'GET') {
    $itemController->getList();
}
elseif (($uri === '/items/' || $uri === '/items') && $method === 'POST') {
    $itemController->create();
}
elseif (preg_match('#^/items/(\d+)$#', $uri, $matches) && $method === 'PUT') {
    $itemController->update($matches[1]);
}
elseif (preg_match('#^/items/(\d+)$#', $uri, $matches) && $method === 'DELETE') {
    $itemController->delete($matches[1]);
}
elseif (preg_match('#^/items/(\d+)/restore$#', $uri, $matches) && $method === 'POST') {
    $itemController->restore($matches[1]);
}
elseif (preg_match('#^/items/(\d+)/permanent$#', $uri, $matches) && $method === 'DELETE') {
    $itemController->permanentDelete($matches[1]);
}
elseif (($uri === '/trash' || $uri === '/trash/') && $method === 'GET') {
    $itemController->getTrash();
}
elseif (($uri === '/empty-trash' || $uri === '/empty-trash/') && $method === 'POST') {
    $itemController->emptyTrash();
}
elseif (($uri === '/clear-addresses' || $uri === '/clear-addresses/') && $method === 'POST') {
    $itemController->clearAddresses();
}
elseif (($uri === '/config' || $uri === '/config/') && $method === 'GET') {
    $configController->getConfig();
}
elseif (($uri === '/config' || $uri === '/config/') && $method === 'POST') {
    $configController->updateConfig();
}
else {
    HttpUtils::jsonResponse(["detail" => "接口不存在"], 404);
}
