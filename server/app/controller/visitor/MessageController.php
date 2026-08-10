<?php
/**
 * 访客端 - 发送消息
 *
 * POST /api/visitor/message/send
 *   Body: { tenant_id, session_id, content, type?, client_msg_id? }
 *
 * 业务规则：
 *   1. 访客必须传 session_id
 *   2. 根据 session.serving_mode 决定走 AI（千帆）还是 人工 服务
 *   3. AI 模式：
 *      - 调千帆 API 取回复
 *      - 推消息给访客（含 type=message, sender_type=ai）
 *      - 关键词 / 负面情绪 / 轮数达到上限 时自动转人工
 *   4. 人共模式：
 *      - 写消息，推送给分配的客服
 */

namespace app\controller\visitor;

use support\Request;
use app\service\MessageService;
use app\service\AiChatService;
use app\lib\Db;

class MessageController
{
    public function send(Request $request) {
        @file_put_contents('d:/phpstudy_pro/WWW/kefu/server/runtime/logs/reopen_debug.log',
            date('H:i:s')." ENTER send session_id=".($request->post('session_id','') ?: '').
            " tenant=".$request->post('tenant_id', 1).
            "\n", FILE_APPEND);
        $tenantId = intval($request->post('tenant_id', 1));
        $sessionId = trim($request->post('session_id', ''));
        $content = trim($request->post('content', ''));
        $type = $request->post('type', '');
        if ($type === '' || $type === 'text') {
            // 修复：兼容前端的 msg_type 字段（vUploadAndSend 等媒体上传函数用 msg_type）
            $type = $request->post('msg_type', 'text');
        }
        $clientMsgId = $request->post('client_msg_id', null);

        if (empty($sessionId) || $content === '') {
            return json(['code' => 400, 'msg' => 'session_id 和 content 必填']);
        }

        Db::setTenantId($tenantId);
        $session = Db::find(
            "SELECT id, customer_id, agent_id, serving_mode, status FROM kefu_session WHERE session_id = :s",
            [':s' => $sessionId]
        );
        $cnt = Db::value("SELECT COUNT(*) FROM kefu_session", []);
        $byLike = Db::value("SELECT COUNT(*) FROM kefu_session WHERE session_id LIKE :s", [':s' => 's_reopen_%']);
        @file_put_contents('d:/phpstudy_pro/WWW/kefu/server/runtime/logs/reopen_debug.log',
            "  count=" . $cnt . " byLike=" . $byLike . " find_return=" . ($session === null ? 'NULL' : ($session === false ? 'FALSE' : json_encode($session))) . "\n", FILE_APPEND);
        if (!$session) return json(['code' => 404, 'msg' => '会话不存在']);

        // 修复：会话已被超时关闭（status=closed），访客重新发消息时自动"复活"
        // - 人工模式（serving_mode='human' 且已分配 agent_id）：保留 agent_id 分配、恢复 active，
        //   原客服在接待台再次看到该会话（按 customer_id + active 过滤的 list 会列出）
        // - AI 模式（serving_mode='ai'）：回到 AI 接待
        // - 其他：默认回 AI（让 AI 接手，由 AI 关键词触发转人工）
        if (($session['status'] ?? '') === 'closed') {
            try {
                $revivedMode = in_array($session['serving_mode'], ['human', 'ai'], true) ? $session['serving_mode'] : 'ai';
                Db::exec(
                    "UPDATE kefu_session
                     SET status = 'active',
                         close_reason = NULL,
                         closed_at = NULL,
                         serving_mode = :m,
                         last_customer_at = NOW()
                     WHERE session_id = :s",
                    [':m' => $revivedMode, ':s' => $sessionId]
                );
                $session['status'] = 'active';
                $session['close_reason'] = null;
                $session['serving_mode'] = $revivedMode;
                \app\lib\Logger::info('session_revived', [
                    'session_id' => $sessionId,
                    'reason'     => 'visitor_reopen_after_timeout',
                    'mode'       => $revivedMode,
                    'agent_id'   => $session['agent_id'],
                ]);
            } catch (\Throwable $e) {
                \app\lib\Logger::error('session_reopen_failed', [
                    'session_id' => $sessionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $mode = $session['serving_mode'] ?? 'ai';

        // ===== AI 模式：交给 AiChatService 处理（含关键词 / 负面 / 转人工） =====
        if ($mode === 'ai') {
            try {
                $ai = new AiChatService();
                // 修复：把 request 传过去，让 AiChatService 能读取敏感词命中
                $r = $ai->handleVisitorMessage($tenantId, $sessionId, $content, $request);
            } catch (\Throwable $e) {
                \app\lib\Logger::error('ai_send_error', [
                    'session_id' => $sessionId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return json(['code' => 500, 'msg' => 'AI 处理失败：' . $e->getMessage()]);
            }

            if (!empty($r['handoff'])) {
                return json([
                    'code' => 0,
                    'msg' => 'ok',
                    'data' => [
                        'mode' => 'handoff',
                        'reason' => $r['reason'],
                        'message' => $r['handoff_msg'] ?? '已转人工',
                        'agent_id' => $r['agent_id'] ?? null,
                        // 修复：把访客消息 id 暴露给前端（防止 poll 重复渲染）
                        'msg_id' => $r['customer_msg_id'] ?? 0,
                    ],
                ]);
            }
            if (!empty($r['error'])) {
                return json(['code' => 500, 'msg' => $r['error']]);
            }
            return json([
                'code' => 0,
                'msg' => 'ok',
                'data' => [
                    'mode' => 'ai',
                    'reply' => $r['reply'],
                    'tokens' => $r['tokens'] ?? 0,
                    // 修复：同上（防止 AI 模式下访客消息被 poll 重复渲染）
                    'msg_id' => $r['customer_msg_id'] ?? 0,
                ],
            ]);
        }

        // ===== 留言模式：无客服在线，写入 kefu_leave_message =====
        if ($mode === 'message') {
            // 取访客信息
            $customer = Db::find(
                "SELECT customer_id, nickname, avatar, email, phone FROM kefu_customer WHERE id = :cid",
                [':cid' => $session['customer_id']]
            );
            $visitorName = $customer['nickname'] ?? '';
            $visitorEmail = $customer['email'] ?? '';
            $visitorPhone = $customer['phone'] ?? '';
            $visitorId = $customer['customer_id'] ?? '';

            // 拼接工单流水号（仅新建时使用；合并时不覆盖原有 ticket_no）
            $today = date('Ymd');
            $ticketNo = null;

            try {
                // 5 分钟内同访客未回复留言合并（追加内容）
                $existing = Db::find(
                    "SELECT id, content FROM kefu_leave_message
                     WHERE tenant_id = :t AND visitor_id = :vid AND status = 'new'
                       AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                     ORDER BY id DESC LIMIT 1",
                    [':t' => $tenantId, ':vid' => $visitorId ?: '']
                );
                if ($existing) {
                    $merged = $existing['content'] . "\n---\n" . $content;
                    Db::exec(
                        "UPDATE kefu_leave_message SET content = :c, updated_at = NOW() WHERE id = :id",
                        [':c' => $merged, ':id' => $existing['id']]
                    );
                    $lmId = intval($existing['id']);
                } else {
                    try {
                        $maxId = Db::value("SELECT IFNULL(MAX(id), 0) FROM kefu_leave_message WHERE tenant_id = :t", [':t' => $tenantId]);
                        $seq = (int)$maxId + 1;
                    } catch (\Throwable $e) {
                        $seq = (int)(microtime(true) * 100) % 999999;
                    }
                    $ticketNo = 'LM' . $today . str_pad((string)$seq, 6, '0', STR_PAD_LEFT);
                    $lmId = Db::insert('kefu_leave_message', [
                        'ticket_no'    => $ticketNo,
                        'tenant_id'    => $tenantId,
                        'visitor_id'   => $visitorId ?: null,
                        'visitor_name' => $visitorName ?: null,
                        'visitor_email'=> $visitorEmail ?: null,
                        'visitor_phone'=> $visitorPhone ?: null,
                        'visitor_meta' => null,
                        'session_id'   => $sessionId ?: null,
                        'source'       => 'chat',
                        'subject'      => '在线会话留言',
                        'content'      => $content,
                        'status'       => 'new',
                        'priority'     => 'normal',
                        'ip'           => $request->getRealIp(),
                        'user_agent'   => substr((string)$request->header('user-agent'), 0, 255),
                        'created_at'   => date('Y-m-d H:i:s'),
                        'updated_at'   => date('Y-m-d H:i:s'),
                    ]);
                }
            } catch (\Throwable $e) {
                \app\lib\Logger::error('留言模式写入失败', ['err' => $e->getMessage()]);
            }

            // 同时写一份到 kefu_message 保持聊天记录连续
            $svc = new MessageService();
            $svc->visitorSend($tenantId, $sessionId, [
                'session_id'     => $sessionId,
                'session_row_id' => $session['id'],
                'customer_id'    => $session['customer_id'],
                'agent_id'       => 0,
                'content'        => $content,
                'msg_type'       => $type,
                'client_msg_id'  => $clientMsgId,
                'is_sensitive'   => !empty($request->tunnelBlocked) ? 1 : 0,
                'sensitive_words'=> (!empty($request->tunnelHits) && isset($request->tunnelHits['content']))
                                    ? implode(',', (array)$request->tunnelHits['content'])
                                    : null,
            ]);

            return json([
                'code' => 0,
                'msg'  => '已留言成功，客服上线后会尽快回复您',
                'data' => [
                    'mode'       => 'message',
                    'message'    => '留言已提交，客服上线后将尽快与您联系',
                    'ticket_no'  => $ticketNo,
                    'leave_id'   => $lmId ?? 0,
                ],
            ]);
        }

        // ===== 人工模式：写消息 + 推给客服 =====
        $svc = new MessageService();
        $result = $svc->visitorSend($tenantId, $sessionId, [
            'session_id'    => $sessionId,
            'session_row_id' => $session['id'],
            'customer_id'   => $session['customer_id'],
            'agent_id'      => $session['agent_id'],
            'content'       => $content,
            'msg_type'      => $type,
            // 修复：把媒体字段从请求体透传给 persist（视频/图片/文件/音频/表情）
            'media_url'     => trim($request->post('media_url', '')),
            'ext'           => $request->post('ext', []),
            'client_msg_id' => $clientMsgId,
            'is_sensitive'  => !empty($request->tunnelBlocked) ? 1 : 0,
            'sensitive_words'=> (!empty($request->tunnelHits) && isset($request->tunnelHits['content']))
                                ? implode(',', (array)$request->tunnelHits['content'])
                                : null,
        ]);
        // 修复：补充 mode 字段（前端 visitor-demo.html 用 mode 决定显示谁回复）
        if (is_array($result) && isset($result['data'])) {
            $result['data']['mode'] = 'human';
            $result['data']['message'] = '消息已发送给客服';
            // 修复：附带当前客服 name / avatar，方便前端渲染（前端 visitor-demo.html 显示头像用）
            if ($session['agent_id']) {
                $agentInfo = Db::find(
                    "SELECT real_name, avatar FROM kefu_employee WHERE id = :i",
                    [':i' => $session['agent_id']]
                );
                $result['data']['agent_name'] = $agentInfo['real_name'] ?? '';
                $result['data']['agent_avatar'] = $agentInfo['avatar'] ?? '';
            }
        }
        return json($result);
    }

    /**
     * 主动请求转人工（访客 UI 上的 "转人工" 按钮）
     *   POST /api/visitor/message/handoff
     *   Body: { tenant_id, session_id, reason? }
     */
    public function handoff(Request $request) {
        $tenantId = intval($request->post('tenant_id', 1));
        $sessionId = trim($request->post('session_id', ''));
        $reason = trim($request->post('reason', 'visitor_request'));
        if (empty($sessionId)) {
            return json(['code' => 400, 'msg' => 'session_id 必填']);
        }
        $ai = new AiChatService();
        return json($ai->handoffNow($tenantId, $sessionId, $reason));
    }

    /**
     * 访客拉取会话状态（用于 UI 显示当前 serving_mode + 客服姓名）
     *   GET /api/visitor/message/status?tenant_id=1&session_id=...
     */
    public function status(Request $request) {
        $tenantId = intval($request->get('tenant_id', 1));
        $sessionId = trim($request->get('session_id', ''));
        if (empty($sessionId)) return json(['code' => 400, 'msg' => 'session_id 必填']);
        Db::setTenantId($tenantId);
        $session = Db::find(
            "SELECT s.serving_mode, s.agent_id, s.status, s.ai_round_count, s.handoff_reason, e.real_name AS agent_name
             FROM kefu_session s LEFT JOIN kefu_employee e ON e.id = s.agent_id
             WHERE s.session_id = :s",
            [':s' => $sessionId]
        );
        if (!$session) return json(['code' => 404, 'msg' => '会话不存在']);
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'serving_mode' => $session['serving_mode'],
            'status' => $session['status'],
            'agent' => $session['agent_id'] ? ['id' => $session['agent_id'], 'name' => $session['agent_name']] : null,
            'ai_round_count' => $session['ai_round_count'],
            'handoff_reason' => $session['handoff_reason'],
        ]]);
    }

    /**
     * 访客拉取消息历史（按时间倒序 limit）
     *   GET /api/visitor/message/history?tenant_id=1&session_id=...&limit=50
     */
    public function history(Request $request) {
        $tenantId = intval($request->get('tenant_id', 1));
        $sessionId = trim($request->get('session_id', ''));
        $limit = max(1, min(200, intval($request->get('limit', 50))));
        if (empty($sessionId)) return json(['code' => 400, 'msg' => 'session_id 必填']);
        Db::setTenantId($tenantId);
        // 修复：JOIN agent 获取 name/avatar 用于访客端显示客服头像
        $msgs = Db::query(
            "SELECT m.id, m.sender_type, m.sender_id, m.content, m.msg_type, m.media_url, m.created_at, m.ext_json,
                    e.real_name AS agent_name, e.avatar AS agent_avatar
             FROM kefu_message m
             LEFT JOIN kefu_employee e ON e.id = m.agent_id
             WHERE m.session_id = :s ORDER BY m.id DESC LIMIT " . $limit,
            [':s' => $sessionId]
        );
        $session = Db::find("SELECT s.serving_mode, s.agent_id, e.real_name AS agent_name FROM kefu_session s LEFT JOIN kefu_employee e ON e.id = s.agent_id WHERE s.session_id = :s", [':s' => $sessionId]);
        // 取客服信息（让前端能立即渲染 mode-bar 上的客服头像/姓名）
        $agentInfo = null;
        if ($session && !empty($session['agent_id'])) {
            $agentInfo = Db::find("SELECT id, real_name, avatar FROM kefu_employee WHERE id = :i", [':i' => $session['agent_id']]);
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'session' => $session ?: [],
            'agent'   => $agentInfo,
            'messages' => array_reverse($msgs ?: []),
        ]]);
    }
}