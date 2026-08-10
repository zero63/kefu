<?php
/**
 * 客服个人资料管理
 * 路由：
 *   GET  /api/agent/profile         获取当前登录客服的资料
 *   POST /api/agent/profile/update  更新资料（昵称、工号、头像、电话、邮箱、简介）
 */
namespace app\controller\agent;

use support\Request;
use app\lib\Db;
use app\lib\Logger;

class ProfileController
{
    /**
     * GET /api/agent/profile
     * 获取个人资料
     */
    public function index(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $employeeId = intval($request->employee_id ?? 0);
        Db::setTenantId($tenantId);

        $row = Db::find(
            "SELECT id, tenant_id, username, real_name, nickname, employee_no, avatar, phone, email, bio,
                    dept_id, role_id, max_sessions, skill_level, status, work_status, last_login_at
             FROM kefu_employee WHERE id = :id",
            [':id' => $employeeId]
        );
        if (!$row) return json(['code' => 404, 'msg' => '账号不存在']);

        // 角色名称、状态名称友好化
        $roleName = Db::value("SELECT role_name FROM kefu_role WHERE id = :i", [':i' => $row['role_id']]) ?: '客服';
        $deptName = $row['dept_id'] ? (Db::value("SELECT dept_name FROM kefu_dept WHERE id = :i", [':i' => $row['dept_id']]) ?: '-') : '-';
        $row['role_name'] = $roleName;
        $row['dept_name'] = $deptName;

        return json(['code' => 0, 'msg' => 'ok', 'data' => $row]);
    }

    /**
     * POST /api/agent/profile/update
     * 更新个人资料（仅可改：昵称/工号/头像/电话/邮箱/简介）
     * 不允许改：username, password, role_id, status 等敏感字段
     */
    public function update(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $employeeId = intval($request->employee_id ?? 0);
        Db::setTenantId($tenantId);

        // 兼容 JSON body
        $body = $request->_body_data ?? [];
        if (empty($body)) {
            $raw = $request->rawBody();
            $body = $raw ? (json_decode($raw, true) ?: []) : [];
        }
        $get = function ($key, $default = null) use ($body, $request) {
            if (array_key_exists($key, $body)) return $body[$key];
            return $request->post($key, $default);
        };

        $update = [];
        foreach (['nickname', 'employee_no', 'avatar', 'phone', 'email', 'bio', 'real_name'] as $f) {
            if ($body !== null && array_key_exists($f, $body)) {
                $v = $get($f);
                $update[$f] = is_string($v) ? trim($v) : $v;
            } elseif ($request->post($f) !== null && $request->post($f) !== '') {
                $update[$f] = trim((string)$request->post($f));
            }
        }
        if (empty($update)) return json(['code' => 400, 'msg' => '没有要更新的字段']);

        // 字段级校验
        if (isset($update['phone']) && $update['phone'] && !preg_match('/^[0-9+\-\s]{5,20}$/', $update['phone'])) {
            return json(['code' => 400, 'msg' => '电话格式不正确']);
        }
        if (isset($update['email']) && $update['email'] && !filter_var($update['email'], FILTER_VALIDATE_EMAIL)) {
            return json(['code' => 400, 'msg' => '邮箱格式不正确']);
        }
        if (isset($update['employee_no']) && $update['employee_no']) {
            $exist = Db::value("SELECT id FROM kefu_employee WHERE employee_no = :n AND id != :id",
                [':n' => $update['employee_no'], ':id' => $employeeId]);
            if ($exist) return json(['code' => 400, 'msg' => '工号已被占用']);
        }
        if (isset($update['avatar']) && $update['avatar']) {
            // 仅允许 http(s) 或 data: 开头的图片 URL
            if (!preg_match('#^(https?://|data:image/)#i', $update['avatar'])) {
                return json(['code' => 400, 'msg' => '头像地址必须为 https/http/data 协议']);
            }
            if (strlen($update['avatar']) > 240) {
                return json(['code' => 400, 'msg' => '头像地址过长']);
            }
        }
        if (isset($update['bio']) && mb_strlen((string)$update['bio']) > 200) {
            return json(['code' => 400, 'msg' => '个人简介请控制在 200 字以内']);
        }

        try {
            Db::update('kefu_employee', $update, ['id' => $employeeId]);
        } catch (\Throwable $e) {
            Logger::error('更新客服资料失败', ['err' => $e->getMessage(), 'emp' => $employeeId]);
            return json(['code' => 500, 'msg' => '保存失败：' . $e->getMessage()]);
        }

        $newRow = Db::find(
            "SELECT id, username, real_name, nickname, employee_no, avatar, phone, email, bio, dept_id, role_id
             FROM kefu_employee WHERE id = :id",
            [':id' => $employeeId]
        );
        return json(['code' => 0, 'msg' => '已保存', 'data' => $newRow]);
    }

    /**
     * POST /api/agent/profile/avatar
     * 上传头像（base64 图片或 URL）
     * Body: { data: 'data:image/png;base64,xxx' } 或 { url: 'https://...' }
     */
    public function uploadAvatar(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        $employeeId = intval($request->employee_id ?? 0);
        Db::setTenantId($tenantId);

        $body = $request->_body_data ?? [];
        if (empty($body)) {
            $raw = $request->rawBody();
            $body = $raw ? (json_decode($raw, true) ?: []) : [];
        }

        $url = null;
        if (!empty($body['data']) && strpos($body['data'], 'data:image/') === 0) {
            // 解析 base64
            $data = $body['data'];
            if (preg_match('#^data:image/(\w+);base64,(.+)$#', $data, $m)) {
                $ext = strtolower($m[1]);
                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'])) {
                    return json(['code' => 400, 'msg' => '仅支持 png/jpg/gif/webp']);
                }
                $bin = base64_decode($m[2], true);
                if (!$bin || strlen($bin) > 3 * 1024 * 1024) {
                    return json(['code' => 400, 'msg' => '图片不能超过 3MB 或 base64 解析失败']);
                }
                $dir = public_path() . '/uploads/avatar/' . $tenantId;
                if (!is_dir($dir)) @mkdir($dir, 0777, true);
                $filename = 'emp_' . $employeeId . '_' . substr(md5(uniqid()), 0, 8) . '.' . $ext;
                $file = $dir . '/' . $filename;
                if (!@file_put_contents($file, $bin)) {
                    return json(['code' => 500, 'msg' => '保存失败']);
                }
                // 用 host + path 生成可访问 URL
                $host = $request->header('host') ?: ($_SERVER['HTTP_HOST'] ?? '');
                $proto = (($request->header('x-forwarded-proto') ?: '') ?: (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http'));
                $url = rtrim($proto . '://' . $host, '/') . '/uploads/avatar/' . $tenantId . '/' . $filename;
            }
        } elseif (!empty($body['url'])) {
            $url = trim((string)$body['url']);
        }
        if (!$url) return json(['code' => 400, 'msg' => '请提供 data 或 url']);
        if (!preg_match('#^(https?://|data:image/)#i', $url)) {
            return json(['code' => 400, 'msg' => 'URL 协议不合法']);
        }

        try {
            Db::update('kefu_employee', ['avatar' => $url], ['id' => $employeeId]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '保存失败：' . $e->getMessage()]);
        }
        return json(['code' => 0, 'msg' => '已上传', 'data' => ['avatar' => $url]]);
    }
}