<?php
/**
 * API 限流中间件（基于文件缓存）
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 * 说明：每 IP 每分钟最多 60 次请求（生产环境建议接 Redis）
 */

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * 限流配置：每分钟最大请求数
     */
    private $maxPerMinute = 60;

    public function process(Request $request, callable $next): Response
    {
        $ip = $request->getRealIp();
        $key = "rate_limit:" . $ip . ":" . date('Y-m-d-H-i');
        $cacheDir = runtime_path('cache');
        if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . md5($key) . '.txt';

        $count = 0;
        if (file_exists($cacheFile)) {
            $count = intval(file_get_contents($cacheFile));
        }

        if ($count >= $this->maxPerMinute) {
            return json([
                'code' => 429,
                'msg'  => '请求过于频繁，请稍后再试',
                'data' => null,
            ]);
        }

        file_put_contents($cacheFile, $count + 1, LOCK_EX);

        return $next($request);
    }
}