<?php
/**
 * 访客端 - 多渠道认证
 *
 * 路由：
 *   POST /api/visitor/auth/weapp   微信小程序（传 code）
 *   POST /api/visitor/auth/h5      H5 匿名访客
 */
namespace app\controller\visitor;
use support\Request;
use app\lib\Db;
use app\lib\ConnectionManager;

class AuthController {
    public function weapp(Request $request) {
        try {
            $channel = new \app\channel\WeappChannel();
            $params = [
                'code'     => $request->post('code', ''),
                'nickname' => $request->post('nickname', ''),
                'avatar'   => $request->post('avatar', ''),
                'phone'    => $request->post('phone', ''),
            ];
            $result = $channel->authenticate($params);

            $tenantId = intval($request->post('tenant_id', 1));
            Db::setTenantId($tenantId);

            // 同步客户档案
            $existing = Db::find(
                "SELECT id FROM kefu_customer WHERE tenant_id=:t AND customer_id=:c",
                [':t' => $tenantId, ':c' => $result['customer_id']]
            );
            if (!$existing) {
                Db::insert('kefu_customer', [
                    'tenant_id'    => $tenantId,
                    'customer_id'  => $result['customer_id'],
                    'channel'      => 'weapp',
                    'nickname'     => $result['nickname'],
                    'avatar'       => $result['avatar'],
                    'phone'        => $result['phone'],
                    'register_time'=> date('Y-m-d H:i:s'),
                    'last_active_time' => date('Y-m-d H:i:s'),
                    'profile'      => json_encode(['unionid' => $result['unionid'] ?? ''], JSON_UNESCAPED_UNICODE),
                ]);
            } else {
                Db::exec(
                    "UPDATE kefu_customer SET last_active_time=NOW(), nickname=:n, avatar=:a WHERE id=:id",
                    [':n' => $result['nickname'], ':a' => $result['avatar'], ':id' => $existing['id']]
                );
            }

            // 自动创建/获取会话
            $svc = new \app\service\SessionService();
            $session = $svc->getOrCreateActiveSession($tenantId, $result['customer_id'], 'weapp');
            if ($session['status'] === 'waiting' || $session['status'] === 'active') {
                \app\lib\ConnectionManager::pushToRole('agent', [
                    'type' => 'new_session',
                    'session_id' => $session['session_id'],
                    'customer_id' => $result['customer_id'],
                    'agent_id' => $session['agent_id'],
                ]);
            }

            return json(['code' => 0, 'msg' => 'ok', 'data' => [
                'customer_id'  => $result['customer_id'],
                'channel'      => 'weapp',
                'openid'       => $result['channel_id'],
                'unionid'      => $result['unionid'],
                'nickname'     => $result['nickname'],
                'avatar'       => $result['avatar'],
                'session_id'   => $session['session_id'],
                'session_status' => $session['status'],
            ]]);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    public function h5(Request $request) {
        $visitorToken = $request->post('visitor_token', '');
        $tenantId = intval($request->post('tenant_id', 1));
        if (empty($visitorToken)) {
            $visitorToken = 'h5_' . bin2hex(random_bytes(16));
        }

        Db::setTenantId($tenantId);

        $existing = Db::find(
            "SELECT id FROM kefu_customer WHERE tenant_id=:t AND customer_id=:c",
            [':t' => $tenantId, ':c' => $visitorToken]
        );
        if (!$existing) {
            Db::insert('kefu_customer', [
                'tenant_id'    => $tenantId,
                'customer_id'  => $visitorToken,
                'channel'      => 'h5',
                'register_time'=> date('Y-m-d H:i:s'),
                'last_active_time' => date('Y-m-d H:i:s'),
                'profile'      => '{}',
            ]);
        } else {
            Db::exec(
                "UPDATE kefu_customer SET last_active_time=NOW() WHERE id=:id",
                [':id' => $existing['id']]
            );
        }

        // 自动创建/获取活跃会话（业务模式：访客进站即会话）
        $svc = new \app\service\SessionService();
        $session = $svc->getOrCreateActiveSession($tenantId, $visitorToken, 'h5');

        // 推送给所有在线客服（有新会话来了）
        if ($session['status'] === 'waiting' || $session['status'] === 'active') {
            $payload = [
                'type' => 'new_session',
                'session_id'  => $session['session_id'],
                'customer_id' => $visitorToken,
                'priority'    => $session['priority'] ?? 1,
                'agent_id'    => $session['agent_id'],
            ];
            \app\lib\ConnectionManager::pushToRole('agent', $payload);
            \app\lib\ConnectionManager::pushToRole('admin', $payload);
            \app\lib\ConnectionManager::pushToSession($session['session_id'], $payload);
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'customer_id'   => $visitorToken,
            'visitor_token' => $visitorToken,
            'channel'       => 'h5',
            'session_id'    => $session['session_id'],
            'session_status'=> $session['status'],
            'agent'         => $session['agent_id'] ? [
                'id'   => $session['agent_id'],
                'name' => $session['agent_name'] ?? '',
            ] : null,
        ]]);
    }
}