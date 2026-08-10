<?php
/**
 * Webman 服务器配置
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 */

return [
    // 监听地址（0.0.0.0 表示所有网卡）
    'listen' => 'http://0.0.0.0:8787',

    // 进程数（生产环境建议设置为 CPU 核数）
    'count' => 4,

    // 是否守护进程（Windows 下必须设为 false）
    'daemonize' => false,

    // 日志文件
    'log_file' => runtime_path() . '/logs/webman.log',

    // 进程名称
    'process_name' => 'kefu-webman',

    // PID 文件
    'pid_file' => runtime_path() . '/webman.pid',

    // stdout 文件
    'stdout_file' => runtime_path() . '/stdout.log',

    // 状态文件
    'status_file' => runtime_path() . '/status.log',

    // 最大包大小（10MB）
    'max_package_size' => 10 * 1024 * 1024,

    // 事件循环（Linux 上可设为 Event 或 Swoole）
    'event_loop' => '',

    // Worker 上下文（SSL 配置等可放这里）
    'context' => [],

    // reload 检测时间（秒）
    'reload_interval' => 1,

    // 停止超时（秒）
    'stop_timeout' => 2,
];