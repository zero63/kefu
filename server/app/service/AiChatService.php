<?php
namespace app\service;

use app\lib\Db;
use app\lib\QianfanClient;
use app\lib\Logger;
use app\lib\ConnectionManager;

/**
 * AI 智能体对话服务
 *
 * 流程：
 *   1. 访客发消息（session.serving_mode='ai' 且未转人工）
 *   2. 在 kefu_ai_conversation 写一条 'user' 记录
 *   3. 调用 QianfanClient::chat 取回复
 *   4. 写 'assistant' 记录 + 写 kefu_message + 推到访客
 *   5. 检查转人工触发：
 *       - 关键词命中（customers keywords_handoff）
 *       - 负向关键词命中（negative_keywords）
 *       - AI 已回复次数 >= max_ai_rounds
 *       - 访客手动请求 /session/handoff
 *   6. 若触发转人工：session.serving_mode='human', handoff_at=NOW, 自动 assignAgent
 */
class AiChatService
{
    /**
     * 处理访客 AI 对话（核心入口）
     * @return array [ 'reply'=>string, 'handoff'=>bool, 'reason'=>string ]
     */
    public function handleVisitorMessage($tenantId, $sessionId, $customerContent, $request = null) {
        Db::setTenantId($tenantId);
        $cfgSvc = new AiConfigService();
        $cfg = $cfgSvc->getConfig($tenantId);

        // 从中间件注入的 tunnel 属性读敏感词命中
        $sensitiveHit = 0;
        $sensitiveWords = [];
        if ($request) {
            $sensitiveHit = !empty($request->tunnelBlocked) ? 1 : 0;
            if (!empty($request->tunnelHits) && isset($request->tunnelHits['content'])) {
                $sensitiveWords = (array)$request->tunnelHits['content'];
            }
        }

        if (!$cfg['enabled'] || empty($cfg['api_key'])) {
            return ['reply' => '', 'handoff' => false, 'reason' => 'ai_disabled', 'error' => 'AI 未启用'];
        }

        $session = Db::find("SELECT * FROM kefu_session WHERE session_id = :s AND tenant_id = :t", [':s' => $sessionId, ':t' => $tenantId]);
        if (!$session) {
            return ['reply' => '', 'handoff' => false, 'error' => '会话不存在'];
        }

        // 1. 检测关键词：是否要求转人工
        // AI 不再限制 max_ai_rounds（不再"轮数到上限就强制转人工"）
        // 累计计数逻辑：访客消息命中转人工关键词时 +1；
        //   达到 cfg.handoff_keyword_count（默认 1）才真正转人工
        $needHandoff = false;
        $reason = '';
        $hkCount = intval($cfg['handoff_keyword_count'] ?? 1);
        $curCount = intval($session['handoff_keyword_count'] ?? 0);
        if ($this->contentMatches($customerContent, $cfg['keywords_handoff_arr'] ?? [])) {
            $newCount = $curCount + 1;
            Db::exec(
                "UPDATE kefu_session SET handoff_keyword_count = :c WHERE session_id = :s",
                [':c' => $newCount, ':s' => $sessionId]
            );
            if ($newCount >= $hkCount) {
                $needHandoff = true;
                $reason = 'visitor_keyword';
            }
        } elseif (
            !empty($cfg['negative_sentiment_handoff']) &&
            $this->contentMatches($customerContent, $cfg['negative_keywords_arr'] ?? [])
        ) {
            $needHandoff = true;
            $reason = 'negative_sentiment';
        }

        // 2. 写访客 user 消息到 AI 上下文
        $this->appendConversation($tenantId, $sessionId, 'user', $customerContent);
        // 也写一份到 kefu_message 持久化（sender_type=customer），并推到队列让前端实时收到
        $custMsgRow = $this->persistMessage($tenantId, $session, $customerContent, 'customer');
        // 修正消息的 customer_id 字段（persistMessage 暂未传入）
        if (!empty($custMsgRow['id'])) {
            try {
                // 同时把敏感词标记写回
                $sensitiveFields = '';
                $bind = [':cid' => intval($session['customer_id'] ?? 0), ':id' => intval($custMsgRow['id'])];
                if ($sensitiveHit) {
                    $sensitiveFields = ', is_sensitive = :ish, sensitive_words = :sw';
                    $bind[':ish'] = 1;
                    $bind[':sw'] = implode(',', $sensitiveWords);
                }
                Db::exec(
                    "UPDATE kefu_message SET customer_id = :cid $sensitiveFields WHERE id = :id",
                    $bind
                );
            } catch (\Throwable $e) {}
        }
        ConnectionManager::pushToSession($sessionId, [
            'type'         => 'message',
            'msg_id'       => (int)$custMsgRow['id'],
            'session_id'   => $sessionId,
            'sender_type'  => 'customer',
            'sender_id'    => (string)($session['customer_id'] ?? '0'),
            'msg_type'     => 'text',
            'content'      => $customerContent,
            'created_at'   => date('Y-m-d H:i:s'),
            'agent_id'     => 0,
        ]);

        // 3. 如果需要直接转人工，跳过千帆调用
        if ($needHandoff) {
            $handoffResult = $this->doHandoff($session, $reason, $cfg['greeting']);
            return [
                'reply' => '',
                'handoff' => true,
                'reason' => $reason,
                'handoff_msg' => $handoffResult['message'] ?? '',
                // 修复：同上，把访客消息 id 一起返回
                'customer_msg_id' => $custMsgRow['id'] ?? 0,
            ];
        }

        // 4. 千帆调用
        $history = $this->loadHistory($tenantId, $sessionId, 10);
        $messages = QianfanClient::buildMessages($history, $cfg['system_prompt'], 10);

        $cli = new QianfanClient($cfg['api_key'], $cfg['app_id'] ?? '', [
            'endpoint'  => $cfg['endpoint'] ?: null,
            'secret_key'=> $cfg['secret_key'] ?? '',
            'timeout_ms'=> $cfg['max_response_ms'] ?? 6000,
        ]);
        $result = $cli->chat($messages);

        if (!$result['success']) {
            Logger::warn('ai_chat_failed', ['session_id' => $sessionId, 'error' => $result['error']]);
            $errMsg = '[抱歉，AI 暂时不可用] ' . $result['error'];
            return ['reply' => $errMsg, 'handoff' => false, 'error' => $result['error'], 'customer_msg_id' => $custMsgRow['id'] ?? 0];
        }

        $reply = trim((string)$result['content']);
        // 5. 持久化 assistant 消息
        $this->appendConversation($tenantId, $sessionId, 'assistant', $reply, $result['tokens']);
        $msgRow = $this->persistMessage($tenantId, $session, $reply, 'ai');

        // 6. session 上 ai_round_count +1
        Db::exec("UPDATE kefu_session SET ai_round_count = ai_round_count + 1 WHERE session_id = :s", [':s' => $sessionId]);

        // 7. 推送消息给访客（visitor:any:{sessionId} + session 队列）
        $pushPayload = [
            'type' => 'message',
            'sender_type' => 'ai',
            'sender_id' => 'ai-bot',
            'sender_name' => '智能客服',
            'msg_id' => $msgRow['id'],
            'session_id' => $sessionId,
            'content' => $reply,
            'msg_type' => 'text',
            'agent_id' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        ConnectionManager::pushToSession($sessionId, $pushPayload);

        // 8. 检查转人工（达到 max_ai_rounds 后，再次发消息时强制转）
        if (intval($session['ai_round_count']) + 1 >= intval($cfg['max_ai_rounds'])) {
            // 在 reply 后不发强制转，但下一条会转
        }

        return [
            'reply' => $reply,
            'handoff' => false,
            'tokens' => $result['tokens'] ?? 0,
            // 修复：把访客消息 id 暴露给前端，让 H5.send 能正确标记 renderedMsgIds 避免 poll 重复渲染
            'customer_msg_id' => $custMsgRow['id'] ?? 0,
        ];
    }

    /**
     * 立即转人工（访客主动请求）
     */
    public function handoffNow($tenantId, $sessionId, $reason = 'visitor_request') {
        Db::setTenantId($tenantId);
        $session = Db::find("SELECT * FROM kefu_session WHERE session_id = :s AND tenant_id = :t", [':s' => $sessionId, ':t' => $tenantId]);
        if (!$session) return ['code' => 404, 'msg' => '会话不存在'];
        if ($session['serving_mode'] === 'human') return ['code' => 0, 'msg' => '已在人工服务'];
        $cfgSvc = new AiConfigService();
        $cfg = $cfgSvc->getConfig($tenantId);
        $r = $this->doHandoff($session, $reason, $cfg['greeting']);
        return ['code' => 0, 'msg' => 'ok', 'data' => $r];
    }

    /**
     * 执行转人工：分配 agent_id，更新 serving_mode，写日志 + 推送事件
     */
    private function doHandoff($session, $reason, $greeting) {
        $tenantId = intval($session['tenant_id']);
        $sessionSvc = new SessionService();
        $newSession = $sessionSvc->handoffToHuman($tenantId, $session['session_id'], $reason);

        // 推送 system 通知给访客 + 客服
        $eventPayload = [
            'type' => 'handoff',
            'session_id' => $session['session_id'],
            'reason' => $reason,
            'agent' => $newSession['agent'] ?? null,
            'message' => '已为您转接人工客服，请稍候！',
            'created_at' => date('Y-m-d H:i:s'),
        ];
        ConnectionManager::pushToSession($session['session_id'], $eventPayload);
        // 同时触发 role
        ConnectionManager::pushToRole('admin', $eventPayload);
        return $eventPayload;
    }

    /**
     * 写 kefu_ai_conversation
     */
    private function appendConversation($tenantId, $sessionId, $role, $content, $tokens = 0) {
        Db::exec(
            "INSERT INTO kefu_ai_conversation (tenant_id, session_id, role, content, tokens, created_at) VALUES (:t, :s, :r, :c, :tk, NOW())",
            [':t' => $tenantId, ':s' => $sessionId, ':r' => $role, ':c' => $content, ':tk' => $tokens]
        );
    }

    private function loadHistory($tenantId, $sessionId, $limit = 10) {
        $rows = Db::query(
            "SELECT role, content FROM kefu_ai_conversation WHERE tenant_id = :t AND session_id = :s ORDER BY id ASC",
            [':t' => $tenantId, ':s' => $sessionId]
        );
        // 仅取最近 $limit 条
        return array_slice($rows, max(0, count($rows) - $limit));
    }

    /**
     * 写消息到 kefu_message（统一消息表）—— 事务化
     * 事务流程：beginTransaction → 计算 session_sequence → INSERT kefu_message
     *           → UPDATE kefu_session.session_sequence + last_active_at → commit
     * 异常时 rollBack
     */
    private function persistMessage($tenantId, $sessionRow, $content, $senderType) {
        Db::setTenantId($tenantId);
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            // 计算会话内消息序号（加锁防并发）
            $currentMaxSeq = Db::value(
                "SELECT IFNULL(MAX(session_sequence), 0) FROM kefu_message WHERE session_id = :s FOR UPDATE",
                [':s' => $sessionRow['session_id']]
            );
            $nextSeq = intval($currentMaxSeq) + 1;

            $ins = [
                'tenant_id' => $tenantId,
                'session_id' => $sessionRow['session_id'],
                'sender_type' => $senderType,    // customer / ai
                'sender_id' => (string)($senderType === 'ai' ? '0' : ($sessionRow['customer_id'] ?? '0')),
                'agent_id' => 0,
                'msg_type' => 'text',
                'content' => $content,
                'ext_json' => '{}',
                'session_sequence' => $nextSeq,
                'client_msg_id' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            $cols = array_keys($ins);
            $place = array_map(function ($c) { return ':' . $c; }, $cols);
            Db::exec(
                "INSERT INTO kefu_message (" . implode(',', $cols) . ") VALUES (" . implode(',', $place) . ")",
                array_combine($place, array_values($ins))
            );
            $id = Db::value("SELECT LAST_INSERT_ID() AS id");

            // 更新会话的最新消息序号和最后活跃时间
            $extraUpdate = '';
            if ($senderType === 'customer') {
                $extraUpdate = ', last_customer_at = NOW()';
            } elseif ($senderType === 'agent') {
                $extraUpdate = ', last_agent_at = NOW()';
            }
            Db::exec(
                "UPDATE kefu_session SET session_sequence = :seq, last_active_at = NOW() $extraUpdate WHERE session_id = :s",
                [':seq' => $nextSeq, ':s' => $sessionRow['session_id']]
            );

            $pdo->commit();

            return ['id' => $id, 'session_sequence' => $nextSeq] + $ins;
        } catch (\Exception $e) {
            $pdo->rollBack();
            Logger::error('persistMessage 事务失败', ['err' => $e->getMessage(), 'session_id' => $sessionRow['session_id']]);
            throw $e;
        }
    }

    /**
     * 关键词命中检测
     */
    private function contentMatches($content, $keywords) {
        if (empty($content) || empty($keywords)) return false;
        foreach ((array)$keywords as $kw) {
            if ($kw === '') continue;
            if (mb_stripos($content, $kw) !== false) return true;
        }
        return false;
    }
}