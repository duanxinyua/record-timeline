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
