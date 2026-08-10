<?php
/**
 * Token 鉴权中间件
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 * 说明：
 *   - 解析请求头中的 JWT Token
 *   - 注入 employee_id、tenant_id、role_id 到 request
 *   - 失效 Token 返回 401
 */

namespace app\middleware;

use app\lib\Token;
use app\service\AuthService;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $token = Token::extractFromRequest();
        $payload = $token ? Token::verify($token) : false;

        if (!$payload) {
            return json([
                'code' => 401,
                'msg'  => '未登录或登录已过期',
                'data' => null,
            ]);
        }

        // 检查 Token 是否已被注销（黑名单）
        $jti = $payload['jti'] ?? '';
        if (!empty($jti) && AuthService::isBlacklisted($jti)) {
            return json([
                'code' => 401,
                'msg'  => '登录已失效，请重新登录',
                'data' => null,
            ]);
        }

        // 注入到 request，供后续使用
        $request->employee_id = $payload['employee_id'] ?? 0;
        $request->tenant_id   = $payload['tenant_id'] ?? 0;
        $request->role_id     = $payload['role_id'] ?? 0;
        $request->username    = $payload['username'] ?? '';

        // 客服心跳：每分钟更新一次 last_active_at，且只要不是"已离线"就保持 online
        // 这样前端没主动切状态时也能保持可用，被自动分配识别
        // 真正手动设置为 'offline' 的客服（点过离线按钮）不会被自动覆盖
        // 增加 manual_offline_at 字段：记录客服最后一次主动离线的时间，避免心跳覆盖
        if (intval($request->role_id) >= 3 && intval($request->employee_id) > 0) {
            try {
                \app\lib\Db::exec(
                    "UPDATE kefu_employee
                     SET last_active_at = NOW(),
                         work_status   = IF(manual_offline_at IS NOT NULL
                                              AND manual_offline_at > DATE_SUB(NOW(), INTERVAL 8 HOUR),
                                            'offline', 'online')
                     WHERE id = :id AND (last_active_at IS NULL OR last_active_at < DATE_SUB(NOW(), INTERVAL 1 MINUTE))",
                    [':id' => intval($request->employee_id)]
                );
            } catch (\Throwable $e) {}
        }

        return $next($request);
    }
}