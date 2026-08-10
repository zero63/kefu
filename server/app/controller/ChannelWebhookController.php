<?php
/**
 * 渠道 webhook 接收端
 *
 * 路由：/api/channel/{code}/{account_id}
 * 例如：/api/channel/api/123  → 渠道代码 api，账号 ID 123
 *
 * 鉴权：API 渠道使用 X-API-Key / Authorization: Bearer {api_key}
 * 请求体（JSON）：
 * {
 *   "visitor_id": "user_123",         // 必填，访客唯一 ID
 *   "session_id": "s_xxx",            // 可选，不传则自动生成
 *   "name": "李雷",                    // 可选，访客姓名
 *   "avatar": "https://...",           // 可选，访客头像
 *   "content": "消息内容",             // 必填
 *   "msg_type": "text",               // 可选，默认 text
 *   "message_id": "msg_xxx",           // 可选，用于幂等
 *   "custom_fields": {                 // 可选，自定义字段（业务订单等）
 *     "order_no": "ORD-2026",
 *     "vip_level": "gold"
 *   }
 * }
 *
 * 返回：
 * { code:0, msg:'ok', data: { session_id, message_id, agent:{...} } }
 */
namespace app\controller;

use support\Request;
use app\lib\Db;
use app\lib\Logger;

class ChannelWebhookController
{
    public function handle(Request $request)
    {
        // 支持 URL: /api/channel/{code}/{account_id} 或 /api/channel/{code}/webhook
        $path = trim($request->path(), '/');
        $segs = explode('/', $path); // ['api','channel','CODE','ID'] or ['api','channel','CODE','webhook']
        $code = isset($segs[2]) ? strtolower(trim($segs[2])) : '';
        $lastSeg = isset($segs[3]) ? trim($segs[3]) : '';
        // webhook 端点：找该渠道默认账号
        if ($lastSeg === 'webhook') {
            $accountId = 0; // 用渠道类型匹配第一个启用的账号
        } else {
            $accountId = intval($lastSeg);
        }

        if (!$code) {
            return json(['code' => 400, 'msg' => '渠道代码必填']);
        }

        // webhook 端点：找该渠道第一个启用的账号
        if ($accountId === 0) {
            $tenantId = $this->resolveTenant($request);
            $acc = Db::find(
                "SELECT a.* FROM kefu_channel_account a
                 JOIN kefu_channel t ON t.id = a.channel_id
                 WHERE a.tenant_id = :t AND t.channel_code = :c AND a.status = 1
                 ORDER BY a.id LIMIT 1",
                [':t' => $tenantId, ':c' => $code]
            );
            if (!$acc) return json(['code' => 404, 'msg' => '该渠道无启用账号']);
            $accountId = $acc['id'];
        }

        // 1. 查账号
        $account = Db::find(
            "SELECT a.*, t.channel_name, t.channel_code AS type_code
             FROM kefu_channel_account a
             JOIN kefu_channel t ON t.id = a.channel_id
             WHERE a.id = :id AND a.status = 1",
            [':id' => $accountId]
        );
        if (!$account) return json(['code' => 404, 'msg' => '账号不存在或已禁用']);
        if (strtolower($account['type_code']) !== $code) {
            return json(['code' => 400, 'msg' => '渠道代码与账号不匹配']);
        }

        // 2. 鉴权（按渠道类型）
        $auth = $this->authenticate($request, $account, $code);
        if ($auth !== true) return $auth;

        $tenantId = intval($account['tenant_id']);
        Db::setTenantId($tenantId);

        // 3. 解析请求体
        $body = $request->post();
        if (!$body) {
            $raw = $request->rawBody();
            $body = $raw ? json_decode($raw, true) : [];
        }
        if (!is_array($body)) $body = [];

        $visitorId = trim($body['visitor_id'] ?? '');
        $content = trim($body['content'] ?? '');
        if (!$visitorId || $content === '') {
            return json(['code' => 400, 'msg' => 'visitor_id 和 content 必填']);
        }
        $sessionId = trim($body['session_id'] ?? '');
        $name = trim($body['name'] ?? '');
        $avatar = trim($body['avatar'] ?? '');
        $msgType = trim($body['msg_type'] ?? 'text') ?: 'text';
        $messageId = trim($body['message_id'] ?? '');
        $customFields = $body['custom_fields'] ?? [];
        if (!is_array($customFields)) $customFields = [];

        // 4. 幂等（按 message_id）
        if ($messageId) {
            $exist = Db::value(
                "SELECT id FROM kefu_channel_message WHERE tenant_id = :t AND message_id = :mid",
                [':t' => $tenantId, ':mid' => $messageId]
            );
            if ($exist) {
                return json(['code' => 0, 'msg' => 'ok（幂等）', 'data' => ['message_id' => $messageId]]);
            }
        }

        // 5. 创建/获取会话
        $session = $this->ensureSession($tenantId, $sessionId, $visitorId, $code, $name, $avatar, $customFields, $accountId);

        // 6. 写入消息
        $now = date('Y-m-d H:i:s');
        try {
            $msgId = Db::insert('kefu_message', [
                'tenant_id' => $tenantId,
                'session_id' => $session['session_id'],
                'customer_id' => 0,
                'agent_id' => $session['agent_id'] ?: null,
                'sender_type' => 'visitor',
                'msg_type' => $msgType,
                'content' => $content,
                'status' => 'delivered',
                'ext_json' => json_encode([
                    'name' => $name,
                    'avatar' => $avatar,
                    'visitor_id' => $visitorId,
                    'custom_fields' => $customFields,
                    'channel' => $code,
                    'channel_account' => $account['account_name'],
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);
        } catch (\Throwable $e) {
            Logger::error('API 渠道接收消息失败', ['err' => $e->getMessage()]);
            return json(['code' => 500, 'msg' => '保存失败：' . $e->getMessage()]);
        }

        // 7. 记录渠道消息
        try {
            Db::insert('kefu_channel_message', [
                'tenant_id' => $tenantId,
                'channel_id' => intval($account['channel_id']),
                'account_id' => $accountId,
                'session_id' => $session['session_id'],
                'visitor_id' => $visitorId,
                'message_id' => $messageId ?: ('auto_' . $msgId),
                'direction' => 'inbound',
                'sender_type' => 'visitor',
                'sender_name' => $name,
                'content' => $content,
                'msg_type' => $msgType,
                'extra_json' => json_encode(['custom_fields' => $customFields], JSON_UNESCAPED_UNICODE),
                'ip' => $request->getRealIp(),
            ]);
        } catch (\Throwable $e) {
            Logger::warn('记录渠道消息失败', ['err' => $e->getMessage()]);
        }

        // 8. 更新会话
        Db::exec(
            "UPDATE kefu_session SET last_message_at = :n, message_count = message_count + 1,
             customer_msg_count = customer_msg_count + 1
             WHERE id = :id",
            [':n' => $now, ':id' => $session['id']]
        );

        // 9. 推送给客服
        try {
            \app\lib\Pusher::pushToSession($tenantId, $session['session_id'], [
                'type' => 'new_message',
                'data' => [
                    'id' => $msgId,
                    'session_id' => $session['session_id'],
                    'sender_type' => 'visitor',
                    'content' => $content,
                    'created_at' => $now,
                    'agent_name' => null,
                    'agent_avatar' => null,
                ],
            ]);
        } catch (\Throwable $e) {}

        return json([
            'code' => 0, 'msg' => 'ok',
            'data' => [
                'session_id' => $session['session_id'],
                'message_id' => $messageId ?: ('msg_' . $msgId),
                'agent' => $session['agent_id'] ? [
                    'id' => $session['agent_id'],
                    'name' => $session['agent_name'],
                ] : null,
            ],
        ]);
    }

    /**
     * 鉴权
     */
    private function resolveTenant(Request $request)
    {
        $tid = intval($request->header('x-tenant-id', 0));
        if ($tid > 0) return $tid;
        $tid = intval($request->get('tenant_id', 0));
        if ($tid > 0) return $tid;
        $tid = intval($request->post('tenant_id', 0));
        if ($tid > 0) return $tid;
        return 1;
    }

    private function authenticate(Request $request, $account, $code)
    {
        // API 渠道：api_key 鉴权
        if ($code === 'api') {
            $apiKey = trim($request->header('x-api-key', ''));
            $auth = trim($request->header('authorization', ''));
            // 支持 Bearer
            if (!$apiKey && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
                $apiKey = trim($m[1]);
            }
            // URL 参数 fallback
            if (!$apiKey) $apiKey = trim($request->get('api_key', ''));

            if (!$apiKey) {
                return json(['code' => 401, 'msg' => '缺少 API Key（Header: X-API-Key 或 Authorization: Bearer xxx）']);
            }
            if ($apiKey !== $account['api_key']) {
                return json(['code' => 401, 'msg' => 'API Key 不正确']);
            }
            return true;
        }
        // 微信等：校验 token / signature（这里简化为放行，依赖 server 的 IP 白名单等）
        return true;
    }

    /**
     * 创建/获取会话
     */
    private function ensureSession($tenantId, $sessionId, $visitorId, $channel, $name, $avatar, $meta, $accountId)
    {
        if ($sessionId) {
            $exist = Db::find(
                "SELECT * FROM kefu_session WHERE tenant_id = :t AND session_id = :sid",
                [':t' => $tenantId, ':sid' => $sessionId]
            );
            if ($exist) {
                $data = [];
                if ($name) $data['visitor_name'] = $name;
                if ($avatar) $data['visitor_avatar'] = $avatar;
                if (!empty($meta)) $data['visitor_meta'] = json_encode($meta, JSON_UNESCAPED_UNICODE);
                if ($data) Db::update('kefu_session', $data, ['id' => $exist['id']]);
                return array_merge($exist, $data);
            }
        }
        // 创建新会话，找在线客服
        $onlineAgent = $this->pickOnlineAgent($tenantId);
        $autoOffline = $onlineAgent === null;
        $sessionId = $sessionId ?: ('s_api_' . substr(md5($visitorId . microtime(true)), 0, 12));
        $id = Db::insert('kefu_session', [
            'tenant_id' => $tenantId,
            'session_id' => $sessionId,
            'customer_id' => 0,
            'visitor_id' => $visitorId,
            'visitor_name' => $name ?: null,
            'visitor_avatar' => $avatar ?: null,
            'visitor_meta' => !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            'channel' => $channel,
            'channel_account_id' => $accountId,
            'status' => $autoOffline ? 'waiting' : 'active',
            'agent_id' => $onlineAgent ? $onlineAgent['id'] : null,
            'agent_name' => $onlineAgent ? $onlineAgent['real_name'] : null,
            'source' => $channel,
        ]);
        // 写入访客档案
        $this->upsertCustomer($tenantId, $visitorId, $name, $avatar, $channel, $meta);
        return [
            'id' => $id, 'session_id' => $sessionId,
            'agent_id' => $onlineAgent ? $onlineAgent['id'] : null,
            'agent_name' => $onlineAgent ? $onlineAgent['real_name'] : null,
        ];
    }

    private function pickOnlineAgent($tenantId)
    {
        $rows = Db::query(
            "SELECT e.id, e.real_name, e.username, e.avatar,
                    (SELECT COUNT(*) FROM kefu_session s
                     WHERE s.tenant_id = e.tenant_id AND s.agent_id = e.id
                       AND s.status IN ('active', 'queued')) AS session_count
             FROM kefu_employee e
             WHERE e.tenant_id = :t AND e.status = 1 AND e.work_status = 'online'
             ORDER BY session_count ASC, e.id ASC LIMIT 1",
            [':t' => $tenantId]
        );
        if (!empty($rows)) return $rows[0];
        return null;
    }

    private function upsertCustomer($tenantId, $visitorId, $name, $avatar, $channel, $meta)
    {
        try {
            $exist = Db::find(
                "SELECT id FROM kefu_customer WHERE tenant_id = :t AND customer_id = :c",
                [':t' => $tenantId, ':c' => $visitorId]
            );
            if (!$exist) {
                Db::insert('kefu_customer', [
                    'tenant_id' => $tenantId,
                    'customer_id' => $visitorId,
                    'nickname' => $name ?: null,
                    'avatar' => $avatar ?: null,
                    'channel' => $channel,
                    'profile' => !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                    'status' => 1,
                    'last_active_time' => date('Y-m-d H:i:s'),
                ]);
            } else {
                $u = ['last_active_time' => date('Y-m-d H:i:s')];
                if ($name) $u['nickname'] = $name;
                if ($avatar) $u['avatar'] = $avatar;
                if (!empty($meta)) $u['profile'] = json_encode($meta, JSON_UNESCAPED_UNICODE);
                Db::update('kefu_customer', $u, ['id' => $exist['id']]);
            }
        } catch (\Throwable $e) {
            Logger::warn('upsert customer fail', ['err' => $e->getMessage()]);
        }
    }
}