<?php
/**
 * 质检 Controller
 */

namespace app\controller\admin;

use support\Request;
use app\service\QualityService;

class QualityController
{
    public function inspect(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $operatorId = intval($request->employee_id ?? 0);
        $sessionId = trim($request->post('session_id', ''));
        if (empty($sessionId)) return json(['code' => 400, 'msg' => 'session_id required']);

        $svc = new QualityService();
        return json($svc->inspectSession($tenantId, $sessionId, $operatorId));
    }

    public function resultList(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $params = [
            'agent_id'      => $request->get('agent_id', ''),
            'review_status' => $request->get('review_status', ''),
            'page'          => intval($request->get('page', 1)),
            'size'          => intval($request->get('size', 20)),
        ];

        $svc = new QualityService();
        return json(['code' => 0, 'msg' => 'ok', 'data' => $svc->listResults($tenantId, $params)]);
    }

    public function stats(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $params = [
            'start_date' => $request->get('start_date', ''),
            'end_date'   => $request->get('end_date', ''),
        ];
        $svc = new QualityService();
        return json(['code' => 0, 'msg' => 'ok', 'data' => $svc->stats($tenantId, $params)]);
    }

    public function ruleList(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $svc = new QualityService();
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $svc->listRules($tenantId)]]);
    }

    public function ruleAdd(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $svc = new QualityService();
        return json($svc->addRule($tenantId, [
            'rule_name'    => $request->post('rule_name', ''),
            'rule_type'    => $request->post('rule_type', ''),
            'rule_config'  => $request->post('rule_config', []),
            'score'        => $request->post('score', 10),
            'level'        => $request->post('level', 'medium'),
        ]));
    }

    public function ruleUpdate(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id required']);
        $svc = new QualityService();
        return json($svc->updateRule($tenantId, $id, [
            'rule_name'    => $request->post('rule_name', ''),
            'rule_type'    => $request->post('rule_type', ''),
            'rule_config'  => $request->post('rule_config', []),
            'score'        => $request->post('score', 10),
            'level'        => $request->post('level', 'medium'),
            'status'       => $request->post('status', 1),
        ]));
    }
}