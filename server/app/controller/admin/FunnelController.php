<?php
/**
 * 漏斗分析 + 会话来源
 * 作者：kefu 开发团队
 * 创建时间：2026-08-01
 */
namespace app\controller\admin;

use support\Request;
use app\lib\Db;

class FunnelController
{
    // ============ 漏斗 ============

    /**
     * 漏斗定义列表
     */
    public function list(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $rows = Db::query(
            "SELECT id, name, steps_json, enabled, created_at FROM kefu_funnel
             WHERE tenant_id = :t ORDER BY id DESC",
            [':t' => $tenantId]
        );
        foreach ($rows as &$r) {
            $r['steps'] = $r['steps_json'] ? json_decode($r['steps_json'], true) : [];
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    public function create(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $name = trim($request->post('name', ''));
        $steps = $request->post('steps', []);
        if ($name === '' || empty($steps)) {
            return json(['code' => 400, 'msg' => '名称和步骤必填']);
        }
        Db::setTenantId($tenantId);
        $id = Db::insert('kefu_funnel', [
            'tenant_id'  => $tenantId,
            'name'       => $name,
            'steps_json' => json_encode($steps, JSON_UNESCAPED_UNICODE),
            'enabled'    => 1,
            'created_by' => intval($request->employee_id ?? 0) ?: null,
        ]);
        return json(['code' => 0, 'msg' => '已创建', 'data' => ['id' => $id]]);
    }

    public function delete(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        Db::delete('kefu_funnel_event', ['funnel_id' => $id]);
        Db::delete('kefu_funnel', ['id' => $id]);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    /**
     * 计算漏斗转化（按步骤统计 customer 数）
     */
    public function analyze(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->get('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        $funnel = Db::find("SELECT * FROM kefu_funnel WHERE tenant_id = :t AND id = :id",
            [':t' => $tenantId, ':id' => $id]);
        if (!$funnel) return json(['code' => 404, 'msg' => '漏斗不存在']);
        $steps = json_decode($funnel['steps_json'], true) ?: [];
        $result = ['steps' => [], 'overall_conversion' => 0];
        $firstCount = 0;
        $prevCount = 0;
        foreach ($steps as $i => $step) {
            $stepName = $step['name'] ?? ('步骤' . ($i + 1));
            $count = Db::value(
                "SELECT COUNT(DISTINCT customer_id) FROM kefu_funnel_event
                 WHERE tenant_id = :t AND funnel_id = :f AND step_no = :n",
                [':t' => $tenantId, ':f' => $id, ':n' => ($i + 1)]
            );
            $count = (int)$count;
            if ($i === 0) $firstCount = $count;
            $conversion = $prevCount > 0 ? round($count / $prevCount * 100, 1) : 100;
            $overallRate = $firstCount > 0 ? round($count / $firstCount * 100, 1) : 0;
            $result['steps'][] = [
                'step_no'   => $i + 1,
                'name'      => $stepName,
                'count'     => $count,
                'conversion'=> $conversion,    // 相对上一步
                'overall'   => $overallRate,   // 相对第一步
            ];
            $prevCount = $count;
        }
        $result['overall_conversion'] = $firstCount > 0
            ? round($prevCount / $firstCount * 100, 1) : 0;
        return json(['code' => 0, 'msg' => 'ok', 'data' => $result]);
    }

    // ============ 会话来源 ============

    /**
     * 会话来源分布
     */
    public function sourceStats(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        // 来源汇总
        $sources = Db::query(
            "SELECT source, COUNT(*) AS session_count, COUNT(DISTINCT session_id) AS session_unique
             FROM kefu_session_source
             WHERE tenant_id = :t AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY source ORDER BY session_count DESC",
            [':t' => $tenantId]
        );
        // 来源详情 Top 10
        $details = Db::query(
            "SELECT source, source_detail, COUNT(*) AS cnt
             FROM kefu_session_source
             WHERE tenant_id = :t AND source_detail IS NOT NULL AND source_detail <> ''
             GROUP BY source, source_detail ORDER BY cnt DESC LIMIT 10",
            [':t' => $tenantId]
        );
        // 每日趋势
        $trend = Db::query(
            "SELECT DATE(created_at) AS d, source, COUNT(*) AS cnt
             FROM kefu_session_source
             WHERE tenant_id = :t AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
             GROUP BY DATE(created_at), source ORDER BY d ASC",
            [':t' => $tenantId]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'sources' => $sources, 'details' => $details, 'trend' => $trend
        ]]);
    }

    /**
     * 设置会话来源
     */
    public function setSource(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $sid = trim($request->post('session_id', ''));
        if ($sid === '') return json(['code' => 400, 'msg' => 'session_id 必填']);
        Db::setTenantId($tenantId);
        $source = $request->post('source', 'direct');
        $exists = Db::value(
            "SELECT COUNT(*) FROM kefu_session_source WHERE tenant_id = :t AND session_id = :s",
            [':t' => $tenantId, ':s' => $sid]
        );
        if ($exists > 0) {
            Db::update('kefu_session_source', [
                'source'       => $source,
                'source_detail'=> trim($request->post('source_detail', '')),
                'landing_page' => trim($request->post('landing_page', '')),
                'campaign'     => trim($request->post('campaign', '')),
                'keyword'      => trim($request->post('keyword', '')),
            ], ['session_id' => $sid]);
        } else {
            Db::insert('kefu_session_source', [
                'tenant_id'    => $tenantId,
                'session_id'   => $sid,
                'source'       => $source,
                'source_detail'=> trim($request->post('source_detail', '')),
                'landing_page' => trim($request->post('landing_page', '')),
                'campaign'     => trim($request->post('campaign', '')),
                'keyword'      => trim($request->post('keyword', '')),
            ]);
        }
        return json(['code' => 0, 'msg' => 'ok']);
    }
}