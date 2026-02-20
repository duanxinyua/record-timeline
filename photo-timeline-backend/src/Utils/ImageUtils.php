<?php
namespace App\Utils;

class ImageUtils {
    /**
     * 创建缩略图
     */
    public static function createThumbnail($src, $dest, $maxWidth, $quality) {
        $size = @getimagesize($src);
        if ($size === false) return false;

        list($width, $height, $type) = $size;

        if ($width > $maxWidth) {
            $ratio = $width / $height;
            $newWidth = $maxWidth;
            $newHeight = intval($maxWidth / $ratio);
        } else {
            $newWidth = $width;
            $newHeight = $height;
        }

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
    public static function buildUploadUrl($filename, $baseUrl) {
        if (!empty($baseUrl)) {
            return rtrim($baseUrl, '/') . '/uploads/' . $filename;
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
        $basePath = ($scriptDir === '/' || $scriptDir === '\\') ? '' : $scriptDir;

        return $protocol . $host . $basePath . '/uploads/' . $filename;
    }

    /**
     * 解析分数字符串（如 "355/10"）
     */
    public static function evalFraction($val) {
        if (is_numeric($val)) return floatval($val);
        if (!is_string($val)) return 0;
        $parts = explode('/', $val);
        if (count($parts) == 1) return floatval($parts[0]);
        $denominator = floatval($parts[1]);
        if ($denominator == 0) return 0;
        return floatval($parts[0]) / $denominator;
    }

    /**
     * GPS DMS 坐标转十进制
     */
    public static function gpsToDecimal($coords, $ref) {
        if (!is_array($coords) || count($coords) < 3) return null;

        $d = self::evalFraction($coords[0]);
        $m = self::evalFraction($coords[1]);
        $s = self::evalFraction($coords[2]);

        $decimal = $d + ($m / 60) + ($s / 3600);

        if (in_array(strtoupper($ref), ['S', 'W'])) {
            $decimal *= -1;
        }

        return $decimal;
    }

    /**
     * 删除上传的文件（原图和缩略图）
     */
    public static function deleteUploadedFile($url, $uploadDir) {
        if (!$url) return;

        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['path'])) return;

        $filename = basename($parsed['path']);
        $filepath = rtrim($uploadDir, '/') . '/' . $filename;

        if (file_exists($filepath)) {
            @unlink($filepath);
        }
    }
}
