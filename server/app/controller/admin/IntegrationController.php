<?php
/**
 * 第三方集成（开发者ID + CRM 对接 + 链接外接 / Webhook + 网页接入）
 * 作者：kefu 开发团队
 * 创建时间：2026-08-01
 * 第三方集成（开发者ID + CRM 对接 + 链接外接 / Webhook + 网页接入）
 */
namespace app\controller\admin;

use support\Request;
use app\lib\Db;

class IntegrationController
{
    // ============ 开发者ID + CRM 信息对接 ============

    public function get(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $type = trim($request->get('type', 'developer'));
        $row = Db::find(
            "SELECT * FROM kefu_integration WHERE tenant_id = :t AND type = :ty",
            [':t' => $tenantId, ':ty' => $type]
        );
        if ($row && $row['config_json']) {
            $cfg = json_decode($row['config_json'], true) ?: [];
            $row = array_merge($row, $cfg);
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => $row ?: (object)[]]);
    }

    public function save(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $type = trim($request->post('type', 'developer'));
        if (!in_array($type, ['developer', 'crm'])) {
            return json(['code' => 400, 'msg' => '类型必须是 developer 或 crm']);
        }
        $data = [
            'tenant_id'  => $tenantId,
            'type'       => $type,
            'app_key'    => trim($request->post('app_key', '')),
            'app_secret' => trim($request->post('app_secret', '')),
            'app_id'     => trim($request->post('app_id', '')),
            'enabled'    => intval($request->post('enabled', 1)),
        ];
        // 其余字段入 config_json
        $extra = [];
        foreach (['token_url', 'user_info_url', 'user_detail_url', 'user_order_url',
                  'user_order_detail_url', 'minip_xiaohongshu_url', 'app_new_appointment_url',
                  'session_url', 'ticket_url', 'robot_call_url', 'customer_card_url',
                  'customer_tag_url'] as $f) {
            if ($request->post($f) !== null) {
                $extra[$f] = trim($request->post($f));
            }
        }
        $data['config_json'] = json_encode($extra, JSON_UNESCAPED_UNICODE);

        $exists = Db::find(
            "SELECT id FROM kefu_integration WHERE tenant_id = :t AND type = :ty",
            [':t' => $tenantId, ':ty' => $type]
        );
        if ($exists) {
            Db::update('kefu_integration', $data, ['id' => $exists['id']]);
        } else {
            Db::insert('kefu_integration', $data);
        }
        return json(['code' => 0, 'msg' => '已保存']);
    }

    // ============ 链接外接 / Webhook ============

    public function webhookList(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $rows = Db::query(
            "SELECT id, name, url, events, enabled, secret, created_at
             FROM kefu_webhook WHERE tenant_id = :t ORDER BY id DESC",
            [':t' => $tenantId]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    public function webhookCreate(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $name = trim($request->post('name', ''));
        $url = trim($request->post('url', ''));
        if ($name === '' || $url === '') return json(['code' => 400, 'msg' => '名称和URL必填']);
        if (!preg_match('~^https?://~', $url)) {
            return json(['code' => 400, 'msg' => 'URL 必须以 http(s):// 开头']);
        }
        $events = $request->post('events', []);
        if (is_array($events)) $events = implode(',', $events);
        Db::setTenantId($tenantId);
        $id = Db::insert('kefu_webhook', [
            'tenant_id' => $tenantId,
            'name'      => $name,
            'url'       => $url,
            'events'    => $events,
            'enabled'   => intval($request->post('enabled', 1)),
            'secret'    => trim($request->post('secret', '')) ?: bin2hex(random_bytes(16)),
        ]);
        return json(['code' => 0, 'msg' => '已创建', 'data' => ['id' => $id]]);
    }

    public function webhookDelete(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        Db::delete('kefu_webhook_log', ['webhook_id' => $id]);
        Db::delete('kefu_webhook', ['id' => $id]);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    public function webhookToggle(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        Db::exec("UPDATE kefu_webhook SET enabled = 1 - enabled
                   WHERE tenant_id = :t AND id = :id",
            [':t' => $tenantId, ':id' => $id]);
        return json(['code' => 0, 'msg' => '已切换']);
    }

    public function webhookLog(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $wid = intval($request->get('webhook_id', 0));
        $rows = Db::query(
            "SELECT id, event, status, response_code, created_at,
                    SUBSTRING(payload, 1, 100) AS payload_preview,
                    SUBSTRING(response_body, 1, 100) AS response_preview
             FROM kefu_webhook_log
             WHERE tenant_id = :t AND (:w = 0 OR webhook_id = :w)
             ORDER BY id DESC LIMIT 100",
            [':t' => $tenantId, ':w' => $wid]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    public function webhookTest(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        $w = Db::find(
            "SELECT * FROM kefu_webhook WHERE tenant_id = :t AND id = :id",
            [':t' => $tenantId, ':id' => $id]
        );
        if (!$w) return json(['code' => 404, 'msg' => 'Webhook 不存在']);
        $payload = json_encode([
            'event'    => 'test.ping',
            'tenant_id'=> $tenantId,
            'time'     => date('c'),
            'data'     => ['msg' => 'Hello from kefu webhook'],
        ], JSON_UNESCAPED_UNICODE);
        // 发送 POST 请求
        $ch = curl_init($w['url']);
        $headers = ['Content-Type: application/json'];
        if ($w['secret']) {
            $headers[] = 'X-Kefu-Signature: ' . hash_hmac('sha256', $payload, $w['secret']);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        // 记录日志
        Db::insert('kefu_webhook_log', [
            'tenant_id'    => $tenantId,
            'webhook_id'   => $id,
            'event'        => 'test.ping',
            'payload'      => $payload,
            'response_code'=> $code,
            'response_body'=> $body,
            'status'       => $code >= 200 && $code < 300 ? 'success' : 'failed',
        ]);
        return json(['code' => 0, 'msg' => '测试已发送', 'data' => [
            'http_code' => $code,
            'response'  => substr($body ?? '', 0, 200),
            'error'     => $err,
        ]]);
    }

    // ============ 网页接入 Widget ============

    public function widgetList(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $rows = Db::query(
            "SELECT id, name, domain, enabled, visit_count, chat_count, created_at
             FROM kefu_web_widget WHERE tenant_id = :t ORDER BY id DESC",
            [':t' => $tenantId]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    public function widgetCreate(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $name = trim($request->post('name', ''));
        if ($name === '') return json(['code' => 400, 'msg' => '名称必填']);
        Db::setTenantId($tenantId);
        $id = Db::insert('kefu_web_widget', [
            'tenant_id'  => $tenantId,
            'name'       => $name,
            'domain'     => trim($request->post('domain', '*')),
            'config_json'=> json_encode($request->post('config', []), JSON_UNESCAPED_UNICODE),
            'enabled'    => 1,
        ]);
        return json(['code' => 0, 'msg' => '已创建', 'data' => ['id' => $id, 'snippet' => $this->getSnippet($tenantId, $id)]]);
    }

    public function widgetDelete(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        Db::delete('kefu_web_widget', ['id' => $id]);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    /**
     * 生成网页嵌入代码
     */
    private function getSnippet($tenantId, $widgetId)
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8787';
        return '<script src="https://' . $host . '/widget/kefu.js?w=' . $widgetId
             . '" data-widget="' . $widgetId . '" async></script>';
    }
}