<?php
/**
 * 访客端 WebSocket Channel
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 * 说明：
 *   - 访客 H5 / 小程序匿名（带 customer_token）连接
 *   - 接收：心跳 / 发送消息 / 评价 / 拉取历史
 *   - 推送：客服的回复 / 消息送达确认 / 评价请求 / 队列位置更新
 *
 *   协议（JSON 格式）：
 *   └── outgoing (client → server)
 *      ├── {"type":"ping"}
 *      ├── {"type":"auth","customer_id":"...","channel":"h5"}
 *      ├── {"type":"message","session_id":"...","content":"...","client_msg_id":"..."}
 *      ├── {"type":"evaluate","session_id":"...","score":5,"comment":"..."}
 *      └── {"type":"history","session_id":"...","before_seq":0}
 *
 *   └── incoming (server → client)
 *      ├── {"type":"pong"}
 *      ├── {"type":"auth_ok","session_id":"...","agent":{"id":2,"name":"客服小李"}}
 *      ├── {"type":"message",...}              客服回复
 *      ├── {"type":"delivery_ack","client_msg_id":"..."}
 *      ├── {"type":"queue_position","position":3}
 *      ├── {"type":"session_transferring","to":"..."}
 *      └── {"type":"session_closed","reason":"resolved"}
 */

namespace app\channel;

use Workerman\Connection\TcpConnection;
use app\lib\Db;
use app\lib\ConnectionManager;
use app\lib\Logger;
use app\service\MessageService;
use app\service\SessionService;

class VisitorChannel
{
    public function connect($connection) {
        $connection->uid = null;
        $connection->tenantId = 0;
        $connection->customerId = '';
        $connection->channel = '';
        $connection->sessionId = null;
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
                $this->doAuth($conn, $msg);
                break;

            case 'message':
                if ($conn->customerId !== '') {
                    (new MessageService())->handleVisitorMessage($conn, $msg);
                }
                break;

            case 'evaluate':
                if ($conn->customerId !== '' && !empty($msg['session_id'])) {
                    $this->doEvaluate($conn, $msg);
                }
                break;

            case 'history':
                if ($conn->customerId !== '' && !empty($msg['session_id'])) {
                    $this->doHistory($conn, $msg);
                }
                break;

            default:
                $conn->send(json_encode(['type' => 'error', 'msg' => "Unknown type: $type"]));
        }
    }

    /**
     * 访客认证：H5 匿名 / 小程序带 token
     */
    private function doAuth(TcpConnection $conn, $msg) {
        $customerId = trim($msg['customer_id'] ?? '');
        $channel = $msg['channel'] ?? 'h5';
        $tenantId = intval($msg['tenant_id'] ?? 1);
        if ($customerId === '') {
            $conn->send(json_encode(['type' => 'auth_fail', 'msg' => 'customer_id required']));
            return;
        }
        Db::setTenantId($tenantId);

        $uid = "visitor:$tenantId:$customerId";
        // 踢下线旧连接
        $oldConn = ConnectionManager::get($uid);
        if ($oldConn && $oldConn !== $conn) {
            $oldConn->send(json_encode(['type' => 'kicked', 'msg' => '另一个页面登录']));
            $oldConn->close();
        }

        $conn->uid = $uid;
        $conn->tenantId = $tenantId;
        $conn->customerId = $customerId;
        $conn->channel = $channel;
        ConnectionManager::register($uid, $conn, 'visitor');

        // 找该访客最近的进行中会话（如有）
        $sessionService = new SessionService();
        $session = $sessionService->getOrCreateActiveSession($tenantId, $customerId, $channel);

        $conn->sessionId = $session['session_id'];
        if ($session['session_id']) {
            ConnectionManager::register($uid, $conn, 'visitor', $session['session_id']);
        }

        $conn->send(json_encode([
            'type' => 'auth_ok',
            'customer_id' => $customerId,
            'session_id'  => $session['session_id'],
            'status'      => $session['status'],
            'agent'       => $session['agent_id'] ? [
                'id'   => $session['agent_id'],
                'name' => $session['agent_name'] ?? '',
            ] : null,
        ]));

        // 如果还是 waiting 状态，通知客服有新会话
        if ($session['status'] === 'waiting') {
            ConnectionManager::pushToRole('agent', [
                'type'        => 'new_session',
                'session_id'  => $session['session_id'],
                'customer_id' => $customerId,
                'priority'    => $session['priority'],
            ]);
        }
    }

    private function doEvaluate(TcpConnection $conn, $msg) {
        $sessionId = $msg['session_id'];
        $score = intval($msg['score']);
        $comment = trim($msg['comment'] ?? '');
        if ($score < 1 || $score > 5) {
            $conn->send(json_encode(['type' => 'error', 'msg' => '评分 1-5']));
            return;
        }
        $level = $score >= 4 ? 'satisfied' : ($score == 3 ? 'neutral' : 'dissatisfied');
        Db::insert('kefu_evaluate', [
            'session_id' => $sessionId,
            'customer_id' => intval(Db::value('SELECT id FROM kefu_customer WHERE customer_id = :c', [':c' => $conn->customerId])),
            'agent_id' => intval(Db::value('SELECT agent_id FROM kefu_session WHERE session_id = :s', [':s' => $sessionId])),
            'score' => $score,
            'level' => $level,
            'comment' => $comment,
        ]);
        $conn->send(json_encode(['type' => 'evaluate_ok', 'session_id' => $sessionId]));
    }

    private function doHistory(TcpConnection $conn, $msg) {
        $sessionId = $msg['session_id'];
        $beforeSeq = intval($msg['before_seq'] ?? 0);
        $rows = Db::query(
            "SELECT m.id, m.session_sequence, m.sender_type, m.msg_type, m.content, m.media_url, m.status, m.created_at,
                    e.real_name AS agent_name
             FROM kefu_message m
             LEFT JOIN kefu_employee e ON e.id = m.agent_id
             WHERE m.session_id = :s AND m.session_sequence < :seq
             ORDER BY m.session_sequence DESC LIMIT 30",
            [':s' => $sessionId, ':seq' => $beforeSeq > 0 ? $beforeSeq : 99999999]
        );
        $conn->send(json_encode([
            'type' => 'history',
            'session_id' => $sessionId,
            'messages' => $rows,
        ]));
    }

    private function onClose(TcpConnection $conn) {
        if ($conn->uid) {
            ConnectionManager::unregister($conn->uid);
        }
    }
}