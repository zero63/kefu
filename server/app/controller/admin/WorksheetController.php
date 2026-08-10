<?php
/**
 * 管理后台 - 工单系统
 * 功能：工单CRUD、分配、回复、关闭
 * 作者：kefu 开发团队
 */
namespace app\controller\admin;

use support\Request;
use app\lib\Db;

class WorksheetController
{
    /**
     * 工单列表（分页 + 筛选）
     */
    public function list(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $empId = intval($request->employee_id ?? 0);
        $page = max(1, intval($request->get('page', 1)));
        $size = min(100, max(10, intval($request->get('size', 20))));
        $offset = ($page - 1) * $size;

        Db::setTenantId($tenantId);
        $where = 'WHERE tenant_id = :t';
        $bind = [':t' => $tenantId];
        foreach (['status', 'priority', 'assigned_to'] as $f) {
            $v = $request->get($f, '');
            if ($v !== '') {
                $where .= " AND $f = :$f";
                $bind[":$f"] = $v;
            }
        }
        // filterId: my_received / my_processed / my_followed / my_assigned / all
        $filter = trim($request->get('filter_id', 'all'));
        if ($filter === 'my_received') {
            $where .= ' AND assigned_to = :me';
            $bind[':me'] = $empId;
        } elseif ($filter === 'my_processed') {
            $where .= ' AND created_by = :me2';
            $bind[':me2'] = $empId;
        } elseif ($filter === 'my_followed') {
            $where .= ' AND (assigned_to = :me3 OR created_by = :me3)';
            $bind[':me3'] = $empId;
        } elseif ($filter === 'my_assigned') {
            $where .= ' AND assigned_to = :me4 AND status IN (\'open\',\'pending\')';
            $bind[':me4'] = $empId;
        }
        if ($kw = trim($request->get('keyword', ''))) {
            $where .= ' AND (title LIKE :k OR ticket_no LIKE :k2 OR customer_name LIKE :k)';
            $bind[':k'] = "%{$kw}%";
            $bind[':k2'] = "%{$kw}%";
        }

        $total = Db::value("SELECT COUNT(*) FROM kefu_worksheet $where", $bind);
        $bind[':limit'] = $size;
        $bind[':offset'] = $offset;

        $rows = Db::query(
            "SELECT id, ticket_no, title, priority, status, source,
                    customer_id, customer_name, session_id,
                    assigned_to, assigned_to_name,
                    created_by, created_by_name, created_at, updated_at
             FROM kefu_worksheet $where
             ORDER BY id DESC LIMIT :limit OFFSET :offset",
            $bind
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'list' => $rows, 'total' => (int)$total, 'page' => $page, 'size' => $size
        ]]);
    }

    /**
     * 工单详情
     */
    public function detail(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->get('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);

        Db::setTenantId($tenantId);
        $ws = Db::find("SELECT * FROM kefu_worksheet WHERE tenant_id = :t AND id = :id",
            [':t' => $tenantId, ':id' => $id]);
        if (!$ws) return json(['code' => 404, 'msg' => '工单不存在']);

        $replies = Db::query(
            "SELECT * FROM kefu_worksheet_reply
             WHERE tenant_id = :t AND worksheet_id = :w
             ORDER BY id ASC",
            [':t' => $tenantId, ':w' => $id]
        );

        // 自定义字段定义和值
        $fieldDefs = Db::query(
            "SELECT id, field_key, field_name, field_type, options_json, required, sort_no
             FROM kefu_worksheet_field WHERE tenant_id = :t ORDER BY sort_no, id",
            [':t' => $tenantId]
        );
        $fieldVals = Db::query(
            "SELECT field_id, field_value FROM kefu_worksheet_field_value
             WHERE worksheet_id = :w",
            [':w' => $id]
        );
        $valMap = [];
        foreach ($fieldVals as $v) $valMap[$v['field_id']] = $v['field_value'];

        $customFields = [];
        foreach ($fieldDefs as $f) {
            $raw = isset($valMap[$f['id']]) ? $valMap[$f['id']] : '';
            // checkbox 类型存储为 JSON 数组
            if ($f['field_type'] === 'checkbox' && $raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) $raw = $decoded;
            }
            $customFields[] = [
                'field_key'   => $f['field_key'],
                'field_name'  => $f['field_name'],
                'field_type'  => $f['field_type'],
                'options'     => $f['options_json'] ? json_decode($f['options_json'], true) : [],
                'required'    => (int)$f['required'],
                'value'       => $raw,
            ];
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'worksheet' => $ws, 'replies' => $replies, 'custom_fields' => $customFields,
        ]]);
    }

    /**
     * 创建工单
     */
    public function create(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $title = trim($request->post('title', ''));
        $content = trim($request->post('content', ''));
        if ($title === '' || $content === '') {
            return json(['code' => 400, 'msg' => '标题和内容必填']);
        }
        $empId = intval($request->employee_id ?? 0);
        $empName = trim($request->employee_name ?? '');

        Db::setTenantId($tenantId);

        // 生成工单号：T + yyyymmdd + 4 位序号
        $today = date('Ymd');
        $todayPrefix = 'T' . $today;
        $seq = Db::value(
            "SELECT COUNT(*) FROM kefu_worksheet WHERE tenant_id = :t AND ticket_no LIKE :p",
            [':t' => $tenantId, ':p' => $todayPrefix . '%']
        ) + 1;
        $ticketNo = $todayPrefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

        $id = Db::insert('kefu_worksheet', [
            'tenant_id'        => $tenantId,
            'ticket_no'        => $ticketNo,
            'title'            => $title,
            'content'          => $content,
            'priority'         => $request->post('priority', 'medium'),
            'status'           => 'open',
            'source'           => $request->post('source', 'manual'),
            'customer_id'      => trim($request->post('customer_id', '')),
            'customer_name'    => trim($request->post('customer_name', '')),
            'session_id'       => trim($request->post('session_id', '')),
            'assigned_to'      => intval($request->post('assigned_to', 0)) ?: null,
            'assigned_to_name' => trim($request->post('assigned_to_name', '')),
            'created_by'       => $empId ?: null,
            'created_by_name'  => $empName,
        ]);

        // 保存自定义字段值
        $customFields = $request->post('custom_fields', null);
        if ($id && is_array($customFields) && !empty($customFields)) {
            $this->saveFieldValues($tenantId, $id, $customFields);
        }

        return json(['code' => 0, 'msg' => '已创建', 'data' => ['id' => $id, 'ticket_no' => $ticketNo]]);
    }

    /**
     * 保存工单的自定义字段值
     */
    private function saveFieldValues($tenantId, $worksheetId, $customFields) {
        try {
            // 读取所有字段定义，key=>field
            $defs = Db::query("SELECT id, field_key, required FROM kefu_worksheet_field WHERE tenant_id = :t",
                [':t' => $tenantId]);
            $map = [];
            foreach ($defs as $d) $map[$d['field_key']] = $d;

            foreach ($customFields as $key => $val) {
                if (!isset($map[$key])) continue;
                $fid = $map[$key]['id'];
                if (is_array($val)) $val = json_encode($val, JSON_UNESCAPED_UNICODE);
                $val = (string)$val;
                // upsert
                $exists = Db::value(
                    "SELECT id FROM kefu_worksheet_field_value WHERE worksheet_id = :w AND field_id = :f",
                    [':w' => $worksheetId, ':f' => $fid]
                );
                if ($exists) {
                    Db::exec(
                        "UPDATE kefu_worksheet_field_value SET field_value = :v WHERE id = :id",
                        [':v' => $val, ':id' => $exists]
                    );
                } else {
                    Db::insert('kefu_worksheet_field_value', [
                        'tenant_id'   => $tenantId,
                        'worksheet_id'=> $worksheetId,
                        'field_id'    => $fid,
                        'field_value' => $val,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // 保存字段值失败不影响工单创建
            \app\lib\Logger::warn('保存自定义字段失败', ['err' => $e->getMessage(), 'ws' => $worksheetId]);
        }
    }

    /**
     * 更新工单（标题/内容/优先级/分配/状态）
     */
    public function update(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);

        $data = [];
        foreach (['title', 'content', 'priority', 'status', 'assigned_to_name'] as $f) {
            if ($request->post($f) !== null) $data[$f] = trim($request->post($f, ''));
        }
        if ($request->post('assigned_to') !== null) {
            $data['assigned_to'] = intval($request->post('assigned_to')) ?: null;
        }

        if (empty($data)) return json(['code' => 400, 'msg' => '没有可更新的字段']);

        // 关闭时设置 resolved_at
        if (isset($data['status']) && in_array($data['status'], ['resolved', 'closed'])) {
            $ws = Db::find("SELECT status, resolved_at FROM kefu_worksheet WHERE id = :id",
                [':id' => $id]);
            if ($ws && !$ws['resolved_at']) {
                $data['resolved_at'] = date('Y-m-d H:i:s');
            }
        }

        Db::update('kefu_worksheet', $data, ['id' => $id]);
        return json(['code' => 0, 'msg' => '已更新']);
    }

    /**
     * 回复工单
     */
    public function reply(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $worksheetId = intval($request->post('worksheet_id', 0));
        $content = trim($request->post('content', ''));
        $replyType = $request->post('reply_type', 'reply');
        $empId = intval($request->employee_id ?? 0);
        $empName = trim($request->employee_name ?? '');

        if ($worksheetId <= 0 || $content === '') {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        Db::setTenantId($tenantId);
        // 校验工单存在
        $exists = Db::value("SELECT COUNT(*) FROM kefu_worksheet WHERE id = :id",
            [':id' => $worksheetId]);
        if (!$exists) return json(['code' => 404, 'msg' => '工单不存在']);

        $id = Db::insert('kefu_worksheet_reply', [
            'tenant_id'       => $tenantId,
            'worksheet_id'    => $worksheetId,
            'reply_type'      => $replyType,
            'content'         => $content,
            'replier_id'      => $empId ?: null,
            'replier_name'    => $empName,
            'customer_visible'=> $replyType == 'reply' ? 1 : 0,
        ]);
        // 状态自动变为 pending（如果还是 open）
        Db::exec("UPDATE kefu_worksheet SET status = 'pending' WHERE id = :id AND status = 'open'",
            [':id' => $worksheetId]);
        return json(['code' => 0, 'msg' => '已回复', 'data' => ['id' => $id]]);
    }

    /**
     * 关闭工单
     */
    public function close(Request $request)
    {
        return $this->updateStatus($request, 'closed', '已关闭');
    }

    /**
     * 重新打开
     */
    public function reopen(Request $request)
    {
        return $this->updateStatus($request, 'open', '已重新打开');
    }

    private function updateStatus(Request $request, $status, $msg)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        // 兼容 id 和 worksheet_id 两个字段名
        $id = intval($request->post('id', 0)) ?: intval($request->post('worksheet_id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        Db::update('kefu_worksheet',
            ['status' => $status] + ($status == 'closed' ? ['resolved_at' => date('Y-m-d H:i:s')] : []),
            ['id' => $id]);
        return json(['code' => 0, 'msg' => $msg]);
    }

    /**
     * 删除工单（仅超管）
     */
    public function delete(Request $request)
    {
        $roleId = intval($request->role_id ?? 0);
        if ($roleId > 2) return json(['code' => 403, 'msg' => '无权操作']);
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        Db::pdo()->beginTransaction();
        try {
            Db::exec("DELETE FROM kefu_worksheet_reply WHERE tenant_id = :t AND worksheet_id = :id",
                [':t' => $tenantId, ':id' => $id]);
            Db::exec("DELETE FROM kefu_worksheet WHERE tenant_id = :t AND id = :id",
                [':t' => $tenantId, ':id' => $id]);
            Db::pdo()->commit();
        } catch (\Throwable $e) {
            Db::pdo()->rollBack();
            return json(['code' => 500, 'msg' => '删除失败：' . $e->getMessage()]);
        }
        return json(['code' => 0, 'msg' => '已删除']);
    }

    /**
     * 统计（看板用）
     */
    public function stats(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);
        $rows = Db::query(
            "SELECT status, COUNT(*) AS cnt FROM kefu_worksheet
             WHERE tenant_id = :t GROUP BY status",
            [':t' => $tenantId]
        );
        $out = ['open' => 0, 'pending' => 0, 'resolved' => 0, 'closed' => 0];
        foreach ($rows as $r) $out[$r['status']] = (int)$r['cnt'];

        // SLA 提醒：超 2 倍首次响应时间仍未响应的工单
        $slaBreach = Db::query(
            "SELECT id, ticket_no, title, assigned_to, created_at,
                    TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS elapsed_min
             FROM kefu_worksheet
             WHERE tenant_id = :t AND status IN ('open', 'pending')
               AND assigned_to IS NOT NULL
               AND TIMESTAMPDIFF(MINUTE, created_at, NOW()) > 60
             ORDER BY elapsed_min DESC LIMIT 10",
            [':t' => $tenantId]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'status' => $out,
            'sla_breach' => $slaBreach,
        ]]);
    }

    // ============ 工单模板 ============

    public function templateList(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $rows = Db::query(
            "SELECT id, name, category, content, default_priority, default_assignee,
                    sla_response_min, sla_resolve_min, enabled, created_at
             FROM kefu_worksheet_template WHERE tenant_id = :t ORDER BY id DESC",
            [':t' => $tenantId]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    public function templateCreate(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $name = trim($request->post('name', ''));
        $content = trim($request->post('content', ''));
        if ($name === '' || $content === '') return json(['code' => 400, 'msg' => '名称和模板内容必填']);
        Db::setTenantId($tenantId);
        $id = Db::insert('kefu_worksheet_template', [
            'tenant_id'         => $tenantId,
            'name'              => $name,
            'category'          => trim($request->post('category', '')),
            'content'           => $content,
            'default_priority'  => $request->post('default_priority', 'medium'),
            'default_assignee'  => intval($request->post('default_assignee', 0)) ?: null,
            'sla_response_min'  => intval($request->post('sla_response_min', 30)),
            'sla_resolve_min'   => intval($request->post('sla_resolve_min', 240)),
            'enabled'           => 1,
        ]);
        return json(['code' => 0, 'msg' => '已创建', 'data' => ['id' => $id]]);
    }

    public function templateDelete(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        Db::delete('kefu_worksheet_template', ['id' => $id]);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    // ============ 工单自定义字段 ============

    public function fieldList(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $rows = Db::query(
            "SELECT id, field_key, field_name, field_type, required, sort_no
             FROM kefu_worksheet_field WHERE tenant_id = :t ORDER BY sort_no, id",
            [':t' => $tenantId]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    public function fieldCreate(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $key = trim($request->post('field_key', ''));
        $name = trim($request->post('field_name', ''));
        if ($key === '' || $name === '') return json(['code' => 400, 'msg' => '字段标识和名称必填']);
        if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/', $key)) {
            return json(['code' => 400, 'msg' => '字段标识格式错误（以小写字母开头，仅含字母数字下划线）']);
        }
        Db::setTenantId($tenantId);
        // 重复校验
        $exists = Db::value(
            "SELECT COUNT(*) FROM kefu_worksheet_field WHERE tenant_id = :t AND field_key = :k",
            [':t' => $tenantId, ':k' => $key]
        );
        if ($exists > 0) return json(['code' => 400, 'msg' => '字段标识已存在']);

        try {
            $id = Db::insert('kefu_worksheet_field', [
                'tenant_id'   => $tenantId,
                'field_key'   => $key,
                'field_name'  => $name,
                'field_type'  => $request->post('field_type', 'text'),
                'options_json'=> json_encode($request->post('options', []), JSON_UNESCAPED_UNICODE),
                'required'    => intval($request->post('required', 0)),
                'sort_no'     => intval($request->post('sort_no', 0)),
            ]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '保存失败：' . $e->getMessage()]);
        }
        return json(['code' => 0, 'msg' => '已添加', 'data' => ['id' => $id]]);
    }

    public function fieldDelete(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        Db::exec("DELETE FROM kefu_worksheet_field_value WHERE field_id = :id", [':id' => $id]);
        Db::delete('kefu_worksheet_field', ['id' => $id]);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    // ============ 工单流程配置（v3） ============

    public function processList(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $rows = Db::query(
            "SELECT id, name, description, steps_json, enabled, created_at
             FROM kefu_worksheet_process WHERE tenant_id = :t ORDER BY id DESC",
            [':t' => $tenantId]
        );
        foreach ($rows as &$r) {
            $r['steps'] = $r['steps_json'] ? json_decode($r['steps_json'], true) : [];
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    public function processCreate(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $name = trim($request->post('name', ''));
        $steps = $request->post('steps', []);
        if ($name === '' || empty($steps)) {
            return json(['code' => 400, 'msg' => '名称和步骤必填']);
        }
        Db::setTenantId($tenantId);
        $id = Db::insert('kefu_worksheet_process', [
            'tenant_id'  => $tenantId,
            'name'       => $name,
            'description'=> trim($request->post('description', '')),
            'steps_json' => json_encode($steps, JSON_UNESCAPED_UNICODE),
            'enabled'    => 1,
            'created_by' => intval($request->employee_id ?? 0) ?: null,
        ]);
        return json(['code' => 0, 'msg' => '已创建', 'data' => ['id' => $id]]);
    }

    public function processDelete(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        Db::delete('kefu_worksheet_process', ['id' => $id]);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    // ============ 工单分类（v3） ============

    public function categoryList(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $rows = Db::query(
            "SELECT id, parent_id, name, icon, sort_no, enabled, created_at
             FROM kefu_worksheet_category WHERE tenant_id = :t ORDER BY sort_no, id",
            [':t' => $tenantId]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    public function categoryCreate(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $name = trim($request->post('name', ''));
        if ($name === '') return json(['code' => 400, 'msg' => '名称必填']);
        Db::setTenantId($tenantId);
        $id = Db::insert('kefu_worksheet_category', [
            'tenant_id' => $tenantId,
            'parent_id' => intval($request->post('parent_id', 0)),
            'name'      => $name,
            'icon'      => trim($request->post('icon', '')),
            'sort_no'   => intval($request->post('sort_no', 0)),
            'enabled'   => 1,
        ]);
        return json(['code' => 0, 'msg' => '已创建', 'data' => ['id' => $id]]);
    }

    public function categoryDelete(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        $hasChildren = Db::value(
            "SELECT COUNT(*) FROM kefu_worksheet_category WHERE tenant_id = :t AND parent_id = :p",
            [':t' => $tenantId, ':p' => $id]
        );
        if ($hasChildren > 0) return json(['code' => 400, 'msg' => '存在子分类']);
        Db::delete('kefu_worksheet_category', ['id' => $id]);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    // ============ SLA 报表（v3） ============

    public function slaReport(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);
        // 各状态的 SLA 概览
        $summary = Db::query(
            "SELECT
                COUNT(*) AS total,
                SUM(sla_status = 'completed') AS completed,
                SUM(sla_status = 'breach') AS breach,
                SUM(sla_status = 'warning') AS warning,
                SUM(sla_status = 'normal') AS normal,
                ROUND(AVG(elapsed_min)) AS avg_elapsed_min
             FROM v_worksheet_sla
             WHERE tenant_id = :t",
            [':t' => $tenantId]
        );
        // 按员工排行
        $agentRank = Db::query(
            "SELECT w.assigned_to,
                    e.username, e.real_name,
                    COUNT(*) AS total,
                    SUM(sla_status='breach') AS breach,
                    SUM(sla_status='warning') AS warning,
                    ROUND(AVG(elapsed_min)) AS avg_min
             FROM v_worksheet_sla w
             LEFT JOIN kefu_employee e ON e.id = w.assigned_to
             WHERE w.tenant_id = :t AND w.assigned_to IS NOT NULL
             GROUP BY w.assigned_to
             ORDER BY breach DESC, total DESC LIMIT 20",
            [':t' => $tenantId]
        );
        // 趋势：每日 SLA 达成率
        $trend = Db::query(
            "SELECT DATE(w.created_at) AS d,
                    COUNT(*) AS total,
                    SUM(w.status IN ('closed','resolved')) AS resolved,
                    SUM(TIMESTAMPDIFF(MINUTE, w.created_at, w.resolved_at) <= 240) AS within_sla
             FROM kefu_worksheet w
             WHERE w.tenant_id = :t AND w.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
             GROUP BY DATE(w.created_at) ORDER BY d ASC",
            [':t' => $tenantId]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'summary'   => $summary[0] ?? [],
            'agent_rank'=> $agentRank,
            'trend'     => $trend,
        ]]);
    }
}