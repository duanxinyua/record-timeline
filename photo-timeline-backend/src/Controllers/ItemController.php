<?php
namespace App\Controllers;

use App\Models\TimelineItem;
use App\Utils\HttpUtils;
use App\Utils\GeoUtils;
use App\Utils\ImageUtils;

class ItemController {
    protected $model;
    protected $config;

    public function __construct(TimelineItem $model, $config) {
        $this->model = $model;
        $this->config = $config;
    }

    private function getJsonBody() {
        return json_decode(file_get_contents('php://input'), true);
    }

    /**
     * 获取列表页 (GET /items)
     */
    public function getList() {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 0;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';

        $whereClause = "deleted_at IS NULL";
        $params = [];

        if (!empty($search)) {
            $whereClause .= " AND (title LIKE :search OR description LIKE :search OR address LIKE :search)";
            $params[':search'] = "%{$search}%";
        }

        if ($page > 0 && $limit > 0) {
            $offset = ($page - 1) * $limit;
            $items = $this->model->getList($whereClause, $params, "ORDER BY date DESC", $limit, $offset);
            $total = $this->model->getCount($whereClause, $params);
            
            header('X-Total-Count: ' . $total);
            header('X-Page: ' . $page);
            header('X-Limit: ' . $limit);
        } else {
            $items = $this->model->getList($whereClause, $params, "ORDER BY date DESC");
        }

        // 自动补全缺失地址
        $this->resolveAddresses($items);

        HttpUtils::jsonResponse($items);
    }

    /**
     * 新建记录 (POST /items)
     */
    public function create() {
        $data = $this->getJsonBody();

        if (!$data || !isset($data['date']) || !isset($data['src'])) {
            HttpUtils::jsonResponse(["detail" => "缺少必填字段 (date, src)"], 400);
        }

        $lat = $data['latitude'] ?? null;
        $lng = $data['longitude'] ?? null;
        $address = $data['address'] ?? null;

        if (!$address && $lat && $lng) {
            $address = GeoUtils::resolveAddress($lat, $lng, $this->config['amap_key']);
        }

        $insertData = [
            'title' => $data['title'] ?? '',
            'date' => $data['date'],
            'src' => $data['src'],
            'thumb' => $data['thumb'] ?? null,
            'latitude' => $lat,
            'longitude' => $lng,
            'taken_at' => $data['taken_at'] ?? null,
            'address' => $address,
            'description' => $data['description'] ?? null
        ];

        $id = $this->model->insert($insertData);
        $data['id'] = (int)$id;
        $data['address'] = $address;

        HttpUtils::jsonResponse($data);
    }

    /**
     * 更新记录 (PUT /items/{id})
     */
    public function update($id) {
        $data = $this->getJsonBody();

        if (!$this->model->find($id)) {
            HttpUtils::jsonResponse(["detail" => "条目不存在"], 404);
        }

        $updateData = [];
        $fields = ['title', 'date', 'description', 'thumb'];
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (!empty($updateData)) {
            $this->model->update($id, $updateData);
        }

        HttpUtils::jsonResponse($this->model->find($id));
    }

    /**
     * 软删除 (DELETE /items/{id})
     */
    public function delete($id) {
        $item = $this->model->getList("id = :id AND deleted_at IS NULL", [':id' => $id]);
        if (empty($item)) {
            HttpUtils::jsonResponse(["detail" => "条目不存在"], 404);
        }

        $this->model->softDelete($id);
        HttpUtils::jsonResponse(["ok" => true]);
    }

    /**
     * 恢复软删除 (POST /items/{id}/restore)
     */
    public function restore($id) {
        $item = $this->model->getList("id = :id AND deleted_at IS NOT NULL", [':id' => $id]);
        if (empty($item)) {
            HttpUtils::jsonResponse(["detail" => "条目不在回收站中"], 404);
        }

        $this->model->restore($id);
        HttpUtils::jsonResponse($this->model->find($id));
    }

    /**
     * 永久删除 (DELETE /items/{id}/permanent)
     */
    public function permanentDelete($id) {
        $item = $this->model->find($id);
        if (!$item) {
            HttpUtils::jsonResponse(["detail" => "条目不存在"], 404);
        }

        $this->model->delete($id);
        ImageUtils::deleteUploadedFile($item['src'], $this->config['upload_dir']);
        ImageUtils::deleteUploadedFile($item['thumb'], $this->config['upload_dir']);

        HttpUtils::jsonResponse(["ok" => true]);
    }

    /**
     * 获取回收站列表 (GET /trash)
     */
    public function getTrash() {
        $items = $this->model->getList("deleted_at IS NOT NULL", [], "ORDER BY deleted_at DESC");
        HttpUtils::jsonResponse($items);
    }

    /**
     * 清空回收站 (POST /empty-trash)
     */
    public function emptyTrash() {
        $trashItems = $this->model->getTrashedMedia();
        foreach ($trashItems as $item) {
            ImageUtils::deleteUploadedFile($item['src'], $this->config['upload_dir']);
            ImageUtils::deleteUploadedFile($item['thumb'], $this->config['upload_dir']);
        }

        $count = $this->model->emptyTrash();
        HttpUtils::jsonResponse(["ok" => true, "deleted" => (int)$count]);
    }

    /**
     * 清除缓存的地址 (POST /clear-addresses)
     */
    public function clearAddresses() {
        $count = $this->model->clearAddresses();
        HttpUtils::jsonResponse(["ok" => true, "cleared" => (int)$count]);
    }

    /**
     * 辅助方法：自动补全未解析的地理位置
     */
    private function resolveAddresses(&$items) {
        $resolved = 0;
        foreach ($items as &$item) {
            if ($resolved >= 2) break;
            if (!empty($item['latitude']) && !empty($item['longitude']) && empty($item['address'])) {
                $address = GeoUtils::resolveAddress($item['latitude'], $item['longitude'], $this->config['amap_key']);
                if ($address) {
                    $item['address'] = $address;
                    $this->model->update($item['id'], ['address' => $address]);
                    $resolved++;
                }
            }
        }
    }
}
