<?php
namespace App\Models;

use PDO;

class Model {
    protected $pdo;
    protected $table;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * 获取带条件的数据列表
     */
    public function getList($where = "1=1", $params = [], $orderBy = "", $limit = 0, $offset = 0) {
        $sql = "SELECT * FROM {$this->table} WHERE {$where} {$orderBy}";
        
        if ($limit > 0) {
            $sql .= " LIMIT :limit OFFSET :offset";
            $params[':limit'] = (int)$limit;
            $params[':offset'] = (int)$offset;
        }

        $stmt = $this->pdo->prepare($sql);
        
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            if (is_int($key)) {
                $stmt->bindValue($key + 1, $value, $type);
            } else {
                $stmt->bindValue($key, $value, $type);
            }
        }
        
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * 获取数据总数
     */
    public function getCount($where = "1=1", $params = []) {
        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$where}";
        $stmt = $this->pdo->prepare($sql);
        
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            if (is_int($key)) {
                $stmt->bindValue($key + 1, $value, $type);
            } else {
                $stmt->bindValue($key, $value, $type);
            }
        }
        
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * 查找单条记录
     */
    public function find($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * 插入新记录
     */
    public function insert($data) {
        $fields = array_keys($data);
        $placeholders = array_fill(0, count($fields), '?');
        
        $sql = "INSERT INTO {$this->table} (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));
        
        return $this->pdo->lastInsertId();
    }

    /**
     * 更新已有记录
     */
    public function update($id, $data) {
        $setClauses = [];
        $params = [];
        
        foreach ($data as $field => $value) {
            $setClauses[] = "{$field} = ?";
            $params[] = $value;
        }
        
        $params[] = $id;
        
        $sql = "UPDATE {$this->table} SET " . implode(', ', $setClauses) . " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * 真删除记录
     */
    public function delete($id) {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * 自定义执行预处理SQL，返回受影响行数或查询结果
     */
    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        if (strpos(strtoupper(trim($sql)), 'SELECT') === 0) {
            return $stmt->fetchAll();
        }
        return $stmt->rowCount();
    }
}
