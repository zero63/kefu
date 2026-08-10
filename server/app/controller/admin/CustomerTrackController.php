<?php
/**
 * 客户访问轨迹 + 自定义属性
 * 作者：kefu 开发团队
 * 创建时间：2026-08-01
 */
namespace app\controller\admin;

use support\Request;
use app\lib\Db;

class CustomerTrackController
{
    /**
     * 客户轨迹列表（按 customer_id 分组）
     */
    public function list(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $page = max(1, intval($request->get('page', 1)));
        $size = 20;
        $offset = ($page - 1) * $size;
        $where = 'WHERE tenant_id = :t';
        $bind = [':t' => $tenantId];
        if ($cid = trim($request->get('customer_id', ''))) {
            $where .= ' AND customer_id = :cid';
            $bind[':cid'] = $cid;
        }
        $total = Db::value("SELECT COUNT(*) FROM kefu_customer_track $where", $bind);
        $bind[':limit'] = $size;
        $bind[':offset'] = $offset;
        $rows = Db::query(
            "SELECT * FROM kefu_customer_track $where ORDER BY id DESC LIMIT :limit OFFSET :offset",
            $bind
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'list' => $rows, 'total' => (int)$total, 'page' => $page, 'size' => $size
        ]]);
    }

    /**
     * 客户轨迹时间线（按 customer_id 全量）
     */
    public function timeline(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $cid = trim($request->get('customer_id', ''));
        if ($cid === '') return json(['code' => 400, 'msg' => 'customer_id 必填']);
        $rows = Db::query(
            "SELECT id, event_type, page_url, page_title, referrer, duration_ms, created_at
             FROM kefu_customer_track
             WHERE tenant_id = :t AND customer_id = :c
             ORDER BY id DESC LIMIT 200",
            [':t' => $tenantId, ':c' => $cid]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    /**
     * 记录轨迹（访客端）
     */
    public function record(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $cid = trim($request->post('customer_id', ''));
        $eventType = trim($request->post('event_type', 'page_view'));
        if ($cid === '') return json(['code' => 400, 'msg' => 'customer_id 必填']);
        Db::insert('kefu_customer_track', [
            'tenant_id'   => $tenantId,
            'customer_id' => $cid,
            'session_id'  => trim($request->post('session_id', '')),
            'event_type'  => $eventType,
            'page_url'    => trim($request->post('page_url', '')),
            'page_title'  => trim($request->post('page_title', '')),
            'referrer'    => trim($request->post('referrer', '')),
            'duration_ms' => intval($request->post('duration_ms', 0)),
            'extra_json'  => json_encode($request->post('extra', []), JSON_UNESCAPED_UNICODE),
            'ip'          => $request->getRealIp(),
            'user_agent'  => substr($request->header('user-agent', ''), 0, 500),
        ]);
        return json(['code' => 0, 'msg' => 'ok']);
    }

    // ====== 自定义字段 ======

    /**
     * 自定义字段定义列表
     */
    public function fieldList(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $rows = Db::query(
            "SELECT * FROM kefu_customer_field WHERE tenant_id = :t ORDER BY sort_no, id",
            [':t' => $tenantId]
        );
        foreach ($rows as &$r) {
            $r['options'] = $r['options_json'] ? json_decode($r['options_json'], true) : [];
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    /**
     * 新增自定义字段
     */
    public function fieldCreate(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $key = trim($request->post('field_key', ''));
        $name = trim($request->post('field_name', ''));
        if ($key === '' || $name === '') return json(['code' => 400, 'msg' => '字段标识和名称必填']);
        if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/', $key)) {
            return json(['code' => 400, 'msg' => '字段标识需以小写字母开头，仅含字母数字下划线']);
        }
        Db::setTenantId($tenantId);
        $exists = Db::value(
            "SELECT COUNT(*) FROM kefu_customer_field WHERE tenant_id = :t AND field_key = :k",
            [':t' => $tenantId, ':k' => $key]
        );
        if ($exists > 0) return json(['code' => 400, 'msg' => '字段标识已存在']);
        $id = Db::insert('kefu_customer_field', [
            'tenant_id'   => $tenantId,
            'field_key'   => $key,
            'field_name'  => $name,
            'field_type'  => $request->post('field_type', 'text'),
            'options_json'=> json_encode($request->post('options', []), JSON_UNESCAPED_UNICODE),
            'required'    => intval($request->post('required', 0)),
            'sort_no'     => intval($request->post('sort_no', 0)),
        ]);
        return json(['code' => 0, 'msg' => '已添加', 'data' => ['id' => $id]]);
    }

    /**
     * 删除自定义字段
     */
    public function fieldDelete(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        Db::exec("DELETE FROM kefu_customer_field_value WHERE tenant_id = :t AND field_id = :id",
            [':t' => $tenantId, ':id' => $id]);
        Db::delete('kefu_customer_field', ['id' => $id]);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    /**
     * 获取某客户的所有自定义字段值
     */
    public function values(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $cid = trim($request->get('customer_id', ''));
        if ($cid === '') return json(['code' => 400, 'msg' => 'customer_id 必填']);
        $rows = Db::query(
            "SELECT f.field_key, f.field_name, f.field_type, v.field_value
             FROM kefu_customer_field f
             LEFT JOIN kefu_customer_field_value v ON v.field_id = f.id AND v.customer_id = :cid
             WHERE f.tenant_id = :t ORDER BY f.sort_no, f.id",
            [':t' => $tenantId, ':cid' => $cid]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    /**
     * 保存客户自定义字段值
     */
    public function saveValues(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $cid = trim($request->post('customer_id', ''));
        $values = $request->post('values', []);
        if ($cid === '' || !is_array($values)) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        Db::setTenantId($tenantId);
        // 先取出所有字段定义
        $fields = Db::query("SELECT id, field_key FROM kefu_customer_field WHERE tenant_id = :t",
            [':t' => $tenantId]);
        $keyMap = [];
        foreach ($fields as $f) $keyMap[$f['field_key']] = $f['id'];
        foreach ($values as $k => $v) {
            if (!isset($keyMap[$k])) continue;
            $fid = $keyMap[$k];
            // upsert
            $exists = Db::value(
                "SELECT COUNT(*) FROM kefu_customer_field_value
                 WHERE tenant_id = :t AND customer_id = :c AND field_id = :f",
                [':t' => $tenantId, ':c' => $cid, ':f' => $fid]
            );
            if ($exists > 0) {
                Db::update('kefu_customer_field_value',
                    ['field_value' => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v],
                    ['customer_id' => $cid, 'field_id' => $fid]
                );
            } else {
                Db::insert('kefu_customer_field_value', [
                    'tenant_id'   => $tenantId,
                    'customer_id' => $cid,
                    'field_id'    => $fid,
                    'field_value' => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v,
                ]);
            }
        }
        return json(['code' => 0, 'msg' => '已保存']);
    }
}