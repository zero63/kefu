<?php
/**
 * 消息业务服务
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 *
 * 核心机制（三态）：
 *   发送方流程：sending → 投递成功 → delivered → 已读 read
 *     1. sending ：消息已写入数据库，正在投递
 *     2. delivered：对方确认已收到（ConnectionManager 推送成功后）
 *     3. read ：对端已读（fetch 历史时小于 session_sequence 自动标已读）
 *
 * 消息序列：
 *   session_sequence：会话内严格自增序号（基于会话内消息数量 + 1），
 *                    客户端可基于此判定消息顺序、断线后传 before_seq 补差。
 *
 * 可靠投递：
 *   - 数据库写入 → 推送 → 收到 ack → 标记 delivered
 *   - 三次推送失败 → 标记 failed，记录重试次数
 *   - 离线消息：用户再次连上后用 last_seq 之后的 seq 补发
 *
 * 消息类型：
 *   text/image/file/voice/video/card/system
 *
 * sender_type：
 *   customer / agent / robot / system
 */

namespace app\service;

use app\lib\Db;
use app\lib\Logger;
use app\lib\ConnectionManager;
use Workerman\Connection\TcpConnection;

class MessageService
{
    /**
     * 访客发送消息（通过 WebSocket）
     * @param TcpConnection $conn
     * @param array $msg 形如 {session_id, content, type, client_msg_id}
     */
    public function handleVisitorMessage(TcpConnection $conn, $msg) {
        $sessionId = $msg['session_id'] ?? $conn->sessionId;
        $content = trim($msg['content'] ?? '');
        $type = $msg['type'] ?? 'text';
        $clientMsgId = $msg['client_msg_id'] ?? null;
        $ext = $msg['ext'] ?? [];

        if (empty($sessionId) || empty($content)) {
            $conn->send(json_encode(['type' => 'error', 'msg' => 'session_id and content required']));
            return;
        }

        $tenantId = $conn->tenantId;
        Db::setTenantId($tenantId);

        // 1. 校验会话
        $session = Db::find("SELECT * FROM kefu_session WHERE session_id = :s", [':s' => $sessionId]);
        if (!$session) {
            $conn->send(json_encode(['type' => 'error', 'msg' => 'session not found']));
            return;
        }

        // 2. 校验会话状态
        if (!in_array($session['status'], ['waiting', 'active'])) {
            $conn->send(json_encode(['type' => 'error', 'msg' => 'session closed']));
            return;
        }

        // 3. 敏感词过滤结果（中间件会传这里）
        $sensitiveHit = $conn->_sensitive_hit ?? 0;
        $sensitiveWords = $conn->_sensitive_words ?? [];

        // 4. 记录消息
        $msgRow = $this->persist([
            'tenant_id'        => $tenantId,
            'session_id'       => $sessionId,
            'customer_id'      => $session['customer_id'],
            'sender_type'      => 'customer',
            'sender_id'        => $conn->customerId,
            'msg_type'         => $type,
            'content'          => $content,
            'media_url'        => $msg['media_url'] ?? null,
            'ext_json'         => json_encode($ext, JSON_UNESCAPED_UNICODE),
            'client_msg_id'    => $clientMsgId,
            'is_sensitive'     => $sensitiveHit,
            'sensitive_words'  => $sensitiveWords ? implode(',', $sensitiveWords) : null,
        ]);

        // 5. 推送消息给会话相关人（visitor + agent + 监管）
        $pushPayload = $this->buildPushPayload($msgRow, $msgRow['sender_type'], null);
        ConnectionManager::pushToSession($sessionId, $pushPayload);

        // 6. 主动 ack 客户端（消息已被服务端接收）
        $conn->send(json_encode([
            'type' => 'delivery_ack',
            'client_msg_id' => $clientMsgId,
            'msg_id' => $msgRow['id'],
            'session_sequence' => $msgRow['session_sequence'],
        ]));

        // 7. 触发机器人（如果 waiting 状态或客服超时）
        if ($session['status'] === 'waiting') {
            $this->robotReply($sessionId, $content);
        }

        Logger::info('visitor message saved', [
            'session_id' => $sessionId,
            'msg_id' => $msgRow['id'],
            'len' => mb_strlen($content),
        ]);
    }

