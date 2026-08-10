<?php
/**
 * 管理后台 - 部门管理
 * 功能：树形部门CRUD，支持父子层级
 * 作者：kefu 开发团队
 */
namespace app\controller\admin;

use support\Request;
use app\lib\Db;

class DeptController
{
    /**
     * 获取部门列表（平铺返回，前端组装树）
     */
    public function list(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);
        $rows = Db::query(
            "SELECT id, tenant_id, parent_id, dept_name, dept_code, sort, leader_id, created_at,
                    (SELECT COUNT(*) FROM kefu_employee e WHERE e.tenant_id = d.tenant_id AND e.dept_id = d.id) AS emp_count
             FROM kefu_dept d WHERE tenant_id = :t ORDER BY parent_id ASC, sort ASC, id ASC",
            [':t' => $tenantId]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    /**
     * 部门树（含每个节点下的员工数）
     * 用于"按部门分配员工"等场景
     */
    public function tree(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);
        // 先查部门（不含子查询），再单独统计员工数（避免 PDO 重复占位符问题）
        $rows = Db::query(
            "SELECT id, parent_id, dept_name, dept_code, sort
             FROM kefu_dept WHERE tenant_id = :t ORDER BY parent_id ASC, sort ASC, id ASC",
            [':t' => $tenantId]
        );
        // 批量统计每个部门的员工数
        $counts = [];
        if (!empty($rows)) {
            $deptIds = array_map(function($r) { return (int)$r['id']; }, $rows);
            $placeholders = [];
            $bind = [':t' => $tenantId];
            foreach ($deptIds as $i => $did) {
                $key = ':d' . $i;
                $placeholders[] = $key;
                $bind[$key] = $did;
            }
            $phStr = implode(',', $placeholders);
            $cntRows = Db::query(
                "SELECT dept_id, COUNT(*) AS cnt FROM kefu_employee
                 WHERE tenant_id = :t AND dept_id IN ($phStr)
                 GROUP BY dept_id",
                $bind
            );
            foreach ($cntRows as $c) {
                $counts[(int)$c['dept_id']] = (int)$c['cnt'];
            }
        }
        foreach ($rows as &$r) {
            $r['emp_count'] = isset($counts[(int)$r['id']]) ? $counts[(int)$r['id']] : 0;
        }
        unset($r);

        // 组装树
        $byId = [];
        foreach ($rows as $r) { $byId[$r['id']] = $r; $byId[$r['id']]['children'] = []; }
        $roots = [];
        foreach ($byId as $id => &$node) {
            if ($node['parent_id'] && isset($byId[$node['parent_id']])) {
                $byId[$node['parent_id']]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['tree' => $roots, 'flat' => $rows]]);
    }

    /**
     * 创建部门
     */
    public function create(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $deptName = trim($request->post('dept_name', ''));
        if ($deptName === '') {
            return json(['code' => 400, 'msg' => '部门名称必填']);
        }
        Db::setTenantId($tenantId);

        // 同租户下不允许重名
        $exists = Db::value(
            "SELECT COUNT(*) FROM kefu_dept WHERE tenant_id = :t AND dept_name = :n",
            [':t' => $tenantId, ':n' => $deptName]
        );
        if ($exists > 0) {
            return json(['code' => 400, 'msg' => '部门名称已存在']);
        }

        $id = Db::insert('kefu_dept', [
            'tenant_id'  => $tenantId,
            'parent_id'  => intval($request->post('parent_id', 0)),
            'dept_name'  => $deptName,
            'dept_code'  => trim($request->post('dept_code', '')),
            'sort'       => intval($request->post('sort', 0)),
            'leader_id'  => intval($request->post('leader_id', 0)) ?: null,
        ]);

        return json(['code' => 0, 'msg' => '创建成功', 'data' => ['id' => $id]]);
    }

    /**
     * 更新部门
     */
    public function update(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) {
            return json(['code' => 400, 'msg' => 'id必填']);
        }
        Db::setTenantId($tenantId);

        $data = [];
        if ($request->post('dept_name') !== null) {
            $data['dept_name'] = trim($request->post('dept_name'));
            if ($data['dept_name'] === '') {
                return json(['code' => 400, 'msg' => '部门名称不能为空']);
            }
        }
        foreach (['parent_id', 'sort', 'leader_id', 'dept_code'] as $f) {
            if ($request->post($f) !== null) {
                $val = $request->post($f);
                $data[$f] = in_array($f, ['dept_code']) ? trim($val) : (intval($val) ?: null);
            }
        }

        if (empty($data)) {
            return json(['code' => 400, 'msg' => '没有可更新的字段']);
        }

        $affected = Db::update('kefu_dept', $data, ['id' => $id]);
        if ($affected === 0) {
            // 区分"无变化"和"记录不存在"
            $exists = Db::value(
                "SELECT COUNT(*) FROM kefu_dept WHERE tenant_id = :t AND id = :i",
                [':t' => $tenantId, ':i' => $id]
            );
            if ($exists == 0) {
                return json(['code' => 404, 'msg' => '部门不存在']);
            }
        }
        return json(['code' => 0, 'msg' => '更新成功']);
    }

    /**
     * 删除部门（存在子部门或员工时不允许删除）
     */
    public function delete(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) {
            return json(['code' => 400, 'msg' => 'id必填']);
        }
        Db::setTenantId($tenantId);

        // 检查子部门
        $childCount = Db::value(
            "SELECT COUNT(*) FROM kefu_dept WHERE tenant_id = :t AND parent_id = :p",
            [':t' => $tenantId, ':p' => $id]
        );
        if ($childCount > 0) {
            return json(['code' => 400, 'msg' => '存在子部门，请先删除子部门']);
        }

        // 检查部门员工
        $empCount = Db::value(
            "SELECT COUNT(*) FROM kefu_employee WHERE tenant_id = :t AND dept_id = :d",
            [':t' => $tenantId, ':d' => $id]
        );
        if ($empCount > 0) {
            return json(['code' => 400, 'msg' => '部门下存在员工，请先调整员工']);
        }

        Db::delete('kefu_dept', ['id' => $id]);
        return json(['code' => 0, 'msg' => '删除成功']);
    }
}