<?php
/**
 * 评价/满意度业务服务
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 *
 * 评价模型：
 *   - 会话评价（会话完成后）
 *   - 工单评价（工单关闭后）
 *   - 评价内容包含：分数、文字评论、附加信息
 *
 *   - 评分体系：1-5
 *     1-2: 不满意
 *     3: 中性
 *     4-5: 满意
 *
 *   - 评价数据会被质检模块纳入评分
 */

namespace app\service;

use app\lib\Db;
use app\lib\Logger;

class EvaluateService
{
    /**
     * 提交会话评价
     * @param int $tenantId
     * @param string $sessionId
     * @param int $score
     * @param string $comment
     * @param string $customerId 业务侧的访客 ID
     * @param array $ext 扩展
     * @return array
     */
    public function submitSessionEvaluate($tenantId, $sessionId, $score, $comment, $customerId, $ext = []) {
        $score = intval($score);
        if ($score < 1 || $score > 5) return ['code' => 400, 'msg' => '评分 1-5'];

        Db::setTenantId($tenantId);

        // 查询会话 + 内部分客户 ID + 处理客服
        $session = Db::find(
            "SELECT id, customer_id, agent_id FROM kefu_session WHERE session_id = :s",
            [':s' => $sessionId]
        );
        if (!$session) return ['code' => 404, 'msg' => '会话不存在'];

        $level = $this->scoreToLevel($score);

        // 一会话一评价，已评过则更新
        $exists = Db::value("SELECT id FROM kefu_evaluate WHERE session_id = :s", [':s' => $sessionId]);
        if ($exists) {
            Db::exec(
                "UPDATE kefu_evaluate
                 SET score=?, level=?, comment=?, updated_at=NOW()
                 WHERE id=?",
                [$score, $level, $comment, $exists]
            );
            return ['code' => 0, 'msg' => '已更新评价', 'data' => ['id' => $exists]];
        }

        $id = Db::insert('kefu_evaluate', [
            'tenant_id'   => $tenantId,
            'session_id'  => $sessionId,
            'customer_id' => $session['customer_id'],
            'agent_id'    => $session['agent_id'],
            'score'       => $score,
            'level'       => $level,
            'comment'     => $comment,
            // 修复：kefu_evaluate 表没 ext_json 列，写到 tags（若有）
            'tags'        => $ext ? substr(json_encode($ext, JSON_UNESCAPED_UNICODE), 0, 500) : null,
        ]);

        Logger::info('session evaluate', [
            'session_id' => $sessionId,
            'score'      => $score,
            'level'      => $level,
        ]);

        return ['code' => 0, 'msg' => 'ok', 'data' => ['id' => $id]];
    }

    /**
     * 提交工单评价
     */
    public function submitTicketEvaluate($tenantId, $ticketId, $score, $comment, $customerId, $ext = []) {
        $score = intval($score);
        if ($score < 1 || $score > 5) return ['code' => 400, 'msg' => '评分 1-5'];

        Db::setTenantId($tenantId);

        $ticket = Db::find("SELECT id FROM kefu_ticket WHERE id = :id", [':id' => $ticketId]);
        if (!$ticket) return ['code' => 404, 'msg' => '工单不存在'];

        $level = $this->scoreToLevel($score);

        $id = Db::insert('kefu_ticket_evaluate', [
            'tenant_id'   => $tenantId,
            'ticket_id'    => $ticketId,
            'customer_id' => $customerId ?: 0,
            'evaluate_score' => $score,
            'comment'     => $comment,
        ]);

        // 同时更新工单的 evaluate_score
        Db::exec(
            "UPDATE kefu_ticket SET evaluate_score = ?, updated_at = NOW() WHERE id = ?",
            [$score, $ticketId]
        );

        return ['code' => 0, 'msg' => 'ok', 'data' => ['id' => $id]];
    }

