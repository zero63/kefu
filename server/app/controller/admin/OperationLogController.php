<?php
/**
 * 管理后台 - 操作日志
 * 功能：分页查询、筛选、按时间清理
 * 作者：kefu 开发团队
 */
namespace app\controller\admin;

use support\Request;
use app\lib\Db;

class OperationLogController
{
    /**
     * 分页查询
     * GET 参数：page, size, module, operator_id, start, end, keyword
     */
    public function list(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $page = max(1, intval($request->get('page', 1)));
        $size = min(100, max(10, intval($request->get('size', 20))));
        $offset = ($page - 1) * $size;

        Db::setTenantId($tenantId);

        $where = 'WHERE tenant_id = :t';
        $bind = [':t' => $tenantId];

        if ($m = trim($request->get('module', ''))) {
            $where .= ' AND module = :m';
            $bind[':m'] = $m;
        }
        if ($op = intval($request->get('operator_id', 0))) {
            $where .= ' AND operator_id = :op';
            $bind[':op'] = $op;
        }
        if ($start = trim($request->get('start', ''))) {
            $where .= ' AND created_at >= :s';
            $bind[':s'] = $start . ' 00:00:00';
        }
        if ($end = trim($request->get('end', ''))) {
            $where .= ' AND created_at <= :e';
            $bind[':e'] = $end . ' 23:59:59';
        }
        if ($kw = trim($request->get('keyword', ''))) {
            $where .= ' AND (description LIKE :k OR operator_name LIKE :k2)';
            $bind[':k']  = "%$kw%";
            $bind[':k2'] = "%$kw%";
        }

        $total = Db::value("SELECT COUNT(*) FROM kefu_operation_log $where", $bind);
        $bind[':limit'] = $size;
        $bind[':offset'] = $offset;
        $rows = Db::query(
            "SELECT id, operator_id, operator_name, module, action, target_id,
                    description, ip, created_at
             FROM kefu_operation_log $where
             ORDER BY id DESC LIMIT :limit OFFSET :offset",
            $bind
        );

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'list' => $rows, 'total' => (int)$total, 'page' => $page, 'size' => $size
        ]]);
    }

    /**
     * 删除某条日志（仅超管）
     */
    public function delete(Request $request)
    {
        $roleId = intval($request->role_id ?? 0);
        if ($roleId > 2) {
            return json(['code' => 403, 'msg' => '无权操作']);
        }
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) {
            return json(['code' => 400, 'msg' => 'id必填']);
        }
        Db::setTenantId($tenantId);
        Db::delete('kefu_operation_log', ['id' => $id]);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    /**
     * 按时间清理（如保留 90 天）
     */
    public function clear(Request $request)
    {
        $roleId = intval($request->role_id ?? 0);
        if ($roleId > 2) {
            return json(['code' => 403, 'msg' => '无权操作']);
        }
        $tenantId = intval($request->tenant_id ?? 0);
        $days = intval($request->post('days', 90));
        if ($days < 7) {
            return json(['code' => 400, 'msg' => '至少保留7天']);
        }
        Db::setTenantId($tenantId);
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $affected = Db::exec(
            "DELETE FROM kefu_operation_log WHERE tenant_id = :t AND created_at < :c",
            [':t' => $tenantId, ':c' => $cutoff]
        );
        return json(['code' => 0, 'msg' => '已清理', 'data' => ['affected' => $affected]]);
    }

    /**
     * 写入日志（供其他模块调用）
     * @param int $tenantId
     * @param int $operatorId
     * @param string $operatorName
     * @param string $module 模块名
     * @param string $action 操作类型
     * @param string $targetId 目标对象ID
     * @param string $description 描述
     * @param string|null $requestData 请求参数
     * @param string|null $ip
     */
    public static function log(
        $tenantId, $operatorId, $operatorName,
        $module, $action, $targetId = '',
        $description = '', $requestData = null, $ip = null
    ) {
        if ($tenantId <= 0) return;
        try {
            Db::setTenantId($tenantId);
            Db::insert('kefu_operation_log', [
                'tenant_id'     => $tenantId,
                'operator_id'   => $operatorId,
                'operator_name' => $operatorName,
                'module'        => $module,
                'action'        => $action,
                'target_id'     => (string)$targetId,
                'description'   => $description,
                'request_data'  => $requestData !== null ? (is_string($requestData) ? $requestData : json_encode($requestData, JSON_UNESCAPED_UNICODE)) : null,
                'ip'            => $ip,
            ]);
        } catch (\Throwable $e) {
            // 日志写失败不影响主业务
            error_log('OperationLog write failed: ' . $e->getMessage());
        }
    }
}