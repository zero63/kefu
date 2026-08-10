<?php
/**
 * 管理后台 - 角色管理
 * 功能：角色列表、分配员工角色、角色与权限绑定（kefu_role + kefu_role_permission）
 * 作者：kefu 开发团队
 */
namespace app\controller\admin;
use support\Request;
use app\lib\Db;

class RoleController {
    /**
     * 角色列表（含员工数 / 部门数等关联统计）
     */
    public function list(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);

        // 按租户隔离：只查当前租户的角色，并统计员工数
        $rows = Db::query(
            "SELECT r.*,
                    (SELECT COUNT(*) FROM kefu_employee e WHERE e.tenant_id = r.tenant_id AND e.role_id = r.id) AS emp_count
             FROM kefu_role r
             WHERE r.tenant_id = :t
             ORDER BY r.is_system DESC, r.id ASC",
            [':t' => $tenantId]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    /**
     * 给员工分配角色（可同时设置部门）
     */
    public function assign(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $employeeId = intval($request->post('employee_id', 0));
        $roleId = intval($request->post('role_id', 0));
        if ($employeeId <= 0 || $roleId <= 0) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        Db::setTenantId($tenantId);

        // 校验角色存在
        $roleOk = Db::value(
            "SELECT COUNT(*) FROM kefu_role WHERE tenant_id = :t AND id = :r",
            [':t' => $tenantId, ':r' => $roleId]
        );
        if ($roleOk == 0) return json(['code' => 400, 'msg' => '所选角色不存在']);

        // 同时可改部门（联动）
        $data = ['role_id' => $roleId];
        if ($request->post('dept_id') !== null) {
            $deptId = intval($request->post('dept_id', 0));
            if ($deptId > 0) {
                $deptOk = Db::value(
                    "SELECT COUNT(*) FROM kefu_dept WHERE tenant_id = :t AND id = :d",
                    [':t' => $tenantId, ':d' => $deptId]
                );
                if ($deptOk == 0) return json(['code' => 400, 'msg' => '所选部门不存在']);
                $data['dept_id'] = $deptId;
            } else {
                $data['dept_id'] = 0;
            }
        }

        Db::update('kefu_employee', $data, ['id' => $employeeId]);
        return json(['code' => 0, 'msg' => '分配成功']);
    }

    /**
     * 批量分配部门（不换角色）
     */
    public function assignDept(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $employeeIds = $request->post('employee_ids', []);
        $deptId = intval($request->post('dept_id', 0));
        if (!is_array($employeeIds) || empty($employeeIds)) {
            return json(['code' => 400, 'msg' => '请选择员工']);
        }
        Db::setTenantId($tenantId);

        // 校验部门
        if ($deptId > 0) {
            $deptOk = Db::value(
                "SELECT COUNT(*) FROM kefu_dept WHERE tenant_id = :t AND id = :d",
                [':t' => $tenantId, ':d' => $deptId]
            );
            if ($deptOk == 0) return json(['code' => 400, 'msg' => '所选部门不存在']);
        }

        $count = 0;
        foreach (array_unique(array_map('intval', $employeeIds)) as $eid) {
            if ($eid <= 0) continue;
            $r = Db::update('kefu_employee', ['dept_id' => $deptId], ['id' => $eid]);
            $count++;
        }
        return json(['code' => 0, 'msg' => '已调整 ' . $count . ' 名员工部门', 'data' => ['count' => $count]]);
    }
}