<?php
/**
 * 留言控制器
 * - 访客端提交留言（公开接口 /api/visitor/leave-message）
 * - 客服/管理员管理留言（需要 token）
 */

namespace app\controller;

use support\Request;
use support\Response;
use app\lib\Db;
use app\lib\Auth;
use app\lib\Logger;

class LeaveMessageController
{
    /**
     * 访客提交留言（公开接口，无 token）
     */
    public function submit(Request $request)
    {
        $tenantId = $this->resolveTenant($request);
        if (!$tenantId) {
            return json(['code' => 400, 'msg' => '租户未识别']);
        }
        $content = trim($request->post('content', ''));
        $subject = trim($request->post('subject', ''));
        $name = trim($request->post('visitor_name', ''));
        $email = trim($request->post('visitor_email', ''));
        $phone = trim($request->post('visitor_phone', ''));
        $visitorId = trim($request->post('visitor_id', ''));
        $sessionId = trim($request->post('session_id', ''));
        $source = trim($request->post('source', 'web'));
        $priority = trim($request->post('priority', 'normal'));

        if ($content === '') {
            return json(['code' => 400, 'msg' => '留言内容不能为空']);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return json(['code' => 400, 'msg' => '邮箱格式错误']);
        }
        if ($phone !== '' && !preg_match('/^[\d\-\+\(\)\s]{5,20}$/', $phone)) {
            return json(['code' => 400, 'msg' => '手机号格式错误']);
        }
        if ($priority && !in_array($priority, ['low', 'normal', 'high'])) {
            $priority = 'normal';
        }

        // 收集访客自定义元数据
        $meta = $request->post('visitor_meta', null);
        if (!is_array($meta)) $meta = [];

        Db::setTenantId($tenantId);
        try {
            // 5 分钟内同访客（同 visitor_id 或同 email）的未回复留言合并（追加内容）
            $mergeWhere = "tenant_id = :t AND status = 'new' AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
            $mergeBind = [':t' => $tenantId];
            if ($visitorId) { $mergeWhere .= " AND visitor_id = :vid"; $mergeBind[':vid'] = $visitorId; }
            elseif ($email) { $mergeWhere .= " AND visitor_email = :ve"; $mergeBind[':ve'] = $email; }
            elseif ($phone) { $mergeWhere .= " AND visitor_phone = :vp"; $mergeBind[':vp'] = $phone; }
            else { $mergeWhere .= " AND visitor_id = :vid"; $mergeBind[':vid'] = '__none__'; }
            $existing = Db::find("SELECT id, content FROM kefu_leave_message WHERE $mergeWhere ORDER BY id DESC LIMIT 1", $mergeBind);

            if ($existing) {
                $merged = $existing['content'] . "\n---\n" . $content;
                Db::exec(
                    "UPDATE kefu_leave_message SET content = :c, updated_at = NOW() WHERE id = :id",
                    [':c' => $merged, ':id' => $existing['id']]
                );
                $id = intval($existing['id']);
                // 取原 ticket_no 一起返回
                $row = Db::find("SELECT ticket_no, created_at FROM kefu_leave_message WHERE id = :id", [':id' => $id]);
                $ticketNo = $row['ticket_no'] ?? null;
                $createdAt = $row['created_at'] ?? date('Y-m-d H:i:s');
            } else {
                // 先生成工单号：LM + 年月日 + 6位自增（取当前表内最大 ID 估算）
                $ticketNo = $this->generateTicketNo($tenantId);
                $id = Db::insert('kefu_leave_message', [
                    'ticket_no'    => $ticketNo,
                    'tenant_id'    => $tenantId,
                    'visitor_id'   => $visitorId ?: null,
                    'visitor_name' => $name ?: null,
                    'visitor_email'=> $email ?: null,
                    'visitor_phone'=> $phone ?: null,
                    'visitor_meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                    'session_id'   => $sessionId ?: null,
                    'source'       => $source ?: 'web',
                    'subject'      => $subject ?: null,
                    'content'      => $content,
                    'status'       => 'new',
                    'priority'     => $priority,
                    'ip'           => $request->getRealIp(),
                    'user_agent'   => substr((string)$request->header('user-agent'), 0, 255),
                ]);
                $createdAt = date('Y-m-d H:i:s');
            }
        } catch (\Throwable $e) {
            Logger::error('提交留言失败', ['err' => $e->getMessage()]);
            return json(['code' => 500, 'msg' => '提交失败：' . $e->getMessage()]);
        }

        return json([
            'code' => 0,
            'msg'  => '留言已提交，我们会尽快与您联系',
            'data' => [
                'id'         => $id,
                'ticket_no'  => $ticketNo,
                'created_at' => $createdAt,
            ],
        ]);
    }

