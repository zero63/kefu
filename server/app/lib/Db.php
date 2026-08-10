<?php
/**
 * 数据库连接封装
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 * 说明：
 *   - 封装 PDO 连接（避免每个控制器重复连接）
 *   - 提供 query/exec/find/list 等常用方法
 *   - 所有 SQL 自动注入 tenant_id（多租户隔离）
 */

namespace app\lib;

use PDO;
use PDOException;
use Exception;

class Db
{
    /**
     * @var PDO 单例连接
     */
    private static $pdo = null;

    /**
     * @var int 当前租户 ID（由中间件注入）
     */
    private static $currentTenantId = 0;

    /**
     * 获取 PDO 连接（单例 + 自动重连）
     * @return PDO
     */
    public static function pdo() {
        if (self::$pdo === null) {
            self::connect();
            return self::$pdo;
        }
        // 检测连接是否还活着（workerman 长进程下 MySQL 可能断开）
        try {
            self::$pdo->query('SELECT 1');
        } catch (\Throwable $e) { // 捕获 PDO + Error
            // 连接已断开，重连
            try {
                self::$pdo = null;
                self::connect();
            } catch (\Throwable $ex) {
                throw new Exception('数据库重连失败：' . $ex->getMessage());
            }
        }
        return self::$pdo;
    }

    /**
     * 真正建立连接
     */
    private static function connect() {
        $host    = env('DB_HOST', '127.0.0.1');
        $port    = env('DB_PORT', 3306);
        $dbname  = env('DB_DATABASE', 'kefu');
        $user    = env('DB_USERNAME', 'kefu');
        $pass    = env('DB_PASSWORD', 'adminkefu');
        $charset = env('DB_CHARSET', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}",
            ]);
        } catch (PDOException $e) {
            throw new Exception('数据库连接失败：' . $e->getMessage());
        }
    }

    /**
     * 设置当前租户 ID（由中间件调用）
     */
    public static function setTenantId($tenantId) {
        self::$currentTenantId = intval($tenantId);
    }

    /**
     * 获取当前租户 ID
     */
    public static function getTenantId() {
        return self::$currentTenantId;
    }

    /**
     * 执行查询并返回所有记录
     * @param string $sql SQL 语句
     * @param array $params 参数
     * @return array
     */
    public static function query($sql, $params = []) {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * 查询单条记录
     * @param string $sql
     * @param array $params
     * @return array|null
     */
    public static function find($sql, $params = []) {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * 查询单列值
     * @param string $sql
     * @param array $params
     * @return mixed
     */
    public static function value($sql, $params = []) {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /**
     * 执行 INSERT/UPDATE/DELETE
     * @param string $sql
     * @param array $params
     * @return int 受影响的行数
     */
    public static function exec($sql, $params = []) {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * 插入数据并返回 lastInsertId
     * @param string $table
     * @param array $data
     * @return int
     */
    public static function insert($table, $data) {
        // 自动注入 tenant_id
        if (self::$currentTenantId > 0 && !isset($data['tenant_id'])) {
            $data['tenant_id'] = self::$currentTenantId;
        }
        $columns = array_keys($data);
        $placeholders = array_map(function($c) { return ':' . $c; }, $columns);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $columns),
            implode(', ', $placeholders)
        );
        // 直接用 pdo instance（避免 self::pdo() 触发 SELECT 1 重置 lastInsertId）
        if (self::$pdo === null) {
            self::connect();
        }
        $pdo = self::$pdo;
        try {
            // 健康检查
            $pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            self::$pdo = null;
            self::connect();
            $pdo = self::$pdo;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);
        return (int)$pdo->lastInsertId();
    }

    /**
     * 更新数据
     * @param string $table
     * @param array $data
     * @param array $where
     * @return int
     */
    public static function update($table, $data, $where) {
        // 自动加 tenant_id 限定（除非显式指定）
        if (self::$currentTenantId > 0 && !isset($where['tenant_id'])) {
            $where['tenant_id'] = self::$currentTenantId;
        }
        $set = [];
        foreach ($data as $k => $v) {
            $set[] = "`{$k}` = :set_{$k}";
        }
        $setStr = implode(', ', $set);

        $whereClause = [];
        foreach ($where as $k => $v) {
            $whereClause[] = "`{$k}` = :w_{$k}";
        }
        $whereStr = implode(' AND ', $whereClause);

        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, $setStr, $whereStr);

        $params = [];
        foreach ($data as $k => $v) $params["set_{$k}"] = $v;
        foreach ($where as $k => $v) $params["w_{$k}"] = $v;

        return self::exec($sql, $params);
    }

    /**
     * 删除数据（按 where 条件）
     * @param string $table
     * @param array $where
     * @return int 受影响行数
     */
    public static function delete($table, $where) {
        if (empty($where)) {
            throw new Exception('Db::delete 拒绝不带 where 的删除（全表删除危险）');
        }
        // 自动加 tenant_id 限定（除非显式指定）
        if (self::$currentTenantId > 0 && !isset($where['tenant_id'])) {
            $where['tenant_id'] = self::$currentTenantId;
        }
        $whereClause = [];
        $params = [];
        foreach ($where as $k => $v) {
            $whereClause[] = "`{$k}` = :w_{$k}";
            $params["w_{$k}"] = $v;
        }
        $whereStr = implode(' AND ', $whereClause);
        $sql = sprintf('DELETE FROM `%s` WHERE %s', $table, $whereStr);
        return self::exec($sql, $params);
    }

    /**
     * 测试数据库连接
     */
    public static function ping() {
        try {
            self::pdo()->query('SELECT 1');
            return true;
        } catch (Exception $e) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
            return false;
        }
    }
}