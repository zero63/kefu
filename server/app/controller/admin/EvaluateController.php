<?php
/**
 * 评价 Controller
 *
 * 路由：
 *   POST /api/admin/evaluate/session  提交会话评价（访客端，公开）
 *   POST /api/admin/evaluate/ticket   提交工单评价（访客端，公开）
 *   GET  /api/admin/evaluate/stats    满意度统计（需鉴权）
 *   GET  /api/admin/evaluate/list     评价列表（需鉴权）
 */

namespace app\controller\admin;

use support\Request;
use app\service\EvaluateService;

class EvaluateController
{
    public function session(Request $request) {
        $tenantId = intval($request->post('tenant_id', 1));
        $sessionId = trim($request->post('session_id', ''));
        $score = intval($request->post('score', 0));
        $comment = trim($request->post('comment', ''));
        $customerId = trim($request->post('customer_id', ''));
        $ext = $request->post('ext', []);

        $svc = new EvaluateService();
        return json($svc->submitSessionEvaluate($tenantId, $sessionId, $score, $comment, $customerId, $ext));
    }

    public function ticket(Request $request) {
        $tenantId = intval($request->post('tenant_id', 1));
        $ticketId = intval($request->post('ticket_id', 0));
        $score = intval($request->post('score', 0));
        $comment = trim($request->post('comment', ''));
        $customerId = trim($request->post('customer_id', ''));

        $svc = new EvaluateService();
        return json($svc->submitTicketEvaluate($tenantId, $ticketId, $score, $comment, $customerId));
    }

    public function stats(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $params = [
            'start_date' => $request->get('start_date', ''),
            'end_date'   => $request->get('end_date', ''),
            'agent_id'   => $request->get('agent_id', 0),
        ];
        $svc = new EvaluateService();
        return json(['code' => 0, 'msg' => 'ok', 'data' => $svc->stats($tenantId, $params)]);
    }

    public function list(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $params = [
            'agent_id' => $request->get('agent_id', ''),
            'level'    => $request->get('level', ''),
            'keyword'  => $request->get('keyword', ''),
            'page'     => intval($request->get('page', 1)),
            'size'     => intval($request->get('size', 20)),
        ];
        $svc = new EvaluateService();
        return json(['code' => 0, 'msg' => 'ok', 'data' => $svc->listEvaluates($tenantId, $params)]);
    }
}