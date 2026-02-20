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
    : ['http://localhost:5173', 'http://127.0.0.1:5173', 'http://localhost:3000', 'https://hetao.us', 'https://admin.hetao.us', 'https://duanxinyu.hetao.us'];

    // ----------------------------------------------------------------------
    // 安全与核心配置
    // ----------------------------------------------------------------------

    // API 访问密钥（客户端需要通过 x-api-key 请求头传递此值）
    // 生产环境中强烈建议通过 PEANUT_API_SECRET 环境变量来动态注入，避免硬编码
    'api_secret' => getenv('PEANUT_API_SECRET') ?: 'replace-with-strong-secret',

    // 生产环境标记（true = 生产环境，false = 本地开发环境）
    'production' => $envBool('PEANUT_PRODUCTION', false),

    // SQLite 数据库文件的存放路径（默认为后端根目录下的 timeline.db）
    'db_file' => getenv('PEANUT_DB_FILE') ?: (__DIR__ . '/timeline.db'),

    // ----------------------------------------------------------------------
    // 资源与网络配置
    // ----------------------------------------------------------------------

    // 用户上传媒体文件（图片、视频）的存放物理路径
    'upload_dir' => getenv('PEANUT_UPLOAD_DIR') ?: (__DIR__ . '/uploads'),

    // 对外访问的基准 URL（用于拼接长传文件的绝对链接）
    // 线上生产环境强烈建议配置此项，如：https://api.example.com
    'base_url' => rtrim(getenv('PEANUT_BASE_URL') ?: '', '/'),

    // 跨域资源共享（CORS）允许的域名列表
    // 控制哪些前端域名可以调用本 API。支持通过 PEANUT_CORS_ALLOWED_ORIGINS 环境变量用逗号分隔配置
    'cors_allowed_origins' => $corsAllowedOrigins,

    // 是否在外部 HTTPS 请求（如调用外部API）时开启 SSL 证书校验
    // 默认开启。本地开发如有自签证书问题可考虑设为 false，生产环境严禁设为 false
    'ssl_verify' => $envBool('PEANUT_SSL_VERIFY', true),

    // ----------------------------------------------------------------------
    // 媒体处理与第三方服务
    // ----------------------------------------------------------------------

    // 生成的图片缩略图的最大宽度（单位：像素px）
    'thumb_max_width' => (int)(getenv('PEANUT_THUMB_MAX_WIDTH') ?: 800),

    // 缩略图生成的压缩质量 (1-100，100为最好质量且体积最大)
    'thumb_quality' => (int)(getenv('PEANUT_THUMB_QUALITY') ?: 60),

    // 高德地图 Web Service API Key
    // 用于自动将照片中提取的 GPS 经纬度坐标反解析为具体的中文地址（省市区街道）
    'amap_key' => getenv('PEANUT_AMAP_KEY') ?: '',
];
