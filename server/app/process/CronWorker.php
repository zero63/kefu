<?php
/**
 * 定时任务进程
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 * 说明：
 *   - 定时清理过期数据、推送评价提醒、统计会话超时等
 *
 *   ⚠ Windows 下 webman 1.5 不支持 fork 自定义进程，
 *     这些任务在演示环境通过访问 /api/admin/cron 手动触发，
 *     真实部署到 Linux 服务器后由 cron-worker 进程自动执行。
 */

namespace app\process;

use app\lib\Db;
use app\lib\Logger;
use Workerman\Worker;

class CronWorker
{
    public function onWorkerStart($worker)
    {
        // 修复：定时任务原只在 Linux 触发（Windows 下 DIRECTORY_SEPARATOR !== '/'），导致超时会话永远不会被自动关闭。
        // 改为跨平台：每 60 秒执行一次，不依赖操作系统
        \Workerman\Timer::add(60, function () {
            try { $this->runTasks(); } catch (\Throwable $e) { \support\Log::error('cron task error: ' . $e->getMessage()); }
        });
        if (DIRECTORY_SEPARATOR === '/') {
            fwrite(STDOUT, "[cron-worker {$worker->id}] started\n");
        }
    }

    /**
     * 定时任务列表
     */
    public function runTasks()
    {
        $this->closeExpiredSessions();
        $this->cleanExpiredMessages();
        $this->evaluateRecycle();
        $this->checkSoothing();
        $this->cleanupDisabledAgentSessions();
        Logger::info('cron tasks done', ['at' => date('Y-m-d H:i:s')]);
    }

