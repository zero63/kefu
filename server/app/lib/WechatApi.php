<?php
/**
 * 微信 API 客户端（客服消息收发）
 *
 * 负责：
 *   - access_token 全局缓存（kefu_access_token 表）
 *   - 客服账号管理（kfaccount add/update/del/getkflist）
 *   - 会话管理（kfsession create/close/getsession/getwaitcase/getmsglist）
 *   - 客服消息发送（cgi-bin/message/custom/send）
 *
 * 作者：kefu 开发团队
 */
namespace app\lib;
use app\lib\Db;

class WechatApi {
    const API_BASE = 'https://api.weixin.qq.com';

    /**
     * 获取 access_token（带缓存，过期前 5 分钟刷新）
     * @param int $tenantId 租户
     * @param int $accountId channel_account.id
     * @param string $appId
     * @param string $appSecret
     */
    public static function getAccessToken($tenantId, $accountId, $appId, $appSecret) {
        if (!$appId || !$appSecret) {
            throw new \Exception('app_id / app_secret 未配置');
        }
        Db::setTenantId($tenantId);
        // 查缓存
        $row = Db::find(
            "SELECT * FROM kefu_access_token
             WHERE tenant_id = :t AND channel_code = 'wechat' AND account_id = :a
             LIMIT 1",
            [':t' => $tenantId, ':a' => $accountId]
        );
        $now = time();
        if ($row && strtotime($row['expires_at']) - $now > 300) {
            return $row['access_token'];
        }
        // 重新获取
        $url = self::API_BASE . '/cgi-bin/token?grant_type=client_credential&appid=' . urlencode($appId) . '&secret=' . urlencode($appSecret);
        $resp = self::httpGet($url);
        $data = json_decode($resp, true);
        if (!is_array($data) || isset($data['errcode'])) {
            throw new \Exception('获取 access_token 失败：' . json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        $token = $data['access_token'];
        $expiresIn = (int)($data['expires_in'] ?? 7200);
        $expiresAt = date('Y-m-d H:i:s', $now + $expiresIn);

        // 写回缓存
        if ($row) {
            Db::update('kefu_access_token', [
                'access_token' => $token,
                'expires_in'   => $expiresIn,
                'expires_at'   => $expiresAt,
                'refresh_at'   => date('Y-m-d H:i:s'),
            ], ['id' => $row['id']]);
        } else {
            Db::insert('kefu_access_token', [
                'tenant_id'    => $tenantId,
                'channel_code' => 'wechat',
                'account_id'   => $accountId,
                'app_id'       => $appId,
                'access_token' => $token,
                'expires_in'   => $expiresIn,
                'expires_at'   => $expiresAt,
                'refresh_at'   => date('Y-m-d H:i:s'),
            ]);
        }
        return $token;
    }

    /**
     * 客服账号：添加
     * POST /customservice/kfaccount/add?access_token=
     */
    public static function kfAccountAdd($accessToken, $kfAccount, $nickname, $password) {
        $url = self::API_BASE . '/customservice/kfaccount/add?access_token=' . $accessToken;
        $body = json_encode([
            'kf_account' => $kfAccount,
            'nickname'   => $nickname,
            'password'   => md5($password), // 微信要求 md5
        ], JSON_UNESCAPED_UNICODE);
        return self::httpJson('POST', $url, $body);
    }

    /**
     * 客服账号：删除
     * GET /customservice/kfaccount/del?access_token=&kf_account=
     */
    public static function kfAccountDel($accessToken, $kfAccount) {
        $url = self::API_BASE . '/customservice/kfaccount/del?access_token=' . $accessToken . '&kf_account=' . urlencode($kfAccount);
        return self::httpGet($url);
    }

    /**
     * 客服账号：列表
     * GET /customservice/kfaccount/getkflist?access_token=
     */
    public static function kfAccountList($accessToken) {
        $url = self::API_BASE . '/customservice/kfaccount/getkflist?access_token=' . $accessToken;
        $resp = self::httpGet($url);
        return json_decode($resp, true);
    }

    /**
     * 在线客服列表
     * GET /customservice/kfaccount/getonlinekflist?access_token=
     */
    public static function kfOnlineList($accessToken) {
        $url = self::API_BASE . '/customservice/kfaccount/getonlinekflist?access_token=' . $accessToken;
        $resp = self::httpGet($url);
        return json_decode($resp, true);
    }

    /**
     * 会话管理：创建会话
     */
    public static function kfSessionCreate($accessToken, $kfAccount, $openid) {
        $url = self::API_BASE . '/customservice/kfsession/create?access_token=' . $accessToken;
        $body = json_encode([
            'kf_account' => $kfAccount,
            'openid'     => $openid,
        ], JSON_UNESCAPED_UNICODE);
        return self::httpJson('POST', $url, $body);
    }

    /**
     * 关闭会话
     */
    public static function kfSessionClose($accessToken, $kfAccount, $openid) {
        $url = self::API_BASE . '/customservice/kfsession/close?access_token=' . $accessToken;
        $body = json_encode([
            'kf_account' => $kfAccount,
            'openid'     => $openid,
        ], JSON_UNESCAPED_UNICODE);
        return self::httpJson('POST', $url, $body);
    }

    /**
     * 客户会话状态
     */
    public static function kfGetSession($accessToken, $openid) {
        $url = self::API_BASE . '/customservice/kfsession/getsession?access_token=' . $accessToken . '&openid=' . urlencode($openid);
        return self::httpGet($url);
    }

    /**
     * 获取未接入会话列表
     */
    public static function kfGetWaitcase($accessToken) {
        $url = self::API_BASE . '/customservice/kfsession/getwaitcase?access_token=' . $accessToken;
        return self::httpGet($url);
    }

    /**
     * 获取聊天记录
     */
    public static function kfGetMsgList($accessToken, $starttime, $endtime, $msgId = 1, $number = 100) {
        $url = self::API_BASE . '/customservice/msgrecord/getmsglist?access_token=' . $accessToken;
        $body = json_encode([
            'starttime' => $starttime,
            'endtime'   => $endtime,
            'msgid'     => $msgId,
            'number'    => $number,
        ], JSON_UNESCAPED_UNICODE);
        return self::httpJson('POST', $url, $body);
    }

    /**
     * 小程序微信客服（新版）：查询绑定情况
     * GET /customservice/work/get?access_token=
     * 返回：{ errcode, entityName, corpid, bindTime }
     */
    public static function kfWorkGet($accessToken) {
        $url = self::API_BASE . '/customservice/work/get?access_token=' . $accessToken;
        $resp = self::httpGet($url);
        return json_decode($resp, true);
    }

    /**
     * 小程序微信客服（新版）：绑定
     * POST /customservice/work/bind?access_token=
     * Body: { corpid }
     */
    public static function kfWorkBind($accessToken, $corpid) {
        $url = self::API_BASE . '/customservice/work/bind?access_token=' . $accessToken;
        return self::httpJson('POST', $url, json_encode(['corpid' => $corpid], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 小程序微信客服（新版）：解绑
     * POST /customservice/work/unbind?access_token=
     */
    public static function kfWorkUnbind($accessToken) {
        $url = self::API_BASE . '/customservice/work/unbind?access_token=' . $accessToken;
        return self::httpJson('POST', $url, '{}');
    }

    /**
     * 发送客服消息
     * @param string $kfAccount 指定客服账号（可选），如 test1@kftest
     */
    public static function customSend($accessToken, $openid, $msgType, $content, $kfAccount = '') {
        $url = self::API_BASE . '/cgi-bin/message/custom/send?access_token=' . $accessToken;
        $body = [
            'touser'  => $openid,
            'msgtype' => $msgType,
        ];
        if ($msgType === 'text') {
            $body['text'] = ['content' => $content];
        } elseif ($msgType === 'image' || $msgType === 'voice' || $msgType === 'video') {
            $body[$msgType] = ['media_id' => $content];
        } elseif ($msgType === 'news') {
            $body['news'] = $content; // ['articles' => [...]]
        } elseif ($msgType === 'mpnews') {
            $body['mpnews'] = ['media_id' => $content];
        }
        if ($kfAccount) {
            $body['customservice'] = ['kf_account' => $kfAccount];
        }
        return self::httpJson('POST', $url, json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 获取素材列表（用于客服消息可选媒体）
     */
    public static function getMaterialList($accessToken, $type = 'image', $offset = 0, $count = 20) {
        $url = self::API_BASE . '/cgi-bin/material/batchget_material?access_token=' . $accessToken;
        $body = json_encode([
            'type'   => $type,
            'offset' => $offset,
            'count'  => $count,
        ], JSON_UNESCAPED_UNICODE);
        return self::httpJson('POST', $url, $body);
    }

    /** ============ HTTP 工具 ============ */

    public static function httpGet($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new \Exception('HTTP 失败：' . $err);
        return $resp;
    }

    public static function httpJson($method, $url, $body, $headers = []) {
        $ch = curl_init();
        $defaultHeaders = ['Content-Type: application/json'];
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => array_merge($defaultHeaders, $headers),
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new \Exception('HTTP 失败：' . $err);
        return $resp;
    }
}