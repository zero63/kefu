<?php
/**
 * 客服侧 - 实时数据看板
 * author: kefu dev team
 */
namespace app\controller\agent;
use support\Request;
use app\lib\Db;
use app\lib\ConnectionManager;

class DashboardController {
    /**
     * GET /api/agent/dashboard/realtime
     */
    public function realtime(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $employeeId = intval($request->employee_id ?? 0);
        Db::setTenantId($tenantId);

        // 在线客服数（有 active 会话）
        $online = Db::value(
            "SELECT COUNT(DISTINCT agent_id) FROM kefu_session
             WHERE tenant_id = :t AND agent_id IS NOT NULL AND status = 'active'",
            [':t' => $tenantId]
        );

        $active = Db::value(
            "SELECT COUNT(*) FROM kefu_session WHERE tenant_id = :t AND status = 'active'",
            [':t' => $tenantId]
        );
        $waiting = Db::value(
            "SELECT COUNT(*) FROM kefu_session WHERE tenant_id = :t AND status = 'waiting'",
            [':t' => $tenantId]
        );

        $today = Db::value(
            "SELECT COUNT(*) FROM kefu_session
             WHERE tenant_id = :t AND DATE(created_at) = CURDATE()",
            [':t' => $tenantId]
        );

        // 我的会话数
        $my = $employeeId > 0 ? (int)Db::value(
            "SELECT COUNT(*) FROM kefu_session WHERE agent_id = :a AND status IN ('active','waiting')",
            [':a' => $employeeId]
        ) : 0;

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'online_agents'    => (int)$online,
            'active_sessions'  => (int)$active,
            'waiting_sessions' => (int)$waiting,
            'today_sessions'   => (int)$today,
            'my_sessions'      => $my,
            'ws_connections'   => ConnectionManager::stats(),
        ]]);
    }
}