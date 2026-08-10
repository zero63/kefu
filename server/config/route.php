<?php
/**
 * API 路由定义（Webman 框架）
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 *
 * 路由分组使用 Route::group + middleware 参数指定中间件。
 *
 * 路由分组：
 *   /api/common/*   公共接口（登录、文件上传、字典）
 *   /api/admin/*    管理后台（AuthMiddleware）
 *   /api/agent/*    客服工作台（AuthMiddleware）
 *   /api/visitor/*  访客端
 *   /api/robot/*    机器人
 */

use Webman\Route;

// ==========================================
// 根路由 —— 直接输出 landing page（避免与静态服务冲突）
// ==========================================
Route::get('/', function (\support\Request $req) {
    $path = __DIR__ . '/../public/index.html';
    if (is_file($path)) {
        $resp = new \support\Response();
        $resp->withHeader('Content-Type', 'text/html; charset=utf-8');
        $resp->withBody(file_get_contents($path));
        return $resp;
    }
    return json(['app' => 'kefu', 'version' => '1.0']);
});

// ==========================================
// 多渠道接入 Webhook（公开，无需鉴权）
// 微信官方要求每个账号独立 URL（每账号独立 webhook）
// 支持两种路径：
//   1. /api/channel/{code}/{account_id}  按账号独立路径（推荐，每个公众号/小程序独立配置）
//   2. /api/channel/{code}/webhook       单一路径（按 app_id 自动匹配账号）
// 优先级：带 account_id 的路径优先匹配
// ==========================================
Route::any('/api/channel/{code}/{account_id}', [app\controller\ChannelWebhookController::class, 'handle']);
Route::any('/api/channel/{code}/webhook', [app\controller\ChannelWebhookController::class, 'handle']);

// 访客端公开接口
Route::post('/api/visitor/leave-message', [app\controller\LeaveMessageController::class, 'submit']);
Route::get('/api/visitor/online-agents', function() {
    return json(['code' => 0, 'msg' => 'ok', 'data' => []]);
});

// 访客自定义字段（公开接口 + 后台管理）
Route::get('/api/visitor/field/list',    [app\controller\VisitorFieldController::class, 'list']);
Route::post('/api/visitor/field/save',   [app\controller\VisitorFieldController::class, 'saveValue']);
Route::get('/api/visitor/field/get',     [app\controller\VisitorFieldController::class, 'getValues']);
Route::get('/api/admin/visitor-field/list',    [app\controller\VisitorFieldController::class, 'adminList']);
Route::post('/api/admin/visitor-field/create', [app\controller\VisitorFieldController::class, 'adminCreate']);
Route::post('/api/admin/visitor-field/update', [app\controller\VisitorFieldController::class, 'adminUpdate']);
Route::post('/api/admin/visitor-field/delete', [app\controller\VisitorFieldController::class, 'adminDelete']);
// 客服读取访客字段值
Route::get('/api/admin/visitor/field/get', [app\controller\VisitorFieldController::class, 'getValues']);
Route::get('/api/agent/visitor/field/get',  [app\controller\VisitorFieldController::class, 'getValues']);

// 访客端业务 API（公开）—— 只暴露新接口，避免与 visitor group 冲突
Route::get('/api/visitor/style/get-public',         [app\controller\VisitorApiController::class, 'getPublicStyle']);
Route::post('/api/visitor/session/ensure',          [app\controller\VisitorApiController::class, 'ensureSession']);
Route::get('/api/visitor/session/get',              [app\controller\VisitorApiController::class, 'getSession']);
Route::get('/api/visitor/message/poll',             [app\controller\VisitorApiController::class, 'poll']);

/**
 * WebSocket 连接信息（前端连接时读这个拿端口）
 */
Route::get('/ws/info', function () {
    return json([
        'code' => 0,
        'msg'  => 'ok',
        'data' => [
            'ws_host'      => config('app.ws_host', '127.0.0.1'),
            'ws_port'      => (int)config('app.ws_port', 8788),
            'agent_path'   => '/ws/agent',
            'visitor_path' => '/ws/visitor',
            'admin_path'   => '/ws/admin',
            'protocol'     => 'JSON over WebSocket',
            'note'         => 'Windows 下 WS 不可用（依赖 workerman fork），生产环境需部署到 Linux',
        ],
    ]);
});

// ==========================================
// 全局 - 健康检查（无中间件）
// ==========================================
Route::get('/api/health', function () {
    return json([
        'status' => 'ok',
        'app'    => 'kefu',
        'time'   => date('Y-m-d H:i:s'),
        'php'    => PHP_VERSION,
    ]);
});

