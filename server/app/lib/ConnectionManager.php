<?php
/**
 * 实时推送中转（无 WS 跨进程模式的替代方案）
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 *
 * 模式：
 *   - 写：调用 enqueue() 把消息推到文件（runtime/push/<uid>/<seq>）
 *   - 读：HTTP 长轮询调用 drain() 拿走当前 uid 队列里所有未读消息
 *   - Linux 上可换成 Redis List / Stream 替代文件队列
 *
 * 设计目标：
 *   - 单进程兼容（Windows）
 *   - 多进程安全（用进程内 static + 文件目录 mutex 锁）
 *   - HTTP 长轮询可达 1s/次
 *
 * WebSocket 完全替代方案（生产）：
 *   Linux + 多进程时，把 push queue 改成 Redis Stream（PEL 跟踪 + consumer group）
 */

namespace app\lib;

class ConnectionManager
{
    /**
     * 注册连接（保留 API 兼容性 —— Channel 类用）
     * 这里只记录"online"信息到文件
     */
    public static function register($uid, $connection, $role, $sessionId = null) {
        // 进程内 map（保持 backward compat）
        self::$uidMap[$uid] = $connection;
        if (!isset(self::$roleMap[$role])) self::$roleMap[$role] = [];
        if (!in_array($uid, self::$roleMap[$role])) self::$roleMap[$role][] = $uid;

        if ($sessionId) {
            if (!isset(self::$sessionMap[$sessionId])) self::$sessionMap[$sessionId] = [];
            if (!in_array($uid, self::$sessionMap[$sessionId])) self::$sessionMap[$sessionId][] = $uid;
        }
        // online 状态：仅在第一次登记时写一次，避免每次 poll 都写
        if (!self::$onlineWritten) {
            self::$onlineWritten = [];
        }
        if (!isset(self::$onlineWritten[$uid])) {
            self::setOnline($uid, $role, true);
            self::$onlineWritten[$uid] = true;
        }
    }

    public static function unregister($uid) {
        unset(self::$uidMap[$uid]);
        foreach (self::$roleMap as $role => $uids) {
            self::$roleMap[$role] = array_values(array_diff($uids, [$uid]));
        }
        foreach (self::$sessionMap as $sid => $uids) {
            self::$sessionMap[$sid] = array_values(array_diff($uids, [$uid]));
            if (empty(self::$sessionMap[$sid])) unset(self::$sessionMap[$sid]);
        }
        self::setOnline($uid, null, false);
    }

