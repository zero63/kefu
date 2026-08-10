<?php
namespace app\controller\admin;

use support\Request;
use app\lib\Db;

class CollaborationController
{
    // 会话备注

    public function noteList(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $sid = trim($request->get('session_id', ''));
        if ($sid === '') return json(['code' => 400, 'msg' => 'session_id 必填']);
        $rows = Db::query(
            "SELECT id, author_id, author_name, content, mentioned_ids, is_internal, created_at
             FROM kefu_session_note
             WHERE tenant_id = :t AND session_id = :s ORDER BY id DESC LIMIT 100",
            [':t' => $tenantId, ':s' => $sid]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    public function noteCreate(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $sid = trim($request->post('session_id', ''));
        $content = trim($request->post('content', ''));
        if ($sid === '' || $content === '') {
            return json(['code' => 400, 'msg' => '会话和内容必填']);
        }
        Db::setTenantId($tenantId);
        $empId = intval($request->employee_id ?? 0);
        $empName = trim($request->employee_name ?? '');
        $mentioned = array();
        if (preg_match_all('/@(\w+)/u', $content, $m)) {
            foreach ($m[1] as $name) {
                $row = Db::find(
                    "SELECT id FROM kefu_employee WHERE tenant_id = :t AND username = :u LIMIT 1",
                    [':t' => $tenantId, ':u' => $name]
                );
                if ($row) $mentioned[] = $row['id'];
            }
        }
        $id = Db::insert('kefu_session_note', [
            'tenant_id'    => $tenantId,
            'session_id'   => $sid,
            'author_id'    => $empId,
            'author_name'  => $empName,
            'content'      => $content,
            'mentioned_ids'=> implode(',', array_unique($mentioned)),
            'is_internal'  => intval($request->post('is_internal', 1)),
        ]);
        foreach (array_unique($mentioned) as $mid) {
            if ($mid == $empId) continue;
            Db::insert('kefu_mention', [
                'tenant_id'        => $tenantId,
                'source_type'      => 'session',
                'source_id'        => $id,
                'from_employee_id' => $empId,
                'to_employee_id'   => $mid,
                'content'          => mb_substr($content, 0, 200),
            ]);
        }
        return json(['code' => 0, 'msg' => '已保存', 'data' => [
            'id' => $id, 'mentioned' => $mentioned
        ]]);
    }

    public function noteDelete(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        Db::delete('kefu_session_note', ['id' => $id]);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    // 协作者

    public function collabList(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $sid = trim($request->get('session_id', ''));
        if ($sid === '') return json(['code' => 400, 'msg' => 'session_id 必填']);
        $rows = Db::query(
            "SELECT c.id, c.employee_id, c.role, c.created_at,
                    e.username, e.real_name, e.avatar
             FROM kefu_session_collaborator c
             LEFT JOIN kefu_employee e ON e.id = c.employee_id
             WHERE c.tenant_id = :t AND c.session_id = :s ORDER BY c.id ASC",
            [':t' => $tenantId, ':s' => $sid]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    public function collabAdd(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $sid = trim($request->post('session_id', ''));
        $empId = intval($request->post('employee_id', 0));
        if ($sid === '' || $empId <= 0) return json(['code' => 400, 'msg' => '参数错误']);
        Db::setTenantId($tenantId);
        $exists = Db::value(
            "SELECT COUNT(*) FROM kefu_session_collaborator
             WHERE tenant_id = :t AND session_id = :s AND employee_id = :e",
            [':t' => $tenantId, ':s' => $sid, ':e' => $empId]
        );
        if ($exists > 0) return json(['code' => 400, 'msg' => '已经是协作者']);
        $id = Db::insert('kefu_session_collaborator', [
            'tenant_id'   => $tenantId,
            'session_id'  => $sid,
            'employee_id' => $empId,
            'role'        => $request->post('role', 'collaborator'),
            'added_by'    => intval($request->employee_id ?? 0) ?: null,
        ]);
        $me = intval($request->employee_id ?? 0);
        if ($empId != $me) {
            Db::insert('kefu_mention', [
                'tenant_id'        => $tenantId,
                'source_type'      => 'session',
                'source_id'        => $id,
                'from_employee_id' => $me,
                'to_employee_id'   => $empId,
                'content'          => '邀请你协作会话 ' . $sid,
            ]);
        }
        return json(['code' => 0, 'msg' => '已添加', 'data' => ['id' => $id]]);
    }

    public function collabRemove(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        Db::delete('kefu_session_collaborator', ['id' => $id]);
        return json(['code' => 0, 'msg' => '已移除']);
    }

    // @提醒

    public function myMentions(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $empId = intval($request->employee_id ?? 0);
        $rows = Db::query(
            "SELECT m.id, m.source_type, m.source_id, m.from_employee_id, m.content,
                    m.read_at, m.created_at,
                    e.username AS from_username, e.real_name AS from_real_name
             FROM kefu_mention m
             LEFT JOIN kefu_employee e ON e.id = m.from_employee_id
             WHERE m.tenant_id = :t AND m.to_employee_id = :me
             ORDER BY (m.read_at IS NULL) DESC, m.id DESC LIMIT 50",
            [':t' => $tenantId, ':me' => $empId]
        );
        $unread = 0;
        foreach ($rows as $r) if (!$r['read_at']) $unread++;
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'list' => $rows, 'unread' => $unread
        ]]);
    }

    public function markRead(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $empId = intval($request->employee_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id > 0) {
            Db::exec("UPDATE kefu_mention SET read_at = NOW()
                       WHERE tenant_id = :t AND to_employee_id = :me AND id = :id",
                [':t' => $tenantId, ':me' => $empId, ':id' => $id]);
        } else {
            Db::exec("UPDATE kefu_mention SET read_at = NOW()
                       WHERE tenant_id = :t AND to_employee_id = :me AND read_at IS NULL",
                [':t' => $tenantId, ':me' => $empId]);
        }
        return json(['code' => 0, 'msg' => '已标记已读']);
    }
}