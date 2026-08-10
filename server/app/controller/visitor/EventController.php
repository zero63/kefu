<?php
/**
 * 访客端 - 自定义事件
 */
namespace app\controller\visitor;
use support\Request;
use app\lib\Db;

class EventController {
    public function record(Request $request) {
        // 演示版：与 TrackController 行为一致，可作为业务事件扩展点
        // 真实版会接入数据仓库或业务回调
        $tenantId = intval($request->post('tenant_id', 1));
        $customerId = trim($request->post('customer_id', ''));
        $eventName = trim($request->post('event_name', ''));
        $params = $request->post('params', []);
        if (!$customerId || !$eventName) {
            return json(['code' => 400, 'msg' => 'customer_id 和 event_name 必填']);
        }
        Db::setTenantId($tenantId);

        $cid = Db::value("SELECT id FROM kefu_customer WHERE customer_id = :c", [':c' => $customerId]);

        Db::insert('kefu_visitor_track', [
            'tenant_id'   => $tenantId,
            'customer_id' => $cid ?: 0,
            'session_id'  => $request->post('session_id', ''),
            'event_type'  => 'custom_' . $eventName,
            'target'      => '',
            'payload'     => json_encode($params, JSON_UNESCAPED_UNICODE),
            'occurred_at' => date('Y-m-d H:i:s'),
        ]);

        return json(['code' => 0, 'msg' => 'ok']);
    }
}