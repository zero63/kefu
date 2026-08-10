<?php
/**
 * 客服工作台 - 会话管理控制器
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 *
 * 路由（在 config/route.php）：
 *   GET  /api/agent/session/list          会话列表（分页）
 *   POST /api/agent/session/assign        分配客服
 *   POST /api/agent/session/transfer      转接
 *   POST /api/agent/session/close         关闭
 *   GET  /api/agent/history/sessions      历史会话（超时/已关闭）
 *   POST /api/agent/history/reopen        重启历史会话
 */

namespace app\controller\agent;

use support\Request;
use app\service\SessionService;
use app\lib\Db;

class SessionController
{
    /**
     * 兼容 JSON body 与 form-data
     */
    private function jsonBody(Request $request) {
        $body = $request->_body_data ?? [];
        if (empty($body)) {
            $raw = $request->rawBody();
            $body = $raw ? (json_decode($raw, true) ?: []) : [];
        }
        return $body;
    }

    /**
     * GET /api/agent/session/list
     * Query: ?status=active&mine_only=1&page=1&size=20&channel=h5
     */
    public function list(Request $request) {
        $employeeId = intval($request->employee_id ?? 0);
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);

        // 修复：Windows 下 CronWorker 不自动跑定时任务，每次拉取会话列表时"懒清理"超时会话
        // 这是跨平台兜底，保证超管配置的客户无操作超时能真实生效
        try {
            $cfg = Db::value(
                "SELECT config_value FROM kefu_config WHERE tenant_id = :t AND config_key = 'session_timeout_min'",
                [':t' => $tenantId]
            );
            $customerTimeout = max(5, intval($cfg));   // 默认/最小 5 分钟
            $svc0 = new SessionService();
            // 不传客服超时（避免关掉正在思考的客服），只关客户超时
            $svc0->autoCloseTimeoutSessions($tenantId, $customerTimeout, 999999);
        } catch (\Throwable $e) {}

        $params = [
            'status'    => trim($request->get('status', '')),
            'channel'   => trim($request->get('channel', '')),
            'mine_only' => $request->get('mine_only', '0') == '1',
            'page'      => intval($request->get('page', 1)),
            'size'      => intval($request->get('size', 20)),
        ];

        $svc = new SessionService();
        $r = $svc->listSessions($tenantId, $employeeId, $params);
        return json(['code' => 0, 'msg' => 'ok', 'data' => $r]);
    }

    /**
     * POST /api/agent/session/assign
     * Body: { session_id, employee_id }
     */
    public function assign(Request $request) {
        $body = $this->jsonBody($request);
        $sessionId = trim((string)($body['session_id'] ?? $request->post('session_id', '')));
        $employeeId = intval($body['employee_id'] ?? $request->post('employee_id', 0));
        $operatorId = intval($request->employee_id ?? 0);
        if (!$sessionId || !$employeeId) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        Db::setTenantId(intval($request->tenant_id ?? 0));

        $svc = new SessionService();
        return json($svc->assignAgent($sessionId, $employeeId, $operatorId));
    }

    /**
     * POST /api/agent/session/transfer
     * Body: { session_id, to_employee_id, reason }
     */
    public function transfer(Request $request) {
        $body = $this->jsonBody($request);
        $sessionId = trim((string)($body['session_id'] ?? $request->post('session_id', '')));
        $toEmployeeId = intval($body['to_employee_id'] ?? $request->post('to_employee_id', 0));
        $reason = trim((string)($body['reason'] ?? $request->post('reason', '主动转接')));
        $operatorId = intval($request->employee_id ?? 0);
        if (!$sessionId || !$toEmployeeId) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        Db::setTenantId(intval($request->tenant_id ?? 0));

        $svc = new SessionService();
        return json($svc->transferSession($sessionId, $toEmployeeId, $reason, $operatorId));
    }

    /**
     * GET /api/agent/peers/online
     * 获取当前客服所在租户的"可转接"在线客服列表（排除自己）
     */
    public function onlinePeers(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $me = intval($request->employee_id ?? 0);
        Db::setTenantId($tenantId);
        $rows = Db::query(
            "SELECT id, real_name, nickname, avatar, skill_level, work_status, max_sessions,
                    (SELECT COUNT(*) FROM kefu_session
                     WHERE tenant_id = e.tenant_id AND agent_id = e.id
                       AND status = 'active' AND serving_mode = 'human') AS load_count
             FROM kefu_employee e
             WHERE e.tenant_id = :t AND e.status = 1
               AND e.role_id >= 3
               AND e.id <> :me
               AND e.work_status = 'online'
             ORDER BY e.skill_level DESC, e.id ASC",
            [':t' => $tenantId, ':me' => $me]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => $rows]);
    }

    /**
     * POST /api/agent/session/close
     * Body: { session_id, reason }
     */
    public function close(Request $request) {
        $body = $this->jsonBody($request);
        $sessionId = trim((string)($body['session_id'] ?? $request->post('session_id', '')));
        $reason = trim((string)($body['reason'] ?? $request->post('reason', 'resolved')));
        $operatorId = intval($request->employee_id ?? 0);
        if (!$sessionId) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        Db::setTenantId(intval($request->tenant_id ?? 0));

        $svc = new SessionService();
        return json($svc->closeSession($sessionId, $reason, $operatorId));
    }

    /**
     * GET /api/agent/history/sessions
     * Query: ?mine_only=1&page=1&size=20&q=keyword&from=2026-08-01&to=2026-08-05
     * 历史会话：超时关闭、被踢出、转接走的
     */
    public function historyList(Request $request) {
        $employeeId = intval($request->employee_id ?? 0);
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);

        $params = [
            'mine_only' => $request->get('mine_only', '0') == '1',
            'page'      => intval($request->get('page', 1)),
            'size'      => min(100, max(1, intval($request->get('size', 20)))),
            'q'         => trim((string)$request->get('q', '')),
            'from'      => trim((string)$request->get('from', '')),
            'to'        => trim((string)$request->get('to', '')),
            'channel'   => trim((string)$request->get('channel', '')),
            'reopenable'=> $request->get('reopenable', '1') == '1',
        ];

        $svc = new SessionService();
        $r = $svc->listHistorySessions($tenantId, $employeeId, $params);
        return json(['code' => 0, 'msg' => 'ok', 'data' => $r]);
    }

    /**
     * POST /api/agent/history/reopen
     * Body: { session_id }
     * 重新接管一个历史会话（如果客户还在，可继续对话）
     */
    public function reopen(Request $request) {
        $body = $this->jsonBody($request);
        $sessionId = trim((string)($body['session_id'] ?? $request->post('session_id', '')));
        $operatorId = intval($request->employee_id ?? 0);
        $tenantId = intval($request->tenant_id ?? 0);
        if (!$sessionId) {
            return json(['code' => 400, 'msg' => 'session_id required']);
        }
        Db::setTenantId($tenantId);

        $svc = new SessionService();
        return json($svc->reopenSession($tenantId, $sessionId, $operatorId));
    }
}