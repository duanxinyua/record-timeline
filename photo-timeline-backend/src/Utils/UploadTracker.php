<?php
namespace App\Utils;

use App\Models\TimelineItem;

class UploadTracker {
    private const TRACK_DIR_NAME = '.pending_uploads';
    private const TRACK_FILE_SUFFIX = '.json';
    private const CLEANUP_STAMP_FILE = '.last_cleanup_at';
    private const DEFAULT_PENDING_TTL = 86400;
    private const DEFAULT_CLEANUP_INTERVAL = 300;

    public static function trackUrls(array $urls, array $config): void {
        $normalizedUrls = self::normalizeUrls($urls);
        if (empty($normalizedUrls)) {
            return;
        }

        $trackDir = self::ensureWritableDirectory(self::getTrackDir($config), '上传跟踪目录');

        $now = time();
        foreach ($normalizedUrls as $url) {
            $payload = json_encode([
                'url' => $url,
                'created_at' => $now,
                'updated_at' => $now,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($payload === false || @file_put_contents(self::getTrackFilePath($url, $config), $payload) === false) {
                throw new \RuntimeException('无法写入上传跟踪信息', 500);
            }
        }
    }

    public static function claimUrls(array $urls, array $config): void {
        foreach (self::normalizeUrls($urls) as $url) {
            @unlink(self::getTrackFilePath($url, $config));
        }
    }

    public static function deleteFileByUrl(string $url, array $config): bool {
        $path = self::resolveUploadPathFromUrl($url, $config);
        if ($path === null || !is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    public static function cleanupExpired(array $config, TimelineItem $model, bool $force = false): array {
        $trackDir = self::getTrackDir($config);
        if (!is_dir($trackDir)) {
            return [
                'tracked' => 0,
                'deleted' => 0,
                'missing' => 0,
                'claimed' => 0,
            ];
        }

        if (!$force && !self::shouldRunCleanup($trackDir, $config)) {
            return [
                'tracked' => 0,
                'deleted' => 0,
                'missing' => 0,
                'claimed' => 0,
            ];
        }

        self::touchCleanupStamp($trackDir);

        $ttl = max(60, (int)($config['pending_upload_ttl'] ?? self::DEFAULT_PENDING_TTL));
        $now = time();
        $tracked = [];
        foreach (glob($trackDir . '/*' . self::TRACK_FILE_SUFFIX) ?: [] as $trackFile) {
            $raw = @file_get_contents($trackFile);
            $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($data) || empty($data['url'])) {
                @unlink($trackFile);
                continue;
            }

            $url = trim((string)$data['url']);
            if ($url === '') {
                @unlink($trackFile);
                continue;
            }

            $tracked[$url] = [
                'path' => $trackFile,
                'created_at' => (int)($data['created_at'] ?? 0),
            ];
        }

        if (empty($tracked)) {
            return [
                'tracked' => 0,
                'deleted' => 0,
                'missing' => 0,
                'claimed' => 0,
            ];
        }

        $referencedUrls = $model->getReferencedMediaUrls(array_keys($tracked));
        $referencedMap = array_fill_keys($referencedUrls, true);

        $deleted = 0;
        $missing = 0;
        $claimed = 0;

        foreach ($tracked as $url => $meta) {
            if (isset($referencedMap[$url])) {
                @unlink($meta['path']);
                $claimed++;
                continue;
            }

            $createdAt = $meta['created_at'] > 0 ? $meta['created_at'] : @filemtime($meta['path']);
            if ($createdAt !== false && ($now - (int)$createdAt) < $ttl) {
                continue;
            }

            $result = self::deleteFileByUrl($url, $config);
            if ($result) {
                $deleted++;
            } else {
                $missing++;
            }
            @unlink($meta['path']);
        }

        return [
            'tracked' => count($tracked),
            'deleted' => $deleted,
            'missing' => $missing,
            'claimed' => $claimed,
        ];
    }

    private static function normalizeUrls(array $urls): array {
        $normalized = [];
        foreach ($urls as $url) {
            $value = trim((string)$url);
            if ($value !== '') {
                $normalized[$value] = true;
            }
        }

        return array_keys($normalized);
    }

    private static function shouldRunCleanup(string $trackDir, array $config): bool {
        $interval = max(30, (int)($config['pending_upload_cleanup_interval'] ?? self::DEFAULT_CLEANUP_INTERVAL));
        $stampPath = $trackDir . '/' . self::CLEANUP_STAMP_FILE;
        $lastRun = @filemtime($stampPath);

        return $lastRun === false || (time() - (int)$lastRun) >= $interval;
    }

    private static function touchCleanupStamp(string $trackDir): void {
        $stampPath = $trackDir . '/' . self::CLEANUP_STAMP_FILE;
        if (!is_dir($trackDir)) {
            return;
        }
        if (!is_file($stampPath)) {
            @file_put_contents($stampPath, (string)time());
            return;
        }
        @touch($stampPath);
    }

    private static function getTrackDir(array $config): string {
        $trackDir = trim((string)($config['pending_upload_dir'] ?? ''));
        if ($trackDir !== '') {
            return rtrim($trackDir, "\\/");
        }

        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'peanut-timeline-pending';
    }

    private static function ensureWritableDirectory(string $path, string $label): string {
        $path = rtrim(trim($path), "\\/");
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

    private static function getTrackFilePath(string $url, array $config): string {
        return self::getTrackDir($config) . '/' . sha1($url) . self::TRACK_FILE_SUFFIX;
    }

    private static function resolveUploadPathFromUrl(string $url, array $config): ?string {
        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['path'])) {
            return null;
        }

        $filename = basename((string)$parsed['path']);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return null;
        }
        if (strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            return null;
        }

        return rtrim((string)($config['upload_dir'] ?? ''), '/') . '/' . $filename;
    }
}
