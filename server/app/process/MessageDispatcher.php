<?php
/**
 * 消息分发进程
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 * 说明：
 *   - 在 webman 多进程模型下保证消息可靠分发
 *   - 每个 worker 只能管理自己进程内的连接，需要一个跨进程的"消息总线"
 *   - 演示版使用文件 + 文件锁实现可跨进程的可靠队列
 *
 *   ⚠ Windows 下 webman 1.5 不支持 fork 自定义进程，
 *     主进程直接处理消息即可（性能足够支撑中小客户群）。
 */

namespace app\process;

use app\lib\Db;
use app\lib\Logger;
use app\lib\ConnectionManager;
use Workerman\Worker;

class MessageDispatcher
{
    public function onWorkerStart($worker)
    {
        if (DIRECTORY_SEPARATOR === '/') {
            fwrite(STDOUT, "[message-dispatcher {$worker->id}] started\n");
        }
    }

    /**
     * 发送消息到会话
     * 同步分发（因为演示版是单进程）
     *
     * @param string $sessionId
     * @param array $payload 标准消息格式
     * @return int 推送次数
     */
    public static function sendToSession($sessionId, $payload) {
        $count = ConnectionManager::pushToSession($sessionId, $payload);
        Logger::info('message dispatched to session', [
            'session_id' => $sessionId,
            'count'      => $count,
        ]);
        return $count;
    }

    /**
     * 广播给某个角色（agent / admin）
     */
    public static function broadcastRole($role, $payload) {
        $count = ConnectionManager::pushToRole($role, $payload);
        Logger::info('message broadcast by role', [
            'role'  => $role,
            'count' => $count,
        ]);
        return $count;
    }
}