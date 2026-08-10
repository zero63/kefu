<?php
/**
 * 管理后台 - 会话黑名单
 * 功能：屏蔽客户/IP/手机号，防止骚扰
 * 作者：kefu 开发团队
 */
namespace app\controller\admin;

use support\Request;
use app\lib\Db;

class BlacklistController
{
    /**
     * 黑名单列表
     */
    public function list(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $page = max(1, intval($request->get('page', 1)));
        $size = min(100, max(10, intval($request->get('size', 20))));
        $offset = ($page - 1) * $size;

        Db::setTenantId($tenantId);
        $where = 'WHERE b.tenant_id = :t';
        $bind = [':t' => $tenantId];
        if ($t = trim($request->get('target_type', ''))) {
            $where .= ' AND b.target_type = :tt';
            $bind[':tt'] = $t;
        }
        if ($kw = trim($request->get('keyword', ''))) {
            $where .= ' AND (b.target_value LIKE :k OR b.reason LIKE :k)';
            $bind[':k'] = "%$kw%";
        }

        $total = Db::value("SELECT COUNT(*) FROM kefu_blacklist b $where", $bind);
        $bind[':limit'] = $size;
        $bind[':offset'] = $offset;

        $rows = Db::query(
            "SELECT b.id, b.target_type, b.target_value, b.reason, b.added_by,
                    b.expire_at, b.created_at, e.username AS added_by_name
             FROM kefu_blacklist b
             LEFT JOIN kefu_employee e ON e.id = b.added_by
             $where ORDER BY b.id DESC LIMIT :limit OFFSET :offset",
            $bind
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'list' => $rows, 'total' => (int)$total, 'page' => $page, 'size' => $size
        ]]);
    }

    /**
     * 加入黑名单
     */
    public function add(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $type = trim($request->post('target_type', ''));
        $value = trim($request->post('target_value', ''));
        if (!in_array($type, ['customer', 'ip', 'phone'])) {
            return json(['code' => 400, 'msg' => 'target_type 必须是 customer/ip/phone']);
        }
        if ($value === '') {
            return json(['code' => 400, 'msg' => 'target_value 必填']);
        }
        Db::setTenantId($tenantId);

        // 重复检查
        $exists = Db::value(
            "SELECT COUNT(*) FROM kefu_blacklist
             WHERE tenant_id = :t AND target_type = :tt AND target_value = :v",
            [':t' => $tenantId, ':tt' => $type, ':v' => $value]
        );
        if ($exists > 0) {
            return json(['code' => 400, 'msg' => '该对象已在黑名单中']);
        }

        $expireAt = $request->post('expire_at', '');
        $id = Db::insert('kefu_blacklist', [
            'tenant_id'    => $tenantId,
            'target_type'  => $type,
            'target_value' => $value,
            'reason'       => trim($request->post('reason', '')),
            'added_by'     => intval($request->employee_id ?? 0) ?: null,
            'expire_at'    => $expireAt ?: null,
        ]);
        return json(['code' => 0, 'msg' => '已加入黑名单', 'data' => ['id' => $id]]);
    }

    /**
     * 移出黑名单
     */
    public function remove(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        Db::delete('kefu_blacklist', ['id' => $id]);
        return json(['code' => 0, 'msg' => '已移出']);
    }

    /**
     * 检查某个值是否在黑名单（供访客端使用）
     * GET 参数：target_type=customer/ip/phone, target_value=xxx
     */
    public function check(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $type = trim($request->get('target_type', ''));
        $value = trim($request->get('target_value', ''));
        if ($type === '' || $value === '') {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        Db::setTenantId($tenantId);
        $row = Db::find(
            "SELECT id, reason, expire_at FROM kefu_blacklist
             WHERE tenant_id = :t AND target_type = :tt AND target_value = :v",
            [':t' => $tenantId, ':tt' => $type, ':v' => $value]
        );
        if (!$row) {
            return json(['code' => 0, 'msg' => 'ok', 'data' => ['blocked' => false]]);
        }
        // 检查是否过期
        if ($row['expire_at'] && strtotime($row['expire_at']) < time()) {
            return json(['code' => 0, 'msg' => 'ok', 'data' => ['blocked' => false]]);
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'blocked' => true,
            'reason'  => $row['reason'],
            'expire_at' => $row['expire_at'],
        ]]);
    }
}