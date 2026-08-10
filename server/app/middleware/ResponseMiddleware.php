<?php
/**
 * 响应统一封装中间件
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 * 说明：所有 API 响应统一包装为 {code, msg, data, timestamp} 格式
 */

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class ResponseMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);
        $body = $response->rawBody();

        // 只处理 JSON 响应
        if (empty($body) || strpos($response->getHeader('Content-Type'), 'json') === false) {
            return $response;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || isset($data['code']) && isset($data['msg'])) {
            // 已经是标准格式，不再包装
            return $response;
        }

        // 包装为标准格式
        $wrapped = [
            'code'      => 0,
            'msg'       => 'ok',
            'data'      => $data,
            'timestamp' => time(),
        ];

        $response->withBody(json_encode($wrapped, JSON_UNESCAPED_UNICODE));
        return $response;
    }
}