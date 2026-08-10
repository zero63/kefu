<?php
/**
 * 质检业务服务
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 *
 * 表字段（实际）：
 *   kefu_quality_rule: { id, rule_name, rule_type, rule_config(text), score, level, status }
 *   kefu_quality_result: { id, session_id, agent_id, rule_id, matched_content, score_impact, reviewer_id, review_status, remark, reviewed_at }
 *
 * 规则维度（rule_type）：
 *   - first_response_sec：首响时长阈值
 *   - avg_response_sec：平均响应阈值
 *   - duration_hour：会话持续小时上限
 *   - sensitive_count：敏感词触发次数
 *   - customer_score：客户评分下限
 *   - agent_msg_count：客服消息数下限
 */

namespace app\service;

use app\lib\Db;
use app\lib\Logger;

class QualityService
{
    public function inspectSession($tenantId, $sessionId, $inspectorId = null) {
        Db::setTenantId($tenantId);

        $session = Db::find(
            "SELECT s.*, e.real_name AS agent_name
             FROM kefu_session s
             LEFT JOIN kefu_employee e ON e.id = s.agent_id
             WHERE s.session_id = :s",
            [':s' => $sessionId]
        );
        if (!$session) return ['code' => 404, 'msg' => '会话不存在'];

        $rules = Db::query(
            "SELECT * FROM kefu_quality_rule
             WHERE tenant_id = :t AND status = 1
             ORDER BY id ASC",
            [':t' => $tenantId]
        );

        $totalScore = 100;
        $ruleHits = [];

        foreach ($rules as $rule) {
            $config = json_decode($rule['rule_config'] ?? '{}', true) ?: [];
            $hit = $this->checkRule($rule['rule_type'], $config, $session, $sessionId, $tenantId);
            $deduct = $hit ? intval($rule['score']) : 0;
            $ruleHits[] = [
                'rule_id'    => $rule['id'],
                'rule_name'  => $rule['rule_name'],
                'rule_type'  => $rule['rule_type'],
                'hit'        => $hit,
                'deduct'     => $deduct,
            ];
            $totalScore -= $deduct;

            // 每条命中写入 kefu_quality_result 单独记录
            if ($hit) {
                $this->insertHitRow($tenantId, $session, $rule, $deduct, $inspectorId);
            }
        }

        $totalScore = max(0, min(100, $totalScore));
        $grade = $this->scoreToGrade($totalScore);

        return ['code' => 0, 'msg' => 'ok', 'data' => [
            'session_id'  => $sessionId,
            'total_score'  => $totalScore,
            'grade'        => $grade,
            'rule_hits'    => $ruleHits,
        ]];
    }

    /**
     * 写入单条规则命中记录
     */
    private function insertHitRow($tenantId, $session, $rule, $deduct, $inspectorId) {
        try {
            Db::insert('kefu_quality_result', [
                'tenant_id'       => $tenantId,
                'session_id'      => $session['session_id'],
                'agent_id'        => $session['agent_id'],
                'rule_id'         => $rule['id'],
                'matched_content' => $rule['rule_name'] . '（' . $rule['rule_type'] . '）',
                'score_impact'    => -$deduct, // 扣分
                'reviewer_id'     => $inspectorId,
                'review_status'   => $inspectorId ? 'confirmed' : 'pending',
                'reviewed_at'     => $inspectorId ? date('Y-m-d H:i:s') : null,
            ]);
        } catch (\Exception $e) {
            Logger::error('quality insert failed', ['err' => $e->getMessage()]);
        }
    }

    /**
     * 检查单条规则是否命中
     */
    private function checkRule($type, $config, $session, $sessionId, $tenantId) {
        switch ($type) {
            case 'first_response_sec':
                if (!$session['first_response_at']) return false;
                $diff = time() - strtotime($session['first_response_at']);
                $threshold = intval($config['threshold'] ?? 60);
                return $diff > $threshold;
            case 'avg_response_sec':
                $avg = (int)($session['avg_response_time'] ?? 0);
                if ($avg <= 0) return false;
                $threshold = intval($config['threshold'] ?? 30);
                return $avg > $threshold;
            case 'duration_hour':
                $sec = (int)($session['duration'] ?? 0);
                if ($sec <= 0) return false;
                $hours = $sec / 3600;
                $threshold = intval($config['threshold'] ?? 24);
                return $hours > $threshold;
            case 'sensitive_count':
                $max = intval($config['threshold'] ?? 0);
                $count = (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_message WHERE session_id = :s AND is_sensitive = 1",
                    [':s' => $sessionId]
                );
                return $count > $max;
            case 'customer_score':
                $min = intval($config['threshold'] ?? 4);
                $score = (int)Db::value(
                    "SELECT score FROM kefu_evaluate WHERE session_id = :s LIMIT 1",
                    [':s' => $sessionId]
                );
                return $score > 0 && $score < $min;
            case 'agent_msg_count':
                $min = intval($config['threshold'] ?? 2);
                $count = (int)Db::value(
                    "SELECT COUNT(*) FROM kefu_message WHERE session_id = :s AND sender_type = 'agent'",
                    [':s' => $sessionId]
                );
                return $count < $min;
            default:
                return false;
        }
    }

