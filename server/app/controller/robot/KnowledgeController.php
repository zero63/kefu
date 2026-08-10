<?php
/**
 * 机器人 - 知识库
 */
namespace app\controller\robot;
use support\Request;
use app\lib\Db;

class KnowledgeController {
    public function list(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $page = max(1, intval($request->get('page', 1)));
        $size = min(100, max(10, intval($request->get('size', 20))));
        $offset = ($page - 1) * $size;
        $kw = trim($request->get('keyword', ''));

        Db::setTenantId($tenantId);

        $where = 'WHERE tenant_id = :t';
        $bind = [':t' => $tenantId];
        if ($kw !== '') {
            $where .= ' AND (standard_q LIKE :k OR answer LIKE :k)';
            $bind[':k'] = "%$kw%";
        }

        $total = Db::value("SELECT COUNT(*) FROM kefu_knowledge $where", $bind);
        $bind[':limit'] = $size;
        $bind[':offset'] = $offset;

        $rows = Db::query(
            "SELECT * FROM kefu_knowledge $where ORDER BY id DESC LIMIT :limit OFFSET :offset",
            $bind
        );

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'list' => $rows, 'total' => (int)$total
        ]]);
    }

    public function create(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $stdQ = trim($request->post('standard_q', ''));
        $answer = trim($request->post('answer', ''));
        $robotId = intval($request->post('robot_id', 1));
        if (!$stdQ || !$answer) {
            return json(['code' => 400, 'msg' => '标准问题和答案必填']);
        }
        Db::setTenantId($tenantId);

        $id = Db::insert('kefu_knowledge', [
            'tenant_id'   => $tenantId,
            'robot_id'    => $robotId,
            'standard_q'  => $stdQ,
            'answer'      => $answer,
            'tags'        => $request->post('tags', ''),
            'created_by'  => intval($request->employee_id ?? 0),
        ]);

        return json(['code' => 0, 'msg' => '创建成功', 'data' => ['id' => $id]]);
    }

    public function update(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id required']);
        Db::setTenantId($tenantId);

        $data = [];
        foreach (['standard_q','answer','tags','status'] as $f) {
            if ($request->post($f) !== null) $data[$f] = $request->post($f);
        }
        if (empty($data)) return json(['code' => 400, 'msg' => '没有可更新的字段']);

        Db::update('kefu_knowledge', $data, ['id' => $id]);
        return json(['code' => 0, 'msg' => '更新成功']);
    }

    public function delete(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id required']);
        Db::setTenantId($tenantId);

        Db::update('kefu_knowledge', ['status' => 0], ['id' => $id]);
        return json(['code' => 0, 'msg' => '已删除']);
    }
}