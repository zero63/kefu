<?php
/**
 * 渠道适配器接口（多渠道接入核心抽象）
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 * 说明：
 *   - 所有渠道（微信小程序、H5、微信公众号、企业微信、APP 等）
 *   - 都必须实现这个接口
 *   - 新增渠道只需写一个新的适配器，符合开闭原则
 */

namespace app\channel;

interface ChannelInterface
{
    /**
     * 获取渠道标识
     * @return string 如 'weapp' / 'h5' / 'wxoa' / 'wecom' / 'app'
     */
    public function getChannelCode();

    /**
     * 渠道认证：从原始参数解析访客身份
     * @param array $params 渠道原始参数（如小程序的 code、公众号的 openid）
     * @return array customer_id, nickname, avatar, phone 等
     */
    public function authenticate($params);

    /**
     * 发送消息给访客
     * @param string $customerId 访客ID
     * @param array $message 消息内容（type/text/media_url/ext_json）
     * @return bool
     */
    public function sendMessage($customerId, $message);

    /**
     * 接收访客消息（WebSocket / 回调）
     * @param array $rawMessage 原始消息
     * @return array 标准化消息 ['customer_id', 'type', 'content', 'media_url', 'ext_json']
     */
    public function parseIncomingMessage($rawMessage);

    /**
     * 主动推送（用于系统通知、活动推送等）
     * @param string $customerId
     * @param array $payload
     * @return bool
     */
    public function pushNotification($customerId, $payload);
}