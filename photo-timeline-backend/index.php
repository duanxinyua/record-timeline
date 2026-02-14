<?php
// index.php
// Single file API router and controller.

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, x-api-key");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php';

// Simple Router
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Handle static files if built-in server is used (php -S)
if (preg_match('/\.(?:png|jpg|jpeg|gif)$/', $uri)) {
    $file = __DIR__ . $uri; // e.g. /uploads/xxx
    if (file_exists($file)) {
        header('Content-Type: image/' . pathinfo($file, PATHINFO_EXTENSION));
        readfile($file);
        exit;
    }
}

// Config
$API_SECRET = "peanut2024";

// Helper: Verify API Key
function verifyKey() {
    global $API_SECRET;
    $headers = getallheaders();
    $key = isset($headers['x-api-key']) ? $headers['x-api-key'] : (isset($headers['X-Api-Key']) ? $headers['X-Api-Key'] : null);
    
    if ($key !== $API_SECRET) {
        http_response_code(403);
        echo json_encode(["detail" => "Invalid or missing API Key"]);
        exit();
    }
}

// Helper: Get JSON Body
function getJsonBody() {
    return json_decode(file_get_contents('php://input'), true);
}

// Helper: Convert GPS coordinates to decimal
function gpsToDecimal($coord, $hemi) {
    if (!empty($coord)) {
        $degrees = count($coord) > 0 ? gps2Num($coord[0]) : 0;
        $minutes = count($coord) > 1 ? gps2Num($coord[1]) : 0;
        $seconds = count($coord) > 2 ? gps2Num($coord[2]) : 0;
        $flip = ($hemi == 'W' || $hemi == 'S') ? -1 : 1;
        return $flip * ($degrees + ($minutes / 60) + ($seconds / 3600));
    }
    return null;
}

function gps2Num($coordPart) {
    $parts = explode('/', $coordPart);
    if (count($parts) <= 0) return 0;
    if (count($parts) == 1) return $parts[0];
    return floatval($parts[0]) / floatval($parts[1]);
}

// --- Routes ---

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
        echo json_encode(["detail" => "No file uploaded"]);
        exit();
    }

    $file = $_FILES['file'];
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if (!in_array(strtolower($ext), $allowed)) {
         http_response_code(400);
         echo json_encode(["detail" => "File must be an image"]);
         exit();
    }

    $newFilename = uniqid() . '.' . $ext;
    $targetPath = __DIR__ . '/uploads/' . $newFilename;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        // Build URL
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'];
        // Assume /uploads is served directly
        $url = $protocol . $host . '/uploads/' . $newFilename;

        // Extract EXIF
        $exifData = [];
        if (in_array(strtolower($ext), ['jpg', 'jpeg'])) {
            $exif = @exif_read_data($targetPath);
            if ($exif) {
                if (isset($exif['DateTimeOriginal'])) {
                    $exifData['date'] = $exif['DateTimeOriginal'];
                }
                if (isset($exif['GPSLatitude']) && isset($exif['GPSLatitudeRef']) && 
                    isset($exif['GPSLongitude']) && isset($exif['GPSLongitudeRef'])) {
                    $lat = gpsToDecimal($exif['GPSLatitude'], $exif['GPSLatitudeRef']);
                    $lon = gpsToDecimal($exif['GPSLongitude'], $exif['GPSLongitudeRef']);
                    $exifData['latitude'] = $lat;
                    $exifData['longitude'] = $lon;
                }
            }
        }
        
        echo json_encode([
            "url" => $url,
            "filename" => $file['name'],
            "exif" => $exifData
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["detail" => "Failed to save file"]);
    }
    exit();
}

// GET /items/
if (($uri === '/items/' || $uri === '/items') && $method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM timelineitem ORDER BY date ASC");
    echo json_encode($stmt->fetchAll());
    exit();
}

// POST /items/
if (($uri === '/items/' || $uri === '/items') && $method === 'POST') {
    verifyKey();
    $data = getJsonBody();
    
    if (!$data || !isset($data['date']) || !isset($data['src'])) {
        http_response_code(400);
        echo json_encode(["detail" => "Missing required fields"]);
        exit();
    }

    $stmt = $pdo->prepare("INSERT INTO timelineitem (title, date, src, latitude, longitude, taken_at) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $data['title'] ?? '',
        $data['date'],
        $data['src'],
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
    
    $stmt = $pdo->prepare("DELETE FROM timelineitem WHERE id = ?");
    $stmt->execute([$id]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(["ok" => true]);
    } else {
        http_response_code(404);
        echo json_encode(["detail" => "Item not found"]);
    }
    exit();
}

// GET /config
if (($uri === '/config' || $uri === '/config/') && $method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM appconfig LIMIT 1");
    $config = $stmt->fetch();
    if (!$config) {
        // Should be seeded in db.php but just in case
        echo json_encode([]); 
    } else {
        // Convert numeric keys if any (PDO::FETCH_ASSOC handles this though)
        echo json_encode($config);
    }
    exit();
}

// POST /config
if (($uri === '/config' || $uri === '/config/') && $method === 'POST') {
    verifyKey();
    $data = getJsonBody();
    
    // We update row 1
    $sql = "UPDATE appconfig SET ";
    $params = [];
    $fields = ['kicker', 'mainTitle', 'subTitle', 'timelineTitle', 'emptyText', 'defaultItemTitle', 'unknownDateText'];
    
    foreach ($fields as $field) {
        if (isset($data[$field])) {
            $sql .= "$field = ?, ";
            $params[] = $data[$field];
        }
    }
    
    $sql = rtrim($sql, ", ");
    $sql .= " WHERE id = 1";
    
    if (count($params) > 0) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
    
    // Return updated config
    $stmt = $pdo->query("SELECT * FROM appconfig LIMIT 1");
    echo json_encode($stmt->fetch());
    exit();
}

// 404
http_response_code(404);
echo json_encode(["detail" => "Not Found"]);
exit();
