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

    private function normalizeGroupId($groupId): string {
        return trim((string)$groupId);
    }

    private function hasGroupId(array $item): bool {
        return isset($item['group_id']) && $this->normalizeGroupId($item['group_id']) !== '';
    }

    /**
     * 当 group_id 为空时，按前端逻辑兜底生成：毫秒时间戳-5位随机36进制
     */
    private function generateGroupIdFromData(array $data): string {
        $ms = 0;
        if (!empty($data['date'])) {
            $ts = strtotime((string)$data['date']);
            if ($ts !== false) {
                $ms = (int)round($ts * 1000);
            }
        }
        if ($ms <= 0) {
            $ms = (int)floor(microtime(true) * 1000);
        }

        $rand = base_convert((string)random_int(0, 60466175), 10, 36);
        $rand = str_pad($rand, 5, '0', STR_PAD_LEFT);

        return $ms . '-' . $rand;
    }

    /**
     * 获取列表页 (GET /items)
     */
    public function getList() {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 0;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';

        $baseWhere = "deleted_at IS NULL";
        $params = [];

        if (!empty($search)) {
            $baseWhere .= " AND (title LIKE :search OR description LIKE :search OR address LIKE :search)";
            $params[':search'] = "%{$search}%";
        }

        // 空 group_id 与 NULL 都回退到 id，确保 SQL 分组与 PHP 聚合语义一致
        $gidExpr = "COALESCE(NULLIF(group_id, ''), CAST(id AS TEXT))";

        // 依靠分组表达式获取独立的"动态帖子"
        $groupSql = "SELECT $gidExpr as gid, MAX(date) as gdate FROM timelineitem WHERE $baseWhere GROUP BY $gidExpr ORDER BY gdate DESC";
        
        if ($page > 0 && $limit > 0) {
            $offset = ($page - 1) * $limit;
            $groupListSql = $groupSql . " LIMIT $limit OFFSET $offset";
        } else {
            $groupListSql = $groupSql;
        }

        $groups = $this->model->query($groupListSql, $params);

        // 计算总动态数
        $countSql = "SELECT COUNT(DISTINCT $gidExpr) as total FROM timelineitem WHERE $baseWhere";
        $totalRows = $this->model->query($countSql, $params);
        $total = $totalRows ? (int)$totalRows[0]['total'] : 0;

        if ($page > 0 && $limit > 0) {
            header('X-Total-Count: ' . $total);
            header('X-Page: ' . $page);
            header('X-Limit: ' . $limit);
        }

        $resultItems = [];
        if (!empty($groups)) {
            $gids = array_column($groups, 'gid');
            $inQuery = implode(',', array_fill(0, count($gids), '?'));
            
            // 一次性查出属于这些组的所有图片项
            $itemsSql = "SELECT * FROM timelineitem WHERE deleted_at IS NULL AND $gidExpr IN ($inQuery) ORDER BY date ASC";
            $rawItems = $this->model->query($itemsSql, $gids);
            
            // 补全需要逆向解析的地理位置
            $this->resolveAddresses($rawItems);

            // 在 PHP 中进行整合封装
            $grouped = [];
            foreach ($rawItems as $item) {
                $gid = $this->normalizeGroupId($item['group_id'] ?? '');
                if ($gid === '') {
                    $gid = (string)$item['id'];
                }
                if (!isset($grouped[$gid])) {
                    $grouped[$gid] = $item;
                    $grouped[$gid]['media'] = [];
                    // 清理不再需要在顶层的冗余属性
                    unset($grouped[$gid]['src'], $grouped[$gid]['thumb'], $grouped[$gid]['latitude'], $grouped[$gid]['longitude']);
                }
                
                // 将各自媒体信息推入 media 这个数组
                $grouped[$gid]['media'][] = [
                    'id' => $item['id'],
                    'src' => $item['src'],
                    'thumb' => $item['thumb'],
                    'taken_at' => $item['taken_at'],
                    'latitude' => $item['latitude'],
                    'longitude' => $item['longitude'],
                    'address' => $item['address'],
                ];
                
                // 确保空标题可以被后续的图补足
                if (empty($grouped[$gid]['title']) && !empty($item['title'])) $grouped[$gid]['title'] = $item['title'];
                if (empty($grouped[$gid]['description']) && !empty($item['description'])) $grouped[$gid]['description'] = $item['description'];
            }
            
            // 依照原倒序 MAX(date) 排列
            foreach ($groups as $g) {
                if (isset($grouped[$g['gid']])) {
                    $resultItems[] = $grouped[$g['gid']];
                }
            }
        }

        HttpUtils::jsonResponse($resultItems);
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

        $hasLat = $lat !== null && $lat !== '';
        $hasLng = $lng !== null && $lng !== '';
        if (!$address && $hasLat && $hasLng) {
            $address = GeoUtils::resolveAddress($lat, $lng, $this->config['amap_key']);
        }

        $groupId = isset($data['group_id']) ? trim((string)$data['group_id']) : '';
        if ($groupId === '') {
            $groupId = $this->generateGroupIdFromData($data);
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
            'description' => $data['description'] ?? null,
            'group_id' => $groupId
        ];

        $id = $this->model->insert($insertData);
        $data['id'] = (int)$id;
        $data['address'] = $address;
        $data['group_id'] = $groupId;

        HttpUtils::jsonResponse($data);
    }

    /**
     * 更新记录 (PUT /items/{id})
     */
    public function update($id) {
        $data = $this->getJsonBody();
        $target = $this->model->find($id);

        if (!$target) {
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
            // 如果存在组 ID，同步更新这个组下所有的 title/description 等同源字段
            if ($this->hasGroupId($target)) {
                $targetGroupId = $this->normalizeGroupId($target['group_id']);
                $subUpdate = [];
                if (isset($updateData['title'])) $subUpdate['title'] = $updateData['title'];
                if (isset($updateData['description'])) $subUpdate['description'] = $updateData['description'];
                if (isset($updateData['date'])) $subUpdate['date'] = $updateData['date'];
                
                if (!empty($subUpdate)) {
                    $setClauses = [];
                    $params = [];
                    foreach ($subUpdate as $field => $value) {
                        $setClauses[] = "{$field} = ?";
                        $params[] = $value;
                    }
                    $params[] = $targetGroupId;
                    $sql = "UPDATE timelineitem SET " . implode(', ', $setClauses) . " WHERE group_id = ?";
                    $this->model->query($sql, $params);
                }
            } else {
                $this->model->update($id, $updateData);
            }
        }

        HttpUtils::jsonResponse($this->model->find($id));
    }

    /**
     * 软删除 (DELETE /items/{id})
     */
    public function delete($id) {
        $item = $this->model->find($id);
        if (empty($item) || $item['deleted_at'] !== null) {
            HttpUtils::jsonResponse(["detail" => "条目不存在"], 404);
        }

        if ($this->hasGroupId($item)) {
            $groupId = $this->normalizeGroupId($item['group_id']);
            $this->model->query("UPDATE timelineitem SET deleted_at = ? WHERE group_id = ?", [date('c'), $groupId]);
        } else {
            $this->model->softDelete($id);
        }
        
        HttpUtils::jsonResponse(["ok" => true]);
    }

    /**
     * 恢复软删除 (POST /items/{id}/restore)
     */
    public function restore($id) {
        $item = $this->model->find($id);
        if (empty($item) || $item['deleted_at'] === null) {
            HttpUtils::jsonResponse(["detail" => "条目不在回收站中"], 404);
        }

        if ($this->hasGroupId($item)) {
            $groupId = $this->normalizeGroupId($item['group_id']);
            $this->model->query("UPDATE timelineitem SET deleted_at = NULL WHERE group_id = ?", [$groupId]);
        } else {
            $this->model->restore($id);
        }
        
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

        // 以防这是一个 group 的项目
        if ($this->hasGroupId($item)) {
            $groupId = $this->normalizeGroupId($item['group_id']);
            $items = $this->model->getList("group_id = ?", [$groupId]);
            foreach ($items as $itm) {
                $this->model->delete($itm['id']);
                ImageUtils::deleteUploadedFile($itm['src'], $this->config['upload_dir']);
                ImageUtils::deleteUploadedFile($itm['thumb'], $this->config['upload_dir']);
            }
        } else {
            $this->model->delete($id);
            ImageUtils::deleteUploadedFile($item['src'], $this->config['upload_dir']);
            ImageUtils::deleteUploadedFile($item['thumb'], $this->config['upload_dir']);
        }

        HttpUtils::jsonResponse(["ok" => true]);
    }

    /**
     * 获取回收站列表 (GET /trash)
     */
    public function getTrash() {
        $items = $this->model->getList("deleted_at IS NOT NULL", [], "ORDER BY deleted_at DESC");
        // 因为在回收站只是预览删除的历史记录图片即可，暂时不将其聚合成动态对象
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
            $hasLat = isset($item['latitude']) && $item['latitude'] !== null && $item['latitude'] !== '';
            $hasLng = isset($item['longitude']) && $item['longitude'] !== null && $item['longitude'] !== '';
            $missingAddress = !isset($item['address']) || $item['address'] === null || $item['address'] === '';
            if ($hasLat && $hasLng && $missingAddress) {
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
