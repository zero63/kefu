<?php
/**
 * 短轮询端点（HTTP polling 模拟 WS 单向推送）—— Windows 单进程安全版
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 *
 * 设计：
 *   - 不长连接、不 sleep、不 IO 操作
 *   - 每次调用立即返回（最多 5KB），让 client 调频率
 *   - 客户端轮询节奏（500-1000ms），由浏览器侧做节流
 *
 * 路由：
 *   GET  /api/poll/agent?watch_session=S&after_seq=M
 *   GET  /api/poll/visitor?session_id=S&customer_id=K&after_seq=M
 *   GET  /api/poll/role/{role}?after_seq=M    （admin）
 */

namespace app\controller\poll;

use support\Request;
use app\lib\ConnectionManager;
use app\lib\Token;
use app\lib\Db;

class PollController
{
    /**
     * 鉴权：从 Authorization / X-Token / ?token= 任一取 token
     * webman 1.5 对 HTTP header 处理偶发异常，多通道兜底
     */
    private function auth(Request $request) {
        $token = '';
        try {
            $auth = $request->header('Authorization');
            if ($auth && stripos($auth, 'Bearer ') === 0) {
                $token = trim(substr($auth, 7));
            }
        } catch (Exception $e) {}
        if (!$token) {
            try { $token = trim((string)$request->header('X-Token')); } catch (Exception $e) {}
        }
        if (!$token) {
            $token = trim((string)$request->get('token', ''));
        }
        if (!$token) return false;
        return Token::verify($token);
    }


    public function agent(Request $request) {
        $payload = $this->auth($request);
        if (!$payload) {
            return $this->unauth();
        }

        $employeeId = intval($payload['employee_id']);
        $tenantId   = intval($payload['tenant_id']);
        $afterSeq   = $this->afterSeq($request);
        $watchSession = trim($request->get('watch_session', ''));

        $uid = 'agent:' . $tenantId . ':' . $employeeId;
        if ($watchSession !== '') {
            ConnectionManager::register($uid, ['poll'], 'agent', $watchSession);
        }

        $events = ConnectionManager::drain($uid, $afterSeq);
        $events = $this->filter($events);

        return $this->ok([
            'events'      => $events,
            'server_time' => time(),
        ]);
    }

    public function visitor(Request $request) {
        $sessionId  = trim($request->get('session_id', ''));
        $customerId = trim($request->get('customer_id', ''));
        if (empty($sessionId) && empty($customerId)) {
            return $this->result(400, 'session_id or customer_id required');
        }
        $tenantId = intval($request->get('tenant_id', 1));
        $afterSeq = $this->afterSeq($request);

        $uid = 'visitor:' . $tenantId . ':' . ($customerId ?: $sessionId);
        ConnectionManager::register($uid, ['poll'], 'visitor', $sessionId);

        $events = ConnectionManager::drain($uid, $afterSeq);
        $events = $this->filter($events);

        // 同时也拉到 session 级队列（即使没 register 也可拉到）
        if ($sessionId) {
            $sess = ConnectionManager::drainSession($sessionId, $afterSeq);
            $sess = $this->filter($sess);
            if (!empty($sess)) {
                $events = array_merge($events, $sess);
                usort($events, function ($a, $b) { return $a['seq'] <=> $b['seq']; });
                $events = array_values($events);
            }
        }

        return $this->ok([
            'events'      => $events,
            'server_time' => time(),
        ]);
    }

    public function role(Request $request, $role = 'admin') {
        $payload = Token::verifyFromHeader($request);
        if (!$payload) {
            return $this->unauth();
        }
        $role = trim($role ?: 'admin');
        $afterSeq = $this->afterSeq($request);

        ConnectionManager::register('role:' . $role, ['poll'], $role);

        $events = ConnectionManager::drainRole($role, $afterSeq);
        $events = $this->filter($events);

        return $this->ok([
            'events'      => $events,
            'server_time' => time(),
        ]);
    }

    private function filter(array $events) {
        return array_values(array_filter($events, function ($e) {
            return !empty($e['payload']);
        }));
    }

    private function afterSeq(Request $request) {
        $v = $request->get('after_seq');
        if ($v === null || $v === '') return null;
        return intval($v);
    }

    private function unauth() {
        return $this->result(401, 'unauthorized');
    }
    private function ok($data) {
        return $this->result(0, 'ok', $data);
    }
    private function result($code, $msg, $data = null) {
        $r = ['code' => $code, 'msg' => $msg];
        if ($data !== null) $r['data'] = $data;
        return json($r);
    }
}