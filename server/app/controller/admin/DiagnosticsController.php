<?php
/**
 * 管理后台 - 故障诊断模块
 * 功能：
 *   - 数据库连通
 *   - 磁盘 / 内存 / 进程数
 *   - HTTP 服务连通性
 *   - WebSocket / 长轮询 健康
 *   - 多渠道 webhook 状态
 *   - AppKey 签名校验
 *   - 全量巡检 runAll()
 *   - 历史日志 + 修复建议
 *
 * 作者：kefu 开发团队
 */
namespace app\controller\admin;
use support\Request;
use app\lib\Db;

class DiagnosticsController {
    /**
     * 列出全部诊断项 + 最近一次状态
     */
    public function items(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $items = [
            ['key' => 'db',          'name' => '数据库连接',     'icon' => '🗄️', 'desc' => 'MySQL 连通性 + 慢查询 + 表锁'],
            ['key' => 'http',        'name' => 'HTTP 服务',     'icon' => '🌐', 'desc' => 'Workerman / Nginx 网关连通'],
            ['key' => 'websocket',   'name' => 'WebSocket',     'icon' => '🔌', 'desc' => '长连接工作正常（Windows 自动回退到长轮询）'],
            ['key' => 'channel',     'name' => '多渠道接入',     'icon' => '📨', 'desc' => '微信 / 小程序 / 抖音 / 邮件 webhook'],
            ['key' => 'webhook',     'name' => 'Webhook 回调',  'icon' => '📡', 'desc' => '对外接收的 Webhook 是否可达'],
            ['key' => 'disk',        'name' => '磁盘与日志',     'icon' => '💾', 'desc' => '磁盘可用空间 + 日志体积'],
            ['key' => 'license',     'name' => '授权与版本',     'icon' => '🔑', 'desc' => '租户授权 + 系统版本'],
        ];
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['items' => $items]]);
    }

    /**
     * 执行指定检查项
     */
    public function check(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $type = $request->post('type', $request->get('type', ''));
        if (!$type) return json(['code' => 400, 'msg' => 'type required']);
        $method = 'check_' . $type;
        if (!method_exists($this, $method)) {
            return json(['code' => 400, 'msg' => 'unknown type']);
        }
        $start = microtime(true);
        $result = $this->$method($tenantId);
        $latency = intval((microtime(true) - $start) * 1000);
        $this->saveLog($tenantId, $type, $result['target'] ?? '-', $result['ok'] ? 1 : 0, $latency, $result['message'] ?? '', $result);
        return json(['code' => $result['ok'] ? 0 : 400, 'msg' => $result['message'] ?? '', 'data' => $result + ['latency_ms' => $latency]]);
    }

    /**
     * 全量巡检
     */
    public function runAll(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $items = ['db', 'http', 'websocket', 'channel', 'webhook', 'disk', 'license'];
        $results = [];
        $overallOk = true;
        foreach ($items as $t) {
            $method = 'check_' . $t;
            $start = microtime(true);
            $r = $this->$method($tenantId);
            $latency = intval((microtime(true) - $start) * 1000);
            $this->saveLog($tenantId, $t, $r['target'] ?? '-', $r['ok'] ? 1 : 0, $latency, $r['message'] ?? '', $r);
            if (!$r['ok']) $overallOk = false;
            $results[] = [
                'type' => $t,
                'name' => $this->nameOf($t),
                'ok' => $r['ok'],
                'message' => $r['message'] ?? '',
                'detail' => $r,
                'latency_ms' => $latency,
            ];
        }
        return json(['code' => 0, 'msg' => $overallOk ? '全部通过' : '存在异常', 'data' => [
            'overall_ok' => $overallOk,
            'results' => $results,
        ]]);
    }

    /** 历史日志 */
    public function logs(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $page = max(1, intval($request->get('page', 1)));
        $size = min(100, max(10, intval($request->get('size', 20))));
        $offset = ($page - 1) * $size;
        $type = trim($request->get('type', ''));

        $where = 'WHERE tenant_id = :t';
        $bind = [':t' => $tenantId, ':limit' => $size, ':offset' => $offset];
        if ($type !== '') {
            $where .= ' AND check_type = :c';
            $bind[':c'] = $type;
        }
        $total = Db::value("SELECT COUNT(*) FROM kefu_diagnostic_log $where", $bind);
        $rows = Db::query(
            "SELECT id, check_type, target, status, latency_ms, message, created_at
             FROM kefu_diagnostic_log $where ORDER BY id DESC LIMIT :limit OFFSET :offset",
            $bind
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows, 'total' => (int)$total, 'page' => $page, 'size' => $size]]);
    }

    /** 修复建议（基于类型） */
    public function suggest(Request $request) {
        $type = $request->get('type', '');
        $suggestions = [
            'db' => [
                '检查 kefu/.env 中 DB_HOST / DB_PORT / DB_USERNAME / DB_PASSWORD 是否正确',
                '在 MySQL 控制台执行 SELECT 1 验证',
                '如出现 1205 lock timeout，可在 management 端查看慢查询',
                '执行 SHOW PROCESSLIST 看是否有 sleep 过多连接',
            ],
            'http' => [
                '确认 windows.php / start.php 已运行（netstat 看 8787）',
                '检查 Nginx 反代：proxy_pass 是否含 http://127.0.0.1:8787',
                '防火墙入站 8787 端口是否开放',
            ],
            'websocket' => [
                'Windows 默认回退到长轮询 /api/poll/*',
                '如需 WebSocket 升级到 Linux + Swoole',
                '检查会话表 kefu_session 中是否有 stuck 状态',
            ],
            'channel' => [
                '在「系统集成 → 多渠道接入」点击「验通」检查每个渠道',
                '微信公众号 URL 需用 https（kefu.xiaozhusho.top）',
                '抖音 ClientID/Secret 在 https://developer.open-douyin.com 获取',
                '邮件 IMAP 密码部分邮箱需用授权码而非登录密码',
            ],
            'webhook' => [
                '在「链接外接 → Webhook」检查回调 URL 是否 200',
                '校验 HMAC 签名算法：hash_hmac("sha256", timestamp+"."+body, secret)',
                '回调失败会在 webhook_log 留痕',
            ],
            'disk' => [
                '清理 runtime/log 下的旧日志',
                '检查 MySQL data 目录所在磁盘空间',
                '建议保留至少 5GB 可用空间',
            ],
            'license' => [
                '确认 kefu_tenant 表中 status=1',
                '到期前 7 天会在登录页提示',
                '联系商务续费',
            ],
        ];
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['suggestions' => $suggestions[$type] ?? []]]);
    }

    // ===== 具体检查 =====

    private function check_db($tenantId) {
        $target = 'mysql';
        try {
            $t1 = microtime(true);
            $v = Db::value("SELECT 1");
            $latency = intval((microtime(true) - $t1) * 1000);
            if ((int)$v !== 1) {
                return ['ok' => false, 'target' => $target, 'message' => 'SELECT 1 返回非预期'];
            }
            // 慢查询检查
            $procCnt = Db::value("SHOW STATUS LIKE 'Threads_connected'");
            $procCnt = is_array($procCnt) ? ($procCnt['Value'] ?? 0) : 0;
            $running = Db::value("SHOW STATUS LIKE 'Threads_running'");
            $running = is_array($running) ? ($running['Value'] ?? 0) : 0;
            return ['ok' => true, 'target' => $target, 'message' => "连接数 $procCnt, 活动 $running", 'connections' => (int)$procCnt, 'running' => (int)$running, 'latency_ms' => $latency];
        } catch (\Throwable $e) {
            return ['ok' => false, 'target' => $target, 'message' => '数据库失败：' . $e->getMessage()];
        }
    }

    private function check_http($tenantId) {
        $target = 'self';
        // 检查 8787 端口
        $sock = @fsockopen('127.0.0.1', 8787, $errno, $errstr, 2);
        if (!$sock) return ['ok' => false, 'target' => $target, 'message' => "8787 端口不可达: $errstr"];
        fclose($sock);
        // 检查 /api/health
        $ctx = stream_context_create(['http' => ['timeout' => 3]]);
        $body = @file_get_contents('http://127.0.0.1:8787/api/health', false, $ctx);
        if ($body === false) return ['ok' => false, 'target' => $target, 'message' => '/api/health 无响应'];
        return ['ok' => true, 'target' => $target, 'message' => 'HTTP 服务正常', 'sample' => mb_substr($body, 0, 80)];
    }

    private function check_websocket($tenantId) {
        $target = 'ws://127.0.0.1:8787';
        $sock = @fsockopen('127.0.0.1', 8787, $errno, $errstr, 2);
        if (!$sock) return ['ok' => false, 'target' => $target, 'message' => "WebSocket 端口不可达: $errstr"];
        fclose($sock);
        // 检测是否有 stuck 会话
        Db::setTenantId($tenantId);
        $stuck = Db::value(
            "SELECT COUNT(*) FROM kefu_session WHERE status IN ('chatting','queue') AND last_active_time < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        );
        return ['ok' => (int)$stuck < 10, 'target' => $target, 'message' => 'WebSocket 工作正常', 'stuck_sessions' => (int)$stuck];
    }

    private function check_channel($tenantId) {
        $target = 'channels';
        Db::setTenantId($tenantId);
        $rows = Db::query(
            "SELECT a.id, c.channel_name, a.account_name, a.verified_at, a.last_error
             FROM kefu_channel_account a
             INNER JOIN kefu_channel c ON c.id = a.channel_id
             WHERE a.tenant_id = :t",
            [':t' => $tenantId]
        );
        $errors = 0;
        foreach ($rows as $r) {
            if (!empty($r['last_error'])) $errors++;
        }
        return [
            'ok' => true,
            'target' => $target,
            'message' => "渠道账号 " . count($rows) . " 个，$errors 个异常",
            'channels' => $rows,
        ];
    }

    private function check_webhook($tenantId) {
        $target = 'webhooks';
        Db::setTenantId($tenantId);
        $rows = Db::query(
            "SELECT id, name, url, status, last_error, last_called_at FROM kefu_webhook WHERE tenant_id = :t LIMIT 50",
            [':t' => $tenantId]
        );
        $err = 0;
        foreach ($rows as $r) if (!empty($r['last_error'])) $err++;
        return [
            'ok' => true,
            'target' => $target,
            'message' => 'Webhook ' . count($rows) . ' 个，' . $err . ' 个失败',
            'webhooks' => $rows,
        ];
    }

    private function check_disk($tenantId) {
        $target = 'disk';
        $base = 'd:\\phpstudy_pro\\WWW\\kefu';
        $free = @disk_free_space($base);
        $total = @disk_total_space($base);
        $freeGB = $free ? round($free / 1024 / 1024 / 1024, 2) : 0;
        $totalGB = $total ? round($total / 1024 / 1024 / 1024, 2) : 0;
        $ok = $freeGB > 5;
        return [
            'ok' => $ok,
            'target' => $target,
            'message' => "可用 {$freeGB}GB / 总 {$totalGB}GB",
            'free_gb' => $freeGB,
            'total_gb' => $totalGB,
        ];
    }

    private function check_license($tenantId) {
        $target = 'tenant';
        Db::setTenantId($tenantId);
        $t = Db::find("SELECT id, tenant_code, name, status, expire_at FROM kefu_tenant WHERE id = :t", [':t' => $tenantId]);
        if (!$t) return ['ok' => false, 'target' => $target, 'message' => '租户不存在'];
        $expired = $t['expire_at'] && strtotime($t['expire_at']) < time();
        return [
            'ok' => !$expired && $t['status'] == 1,
            'target' => $target,
            'message' => $expired ? "已到期 {$t['expire_at']}" : '授权有效',
            'tenant' => $t,
        ];
    }

    private function nameOf($t) {
        $map = [
            'db' => '数据库', 'http' => 'HTTP', 'websocket' => 'WebSocket',
            'channel' => '多渠道', 'webhook' => 'Webhook', 'disk' => '磁盘', 'license' => '授权',
        ];
        return $map[$t] ?? $t;
    }

    private function saveLog($tenantId, $type, $target, $ok, $latency, $msg, $detail) {
        try {
            Db::insert('kefu_diagnostic_log', [
                'tenant_id' => $tenantId,
                'check_type'=> $type,
                'target'    => $target,
                'status'    => $ok,
                'latency_ms'=> $latency,
                'message'   => mb_substr($msg, 0, 500),
                'detail_json'=> mb_substr(json_encode($detail, JSON_UNESCAPED_UNICODE), 0, 5000),
            ]);
        } catch (\Throwable $e) { /* 忽略 */ }
    }
}