    private function scoreToGrade($score) {
        if ($score >= 90) return 'A';
        if ($score >= 80) return 'B';
        if ($score >= 70) return 'C';
        return 'D';
    }

    public function listRules($tenantId) {
        Db::setTenantId($tenantId);
        return Db::query("SELECT * FROM kefu_quality_rule WHERE tenant_id = :t ORDER BY id ASC", [':t' => $tenantId]);
    }

    public function addRule($tenantId, $params) {
        if (empty($params['rule_name']) || empty($params['rule_type'])) {
            return ['code' => 400, 'msg' => 'rule_name 与 rule_type 必填'];
        }
        Db::setTenantId($tenantId);
        $id = Db::insert('kefu_quality_rule', [
            'tenant_id'  => $tenantId,
            'rule_name'  => trim($params['rule_name']),
            'rule_type'  => trim($params['rule_type']),
            'rule_config'=> isset($params['rule_config']) ? json_encode($params['rule_config'], JSON_UNESCAPED_UNICODE) : '{}',
            'score'      => intval($params['score'] ?? 10),
            'level'      => $params['level'] ?? 'medium',
            'status'     => 1,
        ]);
        return ['code' => 0, 'msg' => 'ok', 'data' => ['id' => $id]];
    }

    public function updateRule($tenantId, $id, $params) {
        Db::setTenantId($tenantId);
        $data = [];
        foreach (['rule_name', 'rule_type', 'score', 'level', 'status'] as $f) {
            if (isset($params[$f])) $data[$f] = $params[$f];
        }
        if (isset($params['rule_config'])) {
            $data['rule_config'] = is_string($params['rule_config']) ? $params['rule_config'] : json_encode($params['rule_config'], JSON_UNESCAPED_UNICODE);
        }
        if (empty($data)) return ['code' => 400, 'msg' => '没有可更新的字段'];
        Db::update('kefu_quality_rule', $data, ['id' => $id]);
        return ['code' => 0, 'msg' => 'ok'];
    }

    /**
     * 列质检结果
     */
    public function listResults($tenantId, $params = []) {
        $page = max(1, intval($params['page'] ?? 1));
        $size = min(100, max(10, intval($params['size'] ?? 20)));
        $offset = ($page - 1) * $size;

        Db::setTenantId($tenantId);
        $where = 'WHERE r.tenant_id = :t';
        $bind = [':t' => $tenantId];
        if (!empty($params['agent_id'])) {
            $where .= ' AND r.agent_id = :a';
            $bind[':a'] = intval($params['agent_id']);
        }
        if (!empty($params['review_status'])) {
            $where .= ' AND r.review_status = :rs';
            $bind[':rs'] = $params['review_status'];
        }

        $total = Db::value("SELECT COUNT(*) FROM kefu_quality_result r $where", $bind);
        $bind[':limit'] = $size;
        $bind[':offset'] = $offset;

        $rows = Db::query(
            "SELECT r.*, e.real_name AS agent_name, qr.rule_name
             FROM kefu_quality_result r
             LEFT JOIN kefu_employee e ON e.id = r.agent_id
             LEFT JOIN kefu_quality_rule qr ON qr.id = r.rule_id
             $where
             ORDER BY r.id DESC
             LIMIT :limit OFFSET :offset",
            $bind
        );

        return ['list' => $rows, 'total' => (int)$total, 'page' => $page, 'size' => $size];
    }

    /**
     * 整体统计
     */
    public function stats($tenantId, $params = []) {
        $startDate = $params['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $params['end_date'] ?? date('Y-m-d');

        Db::setTenantId($tenantId);
        $where = "WHERE tenant_id = :t AND DATE(created_at) BETWEEN :sd AND :ed";
        $bind = [':t' => $tenantId, ':sd' => $startDate, ':ed' => $endDate];

        $total = Db::value("SELECT COUNT(*) FROM kefu_quality_result $where", $bind);
        $avgScore = Db::value("SELECT AVG(score_impact) FROM kefu_quality_result $where", $bind);

        $statusDist = Db::query(
            "SELECT review_status, COUNT(*) AS cnt FROM kefu_quality_result $where GROUP BY review_status",
            $bind
        );

        $agentRank = Db::query(
            "SELECT r.agent_id, e.real_name AS agent_name,
                    COUNT(*) AS cnt,
                    SUM(score_impact) AS total_deduct,
                    SUM(IF(score_impact = 0, 1, 0)) AS passed,
                    SUM(IF(score_impact < 0, 1, 0)) AS failed
             FROM kefu_quality_result r
             LEFT JOIN kefu_employee e ON e.id = r.agent_id
             $where
             GROUP BY r.agent_id, e.real_name
             ORDER BY total_deduct DESC
             LIMIT 10",
            $bind
        );

        return [
            'period'      => [$startDate, $endDate],
            'total'       => (int)$total,
            'avg_score_impact' => round((float)$avgScore, 1),
            'status_dist' => $statusDist,
            'agent_rank'  => $agentRank,
        ];
    }
}