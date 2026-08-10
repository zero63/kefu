<?php
/**
 * 留言系统迁移：给 kefu_leave_message 增加 ticket_no 字段
 * 用于前端展示工单号
 */
require __DIR__ . '/../vendor/autoload.php';

$dsn = 'mysql:host=127.0.0.1;dbname=kefu;charset=utf8mb4';
$pdo = new PDO($dsn, 'kefu', 'adminkefu');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 检查列是否存在
$col = $pdo->query("SHOW COLUMNS FROM kefu_leave_message LIKE 'ticket_no'")->fetch();
if (!$col) {
    $pdo->exec("ALTER TABLE kefu_leave_message
                ADD COLUMN ticket_no VARCHAR(32) DEFAULT NULL COMMENT '工单号，前端展示用' AFTER id,
                ADD UNIQUE KEY uk_ticket_no (ticket_no)");
    echo "ticket_no 列已添加\n";
} else {
    echo "ticket_no 已存在，跳过\n";
}

// 给历史数据回填工单号
$rows = $pdo->query("SELECT id, created_at FROM kefu_leave_message WHERE ticket_no IS NULL OR ticket_no = ''")->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare("UPDATE kefu_leave_message SET ticket_no = ? WHERE id = ?");
$updated = 0;
foreach ($rows as $r) {
    $no = 'LM' . date('Ymd', strtotime($r['created_at'])) . str_pad($r['id'], 6, '0', STR_PAD_LEFT);
    $stmt->execute([$no, $r['id']]);
    $updated++;
}
echo "已为 {$updated} 条历史留言回填工单号\n";

echo "迁移完成\n";
