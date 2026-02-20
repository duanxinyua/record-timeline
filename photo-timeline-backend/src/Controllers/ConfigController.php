<?php
namespace App\Controllers;

use App\Models\AppConfig;
use App\Utils\HttpUtils;

class ConfigController {
    protected $model;

    public function __construct(AppConfig $model) {
        $this->model = $model;
    }

    private function getJsonBody() {
        return json_decode(file_get_contents('php://input'), true);
    }

    /**
     * 获取配置 (GET /config)
     */
    public function getConfig() {
        $config_data = $this->model->getConfig();
        HttpUtils::jsonResponse($config_data);
    }

    /**
     * 更新配置 (POST /config)
     */
    public function updateConfig() {
        $data = $this->getJsonBody();

        // 白名单字段
        $fields = ['appTitle', 'kicker', 'mainTitle', 'subTitle', 'timelineTitle', 'emptyText', 'defaultItemTitle', 'unknownDateText', 'pageSize', 'loadingText', 'loadMoreText', 'endText', 'takenAtLabel'];
        $updateData = [];

        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (!empty($updateData)) {
            $this->model->update(1, $updateData);
        }

        $config_data = $this->model->getConfig();
        HttpUtils::jsonResponse($config_data);
    }
}
