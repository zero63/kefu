<?php
/**
 * 访客自定义字段管理
 * - 后台管理：字段定义 CRUD
 * - 访客端：获取字段定义、提交字段值（公开接口）
 * - 客服端：读取访客的自定义字段值
 */
namespace app\controller;

use support\Request;
use app\lib\Db;
use app\lib\Logger;

class VisitorFieldController
{
    /**
     * 获取租户的访客字段定义（公开）
     * 前端 widget 用，列出当前租户开启了哪些字段
     */
    public function list(Request $request)
    {
        $tenantId = $this->resolveTenant($request);
        if (!$tenantId) return json(['code' => 400, 'msg' => 'tenant_id required']);
        Db::setTenantId($tenantId);
        $rows = Db::query(
            "SELECT id, field_key, field_name, field_type, options_json, required, placeholder, sort_no
             FROM kefu_visitor_field
             WHERE tenant_id = :t AND enabled = 1
             ORDER BY sort_no, id",
            [':t' => $tenantId]
        );
        foreach ($rows as &$r) {
            $r['required'] = (int)$r['required'];
            $r['options'] = $r['options_json'] ? json_decode($r['options_json'], true) : [];
            unset($r['options_json']);
            unset($r['sort_no']);
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    /**
     * 访客提交自定义字段值（公开）
     */
    public function saveValue(Request $request)
    {
        $tenantId = $this->resolveTenant($request);
        $visitorId = trim($request->post('visitor_id', ''));
        $sessionId = trim($request->post('session_id', ''));
        if (!$visitorId) return json(['code' => 400, 'msg' => 'visitor_id 必填']);
        $values = $request->post('values', []);
        if (!is_array($values)) $values = [];
        Db::setTenantId($tenantId);

        // 加载字段定义，校验字段
        $defs = Db::query("SELECT id, field_key, required FROM kefu_visitor_field WHERE tenant_id = :t AND enabled = 1",
            [':t' => $tenantId]);
        $defMap = [];
        foreach ($defs as $d) $defMap[$d['field_key']] = $d;

        // 校验必填
        foreach ($defs as $d) {
            if ($d['required']) {
                $v = isset($values[$d['field_key']]) ? $values[$d['field_key']] : null;
                $empty = ($v === null) || ($v === '') || (is_array($v) && empty($v));
                if ($empty) return json(['code' => 400, 'msg' => '字段"' . $d['field_key'] . '"必填']);
            }
        }

        // upsert
        foreach ($values as $key => $val) {
            if (!isset($defMap[$key])) continue; // 跳过未知字段
            $fid = $defMap[$key]['id'];
            if (is_array($val)) $val = json_encode($val, JSON_UNESCAPED_UNICODE);
            $val = (string)$val;

            $exists = Db::value(
                "SELECT id FROM kefu_visitor_field_value
                 WHERE tenant_id = :t AND visitor_id = :v AND field_id = :f",
                [':t' => $tenantId, ':v' => $visitorId, ':f' => $fid]
            );
            if ($exists) {
                Db::exec(
                    "UPDATE kefu_visitor_field_value SET field_value = :v, session_id = COALESCE(:s, session_id)
                     WHERE id = :id",
                    [':v' => $val, ':s' => $sessionId ?: null, ':id' => $exists]
                );
            } else {
                Db::insert('kefu_visitor_field_value', [
                    'tenant_id'    => $tenantId,
                    'visitor_id'   => $visitorId,
                    'field_id'     => $fid,
                    'field_value'  => $val,
                    'session_id'   => $sessionId ?: null,
                ]);
            }
        }
        return json(['code' => 0, 'msg' => '已保存']);
    }

    /**
     * 读取访客的自定义字段值（后台/客服用）
     */
    public function getValues(Request $request)
    {
        $tenantId = $this->resolveTenant($request);
        if (!$tenantId) return json(['code' => 400, 'msg' => 'tenant_id required']);
        $visitorId = trim($request->get('visitor_id', ''));
        $sessionId = trim($request->get('session_id', ''));
        if (!$visitorId && !$sessionId) return json(['code' => 400, 'msg' => 'visitor_id 或 session_id 必填']);
        Db::setTenantId($tenantId);
        $where = 'WHERE v.tenant_id = :t';
        $bind = [':t' => $tenantId];
        if ($visitorId) {
            $where .= ' AND v.visitor_id = :v';
            $bind[':v'] = $visitorId;
        }
        if ($sessionId) {
            $where .= ' AND v.session_id = :s';
            $bind[':s'] = $sessionId;
        }
        $rows = Db::query(
            "SELECT f.field_key, f.field_name, f.field_type, v.field_value, v.updated_at
             FROM kefu_visitor_field_value v
             JOIN kefu_visitor_field f ON f.id = v.field_id
             $where
             ORDER BY f.sort_no, f.id",
            $bind
        );
        $out = [];
        foreach ($rows as $r) {
            // checkbox 类型解析
            if ($r['field_type'] === 'checkbox' && $r['field_value']) {
                $decoded = json_decode($r['field_value'], true);
                $r['field_value'] = is_array($decoded) ? $decoded : [];
            }
            $out[$r['field_key']] = [
                'name'  => $r['field_name'],
                'type'  => $r['field_type'],
                'value' => $r['field_value'],
                'updated_at' => $r['updated_at'],
            ];
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['fields' => $out]]);
    }

    /**
     * 后台：字段定义列表（带统计）
     */
    public function adminList(Request $request)
    {
        $tenantId = $this->resolveTenant($request);
        if (!$tenantId) return json(['code' => 400, 'msg' => 'tenant_id required']);
        Db::setTenantId($tenantId);
        $rows = Db::query(
            "SELECT f.*, (SELECT COUNT(DISTINCT v.visitor_id) FROM kefu_visitor_field_value v
                          WHERE v.tenant_id = f.tenant_id AND v.field_id = f.id) AS visitor_count
             FROM kefu_visitor_field f
             WHERE f.tenant_id = :t
             ORDER BY f.sort_no, f.id",
            [':t' => $tenantId]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    /**
     * 后台：新增字段定义
     */
    public function adminCreate(Request $request)
    {
        $tenantId = $this->resolveTenant($request);
        if (!$tenantId) return json(['code' => 400, 'msg' => 'tenant_id required']);
        $key = trim($request->post('field_key', ''));
        $name = trim($request->post('field_name', ''));
        $type = trim($request->post('field_type', 'text'));
        if (!$key || !$name) return json(['code' => 400, 'msg' => '字段标识和名称必填']);
        if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/', $key)) {
            return json(['code' => 400, 'msg' => '字段标识格式错误（以小写字母开头）']);
        }
        if (!in_array($type, ['text','textarea','select','radio','checkbox','date','number','email','phone'])) {
            $type = 'text';
        }
        $opts = $request->post('options', []);
        if (is_array($opts)) $opts = json_encode($opts, JSON_UNESCAPED_UNICODE);

        Db::setTenantId($tenantId);
        $exists = Db::value(
            "SELECT COUNT(*) FROM kefu_visitor_field WHERE tenant_id = :t AND field_key = :k",
            [':t' => $tenantId, ':k' => $key]
        );
        if ($exists > 0) return json(['code' => 400, 'msg' => '字段标识已存在']);

        try {
            $id = Db::insert('kefu_visitor_field', [
                'tenant_id' => $tenantId,
                'field_key' => $key,
                'field_name' => $name,
                'field_type' => $type,
                'options_json' => $opts,
                'required' => intval($request->post('required', 0)),
                'placeholder' => trim($request->post('placeholder', '')),
                'sort_no' => intval($request->post('sort_no', 0)),
                'enabled' => 1,
            ]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '保存失败：' . $e->getMessage()]);
        }
        return json(['code' => 0, 'msg' => '已添加', 'data' => ['id' => $id]]);
    }

