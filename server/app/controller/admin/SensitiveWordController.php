<?php
/**
 * 敏感词管理 Controller
 */

namespace app\controller\admin;

use support\Request;
use app\lib\SensitiveFilter;

class SensitiveWordController
{
    public function list(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $params = [
            'category' => $request->get('category', ''),
            'action'   => $request->get('action', ''),
            'keyword'  => $request->get('keyword', ''),
            'scope'    => $request->get('scope', ''),
        ];
        $rows = SensitiveFilter::listWords($tenantId, $params);
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    public function add(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $operatorId = intval($request->employee_id ?? 0);
        return json(SensitiveFilter::addWord($tenantId, [
            'word'     => $request->post('word', ''),
            'category' => $request->post('category', 'common'),
            'action'   => $request->post('action', 'replace'),
            'is_regex' => $request->post('is_regex', 0),
            'scope'    => $request->post('scope', 'tenant'),
        ], $operatorId));
    }

    public function delete(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id required']);
        return json(SensitiveFilter::removeWord($tenantId, $id));
    }

    public function test(Request $request) {
        $text = $request->post('text', '');
        $direction = $request->post('direction', 'visitor'); // visitor / agent
        if (empty($text)) return json(['code' => 400, 'msg' => 'text required']);
        $res = $direction === 'agent'
            ? SensitiveFilter::filterAgent($text)
            : SensitiveFilter::test($text);
        return json(['code' => 0, 'msg' => 'ok', 'data' => $res]);
    }

    public function clearCache(Request $request) {
        SensitiveFilter::clearCache();
        return json(['code' => 0, 'msg' => 'ok']);
    }
}