    /**
     * 访客发送消息（HTTP API 入口，替代 handleVisitorMessage 的 WS 路径）
     * @param int $tenantId
     * @param string $sessionId
     * @param array $params
     * @return array
     */
    public function visitorSend($tenantId, $sessionId, $params) {
        Db::setTenantId($tenantId);

        // 跟客服发送逻辑相同，只是 sender_type 和 sender_id 不同
        $row = $this->persist([
            'tenant_id'   => $tenantId,
            'session_id'   => $sessionId,
            'sender_type'  => 'customer',
            'sender_id'    => (string)$params['customer_id'],
            'msg_type'     => $params['msg_type'] ?? 'text',
            'content'      => $params['content'],
            'media_url'    => $params['media_url'] ?? null,
            'client_msg_id'=> $params['client_msg_id'] ?? null,
            'ext_json'     => !empty($params['ext']) ? json_encode($params['ext'], JSON_UNESCAPED_UNICODE) : '{}',
        ]);

        // 推送：访客发消息时推送给客服
        $pushPayload = $this->buildPushPayload($row, 'customer', null);
        if (!empty($row['session_id'])) {
            $pushPayload['session_id'] = $row['session_id'];
        }
        // 关键修复：agent_id 是当前会话的客服 ID（即接管人），保证消息能推到对应 agent uid 队列
        $pushPayload['agent_id'] = (int)($params['agent_id'] ?? 0);

        ConnectionManager::pushToSession($sessionId, $pushPayload);

        return ['code' => 0, 'msg' => 'ok', 'data' => $this->buildPushPayload($row, 'customer', null)];
    }

    /**
     * 客服发送消息（通过 HTTP POST /api/agent/message/send）
     * @param int $tenantId
     * @param int $employeeId
     * @param array $params
     */
    public function agentSend($tenantId, $employeeId, $params) {
        $sessionId = $params['session_id'] ?? '';
        $content = trim($params['content'] ?? '');
        $type = $params['type'] ?? 'text';
        $clientMsgId = $params['client_msg_id'] ?? null;
        $ext = $params['ext'] ?? [];
        $mediaUrl = $params['media_url'] ?? null;

        // 修复：非文本消息（图片/文件/音视频/emoji）允许 content 为空，但必须有 media_url 或 content
        if (empty($sessionId)) {
            return ['code' => 400, 'msg' => 'session_id 必填'];
        }
        if ($type === 'text' && empty($content)) {
            return ['code' => 400, 'msg' => 'content 必填'];
        }
        if ($type !== 'text' && empty($mediaUrl) && empty($content)) {
            return ['code' => 400, 'msg' => '非文本消息必须传 media_url'];
        }
        Db::setTenantId($tenantId);

        $session = Db::find("SELECT * FROM kefu_session WHERE session_id = :s", [':s' => $sessionId]);
        if (!$session) {
            return ['code' => 404, 'msg' => '会话不存在'];
        }
        if ($session['status'] === 'closed') {
            return ['code' => 400, 'msg' => '会话已关闭'];
        }
        // 客服只能发自己会话（除非超管——这里演示版强制）
        if ($session['agent_id'] != $employeeId) {
            return ['code' => 403, 'msg' => '不是该会话的接待客服'];
        }

        $msgRow = $this->persist([
            'tenant_id'    => $tenantId,
            'session_id'   => $sessionId,
            'customer_id'  => $session['customer_id'],
            'agent_id'     => $employeeId,
            'sender_type'  => 'agent',
            'sender_id'    => (string)$employeeId,
            'msg_type'     => $type,
            'content'      => $content,
            'media_url'    => $params['media_url'] ?? null,
            'ext_json'     => json_encode($ext, JSON_UNESCAPED_UNICODE),
            'client_msg_id'=> $clientMsgId,
        ]);

        // 推送（同时给访客和监管）
        $pushPayload = $this->buildPushPayload($msgRow, 'agent', [
            'id'   => $employeeId,
            'name' => (string)Db::value("SELECT real_name FROM kefu_employee WHERE id = :i", [':i' => $employeeId]),
        ]);
        // 关键：把 agent_id 写入 payload，ConnectionManager 会据此推送到 agent 自己的 uid 队列
        // 这样客服即使没接到 ConnectionManager 的 sessionMap 也能从 polling 里看到自己的消息
        $pushPayload['agent_id'] = (int)$employeeId;
        $pushPayload['agent_name'] = (string)Db::value("SELECT real_name FROM kefu_employee WHERE id = :i", [':i' => $employeeId]);
        $pushPayload['agent_avatar'] = (string)Db::value("SELECT IFNULL(avatar, '') FROM kefu_employee WHERE id = :i", [':i' => $employeeId]);
        ConnectionManager::pushToSession($sessionId, $pushPayload);

        // 通知访客端有消息到达（已在 pushPayload 中）

        Logger::info('agent message sent', [
            'session_id' => $sessionId,
            'employee_id'=> $employeeId,
            'msg_id'     => $msgRow['id'],
        ]);

        return ['code' => 0, 'msg' => 'ok', 'data' => [
            'msg_id'           => $msgRow['id'],
            'session_sequence' => $msgRow['session_sequence'],
            'created_at'       => $msgRow['created_at'],
        ]];
    }