    /**
     * 后台：修改字段定义
     */
    public function adminUpdate(Request $request)
    {
        $tenantId = $this->resolveTenant($request);
        if (!$tenantId) return json(['code' => 400, 'msg' => 'tenant_id required']);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id 必填']);
        $data = [];
        if ($request->post('field_name') !== null) $data['field_name'] = trim($request->post('field_name'));
        if ($request->post('field_type') !== null) $data['field_type'] = trim($request->post('field_type'));
        if ($request->post('options') !== null) {
            $opts = $request->post('options', []);
            $data['options_json'] = is_array($opts) ? json_encode($opts, JSON_UNESCAPED_UNICODE) : $opts;
        }
        if ($request->post('required') !== null) $data['required'] = intval($request->post('required'));
        if ($request->post('placeholder') !== null) $data['placeholder'] = trim($request->post('placeholder'));
        if ($request->post('sort_no') !== null) $data['sort_no'] = intval($request->post('sort_no'));
        if ($request->post('enabled') !== null) $data['enabled'] = intval($request->post('enabled'));

        if (!$data) return json(['code' => 400, 'msg' => '无更新字段']);

        Db::setTenantId($tenantId);
        Db::update('kefu_visitor_field', $data, ['id' => $id]);
        return json(['code' => 0, 'msg' => '已更新']);
    }

    /**
     * 后台：删除字段定义
     */
    public function adminDelete(Request $request)
    {
        $tenantId = $this->resolveTenant($request);
        if (!$tenantId) return json(['code' => 400, 'msg' => 'tenant_id required']);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id 必填']);
        Db::setTenantId($tenantId);
        Db::exec("DELETE FROM kefu_visitor_field_value WHERE tenant_id = :t AND field_id = :id",
            [':t' => $tenantId, ':id' => $id]);
        Db::exec("DELETE FROM kefu_visitor_field WHERE tenant_id = :t AND id = :id",
            [':t' => $tenantId, ':id' => $id]);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    private function resolveTenant(Request $request) {
        $tid = intval($request->header('x-tenant-id', 0));
        if ($tid > 0) return $tid;
        $tid = intval($request->get('tenant_id', 0));
        if ($tid > 0) return $tid;
        $tid = intval($request->post('tenant_id', 0));
        if ($tid > 0) return $tid;
        return 1; // 默认租户
    }
}