// ==========================================
// /api/common/* —— 公共接口（无需鉴权）
// ==========================================
Route::group('/api/common', function () {
    Route::post('/login', [app\controller\common\LoginController::class, 'login']);
    Route::post('/logout', [app\controller\common\LoginController::class, 'logout']);
    Route::post('/refresh-token', [app\controller\common\LoginController::class, 'refresh']);
    Route::post('/upload', [app\controller\common\UploadController::class, 'upload']);
    Route::get('/dict', [app\controller\common\DictController::class, 'index']);
});

// ==========================================
// /api/admin/* —— 管理后台（需鉴权）
// ==========================================
Route::group('/api/admin', function () {
    // 员工 / 角色
    Route::get('/employee/list', [app\controller\admin\EmployeeController::class, 'list']);
    Route::post('/employee/create', [app\controller\admin\EmployeeController::class, 'create']);
    Route::post('/employee/update', [app\controller\admin\EmployeeController::class, 'update']);
    Route::post('/employee/delete', [app\controller\admin\EmployeeController::class, 'delete']);
    Route::get('/role/list', [app\controller\admin\RoleController::class, 'list']);
    Route::post('/role/assign', [app\controller\admin\RoleController::class, 'assign']);
    Route::post('/role/assign-dept', [app\controller\admin\RoleController::class, 'assignDept']);
    Route::post('/employee/reset-pwd', [app\controller\admin\EmployeeController::class, 'resetPwd']);
    Route::get('/employee/online-status', [app\controller\admin\EmployeeController::class, 'onlineStatus']);

    // 留言管理
    Route::get('/leave-message/list',     [app\controller\LeaveMessageController::class, 'list']);
    Route::get('/leave-message/detail',   [app\controller\LeaveMessageController::class, 'detail']);
    Route::post('/leave-message/reply',   [app\controller\LeaveMessageController::class, 'reply']);
    Route::post('/leave-message/update',  [app\controller\LeaveMessageController::class, 'updateStatus']);
    Route::post('/leave-message/status',  [app\controller\LeaveMessageController::class, 'updateStatus']);  // 兼容 agent/leave-msg.html 的别名
    Route::post('/leave-message/delete',  [app\controller\LeaveMessageController::class, 'delete']);
    Route::get('/leave-message/stats',    [app\controller\LeaveMessageController::class, 'stats']);

    // 权限管理
    Route::get('/permission/tree',                 [app\controller\admin\PermissionController::class, 'tree']);
    Route::get('/permission/role-permissions',     [app\controller\admin\PermissionController::class, 'getRolePermissions']);
    Route::post('/permission/save-role-permissions',[app\controller\admin\PermissionController::class, 'saveRolePermissions']);
    Route::post('/permission/save',                [app\controller\admin\PermissionController::class, 'save']);
    Route::post('/permission/delete',              [app\controller\admin\PermissionController::class, 'delete']);
    Route::get('/permission/my-permissions',       [app\controller\admin\PermissionController::class, 'myPermissions']);
    Route::get('/permission/my-menu',              [app\controller\admin\PermissionController::class, 'myMenu']);
    Route::get('/dept/list',     [app\controller\admin\DeptController::class, 'list']);
    Route::get('/dept/tree',     [app\controller\admin\DeptController::class, 'tree']);
    Route::post('/dept/create',  [app\controller\admin\DeptController::class, 'create']);
    Route::post('/dept/update',  [app\controller\admin\DeptController::class, 'update']);
    Route::post('/dept/delete',  [app\controller\admin\DeptController::class, 'delete']);



    // 企业（租户）设置
    Route::get('/tenant/info',          [app\controller\admin\TenantController::class, 'info']);
    Route::post('/tenant/update',       [app\controller\admin\TenantController::class, 'update']);
    Route::get('/tenant/default-config',[app\controller\admin\TenantController::class, 'defaultConfig']);
    Route::post('/tenant/save-config',  [app\controller\admin\TenantController::class, 'saveConfig']);

    // 定时任务手动触发（Windows 演示环境）
    Route::post('/cron/run',            [app\controller\admin\CronController::class, 'run']);
    Route::post('/cron/close-expired',  [app\controller\admin\CronController::class, 'closeExpired']);

    // 操作日志
    Route::get('/op-log/list',  [app\controller\admin\OperationLogController::class, 'list']);
    Route::post('/op-log/delete',[app\controller\admin\OperationLogController::class, 'delete']);
    Route::post('/op-log/clear', [app\controller\admin\OperationLogController::class, 'clear']);

    // 站内信箱
    Route::get('/msg/inbox',         [app\controller\admin\InternalMsgController::class, 'inbox']);
    Route::get('/msg/unread-count',  [app\controller\admin\InternalMsgController::class, 'unreadCount']);
    Route::post('/msg/send',         [app\controller\admin\InternalMsgController::class, 'send']);
    Route::post('/msg/read',         [app\controller\admin\InternalMsgController::class, 'read']);
    Route::post('/msg/read-all',     [app\controller\admin\InternalMsgController::class, 'readAll']);
    Route::post('/msg/delete',       [app\controller\admin\InternalMsgController::class, 'delete']);

    // 标签管理
    Route::get('/tag/customer/list',          [app\controller\admin\TagController::class, 'customerList']);
    Route::post('/tag/customer/create',       [app\controller\admin\TagController::class, 'customerCreate']);
    Route::post('/tag/customer/delete',       [app\controller\admin\TagController::class, 'customerDelete']);
    Route::post('/tag/customer/tag',          [app\controller\admin\TagController::class, 'tagCustomer']);
    Route::post('/tag/customer/untag',        [app\controller\admin\TagController::class, 'untagCustomer']);
    Route::get('/tag/customer/tags',          [app\controller\admin\TagController::class, 'customerTags']);
    Route::post('/tag/session/tag',           [app\controller\admin\TagController::class, 'sessionTag']);
    Route::get('/tag/session/tags',           [app\controller\admin\TagController::class, 'sessionTags']);

    // 工单系统
    Route::get('/worksheet/list',    [app\controller\admin\WorksheetController::class, 'list']);
    Route::get('/worksheet/detail',  [app\controller\admin\WorksheetController::class, 'detail']);
    Route::post('/worksheet/create', [app\controller\admin\WorksheetController::class, 'create']);
    Route::post('/worksheet/update', [app\controller\admin\WorksheetController::class, 'update']);
    Route::post('/worksheet/reply',  [app\controller\admin\WorksheetController::class, 'reply']);
    Route::post('/worksheet/close',  [app\controller\admin\WorksheetController::class, 'close']);
    Route::post('/worksheet/reopen', [app\controller\admin\WorksheetController::class, 'reopen']);
    Route::post('/worksheet/delete', [app\controller\admin\WorksheetController::class, 'delete']);
    Route::get('/worksheet/stats',   [app\controller\admin\WorksheetController::class, 'stats']);

    // 黑名单
    Route::get('/blacklist/list',   [app\controller\admin\BlacklistController::class, 'list']);
    Route::post('/blacklist/add',   [app\controller\admin\BlacklistController::class, 'add']);
    Route::post('/blacklist/remove',[app\controller\admin\BlacklistController::class, 'remove']);
    Route::get('/blacklist/check',  [app\controller\admin\BlacklistController::class, 'check']);

    // === 客服工作台自定义 ===
    Route::get('/workbench/get',           [app\controller\admin\WorkbenchController::class, 'get']);
    Route::post('/workbench/save',         [app\controller\admin\WorkbenchController::class, 'save']);
    Route::post('/workbench/reset',        [app\controller\admin\WorkbenchController::class, 'reset']);
    Route::get('/workbench/options',       [app\controller\admin\WorkbenchController::class, 'options']);

    // === 访客端样式自定义 ===
    Route::get('/visitor-style/get',       [app\controller\admin\VisitorStyleController::class, 'get']);
    Route::post('/visitor-style/save',     [app\controller\admin\VisitorStyleController::class, 'save']);
    Route::post('/visitor-style/reset',    [app\controller\admin\VisitorStyleController::class, 'reset']);
    Route::get('/visitor-style/preview',   [app\controller\admin\VisitorStyleController::class, 'preview']);

    // === 多渠道接入（在线接入 / APP 接入 / 微信接入）===
    Route::get('/channel-mgmt/list',            [app\controller\admin\ChannelController::class, 'list']);
    Route::get('/channel-mgmt/detail',          [app\controller\admin\ChannelController::class, 'detail']);
    Route::post('/channel-mgmt/toggle',         [app\controller\admin\ChannelController::class, 'toggle']);
    Route::post('/channel-mgmt/save-account',   [app\controller\admin\ChannelController::class, 'saveAccount']);
    Route::post('/channel-mgmt/delete-account', [app\controller\admin\ChannelController::class, 'deleteAccount']);
    Route::post('/channel-mgmt/verify-account', [app\controller\admin\ChannelController::class, 'verifyAccount']);
    Route::post('/channel-mgmt/rotate-secret',  [app\controller\admin\ChannelController::class, 'rotateSecret']);

    // === 微信客服账号管理（kfaccount add/update/del/list/online）===
    Route::get('/channel-mgmt/kfaccount/list',    [app\controller\admin\ChannelController::class, 'kfAccountList']);
    Route::post('/channel-mgmt/kfaccount/add',    [app\controller\admin\ChannelController::class, 'kfAccountAdd']);
    Route::post('/channel-mgmt/kfaccount/del',    [app\controller\admin\ChannelController::class, 'kfAccountDel']);
    Route::post('/channel-mgmt/kfaccount/update', [app\controller\admin\ChannelController::class, 'kfAccountUpdate']);

    // === 微信主动发送客服消息（cgi-bin/message/custom/send）===
    Route::post('/channel-mgmt/custom/send', [app\controller\admin\ChannelController::class, 'customSend']);

    // === 微信客服会话管理（kfsession create/close/getsession/getwaitcase）===
    Route::post('/channel-mgmt/kfsession/create',   [app\controller\admin\ChannelController::class, 'kfSessionCreate']);
    Route::post('/channel-mgmt/kfsession/close',    [app\controller\admin\ChannelController::class, 'kfSessionClose']);
    Route::get('/channel-mgmt/kfsession/get',       [app\controller\admin\ChannelController::class, 'kfSessionGet']);
    Route::get('/channel-mgmt/kfsession/waitcase',  [app\controller\admin\ChannelController::class, 'kfSessionWaitcase']);

    // === 微信聊天记录（customservice/msgrecord/getmsglist）===
    Route::get('/channel-mgmt/msgrecord/list', [app\controller\admin\ChannelController::class, 'msgRecordList']);

    // === access_token 手动刷新 ===
    Route::get('/channel-mgmt/access-token/refresh', [app\controller\admin\ChannelController::class, 'refreshAccessToken']);

    // === 小程序微信客服（新版 2025）===
    Route::get('/channel-mgmt/kfwork/get',    [app\controller\admin\ChannelController::class, 'kfWorkGetStatus']);
    Route::post('/channel-mgmt/kfwork/bind',   [app\controller\admin\ChannelController::class, 'kfWorkBind']);
    Route::post('/channel-mgmt/kfwork/unbind', [app\controller\admin\ChannelController::class, 'kfWorkUnbind']);

    // === 故障诊断 ===
    Route::get('/diagnostics/items',       [app\controller\admin\DiagnosticsController::class, 'items']);
    Route::get('/diagnostics/check',       [app\controller\admin\DiagnosticsController::class, 'check']);
    Route::get('/diagnostics/run-all',     [app\controller\admin\DiagnosticsController::class, 'runAll']);
    Route::get('/diagnostics/logs',        [app\controller\admin\DiagnosticsController::class, 'logs']);
    Route::get('/diagnostics/suggest',     [app\controller\admin\DiagnosticsController::class, 'suggest']);

    // === v3 客户管理 ===
    Route::get('/customer-mgmt/list',       [app\controller\admin\CustomerMgmtController::class, 'list']);
    Route::get('/customer-mgmt/detail',     [app\controller\admin\CustomerMgmtController::class, 'detail']);
    Route::post('/customer-mgmt/update',    [app\controller\admin\CustomerMgmtController::class, 'update']);
    Route::post('/customer-mgmt/set-vip',   [app\controller\admin\CustomerMgmtController::class, 'setVip']);
    Route::get('/customer-mgmt/stats',      [app\controller\admin\CustomerMgmtController::class, 'stats']);
    // 分组
    Route::get('/customer-group/list',      [app\controller\admin\CustomerMgmtController::class, 'groupList']);
    Route::post('/customer-group/create',   [app\controller\admin\CustomerMgmtController::class, 'groupCreate']);
    Route::post('/customer-group/delete',   [app\controller\admin\CustomerMgmtController::class, 'groupDelete']);
    // 留言
    Route::get('/leave-msg/list',           [app\controller\admin\CustomerMgmtController::class, 'leaveList']);
    Route::post('/leave-msg/assign',        [app\controller\admin\CustomerMgmtController::class, 'leaveAssign']);
    Route::post('/leave-msg/reply',         [app\controller\admin\CustomerMgmtController::class, 'leaveReply']);
    // 修复：新增留言状态更新（标记已处理 / 关闭 / 垃圾）
    Route::post('/leave-msg/update',        [app\controller\admin\CustomerMgmtController::class, 'leaveUpdate']);

    // === v3 第三方集成 ===
    Route::get('/integration/get',          [app\controller\admin\IntegrationController::class, 'get']);
    Route::post('/integration/save',        [app\controller\admin\IntegrationController::class, 'save']);
    // Webhook
    Route::get('/webhook/list',             [app\controller\admin\IntegrationController::class, 'webhookList']);
    Route::post('/webhook/create',          [app\controller\admin\IntegrationController::class, 'webhookCreate']);
    Route::post('/webhook/delete',          [app\controller\admin\IntegrationController::class, 'webhookDelete']);
    Route::post('/webhook/toggle',          [app\controller\admin\IntegrationController::class, 'webhookToggle']);
    Route::get('/webhook/log',              [app\controller\admin\IntegrationController::class, 'webhookLog']);
    Route::post('/webhook/test',            [app\controller\admin\IntegrationController::class, 'webhookTest']);
    // 网页 Widget
    Route::get('/web-widget/list',          [app\controller\admin\IntegrationController::class, 'widgetList']);
    Route::post('/web-widget/create',       [app\controller\admin\IntegrationController::class, 'widgetCreate']);
    Route::post('/web-widget/delete',       [app\controller\admin\IntegrationController::class, 'widgetDelete']);

    // === v3 工单增强 ===
    Route::get('/worksheet/process/list',    [app\controller\admin\WorksheetController::class, 'processList']);
    Route::post('/worksheet/process/create', [app\controller\admin\WorksheetController::class, 'processCreate']);
    Route::post('/worksheet/process/delete', [app\controller\admin\WorksheetController::class, 'processDelete']);
    Route::get('/worksheet/category/list',   [app\controller\admin\WorksheetController::class, 'categoryList']);
    Route::post('/worksheet/category/create',[app\controller\admin\WorksheetController::class, 'categoryCreate']);
    Route::post('/worksheet/category/delete',[app\controller\admin\WorksheetController::class, 'categoryDelete']);
    Route::get('/worksheet/sla-report',      [app\controller\admin\WorksheetController::class, 'slaReport']);

    // === v2 新增功能 ===
    // 多渠道接入（迁移到 /channel-mgmt/*，旧路由保留兼容）

    // 客户轨迹 + 自定义字段
    Route::get('/customer-track/list',         [app\controller\admin\CustomerTrackController::class, 'list']);
    Route::get('/customer-track/timeline',     [app\controller\admin\CustomerTrackController::class, 'timeline']);
    Route::post('/customer-track/record',      [app\controller\admin\CustomerTrackController::class, 'record']);
    Route::get('/customer-track/field/list',   [app\controller\admin\CustomerTrackController::class, 'fieldList']);
    Route::post('/customer-track/field/create',[app\controller\admin\CustomerTrackController::class, 'fieldCreate']);
    Route::post('/customer-track/field/delete',[app\controller\admin\CustomerTrackController::class, 'fieldDelete']);
    Route::get('/customer-track/values',       [app\controller\admin\CustomerTrackController::class, 'values']);
    Route::post('/customer-track/save-values', [app\controller\admin\CustomerTrackController::class, 'saveValues']);

    // 营销活动
    Route::get('/campaign/list',     [app\controller\admin\CampaignController::class, 'list']);
    Route::post('/campaign/create',  [app\controller\admin\CampaignController::class, 'create']);
    Route::post('/campaign/launch',  [app\controller\admin\CampaignController::class, 'launch']);
    Route::post('/campaign/delete',  [app\controller\admin\CampaignController::class, 'delete']);
    Route::get('/campaign/stats',    [app\controller\admin\CampaignController::class, 'stats']);
    // 满意度调研
    Route::get('/survey/list',       [app\controller\admin\CampaignController::class, 'surveyList']);
    Route::post('/survey/create',    [app\controller\admin\CampaignController::class, 'surveyCreate']);
    Route::post('/survey/publish',   [app\controller\admin\CampaignController::class, 'surveyPublish']);
    Route::post('/survey/close',     [app\controller\admin\CampaignController::class, 'surveyClose']);

    // 工单模板 + 自定义字段
    Route::get('/worksheet/template/list',   [app\controller\admin\WorksheetController::class, 'templateList']);
    Route::post('/worksheet/template/create',[app\controller\admin\WorksheetController::class, 'templateCreate']);
    Route::post('/worksheet/template/delete',[app\controller\admin\WorksheetController::class, 'templateDelete']);
    Route::get('/worksheet/field/list',      [app\controller\admin\WorksheetController::class, 'fieldList']);
    Route::post('/worksheet/field/create',   [app\controller\admin\WorksheetController::class, 'fieldCreate']);
    Route::post('/worksheet/field/delete',   [app\controller\admin\WorksheetController::class, 'fieldDelete']);

    // 知识库 + 意图
    Route::get('/kb/category/list',           [app\controller\admin\KnowledgeController::class, 'categoryList']);
    Route::post('/kb/category/create',        [app\controller\admin\KnowledgeController::class, 'categoryCreate']);
    Route::post('/kb/category/delete',        [app\controller\admin\KnowledgeController::class, 'categoryDelete']);
    Route::get('/kb/list',                    [app\controller\admin\KnowledgeController::class, 'list']);
    Route::post('/kb/create',                 [app\controller\admin\KnowledgeController::class, 'create']);
    Route::post('/kb/update',                 [app\controller\admin\KnowledgeController::class, 'update']);
    Route::post('/kb/delete',                 [app\controller\admin\KnowledgeController::class, 'delete']);
    Route::post('/kb/test',                   [app\controller\admin\KnowledgeController::class, 'test']);
    Route::get('/kb/stats',                   [app\controller\admin\KnowledgeController::class, 'stats']);
    Route::get('/kb/intent/list',             [app\controller\admin\KnowledgeController::class, 'intentList']);
    Route::post('/kb/intent/create',          [app\controller\admin\KnowledgeController::class, 'intentCreate']);
    Route::post('/kb/intent/delete',          [app\controller\admin\KnowledgeController::class, 'intentDelete']);

    // 漏斗 + 会话来源
    Route::get('/funnel/list',          [app\controller\admin\FunnelController::class, 'list']);
    Route::post('/funnel/create',       [app\controller\admin\FunnelController::class, 'create']);
    Route::post('/funnel/delete',       [app\controller\admin\FunnelController::class, 'delete']);
    Route::get('/funnel/analyze',       [app\controller\admin\FunnelController::class, 'analyze']);
    Route::get('/funnel/source-stats',  [app\controller\admin\FunnelController::class, 'sourceStats']);
    Route::post('/funnel/set-source',   [app\controller\admin\FunnelController::class, 'setSource']);

    // 团队协作
    Route::get('/collab/notes',           [app\controller\admin\CollaborationController::class, 'noteList']);
    Route::post('/collab/note/create',    [app\controller\admin\CollaborationController::class, 'noteCreate']);
    Route::post('/collab/note/delete',    [app\controller\admin\CollaborationController::class, 'noteDelete']);
    Route::get('/collab/list',            [app\controller\admin\CollaborationController::class, 'collabList']);
    Route::post('/collab/add',            [app\controller\admin\CollaborationController::class, 'collabAdd']);
    Route::post('/collab/remove',         [app\controller\admin\CollaborationController::class, 'collabRemove']);
    Route::get('/collab/mentions',        [app\controller\admin\CollaborationController::class, 'myMentions']);
    Route::post('/collab/mark-read',      [app\controller\admin\CollaborationController::class, 'markRead']);

    // 评价 / 满意度
    Route::get('/evaluate/stats',     [app\controller\admin\EvaluateController::class, 'stats']);
    Route::get('/evaluate/list',      [app\controller\admin\EvaluateController::class, 'list']);

    // 质检
    Route::post('/quality/inspect',         [app\controller\admin\QualityController::class, 'inspect']);
    Route::get('/quality/result/list',      [app\controller\admin\QualityController::class, 'resultList']);
    Route::get('/quality/stats',            [app\controller\admin\QualityController::class, 'stats']);
    Route::get('/quality/rule/list',        [app\controller\admin\QualityController::class, 'ruleList']);
    Route::post('/quality/rule/add',        [app\controller\admin\QualityController::class, 'ruleAdd']);
    Route::post('/quality/rule/update',     [app\controller\admin\QualityController::class, 'ruleUpdate']);

    // 敏感词管理
    Route::get('/sensitive/list',    [app\controller\admin\SensitiveWordController::class, 'list']);
    Route::post('/sensitive/add',     [app\controller\admin\SensitiveWordController::class, 'add']);
    Route::post('/sensitive/delete',  [app\controller\admin\SensitiveWordController::class, 'delete']);
    Route::post('/sensitive/test',    [app\controller\admin\SensitiveWordController::class, 'test']);
    Route::post('/sensitive/clear',   [app\controller\admin\SensitiveWordController::class, 'clearCache']);

    // AI Agent 配置（千帆智能体）
    Route::get('/ai/config',     [app\controller\admin\AiConfigController::class, 'get']);
    Route::post('/ai/config/save', [app\controller\admin\AiConfigController::class, 'save']);
    Route::post('/ai/test',       [app\controller\admin\AiConfigController::class, 'test']);

    // 报表
    Route::get('/report/overview',          [app\controller\admin\StatisticsController::class, 'overview']);
    Route::get('/report/agent-rank',        [app\controller\admin\StatisticsController::class, 'agentRank']);
    Route::get('/report/trend/{metric}',    [app\controller\admin\StatisticsController::class, 'trend']);
    Route::get('/report/hourly',            [app\controller\admin\StatisticsController::class, 'hourly']);
    Route::get('/report/daily-volume',      [app\controller\admin\StatisticsController::class, 'dailyVolume']);
    Route::get('/report/channel',           [app\controller\admin\StatisticsController::class, 'channel']);
    Route::post('/report/daily',            [app\controller\admin\StatisticsController::class, 'generateDaily']);
    Route::get('/report/daily/list',        [app\controller\admin\StatisticsController::class, 'dailyList']);
    Route::post('/report/custom/save',      [app\controller\admin\StatisticsController::class, 'customSave']);
    Route::get('/report/custom/list',       [app\controller\admin\StatisticsController::class, 'customList']);
    // 修复：自定义报表 - 立即生成 / 删除
    Route::post('/report/custom/run',       [app\controller\admin\StatisticsController::class, 'customRun']);
    Route::post('/report/custom/delete',    [app\controller\admin\StatisticsController::class, 'customDelete']);
})->middleware([
    app\middleware\AuthMiddleware::class,
    app\middleware\TenantMiddleware::class,
]);

