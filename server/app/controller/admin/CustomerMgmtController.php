<?php
/**
 * 客户管理（客户列表 / VIP / 分组 / 留言）
 * 作者：kefu 开发团队
 * 创建时间：2026-08-01
 * 客户管理（客户列表 / VIP / 分组 / 留言）
 */
namespace app\controller\admin;

use support\Request;
use app\lib\Db;

class CustomerMgmtController
{
    // ============ 客户列表 ============

    public function list(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $page = max(1, intval($request->get('page', 1)));
        $size = min(100, max(10, intval($request->get('size', 20))));
        $offset = ($page - 1) * $size;
        $where = 'WHERE tenant_id = :t';
        $bind = [':t' => $tenantId];
        if ($kw = trim($request->get('keyword', ''))) {
            $where .= ' AND (customer_id LIKE :k OR nickname LIKE :k2 OR phone LIKE :k3 OR email LIKE :k4)';
            $bind[':k']  = "%{$kw}%";
            $bind[':k2'] = "%{$kw}%";
            $bind[':k3'] = "%{$kw}%";
            $bind[':k4'] = "%{$kw}%";
        }
        if ($vip = $request->get('vip_level', '')) {
            if ($vip !== 'all') {
                $where .= ' AND vip_level = :v';
                $bind[':v'] = intval($vip);
            }
        }
        if ($st = trim($request->get('status', ''))) {
            $where .= ' AND status = :st';
            $bind[':st'] = $st;
        }
        $total = Db::value("SELECT COUNT(*) FROM kefu_customer $where", $bind);
        $bind[':limit'] = $size;
        $bind[':offset'] = $offset;
        $rows = Db::query(
            "SELECT id, customer_id AS external_id, channel, nickname, phone, email,
                    avatar, gender, vip_level, source, owner_employee_id, tags, status,
                    total_spent, last_active_time, created_at
             FROM kefu_customer $where ORDER BY last_active_time DESC, id DESC
             LIMIT :limit OFFSET :offset",
            $bind
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'list' => $rows, 'total' => (int)$total, 'page' => $page, 'size' => $size
        ]]);
    }

    public function detail(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->get('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        $row = Db::find("SELECT * FROM kefu_customer WHERE tenant_id = :t AND id = :id",
            [':t' => $tenantId, ':id' => $id]);
        if (!$row) return json(['code' => 404, 'msg' => '客户不存在']);
        // 读取自定义字段
        $fields = Db::query(
            "SELECT f.field_key, f.field_name, f.field_type, v.field_value
             FROM kefu_customer_field f
             LEFT JOIN kefu_customer_field_value v
                 ON v.field_id = f.id AND v.customer_id = :cid
             WHERE f.tenant_id = :t ORDER BY f.sort_no",
            [':t' => $tenantId, ':cid' => $row['customer_id']]
        );
        $row['custom_fields'] = $fields;
        return json(['code' => 0, 'msg' => 'ok', 'data' => $row]);
    }

    public function update(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        $data = [];
        foreach (['nickname', 'phone', 'email', 'avatar'] as $f) {
            if ($request->post($f) !== null) $data[$f] = trim($request->post($f));
        }
        if ($request->post('gender') !== null) $data['gender'] = intval($request->post('gender'));
        if ($request->post('vip_level') !== null) $data['vip_level'] = intval($request->post('vip_level'));
        if ($request->post('tags') !== null) $data['tags'] = trim($request->post('tags'));
        if ($request->post('profile') !== null) $data['profile'] = json_encode($request->post('profile'), JSON_UNESCAPED_UNICODE);
        if (empty($data)) return json(['code' => 400, 'msg' => '无字段更新']);
        Db::update('kefu_customer', $data, ['id' => $id]);
        return json(['code' => 0, 'msg' => '已更新']);
    }

    public function setVip(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        $level = intval($request->post('vip_level', 0));
        if ($id <= 0 || $level < 0 || $level > 5) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        Db::setTenantId($tenantId);
        Db::update('kefu_customer', ['vip_level' => $level], ['id' => $id]);
        return json(['code' => 0, 'msg' => '已设置 VIP 等级']);
    }

    public function stats(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $rows = Db::query(
            "SELECT vip_level, COUNT(*) AS cnt FROM kefu_customer
             WHERE tenant_id = :t GROUP BY vip_level",
            [':t' => $tenantId]
        );
        $out = ['total' => 0, 'vip0' => 0, 'vip1' => 0, 'vip2' => 0, 'vip3' => 0, 'vip4' => 0, 'vip5' => 0];
        foreach ($rows as $r) {
            $out['total'] += $r['cnt'];
            $out['vip' . $r['vip_level']] = (int)$r['cnt'];
        }
        $groupRows = Db::query(
            "SELECT g.id, g.group_name AS name, g.customer_count FROM kefu_customer_group g
             WHERE g.tenant_id = :t ORDER BY g.id ASC",
            [':t' => $tenantId]
        );
        $out['groups'] = $groupRows;
        return json(['code' => 0, 'msg' => 'ok', 'data' => $out]);
    }

    // ============ 客户分组 ============

    public function groupList(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $rows = Db::query(
            "SELECT id, group_name AS name, description, `condition` AS rules_json,
                    customer_count, created_at
             FROM kefu_customer_group WHERE tenant_id = :t ORDER BY id ASC",
            [':t' => $tenantId]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    public function groupCreate(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $name = trim($request->post('name', ''));
        if ($name === '') return json(['code' => 400, 'msg' => '分组名必填']);
        Db::setTenantId($tenantId);
        $id = Db::insert('kefu_customer_group', [
            'tenant_id'    => $tenantId,
            'group_name'   => $name,
            'description'  => trim($request->post('description', '')),
            'condition'    => json_encode($request->post('rules', []), JSON_UNESCAPED_UNICODE),
            'customer_count'=> 0,
            'created_by'   => intval($request->employee_id ?? 0) ?: null,
        ]);
        return json(['code' => 0, 'msg' => '已创建', 'data' => ['id' => $id]]);
    }

    public function groupDelete(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        Db::delete('kefu_customer_group', ['id' => $id]);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    // ============ 留言记录 ============

    public function leaveList(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $page = max(1, intval($request->get('page', 1)));
        $size = 20;
        $offset = ($page - 1) * $size;
        $where = 'WHERE m.tenant_id = :t';
        $bind = [':t' => $tenantId];
        if ($s = trim($request->get('status', ''))) {
            $where .= ' AND m.status = :s';
            $bind[':s'] = $s;
        }
        if ($kw = trim($request->get('keyword', ''))) {
            $where .= ' AND (m.customer_name LIKE :k OR m.content LIKE :k2)';
            $bind[':k'] = "%{$kw}%";
            $bind[':k2'] = "%{$kw}%";
        }
        $total = Db::value("SELECT COUNT(*) FROM kefu_leave_msg m $where", $bind);
        $bind[':limit'] = $size;
        $bind[':offset'] = $offset;
        $rows = Db::query(
            "SELECT m.id, m.tenant_id, m.customer_id, m.customer_name, m.customer_phone,
                    m.content, m.attachment, m.status, m.assigned_to,
                    m.reply_content, m.reply_at, m.channel, m.created_at,
                    e.username AS assigned_to_name
             FROM kefu_leave_msg m
             LEFT JOIN kefu_employee e ON e.id = m.assigned_to
             $where ORDER BY m.id DESC LIMIT :limit OFFSET :offset",
            $bind
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'list' => $rows, 'total' => (int)$total, 'page' => $page, 'size' => $size
        ]]);
    }

    public function leaveAssign(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        $empId = intval($request->post('assigned_to', 0));
        if ($id <= 0 || $empId <= 0) return json(['code' => 400, 'msg' => '参数错误']);
        Db::setTenantId($tenantId);
        Db::update('kefu_leave_msg',
            ['assigned_to' => $empId, 'status' => 'assigned'],
            ['id' => $id]);
        return json(['code' => 0, 'msg' => '已分配']);
    }

    public function leaveReply(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $empId = intval($request->employee_id ?? 0);
        $empName = trim((string)$request->employee_name ?? '');
        if ($empName === '' && $empId > 0) {
            Db::setTenantId($tenantId);
            $empName = (string)Db::value("SELECT real_name FROM kefu_employee WHERE id = :id", [':id' => $empId]);
        }
        $id = intval($request->post('id', 0));
        $reply = trim($request->post('reply_content', ''));
        if ($id <= 0 || $reply === '') return json(['code' => 400, 'msg' => '参数错误']);
        Db::setTenantId($tenantId);
        Db::update('kefu_leave_msg', [
            'reply_content' => $reply,
            'reply_at'      => date('Y-m-d H:i:s'),
            'status'        => 'resolved',
        ], ['id' => $id]);
        // 修复：把客服回复注入访客最近一个会话（让访客端能看到）
        try {
            $lm = Db::find("SELECT customer_id, customer_name FROM kefu_leave_msg WHERE id = :id AND tenant_id = :t", [':id' => $id, ':t' => $tenantId]);
            if ($lm && intval($lm['customer_id']) > 0) {
                $sessId = Db::value(
                    "SELECT session_id FROM kefu_session
                     WHERE tenant_id = :t AND customer_id = :cid
                     ORDER BY id DESC LIMIT 1",
                    [':t' => $tenantId, ':cid' => intval($lm['customer_id'])]
                );
                if ($sessId) {
                    $mid = 'm_' . substr(md5(uniqid('', true)), 0, 16);
                    // 修复：kefu_message 表字段是 sender_id (varchar) 不是 sender_name，且没有 customer_id 字段
                    Db::exec(
                        "INSERT INTO kefu_message
                          (tenant_id, session_id, agent_id, sender_type, sender_id, msg_type, content, client_msg_id, created_at)
                         VALUES (:t, :s, :aid, 'agent', :sid, 'text', :msg, :mid, NOW())",
                        [
                            ':t' => $tenantId, ':s' => $sessId, ':aid' => $empId > 0 ? $empId : 0,
                            ':sid' => $empId > 0 ? (string)$empId : 'agent',
                            ':mid' => $mid, ':msg' => $reply,
                        ]
                    );
                }
            }
        } catch (\Throwable $e) {
            // 不影响主流程
        }
        return json(['code' => 0, 'msg' => '已回复']);
    }

    /**
     * 修复：更新留言状态（标记已处理 / 关闭 / 垃圾）
     * POST /api/admin/leave-msg/update
     * Body: { id, status }
     */
    public function leaveUpdate(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        $status = trim($request->post('status', ''));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id 必填']);
        // 允许的状态值
        $allowed = ['pending', 'assigned', 'resolved', 'closed', 'spam'];
        if (!in_array($status, $allowed, true)) {
            return json(['code' => 400, 'msg' => 'status 取值错误，允许：' . implode(',', $allowed)]);
        }
        Db::setTenantId($tenantId);
        Db::update('kefu_leave_msg', ['status' => $status], ['id' => $id]);
        return json(['code' => 0, 'msg' => '已更新']);
    }
}