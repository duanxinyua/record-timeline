<?php
namespace App\Utils;

class GeoUtils {
    /**
     * 逆地理编码：坐标转地址
     * 优先高德地图（需 amap_key），兜底 Nominatim
     */
    public static function resolveAddress($lat, $lng, $amapKey = '', $sslVerify = true) {
        if (!$lat || !$lng) return null;

        // 方案1：高德地图
        if (!empty($amapKey)) {
            $url = "https://restapi.amap.com/v3/geocode/regeo?" . http_build_query([
                'key' => $amapKey,
                'location' => round($lng, 6) . ',' . round($lat, 6),
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
}