// 访客端样式：公开读（客服工作台读取用于气泡颜色等）
Route::get('/api/visitor-style/get', [app\controller\admin\VisitorStyleController::class, 'publicGet']);

// ==========================================
// /api/evaluate/* —— 评价提交（公开接口，无需 auth，访客/H5调用）
// ==========================================
Route::post('/api/evaluate/session', [app\controller\admin\EvaluateController::class, 'session']);
Route::post('/api/evaluate/ticket',  [app\controller\admin\EvaluateController::class, 'ticket']);

// v2: 调研提交（公开）
Route::post('/api/survey/submit', [app\controller\admin\CampaignController::class, 'surveySubmit']);

// ==========================================
// /api/ticket/* —— 工单管理（需鉴权）
// ==========================================
Route::group('/api/ticket', function () {
    Route::post('/create',        [app\controller\ticket\TicketController::class, 'create']);
    Route::post('/from-session',  [app\controller\ticket\TicketController::class, 'fromSession']);
    Route::get('/list',           [app\controller\ticket\TicketController::class, 'list']);
    Route::get('/detail',         [app\controller\ticket\TicketController::class, 'detail']);
    Route::post('/assign',        [app\controller\ticket\TicketController::class, 'assign']);
    Route::post('/reply',         [app\controller\ticket\TicketController::class, 'reply']);
    Route::post('/resolve',       [app\controller\ticket\TicketController::class, 'resolve']);
    Route::post('/close',         [app\controller\ticket\TicketController::class, 'close']);
    Route::post('/reopen',        [app\controller\ticket\TicketController::class, 'reopen']);
})->middleware([
    app\middleware\AuthMiddleware::class,
    app\middleware\TenantMiddleware::class,
]);

