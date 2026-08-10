<?php
/**
 * 客服工作台 WebSocket Channel
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 * 说明：
 *   - 客服登录后建立 ws 连接
 *   - 接收消息：心跳、心跳应答、客服主动消息、状态切换
 *   - 推送消息：新会话通知、来访消息、上线通知等
 *
 *   协议（JSON 格式）：
 *   └── outgoing (client → server)
 *      ├── {"type":"ping"}                     心跳
 *      ├── {"type":"auth","token":"..."}        认证
 *      ├── {"type":"status","status":"online"}  状态切换
 *      ├── {"type":"read","session_id":"..."}   已读回执
 *      └── {"type":"typing","session_id":"..."} 正在输入
 *
 *   └── incoming (server → client)
 *      ├── {"type":"pong"}                      心跳应答
 *      ├── {"type":"auth_ok","agent_id":2}       认证成功
 *      ├── {"type":"new_session",...}           新会话通知
 *      ├── {"type":"message",...}               收到访客消息
 *      ├── {"type":"delivery_ack","mid":...}     消息送达确认
 *      ├── {"type":"system","event":"..."}      系统通知
 *      └── {"type":"session_closed","sid":...}  会话关闭
 */

namespace app\channel;

use Workerman\Connection\TcpConnection;
use app\lib\Token;
use app\lib\Db;
use app\lib\ConnectionManager;
use app\lib\Logger;

class AgentChannel
{
    /**
     * WebSocket 连接建立
     * @param TcpConnection $connection
     */
    public function connect($connection) {
        // 安全：等浏览器发"auth"完成认证
        $connection->uid = null;
        $connection->tenantId = 0;
        $connection->employeeId = 0;
        $connection->lastPing = time();
        $connection->onWebSocketConnect = function () use ($connection) {
            Logger::info('agent ws connected', ['remote_ip' => $connection->getRemoteIp()]);
        };
        $connection->onMessage = function (TcpConnection $conn, $data) {
            $this->onMessage($conn, $data);
        };
        $connection->onClose = function (TcpConnection $conn) {
            $this->onClose($conn);
        };
    }

    /**
     * 消息处理
     */
    private function onMessage(TcpConnection $conn, $data) {
        $msg = json_decode($data, true);
        if (!is_array($msg)) {
            $conn->send(json_encode(['type' => 'error', 'msg' => 'Invalid JSON']));
            return;
        }

        $type = $msg['type'] ?? '';
        switch ($type) {
            case 'ping':
                $conn->lastPing = time();
                $conn->send(json_encode(['type' => 'pong', 'ts' => time()]));
                break;

            case 'auth':
                $this->doAuth($conn, $msg['token'] ?? '');
                break;

            case 'status':
                if ($conn->employeeId > 0) {
                    $this->doStatusSwitch($conn, $msg['status'] ?? 'online');
                }
                break;

            case 'read':
                if ($conn->employeeId > 0 && !empty($msg['session_id'])) {
                    $this->doReadAck($conn, $msg['session_id']);
                }
                break;

            case 'typing':
                if ($conn->employeeId > 0 && !empty($msg['session_id'])) {
                    ConnectionManager::pushToSession(
                        $msg['session_id'],
                        ['type' => 'agent_typing', 'employee_id' => $conn->employeeId]
                    );
                }
                break;

            default:
                $conn->send(json_encode(['type' => 'error', 'msg' => "Unknown type: $type"]));
        }
    }

    /**
     * 认证
     */
    private function doAuth(TcpConnection $conn, $token) {
        $payload = Token::verify($token);
        if (!$payload) {
            $conn->send(json_encode(['type' => 'auth_fail', 'msg' => 'Token invalid']));
            $conn->close();
            return;
        }
        $tenantId = $payload['tenant_id'] ?? 0;
        $employeeId = $payload['employee_id'] ?? 0;
        if ($tenantId <= 0 || $employeeId <= 0) {
            $conn->send(json_encode(['type' => 'auth_fail', 'msg' => 'Bad token payload']));
            $conn->close();
            return;
        }

        Db::setTenantId($tenantId);
        $uid = "agent:$tenantId:$employeeId";

        // 踢下线旧连接（同账号只允许一处在线）
        $oldConn = ConnectionManager::get($uid);
        if ($oldConn && $oldConn !== $conn) {
            $oldConn->send(json_encode(['type' => 'kicked', 'msg' => '账号在别处登录']));
            $oldConn->close();
        }

        ConnectionManager::register($uid, $conn, 'agent');

        $conn->uid = $uid;
        $conn->employeeId = $employeeId;
        $conn->tenantId = $tenantId;

        // 通知自己认证成功
        $conn->send(json_encode([
            'type' => 'auth_ok',
            'agent_id' => $employeeId,
            'tenant_id' => $tenantId,
            'online_count' => count(ConnectionManager::stats()['online']),
        ]));
    }

    /**
     * 状态切换
     */
    private function doStatusSwitch(TcpConnection $conn, $status) {
        $allowed = ['online', 'busy', 'away', 'offline'];
        if (!in_array($status, $allowed)) return;

        // 通知所有监控端
        ConnectionManager::pushToRole('admin', [
            'type' => 'agent_status_change',
            'employee_id' => $conn->employeeId,
            'status' => $status,
        ]);
    }

    /**
     * 已读回执
     */
    private function doReadAck(TcpConnection $conn, $sessionId) {
        Db::setTenantId($conn->tenantId);
        Db::exec(
            "UPDATE kefu_message
             SET status = 'read', read_at = NOW()
             WHERE session_id = :sid
               AND sender_type = 'customer'
               AND status IN ('delivered', 'sending')",
            [':sid' => $sessionId]
        );
        // 通知访客
        ConnectionManager::pushToSession($sessionId, [
            'type' => 'read_ack',
            'session_id' => $sessionId,
            'by_employee_id' => $conn->employeeId,
        ]);
    }

    /**
     * 连接关闭
     */
    private function onClose(TcpConnection $conn) {
        if ($conn->uid) {
            ConnectionManager::unregister($conn->uid);
            // 广播：agent 下线
            ConnectionManager::pushToRole('admin', [
                'type' => 'agent_offline',
                'employee_id' => $conn->employeeId,
            ]);
        }
    }
}