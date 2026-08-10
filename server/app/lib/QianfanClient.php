<?php
/**
 * 百度千帆 App 智能体 API 客户端
 * 文档：https://cloud.baidu.com/doc/qianfan-api/s/Fm5cv42aq
 *
 * 仅支持 App 模式（智能体应用对话）：
 *   1. 新建会话：POST https://qianfan.baidubce.com/v2/app/conversation
 *      Header: Authorization: Bearer {api_key}
 *      Body:   { "app_id": "xxx" }
 *      响应：   { "conversation_id": "xxxx-xxxx-..." }
 *
 *   2. 对话：    POST https://qianfan.baidubce.com/v2/app/conversation/runs
 *      Header: Authorization: Bearer {api_key}
 *      Body:   { "app_id":"xxx", "stream":false, "query":"...", "conversation_id":"上一步拿到的 ID", "end_user_id":"..." }
 *      响应：   { "request_id":"...", "answer":"...", "conversation_id":"...", "message_id":"..." }
 *
 * 注意：
 *   - 千帆要求 conversation_id 必须通过"新建会话"接口获取，
 *     不能客户端自己生成 UUID（否则只返回元数据不处理对话）
 *   - 多次对话复用同一个 conversation_id 可保持上下文记忆
 *
 * 使用：
 *   $client = new QianfanClient($apiKey, $appId);
 *   $reply = $client->chat([['role'=>'user','content'=>'你好']]);
 */

namespace app\lib;

class QianfanClient
{
    private $apiKey;
    private $appId;
    private $endpoint;        // 对话接口地址
    private $conversationEndpoint;  // 新建会话接口地址
    private $timeoutMs;
    private $accessToken;
    private $conversationId;  // 千帆会话 ID（已通过新建会话接口获取的）

    /**
     * @param string $apiKey    千帆 API Key（Bearer Token）
     * @param string $appId     千帆智能体 App ID
     * @param array  $opt       [ 'endpoint' => '...', 'secret_key' => '...', 'timeout_ms' => 6000, 'conversation_id' => '...' ]
     */
    public function __construct($apiKey, $appId, $opt = []) {
        $this->apiKey = trim((string)$apiKey);
        $this->appId  = trim((string)$appId);
        $this->timeoutMs   = intval($opt['timeout_ms'] ?? 6000);
        $this->accessToken = isset($opt['secret_key']) ? trim((string)$opt['secret_key']) : '';
        // 对话接口：默认千帆官方对话地址
        $endpoint = isset($opt['endpoint']) && $opt['endpoint']
            ? $opt['endpoint']
            : 'https://qianfan.baidubce.com/v2/app/conversation/runs';
        // 兼容：如果用户填的是"新建会话"地址（不带 /runs），自动补全到对话地址
        // 千帆"新建会话"接口：/v2/app/conversation
        // 千帆"对话"接口：  /v2/app/conversation/runs
        // 如果 endpoint 没带 /runs 但有 /conversation，可能是填错了
        if (strpos($endpoint, '/runs') === false && strpos($endpoint, '/conversation') !== false) {
            $endpoint = rtrim($endpoint, '/') . '/runs';
        }
        $this->endpoint = $endpoint;
        // 新建会话接口
        $convEndpoint = isset($opt['conversation_endpoint']) && $opt['conversation_endpoint']
            ? $opt['conversation_endpoint']
            : 'https://qianfan.baidubce.com/v2/app/conversation';
        // 如果对话 endpoint 里有 conversation/runs，反推新建会话地址
        if (isset($opt['endpoint']) && $opt['endpoint'] && strpos($convEndpoint, 'qianfan.baidubce.com') !== false) {
            $convEndpoint = preg_replace('#/conversation/runs/?$#', '/conversation', $endpoint);
        }
        $this->conversationEndpoint = $convEndpoint;
        $this->conversationId = isset($opt['conversation_id']) && $opt['conversation_id']
            ? trim((string)$opt['conversation_id'])
            : '';
    }

