<?php
// config.php
// 本地运行配置（生产环境请使用环境变量覆盖）

// ---- 自动加载 .env 文件（兼容禁用 putenv 的主机） ----
$_ENV_LOADED = [];
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $_ENV_LOADED[trim($name)] = trim($value);
        }
    }
}

// 安全的环境变量读取函数（不依赖 putenv）
function env($name, $default = null) {
    global $_ENV_LOADED;
    // 1. 优先从 .env 文件加载的值中读取
    if (isset($_ENV_LOADED[$name])) {
        return $_ENV_LOADED[$name];
    }
    // 2. 尝试 getenv（系统环境变量）
    $val = getenv($name);
    if ($val !== false && $val !== '') {
        return $val;
    }
    // 3. 尝试 $_ENV
    if (isset($_ENV[$name])) {
        return $_ENV[$name];
    }
    // 4. 尝试 $_SERVER
    if (isset($_SERVER[$name])) {
        return $_SERVER[$name];
    }
    return $default;
}
// ---- .env 加载结束 ----

$envBool = function ($name, $default = false) {
    $value = env($name);
    if ($value === null || $value === '') {
        return $default;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed === null ? $default : $parsed;
};

$rawCorsOrigins = env('PEANUT_CORS_ALLOWED_ORIGINS');
$corsAllowedOrigins = $rawCorsOrigins
    ? array_values(array_filter(array_map('trim', explode(',', $rawCorsOrigins))))
    : ['*'];

return [
    // API 密钥
    'api_secret' => env('PEANUT_API_SECRET', 'peanut-change-me'),

    // 是否为生产环境
    'production' => $envBool('PEANUT_PRODUCTION', false),

    // 数据库文件路径
    'db_file' => env('PEANUT_DB_FILE', __DIR__ . '/timeline.db'),

    // 上传目录路径
    'upload_dir' => env('PEANUT_UPLOAD_DIR', __DIR__ . '/uploads'),

    // 对外访问基准 URL
    'base_url' => rtrim(env('PEANUT_BASE_URL', ''), '/'),

    // 允许跨域来源
    'cors_allowed_origins' => $corsAllowedOrigins,

    // 外部 HTTPS 请求是否校验证书
    'ssl_verify' => $envBool('PEANUT_SSL_VERIFY', true),

    // 缩略图最大宽度
    'thumb_max_width' => (int)(env('PEANUT_THUMB_MAX_WIDTH', 800)),

    // 缩略图质量 (1-100)
    'thumb_quality' => (int)(env('PEANUT_THUMB_QUALITY', 60)),

    // 高德地图 Web Service API Key
    'amap_key' => env('PEANUT_AMAP_KEY', ''),
];
