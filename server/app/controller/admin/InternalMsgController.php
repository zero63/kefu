<?php
/**
 * 管理后台 / 客服端 - 站内信箱（内部消息）
 * 功能：发件箱、收件箱、未读数、已读标记
 * 说明：
 *   - 既用于管理员广播，也用于客服间@沟通
 *   - to_employee_id 为 0 表示广播（全员可见）
 * 作者：kefu 开发团队
 */
namespace app\controller\admin;

use support\Request;
use app\lib\Db;

class InternalMsgController
{
    /**
     * 收件箱列表
     * GET 参数：page, size, only_unread(0/1)
     */
    public function inbox(Request $request)
    {
        try {
            $tenantId = intval($request->tenant_id ?? 0);
            $empId = intval($request->employee_id ?? 0);
            $page = max(1, intval($request->get('page', 1)));
            $size = min(100, max(10, intval($request->get('size', 20))));
            $offset = ($page - 1) * $size;
            $onlyUnread = intval($request->get('only_unread', 0));

            Db::setTenantId($tenantId);

            // 收件箱：to_employee_id = 自己 OR to_employee_id = 0 (广播)
            $where = "WHERE m.tenant_id = :t AND (m.to_employee_id = :me OR m.to_employee_id = 0)";
            $bind = [':t' => $tenantId, ':me' => $empId];
            if ($onlyUnread) {
                $where .= ' AND m.is_read = 0';
            }

            $total = Db::value("SELECT COUNT(*) FROM kefu_internal_msg m $where", $bind);
            $bind[':limit'] = $size;
            $bind[':offset'] = $offset;

            $rows = Db::query(
                "SELECT m.id, m.from_employee_id, m.to_employee_id, m.content, m.msg_type,
                        m.is_read, m.read_at, m.created_at,
                        e.real_name AS from_name, e.username AS from_username
                 FROM kefu_internal_msg m
                 LEFT JOIN kefu_employee e ON e.id = m.from_employee_id
                 $where
                 ORDER BY m.id DESC LIMIT :limit OFFSET :offset",
                $bind
            );

            return json(['code' => 0, 'msg' => 'ok', 'data' => [
                'list' => $rows, 'total' => (int)$total, 'page' => $page, 'size' => $size
            ]]);
        } catch (\Throwable $e) {
            \app\lib\Logger::error('msg/inbox error: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => 'inbox错误: ' . $e->getMessage()]);
        }
    }

    /**
     * 未读消息数（用于角标）
     */
    public function unreadCount(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $empId = intval($request->employee_id ?? 0);
        Db::setTenantId($tenantId);

        $count = Db::value(
            "SELECT COUNT(*) FROM kefu_internal_msg
             WHERE tenant_id = :t AND is_read = 0
               AND (to_employee_id = :me OR to_employee_id = 0)",
            [':t' => $tenantId, ':me' => $empId]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['unread' => (int)$count]]);
    }

    /**
     * 发送消息
     * POST：to_employee_id (0=广播), content
     */
    public function send(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $fromId = intval($request->employee_id ?? 0);
        $toId = intval($request->post('to_employee_id', 0));
        $content = trim($request->post('content', ''));

        if ($content === '') {
            return json(['code' => 400, 'msg' => '内容不能为空']);
        }
        if ($fromId <= 0) {
            return json(['code' => 400, 'msg' => '发送人未识别']);
        }
        // 校验收件人（广播传0，其他必须存在）
        Db::setTenantId($tenantId);
        if ($toId > 0) {
            $exists = Db::value(
                "SELECT COUNT(*) FROM kefu_employee WHERE tenant_id = :t AND id = :e",
                [':t' => $tenantId, ':e' => $toId]
            );
            if ($exists == 0) {
                return json(['code' => 400, 'msg' => '收件人不存在']);
            }
        }
        // 广播权限：仅超管/管理员可发广播
        if ($toId === 0) {
            $roleId = intval($request->role_id ?? 0);
            if ($roleId > 2) {
                return json(['code' => 403, 'msg' => '无权发送全员广播']);
            }
        }

        $id = Db::insert('kefu_internal_msg', [
            'tenant_id'         => $tenantId,
            'from_employee_id'  => $fromId,
            'to_employee_id'    => $toId,
            'content'           => $content,
            'msg_type'          => 'text',
            'is_read'           => 0,
        ]);
        return json(['code' => 0, 'msg' => '发送成功', 'data' => ['id' => $id]]);
    }

    /**
     * 标记已读（单条）
     */
    public function read(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $empId = intval($request->employee_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) {
            return json(['code' => 400, 'msg' => 'id必填']);
        }
        Db::setTenantId($tenantId);

        // 只能改发给自己的
        $affected = Db::exec(
            "UPDATE kefu_internal_msg
             SET is_read = 1, read_at = :n
             WHERE tenant_id = :t AND id = :id AND is_read = 0
               AND (to_employee_id = :me OR to_employee_id = 0)",
            [':t' => $tenantId, ':id' => $id, ':me' => $empId, ':n' => date('Y-m-d H:i:s')]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['affected' => $affected]]);
    }

    /**
     * 全部标记已读
     */
    public function readAll(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $empId = intval($request->employee_id ?? 0);
        Db::setTenantId($tenantId);
        $affected = Db::exec(
            "UPDATE kefu_internal_msg
             SET is_read = 1, read_at = :n
             WHERE tenant_id = :t AND is_read = 0
               AND (to_employee_id = :me OR to_employee_id = 0)",
            [':t' => $tenantId, ':me' => $empId, ':n' => date('Y-m-d H:i:s')]
        );
        return json(['code' => 0, 'msg' => '全部已读', 'data' => ['affected' => $affected]]);
    }

    /**
     * 删除（仅本人发件或收件可删）
     */
    public function delete(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $empId = intval($request->employee_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) {
            return json(['code' => 400, 'msg' => 'id必填']);
        }
        Db::setTenantId($tenantId);
        Db::exec(
            "DELETE FROM kefu_internal_msg
             WHERE tenant_id = :t AND id = :id
               AND (from_employee_id = :me OR to_employee_id = :me)",
            [':t' => $tenantId, ':id' => $id, ':me' => $empId]
        );
        return json(['code' => 0, 'msg' => '已删除']);
    }
}