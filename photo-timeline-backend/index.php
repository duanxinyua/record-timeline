<?php
// index.php
// API 路由器和控制器

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, x-api-key");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 加载配置
$config = require __DIR__ . '/config.php';

// 禁用错误输出
ini_set('display_errors', 0);
error_reporting(E_ALL);

// 自定义错误处理器（JSON 格式）
function jsonErrorHandler($errno, $errstr, $errfile, $errline) {
    global $config;
    http_response_code(500);
    if (!empty($config['production'])) {
        echo json_encode(['error' => '服务器内部错误']);
        error_log("PHP Error ($errno): $errstr in $errfile:$errline");
    } else {
        echo json_encode(['error' => "服务器错误 ($errno): $errstr in $errfile:$errline"]);
    }
    exit;
}
set_error_handler("jsonErrorHandler");

// 处理致命错误
register_shutdown_function(function() {
    global $config;
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

require_once 'db.php';

// 路由解析
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

// ==================== 工具函数 ====================

/**
 * 验证 API Key（仅通过 Header 传递）
 */
function verifyKey() {
    global $config;
    $key = null;

    // 1. 通过 $_SERVER（Nginx/FastCGI 标准方式）
    if (isset($_SERVER['HTTP_X_API_KEY'])) {
        $key = $_SERVER['HTTP_X_API_KEY'];
    }
    // 2. 通过 getallheaders（Apache）
    elseif (function_exists('getallheaders')) {
        $headers = getallheaders();
        $headers = array_change_key_case($headers, CASE_LOWER);
        if (isset($headers['x-api-key'])) {
            $key = $headers['x-api-key'];
        }
    }

    if (!$key || !hash_equals($config['api_secret'], $key)) {
        http_response_code(403);
        echo json_encode(["detail" => "无效或缺失的 API Key"]);
        exit();
    }
}

/**
 * 获取 JSON 请求体
 */
function getJsonBody() {
    return json_decode(file_get_contents('php://input'), true);
}

/**
 * GPS DMS 坐标转十进制
 */
function gpsToDecimal($coords, $ref) {
    if (!is_array($coords) || count($coords) < 3) return null;

    $d = evalFraction($coords[0]);
    $m = evalFraction($coords[1]);
    $s = evalFraction($coords[2]);

    $decimal = $d + ($m / 60) + ($s / 3600);

    if (in_array(strtoupper($ref), ['S', 'W'])) {
        $decimal *= -1;
    }

    return $decimal;
}

/**
 * 解析分数字符串（如 "355/10"）
 */
function evalFraction($str) {
    if (!is_string($str)) return 0;
    $parts = explode('/', $str);
    if (count($parts) == 1) return floatval($parts[0]);
    $denominator = floatval($parts[1]);
    if ($denominator == 0) return 0;
    return floatval($parts[0]) / $denominator;
}

/**
 * 创建缩略图
 */
function createThumbnail($src, $dest, $maxWidth = null) {
    global $config;
    $maxWidth = $maxWidth ?? $config['thumb_max_width'];
    $quality = $config['thumb_quality'];

    $size = @getimagesize($src);
    if ($size === false) return false;

    list($width, $height, $type) = $size;

    // 计算新尺寸
    if ($width > $maxWidth) {
        $ratio = $width / $height;
        $newWidth = $maxWidth;
        $newHeight = intval($maxWidth / $ratio);
    } else {
        $newWidth = $width;
        $newHeight = $height;
    }

    // 创建源图资源
    $source = null;
    switch ($type) {
        case IMAGETYPE_JPEG: $source = @imagecreatefromjpeg($src); break;
        case IMAGETYPE_PNG:  $source = @imagecreatefrompng($src); break;
        case IMAGETYPE_GIF:  $source = @imagecreatefromgif($src); break;
        case IMAGETYPE_WEBP:
            if (function_exists('imagecreatefromwebp')) {
                $source = @imagecreatefromwebp($src);
            }
            break;
        case IMAGETYPE_BMP:
            if (function_exists('imagecreatefrombmp')) {
                $source = @imagecreatefrombmp($src);
            }
            break;
    }

    if (!$source) return false;

    $thumb = imagecreatetruecolor($newWidth, $newHeight);
    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    imagejpeg($thumb, $dest, $quality);

    imagedestroy($source);
    imagedestroy($thumb);

    return true;
}

/**
 * 构建上传文件的完整 URL
 */
function buildUploadUrl($filename) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];

    // 自动检测脚本路径，支持子目录部署
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    $basePath = ($scriptDir === '/' || $scriptDir === '\\') ? '' : $scriptDir;

    return $protocol . $host . $basePath . '/uploads/' . $filename;
}

