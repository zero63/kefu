<?php
/**
 * JWT Token 工具类
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 * 说明：
 *   - 基于 firebase/php-jwt
 *   - Token 中包含 employee_id、tenant_id、role_id 等关键信息
 *   - 支持签发、验证、刷新
 */

namespace app\lib;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

class Token
{
    /**
     * 签发 Token
     * @param array $payload 必须包含 employee_id, tenant_id
     * @param int $ttl 有效期（秒）
     * @return string
     */
    public static function issue($payload, $ttl = 0) {
        $ttl = $ttl > 0 ? $ttl : intval(env('JWT_TTL', 86400));
        $secret = env('JWT_SECRET', 'kefu_secret');

        $payload['iat'] = time();
        $payload['exp'] = time() + $ttl;
        $payload['iss'] = 'kefu';
        // 生成唯一标识，用于 Token 黑名单注销
        $payload['jti'] = bin2hex(random_bytes(16));

        return JWT::encode($payload, $secret, 'HS256');
    }

    /**
     * 验证 Token
     * @param string $token
     * @return array|false 返回 payload 或 false
     */
    public static function verify($token) {
        if (empty($token)) return false;

        $secret = env('JWT_SECRET', 'kefu_secret');

        try {
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            return (array)$decoded;
        } catch (Exception $e) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
            return false;
        }
    }

    /**
     * 刷新 Token（在原 Token 基础上延长有效期）
     */
    public static function refresh($token) {
        $payload = self::verify($token);
        if (!$payload) return false;

        unset($payload['iat']);
        unset($payload['exp']);
        return self::issue($payload);
    }

    /**
     * 从请求头提取 Token
     * @return string|null
     */
    public static function extractFromRequest() {
        $request = request();
        // 优先从 Authorization: Bearer xxx 取
        $auth = $request->header('Authorization');
        if ($auth && stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        // 备选：从自定义头 X-Token 取
        $token = $request->header('X-Token');
        if ($token) return trim($token);
        // 备选：从 query 取（仅用于测试）
        $token = $request->get('token');
        if ($token) return trim($token);

        return null;
    }

    /**
     * 从 token 抽取并 verify，直接返回 payload（false 表示失败）
     * @param mixed $req webman Request 或 null（自动 request()）
     * @return array|false
     */
    public static function verifyFromHeader($req = null) {
        if (!$req) {
            try { $req = request(); } catch (Exception $e) { return false; }
        }
        // 优先从 $req header 直接取，再用 request() 全局 fallback（webman 1.5 quirk）
        $auth = null;
        try { $auth = $req->header('Authorization'); } catch (Exception $e) {}
        if (!$auth) {
            try { $auth = request()->header('Authorization'); } catch (Exception $e) {}
        }
        if ($auth && stripos($auth, 'Bearer ') === 0) {
            $token = trim(substr($auth, 7));
        } else {
            $token = $req->header('X-Token');
            if (!$token) {
                try { $token = request()->header('X-Token'); } catch (Exception $e) {}
            }
            if (!$token) {
                $token = $req->get('token');
            }
        }
        if (!$token) return false;
        return self::verify($token);
    }
}