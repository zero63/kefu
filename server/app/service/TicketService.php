<?php
/**
 * 工单业务服务
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 *
 * 工单核心流程：
 *   1. 创建工单（可来自会话、一键升级、主动填写）
 *   2. 工单分配（手动/自动）
 *   3. 内部协作（备注、回复）
 *   4. SLA 监控
 *   5. 关闭/完成
 *   6. 评价
 *
 * 状态机：
 *   pending → assigned → in_progress → replied → resolved → closed → reopened...
 */

namespace app\service;

use app\lib\Db;
use app\lib\Logger;
use app\lib\ConnectionManager;

class TicketService
{
    /**
     * 创建工单
     * @param int $tenantId
     * @param array $params {customer_id, session_id?, title, content, category, priority, custom_fields?}
     * @param int $creatorId 创建人（客服 id 或 0 表示客户自建）
     * @return array 形如 ['code'=>0, 'msg'=>'ok', 'data'=>['ticket_id'=>..., 'ticket_no'=>...]]
     */
    public function create($tenantId, $params, $creatorId) {
        if (!is_array($params)) {
            return ['code' => 400, 'msg' => '参数错误'];
        }
        $customerId = intval($params['customer_id'] ?? 0);
        $title = trim($params['title'] ?? '');
        $content = trim($params['content'] ?? '');
        $category = trim($params['category'] ?? '咨询');
        $priority = min(5, max(1, intval($params['priority'] ?? 2)));
        $sessionId = trim($params['session_id'] ?? '');
        $customFields = $params['custom_fields'] ?? [];

        // 修复：允许 customer_id=0 创建（演示/手动工单场景），后端自动按 customer_name 在 kefu_customer 匹配或创建
        $customerName = trim($params['customer_name'] ?? '');
        $customerPhone = trim($params['customer_phone'] ?? '');
        if ($customerId <= 0 && $customerName !== '') {
            // 尝试按客户名匹配已有客户
            $exist = Db::value("SELECT id FROM kefu_customer WHERE tenant_id = :t AND nickname = :n LIMIT 1",
                [':t' => $tenantId, ':n' => $customerName]);
            if ($exist > 0) {
                $customerId = intval($exist);
            } else {
                // 演示场景自动建档（工单需要 customer_id NOT NULL）
                $customerId = Db::insert('kefu_customer', [
                    'tenant_id'     => $tenantId,
                    'customer_id'   => 'cust_' . substr(md5($customerName . $tenantId . time()), 0, 10),
                    'nickname'      => $customerName,
                    'phone'         => $customerPhone,
                    'channel'       => 'manual',
                    'register_time' => date('Y-m-d H:i:s'),
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
            }
        }
        if ($customerId <= 0) {
            return ['code' => 400, 'msg' => '客户信息无效，请填写客户姓名或选择已有客户'];
        }
        if ($title === '' || $content === '') {
            return ['code' => 400, 'msg' => 'title / content 必填'];
        }

        Db::setTenantId($tenantId);

        $ticketNo = $this->generateTicketNo($tenantId);
        $id = Db::insert('kefu_ticket', [
            'tenant_id'   => $tenantId,
            'ticket_no'   => $ticketNo,
            'customer_id' => $customerId,
            'session_id'  => $sessionId,
            'title'       => $title,
            'content'     => $content,
            'category'    => $category,
            'priority'    => $priority,
            'status'      => 'pending',
            'sla_response_time' => $this->slaResponseTime($priority),
            'sla_resolve_time'  => $this->slaResolveTime($priority),
            'custom_fields' => json_encode($customFields, JSON_UNESCAPED_UNICODE),
            'created_by'  => $creatorId,
        ]);

        $this->log($tenantId, $id, $creatorId, 'create', "创建工单：$title");

        // 通知所有管理员（管理后台 WS）
        ConnectionManager::pushToRole('admin', [
            'type' => 'new_ticket',
            'ticket_id' => $id,
            'ticket_no' => $ticketNo,
            'title' => $title,
            'priority' => $priority,
        ]);

        return ['code' => 0, 'msg' => 'ok', 'data' => [
            'ticket_id' => $id,
            'ticket_no' => $ticketNo,
        ]];
    }

    /**
     * 一键升级（从会话升级为工单）
     */
    public function createFromSession($tenantId, $sessionId, $employeeId, $reason = '问题复杂升级处理') {
        Db::setTenantId($tenantId);
        $session = Db::find(
            "SELECT s.*, c.nickname, c.customer_id AS ext_customer_id
             FROM kefu_session s LEFT JOIN kefu_customer c ON c.id = s.customer_id
             WHERE s.session_id = :s",
            [':s' => $sessionId]
        );
        if (!$session) return ['code' => 404, 'msg' => '会话不存在'];

        $msgs = Db::query(
            "SELECT content, sender_type FROM kefu_message WHERE session_id = :s ORDER BY id ASC LIMIT 5",
            [':s' => $sessionId]
        );

        $firstCustomer = '';
        foreach ($msgs as $m) {
            if ($m['sender_type'] === 'customer') {
                $firstCustomer = mb_substr($m['content'], 0, 200);
                break;
            }
        }

        return $this->create($tenantId, [
            'customer_id' => $session['customer_id'],
            'session_id'  => $sessionId,
            'title'       => "会话升级：$reason",
            'content'     => $firstCustomer ?: $reason,
            'category'    => '会话升级',
            'priority'    => 3,
        ], $employeeId);
    }

    /**
     * 分配工单
     */
    public function assign($tenantId, $ticketId, $employeeId, $operatorId) {
        Db::setTenantId($tenantId);
        $ticket = Db::find("SELECT * FROM kefu_ticket WHERE id = :id", [':id' => $ticketId]);
        if (!$ticket) return ['code' => 404, 'msg' => '工单不存在'];
        if (!in_array($ticket['status'], ['pending', 'assigned', 'reopened'])) {
            return ['code' => 400, 'msg' => '工单状态不允许重新分配'];
        }

        $employee = Db::find("SELECT id, real_name FROM kefu_employee WHERE id = :id AND status = 1", [':id' => $employeeId]);
        if (!$employee) return ['code' => 404, 'msg' => '客服不存在'];

        Db::exec(
            "UPDATE kefu_ticket SET assigned_to = :a, status = 'assigned' WHERE id = :id",
            [':a' => $employeeId, ':id' => $ticketId]
        );

        $this->log($tenantId, $ticketId, $operatorId, 'assign', '分配给：' . $employee['real_name']);

        return ['code' => 0, 'msg' => 'ok', 'data' => ['ticket_id' => $ticketId, 'assigned_to' => $employeeId]];
    }

    /**
     * 工单回复（追加到 log 表 + remark 字段）
     */
    public function reply($tenantId, $ticketId, $replyContent, $replierId, $isInternal = false) {
        $replyContent = trim($replyContent);
        if ($replyContent === '') return ['code' => 400, 'msg' => '回复内容为空'];

        Db::setTenantId($tenantId);
        $ticket = Db::find("SELECT * FROM kefu_ticket WHERE id = :id", [':id' => $ticketId]);
        if (!$ticket) return ['code' => 404, 'msg' => '工单不存在'];
        if (in_array($ticket['status'], ['closed'])) {
            return ['code' => 400, 'msg' => '工单已关闭，不能回复'];
        }

        $now = date('Y-m-d H:i:s');
        $newStatus = $ticket['status'] === 'pending' ? 'assigned' : $ticket['status'];

        $this->log($tenantId, $ticketId, $replierId, $isInternal ? 'note' : 'reply', mb_substr($replyContent, 0, 200));

        Db::exec(
            "UPDATE kefu_ticket
             SET status = ?,
                 updated_at = NOW(),
                 first_response_at = IFNULL(first_response_at, ?)
             WHERE id = ?",
            [$newStatus, $now, $ticketId]
        );

        return ['code' => 0, 'msg' => 'ok', 'data' => ['ticket_id' => $ticketId, 'status' => $newStatus]];
    }

    /**
     * 解决工单
     */
    public function resolve($tenantId, $ticketId, $operatorId, $solution) {
        Db::setTenantId($tenantId);
        $ticket = Db::find("SELECT * FROM kefu_ticket WHERE id = :id", [':id' => $ticketId]);
        if (!$ticket) return ['code' => 404, 'msg' => '工单不存在'];

        if (!empty($solution)) {
            $this->log($tenantId, $ticketId, $operatorId, 'resolve', mb_substr($solution, 0, 200));
        }

        Db::exec(
            "UPDATE kefu_ticket
             SET status = ?, updated_at = NOW()
             WHERE id = ?",
            ['resolved', $ticketId]
        );

        return ['code' => 0, 'msg' => 'ok'];
    }

    /**
     * 关闭工单
     */
    public function close($tenantId, $ticketId, $operatorId, $reason) {
        Db::setTenantId($tenantId);
        Db::exec(
            "UPDATE kefu_ticket
             SET status = 'closed', closed_at = NOW(), updated_at = NOW()
             WHERE id = :id AND status IN ('resolved', 'pending', 'assigned', 'in_progress', 'replied', 'reopened')",
            [':id' => $ticketId]
        );
        $this->log($tenantId, $ticketId, $operatorId, 'close', $reason);
        return ['code' => 0, 'msg' => 'ok'];
    }

    /**
     * 重新打开工单
     */
    public function reopen($tenantId, $ticketId, $operatorId, $reason) {
        Db::setTenantId($tenantId);
        $r = Db::exec(
            "UPDATE kefu_ticket SET status='reopened', updated_at=NOW() WHERE id=:id AND status IN ('resolved','closed')",
            [':id' => $ticketId]
        );
        if ($r === 0) return ['code' => 400, 'msg' => '工单状态不允许重开'];
        $this->log($tenantId, $ticketId, $operatorId, 'reopen', $reason);
        return ['code' => 0, 'msg' => 'ok'];
    }

    /**
     * 工单列表
     */
    public function listTickets($tenantId, $params = []) {
        $page = max(1, intval($params['page'] ?? 1));
        $size = min(100, max(10, intval($params['size'] ?? 20)));
        $offset = ($page - 1) * $size;

        Db::setTenantId($tenantId);

        // 修复：多表 JOIN 时，列必须加 t/c/e 前缀（否则 tenant_id 报 1052 ambiguous）
        $where = 'WHERE t.tenant_id = :t';
        $bind = [':t' => $tenantId];
        if (!empty($params['status'])) {
            $where .= ' AND t.status = :st';
            $bind[':st'] = $params['status'];
        }
        if (!empty($params['priority'])) {
            $where .= ' AND t.priority = :pr';
            $bind[':pr'] = intval($params['priority']);
        }
        if (!empty($params['assigned_to'])) {
            $where .= ' AND t.assigned_to = :a';
            $bind[':a'] = intval($params['assigned_to']);
        }
        if (!empty($params['category'])) {
            $where .= ' AND t.category = :c';
            $bind[':c'] = $params['category'];
        }
        if (!empty($params['keyword'])) {
            $where .= ' AND (t.title LIKE :k OR t.content LIKE :k OR t.ticket_no LIKE :k)';
            $bind[':k'] = '%' . $params['keyword'] . '%';
        }

        $total = Db::value("SELECT COUNT(*) FROM kefu_ticket t $where", $bind);
        $bind[':limit'] = $size;
        $bind[':offset'] = $offset;

        $rows = Db::query(
            "SELECT t.*, c.customer_id AS cust_id, c.nickname AS customer_name, e.real_name AS assigned_name
             FROM kefu_ticket t
             LEFT JOIN kefu_customer c ON c.id = t.customer_id
             LEFT JOIN kefu_employee e ON e.id = t.assigned_to
             $where
             ORDER BY t.priority ASC, t.id DESC
             LIMIT :limit OFFSET :offset",
            $bind
        );

        return ['list' => $rows, 'total' => (int)$total, 'page' => $page, 'size' => $size];
    }

    /**
     * 工单详情（含回复/日志）
     */
    public function detail($tenantId, $ticketId) {
        Db::setTenantId($tenantId);
        $row = Db::find(
            "SELECT t.*, c.customer_id AS cust_id, c.nickname AS customer_name, c.phone AS customer_phone,
                    e.real_name AS assigned_name
             FROM kefu_ticket t
             LEFT JOIN kefu_customer c ON c.id = t.customer_id
             LEFT JOIN kefu_employee e ON e.id = t.assigned_to
             WHERE t.id = :id",
            [':id' => $ticketId]
        );
        if (!$row) return null;

        $logs = Db::query(
            "SELECT id, operator_id, action, from_status, to_status, remark, created_at FROM kefu_ticket_log
             WHERE ticket_id = :t ORDER BY id ASC",
            [':t' => $ticketId]
        );

        $row['replies'] = $logs;
        $row['logs']    = $logs;
        return $row;
    }

    /**
     * 生成工单号
     */
    private function generateTicketNo($tenantId) {
        return 'T' . date('Ymd') . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * SLA 响应时间（按优先级，单位：分钟）
     */
    private function slaResponseTime($priority) {
        $map = [1 => 60, 2 => 30, 3 => 10, 4 => 5, 5 => 1];
        return $map[$priority] ?? 30;
    }
    private function slaResolveTime($priority) {
        $map = [1 => 72*60, 2 => 48*60, 3 => 24*60, 4 => 8*60, 5 => 2*60];
        return $map[$priority] ?? 48*60;
    }

    /**
     * 工单日志
     */
    private function log($tenantId, $ticketId, $actorId, $actionType, $remark) {
        try {
            Db::insert('kefu_ticket_log', [
                'tenant_id'   => $tenantId,
                'ticket_id'   => $ticketId,
                'operator_id' => $actorId,
                'action'      => $actionType,
                'from_status' => $this->statusMap($actionType)[0] ?? null,
                'to_status'   => $this->statusMap($actionType)[1] ?? null,
                'remark'      => $remark,
            ]);
        } catch (\Exception $e) {
            Logger::error('ticket log failed', ['err' => $e->getMessage()]);
        }
    }

    private function statusMap($action) {
        $map = [
            'create'  => [null, 'pending'],
            'assign'  => ['pending', 'assigned'],
            'reply'   => ['assigned', 'in_progress'],
            'note'    => [null, null],
            'resolve' => ['in_progress', 'resolved'],
            'close'   => ['resolved', 'closed'],
            'reopen'  => ['closed', 'reopened'],
        ];
        return $map[$action] ?? [null, null];
    }
}