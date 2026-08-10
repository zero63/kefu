<?php
/**
 * 访客端 - 行为埋点
 */
namespace app\controller\visitor;
use support\Request;
use app\lib\Db;

class TrackController {
    public function record(Request $request) {
        $tenantId = intval($request->post('tenant_id', 1));
        $customerId = trim($request->post('customer_id', ''));
        $sessionId = trim($request->post('session_id', ''));
        $eventType = trim($request->post('event_type', ''));
        $target = trim($request->post('target', ''));
        $payload = $request->post('payload', []);
        if (!$customerId || !$eventType) {
            return json(['code' => 400, 'msg' => 'customer_id and event_type required']);
        }
        Db::setTenantId($tenantId);

        $cid = Db::value("SELECT id FROM kefu_customer WHERE customer_id = :c", [':c' => $customerId]);

        Db::insert('kefu_visitor_track', [
            'tenant_id'    => $tenantId,
            'customer_id'  => $cid ?: 0,
            'session_id'   => $sessionId,
            'event_type'   => $eventType,
            'target'       => $target,
            'payload'      => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'occurred_at'  => date('Y-m-d H:i:s'),
        ]);

        return json(['code' => 0, 'msg' => 'ok']);
    }
}