// ==========================================
// /api/agent/* —— 客服工作台（需鉴权）
// ==========================================
Route::group('/api/agent', function () {
    // 会话管理
    Route::get('/session/list', [app\controller\agent\SessionController::class, 'list']);
    Route::post('/session/assign', [app\controller\agent\SessionController::class, 'assign']);
    Route::post('/session/transfer', [app\controller\agent\SessionController::class, 'transfer']);
    Route::post('/session/close', [app\controller\agent\SessionController::class, 'close']);
    // 修复：可转接客服列表（agent 工作台转接弹窗用）
    Route::get('/peers/online', [app\controller\agent\SessionController::class, 'onlinePeers']);
    // 消息管理
    Route::post('/message/send', [app\controller\agent\MessageController::class, 'send']);
    Route::get('/message/history', [app\controller\agent\MessageController::class, 'history']);
    // 工作台状态
    Route::post('/status/switch', [app\controller\agent\StatusController::class, 'switch']);
    // 客户管理
    Route::get('/customer/list',   [app\controller\agent\CustomerController::class, 'list']);
    Route::get('/customer/tags',   [app\controller\agent\CustomerController::class, 'tags']);
    Route::get('/customer/detail', [app\controller\agent\CustomerController::class, 'detail']);
    // 数据看板
    Route::get('/dashboard/realtime', [app\controller\agent\DashboardController::class, 'realtime']);
    // 快捷回复管理
    Route::get('/quick-reply/list',   [app\controller\agent\QuickReplyController::class, 'list']);
    Route::post('/quick-reply/create',[app\controller\agent\QuickReplyController::class, 'create']);
    Route::post('/quick-reply/update',[app\controller\agent\QuickReplyController::class, 'update']);
    Route::post('/quick-reply/delete',[app\controller\agent\QuickReplyController::class, 'delete']);
    // 留言查看
    Route::get('/leave-msg/list',     [app\controller\visitor\LeaveMsgController::class, 'list']);
    // 服务小记
    Route::post('/service-note/save', [app\controller\agent\ServiceNoteController::class, 'save']);
    Route::get('/service-note/get',   [app\controller\agent\ServiceNoteController::class, 'get']);
    // 个人资料
    Route::get('/profile',             [app\controller\agent\ProfileController::class, 'index']);
    Route::post('/profile/update',     [app\controller\agent\ProfileController::class, 'update']);
    Route::post('/profile/avatar',     [app\controller\agent\ProfileController::class, 'uploadAvatar']);
    // 历史会话列表（被超时关闭的可重新接管）
    Route::get('/history/sessions',    [app\controller\agent\SessionController::class, 'historyList']);
    Route::post('/history/reopen',     [app\controller\agent\SessionController::class, 'reopen']);
})->middleware([
    app\middleware\AuthMiddleware::class,
    app\middleware\TenantMiddleware::class,
    app\middleware\SensitiveFilterMiddleware::class,
]);

