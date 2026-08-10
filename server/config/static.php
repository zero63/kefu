<?php
/**
 * 静态文件中间件配置
 *
 * 说明：
 *   - static.enable = true 允许 webman 直接返回 public/ 下的静态文件
 *   - middleware：可对静态文件加中间件（鉴权、限流、缓存等）
 *   - 在生产环境建议用 nginx/apache 提供静态资源，这里给开发用
 */
return [
    'enable'    => true,
    'middleware' => [
        // app\middleware\StaticAuthMiddleware::class, // 如果需要登录才能访问静态资源
    ],
];