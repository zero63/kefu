<?php
/**
 * 应用全局业务辅助函数
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 * 说明：
 *   - 仅定义业务函数（app_path/runtime_path 等由 webman framework 提供）
 *   - 不能与 framework 的 helpers.php 冲突
 */

if (!function_exists('env')) {
    /**
     * 读取 .env 配置
     */
    function env($key, $default = null) {
        $value = getenv($key);
        if ($value === false) {
            static $envCache = null;
            if ($envCache === null) {
                $envFile = dirname(__DIR__) . '/.env';
                $envCache = [];
                if (file_exists($envFile)) {
                    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    foreach ($lines as $line) {
                        if (strpos(trim($line), '#') === 0) continue;
                        if (strpos($line, '=') === false) continue;
                        list($k, $v) = explode('=', $line, 2);
                        $envCache[trim($k)] = trim($v, " \t\"'");
                    }
                }
            }
            $value = isset($envCache[$key]) ? $envCache[$key] : $default;
        }
        return $value;
    }
}

if (!function_exists('kefu_json')) {
    /**
     * 统一 JSON 响应（业务级）
     */
    function kefu_json($code = 0, $msg = 'ok', $data = null) {
        return json([
            'code' => $code,
            'msg'  => $msg,
            'data' => $data,
            'timestamp' => time(),
        ]);
    }
}

if (!function_exists('kefu_success')) {
    function kefu_success($data = null, $msg = 'ok') {
        return kefu_json(0, $msg, $data);
    }
}

if (!function_exists('kefu_fail')) {
    function kefu_fail($code = 1, $msg = 'fail', $data = null) {
        return kefu_json($code, $msg, $data);
    }
}