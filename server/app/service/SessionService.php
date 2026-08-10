<?php
/**
 * 会话业务服务
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 *
 * 业务规则：
 *   - 排队策略：FIFO（先到先服务）+ 优先级
 *   - 自动分配：会话进入 waiting 时尝试自动找客服（按空闲程度）
 *   - 转接：原客服会话状态变 closed/transfer，新客服接管
 *   - 关闭：正常关闭（resolved/timeout），或访客主动离开
 */

namespace app\service;

use app\lib\Db;
use app\lib\Logger;
use app\lib\ConnectionManager;

class SessionService
{
    /**
     * 获取访客当前活跃会话（无则创建）
     * @param int $tenantId
     * @param string $customerId 访客业务 ID
     * @param string $channel 渠道
     * @return array|false
     */
    public function getOrCreateActiveSession($tenantId, $customerId, $channel = 'h5') {
        // 1. 取得/创建客户档案
        $customerIdInternal = $this->upsertCustomer($tenantId, $customerId, $channel);

        // 2. 查找该访客当前进行中的会话
        $session = Db::find(
            "SELECT s.*, e.real_name AS agent_name, e.status AS agent_enabled
             FROM kefu_session s
             LEFT JOIN kefu_employee e ON e.id = s.agent_id
             WHERE s.customer_id = :cid
               AND s.status IN ('waiting', 'active')
             ORDER BY s.id DESC
             LIMIT 1",
            [':cid' => $customerIdInternal]
        );

        if ($session) {
            // 关键修复：如果会话挂着但客服已被禁用/删除（agent_enabled=0），
            // 清空 agent_id，重新走分配逻辑（找不到客服就走留言模式）
            $needsReassign = false;
            if ($session['status'] === 'active' && $session['agent_id']
                && (int)($session['agent_enabled'] ?? 1) === 0) {
                Db::exec(
                    "UPDATE kefu_session SET agent_id = 0, status = 'waiting',
                     serving_mode = 'human', queue_start_at = NOW()
                     WHERE session_id = :s",
                    [':s' => $session['session_id']]
                );
                $session['agent_id'] = 0;
                $session['status'] = 'waiting';
                $needsReassign = true;
            }

            // 维护最后活跃时间，供 CronWorker 超时关闭判断使用
            Db::exec(
                "UPDATE kefu_session SET last_active_at = NOW() WHERE session_id = :s",
                [':s' => $session['session_id']]
            );
            // 如果会话已经 active 且客服可用，直接返回
            if ($session['status'] === 'active' && $session['agent_id']) {
                return $session;
            }
            // waiting 状态：如果需要重分配（刚清掉禁用客服），立即尝试分配
            if ($needsReassign || ($session['status'] === 'waiting' && empty($session['agent_id']))) {
                $aiEnabled = (new \app\service\AiConfigService())->isEnabled($tenantId);
                $newAgentId = 0;
                // AI 启用时直接走 AI；否则才尝试分配人工客服
                if (!$aiEnabled) {
                    $newAgentId = $this->autoAssignAgent($tenantId);
                }
                if ($newAgentId) {
                    Db::exec(
                        "UPDATE kefu_session SET agent_id = :aid, status = 'active',
                         serving_mode = 'human', assign_at = NOW(), queue_start_at = NULL
                         WHERE session_id = :s",
                        [':aid' => $newAgentId, ':s' => $session['session_id']]
                    );
                    $session['agent_id'] = $newAgentId;
                    $session['status'] = 'active';
                    $session['serving_mode'] = 'human';
                    // 通知客户端
                    try {
                        $agent = Db::find("SELECT real_name FROM kefu_employee WHERE id = :id", [':id' => $newAgentId]);
                        \app\lib\ConnectionManager::pushToSession($session['session_id'], [
                            'type' => 'assigned',
                            'session_id' => $session['session_id'],
                            'agent' => ['id' => $newAgentId, 'name' => $agent['real_name'] ?? ''],
                        ]);
                    } catch (\Throwable $e) {}
                    return $session;
                }
                // 没有可分配的人工客服：根据 AI 是否启用决定 serving_mode
                $newServingMode = $aiEnabled ? 'ai' : 'message';
                Db::exec(
                    "UPDATE kefu_session SET serving_mode = :m WHERE session_id = :s",
                    [':m' => $newServingMode, ':s' => $session['session_id']]
                );
                $session['serving_mode'] = $newServingMode;
            }
            return $session;
        }

        // 3. 没有就创建新会话
        $sessionId = 's_' . bin2hex(random_bytes(12));
        // 判断：是否启用 AI（智能体）—— 启用则默认先走 AI 模式
        $aiSvc = new \app\service\AiConfigService();
        $aiEnabled = $aiSvc->isEnabled($tenantId);
        $agentId = 0;
        if (!$aiEnabled) {
            // 仅在 AI 关时才直接自动分配人工
            $agentId = $this->autoAssignAgent($tenantId);
        }

        // 确定 serving_mode
        if ($aiEnabled) {
            // AI 开启：默认 AI 模式（访客发消息先走 AI，AI 回复满 max_ai_rounds 或访客转人工时再 handoffToHuman 分配客服 / 走留言）
            $servingMode = 'ai';
            $sessionStatus = 'waiting';
        } elseif ($agentId) {
            // AI 关 但 有客服在线：人工模式
            $servingMode = 'human';
            $sessionStatus = 'active';
        } else {
            // AI 关 + 无客服在线：留言模式
            $servingMode = 'message';
            $sessionStatus = 'waiting';
        }
        Db::insert('kefu_session', [
            'tenant_id'    => $tenantId,
            'session_id'   => $sessionId,
            'customer_id'  => $customerIdInternal,
            'channel'      => $channel,
            'agent_id'     => $agentId,
            'status'       => $sessionStatus,
            'session_type' => $servingMode === 'ai' ? 'ai' : 'human',
            'serving_mode' => $servingMode,
            'ai_round_count' => 0,
            'queue_start_at' => $agentId ? null : date('Y-m-d H:i:s'),
            'assign_at'    => $agentId ? date('Y-m-d H:i:s') : null,
            'last_active_at' => date('Y-m-d H:i:s'),
            'is_first'     => $this->isFirstVisit($tenantId, $customerIdInternal) ? 1 : 0,
        ]);

        // 如果转到留言模式（AI 关闭且无客服），写一条系统消息提示
        if ($servingMode === 'message') {
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

        return Db::find(
            "SELECT s.*, e.real_name AS agent_name
             FROM kefu_session s
             LEFT JOIN kefu_employee e ON e.id = s.agent_id
             WHERE s.session_id = :sid",
            [':sid' => $sessionId]
        );
    }

    /**
     * 分配客服（手动）
     * @param string $sessionId
     * @param int $employeeId
     * @param int $operatorEmployeeId 操作人（管理员或客服主管）
     * @return array
     */
    public function assignAgent($sessionId, $employeeId, $operatorEmployeeId) {
        $session = Db::find("SELECT * FROM kefu_session WHERE session_id = :s", [':s' => $sessionId]);
        if (!$session) {
            return ['code' => 404, 'msg' => '会话不存在'];
        }
        if (!in_array($session['status'], ['waiting', 'active'])) {
            return ['code' => 400, 'msg' => '会话已关闭，不能分配'];
        }

        $employee = Db::find("SELECT id, real_name FROM kefu_employee WHERE id = :id AND status = 1", [':id' => $employeeId]);
        if (!$employee) {
            return ['code' => 404, 'msg' => '客服不存在'];
        }

        $oldAgentId = $session['agent_id'];
        // 关键修复：接管时同时把 serving_mode 改成 human，否则后续访客消息仍走 AI 分支
        Db::exec(
            "UPDATE kefu_session
             SET agent_id = :aid, status = 'active', serving_mode = 'human',
                 assign_at = NOW(), queue_start_at = NULL, queue_position = 0,
                 last_active_at = NOW()
             WHERE session_id = :s",
            [':aid' => $employeeId, ':s' => $sessionId]
        );

        // 写历史
        Logger::info('session assigned', [
            'session_id' => $sessionId,
            'old_agent'  => $oldAgentId,
            'new_agent'  => $employeeId,
            'operator'   => $operatorEmployeeId,
        ]);

        // 推送通知
        ConnectionManager::pushToSession($sessionId, [
            'type' => 'assigned',
            'session_id' => $sessionId,
            'agent' => ['id' => $employeeId, 'name' => $employee['real_name']],
        ]);

        return ['code' => 0, 'msg' => 'ok', 'data' => ['session_id' => $sessionId, 'agent_id' => $employeeId]];
    }

    /**
     * 转接客会话
     * @param string $sessionId
     * @param int $toEmployeeId 接收方
     * @param string $reason 原因
     * @param int $operatorEmployeeId 操作人
     */
    public function transferSession($sessionId, $toEmployeeId, $reason, $operatorEmployeeId) {
        $session = Db::find("SELECT * FROM kefu_session WHERE session_id = :s", [':s' => $sessionId]);
        if (!$session) return ['code' => 404, 'msg' => '会话不存在'];
        if ($session['status'] !== 'active') {
            return ['code' => 400, 'msg' => '会话未激活'];
        }
        if ($session['agent_id'] == $toEmployeeId) {
            return ['code' => 400, 'msg' => '已经是该客服接待'];
        }

        $newAgent = Db::find("SELECT real_name, avatar FROM kefu_employee WHERE id = :id AND status = 1", [':id' => $toEmployeeId]);
        if (!$newAgent) return ['code' => 404, 'msg' => '目标客服不存在'];

        $oldAgentId = (int)$session['agent_id'];
        $tenantId = (int)$session['tenant_id'];

        Db::exec(
            "UPDATE kefu_session SET agent_id = :aid, assign_at = NOW() WHERE session_id = :s",
            [':aid' => $toEmployeeId, ':s' => $sessionId]
        );

        // 修复：写系统消息 + 转接记录（访客端能直观看到）
        try {
            Db::insert('kefu_message', [
                'tenant_id'   => $tenantId,
                'session_id'  => $sessionId,
                'sender_type' => 'system',
                'msg_type'    => 'text',
                'content'     => '客服已将本会话转接给 ' . ($newAgent['real_name'] ?? '其他客服') . '（' . $reason . '）',
                'status'      => 'delivered',
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {}

        ConnectionManager::pushToSession($sessionId, [
            'type' => 'transferred',
            'session_id' => $sessionId,
            'to_agent' => ['id' => $toEmployeeId, 'name' => $newAgent['real_name'], 'avatar' => $newAgent['avatar'] ?? ''],
            'reason' => $reason,
        ]);

        Logger::info('session transferred', [
            'session_id' => $sessionId,
            'from'       => $oldAgentId,
            'to'         => $toEmployeeId,
            'reason'     => $reason,
            'operator'   => $operatorEmployeeId,
        ]);

        return ['code' => 0, 'msg' => 'ok', 'data' => ['to_agent' => $newAgent['real_name']]];
    }

    /**
     * 关闭会话
     * @param string $sessionId
     * @param string $reason resolved/timeout/customer_leave/agent_leave/transfer_ticket
     * @param int $operatorEmployeeId 操作人
     */
    public function closeSession($sessionId, $reason, $operatorEmployeeId) {
        $session = Db::find("SELECT * FROM kefu_session WHERE session_id = :s", [':s' => $sessionId]);
        if (!$session) return ['code' => 404, 'msg' => '会话不存在'];
        if ($session['status'] === 'closed') {
            return ['code' => 400, 'msg' => '会话已关闭'];
        }
        if (!in_array($reason, ['resolved', 'timeout', 'customer_leave', 'agent_leave', 'transfer_ticket'])) {
            $reason = 'resolved';
        }

        // 计算平均响应时长 & 总时长
        $avgRt = Db::value(
            "SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, ack_at))
             FROM kefu_message
             WHERE session_id = :s AND sender_type = 'agent' AND ack_at IS NOT NULL",
            [':s' => $sessionId]
        );
        $duration = (int)Db::value(
            "SELECT TIMESTAMPDIFF(SECOND, created_at, IFNULL(first_response_at, NOW())) FROM kefu_session WHERE session_id = :s",
            [':s' => $sessionId]
        );

        $msgCount = Db::value("SELECT message_count FROM kefu_session WHERE session_id = :s", [':s' => $sessionId]);
        $customerMsgCount = (int)Db::value(
            "SELECT COUNT(*) FROM kefu_message WHERE session_id = :s AND sender_type = 'customer'",
            [':s' => $sessionId]
        );
        $agentMsgCount = (int)Db::value(
            "SELECT COUNT(*) FROM kefu_message WHERE session_id = :s AND sender_type = 'agent'",
            [':s' => $sessionId]
        );

        Db::exec(
            "UPDATE kefu_session
             SET status = 'closed', closed_at = NOW(), close_reason = :r,
                 avg_response_time = :rt, duration = :dur,
                 message_count = :mc, customer_msg_count = :cc, agent_msg_count = :ac
             WHERE session_id = :s",
            [
                ':r'  => $reason,
                ':rt' => (int)$avgRt,
                ':dur'=> $duration,
                ':mc' => (int)$msgCount,
                ':cc' => $customerMsgCount,
                ':ac' => $agentMsgCount,
                ':s'  => $sessionId,
            ]
        );

        ConnectionManager::pushToSession($sessionId, [
            'type' => 'session_closed',
            'session_id' => $sessionId,
            'reason' => $reason,
        ]);

        Logger::info('session closed', [
            'session_id' => $sessionId,
            'reason'     => $reason,
            'duration'   => $duration,
            'operator'   => $operatorEmployeeId,
        ]);

        return ['code' => 0, 'msg' => 'ok'];
    }

    /**
     * 会话列表（分页）
     */
    public function listSessions($tenantId, $employeeId, $params = []) {
        $status = $params['status'] ?? '';
        $page = max(1, intval($params['page'] ?? 1));
        $size = min(100, max(10, intval($params['size'] ?? 20)));
        $offset = ($page - 1) * $size;

        $where = "WHERE s.tenant_id = :t";
        $bind = [':t' => $tenantId];

        // 关键修复：过滤掉被禁用（status=0）的客服所关联的"已关闭"会话，
        // 避免在会话列表里出现"挂在一个不存在的客服名下"的历史会话。
        // 但【active/waiting】状态的会话必须保留——客服被禁用时不能丢会话（避免服务中断）。
        // agent_id = 0 表示无人接管（队列等待中），保留。
        $where .= " AND (s.agent_id = 0 OR e.id IS NOT NULL OR s.status IN ('active','waiting'))";

        // 修复：AI-only 会话（serving_mode='ai' 且 agent_id=0）不进客服在线列表，
        // 避免大量 AI 机器人会话污染客服工作台。访客触发转人工后，会变成 serving_mode='human' 或 agent_id>0，自动出现在列表。
        $where .= " AND NOT (s.serving_mode = 'ai' AND (s.agent_id IS NULL OR s.agent_id = 0))";

        // 客服默认只看自己
        if (!empty($params['mine_only']) && $employeeId > 0) {
            $where .= " AND s.agent_id = :aid";
            $bind[':aid'] = $employeeId;
        }
        if ($status !== '') {
            $where .= " AND s.status = :st";
            $bind[':st'] = $status;
        } else {
            // 不指定 status 时，默认只显示进行中的会话（active / waiting），
            // 已关闭的会话请从 /api/agent/history/sessions 查询
            $where .= " AND s.status IN ('active', 'waiting')";
        }
        if (!empty($params['channel'])) {
            $where .= " AND s.channel = :ch";
            $bind[':ch'] = $params['channel'];
        }

        $total = Db::value("SELECT COUNT(*) FROM kefu_session s
                            LEFT JOIN kefu_employee e ON e.id = s.agent_id
                            $where", $bind);
        $bind[':limit'] = (int)$size;
        $bind[':offset'] = (int)$offset;

        $rows = Db::query(
            "SELECT s.*, c.nickname AS customer_name, c.avatar AS customer_avatar,
                    e.real_name AS agent_name
             FROM kefu_session s
             LEFT JOIN kefu_customer c ON c.id = s.customer_id
             LEFT JOIN kefu_employee e ON e.id = s.agent_id
             $where
             ORDER BY s.id DESC
             LIMIT :limit OFFSET :offset",
            $bind
        );

        return ['list' => $rows, 'total' => (int)$total, 'page' => $page, 'size' => $size];
    }

    /**
     * 历史会话列表（已关闭、超时、被踢出的）
     * - 默认只显示当前客服历史接管过的会话
     * - mine_only=0 时显示全租户（用于超管/主管审查）
     * - 支持按关键字、客户名、日期、渠道筛选
     */
    public function listHistorySessions($tenantId, $employeeId, $params = []) {
        $page = max(1, intval($params['page'] ?? 1));
        $size = min(100, max(10, intval($params['size'] ?? 20)));
        $offset = ($page - 1) * $size;

        $where = "WHERE s.tenant_id = :t AND s.status = 'closed'";
        $bind = [':t' => $tenantId];
        // 同样过滤掉被禁用/已删除客服的历史会话
        $where .= " AND (s.agent_id = 0 OR e.id IS NOT NULL)";
        if (!empty($params['mine_only']) && $employeeId > 0) {
            $where .= " AND s.agent_id = :aid";
            $bind[':aid'] = $employeeId;
        }
        if (!empty($params['q'])) {
            $where .= " AND (s.session_id LIKE :q OR c.nickname LIKE :q OR c.customer_id LIKE :q)";
            $bind[':q'] = '%' . $params['q'] . '%';
        }
        if (!empty($params['from'])) {
            $where .= " AND s.closed_at >= :from";
            $bind[':from'] = $params['from'] . ' 00:00:00';
        }
        if (!empty($params['to'])) {
            $where .= " AND s.closed_at <= :to";
            $bind[':to'] = $params['to'] . ' 23:59:59';
        }
        if (!empty($params['channel'])) {
            $where .= " AND s.channel = :ch";
            $bind[':ch'] = $params['channel'];
        }
        if (!empty($params['reopenable'])) {
            // 可重新接管：超时关闭的、被踢出的（关闭原因不是 resolved）
            $where .= " AND s.close_reason IN ('timeout', 'customer_leave', 'agent_leave', 'no_response')";
        }

        $total = Db::value("SELECT COUNT(*) FROM kefu_session s
                            LEFT JOIN kefu_customer c ON c.id = s.customer_id
                            LEFT JOIN kefu_employee e ON e.id = s.agent_id
                            $where", $bind);
        $bind[':limit'] = (int)$size;
        $bind[':offset'] = (int)$offset;

        $rows = Db::query(
            "SELECT s.id, s.session_id, s.customer_id, s.agent_id, s.channel, s.status,
                    s.serving_mode, s.close_reason, s.created_at, s.closed_at,
                    s.message_count, s.duration, s.avg_response_time, s.first_response_at,
                    s.handoff_reason,
                    c.nickname AS customer_name, c.avatar AS customer_avatar, c.customer_id AS customer_external_id,
                    e.real_name AS agent_name, e.employee_no AS agent_no,
                    -- 是否可重新接管：最近 N 天内且状态非正常完结
                    CASE
                        WHEN s.close_reason IN ('resolved','admin_clear') THEN 0
                        WHEN s.closed_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 0
                        ELSE 1
                    END AS can_reopen
             FROM kefu_session s
             LEFT JOIN kefu_customer c ON c.id = s.customer_id
             LEFT JOIN kefu_employee e ON e.id = s.agent_id
             $where
             ORDER BY s.closed_at DESC
             LIMIT :limit OFFSET :offset",
            $bind
        );

        return ['list' => $rows, 'total' => (int)$total, 'page' => $page, 'size' => $size];
    }

    /**
     * 重新接管历史会话
     * - 把 closed 状态切回 active
     * - agent_id 设回当前客服
     * - serving_mode 设回 human
     * - 可继续对话；记录 reopen_count
     */
    public function reopenSession($tenantId, $sessionId, $operatorId) {
        Db::setTenantId($tenantId);
        $session = Db::find("SELECT * FROM kefu_session WHERE session_id = :s", [':s' => $sessionId]);
        if (!$session) return ['code' => 404, 'msg' => '会话不存在'];
        if ($session['status'] !== 'closed') {
            return ['code' => 400, 'msg' => '只能重新接管已关闭的会话'];
        }
        // 已完结 (resolved) 或管理清理的不允许重新接管
        if (in_array($session['close_reason'], ['resolved', 'admin_clear'], true)) {
            return ['code' => 400, 'msg' => '已完结的会话无法重新接管'];
        }
        // 关闭超过 7 天的会话也不允许（避免历史包袱）
        if (strtotime($session['closed_at']) < strtotime('-7 days')) {
            return ['code' => 400, 'msg' => '会话关闭超过 7 天，无法重新接管'];
        }

        try {
            Db::exec(
                "UPDATE kefu_session
                 SET status = 'active',
                     agent_id = :aid,
                     serving_mode = 'human',
                     closed_at = NULL,
                     close_reason = NULL,
                     reopen_count = IFNULL(reopen_count, 0) + 1,
                     last_active_at = NOW(),
                     assign_at = NOW()
                 WHERE session_id = :s",
                [':aid' => $operatorId, ':s' => $sessionId]
            );
        } catch (\Throwable $e) {
            return ['code' => 500, 'msg' => '接管失败：' . $e->getMessage()];
        }

        // 写系统消息
        try {
            Db::insert('kefu_message', [
                'tenant_id' => $tenantId,
                'session_id' => $sessionId,
                'customer_id' => $session['customer_id'] ?? 0,
                'sender_type' => 'system',
                'msg_type' => 'text',
                'content' => '客服重新接入了此会话',
                'status' => 'delivered',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {}

        // 推送通知
        $agent = Db::find("SELECT id, real_name, employee_no FROM kefu_employee WHERE id = :i", [':i' => $operatorId]);
        ConnectionManager::pushToSession($sessionId, [
            'type' => 'session_reopened',
            'session_id' => $sessionId,
            'agent' => $agent ?: ['id' => $operatorId, 'name' => '客服'],
        ]);

        return ['code' => 0, 'msg' => '已重新接管', 'data' => ['session_id' => $sessionId, 'agent_id' => $operatorId]];
    }

    /**
     * 扫描超时未回应的会话，自动关闭
     * @param int $tenantId
     * @param int $timeoutMinutes 客户无操作超过 N 分钟自动关闭
     * @param int $agentTimeoutMinutes 客服无操作超过 N 分钟自动关闭
     * @return array 关闭的会话数
     */
    public function autoCloseTimeoutSessions($tenantId, $timeoutMinutes = 30, $agentTimeoutMinutes = 60) {
        Db::setTenantId($tenantId);
        $closed = 0;
        // 修复：PDO 不支持同名占位符重复，直接拼接（已 intval 转 int 防 SQL 注入）
        $tMin = intval($timeoutMinutes);
        $aMin = intval($agentTimeoutMinutes);
        // 1. 客户超时
        try {
            $sql = "SELECT session_id, customer_id, serving_mode FROM kefu_session
                    WHERE tenant_id = :t
                      AND status IN ('active', 'waiting')
                      AND (
                          (last_customer_at IS NOT NULL
                            AND last_customer_at < DATE_SUB(NOW(), INTERVAL $tMin MINUTE))
                          OR (last_customer_at IS NULL
                            AND created_at < DATE_SUB(NOW(), INTERVAL $tMin MINUTE))
                      )";
            $rows = Db::query($sql, [':t' => $tenantId]);
            foreach ($rows as $r) {
                Db::exec(
                    "UPDATE kefu_session
                     SET status = 'closed', close_reason = 'customer_timeout', closed_at = NOW()
                     WHERE session_id = :s AND status IN ('active', 'waiting')",
                    [':s' => $r['session_id']]
                );
                try {
                    Db::insert('kefu_message', [
                        'tenant_id' => $tenantId,
                        'session_id' => $r['session_id'],
                        'customer_id' => $r['customer_id'] ?: 0,
                        'sender_type' => 'system',
                        'msg_type' => 'text',
                        'content' => '由于客户长时间无操作，会话已自动关闭。客服可从"历史会话"重新接管。',
                        'status' => 'delivered',
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                } catch (\Throwable $e) {}
                $closed++;
            }
        } catch (\Throwable $e) {
            Logger::error('autoCloseTimeoutSessions (customer) failed', ['err' => $e->getMessage()]);
        }
        // 2. 客服超时
        try {
            $sql2 = "SELECT session_id, customer_id FROM kefu_session
                    WHERE tenant_id = :t
                      AND status IN ('active', 'waiting')
                      AND serving_mode = 'human'
                      AND agent_id IS NOT NULL
                      AND last_agent_at IS NOT NULL
                      AND last_agent_at < DATE_SUB(NOW(), INTERVAL $aMin MINUTE)";
            $rows = Db::query($sql2, [':t' => $tenantId]);
            foreach ($rows as $r) {
                Db::exec(
                    "UPDATE kefu_session
                     SET status = 'closed', close_reason = 'agent_timeout', closed_at = NOW()
                     WHERE session_id = :s AND status IN ('active', 'waiting')",
                    [':s' => $r['session_id']]
                );
                try {
                    Db::insert('kefu_message', [
                        'tenant_id' => $tenantId,
                        'session_id' => $r['session_id'],
                        'customer_id' => $r['customer_id'] ?: 0,
                        'sender_type' => 'system',
                        'msg_type' => 'text',
                        'content' => '由于客服长时间未回复，会话已自动关闭。可从历史会话重新接管。',
                        'status' => 'delivered',
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                } catch (\Throwable $e) {}
                $closed++;
            }
        } catch (\Throwable $e) {
            Logger::error('autoCloseTimeoutSessions (agent) failed', ['err' => $e->getMessage()]);
        }
        return ['closed' => $closed];
    }

    /**
     * 创建/获取访客记录
     */
    private function upsertCustomer($tenantId, $customerId, $channel) {
        $customer = Db::find(
            "SELECT id FROM kefu_customer WHERE tenant_id = :t AND customer_id = :c",
            [':t' => $tenantId, ':c' => $customerId]
        );
        if ($customer) {
            Db::exec(
                "UPDATE kefu_customer SET last_active_time = NOW() WHERE id = :id",
                [':id' => $customer['id']]
            );
            return $customer['id'];
        }
        return Db::insert('kefu_customer', [
            'tenant_id'   => $tenantId,
            'customer_id' => $customerId,
            'channel'     => $channel,
            'register_time' => date('Y-m-d H:i:s'),
            'last_active_time' => date('Y-m-d H:i:s'),
            'profile' => '{}',
        ]);
    }

    /**
     * 由 AI 转人工（handoff）：
     *   - 复用 autoAssignAgent 选客服
     *   - 找到客服：更新 session.agent_id, serving_mode='human', handoff_at, handoff_reason
     *   - 没找到客服：转留言模式 serving_mode='message'，并写系统提示消息
     *   - 返回 agent 信息（供推送通知）
     */
    public function handoffToHuman($tenantId, $sessionId, $reason = '') {
        Db::setTenantId($tenantId);
        $session = Db::find("SELECT * FROM kefu_session WHERE session_id = :s AND tenant_id = :t", [':s' => $sessionId, ':t' => $tenantId]);
        if (!$session) {
            return ['code' => 404, 'msg' => '会话不存在'];
        }
        // 已经被分配过？不重复分配
        $reusingAgent = intval($session['agent_id'] ?? 0) > 0;

        $agent = null;
        if (!$reusingAgent) {
            $row = $this->autoAssignAgentRow($tenantId);
            $agent = $row ?: null;
        } else {
            // 已分配过：验证客服是否还可用（启用 + 未超载 + 在线）
            $existing = Db::find(
                "SELECT e.id, e.real_name, e.max_sessions,
                        (SELECT COUNT(*) FROM kefu_session s
                         WHERE s.tenant_id = e.tenant_id AND s.agent_id = e.id AND s.status='active'
                           AND s.serving_mode = 'human') AS load_count,
                        e.work_status, e.status
                 FROM kefu_employee e WHERE e.id = :id",
                [':id' => intval($session['agent_id'])]
            );
            if ($existing && $existing['status'] == 1
                && ($existing['work_status'] == 'online' || $existing['work_status'] === null)
                && intval($existing['load_count']) < intval($existing['max_sessions'])) {
                $agent = $existing;
            } else {
                // 之前的客服已不可用，重新分配
                $row = $this->autoAssignAgentRow($tenantId);
                $agent = $row ?: null;
            }
        }

        $newAgentId = $agent ? intval($agent['id']) : 0;
        if (!$newAgentId) {
            // 没客服 / 都不在线 / 全部满载 → 转留言模式
            $this->transferToMessage($tenantId, $sessionId, $reason ?: 'all_agents_busy');
            return [
                'code' => 0,
                'agent' => null,
                'serving_mode' => 'message',
                'message' => '当前所有客服都不在线或正在忙碌，已自动切换到留言模式，请留下您的留言',
            ];
        }

        Db::exec(
            "UPDATE kefu_session SET agent_id = :aid, status = 'active', serving_mode = 'human',
             handoff_at = NOW(), handoff_reason = :r, assign_at = IFNULL(assign_at, NOW())
             WHERE session_id = :s",
            [':aid' => $newAgentId, ':r' => $reason, ':s' => $sessionId]
        );

        return [
            'code' => 0,
            'agent' => ['id' => $newAgentId, 'name' => $agent['real_name'] ?? null],
            'serving_mode' => 'human',
            'message' => '已为您接入客服 ' . ($agent['real_name'] ?? $newAgentId),
        ];
    }

    /**
     * 与 autoAssignAgent 类似，但返回完整 row 而不只 id
     * 分配规则（关键）：
     *   - 客服 status=1（启用）
     *   - 客服 role_id >= 3（不分配给超管/主管，避免普通访客骚扰）
     *   - 客服 work_status='online'（客服必须明确处于"在线"状态——离开/忙碌/离线都不分配）
     *   - 客服当前负载 < max_sessions（同时接待上限）
     *   - WS 兜底：客服 WS 心跳在 30 秒内被视作"实际在线"，仍需 work_status='online'
     *   - 多个候选时：按负载（少→多）、技能等级（高→低）、最近登录（新→旧）排序
     */
    private function autoAssignAgentRow($tenantId) {
        // 主查询：DB 上明确 online + 未满负载 + 非超管
        $row = Db::find(
            "SELECT e.id, e.real_name, e.max_sessions,
                    IFNULL(lo.load_count, 0) AS load_count
             FROM kefu_employee e
             LEFT JOIN (
                 SELECT agent_id, COUNT(*) AS load_count
                 FROM kefu_session
                 WHERE status = 'active' AND serving_mode = 'human' -- 修复：只算人工会话
                 GROUP BY agent_id
             ) lo ON lo.agent_id = e.id
             WHERE e.tenant_id = :t AND e.status = 1 AND e.role_id >= 3
               AND e.work_status = 'online'
               AND IFNULL(lo.load_count, 0) < e.max_sessions
             ORDER BY IFNULL(lo.load_count, 0) ASC, e.skill_level DESC, e.last_login_at DESC
             LIMIT 1",
            [':t' => $tenantId]
        );
        if ($row) return $row;

        // 兜底 1：DB 上显示 away/busy 但实际在线（WS 心跳 < 30s），仍视为不可分配
        //      —— 离开/忙碌的客服不应被分配新会话
        // 兜底 2：客户端 work_status 字段可能因网络抖动短暂不是 'online'，但 WS 活跃
        //      —— 此时不分配（避免给"离开"状态的人发会话）
        //      这种情况下系统会走 transferToMessage 进入留言模式
        $onlineFileDir = runtime_path() . '/push/online';
        if (!is_dir($onlineFileDir)) return null;
        // 只在 work_status='online' 的客服里查找 WS 在线的人（双重确认）
        $candidates = Db::query(
            "SELECT e.id, e.real_name, e.max_sessions,
                    IFNULL(lo.load_count, 0) AS load_count
             FROM kefu_employee e
             LEFT JOIN (
                 SELECT agent_id, COUNT(*) AS load_count
                 FROM kefu_session
                 WHERE status = 'active' AND serving_mode = 'human' -- 修复：只算人工会话，不算 AI
                 GROUP BY agent_id
             ) lo ON lo.agent_id = e.id
             WHERE e.tenant_id = :t AND e.status = 1 AND e.role_id >= 3
               AND e.work_status = 'online'
               AND IFNULL(lo.load_count, 0) < e.max_sessions
             ORDER BY IFNULL(lo.load_count, 0) ASC, e.skill_level DESC, e.last_login_at DESC",
            [':t' => $tenantId]
        );
        foreach ($candidates as $c) {
            $file = $onlineFileDir . '/agent_' . $tenantId . '_' . $c['id'] . '.json';
            if (!is_file($file)) continue;
            $meta = @json_decode(@file_get_contents($file), true);
            // WS 心跳 30 秒内的客服才视为真正活跃
            $lastActive = isset($meta['last_active']) ? (int)$meta['last_active'] : 0;
            if ($lastActive > 0 && (time() - $lastActive) > 30) continue;
            return $c;
        }
        // 没有可分配的在线客服
        return null;
    }

    /**
     * 自动分配客服：找最闲的客服（max_sessions 没排满的 + 离线状态除外）
     * @return int agent_id，0 表示无客服可分配
     */
    private function autoAssignAgent($tenantId) {
        $row = $this->autoAssignAgentRow($tenantId);
        return $row ? intval($row['id']) : 0;
    }

    /**
     * 转留言：当所有客服都不在线 / 已满载时调用
     */
    public function transferToMessage($tenantId, $sessionId, $reason = 'all_agents_busy') {
        Db::setTenantId($tenantId);
        Db::exec(
            "UPDATE kefu_session SET serving_mode = 'message', handoff_reason = :r,
             handoff_at = NOW(), status = 'waiting' WHERE session_id = :s",
            [':r' => $reason, ':s' => $sessionId]
        );
        // 写留言提示消息
        $now = date('Y-m-d H:i:s');
        try {
            Db::insert('kefu_message', [
                'tenant_id' => $tenantId,
                'session_id' => $sessionId,
                'customer_id' => 0,
                'sender_type' => 'system',
                'msg_type' => 'text',
                'content' => '当前所有客服都不在线或正在忙碌，请留下您的留言，客服上线后会尽快联系您。',
                'status' => 'delivered',
                'created_at' => $now,
            ]);
        } catch (\Throwable $e) {}
        return ['code' => 0, 'msg' => '已转留言', 'data' => ['serving_mode' => 'message']];
    }

    /**
     * 是否新访客
     */
    private function isFirstVisit($tenantId, $customerIdInternal) {
        $count = Db::value(
            "SELECT COUNT(*) FROM kefu_session WHERE customer_id = :c",
            [':c' => $customerIdInternal]
        );
        return $count <= 1;
    }
}