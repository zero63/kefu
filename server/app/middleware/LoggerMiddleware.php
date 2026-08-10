<?php
/**
 * 请求日志中间件
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 * 说明：记录所有 API 请求的 method/path/ip/耗时
 */

namespace app\middleware;

use app\lib\Logger;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class LoggerMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $start = microtime(true);
        $response = $next($request);
        $duration = round((microtime(true) - $start) * 1000, 2);

        Logger::info('API request', [
            'method'   => $request->method(),
            'path'     => $request->path(),
            'ip'       => $request->getRealIp(),
            'duration' => $duration . 'ms',
            'status'   => $response->getStatusCode(),
        ]);

        return $response;
    }
}