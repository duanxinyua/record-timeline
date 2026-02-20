<?php
namespace App\Controllers;

use App\Utils\HttpUtils;
use App\Utils\ImageUtils;

class UploadController {
    protected $config;

    public function __construct($config) {
        $this->config = $config;
    }

    public function handleUpload() {
        if (!isset($_FILES['file'])) {
            HttpUtils::jsonResponse(["detail" => "没有上传文件"], 400);
        }

        $file = $_FILES['file'];
        if (!is_array($file)) {
            HttpUtils::jsonResponse(["detail" => "上传数据格式错误"], 400);
        }

        $uploadError = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
        if ($uploadError !== UPLOAD_ERR_OK) {
            $errorMap = [
                UPLOAD_ERR_INI_SIZE => '文件超过服务器允许大小',
                UPLOAD_ERR_FORM_SIZE => '文件超过表单允许大小',
                UPLOAD_ERR_PARTIAL => '文件上传不完整',
                UPLOAD_ERR_NO_FILE => '没有上传文件',
                UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
                UPLOAD_ERR_CANT_WRITE => '服务器写入文件失败',
                UPLOAD_ERR_EXTENSION => '文件上传被扩展拦截',
            ];
            HttpUtils::jsonResponse(["detail" => $errorMap[$uploadError] ?? ('上传失败，错误码: ' . $uploadError)], 400);
        }

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            HttpUtils::jsonResponse(["detail" => "上传源文件无效"], 400);
        }
        if (!isset($file['size']) || (int)$file['size'] <= 0) {
            HttpUtils::jsonResponse(["detail" => "上传文件为空"], 400);
        }

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
                    HttpUtils::jsonResponse(["detail" => "不支持的文件类型: $mime"], 400);
                }
            } else {
                HttpUtils::jsonResponse(["detail" => "不支持的文件类型: .$ext"], 400);
            }
        }

        // 使用时间戳+随机数命名，保证唯一性
        $baseId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $newFilename = $baseId . '.' . $ext;
        $targetPath = rtrim($this->config['upload_dir'], '/') . '/' . $newFilename;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $url = ImageUtils::buildUploadUrl($newFilename, $this->config['base_url']);

            // 提取 EXIF 信息
            $exifData = [];

            // 1. 服务端 EXIF 读取（支持 JPEG 和 TIFF）
            if (in_array($ext, ['jpg', 'jpeg', 'tiff', 'tif']) && function_exists('exif_read_data')) {
                $exif = @exif_read_data($targetPath, 'ANY_TAG', true);
                if ($exif) {
                    if (!empty($exif['EXIF']['DateTimeOriginal'])) {
                        $exifData['date'] = $exif['EXIF']['DateTimeOriginal'];
                    } elseif (!empty($exif['EXIF']['DateTimeDigitized'])) {
                        $exifData['date'] = $exif['EXIF']['DateTimeDigitized'];
                    } elseif (!empty($exif['IFD0']['DateTime'])) {
                        $exifData['date'] = $exif['IFD0']['DateTime'];
                    }
                    if (empty($exifData['date'])) {
                        if (!empty($exif['DateTimeOriginal'])) {
                            $exifData['date'] = $exif['DateTimeOriginal'];
                        } elseif (!empty($exif['DateTime'])) {
                            $exifData['date'] = $exif['DateTime'];
                        }
                    }

                    $gps = isset($exif['GPS']) ? $exif['GPS'] : $exif;
                    if (isset($gps['GPSLatitude'], $gps['GPSLatitudeRef'],
                               $gps['GPSLongitude'], $gps['GPSLongitudeRef'])) {
                        $exifData['latitude'] = ImageUtils::gpsToDecimal($gps['GPSLatitude'], $gps['GPSLatitudeRef']);
                        $exifData['longitude'] = ImageUtils::gpsToDecimal($gps['GPSLongitude'], $gps['GPSLongitudeRef']);
                    }
                }
            }

            // 2. 客户端 EXIF 兜底
            if (empty($exifData['date']) && !empty($_POST['exif_date'])) {
                $exifData['date'] = $_POST['exif_date'];
            }
            if (empty($exifData['latitude']) && !empty($_POST['exif_lat'])) {
                $exifData['latitude'] = floatval($_POST['exif_lat']);
            }
            if (empty($exifData['longitude']) && !empty($_POST['exif_lng'])) {
                $exifData['longitude'] = floatval($_POST['exif_lng']);
            }

            // 生成缩略图
            $thumbUrl = null;
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])) {
                $thumbFilename = $baseId . '_thumb.jpg';
                $thumbPath = rtrim($this->config['upload_dir'], '/') . '/' . $thumbFilename;
                if (ImageUtils::createThumbnail($targetPath, $thumbPath, $this->config['thumb_max_width'], $this->config['thumb_quality'])) {
                    $thumbUrl = ImageUtils::buildUploadUrl($thumbFilename, $this->config['base_url']);
                }
            }

            HttpUtils::jsonResponse([
                "url" => $url,
                "thumb" => $thumbUrl,
                "filename" => $file['name'],
                "exif" => $exifData
            ]);
        } else {
            HttpUtils::jsonResponse(["detail" => "文件保存失败"], 500);
        }
    }
}
