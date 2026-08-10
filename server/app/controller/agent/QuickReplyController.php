<?php
/**
 * 快捷回复管理控制器
 * 作者：kefu 开发团队
 * 创建时间：2026-08-01
 * 说明：
 *   - 客服工作台的快捷回复 CRUD
 *   - 操作 kefu_quick_reply 表
 *   - 路由（在 config/route.php 的 agent 分组）：
 *     GET  /quick-reply/list
 *     POST /quick-reply/create
 *     POST /quick-reply/update
 *     POST /quick-reply/delete
 */

namespace app\controller\agent;

use support\Request;
use app\lib\Db;

class QuickReplyController
{
    /**
     * 快捷回复列表
     * GET /api/agent/quick-reply/list
     * Query: ?category=common|personal&page=1&size=50
     */
    public function list(Request $request) {
        $employeeId = intval($request->employee_id ?? 0);
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);

        $category = trim($request->get('category', ''));
        $page = max(1, intval($request->get('page', 1)));
        $size = min(100, max(1, intval($request->get('size', 50))));
        $offset = ($page - 1) * $size;

        // 构建查询条件：公共库（common）所有人可见，个人库（personal）仅本人可见
        $sql = "SELECT * FROM kefu_quick_reply WHERE tenant_id = :tid";
        $params = [':tid' => $tenantId];

        if ($category === 'common') {
            $sql .= " AND category = 'common'";
        } elseif ($category === 'personal') {
            $sql .= " AND category = 'personal' AND owner_id = :eid";
            $params[':eid'] = $employeeId;
        } else {
            // 查全部：公共库 + 本人个人库
            $sql .= " AND (category = 'common' OR (category = 'personal' AND owner_id = :eid))";
            $params[':eid'] = $employeeId;
        }

        // 总数
        $countSql = "SELECT COUNT(*) FROM ($sql) AS tmp";
        $total = intval(Db::value($countSql, $params));

        $sql .= " ORDER BY sort ASC, id DESC LIMIT $offset, $size";
        $rows = Db::query($sql, $params);

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'list'  => $rows,
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
        ]]);
    }

    /**
     * 创建快捷回复
     * POST /api/agent/quick-reply/create
     * Body: { title, content, shortcut?, category?, sort? }
     */
    public function create(Request $request) {
        $employeeId = intval($request->employee_id ?? 0);
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);

        $title = trim($request->post('title', ''));
        $content = trim($request->post('content', ''));
        if (empty($title) || empty($content)) {
            return json(['code' => 400, 'msg' => '标题和内容不能为空']);
        }

        $category = $request->post('category', 'common');
        if (!in_array($category, ['common', 'personal'])) {
            $category = 'common';
        }

        $data = [
            'tenant_id' => $tenantId,
            'category'  => $category,
            'owner_id'  => $category === 'personal' ? $employeeId : null,
            'title'     => $title,
            'shortcut'  => $request->post('shortcut', '') ?: null,
            'content'   => $content,
            'sort'      => intval($request->post('sort', 0)),
            'use_count' => 0,
        ];

        $id = Db::insert('kefu_quick_reply', $data);

        return json(['code' => 0, 'msg' => '创建成功', 'data' => ['id' => $id]]);
    }

    /**
     * 更新快捷回复
     * POST /api/agent/quick-reply/update
     * Body: { id, title?, content?, shortcut?, sort?, category? }
     */
    public function update(Request $request) {
        $employeeId = intval($request->employee_id ?? 0);
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);

        $id = intval($request->post('id', 0));
        if (!$id) {
            return json(['code' => 400, 'msg' => 'id 不能为空']);
        }

        // 校验权限：只能修改本租户的，个人库只能改自己的
        $existing = Db::find(
            "SELECT * FROM kefu_quick_reply WHERE id = :id AND tenant_id = :tid",
            [':id' => $id, ':tid' => $tenantId]
        );
        if (!$existing) {
            return json(['code' => 404, 'msg' => '快捷回复不存在']);
        }
        if ($existing['category'] === 'personal' && intval($existing['owner_id']) !== $employeeId) {
            return json(['code' => 403, 'msg' => '无权修改他人的个人快捷回复']);
        }

        $data = [];
        if ($request->post('title') !== null) {
            $title = trim($request->post('title'));
            if (!empty($title)) $data['title'] = $title;
        }
        if ($request->post('content') !== null) {
            $content = trim($request->post('content'));
            if (!empty($content)) $data['content'] = $content;
        }
        if ($request->post('shortcut') !== null) {
            $data['shortcut'] = $request->post('shortcut') ?: null;
        }
        if ($request->post('sort') !== null) {
            $data['sort'] = intval($request->post('sort'));
        }

        if (empty($data)) {
            return json(['code' => 400, 'msg' => '没有需要更新的字段']);
        }

        $affected = Db::update('kefu_quick_reply', $data, ['id' => $id, 'tenant_id' => $tenantId]);

        return json(['code' => 0, 'msg' => '更新成功', 'data' => ['affected' => $affected]]);
    }

    /**
     * 删除快捷回复
     * POST /api/agent/quick-reply/delete
     * Body: { id }
     */
    public function delete(Request $request) {
        $employeeId = intval($request->employee_id ?? 0);
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);

        $id = intval($request->post('id', 0));
        if (!$id) {
            return json(['code' => 400, 'msg' => 'id 不能为空']);
        }

        // 校验权限
        $existing = Db::find(
            "SELECT * FROM kefu_quick_reply WHERE id = :id AND tenant_id = :tid",
            [':id' => $id, ':tid' => $tenantId]
        );
        if (!$existing) {
            return json(['code' => 404, 'msg' => '快捷回复不存在']);
        }
        if ($existing['category'] === 'personal' && intval($existing['owner_id']) !== $employeeId) {
            return json(['code' => 403, 'msg' => '无权删除他人的个人快捷回复']);
        }

        $affected = Db::exec(
            "DELETE FROM kefu_quick_reply WHERE id = :id AND tenant_id = :tid",
            [':id' => $id, ':tid' => $tenantId]
        );

        return json(['code' => 0, 'msg' => '删除成功', 'data' => ['affected' => $affected]]);
    }
}
