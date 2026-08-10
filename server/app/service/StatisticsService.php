<?php
/**
 * 报表统计服务
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 *
 * 报表维度：
 *   - 实时（看板）
 *   - 日报（kefu_report_daily 已落表）
 *   - 自定义（kefu_report_custom — 用户保存的报表配置）
 *   - 客服排行
 *   - 渠道分析
 *   - 时段分布（24h）
 *
 * 统计口径：
 *   - 当日：CURDATE()
 *   - 周：DATE_SUB(CURDATE(), INTERVAL 7 DAY)
 *   - 月：DATE_SUB(CURDATE(), INTERVAL 30 DAY)
 */

namespace app\service;

use app\lib\Db;
use app\lib\Logger;

class StatisticsService
{
    /**
     * 综合看板
     */
    public function overview($tenantId) {
        Db::setTenantId($tenantId);

        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $data = [
            'today' => [
                'date'           => $today,
                'new_sessions'   => (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_session WHERE tenant_id = :t AND DATE(created_at) = :d",
                    [':t' => $tenantId, ':d' => $today]
                ),
                'closed_sessions'=> (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_session WHERE tenant_id = :t AND DATE(closed_at) = :d AND status='closed'",
                    [':t' => $tenantId, ':d' => $today]
                ),
                'total_messages' => (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_message WHERE tenant_id = :t AND DATE(created_at) = :d",
                    [':t' => $tenantId, ':d' => $today]
                ),
                'new_visitors'   => (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_customer WHERE tenant_id = :t AND DATE(register_time) = :d",
                    [':t' => $tenantId, ':d' => $today]
                ),
                'new_tickets'    => (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_ticket WHERE tenant_id = :t AND DATE(created_at) = :d",
                    [':t' => $tenantId, ':d' => $today]
                ),
                'new_leave_msgs' => (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_leave_message WHERE tenant_id = :t AND DATE(created_at) = :d",
                    [':t' => $tenantId, ':d' => $today]
                ),
                'resolved_tickets' => (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_ticket WHERE tenant_id = :t AND DATE(updated_at) = :d AND status='resolved'",
                    [':t' => $tenantId, ':d' => $today]
                ),
                'avg_response_sec'   => (int)Db::value(
                    "SELECT IFNULL(AVG(avg_response_time), 0) FROM kefu_session WHERE tenant_id = :t AND DATE(created_at) = :d AND avg_response_time IS NOT NULL AND avg_response_time > 0",
                    [':t' => $tenantId, ':d' => $today]
                ),
                'avg_session_duration_sec' => (int)Db::value(
                    "SELECT IFNULL(AVG(duration), 0) FROM kefu_session WHERE tenant_id = :t AND DATE(created_at) = :d AND duration > 0",
                    [':t' => $tenantId, ':d' => $today]
                ),
                'satisfaction_rate' => $this->calcSatisfactionRate($tenantId, $today),
                'satisfaction_count'=> (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_evaluate WHERE tenant_id = :t AND DATE(created_at) = :d",
                    [':t' => $tenantId, ':d' => $today]
                ),
            ],
            'yesterday' => [
                'date'         => $yesterday,
                'new_sessions' => (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_session WHERE tenant_id = :t AND DATE(created_at) = :d",
                    [':t' => $tenantId, ':d' => $yesterday]
                ),
                'closed_sessions' => (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_session WHERE tenant_id = :t AND DATE(closed_at) = :d AND status='closed'",
                    [':t' => $tenantId, ':d' => $yesterday]
                ),
                'total_messages' => (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_message WHERE tenant_id = :t AND DATE(created_at) = :d",
                    [':t' => $tenantId, ':d' => $yesterday]
                ),
                'satisfaction_rate' => $this->calcSatisfactionRate($tenantId, $yesterday),
            ],
            'active' => [
                'active_sessions' => (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_session WHERE tenant_id = :t AND status = 'active'",
                    [':t' => $tenantId]
                ),
                'waiting_sessions' => (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_session WHERE tenant_id = :t AND status = 'waiting'",
                    [':t' => $tenantId]
                ),
                'ai_active_sessions' => (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_session WHERE tenant_id = :t AND status = 'active' AND session_type IN ('robot','mixed')",
                    [':t' => $tenantId]
                ),
                'human_active_sessions' => (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_session WHERE tenant_id = :t AND status = 'active' AND (session_type = 'human' OR session_type IS NULL)",
                    [':t' => $tenantId]
                ),
                'online_customers' => (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_customer WHERE tenant_id = :t AND last_active_time > DATE_SUB(NOW(), INTERVAL 5 MINUTE)",
                    [':t' => $tenantId]
                ),
            ],
            'agents' => [
                // 在线客服：work_status='online' 且未禁用
                'online_agents' => (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_employee WHERE tenant_id = :t AND status = 1 AND work_status = 'online'",
                    [':t' => $tenantId]
                ),
                'busy_agents' => (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_employee WHERE tenant_id = :t AND status = 1 AND work_status = 'busy'",
                    [':t' => $tenantId]
                ),
                'away_agents' => (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_employee WHERE tenant_id = :t AND status = 1 AND work_status = 'away'",
                    [':t' => $tenantId]
                ),
                'total_agents' => (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_employee WHERE tenant_id = :t AND status = 1",
                    [':t' => $tenantId]
                ),
                'total_active_load' => (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_session WHERE tenant_id = :t AND status = 'active' AND agent_id IS NOT NULL",
                    [':t' => $tenantId]
                ),
            ],
            'month' => [
                'total_sessions'  => (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_session WHERE tenant_id = :t AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
                    [':t' => $tenantId]
                ),
                'total_messages'  => (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_message WHERE tenant_id = :t AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
                    [':t' => $tenantId]
                ),
                'avg_satisfaction_rate' => $this->calcSatisfactionRate($tenantId, null, 30),
            ],
            // 兼容旧字段：直接平铺出来，前端旧 dashboard.html 仍能取到
            'today_satisfaction' => $this->calcSatisfactionRate($tenantId, $today),
            'online_agents' => (int)Db::value(
                "SELECT COUNT(*) FROM kefu_employee WHERE tenant_id = :t AND status = 1 AND work_status = 'online'",
                [':t' => $tenantId]
            ),
            'ai_active_sessions' => (int)Db::value(
                "SELECT COUNT(*) FROM kefu_session WHERE tenant_id = :t AND status = 'active' AND session_type IN ('robot','mixed')",
                [':t' => $tenantId]
            ),
        ];

        return $data;
    }