    /**
     * 生成工单号：LM + 年月日 + 6位顺序号
     * 格式示例：LM20260805000001
     * 兜底策略：基于当前最大 ID + 1，避免重号
     */
    private function generateTicketNo($tenantId)
    {
        $today = date('Ymd');
        try {
            $maxId = Db::value(
                "SELECT IFNULL(MAX(id), 0) FROM kefu_leave_message WHERE tenant_id = :t",
                [':t' => $tenantId]
            );
            $seq = (int)$maxId + 1;
        } catch (\Throwable $e) {
            $seq = (int)(microtime(true) * 100) % 999999;
        }
        return 'LM' . $today . str_pad((string)$seq, 6, '0', STR_PAD_LEFT);
    }

    /**
     * 解析 tenant_id（公开接口通过 domain 或 header）
     */
    private function resolveTenant(Request $request)
    {
        // 1) header 优先
        $tid = intval($request->header('x-tenant-id', 0));
        if ($tid > 0) return $tid;
        // 2) URL 参数
        $tid = intval($request->get('tenant_id', 0));
        if ($tid > 0) return $tid;
        // 3) 根据 host 查找（如果配置了）
        $host = $request->host();
        // 默认租户 1（演示环境）
        return 1;
    }

    /* ============ 以下是后台接口 ============ */

    /**
     * 留言列表（后台）
     */
    public function list(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);
        $page = max(1, intval($request->get('page', 1)));
        $size = min(100, max(5, intval($request->get('size', 20))));
        $status = trim($request->get('status', ''));
        $keyword = trim($request->get('keyword', ''));

