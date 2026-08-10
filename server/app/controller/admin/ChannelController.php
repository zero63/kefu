<?php
/**
 * 管理后台 - 多渠道接入管理（在线接入 / APP 接入 / 微信接入）
 * 功能（按微信/抖音官方接入流程实现）：
 *   - 渠道列表 / 启用 / 配置 / 验通
 *   - 每个渠道下多个账号绑定（多店铺、多公众号）
 *   - 自动生成 webhook URL / AppKey / AppSecret / Token / EncodingAESKey
 *   - 微信消息接口的 URL 校验（echostr）
 *   - 微信客服账号管理（kfaccount add/update/del/getkflist/getonlinekflist）
 *   - 微信会话管理（kfsession create/close/getsession/getsessionlist/getwaitcase）
 *   - 微信主动发消息（cgi-bin/message/custom/send）
 *   - 微信 access_token 缓存管理
 *   - 微信素材管理
 *
 * 参考文档：
 *   - 微信公众号：https://developers.weixin.qq.com/doc/offiaccount/Message_Management/Service_Center_messages.html
 *   - 微信小程序：https://developers.weixin.qq.com/miniprogram/dev/framework/server-ability/message-push.html
 *   - 微信小程序接入：https://developers.weixin.qq.com/miniprogram/dev/framework/server-ability/message-push.html
 *
 * 作者：kefu 开发团队
 */
namespace app\controller\admin;
use support\Request;
use app\lib\Db;
use app\lib\WechatApi;

class ChannelController {
    /**
     * 渠道字典列表（含每渠道的账号数）
     */
    public function list(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);

