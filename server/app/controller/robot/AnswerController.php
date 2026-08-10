<?php
/**
 * 机器人问答（HTTP 入口）
 */
namespace app\controller\robot;
use support\Request;
use app\lib\Db;

class AnswerController {
    public function answer(Request $request) {
        $tenantId = intval($request->post('tenant_id', 1));
        $robotId = intval($request->post('robot_id', 1));
        $q = trim($request->post('q', ''));
        if (!$q) return json(['code' => 400, 'msg' => 'q required']);

        Db::setTenantId($tenantId);
        $result = \app\process\RobotWorker::infer([
            'tenant_id' => $tenantId,
            'robot_id'  => $robotId,
            'q'         => $q,
        ]);
        return json(['code' => 0, 'msg' => 'ok', 'data' => $result]);
    }
}