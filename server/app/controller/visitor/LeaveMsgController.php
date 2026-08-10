<?php
/**
 * 留言控制器
 * 作者：kefu 开发团队
 * 创建时间：2026-08-01
 * 说明：
 *   - submit：访客提交留言（visitor 分组，无需鉴权）
 *   - list：客服查看留言列表（agent 分组，需鉴权）
 *   - 操作 kefu_leave_msg 表
 *   - 路由：
 *     POST /api/visitor/leave-msg/submit
 *     GET  /api/agent/leave-msg/list
 */

namespace app\controller\visitor;

use support\Request;
use app\lib\Db;

class LeaveMsgController
{
    /**
     * 访客提交留言
     * POST /api/visitor/leave-msg/submit
     * Body: { customer_id, customer_name?, customer_phone?, content, attachment?, channel? }
     */
    public function submit(Request $request) {
        $tenantId = intval($request->tenant_id ?? 1);
        Db::setTenantId($tenantId);

        $customerId = trim($request->post('customer_id', ''));
        $content = trim($request->post('content', ''));
        $name = trim($request->post('customer_name', '')) ?: trim($request->post('name', ''));

        // 修复：留言时 customer_id 可选（访客可能尚未注册），只要有 content + name 或 phone 即可
        if (empty($content)) {
            return json(['code' => 400, 'msg' => '留言内容不能为空']);
        }
        if (empty($customerId) && empty($name) && !trim($request->post('customer_phone', '')) && !trim($request->post('phone', ''))) {
            return json(['code' => 400, 'msg' => '请至少留下姓名或联系方式']);
        }

        $data = [
            'tenant_id'      => $tenantId,
            'customer_id'    => $customerId ?: null,
            'customer_name'  => $name ?: null,
            'customer_phone' => $request->post('customer_phone', '') ?: $request->post('phone', '') ?: null,
            'content'        => $content,
            'attachment'     => $request->post('attachment', '') ?: null,
            'status'         => 'pending',
            'channel'        => $request->post('channel', 'h5') ?: 'h5',
        ];

        $id = Db::insert('kefu_leave_msg', $data);

        return json(['code' => 0, 'msg' => '留言提交成功', 'data' => ['id' => $id]]);
    }

    /**
     * 客服查看留言列表
     * GET /api/agent/leave-msg/list
     * Query: ?status=pending&page=1&size=20
     */
    public function list(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);

        $status = trim($request->get('status', ''));
        $page = max(1, intval($request->get('page', 1)));
        $size = min(100, max(1, intval($request->get('size', 20))));
        $offset = ($page - 1) * $size;

        // 构建查询条件
        $sql = "SELECT * FROM kefu_leave_msg WHERE tenant_id = :tid";
        $params = [':tid' => $tenantId];

        if (!empty($status) && in_array($status, ['pending', 'processing', 'resolved', 'closed'])) {
            $sql .= " AND status = :status";
            $params[':status'] = $status;
        }

        // 总数
        $total = intval(Db::value("SELECT COUNT(*) FROM ($sql) AS tmp", $params));

        $sql .= " ORDER BY id DESC LIMIT $offset, $size";
        $rows = Db::query($sql, $params);

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'list'  => $rows,
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
        ]]);
    }
}
