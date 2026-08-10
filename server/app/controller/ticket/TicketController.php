<?php
/**
 * 工单管理 Controller
 *
 * 路由在 route.php（/api/ticket/*）：
 *   POST /api/ticket/create         客服手动开单
 *   POST /api/ticket/from-session   会话一键升级
 *   GET  /api/ticket/list           工单列表
 *   GET  /api/ticket/detail         工单详情
 *   POST /api/ticket/assign         分派客服
 *   POST /api/ticket/reply          回复
 *   POST /api/ticket/resolve        解决
 *   POST /api/ticket/close          关闭
 *   POST /api/ticket/reopen         重开
 */

namespace app\controller\ticket;

use support\Request;
use app\service\TicketService;
use app\lib\Db;

class TicketController
{
    public function create(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $operatorId = intval($request->employee_id ?? 0);

        $params = [
            'customer_id'   => $request->post('customer_id', 0),
            'customer_name' => trim((string)$request->post('customer_name', '')),  // 修复：补传，让后端按名匹配/建档
            'customer_phone'=> trim((string)$request->post('customer_phone', '')),
            'session_id'    => $request->post('session_id', ''),
            'title'         => $request->post('title', ''),
            'content'       => $request->post('content', ''),
            'category'      => $request->post('category', ''),
            'priority'      => $request->post('priority', 2),
            'custom_fields' => $request->post('custom_fields', []),
        ];

        $svc = new TicketService();
        return json($svc->create($tenantId, $params, $operatorId));
    }

    public function fromSession(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $operatorId = intval($request->employee_id ?? 0);
        $sessionId = trim($request->post('session_id', ''));
        $reason = trim($request->post('reason', ''));
        if (empty($sessionId)) return json(['code' => 400, 'msg' => 'session_id required']);

        $svc = new TicketService();
        return json($svc->createFromSession($tenantId, $sessionId, $operatorId, $reason));
    }

    public function list(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $params = [
            'status'      => $request->get('status', ''),
            'priority'    => $request->get('priority', ''),
            'assigned_to' => $request->get('assigned_to', ''),
            'category'    => $request->get('category', ''),
            'keyword'     => $request->get('keyword', ''),
            'page'        => intval($request->get('page', 1)),
            'size'        => intval($request->get('size', 20)),
        ];

        $svc = new TicketService();
        return json(['code' => 0, 'msg' => 'ok', 'data' => $svc->listTickets($tenantId, $params)]);
    }

    public function detail(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $ticketId = intval($request->get('id', 0));
        if ($ticketId <= 0) return json(['code' => 400, 'msg' => 'id required']);

        $svc = new TicketService();
        $data = $svc->detail($tenantId, $ticketId);
        if (!$data) return json(['code' => 404, 'msg' => '工单不存在']);
        return json(['code' => 0, 'msg' => 'ok', 'data' => $data]);
    }

    public function assign(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $operatorId = intval($request->employee_id ?? 0);
        $ticketId = intval($request->post('ticket_id', 0));
        $employeeId = intval($request->post('employee_id', 0));
        if ($ticketId <= 0 || $employeeId <= 0) return json(['code' => 400, 'msg' => '参数错误']);

        $svc = new TicketService();
        return json($svc->assign($tenantId, $ticketId, $employeeId, $operatorId));
    }

    public function reply(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $operatorId = intval($request->employee_id ?? 0);
        $ticketId = intval($request->post('ticket_id', 0));
        $content = $request->post('content', '');
        $isInternal = intval($request->post('is_internal', 0));
        if ($ticketId <= 0 || $content === '') return json(['code' => 400, 'msg' => '参数错误']);

        $svc = new TicketService();
        return json($svc->reply($tenantId, $ticketId, $content, $operatorId, $isInternal));
    }

    public function resolve(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $operatorId = intval($request->employee_id ?? 0);
        $ticketId = intval($request->post('ticket_id', 0));
        $solution = $request->post('solution', '');
        if ($ticketId <= 0) return json(['code' => 400, 'msg' => '参数错误']);

        $svc = new TicketService();
        return json($svc->resolve($tenantId, $ticketId, $operatorId, $solution));
    }

    public function close(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $operatorId = intval($request->employee_id ?? 0);
        $ticketId = intval($request->post('ticket_id', 0));
        $reason = $request->post('reason', '');
        if ($ticketId <= 0) return json(['code' => 400, 'msg' => '参数错误']);

        $svc = new TicketService();
        return json($svc->close($tenantId, $ticketId, $operatorId, $reason));
    }

    public function reopen(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $operatorId = intval($request->employee_id ?? 0);
        $ticketId = intval($request->post('ticket_id', 0));
        $reason = $request->post('reason', '');
        if ($ticketId <= 0) return json(['code' => 400, 'msg' => '参数错误']);

        $svc = new TicketService();
        return json($svc->reopen($tenantId, $ticketId, $operatorId, $reason));
    }
}