    /**
     * 调用千帆"新建会话"接口，获取官方的 conversation_id
     * @return array [ 'success'=>bool, 'conversation_id'=>string, 'error'=>string ]
     */
    public function createConversation() {
        if ($this->apiKey === 'MOCK-LOCAL-TEST' || strpos($this->apiKey, 'mock:') === 0) {
            $cid = 'mock_conv_' . bin2hex(random_bytes(8));
            return ['success' => true, 'conversation_id' => $cid];
        }
        if ($this->apiKey === '') {
            return ['success' => false, 'error' => 'API Key 未配置'];
        }
        if ($this->appId === '') {
            return ['success' => false, 'error' => 'App ID 未配置'];
        }

        $payload = ['app_id' => $this->appId];
        $resp = $this->httpPostToUrl($this->conversationEndpoint, $payload);
        if (!$resp['success']) return $resp;
        $data = $resp['data'];

        if (!empty($data['conversation_id'])) {
            $this->conversationId = $data['conversation_id'];
            return ['success' => true, 'conversation_id' => $data['conversation_id']];
        }

        $errMsg = $data['error_msg'] ?? $data['message'] ?? json_encode($data, JSON_UNESCAPED_UNICODE);
        $errCode = $data['error_code'] ?? $data['code'] ?? '';
        return [
            'success' => false,
            'error'   => '千帆新建会话失败' . ($errCode ? ' (code: '.$errCode.')' : '') . '：' . $errMsg,
            'raw'     => $data,
        ];
    }

    /**
     * 多轮对话（App 模式：取最后一条 user 作为 query）
     * @param array $messages [['role'=>'user'|'assistant','content'=>'...'], ...]
     * @param array $opt      [ 'system' => '...', 'temperature' => 0.7, 'conversation_id' => '...', 'end_user_id' => '...' ] （App 模式忽略 system）
     * @return array [ 'success'=>bool, 'content'=>string, 'error'=>string, 'tokens'=>int, 'conversation_id'=>string, 'message_id'=>string, 'raw'=>array ]
     */
    public function chat($messages, $opt = []) {
        // Mock 模式：本地模拟回复（用于本地开发测试）
        if ($this->apiKey === 'MOCK-LOCAL-TEST' || strpos($this->apiKey, 'mock:') === 0) {
            $lastUser = '';
            foreach (array_reverse($messages) as $m) {
                if (($m['role'] ?? '') === 'user') { $lastUser = (string)($m['content'] ?? ''); break; }
            }
            $reply = $this->mockReply($lastUser);
            return [
                'success' => true,
                'content' => $reply,
                'tokens'  => mb_strlen($reply),
                'conversation_id' => $this->conversationId ?: ('mock_conv_' . substr(md5($lastUser), 0, 8)),
                'message_id' => 'mock_msg_' . substr(md5($lastUser . microtime(true)), 0, 8),
                'raw'     => ['mock' => true],
            ];
        }

        if ($this->apiKey === '') {
            return ['success' => false, 'error' => 'API Key 未配置', 'content' => '', 'tokens' => 0];
        }
        if ($this->appId === '') {
            return ['success' => false, 'error' => 'App ID 未配置', 'content' => '', 'tokens' => 0];
        }

        // 取最后一条 user 消息作为 query
        $query = '';
        foreach (array_reverse($messages) as $m) {
            if (($m['role'] ?? '') === 'user') { $query = (string)($m['content'] ?? ''); break; }
        }
        if ($query === '') {
            return ['success' => false, 'error' => '没有 user 消息', 'content' => '', 'tokens' => 0];
        }

        // 千帆 AppBuilder 要求 conversation_id 必须通过"新建会话"接口获取，
        // 自己生成的 UUID 千帆不认（只会返回元数据不处理对话）。
        $convId = isset($opt['conversation_id']) && $opt['conversation_id']
            ? trim((string)$opt['conversation_id'])
            : $this->conversationId;
        if ($convId === '') {
            // 自动调用新建会话接口获取官方 conversation_id
            $cr = $this->createConversation();
            if (!$cr['success']) {
                return [
                    'success' => false,
                    'error'   => '新建会话失败，无法发起对话：' . ($cr['error'] ?? '未知错误'),
                    'content' => '', 'tokens' => 0,
                ];
            }
            $convId = $cr['conversation_id'];
        }

        // end_user_id 是记忆存储标识，限制 6-64 字符
        $endUserId = isset($opt['end_user_id']) && $opt['end_user_id']
            ? trim((string)$opt['end_user_id'])
            : 'kefu_user';

        $payload = [
            'app_id'          => $this->appId,
            'query'           => $query,
            'stream'          => false,
            'conversation_id' => $convId,
            'end_user_id'     => $endUserId,
        ];

        $resp = $this->httpPostToUrl($this->endpoint, $payload);
        if (!$resp['success']) return $resp;
        $data = $resp['data'];

        // 千帆 App 返回示例：
        // { "request_id":"...", "answer":"...", "conversation_id":"...", "message_id":"..." }
        // 错误：{ "request_id":"...", "error_code":..., "error_msg":"..." }
        if (!empty($data['answer'])) {
            $this->conversationId = $data['conversation_id'] ?? $convId;
            return [
                'success' => true,
                'content' => (string)$data['answer'],
                'tokens'  => intval($data['usage']['total_tokens'] ?? 0),
                'conversation_id' => $data['conversation_id'] ?? $convId,
                'message_id' => $data['message_id'] ?? null,
                'raw' => $data,
            ];
        }

        // 响应里没有 answer，需要更详细地告诉用户原因
        $errMsg = $data['error_msg'] ?? $data['message'] ?? ($data['error']['message'] ?? null);
        $errCode = $data['error_code'] ?? $data['code'] ?? '';
        if (!$errMsg) {
            $hasConvId = !empty($data['conversation_id']);
            $rawJson = json_encode($data, JSON_UNESCAPED_UNICODE);
            $hint = $hasConvId
                ? '千帆仅返回了会话元数据（conversation_id）但没有 answer，请检查：1) App ID 对应的智能体是否已发布；2) API Key 是否有该 App 的访问权限；3) 账户配额是否充足；4) 智能体是否包含必需的配置（如开场白、提示词）'
                : '千帆未返回任何有效字段，请检查 API Key / App ID 是否正确';
            return [
                'success' => false,
                'error'   => '千帆 App 接口错误：响应缺少 answer 字段。' . $hint . '（原始响应：' . $rawJson . '）',
                'content' => '', 'tokens' => 0,
                'raw'     => $data,
            ];
        }
        return [
            'success' => false,
            'error'   => '千帆 App 接口错误' . ($errCode ? ' (code: '.$errCode.')' : '') . '：' . $errMsg,
            'content' => '', 'tokens' => 0,
            'raw'     => $data,
        ];
    }

