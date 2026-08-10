<?php
/**
 * 依赖注入容器配置
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 *
 * 配置容器实例：返回一个 Webman\Container 单例
 * 所有 Controller 通过它来获取依赖
 *
 * 文档：https://www.workerman.net/doc/webman/container.html
 */

$container = new \Webman\Container();

// 注册数据库连接
$container->addDefinitions([
    // Db 单例
    \app\lib\Db::class => function () {
        return \app\lib\Db::pdo();
    },
    // Logger 单例
    \app\lib\Logger::class => function () {
        return new \app\lib\Logger();
    },
    // Token 单例
    \app\lib\Token::class => function () {
        return new \app\lib\Token();
    },
]);

return $container;