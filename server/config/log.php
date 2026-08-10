<?php
/**
 * 日志配置（嵌套形式，适配 webman 的 config('log.channel_name') 取法）
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 */

return [
    // 默认通道（也支持 channels.default 的嵌套访问）
    'default' => [
        'driver' => 'file',
        'path'   => runtime_path() . '/logs/webman.log',
        'level'  => 'debug',
        'max_files' => 30,
    ],

    'api' => [
        'driver' => 'file',
        'path'   => runtime_path() . '/logs/api.log',
        'level'  => 'info',
        'max_files' => 30,
    ],

    'error' => [
        'driver' => 'file',
        'path'   => runtime_path() . '/logs/error.log',
        'level'  => 'error',
        'max_files' => 30,
    ],
];