<?php
/**
 * 管理后台 - 标签管理
 * 功能：客户标签与会话标签的统一管理
 * 表：
 *   kefu_customer_tag - 客户标签库
 *   kefu_session_tag - 会话标签
 * 作者：kefu 开发团队
 */
namespace app\controller\admin;

use support\Request;
use app\lib\Db;

class TagController
{
    /**
     * 客户标签列表（按分类分组）
     */
    public function customerList(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        Db::setTenantId($tenantId);
        $rows = Db::query(
            "SELECT id, tag_name, tag_color, category, created_at
             FROM kefu_customer_tag WHERE tenant_id = :t ORDER BY category, id DESC",
            [':t' => $tenantId]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    /**
     * 新建客户标签
     */
    public function customerCreate(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $name = trim($request->post('tag_name', ''));
        if ($name === '') {
            return json(['code' => 400, 'msg' => '标签名必填']);
        }
        Db::setTenantId($tenantId);
        $exists = Db::value(
            "SELECT COUNT(*) FROM kefu_customer_tag WHERE tenant_id = :t AND tag_name = :n",
            [':t' => $tenantId, ':n' => $name]
        );
        if ($exists > 0) {
            return json(['code' => 400, 'msg' => '标签已存在']);
        }
        $id = Db::insert('kefu_customer_tag', [
            'tenant_id' => $tenantId,
            'tag_name'  => $name,
            'tag_color' => trim($request->post('tag_color', '#1890ff')),
            'category'  => trim($request->post('category', 'default')),
        ]);
        return json(['code' => 0, 'msg' => '创建成功', 'data' => ['id' => $id]]);
    }

    /**
     * 删除客户标签（无引用才可删）
     */
    public function customerDelete(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $id = intval($request->post('id', 0));
        if ($id <= 0) {
            return json(['code' => 400, 'msg' => 'id必填']);
        }
        Db::setTenantId($tenantId);

        $used = Db::value(
            "SELECT COUNT(*) FROM kefu_customer_tag_rel WHERE tenant_id = :t AND tag_id = :i",
            [':t' => $tenantId, ':i' => $id]
        );
        if ($used > 0) {
            return json(['code' => 400, 'msg' => "标签已被{$used}个客户使用，请先解除关联"]);
        }
        Db::delete('kefu_customer_tag', ['id' => $id]);
        return json(['code' => 0, 'msg' => '删除成功']);
    }

    /**
     * 给客户打标签
     */
    public function tagCustomer(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $customerId = intval($request->post('customer_id', 0));
        $tagId = intval($request->post('tag_id', 0));
        $empId = intval($request->employee_id ?? 0);
        if ($customerId <= 0 || $tagId <= 0) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        Db::setTenantId($tenantId);

        // 重复检查（unique key 兜底）
        try {
            Db::insert('kefu_customer_tag_rel', [
                'tenant_id'  => $tenantId,
                'customer_id'=> $customerId,
                'tag_id'     => $tagId,
                'tagged_by'  => $empId,
            ]);
        } catch (\Throwable $e) {
            // 已存在视为成功
            return json(['code' => 0, 'msg' => '已存在']);
        }
        return json(['code' => 0, 'msg' => '打标签成功']);
    }

    /**
     * 移除客户标签
     */
    public function untagCustomer(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $customerId = intval($request->post('customer_id', 0));
        $tagId = intval($request->post('tag_id', 0));
        Db::setTenantId($tenantId);
        Db::exec(
            "DELETE FROM kefu_customer_tag_rel WHERE tenant_id = :t AND customer_id = :c AND tag_id = :i",
            [':t' => $tenantId, ':c' => $customerId, ':i' => $tagId]
        );
        return json(['code' => 0, 'msg' => '已移除']);
    }

    /**
     * 客户已绑定的标签列表
     */
    public function customerTags(Request $request)
    {
        $tenantId = intval($request->tenant_id ?? 0);
        $customerId = intval($request->get('customer_id', 0));
        Db::setTenantId($tenantId);
        $rows = Db::query(
            "SELECT t.id, t.tag_name, t.tag_color, t.category, r.tagged_at
             FROM kefu_customer_tag_rel r
             JOIN kefu_customer_tag t ON t.id = r.tag_id
             WHERE r.tenant_id = :t AND r.customer_id = :c
             ORDER BY r.tagged_at DESC",
            [':t' => $tenantId, ':c' => $customerId]
        );
        return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
    }

    /**
     * 会话打标签
     */
    public function sessionTag(Request $request)
    {
        try {
            $tenantId = intval($request->tenant_id ?? 0);
            $sessionId = trim($request->post('session_id', ''));
            $tagName = trim($request->post('tag_name', ''));
            if ($sessionId === '' || $tagName === '') {
                return json(['code' => 400, 'msg' => 'session_id 和 tag_name 必填']);
            }
            Db::setTenantId($tenantId);

            // 先确保标签已存在（kefu_session_tag 作为标签库）
            $tagId = Db::value(
                "SELECT id FROM kefu_session_tag WHERE tenant_id = :t AND tag_name = :n",
                [':t' => $tenantId, ':n' => $tagName]
            );
            if (!$tagId) {
                $tagId = Db::insert('kefu_session_tag', [
                    'tenant_id' => $tenantId,
                    'tag_name'  => $tagName,
                    'tag_color' => '#1890ff',
                ]);
            }
            // 关联：kefu_session_tag_rel
            try {
                Db::insert('kefu_session_tag_rel', [
                    'tenant_id'  => $tenantId,
                    'session_id' => $sessionId,
                    'tag_id'     => $tagId,
                    'tagged_by'  => intval($request->employee_id ?? 0),
                ]);
                return json(['code' => 0, 'msg' => '已标记']);
            } catch (\Throwable $e) {
                // 重复主键视为成功
                if (strpos($e->getMessage(), 'Duplicate') !== false) {
                    return json(['code' => 0, 'msg' => '已存在']);
                }
                throw $e;
            }
        } catch (\Throwable $e) {
            \app\lib\Logger::error('tag/session error: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => 'sessionTag错误: ' . $e->getMessage()]);
        }
    }

    /**
     * 查询会话的所有标签
     */
    public function sessionTags(Request $request)
    {
        try {
            $tenantId = intval($request->tenant_id ?? 0);
            $sessionId = trim($request->get('session_id', ''));
            Db::setTenantId($tenantId);
            $rows = Db::query(
                "SELECT t.id, t.tag_name, t.tag_color, r.tagged_at
                 FROM kefu_session_tag_rel r
                 JOIN kefu_session_tag t ON t.id = r.tag_id
                 WHERE r.tenant_id = :t AND r.session_id = :s
                 ORDER BY r.tagged_at DESC",
                [':t' => $tenantId, ':s' => $sessionId]
            );
            return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $rows]]);
        } catch (\Throwable $e) {
            \app\lib\Logger::error('tag/session/tags error: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => 'sessionTags错误: ' . $e->getMessage()]);
        }
    }
}