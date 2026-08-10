<?php
/**
 * 微信消息加解密（安全模式）
 *
 * 微信官方 AES-256-CBC + PKCS#7 padding + Base64
 * 参考微信官方文档：《消息加解密接入指引》
 *
 * 加密流程：
 *   1. 拼接明文：random(16B) + msg_len(4B 网络字节序) + msg + receiveid
 *   2. AES-256-CBC 加密（key = base64decode(EncodingAESKey + "=")，iv = 前16字节）
 *   3. Base64 编码
 *
 * 解密流程反向
 *
 * 作者：kefu 开发团队
 */
namespace app\lib;

class WechatCrypto {
    private $key;
    private $iv;
    private $appId;
    private $token; // 公众号消息 Token（用于签名）

    public function __construct($encodingAesKey, $appId, $token = '') {
        // EncodingAESKey 是 43 位 base64（32 字节），补一个 "=" 即可 decode
        $this->key = base64_decode($encodingAesKey . '=');
        $this->iv  = substr($this->key, 0, 16);
        $this->appId = $appId;
        $this->token = $token;
    }

    /**
     * 单独设置 token
     */
    public function setToken($token) {
        $this->token = $token;
        return $this;
    }

    /**
     * 解密微信推送的加密消息
     * @param string $encryptedXml Base64 字符串
     * @return string|null 明文 XML
     */
    public function decrypt($encryptedXml) {
        $ciphertext = base64_decode($encryptedXml, true);
        if ($ciphertext === false || strlen($ciphertext) === 0) return null;

        // 不使用 ZERO_PADDING，由 PHP 自动按 PKCS#7 处理 padding
        $plain = openssl_decrypt($ciphertext, 'AES-256-CBC', $this->key, OPENSSL_RAW_DATA, $this->iv);
        if ($plain === false) {
            // 兜底：尝试 PKCS5
            $plain = openssl_decrypt($ciphertext, 'AES-256-CBC', $this->key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->iv);
            if ($plain === false) return null;
            // 手动去除 PKCS#7 padding
            $pad = ord(substr($plain, -1));
            if ($pad > 0 && $pad <= 32) $plain = substr($plain, 0, -$pad);
        }
        // 拆解: random(16) + msg_len(4) + msg + receiveid
        if (strlen($plain) < 20) return null;
        // 校验 receiveid = appId（在明文末尾）
        $receiveId = substr($plain, -strlen($this->appId));
        if ($receiveId !== $this->appId) return null;
        // 取 msg 长度
        $msgLen = unpack('N', substr($plain, 16, 4))[1];
        $msg = substr($plain, 20, $msgLen);
        return $msg;
    }

    /**
     * 加密回复消息
     */
    public function encrypt($replyXml, $timestamp, $nonce) {
        $random = $this->randomBytes(16);
        $msg = $replyXml;
        $len = pack('N', strlen($msg));
        $plain = $random . $len . $msg . $this->appId;
        // PHP openssl_encrypt 默认自动 PKCS#7 padding
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $this->key, OPENSSL_RAW_DATA, $this->iv);
        $encrypted = base64_encode($cipher);

        // 生成签名
        $signature = $this->sign($this->token, $timestamp, $nonce, $encrypted);
        // 拼接 XML
        $ts = (int)$timestamp;
        $xml = <<<XML
<xml>
<Encrypt><![CDATA[{$encrypted}]]></Encrypt>
<MsgSignature><![CDATA[{$signature}]]></MsgSignature>
<TimeStamp>{$ts}</TimeStamp>
<Nonce><![CDATA[{$nonce}]]></Nonce>
</xml>
XML;
        return $xml;
    }

    /**
     * 微信消息签名（用于 URL 校验 + 安全模式验签）
     */
    public function sign($token, $timestamp, $nonce, $encrypted = '') {
        $arr = [$token, $timestamp, $nonce, $encrypted];
        sort($arr, SORT_STRING);
        $str = implode('', $arr);
        return sha1($str);
    }

    /**
     * 生成 16 字节二进制随机串
     */
    private function randomBytes($length) {
        return random_bytes($length);
    }
}