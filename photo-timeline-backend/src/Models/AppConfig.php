<?php
namespace App\Models;

class AppConfig extends Model {
    protected $table = 'appconfig';

    /**
     * 获取全局配置
     */
    public function getConfig() {
        return $this->find(1) ?: [];
    }
}
