<?php
namespace App\Utils;

class MediaUtils {
    private const VIDEO_EXTS = ['mp4', 'mov', 'webm', 'avi', '3gp', 'm4v'];
    private const ISO_BMFF_VIDEO_EXTS = ['mp4', 'mov', 'm4v', '3gp'];
    private const CODEC_SCAN_ORDER = ['hvc1', 'hev1', 'av01', 'vp09', 'avc1'];

    public static function isVideoExtension($ext) {
        return in_array(strtolower((string)$ext), self::VIDEO_EXTS, true);
    }

    public static function shouldTranscodeUpload($filepath, $ext) {
        $ext = strtolower((string)$ext);
        if (!self::isVideoExtension($ext)) {
            return false;
        }

        if ($ext !== 'mp4') {
            return true;
        }

        return self::detectIsoBmffVideoCodec($filepath) !== 'avc1';
    }

    public static function detectIsoBmffVideoCodec($filepath) {
        $ext = strtolower((string)pathinfo((string)$filepath, PATHINFO_EXTENSION));
        if ($ext !== '' && !in_array($ext, self::ISO_BMFF_VIDEO_EXTS, true)) {
            return null;
        }

        $moov = self::readMoovAtom($filepath, 16 * 1024 * 1024);
        if ($moov === null || $moov === '') {
            return null;
        }

        foreach (self::CODEC_SCAN_ORDER as $codecTag) {
            if (strpos($moov, $codecTag) !== false) {
                return $codecTag;
            }
        }

        return null;
    }

    public static function transcodeToBrowserMp4($sourcePath, $targetPath, $config) {
        $ffmpegBin = (string)($config['ffmpeg_bin'] ?? 'ffmpeg');
        $preset = (string)($config['video_transcode_preset'] ?? 'veryfast');
        $crf = (int)($config['video_transcode_crf'] ?? 23);

        $resolvedFfmpegBin = self::resolveCommandPath($ffmpegBin);
        if ($resolvedFfmpegBin === null) {
            throw new \RuntimeException('服务器未安装 ffmpeg，暂时无法自动转换当前视频。');
        }

        if (!is_string($sourcePath) || $sourcePath === '' || !is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \RuntimeException('上传源视频无效，无法执行转码。');
        }

        $tmpTargetPath = $targetPath . '.tmp.mp4';
        @unlink($tmpTargetPath);

        @set_time_limit(0);

        $command = implode(' ', [
            escapeshellarg($resolvedFfmpegBin),
            '-y',
            '-hide_banner',
            '-loglevel', 'error',
            '-i', escapeshellarg($sourcePath),
            '-map', '0:v:0',
            '-map', '0:a?',
            '-c:v', 'libx264',
            '-preset', escapeshellarg($preset),
            '-crf', (string)max(18, min(32, $crf)),
            '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart',
            '-c:a', 'aac',
            '-b:a', '128k',
            escapeshellarg($tmpTargetPath),
        ]);

        [$output, $exitCode] = self::runCommand($command);

        if ($exitCode !== 0 || !is_file($tmpTargetPath) || (int)@filesize($tmpTargetPath) <= 0) {
            @unlink($tmpTargetPath);
            $detail = self::summarizeOutput($output);
            throw new \RuntimeException($detail ? ('视频转码失败：' . $detail) : '视频转码失败，请稍后重试。');
        }

        if (!@rename($tmpTargetPath, $targetPath)) {
            @unlink($tmpTargetPath);
            throw new \RuntimeException('视频转码完成，但写入上传目录失败。');
        }
    }

    private static function commandExists($command) {
        return self::resolveCommandPath($command) !== null;
    }

