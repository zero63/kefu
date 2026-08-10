<?php
/**
 * 认证业务逻辑
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 */

namespace app\service;

use app\lib\Db;
use app\lib\Token;
use app\lib\Logger;

class AuthService
{
    /**
     * 客服/管理员登录
     * @param string $username
     * @param string $password
     * @param int|null $tenantId 指定租户，null 时按 username 匹配（简化：单租户演示）
     * @return array|false
     */
    public function login($username, $password, $tenantId = null) {
        // 多租户场景：按 username + tenant_id 查找
        if ($tenantId === null) {
            $tenantId = 1; // 演示环境默认为租户 1
        }
        Db::setTenantId(0); // 登录时跨租户查询

        $user = Db::find(
            'SELECT id, tenant_id, username, password, real_name, nickname, employee_no,
                    avatar, email, phone, dept_id, role_id, max_sessions, skill_level, status
             FROM kefu_employee
             WHERE tenant_id = :tid AND username = :username',
            [':tid' => $tenantId, ':username' => $username]
        );

        if (!$user) {
            Logger::warn('登录失败：用户不存在', ['username' => $username]);
            return ['code' => 1001, 'msg' => '用户名或密码错误'];
        }
        if ($user['status'] != 1) {
            return ['code' => 1002, 'msg' => '账号已被禁用'];
        }
        if (!password_verify($password, $user['password'])) {
            Logger::warn('登录失败：密码错误', ['username' => $username]);
            return ['code' => 1001, 'msg' => '用户名或密码错误'];
        }

        // 更新最后登录信息 + 登录即视为"在线"（只要角色是客服以上）
        // 这样新登录的客服无需再点工作台的状态切换按钮即可被自动分配
        $setOnline = ((int)$user['role_id'] >= 3) ? 'online' : null;
        Db::exec(
            'UPDATE kefu_employee
             SET last_login_at = NOW(),
                 last_login_ip = :ip,
                 work_status   = :ws,
                 last_active_at = NOW()
             WHERE id = :id',
            [':ip' => request()->getRealIp(), ':id' => $user['id'], ':ws' => $setOnline]
        );

        // 签发 Token
        $token = Token::issue([
            'employee_id' => $user['id'],
            'tenant_id'   => $user['tenant_id'],
            'role_id'     => $user['role_id'],
            'username'    => $user['username'],
        ]);

        // 不返回密码字段
        unset($user['password']);

        Logger::info('登录成功', ['username' => $username, 'employee_id' => $user['id']]);

        return [
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'token' => $token,
                'user'  => $user,
            ],
        ];
    }

    /**
     * 登出（撤销 Token —— 将 jti 写入文件黑名单）
     * @param int $employeeId 员工ID
     * @param string|null $jti Token 的唯一标识
     * @param int|null $exp Token 的过期时间戳
     * @return array
     */
    public function logout($employeeId, $jti = null, $exp = null) {
        Logger::info('登出', ['employee_id' => $employeeId, 'jti' => $jti]);

        // 将 jti 写入文件黑名单
        if (!empty($jti)) {
            $blacklistDir = runtime_path() . '/cache/jwt_blacklist';
            if (!is_dir($blacklistDir)) {
                @mkdir($blacklistDir, 0755, true);
            }
            // 文件内容为过期时间戳，过期后可清理
            $expireTs = !empty($exp) ? intval($exp) : (time() + 86400);
            @file_put_contents($blacklistDir . '/' . $jti, (string)$expireTs);
        }

        return ['code' => 0, 'msg' => 'ok'];
    }

    /**
     * 检查 jti 是否在黑名单中（文件版，不依赖 Redis）
     * @param string $jti Token 的唯一标识
     * @return bool true 表示已注销（在黑名单中）
     */
    public static function isBlacklisted($jti) {
        if (empty($jti)) {
            return false;
        }
        $file = runtime_path() . '/cache/jwt_blacklist/' . $jti;
        if (!file_exists($file)) {
            return false;
        }
        // 读取过期时间戳，已过期则视为不在黑名单（可清理）
        $expireTs = intval(@file_get_contents($file));
        if ($expireTs > 0 && $expireTs < time()) {
            // 已过期，清理文件
            @unlink($file);
            return false;
        }
        return true;
    }

    /**
     * 刷新 Token
     */
    public function refreshToken($oldToken) {
        $newToken = Token::refresh($oldToken);
        if (!$newToken) {
            return ['code' => 401, 'msg' => 'Token 无效或已过期'];
        }
        return ['code' => 0, 'msg' => 'ok', 'data' => ['token' => $newToken]];
    }
}