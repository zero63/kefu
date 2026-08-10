<?php
/**
 * 留言系统迁移：创建 kefu_leave_message 表
 */
require __DIR__ . '/../vendor/autoload.php';

$dsn = 'mysql:host=127.0.0.1;dbname=kefu;charset=utf8mb4';
$pdo = new PDO($dsn, 'kefu', 'adminkefu');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// kefu_leave_message 表
$pdo->exec("
CREATE TABLE IF NOT EXISTS kefu_leave_message (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '租户ID',
  visitor_id VARCHAR(64) DEFAULT NULL COMMENT '访客ID（来自 widget/会话）',
  visitor_name VARCHAR(64) DEFAULT NULL COMMENT '访客姓名',
  visitor_email VARCHAR(128) DEFAULT NULL COMMENT '访客邮箱',
  visitor_phone VARCHAR(32) DEFAULT NULL COMMENT '访客手机',
  visitor_meta JSON DEFAULT NULL COMMENT '访客其它自定义信息',
  session_id VARCHAR(64) DEFAULT NULL COMMENT '关联会话ID',
  source VARCHAR(32) DEFAULT 'web' COMMENT '渠道来源 web/h5/wechat/weapp/api 等',
  subject VARCHAR(255) DEFAULT NULL COMMENT '留言主题/标题',
  content TEXT NOT NULL COMMENT '留言内容',
  status VARCHAR(20) NOT NULL DEFAULT 'new' COMMENT '状态 new/replied/spam/closed',
  priority VARCHAR(16) DEFAULT 'normal' COMMENT '优先级 low/normal/high',
  assigned_to INT UNSIGNED DEFAULT NULL COMMENT '分配给的客服ID',
  assigned_to_name VARCHAR(64) DEFAULT NULL,
  reply_content TEXT COMMENT '回复内容',
  reply_by INT UNSIGNED DEFAULT NULL COMMENT '回复人ID',
  reply_by_name VARCHAR(64) DEFAULT NULL,
  reply_at DATETIME DEFAULT NULL COMMENT '回复时间',
  ip VARCHAR(45) DEFAULT NULL COMMENT '访客IP',
  user_agent VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tenant_status (tenant_id, status),
  KEY idx_tenant_created (tenant_id, created_at),
  KEY idx_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='访客留言表';
");

echo "kefu_leave_message 已创建\n";

// 检查表是否存在
$row = $pdo->query("SHOW TABLES LIKE 'kefu_leave_message'")->fetch();
if (!$row) {
    echo "ERROR: 表创建失败\n";
    exit(1);
}

echo "迁移完成\n";