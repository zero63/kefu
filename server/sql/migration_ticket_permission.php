<?php
/**
 * 工单系统权限补齐
 * - 新增工单系统（worksheet）一级菜单
 * - 新增工单相关二级菜单和按钮权限
 * - 给 admin / supervisor / agent 分配默认权限
 */
require __DIR__ . '/../vendor/autoload.php';

$dsn = 'mysql:host=127.0.0.1;dbname=kefu;charset=utf8mb4';
$pdo = new PDO($dsn, 'kefu', 'adminkefu');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1. 检查一级菜单"worksheet"是否存在
$row = $pdo->query("SELECT id FROM kefu_permission WHERE permission_code='worksheet' AND parent_id=0")->fetch();
if (!$row) {
    // 找到当前最大 sort，放到后面
    $maxSort = (int)$pdo->query("SELECT IFNULL(MAX(sort), 0) FROM kefu_permission WHERE parent_id=0")->fetchColumn();
    $pdo->prepare("INSERT INTO kefu_permission (parent_id, permission_code, permission_name, type, path, sort)
                   VALUES (0, 'worksheet', '工单系统', 'menu', '/admin/worksheets', ?)")
        ->execute([$maxSort + 1]);
    echo "新增一级菜单 worksheet\n";
}
$pWorksheet = (int)$pdo->query("SELECT id FROM kefu_permission WHERE permission_code='worksheet' AND parent_id=0")->fetchColumn();
echo "worksheet id = $pWorksheet\n";

// 2. 二级菜单 / 按钮
$children = [
    ['worksheet:list',     '工单列表', 'menu',   '/admin/worksheets',        1],
    ['worksheet:create',   '新建工单', 'button', NULL,                      2],
    ['worksheet:update',   '编辑工单', 'button', NULL,                      3],
    ['worksheet:reply',    '回复工单', 'button', NULL,                      4],
    ['worksheet:assign',   '分配工单', 'button', NULL,                      5],
    ['worksheet:close',    '关闭工单', 'button', NULL,                      6],
    ['worksheet:reopen',   '重开工单', 'button', NULL,                      7],
    ['worksheet:delete',   '删除工单', 'button', NULL,                      8],
    ['worksheet:stats',    '工单统计', 'menu',   '/admin/worksheets/stats',  9],
    ['worksheet:template', '工单模板', 'menu',   '/admin/worksheets/tpl',   10],
    ['worksheet:field',    '工单字段', 'menu',   '/admin/worksheets/fld',   11],
    ['worksheet:process',  '工单流程', 'menu',   '/admin/worksheets/proc',  12],
    ['worksheet:category', '工单分类', 'menu',   '/admin/worksheets/cat',   13],
    ['worksheet:sla',      'SLA 报表', 'menu',   '/admin/worksheets/sla',   14],
];
$ins = $pdo->prepare("INSERT INTO kefu_permission (parent_id, permission_code, permission_name, type, path, sort)
                      SELECT ?, ?, ?, ?, ?, ?
                      FROM DUAL
                      WHERE NOT EXISTS (
                        SELECT 1 FROM kefu_permission WHERE permission_code = ?
                      )");
foreach ($children as $c) {
    [$code, $name, $type, $path, $sort] = $c;
    $ins->execute([$pWorksheet, $code, $name, $type, $path, $sort, $code]);
    echo "  $code → $name\n";
}

// 3. 留言管理作为顶级菜单（之前散落在 session 下，单独提取更清晰）
$row = $pdo->query("SELECT id FROM kefu_permission WHERE permission_code='leave-msg' AND parent_id=0")->fetch();
if (!$row) {
    $maxSort = (int)$pdo->query("SELECT IFNULL(MAX(sort), 0) FROM kefu_permission WHERE parent_id=0")->fetchColumn();
    $pdo->prepare("INSERT INTO kefu_permission (parent_id, permission_code, permission_name, type, path, sort)
                   VALUES (0, 'leave-msg', '留言管理', 'menu', '/admin/leave-msg', ?)")
        ->execute([$maxSort + 1]);
    echo "新增一级菜单 leave-msg\n";
}
$pLeave = (int)$pdo->query("SELECT id FROM kefu_permission WHERE permission_code='leave-msg' AND parent_id=0")->fetchColumn();
echo "leave-msg id = $pLeave\n";

// 留言子菜单
$leaveChildren = [
    ['leave-msg:list',     '留言列表',   'menu',   '/admin/leave-msg',        1],
    ['leave-msg:reply',    '回复留言',   'button', NULL,                     2],
    ['leave-msg:assign',   '分配留言',   'button', NULL,                     3],
    ['leave-msg:update',   '修改状态',   'button', NULL,                     4],
    ['leave-msg:delete',   '删除留言',   'button', NULL,                     5],
    ['leave-msg:stats',    '留言统计',   'menu',   '/admin/leave-msg/stats',  6],
];
foreach ($leaveChildren as $c) {
    [$code, $name, $type, $path, $sort] = $c;
    $ins->execute([$pLeave, $code, $name, $type, $path, $sort, $code]);
    echo "  $code → $name\n";
}

// 4. 默认权限分配
// admin（role_id=1）= 全部权限（已有）
// supervisor（role_id=2）= 除系统管理外的所有（已有，把新加的工单/留言也覆盖进来）
$pdo->exec("INSERT IGNORE INTO kefu_role_permission (tenant_id, role_id, permission_id)
            SELECT 1, 2, id FROM kefu_permission
            WHERE permission_code IN (
              'worksheet', 'worksheet:list', 'worksheet:create', 'worksheet:update',
              'worksheet:reply', 'worksheet:assign', 'worksheet:close', 'worksheet:reopen',
              'worksheet:delete', 'worksheet:stats', 'worksheet:template', 'worksheet:field',
              'worksheet:process', 'worksheet:category', 'worksheet:sla',
              'leave-msg', 'leave-msg:list', 'leave-msg:reply', 'leave-msg:assign',
              'leave-msg:update', 'leave-msg:delete', 'leave-msg:stats'
            )");
echo "supervisor 已分配工单/留言权限\n";

// agent（role_id=3）= 仅工作台相关 + 工单列表/回复/分配 + 留言列表/回复
$pdo->exec("INSERT IGNORE INTO kefu_role_permission (tenant_id, role_id, permission_id)
            SELECT 1, 3, id FROM kefu_permission
            WHERE permission_code IN (
              'worksheet', 'worksheet:list', 'worksheet:reply',
              'leave-msg', 'leave-msg:list', 'leave-msg:reply'
            )");
echo "agent 已分配工单/留言权限\n";

echo "迁移完成\n";