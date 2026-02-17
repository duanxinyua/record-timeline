<?php
// regen_thumbs.php
// Script to regenerate thumbnails for existing images in the database.
// Usage: Visit http://your-domain/regen_thumbs.php?key=YOUR_API_KEY

header("Content-Type: text/html; charset=UTF-8");
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(0); // Allow long running for many images

require_once 'db.php';

// Config
$API_SECRET = "peanut2026"; // Must match index.php

// 1. Verify Key (from GET param for browser ease)
$key = $_GET['key'] ?? '';
if ($key !== $API_SECRET) {
    die("Error: Invalid API Key. Usage: ?key=YOUR_SECRET_KEY");
}

echo "<h1>Thumbnail Regeneration</h1>";
echo "<pre>";

// ---------------- COPY OF createThumbnail FUNCTION ----------------
function createThumbnail($src, $dest, $maxWidth=800) {
    // Get image dimensions 
    $size = @getimagesize($src);
    if ($size === false) return false;
    
    list($width, $height, $type) = $size;
    
    // Calculate new dimensions
    $ratio = $width / $height;
    if ($width > $maxWidth) {
        $newWidth = $maxWidth;
        $newHeight = $maxWidth / $ratio;
    } else {
        $newWidth = $width;
        $newHeight = $height;
    }
    
    // Create source image resource
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
    
    // Create destination image
    $thumb = imagecreatetruecolor($newWidth, $newHeight);
    
    // Resize with resampling
    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    // Save as JPEG (quality 60)
    imagejpeg($thumb, $dest, 60);
    
    // Clean up
    imagedestroy($source);
    imagedestroy($thumb);
    
    return true;
}
// ------------------------------------------------------------------

// 2. Fetch all items without a thumbnail
// Note: We also fetch items where thumb is NULL or empty string
$stmt = $pdo->query("SELECT * FROM timelineitem WHERE thumb IS NULL OR thumb = ''");
$items = $stmt->fetchAll();

$count = count($items);
echo "Found $count items missing thumbnails.\n\n";

if ($count === 0) {
    echo "All items have thumbnails. Nothing to do.\n";
    echo "</pre>";
    exit;
}

$success = 0;
$fail = 0;
$skipped = 0;

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];

foreach ($items as $item) {
    $id = $item['id'];
    $srcUrl = $item['src']; // e.g. http://api.hetao.us/uploads/file.jpg
    
    echo "Processing ID $id ... ";
    
    // Parse URL to get filename
    // Assumes src is like .../uploads/filename.ext
    $parts = explode('/uploads/', $srcUrl);
    if (count($parts) < 2) {
        echo "[SKIP] Cannot parse filename from URL: $srcUrl\n";
        $skipped++;
        continue;
    }
    
    $filename = $parts[1]; // e.g. file.jpg
    $localPath = __DIR__ . '/uploads/' . $filename;
    
    if (!file_exists($localPath)) {
        echo "[SKIP] File not found on disk: $localPath\n";
        $skipped++;
        continue;
    }
    
    // Check extension
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])) {
        echo "[SKIP] Not an image (video?): $filename\n";
        $skipped++;
        continue;
    }
    
    // Generate Thumb
    $thumbFilename = uniqid() . '_thumb.jpg';
    $thumbPath = __DIR__ . '/uploads/' . $thumbFilename;
    
    if (createThumbnail($localPath, $thumbPath)) {
        $thumbUrl = $protocol . $host . '/uploads/' . $thumbFilename;
        
        // Update DB
        $update = $pdo->prepare("UPDATE timelineitem SET thumb = ? WHERE id = ?");
        $update->execute([$thumbUrl, $id]);
        
        echo "[OK] Generated.\n";
        $success++;
    } else {
        echo "[FAIL] Could not generate thumbnail.\n";
        $fail++;
    }
    
    // Flush output buffer to show progress in browser
    flush();
    ob_flush();
}

echo "\n----------------------------------\n";
echo "Done.\n";
echo "Generated: $success\n";
echo "Failed:    $fail\n";
echo "Skipped:   $skipped\n";
echo "</pre>";
