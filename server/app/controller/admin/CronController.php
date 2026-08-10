<?php
/**
 * 手动触发定时任务（Windows 演示环境）
 * Linux 上由 CronWorker 进程自动每分钟执行，这里是兜底入口
 */
namespace app\controller\admin;

use support\Request;
use app\lib\Db;
use app\service\SessionService;
use app\process\CronWorker;

class CronController
{
    /**
     * POST /api/admin/cron/run
     * 触发一次所有定时任务；返回结果摘要
     */
    public function run(Request $request) {
        $roleId = intval($request->role_id ?? 0);
        if ($roleId > 2) return json(['code' => 403, 'msg' => '无权操作']);

        // 自动关闭超时会话（遍历所有租户）
        $tenants = Db::query("SELECT id FROM kefu_tenant WHERE status = 1");
        $totalClosed = 0;
        $details = [];
        foreach ($tenants as $t) {
            $tenantId = (int)$t['id'];
            $svc = new SessionService();
            // 先取超时配置
            Db::setTenantId($tenantId);
            $timeout = (int)Db::value(
                "SELECT config_value FROM kefu_config WHERE tenant_id = :t AND config_key = 'session_timeout_min'",
                [':t' => $tenantId]
            );
            if ($timeout < 5) $timeout = 30;
            $r = $svc->autoCloseTimeoutSessions($tenantId, $timeout, $timeout * 2);
            $totalClosed += $r['closed'];
            $details[] = ['tenant_id' => $tenantId, 'closed' => $r['closed']];
        }

        return json(['code' => 0, 'msg' => '执行完毕', 'data' => [
            'closed_total' => $totalClosed,
            'details'      => $details,
            'ran_at'       => date('Y-m-d H:i:s'),
        ]]);
    }

    /**
     * POST /api/admin/cron/close-expired
     * 仅触发超时会话关闭
     */
    public function closeExpired(Request $request) {
        $roleId = intval($request->role_id ?? 0);
        if ($roleId > 2) return json(['code' => 403, 'msg' => '无权操作']);

        $tenantId = intval($request->tenant_id ?? 1);
        Db::setTenantId($tenantId);
        $timeout = (int)Db::value(
            "SELECT config_value FROM kefu_config WHERE tenant_id = :t AND config_key = 'session_timeout_min'",
            [':t' => $tenantId]
        );
        if ($timeout < 5) $timeout = 30;
        $svc = new SessionService();
        $r = $svc->autoCloseTimeoutSessions($tenantId, $timeout, $timeout * 2);

        // 清理被禁用客服遗留的活跃会话
        $clean = (new CronWorker())->cleanupDisabledAgentSessions();

        return json(['code' => 0, 'msg' => "已关闭 {$r['closed']} 条超时会话, {$clean} 条禁用客服遗留", 'data' => ['closed' => $r['closed'], 'cleaned_disabled' => $clean]]);
    }
}