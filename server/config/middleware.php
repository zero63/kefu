<?php
/**
 * 全局中间件配置
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 * 说明：
 *
 * Webman 的中间件分组按 controller 命名空间的"app"部分（如
 * app\controller\agent\XController → app='agent'），
 * 或路由第一个 segment。如果实际匹配不上，看 Webman App.php 推断逻辑。
 *
 * Key 说明：
 *   ''     —— 全局（所有请求都经过）
 *   admin  —— /api/admin/* 路由
 *   agent  —— /api/agent/* 路由
 *   visitor —— /api/visitor/* 路由
 *   robot  —— /api/robot/* 路由
 */

return [
    // 全局中间件
    '' => [
        app\middleware\CorsMiddleware::class,
        app\middleware\LoggerMiddleware::class,
        app\middleware\ResponseMiddleware::class,
        // 修复：原配置缺 SensitiveFilterMiddleware，导致敏感词不生效。
        // webman 的 app 分组由 controller 命名空间推导，而 visitor/agent 的
        // controller 都在 app\controller\ 下，分组名都为空 ''。
        // 因此把敏感词中间件加到全局（路径匹配在中间件内部按 url 判断）。
        app\middleware\SensitiveFilterMiddleware::class,
    ],

    // 管理后台 /api/admin/*
    'admin' => [
        app\middleware\AuthMiddleware::class,
        app\middleware\TenantMiddleware::class,
        app\middleware\RateLimitMiddleware::class,
    ],

    // 客服工作台 /api/agent/*
    'agent' => [
        app\middleware\AuthMiddleware::class,
        app\middleware\TenantMiddleware::class,
        app\middleware\RateLimitMiddleware::class,
    ],

    // 访客端 /api/visitor/*
    'visitor' => [
        app\middleware\RateLimitMiddleware::class,
    ],

    // 机器人 /api/robot/*
    'robot' => [
        app\middleware\AuthMiddleware::class,
        app\middleware\TenantMiddleware::class,
    ],
];