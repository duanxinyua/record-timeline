<?php
namespace App\Controllers;

use App\Models\TimelineItem;
use App\Utils\HttpUtils;
use App\Utils\ImageUtils;
use App\Utils\MediaUtils;
use App\Utils\UploadTracker;

class UploadController {
    private const CHUNK_DIR_NAME = '.chunk_uploads';
    private const CHUNK_FILE_PREFIX = 'chunk_';
    private const CHUNK_FILE_SUFFIX = '.part';
    private const CHUNK_STATUS_FILE_PREFIX = 'status_';
    private const CHUNK_STATUS_FILE_SUFFIX = '.json';
    private const CHUNK_PROCESSOR_LOCK_FILE = 'processor.lock';
    private const CHUNK_PROCESSOR_TOUCH_FILE = 'processor.touch';
    private const CHUNK_SIZE_BYTES = 5 * 1024 * 1024;
    private const CHUNK_MAX_COUNT = 4096;
    private const CHUNK_RETENTION_SECONDS = 86400;
    private const CHUNK_PROCESSOR_SPAWN_INTERVAL = 30;
    private const CHUNK_STATUS_UPLOADING = 'uploading';
    private const CHUNK_STATUS_PROCESSING = 'processing';
    private const CHUNK_STATUS_READY = 'ready';
    private const CHUNK_STATUS_FAILED = 'failed';
    private const LOCAL_PROCESS_DIR_NAME = 'chunk-processing';
    private const MIME_MAP = [
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

    protected $config;
    protected $model;

    public function __construct($config, TimelineItem $model = null) {
        $this->config = $config;
        $this->model = $model;
    }

    public function processPendingChunkUploads($limit = null) {
        $this->cleanupExpiredChunkUploads();
        $this->cleanupExpiredLocalProcessingDirs();

        if (!$this->ensureDirectoryExists($this->getChunkRootDir())) {
            return 0;
        }

        $lockHandle = @fopen($this->getChunkProcessorLockPath(), 'c+');
        if ($lockHandle === false) {
            return 0;
        }

        if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            return 0;
        }

        $processed = 0;

        try {
            while (true) {
                $job = $this->findNextProcessingChunkJob();
                if ($job === null) {
                    break;
                }

                $this->touchChunkProcessorHeartbeat();
                $this->finalizeChunkUpload($job['upload_id'], $job['chunk_dir'], $job['meta'], false);
                $processed++;

                if ($limit !== null && $processed >= max(1, (int)$limit)) {
                    break;
                }
            }
        } finally {
            @flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }

        return $processed;
    }

    public function handleUpload() {
        try {
            $file = $this->requireUploadedFile();
            $response = $this->storeMediaFromPath($file['tmp_name'], (string)$file['name'], $_POST, true);
            HttpUtils::jsonResponse($response);
        } catch (\RuntimeException $e) {
            HttpUtils::jsonResponse(["detail" => $e->getMessage()], $this->normalizeStatusCode($e->getCode(), 500));
        }
    }

    public function handleChunkInit() {
        try {
            $this->cleanupExpiredChunkUploads();

            $filename = trim((string)($_POST['filename'] ?? ''));
            $filesize = isset($_POST['filesize']) ? (int)$_POST['filesize'] : 0;
            $totalChunks = isset($_POST['total_chunks']) ? (int)$_POST['total_chunks'] : 0;

            if ($filename === '') {
                throw new \RuntimeException('缺少原始文件名', 400);
            }
            if ($filesize <= 0) {
                throw new \RuntimeException('文件大小无效', 400);
            }
            if ($totalChunks <= 0 || $totalChunks > self::CHUNK_MAX_COUNT) {
                throw new \RuntimeException('分片数量无效', 400);
            }

            $this->validateChunkInitRequest($filesize, $totalChunks);

            $uploadId = date('Ymd_His') . '_' . bin2hex(random_bytes(8));
            $chunkRootDir = $this->ensureWritableDirectory($this->getChunkRootDir(), '临时分片根目录');
            $chunkDir = $chunkRootDir . DIRECTORY_SEPARATOR . $uploadId;
            if (!@mkdir($chunkDir, 0755, true) && !is_dir($chunkDir)) {
                throw new \RuntimeException('无法创建临时分片目录: ' . $chunkDir, 500);
            }
            if (!is_writable($chunkDir)) {
                throw new \RuntimeException('临时分片目录不可写: ' . $chunkDir, 500);
            }

            $meta = [
                'filename' => $filename,
                'filesize' => $filesize,
                'total_chunks' => $totalChunks,
                'mime_type' => trim((string)($_POST['mime_type'] ?? '')),
                'skip_thumb' => $this->isTruthy($_POST['skip_thumb'] ?? null),
                'client_exif' => [
                    'date' => $this->normalizeOptionalString($_POST['exif_date'] ?? null),
                    'latitude' => $this->normalizeOptionalString($_POST['exif_lat'] ?? null),
                    'longitude' => $this->normalizeOptionalString($_POST['exif_lng'] ?? null),
                ],
                'created_at' => time(),
            ];

            $this->writeChunkMeta($chunkDir, $meta);
            $this->writeChunkStatus($uploadId, $this->buildChunkStatusData(
                $uploadId,
                self::CHUNK_STATUS_UPLOADING,
                $meta,
                null,
                null,
                0
            ));

            HttpUtils::jsonResponse([
                'upload_id' => $uploadId,
                'chunk_size' => self::CHUNK_SIZE_BYTES,
                'total_chunks' => $totalChunks,
            ]);
        } catch (\RuntimeException $e) {
            HttpUtils::jsonResponse(["detail" => $e->getMessage()], $this->normalizeStatusCode($e->getCode(), 500));
        }
    }

