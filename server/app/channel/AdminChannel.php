<?php
/**
 * 管理后台 WebSocket Channel
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 * 说明：
 *   - 管理后台用于实时监控全公司客服情况
 *   - 接收：心跳 / 订阅事件类型
 *   - 推送：agent_online/offline、new_session、message_total、质检告警等
 */

namespace app\channel;

use Workerman\Connection\TcpConnection;
use app\lib\Token;
use app\lib\Db;
use app\lib\ConnectionManager;
use app\lib\Logger;

class AdminChannel
{
    public function connect($connection) {
        $connection->uid = null;
        $connection->tenantId = 0;
        $connection->employeeId = 0;
        $connection->lastPing = time();
        $connection->onMessage = function (TcpConnection $conn, $data) {
            $this->onMessage($conn, $data);
        };
        $connection->onClose = function (TcpConnection $conn) {
            $this->onClose($conn);
        };
    }

    private function onMessage(TcpConnection $conn, $data) {
        $msg = json_decode($data, true);
        if (!is_array($msg)) {
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

            case 'subscribe':
                // 订阅事件类型：message / agent / session / quality
                $conn->subscribed = $msg['events'] ?? [];
                $conn->send(json_encode(['type' => 'subscribed', 'events' => $conn->subscribed]));
                break;

            default:
                $conn->send(json_encode(['type' => 'error', 'msg' => "Unknown type: $type"]));
        }
    }

    private function doAuth(TcpConnection $conn, $token) {
        $payload = Token::verify($token);
        if (!$payload) {
            $conn->send(json_encode(['type' => 'auth_fail', 'msg' => 'Token invalid']));
            $conn->close();
            return;
        }
        $tenantId = $payload['tenant_id'] ?? 0;
        $employeeId = $payload['employee_id'] ?? 0;
        $roleId = $payload['role_id'] ?? 0;

        // 只有 admin / supervisor 可连接（role_id: 1=admin, 2=supervisor, 3=agent）
        if ($roleId > 2) {
            $conn->send(json_encode(['type' => 'auth_fail', 'msg' => '没有权限']));
            $conn->close();
            return;
        }

        $uid = "admin:$tenantId:$employeeId";
        ConnectionManager::register($uid, $conn, 'admin');

        $conn->uid = $uid;
        $conn->tenantId = $tenantId;
        $conn->employeeId = $employeeId;
        $conn->send(json_encode([
            'type' => 'auth_ok',
            'tenant_id' => $tenantId,
            'stats' => ConnectionManager::stats(),
        ]));
    }

    private function onClose(TcpConnection $conn) {
        if ($conn->uid) {
            ConnectionManager::unregister($conn->uid);
        }
    }
}