// ==========================================
// /api/poll/* —— HTTP 长轮询（替代 WS，单进程兼容 Windows）
// ==========================================
Route::get('/api/poll/agent', [app\controller\poll\PollController::class, 'agent']);
Route::get('/api/poll/visitor', [app\controller\poll\PollController::class, 'visitor']);
Route::get('/api/poll/role/{role}', [app\controller\poll\PollController::class, 'role']);

// ==========================================
// /api/visitor/* —— 访客端
// ==========================================
Route::group('/api/visitor', function () {
    // 访客认证
    Route::post('/auth/weapp', [app\controller\visitor\AuthController::class, 'weapp']);
    Route::post('/auth/h5', [app\controller\visitor\AuthController::class, 'h5']);
    // 发送消息
    Route::post('/message/send', [app\controller\visitor\MessageController::class, 'send']);
    // 主动请求转人工
    Route::post('/message/handoff', [app\controller\visitor\MessageController::class, 'handoff']);
    // 拉消息历史
    Route::get('/message/history', [app\controller\visitor\MessageController::class, 'history']);
    // 会话状态（含 serving_mode）
    Route::get('/message/status', [app\controller\visitor\MessageController::class, 'status']);
    // 上下文
    Route::post('/context/update', [app\controller\visitor\ContextController::class, 'update']);
    // 行为埋点
    Route::post('/track', [app\controller\visitor\TrackController::class, 'record']);
    // 自定义事件
    Route::post('/event', [app\controller\visitor\EventController::class, 'record']);
    // 留言提交
    Route::post('/leave-msg/submit', [app\controller\visitor\LeaveMsgController::class, 'submit']);
    // v2: 客户访问轨迹
    Route::post('/customer-track/record', [app\controller\admin\CustomerTrackController::class, 'record']);
})->middleware([
    app\middleware\RateLimitMiddleware::class,
    app\middleware\SensitiveFilterMiddleware::class,
]);

// ==========================================
// /api/robot/* —— 机器人问答（公开，访客端调用）
// ==========================================
Route::post('/api/robot/answer', [app\controller\robot\AnswerController::class, 'answer']);

// /api/robot/knowledge/* 和 statistics —— 需鉴权
// ==========================================
Route::group('/api/robot', function () {
    // 知识库
    Route::get('/knowledge/list', [app\controller\robot\KnowledgeController::class, 'list']);
    Route::post('/knowledge/create', [app\controller\robot\KnowledgeController::class, 'create']);
    Route::post('/knowledge/update', [app\controller\robot\KnowledgeController::class, 'update']);
    Route::post('/knowledge/delete', [app\controller\robot\KnowledgeController::class, 'delete']);
    // 统计
    Route::get('/statistics/overview', [app\controller\robot\StatisticsController::class, 'overview']);
})->middleware([
    app\middleware\AuthMiddleware::class,
    app\middleware\TenantMiddleware::class,
]);