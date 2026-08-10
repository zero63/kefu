<?php
/**
 * 多渠道管理 Controller
 * - 列出渠道类型 + 账号数
 * - 渠道启用/禁用
 * - 账号 CRUD
 */
namespace app\controller\admin;

use support\Request;
use app\lib\Db;

class ChannelMgmtController
{
    /**
     * 列出所有渠道类型（含租户的账号数）
     */
    public function list(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);

        $rows = Db::query(
            "SELECT t.*,
                    (SELECT COUNT(*) FROM kefu_channel_account a
                     WHERE a.tenant_id = :t AND a.channel_id = t.id AND a.status = 1) AS account_count,
                    (SELECT COUNT(*) FROM kefu_channel_account a
                     WHERE a.tenant_id = :t AND a.channel_id = t.id) AS account_total
             FROM kefu_channel_type t
             WHERE t.enabled = 1
             ORDER BY t.sort_no, t.id"
        );
        // 添加每个租户的启用状态
        foreach ($rows as &$r) {
            $r['account_count'] = (int)$r['account_count'];
            $r['account_total'] = (int)$r['account_total'];
            $r['enabled'] = 1; // 默认启用（实际由每个账号的 status 控制）
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    /**
     * 渠道详情 + 账号列表
     */
    public function detail(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->get('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id 必填']);
        Db::setTenantId($tenantId);
        $channel = Db::find("SELECT * FROM kefu_channel_type WHERE id = :id", [':id' => $id]);
        if (!$channel) return json(['code' => 404, 'msg' => '渠道不存在']);
        $accounts = Db::query(
            "SELECT id, account_name, account_id, app_id, status, verified_at, created_at, webhook_url
             FROM kefu_channel_account
             WHERE tenant_id = :t AND channel_id = :c
             ORDER BY id DESC",
            [':t' => $tenantId, ':c' => $id]
        );
        // 隐藏敏感字段
        foreach ($accounts as &$a) {
            unset($a['app_secret'], $a['api_secret']);
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'channel' => $channel, 'accounts' => $accounts
        ]]);
    }

    /**
     * 创建账号
     */
    public function createAccount(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $channelId = intval($request->post('channel_id', 0));
        $accountName = trim($request->post('account_name', ''));
        if (!$channelId || !$accountName) return json(['code' => 400, 'msg' => 'channel_id 和 account_name 必填']);
        Db::setTenantId($tenantId);
        $exists = Db::value(
            "SELECT COUNT(*) FROM kefu_channel_account WHERE tenant_id = :t AND channel_id = :c AND account_name = :n",
            [':t' => $tenantId, ':c' => $channelId, ':n' => $accountName]
        );
        if ($exists > 0) return json(['code' => 400, 'msg' => '账号名称已存在']);
        $id = Db::insert('kefu_channel_account', [
            'tenant_id' => $tenantId,
            'channel_id' => $channelId,
            'account_name' => $accountName,
            'account_id' => trim($request->post('account_id', '')),
            'app_id' => trim($request->post('app_id', '')),
            'app_secret' => $request->post('app_secret', ''),
            'api_key' => $request->post('api_key', ''),
            'api_secret' => $request->post('api_secret', ''),
            'webhook_url' => $request->post('webhook_url', ''),
            'token' => $request->post('token', ''),
            'encoding_aes_key' => $request->post('encoding_aes_key', ''),
            'config_json' => $request->post('config_json', null) ? json_encode($request->post('config_json'), JSON_UNESCAPED_UNICODE) : null,
            'status' => 1,
        ]);
        return json(['code' => 0, 'msg' => '已创建', 'data' => ['id' => $id]]);
    }

    /**
     * 更新账号
     */
    public function updateAccount(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id 必填']);
        Db::setTenantId($tenantId);
        $data = [];
        foreach (['account_name','account_id','app_id','token','encoding_aes_key','webhook_url'] as $f) {
            if ($request->post($f) !== null) $data[$f] = trim($request->post($f, ''));
        }
        foreach (['app_secret','api_key','api_secret'] as $f) {
            if ($request->post($f) !== null && $request->post($f) !== '') $data[$f] = $request->post($f);
        }
        if ($request->post('status') !== null) $data['status'] = intval($request->post('status'));
        if ($request->post('config_json') !== null) {
            $cfg = $request->post('config_json');
            if (is_array($cfg)) $data['config_json'] = json_encode($cfg, JSON_UNESCAPED_UNICODE);
        }
        if (!$data) return json(['code' => 400, 'msg' => '没有可更新字段']);
        Db::update('kefu_channel_account', $data, ['id' => $id]);
        return json(['code' => 0, 'msg' => '已更新']);
    }

    /**
     * 启用/禁用
     */
    public function toggleAccount(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        $status = intval($request->post('status', 1));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id 必填']);
        Db::setTenantId($tenantId);
        Db::update('kefu_channel_account', ['status' => $status], ['id' => $id]);
        return json(['code' => 0, 'msg' => $status ? '已启用' : '已禁用']);
    }

    /**
     * 删除账号
     */
    public function deleteAccount(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id 必填']);
        Db::setTenantId($tenantId);
        Db::exec("DELETE FROM kefu_channel_account WHERE tenant_id = :t AND id = :id",
            [':t' => $tenantId, ':id' => $id]);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    /**
     * 列出所有渠道类型（含说明），用于创建账号时下拉
     */
    public function types(Request $request)
    {
        $rows = Db::query("SELECT id, channel_code, channel_name, icon, description FROM kefu_channel_type WHERE enabled = 1 ORDER BY sort_no, id");
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }
}