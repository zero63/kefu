<?php
/**
 * 日志工具类
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 * 说明：分级别记录日志到 runtime/logs/ 目录，按日期分文件
 */

namespace app\lib;

class Logger
{
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO  = 'info';
    const LEVEL_WARN  = 'warn';
    const LEVEL_ERROR = 'error';

    /**
     * 写入日志
     * @param string $level
     * @param string $message
     * @param array $context
     */
    public static function write($level, $message, $context = []) {
        $logDir = runtime_path('logs');
        if (!is_dir($logDir)) mkdir($logDir, 0777, true);

        $date = date('Y-m-d');
        $file = $logDir . "/{$date}.log";

        $time = date('Y-m-d H:i:s');
        $ctxStr = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        $line = "[{$time}] [{$level}] {$message}{$ctxStr}\n";

        // 加锁写入
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public static function debug($msg, $ctx = []) { self::write(self::LEVEL_DEBUG, $msg, $ctx); }
    public static function info($msg, $ctx = [])  { self::write(self::LEVEL_INFO, $msg, $ctx); }
    public static function warn($msg, $ctx = [])  { self::write(self::LEVEL_WARN, $msg, $ctx); }
    public static function error($msg, $ctx = []) { self::write(self::LEVEL_ERROR, $msg, $ctx); }
}