/**
 * 删除上传的文件（原图和缩略图）
 */
function deleteUploadedFile($url) {
    if (!$url) return;

    // 从 URL 中提取文件名
    $parsed = parse_url($url);
    if (!$parsed || !isset($parsed['path'])) return;

    $filename = basename($parsed['path']);
    $filepath = __DIR__ . '/uploads/' . $filename;

    if (file_exists($filepath)) {
        @unlink($filepath);
    }
}

// ==================== 路由 ====================

// GET /
if ($uri === '/' || $uri === '/index.php') {
    echo json_encode(["message" => "Peanut Timeline Backend (PHP) is Running!"]);
    exit();
}

// POST /upload
if ($uri === '/upload' && $method === 'POST') {
    verifyKey();

    if (!isset($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(["detail" => "没有上传文件"]);
        exit();
    }

    $file = $_FILES['file'];

    // MIME 类型白名单
    $mime_map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/bmp' => 'bmp',
        'image/tiff' => 'tiff',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
        'video/x-msvideo' => 'avi',
        'video/3gpp' => '3gp',
        'video/x-m4v' => 'm4v',
    ];

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_exts = array_unique(array_values($mime_map));

    // 扩展名不在白名单中，尝试 MIME 检测
    if (empty($ext) || !in_array($ext, $allowed_exts)) {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (isset($mime_map[$mime])) {
                $ext = $mime_map[$mime];
            } else {
                http_response_code(400);
                echo json_encode(["detail" => "不支持的文件类型: $mime"]);
                exit();
            }
        } else {
            http_response_code(400);
            echo json_encode(["detail" => "不支持的文件类型: .$ext"]);
            exit();
        }
    }

    // 使用时间戳+随机数命名，保证唯一性
    $baseId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $newFilename = $baseId . '.' . $ext;
    $targetPath = __DIR__ . '/uploads/' . $newFilename;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $url = buildUploadUrl($newFilename);

        // 提取 EXIF 信息
        $exifData = [];
        if (in_array($ext, ['jpg', 'jpeg']) && function_exists('exif_read_data')) {
            $exif = @exif_read_data($targetPath);
            if ($exif) {
                if (isset($exif['DateTimeOriginal'])) {
                    $exifData['date'] = $exif['DateTimeOriginal'];
                }
                if (isset($exif['GPSLatitude'], $exif['GPSLatitudeRef'],
                           $exif['GPSLongitude'], $exif['GPSLongitudeRef'])) {
                    $exifData['latitude'] = gpsToDecimal($exif['GPSLatitude'], $exif['GPSLatitudeRef']);
                    $exifData['longitude'] = gpsToDecimal($exif['GPSLongitude'], $exif['GPSLongitudeRef']);
                }
            }
        }

        // 生成缩略图（仅图片类型，文件名与原图关联）
        $thumbUrl = null;
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])) {
            $thumbFilename = $baseId . '_thumb.jpg';
            $thumbPath = __DIR__ . '/uploads/' . $thumbFilename;
            if (createThumbnail($targetPath, $thumbPath)) {
                $thumbUrl = buildUploadUrl($thumbFilename);
            }
        }

        echo json_encode([
            "url" => $url,
            "thumb" => $thumbUrl,
            "filename" => $file['name'],
            "exif" => $exifData
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["detail" => "文件保存失败"]);
    }
    exit();
}

