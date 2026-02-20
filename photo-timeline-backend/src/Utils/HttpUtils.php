<?php
namespace App\Utils;

class HttpUtils {
    /**
     * HTTP GET 请求
     */
    public static function get($url, $headers = [], $sslVerify = true) {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => $sslVerify,
                CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            ];
            if ($headers) {
                $opts[CURLOPT_HTTPHEADER] = $headers;
            }
            curl_setopt_array($ch, $opts);
            $response = curl_exec($ch);
            curl_close($ch);
            return $response ?: null;
        } elseif (ini_get('allow_url_fopen')) {
            $opts = [
                'http' => ['timeout' => 5],
                'ssl' => [
                    'verify_peer' => $sslVerify,
                    'verify_peer_name' => $sslVerify,
                ],
            ];
            if ($headers) {
                $opts['http']['header'] = implode("\r\n", $headers);
            }
            return @file_get_contents($url, false, stream_context_create($opts)) ?: null;
        }
        return null;
    }

    /**
     * 设置跨域投递头
     */
    public static function setCorsHeaders($config) {
        $origin = rtrim($_SERVER['HTTP_ORIGIN'] ?? '', '/');
        $rawAllowedOrigins = $config['cors_allowed_origins'] ?? ['*'];
        $allowedOrigins = array_map(function($url) { return rtrim($url, '/'); }, $rawAllowedOrigins);
        $hasWildcard = in_array('*', $allowedOrigins, true);

        if ($hasWildcard) {
            header("Access-Control-Allow-Origin: *");
        } elseif ($origin && in_array($origin, $allowedOrigins, true)) {
            header("Access-Control-Allow-Origin: " . ($_SERVER['HTTP_ORIGIN'] ?? ''));
            header('Vary: Origin');
        }

        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, x-api-key");
        header("Content-Type: application/json; charset=UTF-8");

        // OPTIONS 预检请求直接退出
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
    }

    /**
     * 响应 JSON 数据并退出
     */
    public static function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode($data);
        exit();
    }
}
