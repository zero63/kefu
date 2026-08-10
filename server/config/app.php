<?php
/**
 * 应用基础配置
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 */

return [
    // 应用调试模式（生产环境必须设为 false）
    'debug' => true,

    // 错误显示
    'error_reporting' => E_ALL,

    // 默认时区（bootstrap 会自动设置）
    'default_timezone' => 'Asia/Shanghai',

    // 应用名称
    'name' => 'kefu',

    // 应用版本
    'version' => '1.0.0',

    // 多租户隔离开关
    'multi_tenant' => true,

    // 默认租户 ID（0 = 未登录）
    'default_tenant_id' => 0,

    // 静态资源目录（默认 runtime/public）
    // 我们用项目根 public/，便于开发
    'public_path' => BASE_PATH . '/public',
];