    /**
     * 计算满意度（百分比，保留 1 位小数）
     * @param int    $tenantId  租户
     * @param string|null $date 指定某一天；为空时使用 $days 天范围
     * @param int    $days       范围天数（仅在 $date 为空时生效）
     */
    private function calcSatisfactionRate($tenantId, $date = null, $days = null) {
        try {
            if ($date) {
                $sql = "SELECT IFNULL(SUM(IF(level='satisfied', 1, 0)) / NULLIF(COUNT(*), 0) * 100, 0)
                        FROM kefu_evaluate WHERE tenant_id = :t AND DATE(created_at) = :d";
                $v = Db::value($sql, [':t' => $tenantId, ':d' => $date]);
            } else {
                $sql = "SELECT IFNULL(SUM(IF(level='satisfied', 1, 0)) / NULLIF(COUNT(*), 0) * 100, 0)
                        FROM kefu_evaluate WHERE tenant_id = :t AND created_at >= DATE_SUB(NOW(), INTERVAL :n DAY)";
                $v = Db::value($sql, [':t' => $tenantId, ':n' => $days ?: 30]);
            }
            return round((float)$v, 1);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 客服排行（按 response / message / score 综合）
     */
    public function agentRank($tenantId, $params = []) {
        $startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $params['end_date'] ?? date('Y-m-d');
        $deptId = intval($params['dept_id'] ?? 0);

        Db::setTenantId($tenantId);

        // 部门过滤条件（PDO 同名占位符限制用字符串拼接）
        $deptWhere = '';
        if ($deptId > 0) {
            $deptWhere = " AND e.dept_id = $deptId";
        }

        // 注意：每个 :tX 都需要单独绑定 key
        $rows = Db::query(
            "SELECT
                e.id,
                e.real_name,
                e.username,
                e.dept_id,
                d.dept_name,
                d.parent_id AS dept_parent_id,
                e.last_login_at AS first_login,
                e.work_status,
                (SELECT COUNT(*) FROM kefu_session WHERE agent_id = e.id AND tenant_id = :t1 AND DATE(created_at) BETWEEN :sd1 AND :ed1) AS session_count,
                (SELECT IFNULL(SUM(message_count),0) FROM kefu_session WHERE agent_id = e.id AND tenant_id = :t2 AND DATE(created_at) BETWEEN :sd2 AND :ed2) AS message_count,
                (SELECT AVG(avg_response_time) FROM kefu_session WHERE agent_id = e.id AND tenant_id = :t3 AND avg_response_time IS NOT NULL AND avg_response_time > 0 AND DATE(created_at) BETWEEN :sd3 AND :ed3) AS avg_first_sec,
                (SELECT ROUND(AVG(score),2) FROM kefu_evaluate WHERE agent_id = e.id AND tenant_id = :t4 AND DATE(created_at) BETWEEN :sd4 AND :ed4) AS avg_score,
                (SELECT ROUND(SUM(IF(level='satisfied', 1, 0)) / COUNT(*) * 100, 1) FROM kefu_evaluate WHERE agent_id = e.id AND tenant_id = :t5 AND DATE(created_at) BETWEEN :sd5 AND :ed5) AS satisfaction_rate,
                (SELECT AVG(duration) FROM kefu_session WHERE agent_id = e.id AND tenant_id = :t6 AND status='closed' AND DATE(created_at) BETWEEN :sd6 AND :ed6) AS avg_session_duration,
                (SELECT COUNT(*) FROM kefu_evaluate WHERE agent_id = e.id AND tenant_id = :t7 AND DATE(created_at) BETWEEN :sd7 AND :ed7) AS eval_count,
                (SELECT COUNT(*) FROM kefu_session WHERE agent_id = e.id AND tenant_id = :t8 AND status='closed' AND close_reason='transferred' AND DATE(created_at) BETWEEN :sd8 AND :ed8) AS transfer_count,
                (SELECT COUNT(*) FROM kefu_evaluate WHERE agent_id = e.id AND tenant_id = :t9 AND level='satisfied' AND DATE(created_at) BETWEEN :sd9 AND :ed9) AS satisfied_count,
                (SELECT COUNT(*) FROM kefu_evaluate WHERE agent_id = e.id AND tenant_id = :t10 AND level='neutral' AND DATE(created_at) BETWEEN :sd10 AND :ed10) AS neutral_count,
                (SELECT COUNT(*) FROM kefu_evaluate WHERE agent_id = e.id AND tenant_id = :t11 AND level='dissatisfied' AND DATE(created_at) BETWEEN :sd11 AND :ed11) AS dissatisfied_count
             FROM kefu_employee e
             LEFT JOIN kefu_dept d ON d.id = e.dept_id AND d.tenant_id = e.tenant_id
             WHERE e.tenant_id = :t12 AND e.status = 1 $deptWhere
             ORDER BY e.dept_id ASC, message_count DESC, session_count DESC
             LIMIT 200",
            [
                ':t1' => $tenantId, ':t2' => $tenantId, ':t3' => $tenantId, ':t4' => $tenantId, ':t5' => $tenantId, ':t6' => $tenantId,
                ':t7' => $tenantId, ':t8' => $tenantId, ':t9' => $tenantId, ':t10' => $tenantId, ':t11' => $tenantId, ':t12' => $tenantId,
                ':sd1' => $startDate, ':ed1' => $endDate,
                ':sd2' => $startDate, ':ed2' => $endDate,
                ':sd3' => $startDate, ':ed3' => $endDate,
                ':sd4' => $startDate, ':ed4' => $endDate,
                ':sd5' => $startDate, ':ed5' => $endDate,
                ':sd6' => $startDate, ':ed6' => $endDate,
                ':sd7' => $startDate, ':ed7' => $endDate,
                ':sd8' => $startDate, ':ed8' => $endDate,
                ':sd9' => $startDate, ':ed9' => $endDate,
                ':sd10' => $startDate, ':ed10' => $endDate,
                ':sd11' => $startDate, ':ed11' => $endDate,
            ]
        );

        return $rows;
    }

    /**
     * 按天汇总会话量指标（历史数据"会话量"Tab）
     * 返回：date/total_sessions/new_sessions/closed_sessions/msg_count/visitor_count/ai_sessions/human_sessions/transfer_count
     */
    public function dailyVolume($tenantId, $params = []) {
        $startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
        $endDate = $params['end_date'] ?? date('Y-m-d');

        Db::setTenantId($tenantId);

        // 注意：PDO 不允许同名占位符出现多次，:t1 用于主查询、:t2 用于 JOIN
        return Db::query(
            "SELECT
                DATE(s.created_at) AS date,
                COUNT(*) AS total_sessions,
                SUM(CASE WHEN c.customer_id IS NOT NULL AND DATE(c.register_time) = DATE(s.created_at) THEN 1 ELSE 0 END) AS new_sessions,
                SUM(CASE WHEN s.status = 'closed' THEN 1 ELSE 0 END) AS closed_sessions,
                IFNULL(SUM(s.message_count), 0) AS msg_count,
                COUNT(DISTINCT s.customer_id) AS visitor_count,
                SUM(CASE WHEN s.serving_mode = 'ai' THEN 1 ELSE 0 END) AS ai_sessions,
                SUM(CASE WHEN s.serving_mode = 'human' THEN 1 ELSE 0 END) AS human_sessions,
                SUM(CASE WHEN s.close_reason = 'transferred' THEN 1 ELSE 0 END) AS transfer_count
             FROM kefu_session s
             LEFT JOIN kefu_customer c ON c.id = s.customer_id AND c.tenant_id = :t2
             WHERE s.tenant_id = :t1 AND DATE(s.created_at) BETWEEN :sd AND :ed
             GROUP BY DATE(s.created_at)
             ORDER BY date ASC",
            [':t1' => $tenantId, ':t2' => $tenantId, ':sd' => $startDate, ':ed' => $endDate]
        );
    }

    /**
     * 趋势图（按天）
     */
    public function trend($tenantId, $metric = 'sessions', $params = []) {
        $startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
        $endDate = $params['end_date'] ?? date('Y-m-d');

        Db::setTenantId($tenantId);

        $sqls = [
            'sessions' => "SELECT DATE(created_at) AS d, COUNT(*) AS v FROM kefu_session
                           WHERE tenant_id = :t AND DATE(created_at) BETWEEN :sd AND :ed
                           GROUP BY DATE(created_at) ORDER BY d ASC",
            'messages' => "SELECT DATE(created_at) AS d, COUNT(*) AS v FROM kefu_message
                           WHERE tenant_id = :t AND DATE(created_at) BETWEEN :sd AND :ed
                           GROUP BY DATE(created_at) ORDER BY d ASC",
            'tickets'  => "SELECT DATE(created_at) AS d, COUNT(*) AS v FROM kefu_ticket
                           WHERE tenant_id = :t AND DATE(created_at) BETWEEN :sd AND :ed
                           GROUP BY DATE(created_at) ORDER BY d ASC",
            'visitors' => "SELECT DATE(register_time) AS d, COUNT(*) AS v FROM kefu_customer
                           WHERE tenant_id = :t AND DATE(register_time) BETWEEN :sd AND :ed
                           GROUP BY DATE(register_time) ORDER BY d ASC",
        ];

        if (!isset($sqls[$metric])) return [];
        return Db::query($sqls[$metric], [':t' => $tenantId, ':sd' => $startDate, ':ed' => $endDate]);
    }

    /**
     * 时段分布（24 小时 - 0:00-23:00）
     */
    public function hourlyDistribution($tenantId, $params = []) {
        $startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
        $endDate = $params['end_date'] ?? date('Y-m-d');

        Db::setTenantId($tenantId);

        $rows = Db::query(
            "SELECT HOUR(created_at) AS hour, COUNT(*) AS cnt
             FROM kefu_session
             WHERE tenant_id = :t AND DATE(created_at) BETWEEN :sd AND :ed
             GROUP BY HOUR(created_at)
             ORDER BY hour ASC",
            [':t' => $tenantId, ':sd' => $startDate, ':ed' => $endDate]
        );

        // 补齐 0-23 小时
        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $out[$h] = ['hour' => $h, 'cnt' => 0];
        }
        foreach ($rows as $r) {
            $h = intval($r['hour']);
            $out[$h] = ['hour' => $h, 'cnt' => intval($r['cnt'])];
        }
        return array_values($out);
    }

    /**
     * 渠道分布
     */
    public function channelDistribution($tenantId, $params = []) {
        $startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $params['end_date'] ?? date('Y-m-d');

        Db::setTenantId($tenantId);
        return Db::query(
            "SELECT channel,
                    COUNT(*) AS session_count,
                    SUM(IFNULL(message_count, 0)) AS total_messages,
                    AVG(IFNULL(duration, 0)) AS avg_duration_sec,
                    AVG(IFNULL(avg_response_time, 0)) AS avg_response_sec
             FROM kefu_session
             WHERE tenant_id = :t AND DATE(created_at) BETWEEN :sd AND :ed
             GROUP BY channel
             ORDER BY session_count DESC",
            [':t' => $tenantId, ':sd' => $startDate, ':ed' => $endDate]
        );
    }

    /**
     * 日报生成（写入 kefu_report_daily）
     */
    public function generateDailyReport($tenantId, $date = null) {
        $date = $date ?? date('Y-m-d');
        Db::setTenantId($tenantId);

        $totalSessions = (int)Db::value(
            "SELECT COUNT(*) FROM kefu_session WHERE tenant_id = :t AND DATE(created_at) = :d",
            [':t' => $tenantId, ':d' => $date]
        );
        $closedSessions = (int)Db::value(
            "SELECT COUNT(*) FROM kefu_session WHERE tenant_id = :t AND DATE(closed_at) = :d AND status='closed'",
            [':t' => $tenantId, ':d' => $date]
        );
        $resolvedSessions = (int)Db::value(
            "SELECT COUNT(*) FROM kefu_ticket WHERE tenant_id = :t AND DATE(updated_at) = :d AND status='resolved'",
            [':t' => $tenantId, ':d' => $date]
        );
        $totalMessages = (int)Db::value(
            "SELECT COUNT(*) FROM kefu_message WHERE tenant_id = :t AND DATE(created_at) = :d",
            [':t' => $tenantId, ':d' => $date]
        );
        $avgResponse = (int)Db::value(
            "SELECT IFNULL(AVG(avg_response_time), 0) FROM kefu_session WHERE tenant_id = :t AND DATE(created_at) = :d AND avg_response_time IS NOT NULL",
            [':t' => $tenantId, ':d' => $date]
        );
        $avgSessionDuration = (int)Db::value(
            "SELECT IFNULL(AVG(duration), 0) FROM kefu_session WHERE tenant_id = :t AND DATE(created_at) = :d AND duration IS NOT NULL",
            [':t' => $tenantId, ':d' => $date]
        );
        $satisfactionRate = (float)Db::value(
            "SELECT IFNULL(SUM(IF(level='satisfied', 1, 0)) / COUNT(*), 0) FROM kefu_evaluate WHERE tenant_id = :t AND DATE(created_at) = :d",
            [':t' => $tenantId, ':d' => $date]
        );
        $onlineAgents = (int)Db::value(
            "SELECT COUNT(*) FROM kefu_employee WHERE tenant_id = :t AND status = 1 AND last_login_at > DATE_SUB(:d, INTERVAL 7 DAY)",
            [':t' => $tenantId, ':d' => $date]
        );

        $workload = json_encode([
            'sessions' => $totalSessions,
            'tickets'  => (int)Db::value("SELECT COUNT(*) FROM kefu_ticket WHERE tenant_id = :t AND DATE(created_at) = :d", [':t' => $tenantId, ':d' => $date]),
        ], JSON_UNESCAPED_UNICODE);

        $exists = Db::value(
            "SELECT id FROM kefu_report_daily WHERE tenant_id = :t AND report_date = :d",
            [':t' => $tenantId, ':d' => $date]
        );
        if ($exists) {
            Db::exec(
                "UPDATE kefu_report_daily
                 SET total_sessions = ?, resolved_sessions = ?, total_messages = ?,
                     avg_response_time = ?, avg_session_duration = ?, satisfaction_rate = ?,
                     online_agents = ?, workload_data = ?
                 WHERE id = ?",
                [$totalSessions, $resolvedSessions, $totalMessages, $avgResponse, $avgSessionDuration, $satisfactionRate, $onlineAgents, $workload, $exists]
            );
            return $exists;
        } else {
            return Db::insert('kefu_report_daily', [
                'tenant_id'          => $tenantId,
                'report_date'        => $date,
                'total_sessions'     => $totalSessions,
                'resolved_sessions'  => $resolvedSessions,
                'total_messages'     => $totalMessages,
                'avg_response_time'  => $avgResponse,
                'avg_session_duration' => $avgSessionDuration,
                'satisfaction_rate'  => $satisfactionRate,
                'online_agents'      => $onlineAgents,
                'workload_data'      => $workload,
            ]);
        }
    }

    /**
     * 自定义报表保存（用户配置报表）
     */
    public function saveCustomReport($tenantId, $params, $creatorId) {
        $name = trim($params['name'] ?? '');
        if (empty($name)) return ['code' => 400, 'msg' => 'name required'];

        Db::setTenantId($tenantId);
        $id = Db::insert('kefu_report_custom', [
            'tenant_id'     => $tenantId,
            'report_name'   => $name,
            'report_config' => json_encode($params['metric_config'] ?? [], JSON_UNESCAPED_UNICODE),
            'description'   => trim($params['description'] ?? '') ?: null,
            'created_by'    => $creatorId,
        ]);
        return ['code' => 0, 'msg' => 'ok', 'data' => ['id' => $id]];
    }

    public function listCustomReports($tenantId, $employeeId) {
        Db::setTenantId($tenantId);
        return Db::query(
            "SELECT id, report_name AS name, description, report_config AS metric_config,
                    created_by AS creator_id, created_at, updated_at
             FROM kefu_report_custom
             WHERE tenant_id = :t
             ORDER BY id DESC",
            [':t' => $tenantId]
        );
    }

    /**
     * 立即按自定义报表配置生成数据
     */
    public function runCustomReport($tenantId, $id) {
        Db::setTenantId($tenantId);
        $row = Db::find("SELECT * FROM kefu_report_custom WHERE id = :i AND tenant_id = :t", [':i' => $id, ':t' => $tenantId]);
        if (!$row) return ['code' => 404, 'msg' => '报表不存在'];

        $cfg = json_decode($row['report_config'] ?? '{}', true) ?: [];
        $metric = $cfg['metric'] ?? 'sessions';
        $start = $cfg['from'] ?? date('Y-m-d', strtotime('-7 days'));
        $end   = $cfg['to'] ?? date('Y-m-d');

        // 复用 trend 拉数
        $data = $this->trend($tenantId, $metric, ['start_date' => $start, 'end_date' => $end]);

        // 更新 updated_at
        try {
            Db::exec("UPDATE kefu_report_custom SET updated_at = NOW() WHERE id = :i", [':i' => $id]);
        } catch (\Throwable $e) {}

        return ['code' => 0, 'msg' => 'ok', 'data' => [
            'name'     => $row['report_name'],
            'metric'   => $metric,
            'from'     => $start,
            'to'       => $end,
            'rows'     => $data,
            'total'    => array_sum(array_map(function ($r) { return +($r['v'] ?? 0); }, $data)),
            'generated_at' => date('Y-m-d H:i:s'),
        ]];
    }

    /**
     * 删除自定义报表
     */
    public function deleteCustomReport($tenantId, $id) {
        Db::setTenantId($tenantId);
        try {
            Db::exec("DELETE FROM kefu_report_custom WHERE id = :i AND tenant_id = :t", [':i' => $id, ':t' => $tenantId]);
            return ['code' => 0, 'msg' => 'ok'];
        } catch (\Throwable $e) {
            return ['code' => 500, 'msg' => '删除失败：' . $e->getMessage()];
        }
    }
}