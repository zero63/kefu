<?php
/**
 * 添加缺失字段 migration_v3.php
 */
$pdo = new PDO('mysql:host=localhost;dbname=kefu;charset=utf8mb4', 'kefu', 'adminkefu');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 检查列是否存在
function columnExists($pdo, $table, $col) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$col]);
    return $stmt->fetch() !== false;
}

function hasIndex($pdo, $table, $indexName) {
    $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
    $stmt->execute([$indexName]);
    return $stmt->fetch() !== false;
}

if (!columnExists($pdo, 'kefu_session', 'channel_account_id')) {
    $pdo->exec("ALTER TABLE kefu_session ADD COLUMN channel_account_id bigint(20) DEFAULT NULL AFTER channel");
    echo "+ kefu_session.channel_account_id 添加成功\n";
} else {
    echo "= kefu_session.channel_account_id 已存在\n";
}

if (!columnExists($pdo, 'kefu_message', 'client_msg_id')) {
    $pdo->exec("ALTER TABLE kefu_message ADD COLUMN client_msg_id varchar(64) DEFAULT NULL AFTER content");
    echo "+ kefu_message.client_msg_id 添加成功\n";
} else {
    echo "= kefu_message.client_msg_id 已存在\n";
}

if (!hasIndex($pdo, 'kefu_message', 'idx_tenant_session')) {
    $pdo->exec("ALTER TABLE kefu_message ADD INDEX idx_tenant_session (tenant_id, session_id)");
    echo "+ kefu_message.tenant+session 索引添加成功\n";
} else {
    echo "= kefu_message.tenant+session 索引已存在\n";
}

if (!hasIndex($pdo, 'kefu_session', 'uniq_tenant_session')) {
    $pdo->exec("ALTER TABLE kefu_session ADD UNIQUE KEY uniq_tenant_session (tenant_id, session_id)");
    echo "+ kefu_session.tenant+session 唯一索引添加成功\n";
} else {
    echo "= kefu_session.tenant+session 唯一索引已存在\n";
}