<?php
/**
 * 访客端 API Controller
 * - 公开接口，无 token 鉴权
 * - 通过 X-Tenant-Id header 区分租户
 *
 * 路径前缀：/api/visitor/*
 */
namespace app\controller;

use support\Request;
use app\lib\Db;
use app\lib\Logger;

class VisitorApiController
{
    /**
     * 解析租户（默认 1）
     */
    private function resolveTenant(Request $request) {
        $tid = intval($request->header('x-tenant-id', 0));
        if ($tid > 0) return $tid;
        $tid = intval($request->get('tenant_id', 0));
        if ($tid > 0) return $tid;
        $tid = intval($request->post('tenant_id', 0));
        if ($tid > 0) return $tid;
        return 1;
    }

    /**
     * 创建或更新访客档案
     */
    private function upsertCustomer($tenantId, $visitorId, $name, $avatar, $channel, $meta)
    {
        try {
            $exist = Db::find(
                "SELECT id FROM kefu_customer WHERE tenant_id = :t AND customer_id = :c",
                [':t' => $tenantId, ':c' => $visitorId]
            );
            if (!$exist) {
                $newId = Db::insert('kefu_customer', [
                    'tenant_id'    => $tenantId,
                    'customer_id'  => $visitorId,
                    'nickname'     => $name ?: null,
                    'avatar'       => $avatar ?: null,
                    'channel'      => $channel ?: 'widget',
                    'profile'      => !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                    'status'       => 1,
                    'last_active_time' => date('Y-m-d H:i:s'),
                ]);
                return $newId ?: 0;
            } else {
                $update = [];
                if ($name) $update['nickname'] = $name;
                if ($avatar) $update['avatar'] = $avatar;
                if (!empty($meta)) $update['profile'] = json_encode($meta, JSON_UNESCAPED_UNICODE);
                $update['last_active_time'] = date('Y-m-d H:i:s');
                Db::update('kefu_customer', $update, ['id' => $exist['id']]);
                return intval($exist['id']);
            }
        } catch (\Throwable $e) {
            Logger::warn('创建访客档案失败', ['err' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * 拉取访客端样式（公开）
     * GET /api/visitor/style/get-public
     */
    public function getPublicStyle(Request $request)
    {
        $tenantId = $this->resolveTenant($request);
        Db::setTenantId($tenantId);
        $row = Db::find(
            "SELECT config_value FROM kefu_config WHERE tenant_id = :t AND config_key = 'visitor_style'",
            [':t' => $tenantId]
        );
        $cfg = $row && !empty($row['config_value'])
            ? json_decode($row['config_value'], true)
            : [];
        if (!is_array($cfg)) $cfg = [];
        return json(['code' => 0, 'msg' => 'ok', 'data' => $cfg]);
    }

    /**
     * 会话创建/获取（自动分配）
     * POST /api/visitor/session/ensure
     * body: { session_id?, visitor_id, channel, name?, avatar?, meta? }
     */
    public function ensureSession(Request $request)
    {
        $tenantId = $this->resolveTenant($request);
        $sessionId = trim($request->post('session_id', ''));
        $visitorId = trim($request->post('visitor_id', ''));
        $channel = trim($request->post('channel', 'widget')) ?: 'widget';
        $name = trim($request->post('name', ''));
        $avatar = trim($request->post('avatar', ''));
        $meta = $request->post('meta', []);
        if (!is_array($meta)) $meta = [];

        if (!$visitorId) return json(['code' => 400, 'msg' => 'visitor_id 必填']);
        Db::setTenantId($tenantId);

        // 1) 是否已有会话
        if ($sessionId) {
            $exist = Db::find(
                "SELECT * FROM kefu_session WHERE tenant_id = :t AND session_id = :sid",
                [':t' => $tenantId, ':sid' => $sessionId]
            );
            if ($exist) {
                // 更新访客信息
                $data = [];
                if ($name) $data['visitor_name'] = $name;
                if ($avatar) $data['visitor_avatar'] = $avatar;
                if (!empty($meta)) $data['visitor_meta'] = json_encode($meta, JSON_UNESCAPED_UNICODE);
                if ($data) Db::update('kefu_session', $data, ['id' => $exist['id']]);
                return json(['code' => 0, 'msg' => 'ok', 'data' => [
                    'session' => array_merge($exist, $data),
                    'auto_offline' => false,
                ]]);
            }
        }

        // 2) 创建新会话：先看 AI 是否启用
        $aiEnabled = (new \app\service\AiConfigService())->isEnabled($tenantId);
        $onlineAgent = null;
        $servingMode = 'human';
        $sessionStatus = 'active';

        if ($aiEnabled) {
            // AI 启用：默认走 AI 模式（visitor 发消息时由 AiChatService 决定转人工）
            $servingMode = 'ai';
            $sessionStatus = 'waiting';
        } else {
            // AI 未启用：尝试分配客服
            $onlineAgent = $this->pickOnlineAgent($tenantId);
            if ($onlineAgent === null) {
                $servingMode = 'message';
                $sessionStatus = 'waiting';
            }
        }
        $autoOffline = $servingMode === 'message';

        $sessionId = $sessionId ?: ('s_' . substr(md5($visitorId . microtime(true)), 0, 16));
        $id = Db::insert('kefu_session', [
            'tenant_id' => $tenantId,
            'session_id' => $sessionId,
            'customer_id' => 0,
            'visitor_id' => $visitorId,
            'visitor_name' => $name ?: null,
            'visitor_avatar' => $avatar ?: null,
            'visitor_meta' => !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            'channel' => $channel,
            'serving_mode' => $servingMode,
            'status' => $sessionStatus,
            'agent_id' => $onlineAgent ? $onlineAgent['id'] : null,
            'agent_name' => $onlineAgent ? $onlineAgent['real_name'] : null,
            'source' => $channel,
        ]);

        // 创建/更新 kefu_customer 档案（如果不存在），并把 customer.id 写回 session
        $customerIdInternal = $this->upsertCustomer($tenantId, $visitorId, $name, $avatar, $channel, $meta);
        if ($customerIdInternal > 0) {
            Db::exec(
                "UPDATE kefu_session SET customer_id = :cid WHERE id = :id",
                [':cid' => $customerIdInternal, ':id' => $id]
            );
        }

        // 写入系统提示消息
        if ($autoOffline) {
            try {
                Db::insert('kefu_message', [
                    'tenant_id'   => $tenantId,
                    'session_id'  => $sessionId,
                    'customer_id' => $customerIdInternal,
                    'sender_type' => 'system',
                    'msg_type'    => 'text',
                    'content'     => '当前没有客服在线，请留下您的留言，我们上线后会尽快回复您。',
                    'status'      => 'delivered',
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) {}
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'session_id'   => $sessionId,
            'id'           => $id,
            'serving_mode' => $servingMode,
            'auto_offline' => $autoOffline,
            'agent'        => $onlineAgent,
        ]]);
    }

    /**
     * 选择一个在线客服（负载均衡）
     */
    private function pickOnlineAgent($tenantId)
    {
        // 找：status=1 且 work_status='online' 且未满负载的客服
        // 排序：当前会话数最少 → 技能等级 → ID（先到先得）
        // 修复：session_count 只算人工会话（serving_mode='human'），AI 会话不计
        $rows = Db::query(
            "SELECT e.id, e.real_name, e.username, e.avatar, e.max_sessions,
                    (SELECT COUNT(*) FROM kefu_session s
                     WHERE s.tenant_id = e.tenant_id AND s.agent_id = e.id
                       AND s.status IN ('active', 'waiting')
                       AND s.serving_mode = 'human') AS session_count
             FROM kefu_employee e
             WHERE e.tenant_id = :t AND e.status = 1 AND e.role_id >= 3
               AND e.work_status = 'online'
               AND (SELECT COUNT(*) FROM kefu_session s
                    WHERE s.tenant_id = e.tenant_id AND s.agent_id = e.id
                      AND s.status IN ('active', 'waiting')
                      AND s.serving_mode = 'human') < e.max_sessions
             ORDER BY session_count ASC, e.skill_level DESC, e.id ASC LIMIT 1",
            [':t' => $tenantId]
        );
        if (!empty($rows)) return $rows[0];

        // 兜底：在 work_status='online' 的客服里查找 WS 心跳活跃的人
        // （覆盖 DB 状态短暂未同步的情况，但仍然要求 work_status='online'）
        $onlineDir = runtime_path('push') . '/online';
        if (!is_dir($onlineDir)) return null;
        $candidates = Db::query(
            "SELECT e.id, e.real_name, e.username, e.avatar, e.max_sessions,
                    (SELECT COUNT(*) FROM kefu_session s
                     WHERE s.tenant_id = e.tenant_id AND s.agent_id = e.id
                       AND s.status IN ('active', 'waiting')) AS session_count
             FROM kefu_employee e
             WHERE e.tenant_id = :t AND e.status = 1 AND e.role_id >= 3
               AND e.work_status = 'online'
               AND (SELECT COUNT(*) FROM kefu_session s
                    WHERE s.tenant_id = e.tenant_id AND s.agent_id = e.id
                      AND s.status IN ('active', 'waiting')) < e.max_sessions
             ORDER BY session_count ASC, e.skill_level DESC, e.id ASC",
            [':t' => $tenantId]
        );
        foreach ($candidates as $c) {
            $file = $onlineDir . '/agent_' . $tenantId . '_' . $c['id'] . '.json';
            if (!is_file($file)) continue;
            $meta = @json_decode(@file_get_contents($file), true);
            $lastActive = isset($meta['last_active']) ? (int)$meta['last_active'] : 0;
            // WS 心跳 30 秒内的客服才视为真正活跃
            if ($lastActive > 0 && (time() - $lastActive) > 30) continue;
            return $c;
        }
        return null;
    }

    /**
     * 拉取会话信息
     * GET /api/visitor/session/get?session_id=xxx
     */
    public function getSession(Request $request)
    {
        $tenantId = $this->resolveTenant($request);
        $sid = trim($request->get('session_id', ''));
        if (!$sid) return json(['code' => 400, 'msg' => 'session_id 必填']);
        Db::setTenantId($tenantId);
        $row = Db::find(
            "SELECT * FROM kefu_session WHERE tenant_id = :t AND session_id = :sid",
            [':t' => $tenantId, ':sid' => $sid]
        );
        if (!$row) return json(['code' => 0, 'msg' => 'ok', 'data' => ['session' => null]]);
        if ($row['visitor_meta']) {
            $row['visitor_meta'] = json_decode($row['visitor_meta'], true);
        } else {
            $row['visitor_meta'] = [];
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['session' => $row]]);
    }

    /**
     * 拉取历史消息
     * GET /api/visitor/message/history?session_id=xxx&limit=50
     */
    public function history(Request $request)
    {
        $tenantId = $this->resolveTenant($request);
        $sid = trim($request->get('session_id', ''));
        $limit = min(100, max(1, intval($request->get('limit', 50))));
        if (!$sid) return json(['code' => 400, 'msg' => 'session_id 必填']);
        Db::setTenantId($tenantId);
        $rows = Db::query(
            "SELECT id, session_id, customer_id, agent_id, sender_type, msg_type, content, ext_json, created_at
             FROM kefu_message
             WHERE tenant_id = :t AND session_id = :sid
             ORDER BY id DESC LIMIT $limit",
            [':t' => $tenantId, ':sid' => $sid]
        );
        // 转为时间正序
        $rows = array_reverse($rows);
        // 附加姓名/头像
        foreach ($rows as &$r) {
            $ext = $r['ext_json'] ? json_decode($r['ext_json'], true) : [];
            $r['sender_name'] = $ext['name'] ?? '';
            $r['sender_avatar'] = $ext['avatar'] ?? '';
            if ($r['sender_type'] === 'agent') {
                $r['agent_name'] = $r['sender_name'] ?: '客服';
                $r['agent_avatar'] = $r['sender_avatar'];
            }
        }
        unset($r);
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['messages' => $rows]]);
    }

    /**
     * 发送消息（访客）
     * POST /api/visitor/message/send
     */
    public function send(Request $request)
    {
        $tenantId = $this->resolveTenant($request);
        $sid = trim($request->post('session_id', ''));
        $content = trim($request->post('content', ''));
        $msgType = trim($request->post('msg_type', 'text')) ?: 'text';
        $senderType = trim($request->post('sender_type', 'visitor')) ?: 'visitor';
        if (!$sid || $content === '') {
            return json(['code' => 400, 'msg' => 'session_id 和 content 必填']);
        }
        Db::setTenantId($tenantId);
        $sess = Db::find(
            "SELECT * FROM kefu_session WHERE tenant_id = :t AND session_id = :sid",
            [':t' => $tenantId, ':sid' => $sid]
        );
        if (!$sess) return json(['code' => 404, 'msg' => '会话不存在']);

        // 取发送者信息
        $senderId = null;
        $senderName = null;
        $senderAvatar = null;
        if ($senderType === 'visitor') {
            $senderId = $sess['visitor_id'];
            $senderName = $sess['visitor_name'] ?: '访客';
            $senderAvatar = $sess['visitor_avatar'] ?: '';
        } elseif ($senderType === 'agent') {
            $senderId = $sess['agent_id'];
            $senderName = $sess['agent_name'] ?: '客服';
            $emp = Db::find("SELECT avatar FROM kefu_employee WHERE id = :id", [':id' => $senderId]);
            $senderAvatar = $emp ? ($emp['avatar'] ?: '') : '';
        }

        $now = date('Y-m-d H:i:s');
        try {
            $insertData = [
                'tenant_id'    => $tenantId,
                'session_id'   => $sid,
                'sender_type'  => $senderType,
                'msg_type'     => $msgType,
                'content'      => $content,
                'status'       => 'delivered',
                'created_at'   => $now,
            ];
            if ($senderType === 'visitor') {
                // visitor 消息：customer_id 用 0（kefu_session.customer_id 也是 bigint）
                $insertData['customer_id'] = 0;
                // 将访客姓名/头像塞入 ext_json
                $ext = ['name' => $senderName, 'avatar' => $senderAvatar, 'visitor_id' => $sess['visitor_id']];
                $insertData['ext_json'] = json_encode($ext, JSON_UNESCAPED_UNICODE);
            } elseif ($senderType === 'agent') {
                $insertData['agent_id'] = $senderId;
                $ext = ['name' => $senderName, 'avatar' => $senderAvatar];
                $insertData['ext_json'] = json_encode($ext, JSON_UNESCAPED_UNICODE);
            } elseif ($senderType === 'robot') {
                $insertData['sender_id'] = $senderId;
                $insertData['ext_json'] = json_encode(['name' => $senderName], JSON_UNESCAPED_UNICODE);
            }
            $msgId = Db::insert('kefu_message', $insertData);
        } catch (\Throwable $e) {
            Logger::error('发送消息失败', ['err' => $e->getMessage()]);
            return json(['code' => 500, 'msg' => '保存失败：' . $e->getMessage()]);
        }

        // 更新会话最后消息时间 + 消息数
        Db::exec(
            "UPDATE kefu_session SET last_message_at = :n, message_count = message_count + 1
             WHERE id = :id",
            [':n' => $now, ':id' => $sess['id']]
        );

        $msg = [
            'id' => $msgId,
            'session_id' => $sid,
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'sender_name' => $senderName,
            'sender_avatar' => $senderAvatar,
            'content' => $content,
            'msg_type' => $msgType,
            'created_at' => $now,
        ];
        if ($senderType === 'agent') {
            $msg['agent_name'] = $senderName;
            $msg['agent_avatar'] = $senderAvatar;
        }
        // 推送给客服端（如果有 WS）
        // 修复：原代码 \support\app('pusher')->pushToSession($tenantId, $sid, ...) 是错的——pusher 服务不存在，
        //      且 ConnectionManager::pushToSession 签名是 ($sessionId, $payload)，
        //      这里推到所有绑了此 session 的连接（访客 + 客服 + 兜底 visitor:any 队列）
        try {
            \app\lib\ConnectionManager::pushToSession($sid, [
                'type' => 'new_message',
                'data' => $msg,
                'agent_id' => $senderType === 'agent' ? $senderId : null,
            ]);
        } catch (\Throwable $e) {}

        return json(['code' => 0, 'msg' => '已发送', 'data' => ['message' => $msg]]);
    }

    /**
     * 轮询新消息（fallback，WS 不可用时）
     * GET /api/visitor/message/poll?session_id=xxx&since=2026-08-05 12:00:00
     */
    public function poll(Request $request)
    {
        $tenantId = $this->resolveTenant($request);
        $sid = trim($request->get('session_id', ''));
        $since = trim($request->get('since', ''));
        if (!$sid) return json(['code' => 400, 'msg' => 'session_id 必填']);
        Db::setTenantId($tenantId);
        $where = 'tenant_id = :t AND session_id = :sid';
        $bind = [':t' => $tenantId, ':sid' => $sid];
        if ($since) { $where .= ' AND created_at > :since'; $bind[':since'] = $since; }
        $rows = Db::query(
            "SELECT * FROM kefu_message WHERE $where ORDER BY id ASC LIMIT 100",
            $bind
        );
        // 补 agent 头像/名字
        foreach ($rows as &$r) {
            $ext = $r['ext_json'] ? json_decode($r['ext_json'], true) : [];
            $r['sender_name'] = $ext['name'] ?? '';
            $r['sender_avatar'] = $ext['avatar'] ?? '';
            if ($r['sender_type'] === 'agent') {
                $r['agent_name'] = $r['sender_name'] ?: '客服';
                $r['agent_avatar'] = $r['sender_avatar'];
            }
        }
        unset($r);
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['messages' => $rows]]);
    }
}