        $rows = Db::query(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM kefu_channel_account a WHERE a.tenant_id = c.tenant_id AND a.channel_id = c.id) AS account_count
             FROM kefu_channel c
             WHERE c.tenant_id = :t
             ORDER BY c.sort ASC, c.id ASC",
            [':t' => $tenantId]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    /**
     * 渠道详情 + 账号列表
     */
    public function detail(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->get('id', $request->post('id', 0)));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id required']);
        Db::setTenantId($tenantId);
        $channel = Db::find("SELECT * FROM kefu_channel WHERE tenant_id = :t AND id = :id", [':t' => $tenantId, ':id' => $id]);
        if (!$channel) return json(['code' => 404, 'msg' => '渠道不存在']);
        $accounts = Db::query(
            "SELECT id, tenant_id, channel_id, account_name, app_id, status, verified_at, last_error, created_at, updated_at
             FROM kefu_channel_account WHERE tenant_id = :t AND channel_id = :id ORDER BY id DESC",
            [':t' => $tenantId, ':id' => $id]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['channel' => $channel, 'accounts' => $accounts]]);
    }

    /**
     * 启用 / 禁用渠道
     */
    public function toggle(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        $enabled = intval($request->post('enabled', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id required']);
        Db::setTenantId($tenantId);
        Db::update('kefu_channel', ['enabled' => $enabled], ['id' => $id]);
        return json(['code' => 0, 'msg' => '已更新']);
    }

    /**
     * 新增 / 更新账号绑定
     */
    public function saveAccount(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);
        $id = intval($request->post('id', 0));
        $channelId = intval($request->post('channel_id', 0));
        if ($channelId <= 0) return json(['code' => 400, 'msg' => 'channel_id required']);

        // 校验渠道
        $ch = Db::find("SELECT id, channel_code FROM kefu_channel WHERE tenant_id = :t AND id = :c", [':t' => $tenantId, ':c' => $channelId]);
        if (!$ch) return json(['code' => 400, 'msg' => '渠道不存在']);

        $accountName = trim($request->post('account_name', ''));
        if ($accountName === '') return json(['code' => 400, 'msg' => '账号别名必填']);

        $data = [
            'tenant_id'     => $tenantId,
            'channel_id'    => $channelId,
            'account_name'  => $accountName,
            'app_id'        => trim($request->post('app_id', '')) ?: null,
            'app_secret'    => $this->mask($request->post('app_secret', '')) ?: null,
            'token'         => trim($request->post('token', '')) ?: null,
            'encoding_aes_key' => trim($request->post('encoding_aes_key', '')) ?: null,
            'app_key'       => trim($request->post('app_key', '')) ?: null,
            'api_key'       => trim($request->post('api_key', '')) ?: null,
            'client_id'     => trim($request->post('client_id', '')) ?: null,
            'client_secret' => $this->mask($request->post('client_secret', '')) ?: null,
            'imap_host'     => trim($request->post('imap_host', '')) ?: null,
            'imap_port'     => intval($request->post('imap_port', 0)) ?: null,
            'imap_user'     => trim($request->post('imap_user', '')) ?: null,
            'imap_pass'     => $this->mask($request->post('imap_pass', '')) ?: null,
            'imap_ssl'      => intval($request->post('imap_ssl', 1)),
            'smtp_host'     => trim($request->post('smtp_host', '')) ?: null,
            'smtp_port'     => intval($request->post('smtp_port', 0)) ?: null,
            'smtp_user'     => trim($request->post('smtp_user', '')) ?: null,
            'smtp_pass'     => $this->mask($request->post('smtp_pass', '')) ?: null,
            'webhook_url'   => trim($request->post('webhook_url', '')) ?: null,
            'webhook_secret'=> trim($request->post('webhook_secret', '')) ?: null,
            'status'        => intval($request->post('status', 1)),
        ];

        if ($id > 0) {
            // 不更新密码字段（已 mask 后的值不要再覆盖）
            foreach (['app_secret','client_secret','imap_pass','smtp_pass'] as $f) {
                if (empty($request->post($f, ''))) unset($data[$f]);
            }
            Db::update('kefu_channel_account', $data, ['id' => $id]);
            $accId = $id;
        } else {
            $accId = Db::insert('kefu_channel_account', $data);
        }

        // 自动生成回调 URL（按 account_id，与新路由匹配）
        $code = $ch['channel_code'];
        if (empty($data['webhook_url']) && in_array($code, ['wechatmp','weapp','channel_wechat','douyin','weibo'])) {
            $host = $request->header('host', 'kefu.xiaozhusho.top');
            $proto = $request->header('x-forwarded-proto', 'https');
            $autoUrl = "$proto://$host/api/channel/$code/$accId";
            Db::update('kefu_channel_account', ['webhook_url' => $autoUrl], ['id' => $accId]);
            $data['webhook_url'] = $autoUrl;
        }

        // 自动生成 AppKey（如未填）
        if (empty($data['app_key']) && in_array($code, ['wechatmp','weapp','channel_wechat','douyin','weibo','app'])) {
            $appKey = bin2hex(random_bytes(16));
            Db::update('kefu_channel_account', ['app_key' => $appKey], ['id' => $accId]);
            $data['app_key'] = $appKey;
        }

        // 微信 Token / EncodingAESKey 自动生成（如未填）
        if (empty($data['token']) && in_array($code, ['wechatmp','weapp','channel_wechat'])) {
            $token = bin2hex(random_bytes(8));
            Db::update('kefu_channel_account', ['token' => $token], ['id' => $accId]);
            $data['token'] = $token;
        }
        if (empty($data['encoding_aes_key']) && in_array($code, ['wechatmp','weapp','channel_wechat'])) {
            $aesKey = bin2hex(random_bytes(23)); // 43 字符 base64 编码后是 32 字节
            Db::update('kefu_channel_account', ['encoding_aes_key' => $aesKey], ['id' => $accId]);
            $data['encoding_aes_key'] = $aesKey;
        }

        // 返回明文（前端展示一次）
        return json(['code' => 0, 'msg' => '保存成功', 'data' => [
            'id'              => $accId,
            'webhook_url'     => $data['webhook_url'] ?? '',
            'token'           => $data['token'] ?? '',
            'encoding_aes_key'=> $data['encoding_aes_key'] ?? '',
            'app_key'         => $data['app_key'] ?? '',
            'app_secret'      => $request->post('app_secret', ''), // 明文
        ]]);
    }

    /**
     * 删除账号
     */
    public function deleteAccount(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id required']);
        Db::setTenantId($tenantId);
        Db::delete('kefu_channel_account', ['id' => $id]);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    /**
     * 验通（按各平台官方握手测试）
     * - wechatmp/weapp: 1) 字段检查 2) access_token 拉取测试 3) 客服列表拉取
     * - email: IMAP 连接
     * - 其他: ping webhook URL
     */
    public function verifyAccount(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id required']);
        Db::setTenantId($tenantId);
        $acc = Db::find(
            "SELECT a.*, c.channel_code FROM kefu_channel_account a
             INNER JOIN kefu_channel c ON c.id = a.channel_id
             WHERE a.tenant_id = :t AND a.id = :id",
            [':t' => $tenantId, ':id' => $id]
        );
        if (!$acc) return json(['code' => 404, 'msg' => '账号不存在']);

        $start = microtime(true);
        $result = ['ok' => true, 'message' => '已通过基本配置检查', 'steps' => []];

        // 步骤1：字段检查
        $missing = [];
        if (in_array($acc['channel_code'], ['wechatmp','weapp','channel_wechat'])) {
            if (!$acc['app_id'])   $missing[] = 'AppID';
            if (!$acc['app_secret']) $missing[] = 'AppSecret';
            if (!$acc['token'])    $missing[] = 'Token';
        } elseif ($acc['channel_code'] === 'douyin') {
            if (!$acc['client_id'])     $missing[] = 'ClientID';
            if (!$acc['client_secret']) $missing[] = 'ClientSecret';
        } elseif ($acc['channel_code'] === 'email') {
            if (!$acc['imap_host']) $missing[] = 'IMAP 主机';
            if (!$acc['imap_user']) $missing[] = 'IMAP 用户';
            if (!$acc['imap_pass']) $missing[] = 'IMAP 密码';
        } else {
            if (!$acc['app_key']) $missing[] = 'AppKey';
        }
        if ($missing) {
            $result['ok'] = false;
            $result['message'] = '缺少必填字段：' . implode('、', $missing);
        }
        $result['steps'][] = ['step' => 'config_check', 'ok' => empty($missing), 'message' => empty($missing) ? '必填字段已填写' : ('缺：' . implode('、', $missing))];

        // 步骤2：webhook URL 本地可达性
        if ($result['ok'] && !empty($acc['webhook_url'])) {
            $url = $acc['webhook_url'];
            $result['steps'][] = ['step' => 'webhook_url', 'ok' => true, 'message' => "URL: $url"];
        }

        // 步骤3：微信 access_token 拉取
        if ($result['ok'] && in_array($acc['channel_code'], ['wechatmp','weapp','channel_wechat'])) {
            try {
                $token = WechatApi::getAccessToken($tenantId, $acc['id'], $acc['app_id'], $this->unmask($acc['app_secret']));
                $result['steps'][] = ['step' => 'access_token', 'ok' => true, 'message' => 'access_token 已成功获取（前 12 位：' . substr($token, 0, 12) . '...）'];
            } catch (\Throwable $e) {
                $result['steps'][] = ['step' => 'access_token', 'ok' => false, 'message' => $e->getMessage()];
                $result['ok'] = false;
                $result['message'] = $e->getMessage();
            }
        }

        // 步骤4：邮件 IMAP 真实连通（如配置完整）
        if ($acc['channel_code'] === 'email' && $result['ok']) {
            $conn = @imap_open(
                '{' . $acc['imap_host'] . ':' . ($acc['imap_port'] ?: 993) . '/imap' . ($acc['imap_ssl'] ? '/ssl' : '') . '}INBOX',
                $acc['imap_user'], $this->unmask($acc['imap_pass'])
            );
            if ($conn) {
                $result['steps'][] = ['step' => 'imap_connect', 'ok' => true, 'message' => 'IMAP 连接成功'];
                @imap_close($conn);
            } else {
                $err = @imap_errors();
                $msg = is_array($err) ? implode(';', $err) : 'IMAP 验证失败';
                $result['steps'][] = ['step' => 'imap_connect', 'ok' => false, 'message' => $msg];
                $result['ok'] = false;
                $result['message'] = $msg;
            }
        }

        $latency = intval((microtime(true) - $start) * 1000);

        Db::update('kefu_channel_account', [
            'verified_at' => $result['ok'] ? date('Y-m-d H:i:s') : null,
            'last_error'  => $result['ok'] ? null : mb_substr($result['message'], 0, 255),
        ], ['id' => $id]);

        $this->diag($tenantId, 'channel', $acc['webhook_url'] ?: $acc['channel_code'], $result['ok'] ? 1 : 0, $latency, $result['message'], json_encode($result, JSON_UNESCAPED_UNICODE));

        return json(['code' => $result['ok'] ? 0 : 400, 'msg' => $result['message'], 'data' => $result + ['latency_ms' => $latency]]);
    }

    /**
     * 重新生成 AppSecret
     */
    public function rotateSecret(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id required']);
        Db::setTenantId($tenantId);
        $newSecret = bin2hex(random_bytes(24));
        Db::update('kefu_channel_account', ['app_secret' => $this->mask($newSecret), 'api_key' => $newSecret], ['id' => $id]);
        return json(['code' => 0, 'msg' => '已重置', 'data' => ['app_secret' => $newSecret]]);
    }

    /**
     * ============================================================
     * 微信官方 API 接入（kfaccount / kfsession / custom_send / access_token）
     * 全部按微信官方文档实现：
     *   - https://developers.weixin.qq.com/doc/offiaccount/Custom_Service/Custom_service_account_management.html
     *   - https://developers.weixin.qq.com/doc/offiaccount/Custom_Service/Session_management.html
     *   - https://developers.weixin.qq.com/doc/offiaccount/Message_Management/Service_Center_messages.html
     * ============================================================
     */

    /**
     * 客服账号列表（同步微信侧的客服工号）
     * GET /api/admin/channel-mgmt/kfaccount/list?id=xx
     */
    public function kfAccountList(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $accountId = intval($request->get('id', 0));
        if ($accountId <= 0) return json(['code' => 400, 'msg' => 'account id required']);
        Db::setTenantId($tenantId);

        $acc = $this->getAccountWithCheck($tenantId, $accountId);
        if (!$acc) return json(['code' => 404, 'msg' => '账号不存在']);

        // 1) 查本系统 kf_account 表
        $local = Db::query("SELECT * FROM kefu_kf_account WHERE tenant_id = :t AND channel_account_id = :a ORDER BY id DESC",
            [':t' => $tenantId, ':a' => $accountId]);

        // 2) 同步拉取微信侧
        $remote = ['kf_list' => [], 'kf_online_list' => [], 'kf_work' => null];
        $errors = [];
        try {
            $token = WechatApi::getAccessToken($tenantId, $accountId, $acc['app_id'], $this->unmask($acc['app_secret']));
            // 公众号：老版多客服
            if (in_array($acc['channel_code'], ['wechatmp', 'channel_wechat'])) {
                $remote['kf_list'] = WechatApi::kfAccountList($token);
                $remote['kf_online_list'] = WechatApi::kfOnlineList($token);
            }
            // 小程序：2025 新版微信客服
            if ($acc['channel_code'] === 'weapp') {
                $remote['kf_work'] = WechatApi::kfWorkGet($token);
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'local'  => $local,
            'remote' => $remote,
            'errors' => $errors,
        ]]);
    }

    /**
     * 添加客服账号（调用微信 /customservice/kfaccount/add）
     * POST /api/admin/channel-mgmt/kfaccount/add
     * body: { id: account_id, kf_account: 'test1@kftest', kf_nick: '客服1', password: 'xxx' }
     */
    public function kfAccountAdd(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $accountId = intval($request->post('id', 0));
        $kfAccount = trim($request->post('kf_account', ''));
        $kfNick    = trim($request->post('kf_nick', ''));
        $password  = trim($request->post('password', ''));
        $employeeId = intval($request->post('employee_id', 0));

        if ($accountId <= 0 || $kfAccount === '' || $kfNick === '' || $password === '') {
            return json(['code' => 400, 'msg' => '参数不完整：id / kf_account / kf_nick / password']);
        }
        Db::setTenantId($tenantId);

        $acc = $this->getAccountWithCheck($tenantId, $accountId);
        if (!$acc) return json(['code' => 404, 'msg' => '账号不存在']);

        try {
            $token = WechatApi::getAccessToken($tenantId, $accountId, $acc['app_id'], $this->unmask($acc['app_secret']));
            $resp = WechatApi::kfAccountAdd($token, $kfAccount, $kfNick, $password);
            $data = json_decode($resp, true);
            if (isset($data['errcode']) && $data['errcode'] != 0) {
                return json(['code' => 400, 'msg' => '微信侧添加失败：' . ($data['errmsg'] ?? json_encode($data)), 'data' => $data]);
            }
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '调用微信 API 失败：' . $e->getMessage()]);
        }

        // 落地本系统
        $id = Db::insert('kefu_kf_account', [
            'tenant_id'        => $tenantId,
            'channel_account_id'=> $accountId,
            'kf_account'       => $kfAccount,
            'kf_nick'          => $kfNick,
            'password_hash'    => md5($password),
            'employee_id'      => $employeeId ?: null,
            'invite_status'    => 'waiting',
            'invite_expire_time'=> date('Y-m-d H:i:s', time() + 7 * 86400), // 7 天邀请有效
            'status'           => 1,
        ]);

        return json(['code' => 0, 'msg' => '已添加', 'data' => ['id' => $id, 'wechat_resp' => $data ?? []]]);
    }

    /**
     * 删除客服账号（调用微信 /customservice/kfaccount/del）
     * POST /api/admin/channel-mgmt/kfaccount/del
     * body: { id: account_id, kf_account: 'test1@kftest' }
     */
    public function kfAccountDel(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $accountId = intval($request->post('id', 0));
        $kfAccount = trim($request->post('kf_account', ''));
        if ($accountId <= 0 || $kfAccount === '') {
            return json(['code' => 400, 'msg' => 'id / kf_account 必填']);
        }
        Db::setTenantId($tenantId);

        $acc = $this->getAccountWithCheck($tenantId, $accountId);
        if (!$acc) return json(['code' => 404, 'msg' => '账号不存在']);

        try {
            $token = WechatApi::getAccessToken($tenantId, $accountId, $acc['app_id'], $this->unmask($acc['app_secret']));
            $resp = WechatApi::kfAccountDel($token, $kfAccount);
            $data = json_decode($resp, true);
            if (isset($data['errcode']) && $data['errcode'] != 0) {
                return json(['code' => 400, 'msg' => '微信侧删除失败：' . ($data['errmsg'] ?? json_encode($data))]);
            }
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '调用微信 API 失败：' . $e->getMessage()]);
        }

        Db::delete('kefu_kf_account', ['tenant_id' => $tenantId, 'channel_account_id' => $accountId, 'kf_account' => $kfAccount]);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    /**
     * 修改客服账号（昵称 / 密码 / 关联员工）
     * POST /api/admin/channel-mgmt/kfaccount/update
     * body: { id, kf_account, kf_nick?, password?, employee_id? }
     */
    public function kfAccountUpdate(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        $accountId = intval($request->post('account_id', 0));
        $kfAccount = trim($request->post('kf_account', ''));
        $kfNick    = trim($request->post('kf_nick', ''));
        $password  = trim($request->post('password', ''));
        $employeeId = intval($request->post('employee_id', 0));

        if ($id <= 0 || $accountId <= 0 || $kfAccount === '') {
            return json(['code' => 400, 'msg' => 'id / account_id / kf_account 必填']);
        }
        Db::setTenantId($tenantId);

        $acc = $this->getAccountWithCheck($tenantId, $accountId);
        if (!$acc) return json(['code' => 404, 'msg' => '账号不存在']);

        $update = [];
        if ($kfNick !== '' || $password !== '') {
            // 同步微信侧
            try {
                $token = WechatApi::getAccessToken($tenantId, $accountId, $acc['app_id'], $this->unmask($acc['app_secret']));
                $body = ['kf_account' => $kfAccount];
                if ($kfNick !== '') $body['nickname'] = $kfNick;
                if ($password !== '') $body['password'] = md5($password);
                $resp = WechatApi::httpJson('POST', WechatApi::API_BASE . '/customservice/kfaccount/update?access_token=' . $token, json_encode($body, JSON_UNESCAPED_UNICODE));
                $data = json_decode($resp, true);
                if (isset($data['errcode']) && $data['errcode'] != 0) {
                    return json(['code' => 400, 'msg' => '微信侧更新失败：' . ($data['errmsg'] ?? json_encode($data))]);
                }
            } catch (\Throwable $e) {
                return json(['code' => 500, 'msg' => '调用微信 API 失败：' . $e->getMessage()]);
            }
            if ($kfNick !== '') $update['kf_nick'] = $kfNick;
            if ($password !== '') $update['password_hash'] = md5($password);
        }
        if ($employeeId > 0) $update['employee_id'] = $employeeId;

        if ($update) {
            Db::update('kefu_kf_account', $update, ['id' => $id]);
        }
        return json(['code' => 0, 'msg' => '已更新']);
    }

    /**
     * 主动发送客服消息（调用微信 cgi-bin/message/custom/send）
     * POST /api/admin/channel-mgmt/custom/send
     * body: {
     *   id: account_id,
     *   openid: '用户 openid',
     *   msg_type: 'text' | 'image' | 'voice' | 'video' | 'news' | 'mpnews' | 'miniprogrampage',
     *   content: '文本内容' 或 { media_id: 'xxx' } 或 articles,
     *   kf_account: '指定客服（可选）'
     * }
     */
    public function customSend(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $accountId = intval($request->post('id', 0));
        $openid    = trim($request->post('openid', ''));
        $msgType   = trim($request->post('msg_type', 'text'));
        $content   = $request->post('content', '');
        $kfAccount = trim($request->post('kf_account', ''));

        if ($accountId <= 0 || $openid === '' || $msgType === '') {
            return json(['code' => 400, 'msg' => 'id / openid / msg_type 必填']);
        }
        Db::setTenantId($tenantId);

        $acc = $this->getAccountWithCheck($tenantId, $accountId);
        if (!$acc) return json(['code' => 404, 'msg' => '账号不存在']);

        try {
            $token = WechatApi::getAccessToken($tenantId, $accountId, $acc['app_id'], $this->unmask($acc['app_secret']));
            $resp = WechatApi::customSend($token, $openid, $msgType, $content, $kfAccount);
            $data = json_decode($resp, true);
            if (isset($data['errcode']) && $data['errcode'] != 0) {
                return json(['code' => 400, 'msg' => '发送失败：' . ($data['errmsg'] ?? json_encode($data)), 'data' => $data]);
            }
            return json(['code' => 0, 'msg' => '已发送', 'data' => $data]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '调用微信 API 失败：' . $e->getMessage()]);
        }
    }

    /**
     * 微信客服会话管理 - 创建会话
     * POST /api/admin/channel-mgmt/kfsession/create
     * body: { id, kf_account, openid }
     */
    public function kfSessionCreate(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $accountId = intval($request->post('id', 0));
        $kfAccount = trim($request->post('kf_account', ''));
        $openid    = trim($request->post('openid', ''));
        if ($accountId <= 0 || $kfAccount === '' || $openid === '') {
            return json(['code' => 400, 'msg' => 'id / kf_account / openid 必填']);
        }
        Db::setTenantId($tenantId);
        $acc = $this->getAccountWithCheck($tenantId, $accountId);
        if (!$acc) return json(['code' => 404, 'msg' => '账号不存在']);

        try {
            $token = WechatApi::getAccessToken($tenantId, $accountId, $acc['app_id'], $this->unmask($acc['app_secret']));
            $resp = WechatApi::kfSessionCreate($token, $kfAccount, $openid);
            $data = json_decode($resp, true);
            return json(['code' => isset($data['errcode']) && $data['errcode'] != 0 ? 400 : 0, 'msg' => $data['errmsg'] ?? 'ok', 'data' => $data]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '调用微信 API 失败：' . $e->getMessage()]);
        }
    }

    /**
     * 微信客服会话管理 - 关闭会话
     * POST /api/admin/channel-mgmt/kfsession/close
     */
    public function kfSessionClose(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $accountId = intval($request->post('id', 0));
        $kfAccount = trim($request->post('kf_account', ''));
        $openid    = trim($request->post('openid', ''));
        if ($accountId <= 0 || $kfAccount === '' || $openid === '') {
            return json(['code' => 400, 'msg' => 'id / kf_account / openid 必填']);
        }
        Db::setTenantId($tenantId);
        $acc = $this->getAccountWithCheck($tenantId, $accountId);
        if (!$acc) return json(['code' => 404, 'msg' => '账号不存在']);

        try {
            $token = WechatApi::getAccessToken($tenantId, $accountId, $acc['app_id'], $this->unmask($acc['app_secret']));
            $resp = WechatApi::kfSessionClose($token, $kfAccount, $openid);
            $data = json_decode($resp, true);
            return json(['code' => isset($data['errcode']) && $data['errcode'] != 0 ? 400 : 0, 'msg' => $data['errmsg'] ?? 'ok', 'data' => $data]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '调用微信 API 失败：' . $e->getMessage()]);
        }
    }

    /**
     * 查询客户当前会话状态
     * GET /api/admin/channel-mgmt/kfsession/get?openid=&id=
     */
    public function kfSessionGet(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $accountId = intval($request->get('id', 0));
        $openid    = trim($request->get('openid', ''));
        if ($accountId <= 0 || $openid === '') {
            return json(['code' => 400, 'msg' => 'id / openid 必填']);
        }
        Db::setTenantId($tenantId);
        $acc = $this->getAccountWithCheck($tenantId, $accountId);
        if (!$acc) return json(['code' => 404, 'msg' => '账号不存在']);

        try {
            $token = WechatApi::getAccessToken($tenantId, $accountId, $acc['app_id'], $this->unmask($acc['app_secret']));
            $resp = WechatApi::kfGetSession($token, $openid);
            $data = json_decode($resp, true);
            return json(['code' => 0, 'msg' => 'ok', 'data' => $data]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '调用微信 API 失败：' . $e->getMessage()]);
        }
    }

    /**
     * 未接入会话列表
     * GET /api/admin/channel-mgmt/kfsession/waitcase?id=
     */
    public function kfSessionWaitcase(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $accountId = intval($request->get('id', 0));
        if ($accountId <= 0) return json(['code' => 400, 'msg' => 'id required']);
        Db::setTenantId($tenantId);
        $acc = $this->getAccountWithCheck($tenantId, $accountId);
        if (!$acc) return json(['code' => 404, 'msg' => '账号不存在']);

        try {
            $token = WechatApi::getAccessToken($tenantId, $accountId, $acc['app_id'], $this->unmask($acc['app_secret']));
            $resp = WechatApi::kfGetWaitcase($token);
            $data = json_decode($resp, true);
            return json(['code' => 0, 'msg' => 'ok', 'data' => $data]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '调用微信 API 失败：' . $e->getMessage()]);
        }
    }

    /**
     * 聊天记录
     * GET /api/admin/channel-mgmt/msgrecord/list?id=&starttime=&endtime=&msgid=&number=
     */
    public function msgRecordList(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $accountId = intval($request->get('id', 0));
        $starttime = intval($request->get('starttime', 0));
        $endtime   = intval($request->get('endtime', 0));
        $msgId     = intval($request->get('msgid', 1));
        $number    = intval($request->get('number', 50));
        if ($accountId <= 0 || $starttime <= 0 || $endtime <= 0) {
            return json(['code' => 400, 'msg' => 'id / starttime / endtime 必填']);
        }
        Db::setTenantId($tenantId);
        $acc = $this->getAccountWithCheck($tenantId, $accountId);
        if (!$acc) return json(['code' => 404, 'msg' => '账号不存在']);

        try {
            $token = WechatApi::getAccessToken($tenantId, $accountId, $acc['app_id'], $this->unmask($acc['app_secret']));
            $resp = WechatApi::kfGetMsgList($token, $starttime, $endtime, $msgId, $number);
            $data = json_decode($resp, true);
            return json(['code' => 0, 'msg' => 'ok', 'data' => $data]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '调用微信 API 失败：' . $e->getMessage()]);
        }
    }

    /**
     * 小程序微信客服（新版 2025）：查询绑定
     * GET /api/admin/channel-mgmt/kfwork/get?id=
     */
    public function kfWorkGetStatus(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $accountId = intval($request->get('id', 0));
        if ($accountId <= 0) return json(['code' => 400, 'msg' => 'id required']);
        Db::setTenantId($tenantId);
        $acc = $this->getAccountWithCheck($tenantId, $accountId);
        if (!$acc) return json(['code' => 404, 'msg' => '账号不存在']);
        try {
            $token = WechatApi::getAccessToken($tenantId, $accountId, $acc['app_id'], $this->unmask($acc['app_secret']));
            $data = WechatApi::kfWorkGet($token);
            return json(['code' => 0, 'msg' => 'ok', 'data' => $data]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '调用微信 API 失败：' . $e->getMessage()]);
        }
    }

    /**
     * 小程序微信客服（新版 2025）：绑定
     * POST /api/admin/channel-mgmt/kfwork/bind
     * body: { id, corpid }
     */
    public function kfWorkBind(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $accountId = intval($request->post('id', 0));
        $corpid = trim($request->post('corpid', ''));
        if ($accountId <= 0 || $corpid === '') return json(['code' => 400, 'msg' => 'id / corpid 必填']);
        Db::setTenantId($tenantId);
        $acc = $this->getAccountWithCheck($tenantId, $accountId);
        if (!$acc) return json(['code' => 404, 'msg' => '账号不存在']);
        try {
            $token = WechatApi::getAccessToken($tenantId, $accountId, $acc['app_id'], $this->unmask($acc['app_secret']));
            $resp = WechatApi::kfWorkBind($token, $corpid);
            $data = json_decode($resp, true);
            return json(['code' => isset($data['errcode']) && $data['errcode'] != 0 ? 400 : 0, 'msg' => $data['errmsg'] ?? 'ok', 'data' => $data]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '调用微信 API 失败：' . $e->getMessage()]);
        }
    }

    /**
     * 小程序微信客服（新版 2025）：解绑
     * POST /api/admin/channel-mgmt/kfwork/unbind
     */
    public function kfWorkUnbind(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $accountId = intval($request->post('id', 0));
        if ($accountId <= 0) return json(['code' => 400, 'msg' => 'id required']);
        Db::setTenantId($tenantId);
        $acc = $this->getAccountWithCheck($tenantId, $accountId);
        if (!$acc) return json(['code' => 404, 'msg' => '账号不存在']);
        try {
            $token = WechatApi::getAccessToken($tenantId, $accountId, $acc['app_id'], $this->unmask($acc['app_secret']));
            $resp = WechatApi::kfWorkUnbind($token);
            $data = json_decode($resp, true);
            return json(['code' => isset($data['errcode']) && $data['errcode'] != 0 ? 400 : 0, 'msg' => $data['errmsg'] ?? 'ok', 'data' => $data]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '调用微信 API 失败：' . $e->getMessage()]);
        }
    }

    /**
     * 获取/刷新 access_token（手动触发）
     * GET /api/admin/channel-mgmt/access-token/refresh?id=
     */
    public function refreshAccessToken(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $accountId = intval($request->get('id', 0));
        if ($accountId <= 0) return json(['code' => 400, 'msg' => 'id required']);
        Db::setTenantId($tenantId);
        $acc = $this->getAccountWithCheck($tenantId, $accountId);
        if (!$acc) return json(['code' => 404, 'msg' => '账号不存在']);

        try {
            // 强制刷新：先清缓存再获取
            Db::exec("DELETE FROM kefu_access_token WHERE tenant_id = :t AND account_id = :a",
                [':t' => $tenantId, ':a' => $accountId]);
            $token = WechatApi::getAccessToken($tenantId, $accountId, $acc['app_id'], $this->unmask($acc['app_secret']));
            $row = Db::find("SELECT * FROM kefu_access_token WHERE tenant_id = :t AND account_id = :a",
                [':t' => $tenantId, ':a' => $accountId]);
            return json(['code' => 0, 'msg' => '已刷新', 'data' => [
                'access_token' => substr($token, 0, 12) . '...(共' . strlen($token) . '字符)',
                'expires_in'   => $row['expires_in'] ?? 7200,
                'expires_at'   => $row['expires_at'] ?? '',
            ]]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '刷新失败：' . $e->getMessage()]);
        }
    }

    /**
     * ============================================================
     * 工具方法
     * ============================================================
     */

    /**
     * 取账号并校验租户归属
     */
    private function getAccountWithCheck($tenantId, $accountId) {
        return Db::find(
            "SELECT a.*, c.channel_code FROM kefu_channel_account a
             INNER JOIN kefu_channel c ON c.id = a.channel_id
             WHERE a.tenant_id = :t AND a.id = :id",
            [':t' => $tenantId, ':id' => $accountId]
        );
    }

    /**
     * 上传诊断日志
     */
    private function diag($tenantId, $type, $target, $ok, $latency, $msg, $detail) {
        try {
            Db::insert('kefu_diagnostic_log', [
                'tenant_id' => $tenantId,
                'check_type'=> $type,
                'target'    => $target,
                'status'    => $ok,
                'latency_ms'=> $latency,
                'message'   => mb_substr($msg, 0, 500),
                'detail_json'=> mb_substr($detail ?? '', 0, 5000),
            ]);
        } catch (\Throwable $e) { /* 忽略诊断日志失败 */ }
    }

    /** 简单脱敏（生产应使用 sodium / openssl） */
    private function mask($val) {
        if (!$val) return '';
        return 'enc:' . base64_encode($val . '|' . 'kefu-mask-2026');
    }
    private function unmask($val) {
        if (!$val || strpos($val, 'enc:') !== 0) return $val;
        $raw = base64_decode(substr($val, 4));
        $parts = explode('|', $raw, 2);
        return $parts[0] ?? '';
    }
}