    /**
     * 清理被禁用/已删除客服遗留的活跃会话
     * - 检测：active / waiting 状态，且 agent_id 不为空
     * - 动作：状态改成 closed，关闭原因：agent_disabled；并写入一条系统消息提示访客
     * - 不删除原始记录，保留审计
     */
    public function cleanupDisabledAgentSessions()
    {
        try {
            $rows = Db::query(
                "SELECT s.id, s.session_id, s.tenant_id, s.customer_id, s.agent_id
                 FROM kefu_session s
                 LEFT JOIN kefu_employee e ON e.id = s.agent_id
                 WHERE s.status IN ('active','waiting')
                   AND s.agent_id > 0
                   AND (e.id IS NULL OR e.status = 0)"
            );
            if (empty($rows)) return 0;
            $count = 0;
            foreach ($rows as $s) {
                Db::exec(
                    "UPDATE kefu_session SET status='closed', close_reason='agent_disabled', closed_at=NOW()
                     WHERE id=:id",
                    [':id' => $s['id']]
                );
                // 写一条系统提示
                try {
                    Db::exec(
                        "INSERT INTO kefu_message (tenant_id, session_id, customer_id, sender_type, msg_type, content, status, created_at)
                         VALUES (:t,:s,:c,'system','text','原客服已被禁用，会话已自动关闭。如需继续沟通，请重新发起会话。','delivered',NOW())",
                        [':t' => $s['tenant_id'], ':s' => $s['session_id'], ':c' => $s['customer_id']]
                    );
                } catch (\Throwable $e) {}
                $count++;
            }
            if ($count > 0) Logger::info('cleanup_disabled_agent_sessions', ['count' => $count]);
            return $count;
        } catch (\Throwable $e) {
            Logger::error('cleanup_disabled_agent_sessions_failed', ['err' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * 关闭超时会话
     * 规则：客户无操作超过 kefu_config.session_timeout_min 分钟自动关闭
     * 不同租户可设置不同超时时间
     */
    private function closeExpiredSessions()
    {
        try {
            // 先按租户扫一遍：每个租户用自己的超时配置
            $tenants = Db::query("SELECT id FROM kefu_tenant WHERE status = 1");
            $totalClosed = 0;
            foreach ($tenants as $t) {
                $tenantId = (int)$t['id'];
                Db::setTenantId($tenantId);
                $timeoutMin = (int)Db::value(
                    "SELECT config_value FROM kefu_config WHERE tenant_id = :t AND config_key = 'session_timeout_min'",
                    [':t' => $tenantId]
                );
                if ($timeoutMin < 5) $timeoutMin = 30; // 默认 30 分钟，最小 5 分钟
                if ($timeoutMin > 1440) $timeoutMin = 1440; // 最大 24 小时

                $count = Db::exec(
                    "UPDATE kefu_session
                     SET status = 'closed',
                         closed_at = NOW(),
                         close_reason = 'timeout'
                     WHERE status IN ('active','waiting')
                       AND serving_mode = 'human'
                       AND agent_id IS NOT NULL
                       AND last_active_at < DATE_SUB(NOW(), INTERVAL $timeoutMin MINUTE)"
                );

                if ($count > 0) {
                    // 给每个被关闭的会话写一条系统提示
                    $rows = Db::query(
                        "SELECT session_id, customer_id FROM kefu_session
                         WHERE status = 'closed' AND close_reason = 'timeout'
                           AND closed_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
                           AND tenant_id = :t",
                        [':t' => $tenantId]
                    );
                    foreach ($rows as $r) {
                        try {
                            Db::insert('kefu_message', [
                                'tenant_id'   => $tenantId,
                                'session_id'  => $r['session_id'],
                                'customer_id' => (int)($r['customer_id'] ?? 0),
                                'sender_type' => 'system',
                                'msg_type'    => 'text',
                                'content'     => '由于客户长时间无操作，会话已自动关闭。客服可从"历史会话"重新接管。',
                                'status'      => 'delivered',
                                'created_at'  => date('Y-m-d H:i:s'),
                            ]);
                        } catch (\Throwable $e) {}
                    }
                    $totalClosed += $count;
                }
            }
            if ($totalClosed > 0) {
                Logger::info('closed expired sessions', ['count' => $totalClosed]);
            }
        } catch (\Exception $e) {
            Logger::error('closeExpiredSessions failed', ['err' => $e->getMessage()]);
        }
    }

    /**
     * 清理 7 天前的 sensitive_log
     */
    private function cleanExpiredMessages()
    {
        try {
            $count = Db::exec(
                "DELETE FROM kefu_sensitive_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
            );
            if ($count > 0) {
                Logger::info('cleaned sensitive logs', ['count' => $count]);
            }
        } catch (\Exception $e) {
            Logger::error('cleanExpiredMessages failed', ['err' => $e->getMessage()]);
        }
    }

    /**
     * 评价超时回收
     * 查询关闭超过 evaluation.timeout（默认 86400 秒）且未评价的会话，
     * 标记 close_reason 为 'no_evaluate'
     */
    private function evaluateRecycle()
    {
        try {
            // 从 kefu_config 读取评价超时配置，默认 86400 秒
            $timeout = intval(Db::value(
                "SELECT config_value FROM kefu_config WHERE tenant_id = 1 AND config_key = 'evaluation.timeout'"
            ));
            if ($timeout <= 0) {
                $timeout = 86400;
            }

            // 查找关闭超过超时时间且未评价、close_reason 非 no_evaluate 的会话
            $sessions = Db::query(
                "SELECT s.session_id, s.tenant_id
                 FROM kefu_session s
                 LEFT JOIN kefu_evaluate e ON e.session_id = s.session_id
                 WHERE s.status = 'closed'
                   AND s.closed_at IS NOT NULL
                   AND s.closed_at < DATE_SUB(NOW(), INTERVAL {$timeout} SECOND)
                   AND IFNULL(s.close_reason, '') != 'no_evaluate'
                   AND e.id IS NULL"
            );

            if (empty($sessions)) {
                return;
            }

            $count = 0;
            foreach ($sessions as $session) {
                Db::exec(
                    "UPDATE kefu_session SET close_reason = 'no_evaluate' WHERE session_id = :s AND tenant_id = :t",
                    [':s' => $session['session_id'], ':t' => $session['tenant_id']]
                );
                $count++;
            }

            if ($count > 0) {
                Logger::info('evaluateRecycle: 标记未评价超时会话', ['count' => $count, 'timeout' => $timeout]);
            }
        } catch (\Exception $e) {
            Logger::error('evaluateRecycle failed', ['err' => $e->getMessage()]);
        }
    }

    /**
     * 超时安抚语
     * 查询 active 会话中最后一条 customer 消息超过 30s 未有 agent 回复的，
     * 自动发一条系统安抚语"您好，客服正在为您查询，请稍等..."
     * 发送后该会话最后一条消息变为 system 类型，不会重复触发
     */
    private function checkSoothing()
    {
        try {
            // 查找 active 会话中最后一条消息来自 customer 且超过 30 秒未回复
            $sessions = Db::query(
                "SELECT s.session_id, s.tenant_id, s.customer_id, m.created_at AS last_msg_at
                 FROM kefu_session s
                 INNER JOIN kefu_message m ON m.session_id = s.session_id
                 WHERE s.status = 'active'
                   AND m.id = (
                       SELECT MAX(id) FROM kefu_message WHERE session_id = s.session_id
                   )
                   AND m.sender_type = 'customer'
                   AND m.created_at < DATE_SUB(NOW(), INTERVAL 30 SECOND)"
            );

            if (empty($sessions)) {
                return;
            }

            $soothingMsg = '您好，客服正在为您查询，请稍等...';

            foreach ($sessions as $session) {
                $tenantId = intval($session['tenant_id']);
                $sessionId = $session['session_id'];
                Db::setTenantId($tenantId);

                // 计算会话内消息序号
                $currentMaxSeq = intval(Db::value(
                    "SELECT IFNULL(MAX(session_sequence), 0) FROM kefu_message WHERE session_id = :s",
                    [':s' => $sessionId]
                ));
                $nextSeq = $currentMaxSeq + 1;

                // 插入系统安抚消息
                Db::insert('kefu_message', [
                    'session_id'       => $sessionId,
                    'customer_id'      => $session['customer_id'],
                    'agent_id'         => 0,
                    'sender_type'      => 'system',
                    'sender_id'        => '0',
                    'msg_type'         => 'text',
                    'content'          => $soothingMsg,
                    'ext_json'         => json_encode(['auto_soothing' => true]),
                    'session_sequence' => $nextSeq,
                    'status'           => 'delivered',
                ]);

                // 更新会话最后活跃时间和序号
                Db::exec(
                    "UPDATE kefu_session SET session_sequence = :seq, last_active_at = NOW() WHERE session_id = :s",
                    [':seq' => $nextSeq, ':s' => $sessionId]
                );

                // 推送安抚消息到会话
                if (class_exists('\\app\\lib\\ConnectionManager')) {
                    \app\lib\ConnectionManager::pushToSession($sessionId, [
                        'type'             => 'message',
                        'msg_id'           => 0,
                        'session_id'       => $sessionId,
                        'session_sequence' => $nextSeq,
                        'sender_type'      => 'system',
                        'sender_id'        => '0',
                        'msg_type'         => 'text',
                        'content'          => $soothingMsg,
                        'created_at'       => date('Y-m-d H:i:s'),
                    ]);
                }

                Logger::info('checkSoothing: 发送安抚语', ['session_id' => $sessionId]);
            }
        } catch (\Exception $e) {
            Logger::error('checkSoothing failed', ['err' => $e->getMessage()]);
        }
    }
}