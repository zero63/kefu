<?php
/**
 * 多租户中间件
 * 自动从 token 中解析 tenant_id 并设置到 Db
 */
namespace app\middleware;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class TenantMiddleware implements MiddlewareInterface {
    public function process(Request $request, callable $handler): Response {
        // 从 token 解析 tenant_id
        $token = $request->header('Authorization', '');
        $token = $token ? preg_replace('/^Bearer\s+/i', '', $token) : '';
        if ($token) {
            try {
                $payload = \app\lib\Jwt::decode($token);
                if (isset($payload['tenant_id'])) {
                    $request->tenant_id = (int)$payload['tenant_id'];
                    \app\lib\Db::setTenantId($request->tenant_id);
                }
            } catch (\Throwable $e) {
                // 解码失败也允许通过，由 controller 自行校验
            }
        }
        return $handler($request);
    }
}