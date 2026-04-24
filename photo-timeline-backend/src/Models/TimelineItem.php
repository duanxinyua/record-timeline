<?php
namespace App\Models;

class TimelineItem extends Model {
    protected $table = 'timelineitem';

    /**
     * 随机获取需要解析地址的记录（不超过$limit条）
     */
    public function getUnresolvedAddresses($limit = 2) {
        $sql = "SELECT id, latitude, longitude FROM {$this->table} WHERE deleted_at IS NULL AND latitude IS NOT NULL AND longitude IS NOT NULL AND address IS NULL LIMIT ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * 获取所有可重建地址的记录
     */
    public function getAddressableItems() {
        $sql = "SELECT id, latitude, longitude, address FROM {$this->table} WHERE deleted_at IS NULL AND latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY id ASC";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * 更新单条记录的地址
     */
    public function updateAddress($id, $address) {
        $stmt = $this->pdo->prepare("UPDATE {$this->table} SET address = ? WHERE id = ?");
        return $stmt->execute([$address, $id]);
    }

    /**
     * 软删除：标记 deleted_at
     */
    public function softDelete($id) {
        return $this->update($id, ['deleted_at' => date('c')]);
    }

    /**
     * 恢复软删除
     */
    public function restore($id) {
        $stmt = $this->pdo->prepare("UPDATE {$this->table} SET deleted_at = NULL WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * 获取所有带有删除标记的图片及其缩略图（用于清理文件）
     */
    public function getTrashedMedia() {
        $stmt = $this->pdo->query("SELECT src, thumb FROM {$this->table} WHERE deleted_at IS NOT NULL");
        return $stmt->fetchAll();
    }

    /**
     * 查出当前数据库仍在引用的媒体 URL（包含 src 和 thumb，含回收站）
     */
    public function getReferencedMediaUrls(array $urls) {
        $normalized = [];
        foreach ($urls as $url) {
            $value = trim((string)$url);
            if ($value !== '') {
                $normalized[$value] = true;
            }
        }

        $normalizedUrls = array_keys($normalized);
        if (empty($normalizedUrls)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($normalizedUrls), '?'));
        $sql = "
            SELECT src AS url FROM {$this->table} WHERE src IN ($placeholders)
            UNION
            SELECT thumb AS url FROM {$this->table} WHERE thumb IN ($placeholders)
        ";

        $params = array_merge($normalizedUrls, $normalizedUrls);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_values(array_filter(array_unique(array_column($rows, 'url'))));
    }

    /**
     * 清空回收站
     */
    public function emptyTrash() {
        return $this->pdo->exec("DELETE FROM {$this->table} WHERE deleted_at IS NOT NULL");
    }

    /**
     * 清空所有地址
     */
    public function clearAddresses() {
        return $this->pdo->exec("UPDATE {$this->table} SET address = NULL WHERE address IS NOT NULL AND deleted_at IS NULL");
    }
}
