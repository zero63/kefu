<?php
/**
 * 管理后台 - 权限分配
 * 功能：获取权限树、获取某角色已分配权限、保存角色权限
 * 作者：kefu 开发团队
 */
namespace app\controller\admin;

use support\Request;
use app\lib\Db;
use app\lib\Logger;

class PermissionController
{
    /**
     * 新建/编辑权限（upsert）
     * - 保存到 kefu_permission
     * - 如果是菜单类型（type=menu 且 path 非空），自动给所有非超管角色授权
     */
    public function save(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        $code = trim($request->post('permission_code', ''));
        $name = trim($request->post('permission_name', ''));
        $type = trim($request->post('type', 'menu'));
        $parentId = intval($request->post('parent_id', 0));
        $path = trim($request->post('path', ''));
        $icon = trim($request->post('icon', ''));
        $sort = intval($request->post('sort', 0));
        if ($code === '' || $name === '') {
            return json(['code' => 400, 'msg' => '权限编码和名称必填']);
        }
        Db::setTenantId($tenantId);
        try {
            if ($id > 0) {
                Db::exec(
                    "UPDATE kefu_permission SET permission_code=:c, permission_name=:n, type=:t,
                     parent_id=:p, path=:p_path, icon=:ic, sort=:s WHERE id=:id",
                    [':c'=>$code, ':n'=>$name, ':t'=>$type, ':p'=>$parentId,
                     ':p_path'=>$path, ':ic'=>$icon, ':s'=>$sort, ':id'=>$id]
                );
            } else {
                $exists = Db::value("SELECT id FROM kefu_permission WHERE permission_code = :c", [':c' => $code]);
                if ($exists) return json(['code' => 400, 'msg' => '权限编码已存在']);
                Db::exec(
                    "INSERT INTO kefu_permission (permission_code, permission_name, type, parent_id, path, icon, sort)
                     VALUES (:c,:n,:t,:p,:p_path,:ic,:s)",
                    [':c'=>$code, ':n'=>$name, ':t'=>$type, ':p'=>$parentId,
                     ':p_path'=>$path, ':ic'=>$icon, ':s'=>$sort]
                );
                $id = Db::value("SELECT LAST_INSERT_ID() AS id");
            }
            $autoCount = 0;
            if ($type === 'menu' && $path !== '') {
                $roles = Db::query("SELECT id, tenant_id FROM kefu_role WHERE id >= 3");
                foreach ($roles as $r) {
                    Db::setTenantId($r['tenant_id']);
                    $exists = Db::value(
                        "SELECT id FROM kefu_role_permission WHERE tenant_id=:t AND role_id=:rid AND permission_id=:pid",
                        [':t'=>$r['tenant_id'], ':rid'=>$r['id'], ':pid'=>$id]
                    );
                    if (!$exists) {
                        Db::insert('kefu_role_permission', [
                            'tenant_id' => $r['tenant_id'],
                            'role_id' => $r['id'],
                            'permission_id' => $id,
                            'created_at' => date('Y-m-d H:i:s'),
                        ]);
                        $autoCount++;
                    }
                }
                Db::setTenantId($tenantId);
            }
            return json(['code' => 0, 'msg' => '已保存', 'data' => ['id' => $id, 'auto_assigned' => $autoCount]]);
        } catch (\Throwable $e) {
            Logger::error('save_permission_failed', ['err' => $e->getMessage()]);
            return json(['code' => 500, 'msg' => '保存失败：' . $e->getMessage()]);
        }
    }

