<?php
/**
 * CORS 跨域中间件
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 */

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class CorsMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        // 跨域预检请求直接返回
        if ($request->method() === 'OPTIONS') {
            $response = new Response('', 204);
        } else {
            $response = $next($request);
        }

        $response->withHeaders([
            'Access-Control-Allow-Origin'  => env('CORS_ALLOW_ORIGINS', '*'),
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Token, X-Tenant-Id',
            'Access-Control-Max-Age'       => '86400',
        ]);

        return $response;
    }
}