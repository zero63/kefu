<?php
/**
 * 微信小程序渠道适配器
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 * 说明：
 *   - 实现 ChannelInterface
 *   - 负责小程序登录（jscode2session）、发送客服消息、接收客户消息
 *   - 文档：https://developers.weixin.qq.com/miniprogram/dev/api-backend/
 */

namespace app\channel;

use app\lib\Logger;

class WeappChannel implements ChannelInterface
{
    /**
     * 微信小程序 AppID
     */
    private $appId;

    /**
     * 微信小程序 Secret
     */
    private $secret;

    public function __construct() {
        $this->appId  = env('WX_APPID', '');
        $this->secret = env('WX_SECRET', '');
    }

    public function getChannelCode() {
        return 'weapp';
    }

    /**
     * 小程序登录：用 code 换取 openid 和 session_key
     * @param array $params 必须包含 code
     * @return array customer_id, nickname, avatar 等
     */
    public function authenticate($params) {
        $code = $params['code'] ?? '';
        if (empty($code)) {
            throw new \Exception('缺少 code 参数');
        }

        // 调用微信接口
        $url = sprintf(
            'https://api.weixin.qq.com/sns/jscode2session?appid=%s&secret=%s&js_code=%s&grant_type=authorization_code',
            $this->appId, $this->secret, $code
        );

        $resp = $this->httpGet($url);
        $data = json_decode($resp, true);

        if (empty($data['openid'])) {
            Logger::error('小程序登录失败', ['resp' => $resp]);
            throw new \Exception('小程序登录失败：' . ($data['errmsg'] ?? '未知错误'));
        }

        return [
            'customer_id' => 'wx_' . $data['openid'], // 业务侧访客唯一ID
            'channel_id'  => $data['openid'],         // 渠道侧 openid
            'unionid'     => $data['unionid'] ?? '',
            'session_key' => $data['session_key'] ?? '',
            'nickname'    => $params['nickname'] ?? '',
            'avatar'      => $params['avatar'] ?? '',
            'phone'       => $params['phone'] ?? '',
        ];
    }

    /**
     * 发送客服消息给小程序访客
     * 注意：必须先在管理后台绑定客服人员，且访客 48 小时内有互动
     */
    public function sendMessage($customerId, $message) {
        // 实际实现：调用 https://api.weixin.qq.com/cgi-bin/message/custom/send
        // 这里先留接口实现，登录后补充
        Logger::info('微信小程序发送消息', [
            'customer_id' => $customerId,
            'type'        => $message['type'] ?? 'text',
            'content_preview' => mb_substr($message['text'] ?? '', 0, 50),
        ]);
        return true;
    }

    /**
     * 解析从 WebSocket 收到的访客消息
     */
    public function parseIncomingMessage($rawMessage) {
        return [
            'customer_id' => 'wx_' . ($rawMessage['openid'] ?? ''),
            'type'        => $rawMessage['type'] ?? 'text',
            'content'     => $rawMessage['content'] ?? '',
            'media_url'   => $rawMessage['media_url'] ?? '',
            'ext_json'    => isset($rawMessage['ext_json']) ? json_encode($rawMessage['ext_json']) : '{}',
        ];
    }

    /**
     * 推送通知（如服务时间提醒、满意度评价提醒）
     */
    public function pushNotification($customerId, $payload) {
        // 调用微信订阅消息接口
        Logger::info('微信小程序推送通知', ['customer_id' => $customerId, 'payload' => $payload]);
        return true;
    }

    /**
     * HTTP GET 请求
     */
    private function httpGet($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $resp = curl_exec($ch);
        // PHP 8 起 curl_close() 被废弃（不再需要，curl_init 句柄自动回收）
        if (function_exists('curl_close') && PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        return $resp;
    }
}