    /**
     * 拉取历史消息
     */
    public function getHistory($tenantId, $sessionId, $beforeSeq = 0, $limit = 30) {
        Db::setTenantId($tenantId);
        $beforeSeq = max(0, intval($beforeSeq));
        $limit = min(100, max(1, intval($limit)));

        $rows = Db::query(
            "SELECT m.id, m.session_sequence, m.sender_type, m.msg_type, m.content, m.media_url,
                    m.status, m.created_at,
                    e.real_name AS agent_name, e.avatar AS agent_avatar, e.employee_no AS agent_no,
                    c.nickname AS customer_name, c.avatar AS customer_avatar, c.customer_id AS customer_external_id
             FROM kefu_message m
             LEFT JOIN kefu_employee e ON e.id = m.agent_id
             LEFT JOIN kefu_session s ON s.session_id = m.session_id
             LEFT JOIN kefu_customer c ON c.id = s.customer_id
             WHERE m.session_id = :s AND m.session_sequence < :seq
             ORDER BY m.session_sequence DESC
             LIMIT $limit",
            [':s' => $sessionId, ':seq' => $beforeSeq ?: PHP_INT_MAX]
        );
        // 统一在源头反转为正序（最早在上、最新在下），前端无需再 reverse
        // 注意：SQL 必须保持 DESC 取数，before_seq 分页才能取到"更早的 N 条"
        return array_reverse($rows ?: []);
    }

    /**
     * 把消息标记为已读（visitor 主动读 / agent 主动读）
     */
    public function markRead($tenantId, $sessionId, $readerType, $readerId) {
        // readerType: visitor / agent
        $oppositeType = $readerType === 'visitor' ? 'agent' : 'customer';
        Db::setTenantId($tenantId);
        $count = Db::exec(
            "UPDATE kefu_message
             SET status = 'read', read_at = NOW()
             WHERE session_id = :s
               AND sender_type = :st
               AND status IN ('delivered', 'sending')",
            [':s' => $sessionId, ':st' => $oppositeType]
        );
        return $count;
    }

    /**
     * 把消息标记为送达（一般 WebSocket 发送成功后由对端 ack）
     */
    public function markDelivered($tenantId, $sessionId, $clientMsgId) {
        Db::setTenantId($tenantId);
        return Db::exec(
            "UPDATE kefu_message
             SET status = 'delivered', ack_at = NOW()
             WHERE session_id = :s AND client_msg_id = :mid AND status = 'sending'",
            [':s' => $sessionId, ':mid' => $clientMsgId]
        );
    }

    /**
     * 机器人回复
     */
    private function robotReply($sessionId, $userMsg) {
        try {
            $robot = \app\process\RobotWorker::infer([
                'tenant_id' => Db::getTenantId(),
                'robot_id'  => 1,
                'q'         => $userMsg,
            ]);

            if (empty($robot['matched'])) {
                $answer = $robot['answer'] ?? '抱歉没能理解您的问题';
            } else {
                $answer = $robot['answer'];
            }

            $msgRow = $this->persist([
                'tenant_id'   => Db::getTenantId(),
                'session_id'  => $sessionId,
                'sender_type' => 'robot',
                'sender_id'   => '1',
                'msg_type'    => 'text',
                'content'     => $answer,
                'ext_json'    => json_encode([
                    'matched'     => $robot['matched'],
                    'knowledge_id'=> $robot['knowledge_id'] ?? null,
                    'matched_type'=> $robot['matched_type'] ?? 'knowledge',
                ]),
            ]);

            ConnectionManager::pushToSession($sessionId, $this->buildPushPayload($msgRow, 'robot', null));
        } catch (\Exception $e) {
            Logger::error('robotReply failed', ['err' => $e->getMessage()]);
        }
    }