    /**
     * 删除权限（同时清理关联的 role_permission）
     */
    public function delete(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id 必填']);
        Db::setTenantId($tenantId);
        try {
            Db::exec("DELETE FROM kefu_role_permission WHERE permission_id = :id", [':id' => $id]);
            Db::exec("DELETE FROM kefu_permission WHERE id = :id", [':id' => $id]);
            return json(['code' => 0, 'msg' => '已删除']);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '删除失败：' . $e->getMessage()]);
        }
    }

    /**
     * 权限树（所有权限，按 parent_id 组织）
     */
    public function tree(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);

        $rows = Db::query(
            "SELECT id, parent_id, permission_code, permission_name, type, path, sort
             FROM kefu_permission ORDER BY parent_id, sort, id"
        );
        // 组织成树形结构
        $byId = [];
        foreach ($rows as $r) {
            $r['children'] = [];
            $byId[$r['id']] = $r;
        }
        $tree = [];
        foreach ($byId as &$node) {
            if ($node['parent_id'] == 0) {
                $tree[] = &$node;
            } elseif (isset($byId[$node['parent_id']])) {
                $byId[$node['parent_id']]['children'][] = &$node;
            } else {
                $tree[] = &$node;
            }
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $tree]]);
    }

    /**
     * 获取某角色已分配的权限 id 列表
     */
    public function getRolePermissions(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $roleId = intval($request->get('role_id', 0));
        if ($roleId <= 0) return json(['code' => 400, 'msg' => 'role_id 必填']);
        Db::setTenantId($tenantId);

        $ids = Db::query(
            "SELECT permission_id FROM kefu_role_permission WHERE tenant_id = :t AND role_id = :r",
            [':t' => $tenantId, ':r' => $roleId]
        );
        $idList = array_map(function ($r) { return (int)$r['permission_id']; }, $ids);
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['permission_ids' => $idList]]);
    }

    /**
     * 保存角色权限（覆盖式：先删后插）
     */
    public function saveRolePermissions(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $roleId = intval($request->post('role_id', 0));
        $permIds = $request->post('permission_ids', []);
        if ($roleId <= 0) return json(['code' => 400, 'msg' => 'role_id 必填']);
        if (!is_array($permIds)) $permIds = [];
        Db::setTenantId($tenantId);

        // 校验角色存在
        $roleOk = Db::value(
            "SELECT COUNT(*) FROM kefu_role WHERE tenant_id = :t AND id = :r",
            [':t' => $tenantId, ':r' => $roleId]
        );
        if ($roleOk == 0) return json(['code' => 400, 'msg' => '角色不存在']);

        try {
            Db::exec(
                "DELETE FROM kefu_role_permission WHERE tenant_id = :t AND role_id = :r",
                [':t' => $tenantId, ':r' => $roleId]
            );
            $now = date('Y-m-d H:i:s');
            foreach ($permIds as $pid) {
                $pid = intval($pid);
                if ($pid <= 0) continue;
                Db::insert('kefu_role_permission', [
                    'tenant_id'     => $tenantId,
                    'role_id'       => $roleId,
                    'permission_id' => $pid,
                    'created_at'    => $now,
                ]);
            }
        } catch (\Throwable $e) {
            Logger::error('保存角色权限失败', ['err' => $e->getMessage(), 'role' => $roleId]);
            return json(['code' => 500, 'msg' => '保存失败：' . $e->getMessage()]);
        }
        return json(['code' => 0, 'msg' => '已保存', 'data' => ['count' => count($permIds)]]);
    }

    /**
     * 当前用户（登录者）的权限码列表（供工作台 UI 渲染）
     */
    public function myPermissions(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $roleId = intval($request->role_id ?? 0);
        // 修复：仅 role_id=1（超级管理员）返回 all=true 不过滤；
        // 其余角色（含主管 role_id=2、客服 role_id=3）一律按 kefu_role_permission 表实际配置返回，
        // 这样超管后台对角色权限的增删才会真实生效到客服工作台菜单
        if ($roleId === 1 || $roleId <= 0) {
            return json(['code' => 0, 'msg' => 'ok', 'data' => ['permissions' => [], 'all' => true, 'role_id' => $roleId]]);
        }
        Db::setTenantId($tenantId);
        $rows = Db::query(
            "SELECT p.permission_code FROM kefu_role_permission rp
             JOIN kefu_permission p ON p.id = rp.permission_id
             WHERE rp.tenant_id = :t AND rp.role_id = :r",
            [':t' => $tenantId, ':r' => $roleId]
        );
        $codes = array_map(function ($r) { return $r['permission_code']; }, $rows);
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['permissions' => $codes, 'all' => false, 'role_id' => $roleId]]);
    }

    /**
     * 当前用户可访问的菜单树（用于客服工作台 / 超管侧边栏渲染）
     * 只返回 type='menu' 且 path 非空的节点
     * 超管（role_id=1）返回所有菜单
     */
    public function myMenu(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $roleId = intval($request->role_id ?? 0);
        Db::setTenantId($tenantId);
        if ($roleId <= 1) {
            // 超管：全部菜单
            $rows = Db::query(
                "SELECT id, parent_id, permission_code, permission_name, type, path, icon, sort
                 FROM kefu_permission WHERE type='menu' AND path IS NOT NULL AND path <> ''
                 ORDER BY parent_id, sort, id"
            );
        } else {
            $rows = Db::query(
                "SELECT p.id, p.parent_id, p.permission_code, p.permission_name, p.type, p.path, p.icon, p.sort
                 FROM kefu_permission p
                 INNER JOIN kefu_role_permission rp ON rp.permission_id = p.id
                 WHERE rp.tenant_id = :t AND rp.role_id = :r
                   AND p.type = 'menu' AND p.path IS NOT NULL AND p.path <> ''
                 ORDER BY p.parent_id, p.sort, p.id",
                [':t' => $tenantId, ':r' => $roleId]
            );
        }
        // 过滤空 path
        $rows = array_values(array_filter($rows, function ($r) {
            return !empty($r['path']);
        }));
        // 树形
        $byId = [];
        foreach ($rows as $r) {
            $r['children'] = [];
            $byId[$r['id']] = $r;
        }
        $tree = [];
        foreach ($byId as &$node) {
            if ((int)$node['parent_id'] === 0) {
                $tree[] = &$node;
            } elseif (isset($byId[$node['parent_id']])) {
                $byId[$node['parent_id']]['children'][] = &$node;
            } else {
                $tree[] = &$node;
            }
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $tree]]);
    }
}