// GET /items/
if (($uri === '/items/' || $uri === '/items') && $method === 'GET') {
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 0;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;

    if ($page > 0 && $limit > 0) {
        $offset = ($page - 1) * $limit;
        $stmt = $pdo->prepare("SELECT * FROM timelineitem ORDER BY date DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = $stmt->fetchAll();

        // 返回总数以支持前端判断分页
        $countStmt = $pdo->query("SELECT COUNT(*) FROM timelineitem");
        $total = (int)$countStmt->fetchColumn();

        echo json_encode([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit
        ]);
    } else {
        // 无分页时也统一使用 DESC 排序
        $stmt = $pdo->query("SELECT * FROM timelineitem ORDER BY date DESC");
        echo json_encode($stmt->fetchAll());
    }
    exit();
}

// POST /items/
if (($uri === '/items/' || $uri === '/items') && $method === 'POST') {
    verifyKey();
    $data = getJsonBody();

    if (!$data || !isset($data['date']) || !isset($data['src'])) {
        http_response_code(400);
        echo json_encode(["detail" => "缺少必填字段 (date, src)"]);
        exit();
    }

    $stmt = $pdo->prepare("INSERT INTO timelineitem (title, date, src, thumb, latitude, longitude, taken_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $data['title'] ?? '',
        $data['date'],
        $data['src'],
        $data['thumb'] ?? null,
        $data['latitude'] ?? null,
        $data['longitude'] ?? null,
        $data['taken_at'] ?? null
    ]);

    $id = $pdo->lastInsertId();
    $data['id'] = (int)$id;
    echo json_encode($data);
    exit();
}

// DELETE /items/{id}
if (preg_match('#^/items/(\d+)$#', $uri, $matches) && $method === 'DELETE') {
    verifyKey();
    $id = $matches[1];

    // 先查询文件路径，以便删除文件
    $queryStmt = $pdo->prepare("SELECT src, thumb FROM timelineitem WHERE id = ?");
    $queryStmt->execute([$id]);
    $item = $queryStmt->fetch();

    if (!$item) {
        http_response_code(404);
        echo json_encode(["detail" => "条目不存在"]);
        exit();
    }

    // 删除数据库记录
    $deleteStmt = $pdo->prepare("DELETE FROM timelineitem WHERE id = ?");
    $deleteStmt->execute([$id]);

    // 删除关联的文件（原图和缩略图）
    deleteUploadedFile($item['src']);
    deleteUploadedFile($item['thumb']);

    echo json_encode(["ok" => true]);
    exit();
}

// GET /config
if (($uri === '/config' || $uri === '/config/') && $method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM appconfig LIMIT 1");
    $config_data = $stmt->fetch();
    echo json_encode($config_data ?: []);
    exit();
}

// POST /config
if (($uri === '/config' || $uri === '/config/') && $method === 'POST') {
    verifyKey();
    $data = getJsonBody();

    // 白名单字段
    $fields = ['appTitle', 'kicker', 'mainTitle', 'subTitle', 'timelineTitle', 'emptyText', 'defaultItemTitle', 'unknownDateText', 'pageSize'];

    $setClauses = [];
    $params = [];
    foreach ($fields as $field) {
        if (isset($data[$field])) {
            $setClauses[] = "$field = ?";
            $params[] = $data[$field];
        }
    }

    if (count($setClauses) > 0) {
        $sql = "UPDATE appconfig SET " . implode(', ', $setClauses) . " WHERE id = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    // 返回更新后的配置
    $stmt = $pdo->query("SELECT * FROM appconfig LIMIT 1");
    echo json_encode($stmt->fetch());
    exit();
}

// 404
http_response_code(404);
echo json_encode(["detail" => "接口不存在"]);
exit();
