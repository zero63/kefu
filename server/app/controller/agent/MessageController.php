<?php
/**
 * 客服工作台 - 消息管理控制器
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 *
 * 路由：
 *   POST /api/agent/message/send     发送消息
 *   GET  /api/agent/message/history  拉取历史
 */

namespace app\controller\agent;

use support\Request;
use app\service\MessageService;
use app\lib\Db;

class MessageController
{
    /**
     * POST /api/agent/message/send
     * Body: { session_id, content, type, media_url?, client_msg_id?, ext? }
     */
    public function send(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $employeeId = intval($request->employee_id ?? 0);
        Db::setTenantId($tenantId);

        // 兼容 JSON body 和 form-data 两种格式
        $body = $request->_body_data ?? [];
        if (empty($body)) {
            $raw = $request->rawBody();
            $body = $raw ? (json_decode($raw, true) ?: []) : [];
        }
        $get = function ($key, $default = null) use ($body, $request) {
            if (isset($body[$key]) && $body[$key] !== '') return $body[$key];
            return $request->post($key, $default);
        };

        $params = [
            'session_id'     => trim((string)$get('session_id', '')),
            'content'        => $get('content', ''),
            'type'           => $get('type', 'text'),
            'media_url'      => $get('media_url', null),
            'client_msg_id'  => $get('client_msg_id', null),
            'ext'            => is_array($get('ext', null)) ? $get('ext', []) : (json_decode((string)$get('ext', '{}'), true) ?: []),
        ];

        $svc = new MessageService();
        return json($svc->agentSend($tenantId, $employeeId, $params));
    }

    /**
     * GET /api/agent/message/history?session_id=s_xxx&before_seq=0&limit=30
     */
    public function history(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $sessionId = trim($request->get('session_id', ''));
        $beforeSeq = intval($request->get('before_seq', 0));
        $limit = intval($request->get('limit', 30));
        if (empty($sessionId)) {
            return json(['code' => 400, 'msg' => 'session_id required']);
        }
        Db::setTenantId($tenantId);

        $svc = new MessageService();
        $rows = $svc->getHistory($tenantId, $sessionId, $beforeSeq, $limit);
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['messages' => $rows]]);
    }
}