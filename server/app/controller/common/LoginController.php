<?php
/**
 * 登录/登出/Token 刷新接口
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 */

namespace app\controller\common;

use app\service\AuthService;
use support\Request;

class LoginController
{
    /**
     * 登录
     * POST /api/common/login
     * Body: { username, password, tenant_code? }
     */
    public function login(Request $request) {
        $username = trim($request->post('username', ''));
        $password = $request->post('password', '');
        $tenantCode = $request->post('tenant_code', '');

        if (empty($username) || empty($password)) {
            return json(['code' => 400, 'msg' => '用户名和密码不能为空']);
        }

        // 演示环境：默认租户 demo
        $tenantId = 1;
        if (!empty($tenantCode)) {
            // 根据 tenant_code 查找
            $tenant = \app\lib\Db::find(
                'SELECT id FROM kefu_tenant WHERE tenant_code = :code AND status = 1',
                [':code' => $tenantCode]
            );
            if (!$tenant) {
                return json(['code' => 400, 'msg' => '租户编码无效']);
            }
            $tenantId = $tenant['id'];
        }

        $service = new AuthService();
        $result = $service->login($username, $password, $tenantId);

        return json($result);
    }

    /**
     * 登出
     * POST /api/common/logout
     */
    public function logout(Request $request) {
        $employeeId = intval($request->employee_id ?? 0);

        // 提取当前 Token 并解析 jti、exp，用于写入黑名单
        $token = \app\lib\Token::extractFromRequest();
        $jti = null;
        $exp = null;
        if ($token) {
            $payload = \app\lib\Token::verify($token);
            if ($payload) {
                $jti = $payload['jti'] ?? null;
                $exp = $payload['exp'] ?? null;
            }
        }

        $service = new AuthService();
        return json($service->logout($employeeId, $jti, $exp));
    }

    /**
     * 刷新 Token
     * POST /api/common/refresh-token
     * Body: { token }
     */
    public function refresh(Request $request) {
        $token = $request->post('token', '');
        $service = new AuthService();
        return json($service->refreshToken($token));
    }
}