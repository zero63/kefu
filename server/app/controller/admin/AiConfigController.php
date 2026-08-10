<?php
/**
 * 管理员端 - AI 智能体配置
 *
 * 路由（已注册在 config/route.php）：
 *   GET  /api/admin/ai/config           取本租户配置
 *   POST /api/admin/ai/config/save      保存
 *   POST /api/admin/ai/test             调通性测试（发一条 sample 消息，验证 AI 返回）
 */

namespace app\controller\admin;

use support\Request;
use app\service\AiConfigService;
use app\lib\QianfanClient;
use app\lib\Db;

class AiConfigController
{
    public function get(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $svc = new AiConfigService();
        $cfg = $svc->getConfig($tenantId);
        // 安全：secret_key 返回掩码
        if (!empty($cfg['secret_key'])) {
            $cfg['secret_key_masked'] = substr($cfg['secret_key'], 0, 6) . '****';
        }
        if (!empty($cfg['api_key'])) {
            $cfg['api_key_masked'] = substr($cfg['api_key'], 0, 6) . '****';
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => $cfg]);
    }

    public function save(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $operatorId = intval($request->employee_id ?? 0);
        $params = $request->post();
        $svc = new AiConfigService();
        return json($svc->saveConfig($tenantId, $params, $operatorId));
    }

    /**
     * 测试 AI 接口连通
     *   POST /api/admin/ai/test
     *   Body: { content: "你好" }
     */
    public function test(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $content = trim($request->post('content', '你好'));
        $svc = new AiConfigService();
        $cfg = $svc->getConfig($tenantId);
        if (!$cfg['enabled'] || !$cfg['api_key']) {
            return json(['code' => 400, 'msg' => 'AI 未启用或未配置 API Key']);
        }
        $cli = new QianfanClient(
            $cfg['api_key'],
            $cfg['app_id'] ?? '',
            [
                'endpoint' => $cfg['endpoint'] ?: null,
                'secret_key' => $cfg['secret_key'] ?? '',
                'timeout_ms' => $cfg['max_response_ms'] ?? 6000,
            ]
        );
        $msgs = QianfanClient::buildMessages([], $cfg['system_prompt'], 0);
        $msgs[] = ['role' => 'user', 'content' => $content];
        $r = $cli->chat($msgs);
        return json($r['success']
            ? ['code' => 0, 'msg' => 'ok', 'data' => ['reply' => $r['content'], 'tokens' => $r['tokens']]]
            : ['code' => 500, 'msg' => $r['error']]
        );
    }
}