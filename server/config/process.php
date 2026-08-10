<?php
/**
 * 自定义进程配置
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 *
 * 说明：
 *   - 此配置只在 Linux 部署时启用
 *   - Windows 下 webman 1.5 不支持 fork 自定义进程，启用会让 webman 反复重启失败
 *
 * 部署到 Linux 后，把 PROCESS_ENABLED 设为 true 即可。
 */

// Windows 上不加载任何 custom process（除非显式开启 PROCESS_ENABLED）
$processEnabled = defined('PROCESS_ENABLED') && constant('PROCESS_ENABLED');
return $processEnabled ? [
    'robot-worker' => [
        'handler' => app\process\RobotWorker::class,
        'count'   => 1,
        'constructor' => [],
    ],
    'message-dispatcher' => [
        'handler' => app\process\MessageDispatcher::class,
        'count'   => 1,
        'constructor' => [],
    ],
    'cron-worker' => [
        'handler' => app\process\CronWorker::class,
        'count'   => 1,
        'constructor' => [],
    ],
] : [];