<?php
namespace App\Utils;

class GeoUtils {
    private const GCJ_PI = 3.1415926535897932384626;
    private const GCJ_A = 6378245.0;
    private const GCJ_EE = 0.00669342162296594323;

    /**
     * 逆地理编码：坐标转地址
     * 优先高德地图（需 amap_key），兜底 Nominatim
     */
    public static function resolveAddress($lat, $lng, $amapKey = '', $sslVerify = true) {
        $hasLat = $lat !== null && $lat !== '';
        $hasLng = $lng !== null && $lng !== '';
        if (!$hasLat || !$hasLng) return null;

        $lat = (float)$lat;
        $lng = (float)$lng;

        // 方案1：高德地图（中国大陆需要先将照片 EXIF 的 WGS84 转为 GCJ-02）
        if (!empty($amapKey)) {
            list($amapLat, $amapLng) = self::wgs84ToGcj02($lat, $lng);
            $url = "https://restapi.amap.com/v3/geocode/regeo?" . http_build_query([
                'key' => $amapKey,
                'location' => round($amapLng, 6) . ',' . round($amapLat, 6),
                'extensions' => 'base'
            ]);
            $response = HttpUtils::get($url, [], $sslVerify);
            if ($response) {
                $data = json_decode($response, true);
                if ($data && isset($data['status']) && $data['status'] === '1'
                    && !empty($data['regeocode']['formatted_address'])) {
                    return $data['regeocode']['formatted_address'];
                }
            }
        }

        // 方案2：Nominatim / OpenStreetMap
        $url = "https://nominatim.openstreetmap.org/reverse?" . http_build_query([
            'lat' => round($lat, 6),
            'lon' => round($lng, 6),
            'format' => 'json',
            'accept-language' => 'zh',
            'zoom' => 16
        ]);
        $response = HttpUtils::get($url, ['User-Agent: PeanutTimeline/1.0'], $sslVerify);
        if ($response) {
            $data = json_decode($response, true);
            if (!empty($data['display_name'])) {
                return $data['display_name'];
            }
        }

        return null;
    }

    /**
     * 照片 EXIF GPS 默认是 WGS84；中国大陆地图服务通常使用 GCJ-02。
     */
    public static function wgs84ToGcj02($lat, $lng): array {
        $lat = (float)$lat;
        $lng = (float)$lng;

        if (self::isOutsideChinaMainland($lat, $lng)) {
            return [$lat, $lng];
        }

        $dLat = self::transformLat($lng - 105.0, $lat - 35.0);
        $dLng = self::transformLng($lng - 105.0, $lat - 35.0);
        $radLat = $lat / 180.0 * self::GCJ_PI;
        $magic = sin($radLat);
        $magic = 1 - self::GCJ_EE * $magic * $magic;
        $sqrtMagic = sqrt($magic);

        $dLat = ($dLat * 180.0) / ((self::GCJ_A * (1 - self::GCJ_EE)) / ($magic * $sqrtMagic) * self::GCJ_PI);
        $dLng = ($dLng * 180.0) / (self::GCJ_A / $sqrtMagic * cos($radLat) * self::GCJ_PI);

        return [$lat + $dLat, $lng + $dLng];
    }

    private static function isOutsideChinaMainland(float $lat, float $lng): bool {
        return $lng < 73.66 || $lng > 135.05 || $lat < 3.86 || $lat > 53.55;
    }

    private static function transformLat(float $lng, float $lat): float {
        $ret = -100.0 + 2.0 * $lng + 3.0 * $lat + 0.2 * $lat * $lat + 0.1 * $lng * $lat + 0.2 * sqrt(abs($lng));
        $ret += (20.0 * sin(6.0 * $lng * self::GCJ_PI) + 20.0 * sin(2.0 * $lng * self::GCJ_PI)) * 2.0 / 3.0;
        $ret += (20.0 * sin($lat * self::GCJ_PI) + 40.0 * sin($lat / 3.0 * self::GCJ_PI)) * 2.0 / 3.0;
        $ret += (160.0 * sin($lat / 12.0 * self::GCJ_PI) + 320 * sin($lat * self::GCJ_PI / 30.0)) * 2.0 / 3.0;
        return $ret;
    }

    private static function transformLng(float $lng, float $lat): float {
        $ret = 300.0 + $lng + 2.0 * $lat + 0.1 * $lng * $lng + 0.1 * $lng * $lat + 0.1 * sqrt(abs($lng));
        $ret += (20.0 * sin(6.0 * $lng * self::GCJ_PI) + 20.0 * sin(2.0 * $lng * self::GCJ_PI)) * 2.0 / 3.0;
        $ret += (20.0 * sin($lng * self::GCJ_PI) + 40.0 * sin($lng / 3.0 * self::GCJ_PI)) * 2.0 / 3.0;
        $ret += (150.0 * sin($lng / 12.0 * self::GCJ_PI) + 300.0 * sin($lng / 30.0 * self::GCJ_PI)) * 2.0 / 3.0;
        return $ret;
    }
}
