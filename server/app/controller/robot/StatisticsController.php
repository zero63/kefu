<?php
/**
 * 机器人 - 统计
 */
namespace app\controller\robot;
use support\Request;
use app\lib\Db;

class StatisticsController {
    public function overview(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);

        $today = date('Y-m-d');
        $totalKnowledge = Db::value("SELECT COUNT(*) FROM kefu_knowledge WHERE tenant_id=:t AND status=1", [':t' => $tenantId]);
        $hitToday = Db::value(
            "SELECT COUNT(*) FROM kefu_message WHERE tenant_id=:t AND sender_type='robot' AND DATE(created_at)=:d",
            [':t' => $tenantId, ':d' => $today]
        );
        $totalHit = Db::value(
            "SELECT SUM(hit_count) FROM kefu_knowledge WHERE tenant_id=:t",
            [':t' => $tenantId]
        );

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'total_knowledge' => (int)$totalKnowledge,
            'hit_today'       => (int)$hitToday,
            'total_hit'       => (int)$totalHit,
            'date'            => $today,
        ]]);
    }
}