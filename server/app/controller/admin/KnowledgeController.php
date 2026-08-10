<?php
/**
 * 知识库 + 意图管理
 * 作者：kefu 开发团队
 * 创建时间：2026-08-01
 * 功能：问一问测试、命中率统计、多轮意图
 */
namespace app\controller\admin;

use support\Request;
use app\lib\Db;

class KnowledgeController
{
    // ============ 分类 ============

    public function categoryList(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $rows = Db::query(
            "SELECT id, parent_id, name, sort_no, enabled, created_at
             FROM kefu_kb_category WHERE tenant_id = :t ORDER BY sort_no, id",
            [':t' => $tenantId]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    public function categoryCreate(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $name = trim($request->post('name', ''));
        if ($name === '') return json(['code' => 400, 'msg' => '名称必填']);
        Db::setTenantId($tenantId);
        $id = Db::insert('kefu_kb_category', [
            'tenant_id' => $tenantId,
            'parent_id' => intval($request->post('parent_id', 0)),
            'name'      => $name,
            'sort_no'   => intval($request->post('sort_no', 0)),
            'enabled'   => 1,
        ]);
        return json(['code' => 0, 'msg' => '已创建', 'data' => ['id' => $id]]);
    }

    public function categoryDelete(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        $hasChildren = Db::value(
            "SELECT COUNT(*) FROM kefu_kb_category WHERE tenant_id = :t AND parent_id = :p",
            [':t' => $tenantId, ':p' => $id]
        );
        if ($hasChildren > 0) return json(['code' => 400, 'msg' => '存在子分类，请先删除']);
        $hasQ = Db::value(
            "SELECT COUNT(*) FROM kefu_kb_question WHERE tenant_id = :t AND category_id = :c",
            [':t' => $tenantId, ':c' => $id]
        );
        if ($hasQ > 0) return json(['code' => 400, 'msg' => '分类下存在问答，请先迁移']);
        Db::delete('kefu_kb_category', ['id' => $id]);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    // ============ 问答 ============

    public function list(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $page = max(1, intval($request->get('page', 1)));
        $size = 20;
        $offset = ($page - 1) * $size;
        $where = 'WHERE q.tenant_id = :t';
        $bind = [':t' => $tenantId];
        if ($kw = trim($request->get('keyword', ''))) {
            $where .= ' AND (q.question LIKE :k OR q.answer LIKE :k2 OR q.keywords LIKE :k3)';
            $bind[':k']  = "%{$kw}%";
            $bind[':k2'] = "%{$kw}%";
            $bind[':k3'] = "%{$kw}%";
        }
        if ($cid = intval($request->get('category_id', 0))) {
            $where .= ' AND q.category_id = :c';
            $bind[':c'] = $cid;
        }
        $total = Db::value("SELECT COUNT(*) FROM kefu_kb_question q $where", $bind);
        $bind[':limit'] = $size;
        $bind[':offset'] = $offset;
        $rows = Db::query(
            "SELECT q.id, q.question, q.answer, q.keywords, q.intent,
                    q.category_id, c.name AS category_name,
                    q.hit_count, q.miss_count, q.enabled,
                    q.created_at, q.updated_at
             FROM kefu_kb_question q
             LEFT JOIN kefu_kb_category c ON c.id = q.category_id
             $where ORDER BY q.id DESC LIMIT :limit OFFSET :offset",
            $bind
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'list' => $rows, 'total' => (int)$total, 'page' => $page, 'size' => $size
        ]]);
    }

    public function create(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $question = trim($request->post('question', ''));
        $answer = trim($request->post('answer', ''));
        if ($question === '' || $answer === '') {
            return json(['code' => 400, 'msg' => '问题和答案必填']);
        }
        Db::setTenantId($tenantId);
        $id = Db::insert('kefu_kb_question', [
            'tenant_id'    => $tenantId,
            'category_id'  => intval($request->post('category_id', 0)) ?: null,
            'question'     => $question,
            'answer'       => $answer,
            'keywords'     => trim($request->post('keywords', '')),
            'intent'       => trim($request->post('intent', '')),
            'follow_up_json'=> json_encode($request->post('follow_up', []), JSON_UNESCAPED_UNICODE),
            'enabled'      => 1,
        ]);
        return json(['code' => 0, 'msg' => '已创建', 'data' => ['id' => $id]]);
    }

    public function update(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        $data = [];
        foreach (['question', 'answer', 'keywords', 'intent'] as $f) {
            if ($request->post($f) !== null) $data[$f] = trim($request->post($f));
        }
        if ($request->post('category_id') !== null) {
            $data['category_id'] = intval($request->post('category_id')) ?: null;
        }
        if ($request->post('enabled') !== null) {
            $data['enabled'] = intval($request->post('enabled'));
        }
        if (empty($data)) return json(['code' => 400, 'msg' => '无字段更新']);
        Db::update('kefu_kb_question', $data, ['id' => $id]);
        return json(['code' => 0, 'msg' => '已更新']);
    }

    public function delete(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        Db::delete('kefu_kb_question', ['id' => $id]);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    /**
     * 问一问测试（输入问题，返回匹配答案）
     */
    public function test(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $q = trim($request->post('question', ''));
        if ($q === '') return json(['code' => 400, 'msg' => '问题必填']);
        $hits = $this->searchKb($tenantId, $q, 5);
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'query' => $q, 'hits' => $hits
        ]]);
    }

    /**
     * 命中率统计
     */
    public function stats(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        // 7 天命中率
        $rows = Db::query(
            "SELECT DATE(created_at) AS d,
                    COUNT(*) AS total,
                    SUM(hit) AS hit_count
             FROM kefu_kb_query_log
             WHERE tenant_id = :t AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY DATE(created_at) ORDER BY d ASC",
            [':t' => $tenantId]
        );
        // 意图分布
        $intents = Db::query(
            "SELECT intent, COUNT(*) AS cnt, SUM(hit) AS hit_count
             FROM kefu_kb_query_log
             WHERE tenant_id = :t AND intent IS NOT NULL AND intent <> ''
             GROUP BY intent ORDER BY cnt DESC LIMIT 10",
            [':t' => $tenantId]
        );
        // 总命中率
        $total = Db::value(
            "SELECT COUNT(*) FROM kefu_kb_query_log WHERE tenant_id = :t",
            [':t' => $tenantId]
        );
        $hitTotal = Db::value(
            "SELECT COUNT(*) FROM kefu_kb_query_log WHERE tenant_id = :t AND hit = 1",
            [':t' => $tenantId]
        );
        $rate = $total > 0 ? round($hitTotal / $total * 100, 1) : 0;
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'trend'    => $rows,
            'intents'  => $intents,
            'total'    => (int)$total,
            'hit_total'=> (int)$hitTotal,
            'hit_rate' => $rate,
        ]]);
    }

    // ============ 意图管理 ============

    public function intentList(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $rows = Db::query(
            "SELECT id, robot_id, intent_name, description, flow_id, category_id, status,
                    created_at, updated_at
             FROM kefu_intent WHERE tenant_id = :t ORDER BY id DESC",
            [':t' => $tenantId]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    public function intentCreate(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $name = trim($request->post('intent_name', ''));
        if ($name === '') return json(['code' => 400, 'msg' => '名称必填']);
        Db::setTenantId($tenantId);
        $id = Db::insert('kefu_intent', [
            'tenant_id'   => $tenantId,
            'intent_name' => $name,
            'robot_id'    => intval($request->post('robot_id', 0)) ?: null,
            'description' => trim($request->post('description', '')),
            'category_id' => intval($request->post('category_id', 0)) ?: null,
            'flow_id'     => intval($request->post('flow_id', 0)) ?: null,
            'status'      => $request->post('status', 'active'),
        ]);
        return json(['code' => 0, 'msg' => '已创建', 'data' => ['id' => $id]]);
    }

    public function intentDelete(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        Db::delete('kefu_intent', ['id' => $id]);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    // ============ 知识库检索（核心算法） ============

    /**
     * 简易关键词匹配（命中后写入 query log）
     * 生产可替换为 ES / 向量检索
     */
    private function searchKb($tenantId, $query, $limit = 5)
    {
        // 1. 按关键词精确/模糊
        $kw = '%' . $query . '%';
        $rows = Db::query(
            "SELECT id, question, answer, keywords, intent,
                    hit_count, miss_count
             FROM kefu_kb_question
             WHERE tenant_id = :t AND enabled = 1
               AND (question LIKE :q1 OR keywords LIKE :q2)
             ORDER BY hit_count DESC LIMIT " . intval($limit),
            [':t' => $tenantId, ':q1' => $kw, ':q2' => $kw]
        );
        $hit = count($rows) > 0 ? 1 : 0;
        $firstId = $rows[0]['id'] ?? null;
        // 记录 query log
        Db::insert('kefu_kb_query_log', [
            'tenant_id'      => $tenantId,
            'kb_question_id' => $firstId,
            'query_text'     => $query,
            'intent'         => $rows[0]['intent'] ?? null,
            'confidence'     => $hit ? 0.85 : 0,
            'hit'            => $hit,
        ]);
        // 更新 hit_count / miss_count
        if ($firstId) {
            Db::exec("UPDATE kefu_kb_question SET hit_count = hit_count + 1 WHERE id = :id",
                [':id' => $firstId]);
        }
        return $rows;
    }
}