    /**
     * 获取当前缓存的 conversation_id（用于持久化）
     */
    public function getConversationId() {
        return $this->conversationId;
    }

    /**
     * 设置 conversation_id（用于多轮对话复用）
     */
    public function setConversationId($cid) {
        $this->conversationId = $cid;
    }

    /**
     * 仅取最后 N 条 history 构建 messages
     * （App 模式不需要 system，但保持兼容 chat 风格的调用）
     */
    public static function buildMessages($history, $systemPrompt = '', $maxHistory = 10) {
        $messages = [];
        $slice = array_slice($history, max(0, count($history) - $maxHistory));
        foreach ($slice as $m) {
            if (($m['role'] ?? '') === 'system') continue;
            $messages[] = [
                'role' => $m['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => (string)($m['content'] ?? ''),
            ];
        }
        return $messages;
    }

    /**
     * 通用 HTTP POST（Bearer Token），可指定 URL
     */
    private function httpPostToUrl($url, $payload) {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => implode("\r\n", $headers),
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'timeout' => max(1, ceil($this->timeoutMs / 1000)),
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            return ['success' => false, 'error' => '调用千帆 API 失败（网络错误）', 'content' => '', 'tokens' => 0];
        }
        $data = @json_decode($response, true);
        if (!is_array($data)) {
            return ['success' => false, 'error' => '千帆 API 返回非 JSON：' . substr($response, 0, 200), 'content' => '', 'tokens' => 0];
        }
        return ['success' => true, 'data' => $data];
    }

    /**
     * Mock 模式下的回复生成（本地开发用，模拟智能体对话）
     */
    private function mockReply($userInput) {
        $u = (string)$userInput;
        if (preg_match('/(你叫什么|怎么称呼|你的名字|姓名)/u', $u)) {
            return '我是智能客服助手小 e，有什么可以帮您？';
        }
        if (preg_match('/(价格|多少钱|怎么卖|报价)/u', $u)) {
            return '关于价格信息，您可以查看商品详情页，或直接告诉我您想了解的商品名称。';
        }
        if (preg_match('/(退货|退款|投诉|差评|不管|骗子|不行)/u', $u)) {
            return '非常抱歉给您带来困扰！我现在就把您转给人工客服为您处理，请稍等。';
        }
        if (preg_match('/(人工|真人|人工客服)/u', $u)) {
            return '好的，正在为您转接人工客服，请稍候…';
        }
        if (mb_strlen($u) < 6) {
            return '好的，我已经收到您的消息"' . $u . '"。请问您方便详细描述一下问题吗？';
        }
        return '感谢您的消息！您说的是：' . mb_substr($u, 0, 30) . (mb_strlen($u) > 30 ? '…' : '') . '。我现在帮您查询。';
    }
}