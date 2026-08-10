<?php
/**
 * 管理后台 - 员工管理
 * 功能：员工 CRUD、关联部门/角色、列表带部门名/角色名展示
 * 作者：kefu 开发团队
 */
namespace app\controller\admin;
use support\Request;
use app\lib\Db;

class EmployeeController {
    /**
     * 员工列表（带 JOIN 返回部门名 + 角色名）
     */
    public function list(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $page = max(1, intval($request->get('page', 1)));
        $size = min(100, max(10, intval($request->get('size', 20))));
        $keyword = trim($request->get('keyword', ''));
        $deptId = intval($request->get('dept_id', 0));
        $offset = ($page - 1) * $size;

        Db::setTenantId($tenantId);

        // 多租户隔离
        $where = 'WHERE e.tenant_id = :t';
        $bind = [':t' => $tenantId];
        if ($keyword !== '') {
            $where .= ' AND (e.username LIKE :k OR e.real_name LIKE :k OR e.employee_no LIKE :k)';
            $bind[':k'] = "%$keyword%";
        }
        if ($deptId > 0) {
            $where .= ' AND e.dept_id = :d';
            $bind[':d'] = $deptId;
        }

        $total = Db::value("SELECT COUNT(*) FROM kefu_employee e $where", $bind);

        $bind[':limit'] = $size;
        $bind[':offset'] = $offset;

        // LEFT JOIN 部门表 + 角色表，便于前端展示
        $rows = Db::query(
            "SELECT e.id, e.tenant_id, e.username, e.real_name, e.nickname, e.employee_no, e.avatar,
                    e.email, e.phone, e.dept_id, e.role_id, e.max_sessions, e.skill_level,
                    e.status, e.work_status, e.last_login_at, e.last_login_ip, e.created_at,
                    d.dept_name,
                    r.role_name, r.is_system AS role_is_system
             FROM kefu_employee e
             LEFT JOIN kefu_dept d ON d.id = e.dept_id AND d.tenant_id = e.tenant_id
             LEFT JOIN kefu_role r ON r.id = e.role_id AND r.tenant_id = e.tenant_id
             $where ORDER BY e.id DESC LIMIT :limit OFFSET :offset",
            $bind
        );

        // 附加实时在线状态（基于 push/online/{uid}.json 文件）
        $onlineDir = runtime_path('push') . '/online';
        foreach ($rows as &$r) {
            $r['online'] = is_file($onlineDir . '/agent_' . $r['tenant_id'] . '_' . $r['id'] . '.json') ? 1 : 0;
        }
        unset($r);

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'list' => $rows, 'total' => (int)$total, 'page' => $page, 'size' => $size
        ]]);
    }

    /**
     * 坐席实时在线状态（仅返回在线/离线 + work_status）
     */
    public function onlineStatus(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);
        $rows = Db::query(
            "SELECT id, username, real_name, work_status, last_login_at
             FROM kefu_employee
             WHERE tenant_id = :t ORDER BY id",
            [':t' => $tenantId]
        );
        $onlineDir = runtime_path('push') . '/online';
        $onlineCount = 0;
        foreach ($rows as &$r) {
            $r['online'] = is_file($onlineDir . '/agent_' . $tenantId . '_' . $r['id'] . '.json') ? 1 : 0;
            $r['work_status'] = $r['work_status'] ?: 'offline';
            if ($r['online'] && $r['work_status'] !== 'offline') $onlineCount++;
        }
        unset($r);
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'list' => $rows,
            'online_count' => $onlineCount,
            'total_count' => count($rows),
        ]]);
    }

    /**
     * 创建员工（必填 username/password/real_name + 可选 dept_id/role_id）
     */
    public function create(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $username = trim($request->post('username', ''));
        $password = $request->post('password', '');
        $realName = trim($request->post('real_name', ''));
        if (!$username || !$password || !$realName) {
            return json(['code' => 400, 'msg' => '用户名/密码/姓名必填']);
        }
        Db::setTenantId($tenantId);

        // 检查重复
        $exists = Db::value(
            "SELECT COUNT(*) FROM kefu_employee WHERE tenant_id = :t AND username = :u",
            [':t' => $tenantId, ':u' => $username]
        );
        if ($exists > 0) return json(['code' => 400, 'msg' => '用户名已存在']);

        // 校验部门
        $deptId = intval($request->post('dept_id', 0));
        if ($deptId > 0) {
            $deptOk = Db::value(
                "SELECT COUNT(*) FROM kefu_dept WHERE tenant_id = :t AND id = :d",
                [':t' => $tenantId, ':d' => $deptId]
            );
            if ($deptOk == 0) return json(['code' => 400, 'msg' => '所选部门不存在']);
        }

        // 校验角色
        $roleId = intval($request->post('role_id', 0));
        if ($roleId > 0) {
            $roleOk = Db::value(
                "SELECT COUNT(*) FROM kefu_role WHERE tenant_id = :t AND id = :r",
                [':t' => $tenantId, ':r' => $roleId]
            );
            if ($roleOk == 0) return json(['code' => 400, 'msg' => '所选角色不存在']);
        }

        $id = Db::insert('kefu_employee', [
            'tenant_id'    => $tenantId,
            'username'     => $username,
            'password'     => password_hash($password, PASSWORD_DEFAULT),
            'real_name'    => $realName,
            'nickname'     => trim($request->post('nickname', '')),
            'employee_no'  => $request->post('employee_no', ''),
            'email'        => $request->post('email', ''),
            'phone'        => $request->post('phone', ''),
            'dept_id'      => $deptId,
            'role_id'      => $roleId,
            'max_sessions' => intval($request->post('max_sessions', 5)),
            'skill_level'  => intval($request->post('skill_level', 1)),
            'status'       => 1,
        ]);

        return json(['code' => 0, 'msg' => '创建成功', 'data' => ['id' => $id]]);
    }

    /**
     * 更新员工（部门/角色可改）
     */
    public function update(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id required']);

        // 防止操作自己（禁用/改密码/改部门/改角色）
        $selfId = intval($request->employee_id ?? 0);
        if ($selfId > 0 && $selfId === $id) {
            $forbidden = ['status', 'password', 'dept_id', 'role_id'];
            foreach ($forbidden as $f) {
                if ($request->post($f) !== null) {
                    return json(['code' => 400, 'msg' => '不能修改自己的' . ($f === 'status' ? '状态' : ($f === 'password' ? '密码' : ($f === 'dept_id' ? '部门' : '角色')))]);
                }
            }
        }

        Db::setTenantId($tenantId);

        $data = [];
        foreach (['real_name','nickname','employee_no','email','phone','avatar'] as $f) {
            if ($request->post($f) !== null) $data[$f] = trim($request->post($f, ''));
        }
        foreach (['dept_id','role_id','max_sessions','skill_level','status'] as $f) {
            if ($request->post($f) !== null) $data[$f] = intval($request->post($f, 0));
        }
        if ($request->post('password') !== null && $request->post('password')) {
            $data['password'] = password_hash($request->post('password'), PASSWORD_DEFAULT);
        }

        if (empty($data)) return json(['code' => 400, 'msg' => '没有可更新的字段']);

        // 校验部门（若提供）
        if (isset($data['dept_id']) && $data['dept_id'] > 0) {
            $deptOk = Db::value(
                "SELECT COUNT(*) FROM kefu_dept WHERE tenant_id = :t AND id = :d",
                [':t' => $tenantId, ':d' => $data['dept_id']]
            );
            if ($deptOk == 0) {
                $data['dept_id'] = 0;
            }
        }
        // 校验角色（若提供）
        if (isset($data['role_id']) && $data['role_id'] > 0) {
            $roleOk = Db::value(
                "SELECT COUNT(*) FROM kefu_role WHERE tenant_id = :t AND id = :r",
                [':t' => $tenantId, ':r' => $data['role_id']]
            );
            if ($roleOk == 0) {
                $data['role_id'] = 0;
            }
        }

        Db::update('kefu_employee', $data, ['id' => $id]);
        return json(['code' => 0, 'msg' => '更新成功']);
    }

    /**
     * 删除员工（彻底删除，不可恢复）
     */
    public function delete(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id required']);
        if ($id == intval($request->employee_id ?? 0)) {
            return json(['code' => 400, 'msg' => '不能删除自己']);
        }
        Db::setTenantId($tenantId);
        Db::exec("DELETE FROM kefu_employee WHERE tenant_id = :t AND id = :id",
            [':t' => $tenantId, ':id' => $id]);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    /**
     * 重置密码（独立接口，便于前端密码管理弹窗调用）
     */
    public function resetPwd(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        $newPwd = $request->post('password', '');
        if ($id <= 0 || strlen($newPwd) < 6) {
            return json(['code' => 400, 'msg' => '参数错误：密码至少 6 位']);
        }
        Db::setTenantId($tenantId);
        Db::update('kefu_employee', [
            'password' => password_hash($newPwd, PASSWORD_DEFAULT)
        ], ['id' => $id]);
        return json(['code' => 0, 'msg' => '密码已重置']);
    }
}