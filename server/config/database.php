<?php
/**
 * 数据库配置
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 * 说明：从 .env 读取数据库连接信息
 */

return [
    'default' => 'mysql',

    'connections' => [
        'mysql' => [
            'driver'    => 'mysql',
            'host'      => env('DB_HOST', '127.0.0.1'),
            'port'      => env('DB_PORT', 3306),
            'database'  => env('DB_DATABASE', 'kefu'),
            'username'  => env('DB_USERNAME', 'kefu'),
            'password'  => env('DB_PASSWORD', 'adminkefu'),
            'charset'   => env('DB_CHARSET', 'utf8mb4'),
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => env('DB_PREFIX', ''),
            'strict'    => true,
            'engine'    => 'InnoDB',
            'options'   => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        ],
    ],
];