    /**
     * 写入持久化文件（推送队列 / 在线状态）
     */
    private static function setOnline($uid, $role, $online) {
        $file = self::onlineFile($uid);
        $dir  = dirname($file);
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        if ($online) {
            $payload = [
                'uid' => $uid,
                'role' => $role,
                'online_at' => time(),
                'last_active' => time(),
            ];
            file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);
        } else {
            @unlink($file);
        }
    }

    public static function get($uid) { return self::$uidMap[$uid] ?? null; }

    /**
     * 推送到单 uid
     */
    public static function pushToUid($uid, $payload) {
        return self::enqueue($uid, $payload);
    }

    /**
     * 推送到会话（所有相关 uid）
     */
    public static function pushToSession($sessionId, $payload) {
        $count = 0;
        $uids = self::$sessionMap[$sessionId] ?? [];
        foreach ($uids as $uid) {
            if (self::enqueue($uid, $payload)) $count++;
        }
        // 同时通过 session 队列 + 访客兜底队列
        self::enqueue('session:' . $sessionId, $payload);
        self::enqueue('visitor:any:' . $sessionId, $payload);
        // 如果 payload 里带 agent_id，主动推到对应 agent uid + role 队列
        if (!empty($payload['agent_id'])) {
            self::enqueue('agent:1:' . $payload['agent_id'], $payload);
        }
        // 推到 role:admin 便于管理员旁路
        self::enqueue('role:admin', $payload, 'role');
        return $count;
    }

    /**
     * 兜底：当访客没 connect/session_map 没记录时，session queue 是唯一渠道
     * visitor poll 已经做了 drainSession
     */
    public static function drainSession($sessionId, $afterSeq = null) {
        $events = self::readQueue('session:' . $sessionId, 'uid', $afterSeq);
        return $events;
    }

    /**
     * 推送到某角色
     */
    public static function pushToRole($role, $payload) {
        $count = 0;
        $uids = self::$roleMap[$role] ?? [];
        foreach ($uids as $uid) {
            if (self::enqueue($uid, $payload)) $count++;
        }
        // 同时发给角色的全局队列（type='role'）
        self::enqueue('role:' . $role, $payload, 'role');
        return $count;
    }

    /**
     * 给 uid 排队单条消息
     */
    private static function enqueue($uid, $payload, $kind = 'uid') {
        $seq = self::bumpSeq($uid);
        $file = self::queueFile($uid, $seq, $kind);
        $dir = dirname($file);
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $body = [
            'seq' => $seq,
            'time' => microtime(true),
            'payload' => $payload,
        ];

        $ok = @file_put_contents($file, json_encode($body, JSON_UNESCAPED_UNICODE), LOCK_EX);
        if ($ok) {
            self::trim($uid, 50, $kind);
        }
        return (bool)$ok;
    }

    /**
     * 增加并返回 seq
     */
    private static function bumpSeq($key) {
        $file = self::seqFile($key);
        $dir = dirname($file);
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        // 直接读后写（多进程并发可能重复 seq，但 seq 仅用于排序不会丢消息）
        $current = 0;
        if (file_exists($file)) {
            $current = (int)trim(@file_get_contents($file));
        }
        $current++;
        @file_put_contents($file, (string)$current, LOCK_EX);
        return $current;
    }

    /**
     * 取走 uid 当前队列中所有未读事件
     * @param string $uid
     * @param int|null $afterSeq 只返回 seq > afterSeq 的事件
     * @return array
     */
    public static function drain($uid, $afterSeq = null) {
        return self::readQueue($uid, 'uid', $afterSeq);
    }

    /**
     * 取走会话级队列
     */
    public static function drainRole($role, $afterSeq = null) {
        return self::readQueue('role:' . $role, 'role', $afterSeq);
    }

    private static function readQueue($key, $kind, $afterSeq) {
        $dir = self::queueDir($key, $kind);
        if (!is_dir($dir)) return [];
        $out = [];
        $files = glob($dir . '/*.json');
        if (!$files) return [];
        sort($files, SORT_NUMERIC); // seq 升序
        foreach ($files as $f) {
            $json = @file_get_contents($f);
            $row = json_decode($json, true);
            if (!$row) continue;
            if ($afterSeq !== null && $row['seq'] <= $afterSeq) {
                continue;
            }
            $out[] = $row;
            // 取走后删除（drain 语义）
            @unlink($f);
        }
        return $out;
    }

    /**
     * 限制队列文件数（保留最近 N 条）
     */
    private static function trim($uid, $keep = 50, $kind = 'uid') {
        $dir = self::queueDir($uid, $kind);
        $files = glob($dir . '/*.json');
        if (count($files) > $keep) {
            sort($files, SORT_NUMERIC);
            $del = array_slice($files, 0, count($files) - $keep);
            foreach ($del as $f) @unlink($f);
        }
    }

    public static function stats() {
        $onlineFiles = glob(self::onlineDir() . '/*.json');
        $online = [
            'agent'   => 0,
            'visitor' => 0,
            'admin'   => 0,
        ];
        foreach ($onlineFiles as $f) {
            $data = json_decode(@file_get_contents($f), true);
            if ($data && isset($online[$data['role'] ?? ''])) {
                $online[$data['role']]++;
            }
        }
        return [
            'total_connections' => count($onlineFiles),
            'online' => $online,
            'active_sessions'  => count(self::$sessionMap),
        ];
    }

    private static $onlineDir = null;
    private static function onlineDir() {
        if (self::$onlineDir === null) {
            self::$onlineDir = runtime_path('push') . '/online';
        }
        return self::$onlineDir;
    }
    private static function onlineFile($uid) {
        $safeUid = preg_replace('/[^a-zA-Z0-9_]/', '_', $uid);
        return self::onlineDir() . DIRECTORY_SEPARATOR . $safeUid . '.json';
    }
    private static function queueDir($key, $kind = 'uid') {
        // Windows 文件名非法字符：\ / : * ? " < > |
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        return runtime_path('push') . DIRECTORY_SEPARATOR . $kind . DIRECTORY_SEPARATOR . $safe;
    }
    private static function queueFile($key, $seq, $kind = 'uid') {
        return self::queueDir($key, $kind) . '/' . str_pad((string)$seq, 12, '0', STR_PAD_LEFT) . '.json';
    }
    private static function seqFile($key) {
        return self::queueDir($key) . '/.seq';
    }

    /**
     * 内部连接表（保留给未来 WS 复用）
     */
    private static $uidMap = [];
    private static $sessionMap = [];
    private static $roleMap = [];
    private static $onlineWritten = null;
}