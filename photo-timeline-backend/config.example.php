<?php
// config.example.php
// 复制此文件为 config.php 后再按实际环境修改

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
    : ['http://localhost:5173', 'http://127.0.0.1:5173'];

return [
    'api_secret' => getenv('PEANUT_API_SECRET') ?: 'replace-with-strong-secret',
    'production' => $envBool('PEANUT_PRODUCTION', false),
    'db_file' => getenv('PEANUT_DB_FILE') ?: (__DIR__ . '/timeline.db'),
    'upload_dir' => getenv('PEANUT_UPLOAD_DIR') ?: (__DIR__ . '/uploads'),
    'base_url' => rtrim(getenv('PEANUT_BASE_URL') ?: '', '/'),
    'cors_allowed_origins' => $corsAllowedOrigins,
    'ssl_verify' => $envBool('PEANUT_SSL_VERIFY', true),
    'thumb_max_width' => (int)(getenv('PEANUT_THUMB_MAX_WIDTH') ?: 800),
    'thumb_quality' => (int)(getenv('PEANUT_THUMB_QUALITY') ?: 60),
    'amap_key' => getenv('PEANUT_AMAP_KEY') ?: '',
];
