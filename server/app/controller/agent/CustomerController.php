<?php
/**
 * 客服侧 - 客户详情
 * author: kefu dev team
 */
namespace app\controller\agent;
use support\Request;
use app\lib\Db;

class CustomerController {
    /**
     * GET /api/agent/customer/list
     * 客服客户列表（只显示"我接待过"的客户，避免看到全公司所有访客）
     * 可选参数：keyword（按 customer_id/昵称/邮箱/手机模糊）、tag_id、page、size
     */
    public function list(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $agentId = intval($request->employee_id ?? 0);
        $keyword = trim($request->get('keyword', ''));
        $tagId = intval($request->get('tag_id', 0));
        $page = max(1, intval($request->get('page', 1)));
        $size = min(50, max(10, intval($request->get('size', 20))));
        $offset = ($page - 1) * $size;

        Db::setTenantId($tenantId);

        $where = 'WHERE c.tenant_id = :t';
        $bind = [':t' => $tenantId];

        // 修复：客服只看到自己接待过的客户（更具操作性）
        // 超级管理员 / 主管（role_id >= 4）可以看全部
        if ($agentId > 0) {
            $where .= ' AND EXISTS (SELECT 1 FROM kefu_session s WHERE s.customer_id = c.id AND s.agent_id = :aid)';
            $bind[':aid'] = $agentId;
        }
        if ($keyword !== '') {
            $where .= ' AND (c.customer_id LIKE :kw OR c.nickname LIKE :kw OR c.email LIKE :kw OR c.phone LIKE :kw)';
            $bind[':kw'] = '%' . $keyword . '%';
        }
        if ($tagId > 0) {
            $where .= ' AND EXISTS (SELECT 1 FROM kefu_customer_tag_rel r WHERE r.customer_id = c.id AND r.tag_id = :tg)';
            $bind[':tg'] = $tagId;
        }

        $total = Db::value("SELECT COUNT(*) FROM kefu_customer c $where", $bind);
        $bind[':limit'] = $size;
        $bind[':offset'] = $offset;

        // 修复：PDO 不支持同名占位符重复，:t 用拼接
        $rows = Db::query(
            "SELECT c.id, c.customer_id, c.nickname, c.avatar, c.email, c.phone, c.channel,
                    c.register_time, c.last_active_time, c.status,
                    (SELECT COUNT(*) FROM kefu_session s
                     WHERE s.customer_id = c.id AND s.tenant_id = $tenantId) AS total_sessions,
                    (SELECT IFNULL(SUM(message_count), 0) FROM kefu_session s
                     WHERE s.customer_id = c.id AND s.tenant_id = $tenantId) AS total_messages,
                    (SELECT IFNULL(MAX(created_at), '') FROM kefu_session s
                     WHERE s.customer_id = c.id AND s.tenant_id = $tenantId) AS last_session_at
             FROM kefu_customer c
             $where
             ORDER BY c.last_active_time DESC, c.id DESC
             LIMIT :limit OFFSET :offset",
            $bind
        );

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'list' => $rows, 'total' => (int)$total, 'page' => $page, 'size' => $size,
        ]]);
    }

    /**
     * GET /api/agent/customer/tags
     * 所有可用标签（供筛选下拉用）
     */
    public function tags(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);
        $rows = Db::query("SELECT id, tag_name, tag_color FROM kefu_customer_tag WHERE tenant_id = :t ORDER BY id ASC", [':t' => $tenantId]);
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    /**
     * GET /api/agent/customer/detail
     */
    public function detail(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $customerId = trim($request->get('customer_id', ''));
        if (empty($customerId)) {
            return json(['code' => 400, 'msg' => 'customer_id required']);
        }
        Db::setTenantId($tenantId);

        $customer = Db::find(
            "SELECT * FROM kefu_customer WHERE tenant_id = :t AND customer_id = :c",
            [':t' => $tenantId, ':c' => $customerId]
        );
        if (!$customer) return json(['code' => 404, 'msg' => '客户不存在']);

        $sessions = Db::query(
            "SELECT s.session_id, s.status, s.created_at, s.closed_at, s.close_reason, s.message_count, s.duration,
                    e.real_name AS agent_name
             FROM kefu_session s
             LEFT JOIN kefu_employee e ON e.id = s.agent_id
             WHERE s.customer_id = :cid
             ORDER BY s.id DESC LIMIT 10",
            [':cid' => $customer['id']]
        );

        $tags = Db::query(
            "SELECT t.tag_name, t.tag_color
             FROM kefu_customer_tag_rel r
             INNER JOIN kefu_customer_tag t ON t.id = r.tag_id
             WHERE r.customer_id = :c",
            [':c' => $customer['id']]
        );

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'customer' => $customer,
            'sessions' => $sessions,
            'tags'     => $tags,
        ]]);
    }
}