    /**
     * 满意度统计（按客服 / 按租户 / 按天）
     */
    public function stats($tenantId, $params = []) {
        $startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $params['end_date'] ?? date('Y-m-d');
        $agentId = intval($params['agent_id'] ?? 0);

        Db::setTenantId($tenantId);

        $where = "WHERE ev.tenant_id = :t AND DATE(ev.created_at) BETWEEN :sd AND :ed";
        $bind = [':t' => $tenantId, ':sd' => $startDate, ':ed' => $endDate];

        if ($agentId > 0) {
            $where .= " AND ev.agent_id = :a";
            $bind[':a'] = $agentId;
        }

        // 总数 + 各等级数量
        $total = Db::value("SELECT COUNT(*) FROM kefu_evaluate ev $where", $bind);
        $satisfied = Db::value("SELECT COUNT(*) FROM kefu_evaluate ev $where AND level='satisfied'", $bind);
        $neutral = Db::value("SELECT COUNT(*) FROM kefu_evaluate ev $where AND level='neutral'", $bind);
        $dissatisfied = Db::value("SELECT COUNT(*) FROM kefu_evaluate ev $where AND level='dissatisfied'", $bind);

        // 平均分
        $avg = Db::value("SELECT AVG(score) FROM kefu_evaluate ev $where", $bind);

        // 按天统计
        $byDay = Db::query(
            "SELECT DATE(ev.created_at) AS d, COUNT(*) AS cnt, AVG(ev.score) AS avg_score,
                    SUM(IF(ev.level='satisfied', 1, 0)) AS sat_count
             FROM kefu_evaluate ev
             $where
             GROUP BY DATE(ev.created_at)
             ORDER BY d ASC",
            $bind
        );

        // 客服排行
        $agentRank = Db::query(
            "SELECT ev.agent_id, e.real_name AS agent_name,
                    COUNT(*) AS total,
                    ROUND(AVG(ev.score), 2) AS avg_score,
                    SUM(IF(ev.level='satisfied', 1, 0)) AS satisfied,
                    SUM(IF(ev.level='neutral', 1, 0)) AS neutral,
                    SUM(IF(ev.level='dissatisfied', 1, 0)) AS dissatisfied
             FROM kefu_evaluate ev
             LEFT JOIN kefu_employee e ON e.id = ev.agent_id
             $where
             GROUP BY ev.agent_id, e.real_name
             HAVING total > 0
             ORDER BY avg_score DESC, total DESC
             LIMIT 10",
            $bind
        );

        // 评分分布（按 score 1-5 聚合）
        $distribution = Db::query(
            "SELECT score, COUNT(*) AS cnt
             FROM kefu_evaluate ev $where
             GROUP BY score ORDER BY score DESC",
            $bind
        );

        return [
            'period'        => [$startDate, $endDate],
            'total'         => (int)$total,
            'satisfied'     => (int)$satisfied,
            'neutral'       => (int)$neutral,
            'dissatisfied'  => (int)$dissatisfied,
            'avg_score'     => round((float)$avg, 2),
            'satisfaction_rate' => $total > 0 ? round($satisfied / $total * 100, 1) : 0,
            'by_day'        => $byDay,
            'agent_rank'    => $agentRank,
            'by_agent'      => $agentRank,  // 兼容前端用 by_agent
            'distribution'  => $distribution,
        ];
    }

    /**
     * 获取某次评价的详情
     */
    public function getEvaluate($tenantId, $id) {
        Db::setTenantId($tenantId);
        return Db::find(
            "SELECT * FROM kefu_evaluate WHERE id = :id",
            [':id' => $id]
        );
    }

    /**
     * 列出会话评价（分页）
     */
    public function listEvaluates($tenantId, $params = []) {
        $page = max(1, intval($params['page'] ?? 1));
        $size = min(100, max(10, intval($params['size'] ?? 20)));
        $offset = ($page - 1) * $size;

        Db::setTenantId($tenantId);
        $where = 'WHERE ev.tenant_id = :t';
        $bind = [':t' => $tenantId];
        if (!empty($params['agent_id'])) {
            $where .= ' AND ev.agent_id = :a';
            $bind[':a'] = intval($params['agent_id']);
        }
        if (!empty($params['level'])) {
            $where .= ' AND ev.level = :l';
            $bind[':l'] = $params['level'];
        }
        // 修复：keyword 同时匹配 comment / 客服姓名
        if (!empty($params['keyword'])) {
            $where .= ' AND (ev.comment LIKE :k OR e.real_name LIKE :k)';
            $bind[':k'] = '%' . $params['keyword'] . '%';
        }
        // 修复：支持 start_date / end_date
        if (!empty($params['start_date'])) {
            $where .= ' AND DATE(ev.created_at) >= :sd';
            $bind[':sd'] = $params['start_date'];
        }
        if (!empty($params['end_date'])) {
            $where .= ' AND DATE(ev.created_at) <= :ed';
            $bind[':ed'] = $params['end_date'];
        }

        $total = Db::value("SELECT COUNT(*) FROM kefu_evaluate ev $where", $bind);
        $bind[':limit'] = $size;
        $bind[':offset'] = $offset;

        $rows = Db::query(
            "SELECT ev.*, e.real_name AS agent_name, c.customer_id AS cust_id
             FROM kefu_evaluate ev
             LEFT JOIN kefu_employee e ON e.id = ev.agent_id
             LEFT JOIN kefu_session s ON s.session_id = ev.session_id
             LEFT JOIN kefu_customer c ON c.id = ev.customer_id
             $where
             ORDER BY ev.id DESC
             LIMIT :limit OFFSET :offset",
            $bind
        );

        return ['list' => $rows, 'total' => (int)$total, 'page' => $page, 'size' => $size];
    }

    /**
     * 评分转 level
     */
    private function scoreToLevel($score) {
        if ($score >= 4) return 'satisfied';
        if ($score == 3) return 'neutral';
        return 'dissatisfied';
    }
}