    private static function resolveCommandPath($command) {
        $command = trim((string)$command);
        if ($command === '') {
            return null;
        }

        if (strpos($command, DIRECTORY_SEPARATOR) !== false || preg_match('/^[A-Za-z]:[\\\\\\/]/', $command) === 1) {
            return $command;
        }

        $pathEnv = getenv('PATH');
        $searchPaths = ($pathEnv === false || $pathEnv === '')
            ? ['/usr/local/sbin', '/usr/local/bin', '/usr/sbin', '/usr/bin', '/sbin', '/bin']
            : explode(PATH_SEPARATOR, $pathEnv);

        $suffixes = [''];
        if (DIRECTORY_SEPARATOR === '\\') {
            $pathExt = getenv('PATHEXT');
            $suffixes = ($pathExt === false || $pathExt === '')
                ? ['', '.exe', '.bat', '.cmd']
                : array_merge([''], array_filter(array_map('trim', explode(';', strtolower($pathExt)))));
        }

        foreach ($searchPaths as $dir) {
            $dir = rtrim((string)$dir, "\\/");
            if ($dir === '') {
                continue;
            }

            foreach ($suffixes as $suffix) {
                $candidate = $dir . DIRECTORY_SEPARATOR . $command . $suffix;
                if (@is_file($candidate) && @is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private static function runCommand($command) {
        $command = trim((string)$command);
        if ($command === '') {
            throw new \RuntimeException('服务器命令执行参数无效，暂时无法自动转换当前视频。');
        }

        if (\function_exists('proc_open')) {
            $pipes = [];
            $process = @\proc_open($command, [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);

            if (\is_resource($process)) {
                $stdout = '';
                $stderr = '';

                try {
                    $stdout = isset($pipes[1]) ? (string)\stream_get_contents($pipes[1]) : '';
                    $stderr = isset($pipes[2]) ? (string)\stream_get_contents($pipes[2]) : '';
                } finally {
                    foreach ($pipes as $pipe) {
                        if (\is_resource($pipe)) {
                            \fclose($pipe);
                        }
                    }
                }

                $exitCode = \proc_close($process);
                return [self::splitCommandOutput($stdout . "\n" . $stderr), (int)$exitCode];
            }
        }

        if (\function_exists('exec')) {
            $output = [];
            $exitCode = 0;
            \exec($command . ' 2>&1', $output, $exitCode);
            return [$output, (int)$exitCode];
        }

        throw new \RuntimeException('服务器禁用了命令执行能力，暂时无法自动转换当前视频。');
    }

    private static function splitCommandOutput($output) {
        $output = trim((string)$output);
        if ($output === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $output);
        if (!is_array($lines)) {
            return [$output];
        }

        return array_values(array_filter(array_map('trim', $lines), static function ($line) {
            return $line !== '';
        }));
    }

    private static function readMoovAtom($filepath, $maxBytes) {
        if (!is_string($filepath) || $filepath === '' || !is_file($filepath) || !is_readable($filepath)) {
            return null;
        }

        $fileSize = @filesize($filepath);
        if ($fileSize === false || $fileSize < 8) {
            return null;
        }

        $handle = @fopen($filepath, 'rb');
        if ($handle === false) {
            return null;
        }

        $offset = 0;

        try {
            for ($i = 0; $i < 32 && $offset + 8 <= $fileSize; $i++) {
                if (fseek($handle, $offset) !== 0) {
                    break;
                }

                $header = fread($handle, 8);
                if ($header === false || strlen($header) < 8) {
                    break;
                }

                $size = self::readUint32Be(substr($header, 0, 4));
                $type = substr($header, 4, 4);
                $headerLen = 8;

                if ($size === 1) {
                    $extended = fread($handle, 8);
                    if ($extended === false || strlen($extended) < 8) {
                        break;
                    }
                    $size = self::readUint64Be($extended);
                    $headerLen = 16;
                } elseif ($size === 0) {
                    $size = $fileSize - $offset;
                }

                if (!is_numeric($size) || $size < $headerLen) {
                    break;
                }

                if ($type === 'moov') {
                    $payloadSize = (int)min($size - $headerLen, $maxBytes);
                    if ($payloadSize <= 0) {
                        return null;
                    }
                    if (fseek($handle, $offset + $headerLen) !== 0) {
                        break;
                    }

                    $buffer = fread($handle, $payloadSize);
                    return $buffer === false ? null : $buffer;
                }

                $nextOffset = $offset + $size;
                if ($nextOffset <= $offset) {
                    break;
                }
                $offset = $nextOffset;
            }
        } finally {
            fclose($handle);
        }

        return null;
    }

    private static function readUint32Be($bytes) {
        $parts = unpack('Nvalue', $bytes);
        return (int)$parts['value'];
    }

    private static function readUint64Be($bytes) {
        $parts = unpack('Nhigh/Nlow', $bytes);
        return (int)($parts['high'] * 4294967296 + $parts['low']);
    }

    private static function summarizeOutput(array $output) {
        if (empty($output)) {
            return '';
        }

        $tail = array_slice($output, -3);
        $tail = array_map('trim', $tail);
        $tail = array_values(array_filter($tail, static function ($line) {
            return $line !== '';
        }));

        return implode(' | ', $tail);
    }
}
