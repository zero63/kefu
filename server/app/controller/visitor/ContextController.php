<?php
/**
 * 访客端 - 上下文更新（订单/购物车/用户画像）
 */
namespace app\controller\visitor;
use support\Request;
use app\lib\Db;

class ContextController {
    public function update(Request $request) {
        $tenantId = intval($request->post('tenant_id', 1));
        $customerId = trim($request->post('customer_id', ''));
        $sessionId = trim($request->post('session_id', ''));
        if (!$customerId) {
            return json(['code' => 400, 'msg' => 'customer_id required']);
        }
        Db::setTenantId($tenantId);

        // 找 customer 内部 id
        $cid = Db::value("SELECT id FROM kefu_customer WHERE customer_id = :c", [':c' => $customerId]);
        if (!$cid) {
            return json(['code' => 404, 'msg' => 'customer not found']);
        }

        Db::insert('kefu_session_context', [
            'tenant_id'   => $tenantId,
            'session_id'  => $sessionId ?: ('ctx_' . bin2hex(random_bytes(8))),
            'customer_id' => $cid,
            'page_url'    => trim($request->post('page_url', '')),
            'page_title'  => trim($request->post('page_title', '')),
            'page_type'   => trim($request->post('page_type', '')),
            'device'      => trim($request->post('device', '')),
            'orders'      => json_encode($request->post('orders', []), JSON_UNESCAPED_UNICODE),
            'products'    => json_encode($request->post('products', []), JSON_UNESCAPED_UNICODE),
            'cart'        => json_encode($request->post('cart', []), JSON_UNESCAPED_UNICODE),
            'custom'      => json_encode($request->post('custom', []), JSON_UNESCAPED_UNICODE),
        ]);

        return json(['code' => 0, 'msg' => 'ok']);
    }
}