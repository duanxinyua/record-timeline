<?php
// config.php
// 本地运行配置（生产环境请使用环境变量覆盖）

// ---- 自动加载 .env 文件（如果存在） ----
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        // 跳过注释行
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        // 只处理 KEY=VALUE 格式
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            // 如果系统环境变量中已经存在，则不覆盖
            if (getenv($name) === false) {
                putenv("$name=$value");
            }
        }
    }
}
// ---- .env 加载结束 ----
$envBool = function ($name, $default = false) {
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }

    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed === null ? $default : $parsed;
};

$rawCorsOrigins = getenv('PEANUT_CORS_ALLOWED_ORIGINS');
$corsAllowedOrigins = $rawCorsOrigins
    ? array_values(array_filter(array_map('trim', explode(',', $rawCorsOrigins))))
    : ['http://localhost:5173', 'http://127.0.0.1:5173', 'http://localhost:3000', 'https://hetao.us', 'https://admin.hetao.us', 'https://duanxinyu.hetao.us'];

return [
    // API 密钥（生产环境请通过 PEANUT_API_SECRET 注入）
    'api_secret' => getenv('PEANUT_API_SECRET') ?: 'peanut-change-me',

    // 是否为生产环境
    'production' => $envBool('PEANUT_PRODUCTION', false),

    // 数据库文件路径（相对于后端目录）
    'db_file' => getenv('PEANUT_DB_FILE') ?: (__DIR__ . '/timeline.db'),

    // 上传目录路径
    'upload_dir' => getenv('PEANUT_UPLOAD_DIR') ?: (__DIR__ . '/uploads'),

    // 对外访问基准 URL（生产建议配置，如 https://api.example.com）
    'base_url' => rtrim(getenv('PEANUT_BASE_URL') ?: '', '/'),

    // 允许跨域来源（逗号分隔环境变量：PEANUT_CORS_ALLOWED_ORIGINS）
    'cors_allowed_origins' => $corsAllowedOrigins,

    // 外部 HTTPS 请求是否校验证书
    'ssl_verify' => $envBool('PEANUT_SSL_VERIFY', true),

    // 缩略图最大宽度
    'thumb_max_width' => (int)(getenv('PEANUT_THUMB_MAX_WIDTH') ?: 800),

    // 缩略图质量 (1-100)
    'thumb_quality' => (int)(getenv('PEANUT_THUMB_QUALITY') ?: 60),

    // 高德地图 Web Service API Key（用于坐标转地址）
    'amap_key' => getenv('PEANUT_AMAP_KEY') ?: '',
];