        $where = "WHERE tenant_id = :t";
        $bind = [':t' => $tenantId];
        if ($status && in_array($status, ['new', 'replied', 'spam', 'closed'])) {
            $where .= " AND status = :s";
            $bind[':s'] = $status;
        }
        if ($keyword !== '') {
            $where .= " AND (content LIKE :kw OR visitor_name LIKE :kw OR visitor_email LIKE :kw OR visitor_phone LIKE :kw)";
            $bind[':kw'] = '%' . $keyword . '%';
        }
        $total = Db::value("SELECT COUNT(*) FROM kefu_leave_message $where", $bind);
        $offset = ($page - 1) * $size;
        $rows = Db::query(
            "SELECT * FROM kefu_leave_message $where ORDER BY id DESC LIMIT $size OFFSET $offset",
            $bind
        );

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'list' => $rows, 'total' => (int)$total, 'page' => $page, 'size' => $size
        ]]);
    }

    /**
     * 留言详情
     */
    public function detail(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->get('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id 必填']);
        Db::setTenantId($tenantId);
        $row = Db::query(
            "SELECT * FROM kefu_leave_message WHERE tenant_id = :t AND id = :id",
            [':t' => $tenantId, ':id' => $id]
        );
        if (empty($row)) return json(['code' => 404, 'msg' => '留言不存在']);
        $item = $row[0];
        if ($item['visitor_meta']) {
            $decoded = json_decode($item['visitor_meta'], true);
            $item['visitor_meta'] = is_array($decoded) ? $decoded : [];
        } else {
            $item['visitor_meta'] = [];
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => $item]);
    }

    /**
     * 回复留言
     */
    public function reply(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $empId = intval($request->employee_id ?? 0);
        // 从中间件或 DB 取员工姓名（修复：原代码用 $request->employee_name 但中间件没注入这个字段）
        $empName = trim($request->employee_name ?? '');
        if ($empName === '' && $empId > 0) {
            Db::setTenantId($tenantId);
            $empName = (string)Db::value("SELECT real_name FROM kefu_employee WHERE id = :id", [':id' => $empId]);
            if ($empName === '') $empName = trim((string)($request->username ?? ''));
        }
        $id = intval($request->post('id', 0));
        $content = trim($request->post('reply_content', ''));
        if ($id <= 0 || $content === '') {
            return json(['code' => 400, 'msg' => 'id 和回复内容必填']);
        }
        Db::setTenantId($tenantId);
        try {
            Db::exec(
                "UPDATE kefu_leave_message
                 SET status = 'replied', reply_content = :c, reply_by = :r, reply_by_name = :n, reply_at = NOW(), updated_at = NOW()
                 WHERE tenant_id = :t AND id = :id",
                [':c' => $content, ':r' => $empId, ':n' => $empName, ':t' => $tenantId, ':id' => $id]
            );
            // 修复：留言回复后，把回复内容作为 agent 消息插入访客最近一个会话
            // 这样访客端聊天面板能立刻拉到（解决"留言回复不在访客端显示"的问题）
            try {
                $lm = Db::find("SELECT visitor_id, visitor_name, visitor_email, visitor_phone, source, session_id FROM kefu_leave_message WHERE id = :id AND tenant_id = :t", [':id' => $id, ':t' => $tenantId]);
                if ($lm && !empty($lm['visitor_id'])) {
                    // 修复：kefu_leave_message.visitor_id 是 varchar（如 h5_xxx），它对应 kefu_customer.customer_id
                    // kefu_session.customer_id 是 bigint，指向 kefu_customer.id
                    // 所以必须先查 customer.id 再查 session
                    $customerPkId = intval(Db::value(
                        "SELECT id FROM kefu_customer WHERE tenant_id = :t AND customer_id = :vid LIMIT 1",
                        [':t' => $tenantId, ':vid' => $lm['visitor_id']]
                    ));
                    if ($customerPkId > 0) {
                        // 找该访客最近一个会话（不限 status，closed 也算——用户重新打开能看到回复）
                        $sessId = Db::value(
                            "SELECT session_id FROM kefu_session
                             WHERE tenant_id = :t AND customer_id = :cid
                             ORDER BY id DESC LIMIT 1",
                            [':t' => $tenantId, ':cid' => $customerPkId]
                        );
                        // 兜底：留言里有 session_id 直接用
                        if (!$sessId && !empty($lm['session_id'])) {
                            $sessId = $lm['session_id'];
                        }
                        if ($sessId) {
                            $mid = 'm_' . substr(md5(uniqid('', true)), 0, 16);
                            // 修复：kefu_message 表字段是 sender_id (varchar) 不是 sender_name
                            Db::exec(
                                "INSERT INTO kefu_message
                                  (tenant_id, session_id, agent_id, sender_type, sender_id, msg_type, content, client_msg_id, created_at)
                                 VALUES
                                  (:t, :s, :aid, 'agent', :sid, 'text', :msg, :mid, NOW())",
                                [
                                    ':t'   => $tenantId, ':s' => $sessId, ':aid' => $empId > 0 ? $empId : 0,
                                    ':sid' => $empId > 0 ? (string)$empId : 'agent',
                                    ':mid' => $mid, ':msg' => $content,
                                ]
                            );
                        }
                    }
                }
            } catch (\Throwable $e) {
                // 不影响主流程（留言已成功回复）
                Logger::error('leave_message.reply.inject_message failed: ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '回复失败：' . $e->getMessage()]);
        }
        return json(['code' => 0, 'msg' => '已回复']);
    }

    /**
     * 修改状态（标记为 spam / closed）
     */
    public function updateStatus(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        $status = trim($request->post('status', ''));
        if (!in_array($status, ['new', 'replied', 'spam', 'closed'])) {
            return json(['code' => 400, 'msg' => 'status 取值错误']);
        }
        Db::setTenantId($tenantId);
        Db::exec(
            "UPDATE kefu_leave_message SET status = :s, updated_at = NOW() WHERE tenant_id = :t AND id = :id",
            [':s' => $status, ':t' => $tenantId, ':id' => $id]
        );
        return json(['code' => 0, 'msg' => '已更新']);
    }

    /**
     * 删除留言
     */
    public function delete(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id 必填']);
        Db::setTenantId($tenantId);
        Db::exec("DELETE FROM kefu_leave_message WHERE tenant_id = :t AND id = :id",
            [':t' => $tenantId, ':id' => $id]);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    /**
     * 留言统计
     */
    public function stats(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);
        $rows = Db::query(
            "SELECT status, COUNT(*) AS cnt FROM kefu_leave_message
             WHERE tenant_id = :t GROUP BY status",
            [':t' => $tenantId]
        );
        $result = ['new' => 0, 'replied' => 0, 'spam' => 0, 'closed' => 0, 'total' => 0];
        foreach ($rows as $r) {
            $result[$r['status']] = (int)$r['cnt'];
            $result['total'] += (int)$r['cnt'];
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => $result]);
    }
}