    /**
     * 持久化消息到 DB（含 session_sequence 自增）
     */
    private function persist($data) {
        Db::setTenantId($data['tenant_id']);

        // 计算 session_sequence（事务里）
        Db::pdo()->beginTransaction();
        try {
            $currentMaxSeq = Db::value(
                "SELECT IFNULL(MAX(session_sequence), 0) FROM kefu_message WHERE session_id = :s FOR UPDATE",
                [':s' => $data['session_id']]
            );
            $nextSeq = $currentMaxSeq + 1;
            $data['session_sequence'] = $nextSeq;
            $data['status'] = $data['status'] ?? 'sending';

            $id = Db::insert('kefu_message', $data);

            // 会话内消息计数 +1
            Db::exec(
                "UPDATE kefu_session SET message_count = message_count + 1 WHERE session_id = :s",
                [':s' => $data['session_id']]
            );
            // 客服首响时间（在会话第一次有客服回复时设置）
            if ($data['sender_type'] === 'agent') {
                Db::exec(
                    "UPDATE kefu_session
                     SET first_response_at = IFNULL(first_response_at, NOW())
                     WHERE session_id = :s",
                    [':s' => $data['session_id']]
                );
            }
            // 修复：超时判断依据（区分客户/客服最后活跃时间）
            if (($data['sender_type'] ?? '') === 'customer') {
                Db::exec(
                    "UPDATE kefu_session SET last_customer_at = NOW() WHERE session_id = :s",
                    [':s' => $data['session_id']]
                );
            } elseif (($data['sender_type'] ?? '') === 'agent') {
                Db::exec(
                    "UPDATE kefu_session SET last_agent_at = NOW() WHERE session_id = :s",
                    [':s' => $data['session_id']]
                );
            }

            Db::pdo()->commit();

            // 多重试一次（避免 PDO 刚重连时 query 偶发失败）
            $row = null;
            for ($i = 0; $i < 3; $i++) {
                $row = Db::find("SELECT * FROM kefu_message WHERE id = :id", [':id' => $id]);
                if ($row) break;
                usleep(10000); // 10ms
            }
            if (!$row) {
                // 兜底：哪怕没取回也要保证关键字段完整
                return [
                    'id' => $id,
                    'tenant_id' => $data['tenant_id'],
                    'session_id' => $data['session_id'],
                    'sender_type' => $data['sender_type'] ?? 'unknown',
                    'sender_id' => $data['sender_id'] ?? '',
                    'msg_type' => $data['msg_type'] ?? 'text',
                    'content' => $data['content'] ?? '',
                    'session_sequence' => $data['session_sequence'] ?? 0,
                    'media_url' => $data['media_url'] ?? null,
                    'ext_json' => $data['ext_json'] ?? '{}',
                    'created_at' => date('Y-m-d H:i:s'),
                ];
            }
            $row['created_at'] = is_string($row['created_at']) ? $row['created_at'] : (string)$row['created_at'];
            return $row;
        } catch (\Exception $e) {
            Db::pdo()->rollBack();
            throw $e;
        }
    }

    /**
     * 构造推送的 payload
     */
    private function buildPushPayload($msgRow, $senderType, $agent) {
        $payload = [
            'type'             => 'message',
            'msg_id'           => (int)($msgRow['id'] ?? 0),
            'session_id'       => $msgRow['session_id'] ?? '',
            'session_sequence' => (int)($msgRow['session_sequence'] ?? 0),
            'sender_type'      => $senderType,
            'sender_id'        => $msgRow['sender_id'] ?? '',
            'msg_type'         => $msgRow['msg_type'] ?? 'text',
            'content'          => $msgRow['content'] ?? '',
            'created_at'       => $msgRow['created_at'] ?? date('Y-m-d H:i:s'),
        ];
        if (!empty($msgRow['media_url'])) {
            $payload['media_url'] = $msgRow['media_url'];
        }
        // 修复：把 ext_json 暴露给前端（用于渲染文件名/大小等）
        if (!empty($msgRow['ext_json'])) {
            $payload['ext_json'] = $msgRow['ext_json'];
        }
        if ($agent) {
            $payload['agent'] = $agent;
            // 同时把 agent_id/name/avatar 平铺到 payload 顶层，便于前端 polling 直接渲染
            if (!empty($agent['id'])) $payload['agent_id'] = (int)$agent['id'];
            if (!empty($agent['name'])) $payload['agent_name'] = (string)$agent['name'];
        }
        return $payload;
    }
}