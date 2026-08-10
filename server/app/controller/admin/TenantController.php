<?php
/**
 * 管理后台 - 企业（租户）设置
 * 功能：查看/修改当前企业的基础信息、客服端配置、超时参数等
 * 说明：仅超级管理员（role_id=1）可修改企业信息
 * 作者：kefu 开发团队
 */
namespace app\controller\admin;

use support\Request;
use app\lib\Db;

class TenantController
{
    /**
     * 获取当前租户信息
     */
    public function info(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        if ($tenantId <= 0) {
            return json(['code' => 400, 'msg' => '租户未识别']);
        }
        // 租户表本身不带 tenant_id 列（顶层表），不要调用 setTenantId
        $row = Db::find(
            "SELECT id, tenant_code, tenant_name, contact_name, contact_phone,
                    contact_email, plan, status, expire_at, created_at
             FROM kefu_tenant WHERE id = :id",
            [':id' => $tenantId]
        );
        if (!$row) {
            return json(['code' => 404, 'msg' => '租户不存在']);
        }

        // 读取该租户在 kefu_config 中的客服端配置
        Db::setTenantId($tenantId);
        $configRows = Db::query(
            "SELECT config_key, config_value FROM kefu_config WHERE tenant_id = :t",
            [':t' => $tenantId]
        );
        $config = [];
        foreach ($configRows as $r) {
            $config[$r['config_key']] = $r['config_value'];
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'tenant' => $row,
            'config' => $config,
        ]]);
    }

    /**
     * 更新企业基础信息
     */
    public function update(Request $request)
    {
        // 仅 role_id <= 2（超管/管理员）可改
        $roleId = intval($request->role_id ?? 0);
        if ($roleId > 2) {
            return json(['code' => 403, 'msg' => '无权操作']);
        }
        $tenantId = intval($request->tenant_id ?? 0);
        if ($tenantId <= 0) {
            return json(['code' => 400, 'msg' => '租户未识别']);
        }

        $data = [];
        foreach (['tenant_name', 'contact_name', 'contact_phone', 'contact_email'] as $f) {
            if ($request->post($f) !== null) {
                $data[$f] = trim($request->post($f, ''));
            }
        }
        if (empty($data)) {
            return json(['code' => 400, 'msg' => '没有可更新的字段']);
        }
        // 顶层表，先 setTenantId(0) 关闭自动注入，否则 update 会自动加 WHERE tenant_id
        Db::setTenantId(0);
        $affected = Db::update('kefu_tenant', $data, ['id' => $tenantId]);
        Db::setTenantId($tenantId);
        return json(['code' => 0, 'msg' => '更新成功']);
    }

    /**
     * 批量保存客服端配置（key/value 形式）
     * 入参：{ items: [{config_key, config_value}, ...] }
     */
    public function saveConfig(Request $request)
    {
        $roleId = intval($request->role_id ?? 0);
        if ($roleId > 2) {
            return json(['code' => 403, 'msg' => '无权操作']);
        }
        $tenantId = intval($request->tenant_id ?? 0);
        $items = $request->post('items', []);
        if (!is_array($items) || empty($items)) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        Db::setTenantId($tenantId);
        $now = date('Y-m-d H:i:s');
        foreach ($items as $it) {
            $key = trim($it['config_key'] ?? '');
            $val = (string)($it['config_value'] ?? '');
            if ($key === '') continue;

            // 简化版：直接 INSERT ... ON DUPLICATE KEY UPDATE
            $sql = "INSERT INTO kefu_config (tenant_id, config_key, config_value, updated_at)
                    VALUES (:t, :k, :v, :n)
                    ON DUPLICATE KEY UPDATE config_value = :v2, updated_at = :n2";
            $pdo = Db::pdo();
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':t'  => $tenantId, ':k'  => $key, ':v'  => $val, ':n'  => $now,
                ':v2' => $val,      ':n2' => $now,
            ]);
        }
        return json(['code' => 0, 'msg' => '保存成功']);
    }

    /**
     * 获取客服端默认配置（首次加载时填充）
     */
    public function defaultConfig()
    {
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'defaults' => [
                'session_timeout_min'    => 30,    // 客户无操作 N 分钟后自动关闭会话
                'first_response_sec'     => 60,    // 首次响应目标时长（秒）
                'robot_fallback_human'   => 1,     // 机器人无法回答时是否转人工
                'max_queue_per_agent'    => 5,     // 单客服最大同时会话数
                'welcome_msg'            => '您好，请问有什么可以帮您？',
                'offline_msg'            => '客服暂未上线，请留言',
                'enable_evaluate'        => 1,     // 是否开启评价
                'enable_sensitive_check' => 1,     // 是否开启敏感词
                'working_hours_start'    => '09:00',
                'working_hours_end'      => '21:00',
            ]
        ]]);
    }
}