    public function handleChunkUpload() {
        try {
            $uploadId = $this->requireChunkUploadId();
            $chunkIndex = isset($_POST['chunk_index']) ? (int)$_POST['chunk_index'] : -1;
            $chunkDir = $this->getChunkDir($uploadId);
            $meta = $this->readChunkMeta($chunkDir);

            if ($meta === null) {
                throw new \RuntimeException('上传任务不存在或已过期', 404);
            }

            $totalChunks = isset($meta['total_chunks']) ? (int)$meta['total_chunks'] : 0;
            if ($chunkIndex < 0 || $chunkIndex >= $totalChunks) {
                throw new \RuntimeException('分片序号无效', 400);
            }

            $file = $this->requireUploadedFile();
            $expectedChunkSize = $this->getExpectedChunkSize($meta, $chunkIndex);
            $this->validateChunkFileSize($file, $expectedChunkSize);

            $targetPath = $chunkDir . '/' . $this->buildChunkFilename($chunkIndex);
            @unlink($targetPath);

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                throw new \RuntimeException('分片写入失败', 500);
            }

            $storedSize = @filesize($targetPath);
            if ($storedSize === false || (int)$storedSize !== $expectedChunkSize) {
                @unlink($targetPath);
                throw new \RuntimeException('分片落盘不完整，请重新上传', 500);
            }

            $this->writeChunkStatus($uploadId, $this->buildChunkStatusData(
                $uploadId,
                self::CHUNK_STATUS_UPLOADING,
                $meta,
                null,
                null,
                $this->countStoredChunks($chunkDir)
            ));

            HttpUtils::jsonResponse([
                'ok' => true,
                'chunk_index' => $chunkIndex,
            ]);
        } catch (\RuntimeException $e) {
            HttpUtils::jsonResponse(["detail" => $e->getMessage()], $this->normalizeStatusCode($e->getCode(), 500));
        }
    }

    public function handleChunkComplete() {
        $chunkDir = null;
        $uploadId = '';
        $meta = null;

        try {
            $uploadId = $this->requireChunkUploadId();
            $statusData = $this->readChunkStatus($uploadId);
            if (is_array($statusData)) {
                $status = (string)($statusData['status'] ?? '');
                if ($status === self::CHUNK_STATUS_READY && !empty($statusData['response']) && is_array($statusData['response'])) {
                    HttpUtils::jsonResponse($statusData['response']);
                }
                if ($status === self::CHUNK_STATUS_PROCESSING) {
                    if ($this->ensureChunkProcessorRunning()) {
                        HttpUtils::jsonResponse($statusData, 202);
                    }

                    $chunkDir = $this->getChunkDir($uploadId);
                    $meta = $this->readChunkMeta($chunkDir);
                    if ($meta === null) {
                        HttpUtils::jsonResponse($statusData, 202);
                    }

                    $this->completeChunkUploadAfterAcceptedResponse($uploadId, $chunkDir, $meta, $statusData);
                    return;
                }
                if ($status === self::CHUNK_STATUS_FAILED) {
                    HttpUtils::jsonResponse(["detail" => (string)($statusData['detail'] ?? '上传失败，请重新上传')], 500);
                }
            }

            $chunkDir = $this->getChunkDir($uploadId);
            $meta = $this->readChunkMeta($chunkDir);

            if ($meta === null) {
                throw new \RuntimeException('上传任务不存在或已过期', 404);
            }

            $processingStatus = $this->buildChunkStatusData(
                $uploadId,
                self::CHUNK_STATUS_PROCESSING,
                $meta,
                null,
                null,
                $this->countStoredChunks($chunkDir)
            );
            $this->writeChunkStatus($uploadId, $processingStatus);

            if ($this->ensureChunkProcessorRunning()) {
                HttpUtils::jsonResponse($processingStatus, 202);
            }

            $this->completeChunkUploadAfterAcceptedResponse($uploadId, $chunkDir, $meta, $processingStatus);
            return;
        } catch (\RuntimeException $e) {
            if ($chunkDir !== null && $uploadId !== '' && $meta !== null) {
                $this->writeChunkStatusSafely($uploadId, $this->buildChunkStatusData(
                    $uploadId,
                    self::CHUNK_STATUS_FAILED,
                    $meta,
                    $e->getMessage(),
                    null,
                    $this->countStoredChunks($chunkDir)
                ));
                $this->deleteDirectory($chunkDir);
            }
            HttpUtils::jsonResponse(["detail" => $e->getMessage()], $this->normalizeStatusCode($e->getCode(), 500));
        }
    }

    public function handleChunkStatus() {
        try {
            $uploadId = $this->requireChunkUploadId($_GET['upload_id'] ?? null);
            $statusData = $this->readChunkStatus($uploadId);
            if (is_array($statusData)) {
                $status = (string)($statusData['status'] ?? '');
                if ($status === self::CHUNK_STATUS_PROCESSING) {
                    $this->ensureChunkProcessorRunning();
                }

                if ($status === self::CHUNK_STATUS_UPLOADING || $status === self::CHUNK_STATUS_PROCESSING) {
                    $chunkDir = $this->getChunkDir($uploadId);
                    $meta = $this->readChunkMeta($chunkDir);
                    if ($meta !== null) {
                        $storedChunkIndexes = $this->getStoredChunkIndexes($chunkDir);
                        HttpUtils::jsonResponse($this->buildChunkStatusData(
                            $uploadId,
                            $status,
                            $meta,
                            isset($statusData['detail']) ? (string)$statusData['detail'] : null,
                            !empty($statusData['response']) && is_array($statusData['response']) ? $statusData['response'] : null,
                            count($storedChunkIndexes),
                            $storedChunkIndexes
                        ));
                    }
                }

                HttpUtils::jsonResponse($statusData);
            }

            $chunkDir = $this->getChunkDir($uploadId);
            $meta = $this->readChunkMeta($chunkDir);
            if ($meta === null) {
                throw new \RuntimeException('上传任务不存在或已过期', 404);
            }

            $storedChunkIndexes = $this->getStoredChunkIndexes($chunkDir);

            HttpUtils::jsonResponse($this->buildChunkStatusData(
                $uploadId,
                self::CHUNK_STATUS_UPLOADING,
                $meta,
                null,
                null,
                count($storedChunkIndexes),
                $storedChunkIndexes
            ));
        } catch (\RuntimeException $e) {
            HttpUtils::jsonResponse(["detail" => $e->getMessage()], $this->normalizeStatusCode($e->getCode(), 500));
        }
    }

    public function handleCleanup() {
        try {
            $urls = $this->normalizeCleanupUrls($this->getJsonField('urls'));
            if (empty($urls)) {
                HttpUtils::jsonResponse([
                    'requested' => 0,
                    'deleted' => 0,
                    'missing' => 0,
                    'referenced' => 0,
                    'requested_urls' => [],
                    'referenced_urls' => [],
                ]);
            }

            $referencedUrls = $this->model ? $this->model->getReferencedMediaUrls($urls) : [];
            $referencedMap = array_fill_keys($referencedUrls, true);

            $deletedCount = 0;
            $missingCount = 0;
            foreach ($urls as $url) {
                if (isset($referencedMap[$url])) {
                    continue;
                }

                if (UploadTracker::deleteFileByUrl($url, $this->config)) {
                    $deletedCount++;
                } else {
                    $missingCount++;
                }
            }

            UploadTracker::claimUrls($urls, $this->config);

            HttpUtils::jsonResponse([
                'requested' => count($urls),
                'deleted' => $deletedCount,
                'missing' => $missingCount,
                'referenced' => count($referencedUrls),
                'requested_urls' => $urls,
                'referenced_urls' => array_values($referencedUrls),
            ]);
        } catch (\RuntimeException $e) {
            HttpUtils::jsonResponse(["detail" => $e->getMessage()], $this->normalizeStatusCode($e->getCode(), 500));
        }
    }

    private function requireUploadedFile() {
        if (!isset($_FILES['file'])) {
            throw new \RuntimeException('没有上传文件', 400);
        }

        $file = $_FILES['file'];
        if (!is_array($file)) {
            throw new \RuntimeException('上传数据格式错误', 400);
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
            throw new \RuntimeException($errorMap[$uploadError] ?? ('上传失败，错误码: ' . $uploadError), 400);
        }

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('上传源文件无效', 400);
        }
        if (!isset($file['size']) || (int)$file['size'] <= 0) {
            throw new \RuntimeException('上传文件为空', 400);
        }

        return $file;
    }

    private function storeMediaFromPath($sourcePath, $originalName, $requestData, $isUploadedFile) {
        if (!is_string($sourcePath) || $sourcePath === '' || !is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \RuntimeException('上传源文件无效', 400);
        }

        $sourceSize = @filesize($sourcePath);
        if ($sourceSize === false || (int)$sourceSize <= 0) {
            throw new \RuntimeException('上传文件为空', 400);
        }

        $ext = $this->resolveStoredExtension($originalName, $sourcePath);
        $isVideo = MediaUtils::isVideoExtension($ext);

        $baseId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $storedExt = $isVideo ? 'mp4' : $ext;
        $newFilename = $baseId . '.' . $storedExt;
        $targetPath = rtrim($this->config['upload_dir'], '/') . '/' . $newFilename;

        $saved = false;
        $saveError = null;

        if ($isVideo) {
            try {
                if (MediaUtils::shouldTranscodeUpload($sourcePath, $ext)) {
                    MediaUtils::transcodeToBrowserMp4($sourcePath, $targetPath, $this->config);
                    $saved = true;
                } else {
                    $saved = $this->moveSourceFile($sourcePath, $targetPath, $isUploadedFile);
                }
            } catch (\RuntimeException $e) {
                $saveError = $e->getMessage();
            }
        } else {
            $saved = $this->moveSourceFile($sourcePath, $targetPath, $isUploadedFile);
        }

        if (!$saved) {
            throw new \RuntimeException($saveError ?: '文件保存失败', 500);
        }

        $url = ImageUtils::buildUploadUrl($newFilename, $this->config['base_url']);
        $skipThumb = $this->isTruthy($requestData['skip_thumb'] ?? null);
        $exifData = $this->extractExifData($targetPath, $storedExt, $requestData);

        $thumbUrl = null;
        if (!$skipThumb && in_array($storedExt, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
            $thumbFilename = $baseId . '_thumb.jpg';
            $thumbPath = rtrim($this->config['upload_dir'], '/') . '/' . $thumbFilename;
            if (ImageUtils::createThumbnail($targetPath, $thumbPath, $this->config['thumb_max_width'], $this->config['thumb_quality'])) {
                $thumbUrl = ImageUtils::buildUploadUrl($thumbFilename, $this->config['base_url']);
            }
        }

        try {
            UploadTracker::trackUrls([$url, $thumbUrl], $this->config);
        } catch (\RuntimeException $e) {
            UploadTracker::deleteFileByUrl($url, $this->config);
            if ($thumbUrl) {
                UploadTracker::deleteFileByUrl($thumbUrl, $this->config);
            }
            throw $e;
        }

        return [
            'url' => $url,
            'thumb' => $thumbUrl,
            'filename' => $originalName,
            'exif' => $exifData,
        ];
    }

    private function resolveStoredExtension($originalName, $sourcePath) {
        $ext = strtolower((string)pathinfo((string)$originalName, PATHINFO_EXTENSION));
        $allowedExts = array_unique(array_values(self::MIME_MAP));

        if ($ext !== '' && in_array($ext, $allowedExts, true)) {
            return $ext;
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $sourcePath);
            finfo_close($finfo);

            if (isset(self::MIME_MAP[$mime])) {
                return self::MIME_MAP[$mime];
            }

            throw new \RuntimeException('不支持的文件类型: ' . $mime, 400);
        }

        throw new \RuntimeException('不支持的文件类型: .' . $ext, 400);
    }

    private function moveSourceFile($sourcePath, $targetPath, $isUploadedFile) {
        $sourceSize = @filesize($sourcePath);

        if ($isUploadedFile && is_uploaded_file($sourcePath)) {
            if (!move_uploaded_file($sourcePath, $targetPath)) {
                return false;
            }

            return $this->verifyStoredFileSize($targetPath, $sourceSize);
        }

        if (@rename($sourcePath, $targetPath)) {
            return $this->verifyStoredFileSize($targetPath, $sourceSize);
        }

        $in = @fopen($sourcePath, 'rb');
        $out = @fopen($targetPath, 'wb');
        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
            return false;
        }

        $copied = stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        if ($copied === false || ($sourceSize !== false && (int)$copied !== (int)$sourceSize)) {
            @unlink($targetPath);
            return false;
        }

        if (!$this->verifyStoredFileSize($targetPath, $sourceSize)) {
            @unlink($targetPath);
            return false;
        }

        @unlink($sourcePath);
        return true;
    }

    private function verifyStoredFileSize($targetPath, $expectedSize) {
        if ($expectedSize === false || (int)$expectedSize < 0) {
            return is_file($targetPath);
        }

        $storedSize = @filesize($targetPath);
        if ($storedSize === false || (int)$storedSize !== (int)$expectedSize) {
            @unlink($targetPath);
            return false;
        }

        return true;
    }

    private function extractExifData($targetPath, $storedExt, $requestData) {
        $exifData = [];

        if (in_array($storedExt, ['jpg', 'jpeg', 'tiff', 'tif'], true) && function_exists('exif_read_data')) {
            $exif = false;
            try {
                $exif = @exif_read_data($targetPath, 'ANY_TAG', true);
            } catch (\Throwable $e) {
                $exif = false;
            }

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
                if (isset($gps['GPSLatitude'], $gps['GPSLatitudeRef'], $gps['GPSLongitude'], $gps['GPSLongitudeRef'])) {
                    $exifData['latitude'] = ImageUtils::gpsToDecimal($gps['GPSLatitude'], $gps['GPSLatitudeRef']);
                    $exifData['longitude'] = ImageUtils::gpsToDecimal($gps['GPSLongitude'], $gps['GPSLongitudeRef']);
                }
            }
        }

        if (empty($exifData['date']) && !empty($requestData['exif_date'])) {
            $exifData['date'] = (string)$requestData['exif_date'];
        }
        if (empty($exifData['latitude']) && isset($requestData['exif_lat']) && $requestData['exif_lat'] !== '') {
            $exifData['latitude'] = (float)$requestData['exif_lat'];
        }
        if (empty($exifData['longitude']) && isset($requestData['exif_lng']) && $requestData['exif_lng'] !== '') {
            $exifData['longitude'] = (float)$requestData['exif_lng'];
        }

        return $exifData;
    }

    private function getJsonField($field) {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            throw new \RuntimeException('请求体不能为空', 400);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('请求体格式无效', 400);
        }

        return $data[$field] ?? null;
    }

    private function normalizeCleanupUrls($rawUrls) {
        if (!is_array($rawUrls)) {
            throw new \RuntimeException('清理参数无效', 400);
        }

        $normalized = [];
        foreach ($rawUrls as $rawUrl) {
            $url = trim((string)$rawUrl);
            if ($url === '') {
                continue;
            }
            $normalized[$url] = true;
        }

        $urls = array_keys($normalized);
        if (count($urls) > 200) {
            throw new \RuntimeException('单次清理文件数量过多', 400);
        }

        return $urls;
    }

    private function requireChunkUploadId($rawValue = null) {
        if ($rawValue === null) {
            $rawValue = $_POST['upload_id'] ?? $_GET['upload_id'] ?? null;
        }

        $uploadId = trim((string)$rawValue);
        if ($uploadId === '' || !preg_match('/^[A-Za-z0-9_]+$/', $uploadId)) {
            throw new \RuntimeException('上传任务标识无效', 400);
        }
        return $uploadId;
    }

    private function getChunkRootDir() {
        $rootDir = trim((string)($this->config['chunk_tmp_dir'] ?? ''));
        if ($rootDir === '') {
            $rootDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'peanut-timeline-chunks';
        }

        return rtrim($rootDir, "\\/");
    }

    private function getChunkDir($uploadId) {
        return $this->getChunkRootDir() . DIRECTORY_SEPARATOR . $uploadId;
    }

    private function getChunkMetaPath($chunkDir) {
        return rtrim($chunkDir, '/') . '/meta.json';
    }

    private function getChunkStatusPath($uploadId) {
        return $this->getChunkStatusPathForRoot($this->getChunkRootDir(), $uploadId);
    }

    private function getChunkStatusPathForRoot($rootDir, $uploadId) {
        return rtrim((string)$rootDir, "\\/") . DIRECTORY_SEPARATOR . self::CHUNK_STATUS_FILE_PREFIX . $uploadId . self::CHUNK_STATUS_FILE_SUFFIX;
    }

    private function getChunkProcessorLockPath() {
        return $this->getChunkRootDir() . '/' . self::CHUNK_PROCESSOR_LOCK_FILE;
    }

    private function getChunkProcessorTouchPath() {
        return $this->getChunkRootDir() . '/' . self::CHUNK_PROCESSOR_TOUCH_FILE;
    }

    private function writeChunkMeta($chunkDir, $meta) {
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($metaJson === false || @file_put_contents($this->getChunkMetaPath($chunkDir), $metaJson, LOCK_EX) === false) {
            throw new \RuntimeException('无法写入上传任务信息', 500);
        }
    }

    private function readChunkMeta($chunkDir) {
        $metaPath = $this->getChunkMetaPath($chunkDir);
        if (!is_file($metaPath) || !is_readable($metaPath)) {
            return null;
        }

        $raw = @file_get_contents($metaPath);
        if ($raw === false || $raw === '') {
            return null;
        }

        $meta = json_decode($raw, true);
        return is_array($meta) ? $meta : null;
    }

    private function writeChunkStatus($uploadId, array $statusData) {
        $rootDir = $this->ensureWritableDirectory($this->getChunkRootDir(), '分片状态目录');

        $statusJson = json_encode($statusData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($statusJson === false || @file_put_contents($this->getChunkStatusPathForRoot($rootDir, $uploadId), $statusJson, LOCK_EX) === false) {
            throw new \RuntimeException('无法写入上传状态', 500);
        }
    }

    private function writeChunkStatusSafely($uploadId, array $statusData) {
        try {
            $this->writeChunkStatus($uploadId, $statusData);
        } catch (\Throwable $e) {
            error_log('Chunk upload status write failed: ' . $e->getMessage());
        }
    }

    private function readChunkStatus($uploadId) {
        $statusPath = $this->getChunkStatusPath($uploadId);
        if (!is_file($statusPath) || !is_readable($statusPath)) {
            return null;
        }

        $raw = @file_get_contents($statusPath);
        if ($raw === false || $raw === '') {
            return null;
        }

        $status = json_decode($raw, true);
        return is_array($status) ? $status : null;
    }

    private function buildChunkFilename($chunkIndex) {
        return self::CHUNK_FILE_PREFIX . str_pad((string)$chunkIndex, 6, '0', STR_PAD_LEFT) . self::CHUNK_FILE_SUFFIX;
    }

    private function finalizeChunkUpload($uploadId, $chunkDir, array $meta, $throwOnFailure = true) {
        try {
            @set_time_limit(0);
            if (function_exists('ignore_user_abort')) {
                @ignore_user_abort(true);
            }

            $response = $this->performChunkUploadCompletion($uploadId, $chunkDir, $meta);
            $this->writeChunkStatus($uploadId, $this->buildChunkStatusData(
                $uploadId,
                self::CHUNK_STATUS_READY,
                $meta,
                null,
                $response,
                (int)($meta['total_chunks'] ?? 0)
            ));
            $this->deleteDirectory($chunkDir);
            return $response;
        } catch (\Throwable $e) {
            $detail = $e instanceof \RuntimeException ? $e->getMessage() : '文件保存失败，请稍后重试';
            $statusCode = $e instanceof \RuntimeException ? $this->normalizeStatusCode($e->getCode(), 500) : 500;

            $this->writeChunkStatusSafely($uploadId, $this->buildChunkStatusData(
                $uploadId,
                self::CHUNK_STATUS_FAILED,
                $meta,
                $detail,
                null,
                $this->countStoredChunks($chunkDir)
            ));
            $this->deleteDirectory($chunkDir);

            if ($throwOnFailure) {
                throw new \RuntimeException($detail, $statusCode);
            }

            return null;
        }
    }

    private function performChunkUploadCompletion($uploadId, $chunkDir, array $meta) {
        $workingDir = $this->createLocalProcessingDir($uploadId);
        $mergedPath = $workingDir . '/merged.upload';

        try {
            $this->mergeChunks($chunkDir, $mergedPath, (int)($meta['total_chunks'] ?? 0), (int)($meta['filesize'] ?? 0));

            $requestData = [
                'skip_thumb' => !empty($meta['skip_thumb']) ? '1' : '',
                'exif_date' => $meta['client_exif']['date'] ?? null,
                'exif_lat' => $meta['client_exif']['latitude'] ?? null,
                'exif_lng' => $meta['client_exif']['longitude'] ?? null,
            ];

            return $this->storeMediaFromPath($mergedPath, (string)($meta['filename'] ?? 'media'), $requestData, false);
        } finally {
            $this->deleteDirectory($workingDir);
        }
    }

    private function mergeChunks($chunkDir, $mergedPath, $totalChunks, $expectedSize) {
        if ($totalChunks <= 0) {
            throw new \RuntimeException('分片数量无效，无法合并', 400);
        }

        @unlink($mergedPath);
        $out = @fopen($mergedPath, 'wb');
        if ($out === false) {
            throw new \RuntimeException('无法创建临时合并文件', 500);
        }

        $writtenBytes = 0;

        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = $chunkDir . '/' . $this->buildChunkFilename($i);
                if (!is_file($chunkPath) || !is_readable($chunkPath)) {
                    throw new \RuntimeException('分片缺失，无法完成合并', 400);
                }

                $in = @fopen($chunkPath, 'rb');
                if ($in === false) {
                    throw new \RuntimeException('读取分片失败，无法完成合并', 500);
                }

                try {
                    $copied = stream_copy_to_stream($in, $out);
                } finally {
                    fclose($in);
                }

                if ($copied === false) {
                    throw new \RuntimeException('分片合并失败', 500);
                }

                $writtenBytes += (int)$copied;
            }
        } catch (\RuntimeException $e) {
            fclose($out);
            @unlink($mergedPath);
            throw $e;
        }

        fclose($out);

        if ($expectedSize > 0 && $writtenBytes !== $expectedSize) {
            @unlink($mergedPath);
            throw new \RuntimeException('合并后文件大小异常，请重新上传', 400);
        }
    }

    private function cleanupExpiredChunkUploads() {
        $expireBefore = time() - self::CHUNK_RETENTION_SECONDS;
        $rootDir = $this->getChunkRootDir();
        if (!is_dir($rootDir)) {
            return;
        }

        $entries = @scandir($rootDir);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $rootDir . '/' . $entry;
            if (is_dir($path)) {
                $mtime = @filemtime($path);
                if ($mtime !== false && $mtime < $expireBefore) {
                    $this->deleteDirectory($path);
                }
                continue;
            }

            if (is_file($path)
                && strpos($entry, self::CHUNK_STATUS_FILE_PREFIX) === 0
                && substr($entry, -strlen(self::CHUNK_STATUS_FILE_SUFFIX)) === self::CHUNK_STATUS_FILE_SUFFIX) {
                $mtime = @filemtime($path);
                if ($mtime !== false && $mtime < $expireBefore) {
                    @unlink($path);
                }
            }
        }
    }

    private function cleanupExpiredLocalProcessingDirs() {
        $rootDir = $this->getLocalProcessingRoot();
        if (!is_dir($rootDir)) {
            return;
        }

        $entries = @scandir($rootDir);
        if (!is_array($entries)) {
            return;
        }

        $expireBefore = time() - self::CHUNK_RETENTION_SECONDS;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $rootDir . '/' . $entry;
            $mtime = @filemtime($path);
            if ($mtime === false || $mtime >= $expireBefore) {
                continue;
            }

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function ensureChunkProcessorRunning() {
        $touchPath = $this->getChunkProcessorTouchPath();
        $lastTouchedAt = @filemtime($touchPath);
        if ($lastTouchedAt !== false && (time() - (int)$lastTouchedAt) < self::CHUNK_PROCESSOR_SPAWN_INTERVAL) {
            return true;
        }

        $this->touchChunkProcessorHeartbeat();

        if ($this->spawnChunkProcessor()) {
            return true;
        }

        @unlink($touchPath);
        return false;
    }

    private function spawnChunkProcessor() {
        $phpBin = $this->resolvePhpCliBinary();
        $scriptPath = $this->getChunkProcessorScriptPath();
        if ($phpBin === null || !is_file($scriptPath) || !is_readable($scriptPath)) {
            return false;
        }

        $command = escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptPath) . ' > /dev/null 2>&1 &';

        if (function_exists('exec')) {
            $output = [];
            $exitCode = 0;
            @exec($command, $output, $exitCode);
            return (int)$exitCode === 0;
        }

        if (function_exists('proc_open')) {
            $process = @proc_open($command, [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'a'],
                2 => ['file', '/dev/null', 'a'],
            ], $pipes);
            if (is_resource($process)) {
                @proc_close($process);
                return true;
            }
        }

        return false;
    }

    private function findNextProcessingChunkJob() {
        $jobs = [];
        $rootDir = $this->getChunkRootDir();
        if (!is_dir($rootDir)) {
            return null;
        }

        $entries = @scandir($rootDir);
        if (!is_array($entries)) {
            return null;
        }

        foreach ($entries as $entry) {
            $uploadId = $this->extractUploadIdFromStatusFilename($entry);
            if ($uploadId === null || isset($jobs[$uploadId])) {
                continue;
            }

            $statusPath = $this->getChunkStatusPathForRoot($rootDir, $uploadId);
            if (!is_file($statusPath) || !is_readable($statusPath)) {
                continue;
            }

            $rawStatus = @file_get_contents($statusPath);
            if ($rawStatus === false || $rawStatus === '') {
                continue;
            }

            $statusData = json_decode($rawStatus, true);
            if (!is_array($statusData) || (string)($statusData['status'] ?? '') !== self::CHUNK_STATUS_PROCESSING) {
                continue;
            }

            $chunkDir = $rootDir . DIRECTORY_SEPARATOR . $uploadId;
            $meta = $this->readChunkMeta($chunkDir);
            if ($meta === null) {
                $this->writeChunkStatusSafely($uploadId, $this->buildChunkStatusData(
                    $uploadId,
                    self::CHUNK_STATUS_FAILED,
                    null,
                    '上传任务不存在或已过期',
                    null,
                    0
                ));
                continue;
            }

            $jobs[$uploadId] = [
                'upload_id' => $uploadId,
                'chunk_dir' => $chunkDir,
                'meta' => $meta,
                'created_at' => (int)($statusData['created_at'] ?? PHP_INT_MAX),
                'updated_at' => (int)($statusData['updated_at'] ?? PHP_INT_MAX),
            ];
        }

        if (empty($jobs)) {
            return null;
        }

        $jobList = array_values($jobs);
        usort($jobList, static function ($left, $right) {
            if ($left['created_at'] === $right['created_at']) {
                return $left['updated_at'] <=> $right['updated_at'];
            }

            return $left['created_at'] <=> $right['created_at'];
        });

        return $jobList[0];
    }

    private function extractUploadIdFromStatusFilename($filename) {
        if (!is_string($filename)) {
            return null;
        }

        if (strpos($filename, self::CHUNK_STATUS_FILE_PREFIX) !== 0) {
            return null;
        }

        if (substr($filename, -strlen(self::CHUNK_STATUS_FILE_SUFFIX)) !== self::CHUNK_STATUS_FILE_SUFFIX) {
            return null;
        }

        $uploadId = substr(
            $filename,
            strlen(self::CHUNK_STATUS_FILE_PREFIX),
            -strlen(self::CHUNK_STATUS_FILE_SUFFIX)
        );

        return preg_match('/^[A-Za-z0-9_]+$/', $uploadId) ? $uploadId : null;
    }

    private function getChunkProcessorScriptPath() {
        return dirname(__DIR__, 2) . '/bin/process-upload-queue.php';
    }

    private function touchChunkProcessorHeartbeat() {
        $rootDir = $this->getChunkRootDir();
        try {
            $rootDir = $this->ensureWritableDirectory($rootDir, '分片状态目录');
        } catch (\RuntimeException $e) {
            return;
        }

        $touchPath = $this->getChunkProcessorTouchPath();
        if (@touch($touchPath)) {
            return;
        }

        @file_put_contents($touchPath, (string)time(), LOCK_EX);
    }

    private function ensureDirectoryExists($path) {
        $path = trim((string)$path);
        if ($path === '') {
            return false;
        }

        return is_dir($path) || (@mkdir($path, 0755, true) && is_dir($path));
    }

    private function ensureWritableDirectory($path, $label) {
        $path = rtrim(trim((string)$path), "\\/");
        if ($path === '') {
            throw new \RuntimeException($label . '未配置', 500);
        }

        if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
            throw new \RuntimeException($label . '无法创建: ' . $path, 500);
        }

        if (!is_writable($path)) {
            throw new \RuntimeException($label . '不可写: ' . $path, 500);
        }

        return $path;
    }

    private function resolvePhpCliBinary() {
        $candidates = [];

        $configured = trim((string)($this->config['php_cli_bin'] ?? ''));
        if ($configured !== '') {
            $candidates[] = $configured;
        }

        if (defined('PHP_BINDIR')) {
            $candidates[] = rtrim((string)PHP_BINDIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php';
        }

        if (defined('PHP_BINARY')) {
            $phpBinaryName = strtolower(basename((string)PHP_BINARY));
            if ($phpBinaryName !== '' && strpos($phpBinaryName, 'fpm') === false) {
                $candidates[] = PHP_BINARY;
            }
        }

        $candidates[] = 'php';

        foreach (array_values(array_unique($candidates)) as $candidate) {
            $resolved = $this->resolveCommandPath($candidate);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function resolveCommandPath($command) {
        $command = trim((string)$command);
        if ($command === '') {
            return null;
        }

        if (strpos($command, DIRECTORY_SEPARATOR) !== false || preg_match('/^[A-Za-z]:[\\\\\\/]/', $command) === 1) {
            return (@is_file($command) && @is_executable($command)) ? $command : null;
        }

        $pathEnv = getenv('PATH');
        $searchPaths = ($pathEnv === false || $pathEnv === '')
            ? ['/usr/local/sbin', '/usr/local/bin', '/usr/sbin', '/usr/bin', '/sbin', '/bin']
            : explode(PATH_SEPARATOR, $pathEnv);

        foreach ($searchPaths as $dir) {
            $dir = rtrim((string)$dir, "\\/");
            if ($dir === '') {
                continue;
            }

            $candidate = $dir . DIRECTORY_SEPARATOR . $command;
            if (@is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function getLocalProcessingRoot() {
        $rootDir = trim((string)($this->config['video_tmp_dir'] ?? ''));
        if ($rootDir === '') {
            $rootDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'peanut-timeline-video';
        }

        return rtrim($rootDir, "\\/") . DIRECTORY_SEPARATOR . self::LOCAL_PROCESS_DIR_NAME;
    }

    private function createLocalProcessingDir($uploadId) {
        $rootDir = $this->ensureWritableDirectory($this->getLocalProcessingRoot(), '本地视频处理根目录');

        $workingDir = $rootDir . DIRECTORY_SEPARATOR . $uploadId . '_' . bin2hex(random_bytes(4));
        if (!@mkdir($workingDir, 0755, true) && !is_dir($workingDir)) {
            throw new \RuntimeException('无法创建本地视频处理目录: ' . $workingDir, 500);
        }
        if (!is_writable($workingDir)) {
            throw new \RuntimeException('本地视频处理目录不可写: ' . $workingDir, 500);
        }

        return $workingDir;
    }

    private function countStoredChunks($chunkDir) {
        return count($this->getStoredChunkIndexes($chunkDir));
    }

    private function getStoredChunkIndexes($chunkDir) {
        if (!is_dir($chunkDir)) {
            return [];
        }

        $entries = @scandir($chunkDir);
        if (!is_array($entries)) {
            return [];
        }

        $indexes = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (strpos($entry, self::CHUNK_FILE_PREFIX) !== 0 || substr($entry, -strlen(self::CHUNK_FILE_SUFFIX)) !== self::CHUNK_FILE_SUFFIX) {
                continue;
            }

            $chunkIndex = substr(
                $entry,
                strlen(self::CHUNK_FILE_PREFIX),
                -strlen(self::CHUNK_FILE_SUFFIX)
            );
            if ($chunkIndex === '' || !ctype_digit($chunkIndex)) {
                continue;
            }

            $indexes[(int)$chunkIndex] = true;
        }

        $indexes = array_keys($indexes);
        sort($indexes, SORT_NUMERIC);

        return array_values($indexes);
    }

    private function deleteDirectory($path) {
        if (!is_dir($path)) {
            return;
        }

        $entries = @scandir($path);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            if (is_dir($child)) {
                $this->deleteDirectory($child);
                continue;
            }

            @unlink($child);
        }

        @rmdir($path);
    }

    private function validateChunkInitRequest($filesize, $totalChunks) {
        $expectedChunks = (int)ceil($filesize / self::CHUNK_SIZE_BYTES);
        if ($expectedChunks !== $totalChunks) {
            throw new \RuntimeException('分片数量与文件大小不匹配', 400);
        }
    }

    private function validateChunkFileSize(array $file, $expectedChunkSize) {
        $fileSize = isset($file['size']) ? (int)$file['size'] : 0;
        if ($fileSize <= 0) {
            throw new \RuntimeException('上传分片为空', 400);
        }

        if ($fileSize > self::CHUNK_SIZE_BYTES) {
            throw new \RuntimeException('上传分片超过服务器允许大小', 400);
        }

        if ($fileSize !== $expectedChunkSize) {
            throw new \RuntimeException('上传分片大小异常，请重新上传', 400);
        }
    }

    private function getExpectedChunkSize(array $meta, $chunkIndex) {
        $fileSize = isset($meta['filesize']) ? (int)$meta['filesize'] : 0;
        $offset = $chunkIndex * self::CHUNK_SIZE_BYTES;
        $remaining = $fileSize - $offset;
        if ($remaining <= 0) {
            throw new \RuntimeException('分片序号无效', 400);
        }

        return (int)min(self::CHUNK_SIZE_BYTES, $remaining);
    }

    private function buildChunkStatusData($uploadId, $status, $meta = null, $detail = null, $response = null, $uploadedChunks = null, $uploadedChunkIndexes = null) {
        $existing = $this->readChunkStatus($uploadId);
        $totalChunks = $meta !== null
            ? (int)($meta['total_chunks'] ?? 0)
            : (int)($existing['total_chunks'] ?? 0);
        $createdAt = (int)($existing['created_at'] ?? time());
        $filename = $meta !== null
            ? (string)($meta['filename'] ?? '')
            : (string)($existing['filename'] ?? '');
        $filesize = $meta !== null
            ? (int)($meta['filesize'] ?? 0)
            : (int)($existing['filesize'] ?? 0);
        $mimeType = $meta !== null
            ? (string)($meta['mime_type'] ?? '')
            : (string)($existing['mime_type'] ?? '');

        if ($uploadedChunks === null) {
            $uploadedChunks = (int)($existing['uploaded_chunks'] ?? 0);
        }

        if (!is_array($uploadedChunkIndexes) && !empty($existing['uploaded_chunk_indexes']) && is_array($existing['uploaded_chunk_indexes'])) {
            $uploadedChunkIndexes = $existing['uploaded_chunk_indexes'];
        }

        $normalizedChunkIndexes = [];
        if (is_array($uploadedChunkIndexes)) {
            foreach ($uploadedChunkIndexes as $chunkIndex) {
                if (!is_numeric($chunkIndex)) {
                    continue;
                }

                $chunkIndex = (int)$chunkIndex;
                if ($chunkIndex < 0) {
                    continue;
                }
                if ($totalChunks > 0 && $chunkIndex >= $totalChunks) {
                    continue;
                }

                $normalizedChunkIndexes[$chunkIndex] = true;
            }
        }

        $normalizedChunkIndexes = array_keys($normalizedChunkIndexes);
        sort($normalizedChunkIndexes, SORT_NUMERIC);

        if (!empty($normalizedChunkIndexes)) {
            $uploadedChunks = count($normalizedChunkIndexes);
        }

        if ($totalChunks > 0) {
            $uploadedChunks = max(0, min((int)$uploadedChunks, $totalChunks));
        } else {
            $uploadedChunks = max(0, (int)$uploadedChunks);
        }

        return [
            'upload_id' => $uploadId,
            'status' => $status,
            'detail' => $detail,
            'filename' => $filename,
            'filesize' => $filesize,
            'mime_type' => $mimeType,
            'total_chunks' => $totalChunks,
            'uploaded_chunks' => $uploadedChunks,
            'uploaded_chunk_indexes' => array_values($normalizedChunkIndexes),
            'response' => is_array($response) ? $response : null,
            'created_at' => $createdAt,
            'updated_at' => time(),
        ];
    }

    private function respondAcceptedJson($data, $statusCode = 202) {
        $payload = json_encode($data);
        if ($payload === false) {
            $payload = '{}';
        }

        if (function_exists('session_write_close')) {
            @session_write_close();
        }

        http_response_code($statusCode);
        echo $payload;

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        flush();
    }

    private function completeChunkUploadAfterAcceptedResponse($uploadId, $chunkDir, array $meta, array $processingStatus) {
        error_log('Chunk processor unavailable, falling back to inline finalize after early response: ' . $uploadId);
        $this->respondAcceptedJson($processingStatus, 202);
        if ($this->finalizeChunkUpload($uploadId, $chunkDir, $meta, false) === null) {
            error_log('Chunk upload inline finalize failed after early response: ' . $uploadId);
        }
    }

    private function normalizeOptionalString($value) {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function isTruthy($value) {
        if ($value === null) {
            return false;
        }

        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }

    private function normalizeStatusCode($statusCode, $fallback) {
        $statusCode = (int)$statusCode;
        if ($statusCode >= 400 && $statusCode <= 599) {
            return $statusCode;
        }
        return $fallback;
    }
}
