<?php
/**
 * 报表 Controller
 *
 * 路由：
 *   GET  /api/admin/report/overview           综合看板
 *   GET  /api/admin/report/agent-rank         客服排行
 *   GET  /api/admin/report/trend/{metric}     趋势（按 metric: sessions/messages/tickets/visitors）
 *   GET  /api/admin/report/hourly             时段分布
 *   GET  /api/admin/report/channel            渠道分布
 *   POST /api/admin/report/daily              手工生成日报
 *   GET  /api/admin/report/daily/list         日报列表
 *   POST /api/admin/report/custom/save        保存自定义报表
 *   GET  /api/admin/report/custom/list        自定义报表列表
 */

namespace app\controller\admin;

use support\Request;
use app\service\StatisticsService;
use app\lib\Db;

class StatisticsController
{
    public function overview(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $svc = new StatisticsService();
        return json(['code' => 0, 'msg' => 'ok', 'data' => $svc->overview($tenantId)]);
    }

    public function agentRank(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $params = [
            'start_date' => $request->get('start_date', ''),
            'end_date'   => $request->get('end_date', ''),
            'dept_id'    => intval($request->get('dept_id', 0)),
        ];
        $svc = new StatisticsService();
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $svc->agentRank($tenantId, $params)]]);
    }

    public function trend(Request $request, $metric = 'sessions') {
        $tenantId = intval($request->tenant_id ?? 0);
        $params = [
            'start_date' => $request->get('start_date', ''),
            'end_date'   => $request->get('end_date', ''),
        ];
        $svc = new StatisticsService();
        return json(['code' => 0, 'msg' => 'ok', 'data' => $svc->trend($tenantId, $metric, $params)]);
    }

    /**
     * GET /api/admin/report/daily-volume 按天会话量汇总（历史数据）
     */
    public function dailyVolume(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $params = [
            'start_date' => $request->get('start_date', ''),
            'end_date'   => $request->get('end_date', ''),
        ];
        $svc = new StatisticsService();
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $svc->dailyVolume($tenantId, $params)]]);
    }

    public function hourly(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $params = [
            'start_date' => $request->get('start_date', ''),
            'end_date'   => $request->get('end_date', ''),
        ];
        $svc = new StatisticsService();
        return json(['code' => 0, 'msg' => 'ok', 'data' => $svc->hourlyDistribution($tenantId, $params)]);
    }

    public function channel(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $params = [
            'start_date' => $request->get('start_date', ''),
            'end_date'   => $request->get('end_date', ''),
        ];
        $svc = new StatisticsService();
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $svc->channelDistribution($tenantId, $params)]]);
    }

    public function generateDaily(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $date = trim($request->post('date', ''));
        if (empty($date)) $date = date('Y-m-d');

        $svc = new StatisticsService();
        $id = $svc->generateDailyReport($tenantId, $date);
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['id' => $id, 'date' => $date]]);
    }

    public function dailyList(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $startDate = $request->get('start_date', date('Y-m-d', strtotime('-30 days')));
        $endDate = $request->get('end_date', date('Y-m-d'));
        $page = max(1, intval($request->get('page', 1)));
        $size = min(100, max(10, intval($request->get('size', 31))));
        $offset = ($page - 1) * $size;

        Db::setTenantId($tenantId);
        $total = Db::value(
            "SELECT COUNT(*) FROM kefu_report_daily WHERE tenant_id = :t AND report_date BETWEEN :sd AND :ed",
            [':t' => $tenantId, ':sd' => $startDate, ':ed' => $endDate]
        );
        $rows = Db::query(
            "SELECT id, tenant_id, report_date, total_sessions, resolved_sessions, total_messages,
                    avg_response_time, avg_session_duration, satisfaction_rate, online_agents
             FROM kefu_report_daily WHERE tenant_id = :t AND report_date BETWEEN :sd AND :ed
             ORDER BY report_date DESC LIMIT $size OFFSET $offset",
            [':t' => $tenantId, ':sd' => $startDate, ':ed' => $endDate]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows, 'total' => $total, 'page' => $page, 'size' => $size]]);
    }

    public function customSave(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $operatorId = intval($request->employee_id ?? 0);
        $svc = new StatisticsService();
        return json($svc->saveCustomReport($tenantId, [
            'name'          => $request->post('name', ''),
            'description'   => $request->post('description', ''),
            'metric_config' => $request->post('metric_config', []),
            'is_public'     => $request->post('is_public', 0),
        ], $operatorId));
    }

    public function customList(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $operatorId = intval($request->employee_id ?? 0);
        $svc = new StatisticsService();
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $svc->listCustomReports($tenantId, $operatorId)]]);
    }

    /**
     * POST /api/admin/report/custom/run
     * 立即按自定义报表配置生成数据并返回（不进 kefu_report_daily，写到 kefu_report_custom）
     */
    public function customRun(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if (!$id) return json(['code' => 400, 'msg' => '缺少 id']);
        $svc = new StatisticsService();
        $res = $svc->runCustomReport($tenantId, $id);
        return json($res);
    }

    /**
     * POST /api/admin/report/custom/delete
     */
    public function customDelete(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if (!$id) return json(['code' => 400, 'msg' => '缺少 id']);
        $svc = new StatisticsService();
        return json($svc->deleteCustomReport($tenantId, $id));
    }
}