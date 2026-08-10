<?php
/**
 * 客服工作状态切换
 * author: kefu dev team
 */
namespace app\controller\agent;
use support\Request;
use app\lib\Db;

class StatusController {
    public function switch(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $employeeId = intval($request->employee_id ?? 0);
        $status = trim($request->post('status', 'online'));
        if (!in_array($status, ['online', 'busy', 'away', 'offline'])) {
            return json(['code' => 400, 'msg' => 'invalid status']);
        }
        Db::setTenantId($tenantId);

        // 更新客服工作状态到 kefu_employee.work_status 字段
        // 客服主动切"离线"时记录 manual_offline_at，心跳不再覆盖
        if ($status === 'offline') {
            Db::exec(
                "UPDATE kefu_employee SET work_status = :status, manual_offline_at = NOW() WHERE id = :id",
                [':id' => $employeeId, ':status' => $status]
            );
        } else {
            Db::exec(
                "UPDATE kefu_employee SET work_status = :status, manual_offline_at = NULL WHERE id = :id",
                [':id' => $employeeId, ':status' => $status]
            );
        }

        // 通知管理员
        \app\lib\ConnectionManager::pushToRole('admin', [
            'type' => 'agent_status_change',
            'employee_id' => $employeeId,
            'status' => $status,
        ]);

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['status' => $status]]);
    }
}