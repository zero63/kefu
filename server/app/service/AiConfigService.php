<?php
namespace app\service;

use app\lib\Db;

/**
 * AI Agent 配置服务（租户级）
 * 启用 AI 智能体（即"管理员在后台开启智能体")的入口
 */
class AiConfigService
{
    /**
     * 取租户 AI 配置（无则创建默认）
     */
    public function getConfig($tenantId) {
        Db::setTenantId($tenantId);
        $row = Db::find("SELECT * FROM kefu_ai_agent_config WHERE tenant_id = :t", [':t' => $tenantId]);
        if ($row) {
            // 把 JSON 字段还原成 array
            $row['keywords_handoff_arr'] = $row['keywords_handoff'] ? json_decode($row['keywords_handoff'], true) : [];
            $row['negative_keywords_arr'] = $row['negative_keywords'] ? json_decode($row['negative_keywords'], true) : [];
            return $row;
        }
        // 默认配置
        return [
            'tenant_id' => $tenantId,
            'enabled' => 0,
            'provider' => 'qianfan',
            'api_key' => '',
            'secret_key' => '',
            'endpoint' => '',
            'app_id' => '',
            'system_prompt' => '你是企业的智能客服助手，名字叫小 e。请用简洁、友好的中文回复。',
            'greeting' => '你好，我是智能客服小 e，有什么可以帮您？',
            'keywords_handoff_arr' => ['人工', '人工客服', '转人工', '真人'],
            'negative_keywords_arr' => ['投诉', '退款', '差评', '不满意', '愤怒', '生气', '不行', '骗子', '解决不了', '尽快'],
            'negative_sentiment_handoff' => 1,
            'max_ai_rounds' => 8,
            'handoff_keyword_count' => 1,
            'max_response_ms' => 6000,
        ];
    }

    /**
     * 保存配置（敏感字段 api_key / secret_key 可不传，保留原值）
     */
    public function saveConfig($tenantId, $params, $operatorId = 0) {
        Db::setTenantId($tenantId);
        $cur = $this->getConfig($tenantId);

        // 提取 & 转码 JSON
        $kw = isset($params['keywords_handoff']) ? $params['keywords_handoff'] : (is_array($cur['keywords_handoff_arr']) ? $cur['keywords_handoff_arr'] : []);
        if (is_string($kw)) {
            // 支持 "人工,客服,转人工" 字符串格式
            $kw = array_filter(array_map('trim', preg_split('/[，,\n]/u', $kw)));
        }
        $nk = isset($params['negative_keywords']) ? $params['negative_keywords'] : (is_array($cur['negative_keywords_arr']) ? $cur['negative_keywords_arr'] : []);
        if (is_string($nk)) {
            $nk = array_filter(array_map('trim', preg_split('/[，,\n]/u', $nk)));
        }

        $data = [
            'tenant_id' => $tenantId,
            'enabled' => !empty($params['enabled']) ? 1 : 0,
            'provider' => $params['provider'] ?? 'qianfan',
            'system_prompt' => $params['system_prompt'] ?? '',
            'greeting' => $params['greeting'] ?? '',
            'keywords_handoff' => json_encode(array_values($kw), JSON_UNESCAPED_UNICODE),
            'negative_sentiment_handoff' => !empty($params['negative_sentiment_handoff']) ? 1 : 0,
            'negative_keywords' => json_encode(array_values($nk), JSON_UNESCAPED_UNICODE),
            'max_ai_rounds' => max(1, intval($params['max_ai_rounds'] ?? 8)),
            'handoff_keyword_count' => max(1, intval($params['handoff_keyword_count'] ?? 1)),
            'max_response_ms' => max(1000, intval($params['max_response_ms'] ?? 6000)),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // 敏感字段：只有非空时才更新
        if (!empty($params['api_key'])) $data['api_key'] = $params['api_key'];
        if (!empty($params['secret_key'])) $data['secret_key'] = $params['secret_key'];
        if (!empty($params['endpoint'])) $data['endpoint'] = $params['endpoint'];
        if (!empty($params['app_id'])) $data['app_id'] = $params['app_id'];

        // upsert
        $exists = Db::find("SELECT id FROM kefu_ai_agent_config WHERE tenant_id = :t", [':t' => $tenantId]);
        if ($exists) {
            Db::exec(
                "UPDATE kefu_ai_agent_config SET enabled=:enabled, provider=:provider,
                 system_prompt=:system_prompt, greeting=:greeting, keywords_handoff=:kw,
                 negative_sentiment_handoff=:neg_on, negative_keywords=:nk,
                 max_ai_rounds=:max_round, handoff_keyword_count=:hk_count, max_response_ms=:max_ms, updated_at=now()"
                . (!empty($data['api_key']) ? ', api_key=:api_key' : '')
                . (!empty($data['secret_key']) ? ', secret_key=:secret_key' : '')
                . (!empty($data['endpoint']) ? ', endpoint=:endpoint' : '')
                . (!empty($data['app_id']) ? ', app_id=:app_id' : '')
                . " WHERE tenant_id = :t",
                array_merge([
                    ':enabled' => $data['enabled'],
                    ':provider' => $data['provider'],
                    ':system_prompt' => $data['system_prompt'],
                    ':greeting' => $data['greeting'],
                    ':kw' => $data['keywords_handoff'],
                    ':neg_on' => $data['negative_sentiment_handoff'],
                    ':nk' => $data['negative_keywords'],
                    ':max_round' => $data['max_ai_rounds'],
                    ':hk_count' => $data['handoff_keyword_count'],
                    ':max_ms' => $data['max_response_ms'],
                    ':t' => $tenantId,
                ], !empty($data['api_key']) ? [':api_key' => $data['api_key']] : [],
                   !empty($data['secret_key']) ? [':secret_key' => $data['secret_key']] : [],
                   !empty($data['endpoint']) ? [':endpoint' => $data['endpoint']] : [],
                   !empty($data['app_id']) ? [':app_id' => $data['app_id']] : [])
            );
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $cols = array_keys($data);
            $place = array_map(function ($c) { return ':' . $c; }, $cols);
            Db::exec(
                "INSERT INTO kefu_ai_agent_config (" . implode(',', $cols) . ") VALUES (" . implode(',', $place) . ")",
                array_combine($place, array_values($data))
            );
        }
        \app\lib\Logger::info('ai_config_saved', [
            'tenant_id' => $tenantId, 'operator' => $operatorId,
            'enabled' => $data['enabled'],
        ]);
        return ['code' => 0, 'msg' => 'ok', 'data' => $this->getConfig($tenantId)];
    }

    /**
     * AI 是否启用
     */
    public function isEnabled($tenantId) {
        $cfg = $this->getConfig($tenantId);
        return !empty($cfg['enabled']) && !empty($cfg['api_key']);
    }
}