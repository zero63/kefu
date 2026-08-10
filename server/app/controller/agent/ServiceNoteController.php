<?php
/**
 * 服务小记控制器
 * 作者：kefu 开发团队
 * 创建时间：2026-08-01
 * 说明：
 *   - save：保存服务小记（新增或更新）
 *   - get：查询会话的服务小记
 *   - 操作 kefu_service_note 表
 *   - 路由（在 config/route.php 的 agent 分组）：
 *     POST /service-note/save
 *     GET  /service-note/get
 */

namespace app\controller\agent;

use support\Request;
use app\lib\Db;

class ServiceNoteController
{
    /**
     * 保存服务小记
     * POST /api/agent/service-note/save
     * Body: { session_id, category_id?, is_resolved?, note?, custom_fields? }
     */
    public function save(Request $request) {
        $employeeId = intval($request->employee_id ?? 0);
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);

        $sessionId = trim($request->post('session_id', ''));
        if (empty($sessionId)) {
            return json(['code' => 400, 'msg' => 'session_id 不能为空']);
        }

        // 检查是否已有服务小记（同一会话只保留一条，有则更新）
        $existing = Db::find(
            "SELECT id FROM kefu_service_note WHERE session_id = :s AND tenant_id = :tid LIMIT 1",
            [':s' => $sessionId, ':tid' => $tenantId]
        );

        $data = [
            'category_id'   => $request->post('category_id') !== null ? intval($request->post('category_id')) : null,
            'is_resolved'   => $request->post('is_resolved', 1) ? 1 : 0,
            'note'          => $request->post('note', '') ?: null,
            'custom_fields' => $request->post('custom_fields', '') ?: null,
        ];

        if ($existing) {
            // 更新已有记录
            Db::update('kefu_service_note', $data, [
                'id'        => $existing['id'],
                'tenant_id' => $tenantId,
            ]);
            return json(['code' => 0, 'msg' => '更新成功', 'data' => ['id' => $existing['id']]]);
        }

        // 新增记录
        $data['tenant_id'] = $tenantId;
        $data['session_id'] = $sessionId;
        $data['agent_id'] = $employeeId;

        $id = Db::insert('kefu_service_note', $data);

        return json(['code' => 0, 'msg' => '保存成功', 'data' => ['id' => $id]]);
    }

    /**
     * 查询服务小记
     * GET /api/agent/service-note/get
     * Query: ?session_id=xxx
     */
    public function get(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);

        $sessionId = trim($request->get('session_id', ''));
        if (empty($sessionId)) {
            return json(['code' => 400, 'msg' => 'session_id 不能为空']);
        }

        $row = Db::find(
            "SELECT n.*, e.real_name AS agent_name
             FROM kefu_service_note n
             LEFT JOIN kefu_employee e ON e.id = n.agent_id
             WHERE n.session_id = :s AND n.tenant_id = :tid
             ORDER BY n.id DESC LIMIT 1",
            [':s' => $sessionId, ':tid' => $tenantId]
        );

        if (!$row) {
            return json(['code' => 0, 'msg' => 'ok', 'data' => null]);
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => $row]);
    }
}
