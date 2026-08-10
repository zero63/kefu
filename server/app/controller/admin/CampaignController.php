<?php
/**
 * 营销主动触达（Push / 群发 / 调研）
 * 作者：kefu 开发团队
 * 创建时间：2026-08-01
 */
namespace app\controller\admin;

use support\Request;
use app\lib\Db;

class CampaignController
{
    /**
     * 活动列表
     */
    public function list(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $page = max(1, intval($request->get('page', 1)));
        $size = 20;
        $offset = ($page - 1) * $size;
        $where = 'WHERE tenant_id = :t';
        $bind = [':t' => $tenantId];
        if ($tp = trim($request->get('type', ''))) {
            $where .= ' AND type = :tp';
            $bind[':tp'] = $tp;
        }
        $total = Db::value("SELECT COUNT(*) FROM kefu_campaign $where", $bind);
        $bind[':limit'] = $size;
        $bind[':offset'] = $offset;
        $rows = Db::query(
            "SELECT id, name, type, status, target_json, schedule_at,
                    sent_count, opened_count, replied_count, created_at
             FROM kefu_campaign $where ORDER BY id DESC LIMIT :limit OFFSET :offset",
            $bind
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'list' => $rows, 'total' => (int)$total, 'page' => $page, 'size' => $size
        ]]);
    }

    /**
     * 创建活动
     */
    public function create(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $name = trim($request->post('name', ''));
        $type = trim($request->post('type', 'push'));
        if ($name === '') return json(['code' => 400, 'msg' => '活动名称必填']);
        if (!in_array($type, ['push', 'mass', 'survey'])) {
            return json(['code' => 400, 'msg' => 'type 必须是 push/mass/survey']);
        }
        Db::setTenantId($tenantId);
        $id = Db::insert('kefu_campaign', [
            'tenant_id'    => $tenantId,
            'name'         => $name,
            'type'         => $type,
            'status'       => 'draft',
            'target_json'  => json_encode($request->post('target', []), JSON_UNESCAPED_UNICODE),
            'content_json' => json_encode($request->post('content', []), JSON_UNESCAPED_UNICODE),
            'schedule_at'  => $request->post('schedule_at', '') ?: null,
            'created_by'   => intval($request->employee_id ?? 0) ?: null,
        ]);
        return json(['code' => 0, 'msg' => '已创建', 'data' => ['id' => $id]]);
    }

    /**
     * 启动活动（修改状态为 running / scheduled）
     */
    public function launch(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        $row = Db::find("SELECT schedule_at, status FROM kefu_campaign WHERE id = :id",
            [':id' => $id]);
        if (!$row) return json(['code' => 404, 'msg' => '活动不存在']);
        $newStatus = $row['schedule_at'] && strtotime($row['schedule_at']) > time()
            ? 'scheduled' : 'running';
        Db::update('kefu_campaign', ['status' => $newStatus], ['id' => $id]);
        return json(['code' => 0, 'msg' => '已启动', 'data' => ['status' => $newStatus]]);
    }

    /**
     * 删除活动
     */
    public function delete(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        Db::delete('kefu_campaign_log', ['campaign_id' => $id]);
        Db::delete('kefu_campaign', ['id' => $id]);
        return json(['code' => 0, 'msg' => '已删除']);
    }

    /**
     * 活动统计
     */
    public function stats(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $rows = Db::query(
            "SELECT type,
                    COUNT(*) AS total,
                    SUM(status='done') AS done,
                    SUM(status='running') AS running,
                    COALESCE(SUM(sent_count), 0) AS sent,
                    COALESCE(SUM(opened_count), 0) AS opened
             FROM kefu_campaign WHERE tenant_id = :t GROUP BY type",
            [':t' => $tenantId]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => $rows]);
    }

    // ====== 满意度调研 ======

    /**
     * 调研列表
     */
    public function surveyList(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $rows = Db::query(
            "SELECT id, title, description, status, target_count, response_count, created_at, published_at
             FROM kefu_survey WHERE tenant_id = :t ORDER BY id DESC",
            [':t' => $tenantId]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    /**
     * 创建调研
     */
    public function surveyCreate(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $title = trim($request->post('title', ''));
        $questions = $request->post('questions', []);
        if ($title === '' || empty($questions)) {
            return json(['code' => 400, 'msg' => '标题和问题必填']);
        }
        Db::setTenantId($tenantId);
        $id = Db::insert('kefu_survey', [
            'tenant_id'      => $tenantId,
            'title'          => $title,
            'description'    => trim($request->post('description', '')),
            'questions_json' => json_encode($questions, JSON_UNESCAPED_UNICODE),
            'status'         => 'draft',
            'created_by'     => intval($request->employee_id ?? 0) ?: null,
        ]);
        return json(['code' => 0, 'msg' => '已创建', 'data' => ['id' => $id]]);
    }

    /**
     * 发布调研
     */
    public function surveyPublish(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        Db::update('kefu_survey',
            ['status' => 'published', 'published_at' => date('Y-m-d H:i:s')],
            ['id' => $id]
        );
        return json(['code' => 0, 'msg' => '已发布']);
    }

    /**
     * 关闭调研
     */
    public function surveyClose(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) return json(['code' => 400, 'msg' => 'id必填']);
        Db::setTenantId($tenantId);
        Db::update('kefu_survey', ['status' => 'closed'], ['id' => $id]);
        return json(['code' => 0, 'msg' => '已关闭']);
    }

    /**
     * 客户提交调研答案（公开接口，无需 auth）
     */
    public function surveySubmit(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $sid = intval($request->post('survey_id', 0));
        $answers = $request->post('answers', []);
        if ($sid <= 0 || empty($answers)) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        Db::setTenantId($tenantId);
        $sv = Db::find("SELECT status FROM kefu_survey WHERE id = :id", [':id' => $sid]);
        if (!$sv || $sv['status'] !== 'published') {
            return json(['code' => 400, 'msg' => '调研未发布']);
        }
        $score = 0;
        foreach ($answers as $a) if (isset($a['score'])) $score += intval($a['score']);
        Db::insert('kefu_survey_response', [
            'tenant_id'   => $tenantId,
            'survey_id'   => $sid,
            'customer_id' => trim($request->post('customer_id', '')),
            'answers_json'=> json_encode($answers, JSON_UNESCAPED_UNICODE),
            'score'       => $score,
        ]);
        Db::exec("UPDATE kefu_survey SET response_count = response_count + 1 WHERE id = :id",
            [':id' => $sid]);
        return json(['code' => 0, 'msg' => '感谢您的反馈']);
    }
}