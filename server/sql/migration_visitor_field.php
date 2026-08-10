<?php
/**
 * 访客自定义字段迁移
 * 创建 kefu_visitor_field（字段定义）和 kefu_visitor_field_value（字段值）
 */
require __DIR__ . '/../vendor/autoload.php';

$dsn = 'mysql:host=127.0.0.1;dbname=kefu;charset=utf8mb4';
$pdo = new PDO($dsn, 'kefu', 'adminkefu');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 字段定义表
$pdo->exec("
CREATE TABLE IF NOT EXISTS kefu_visitor_field (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
  field_key VARCHAR(64) NOT NULL COMMENT '字段标识',
  field_name VARCHAR(64) NOT NULL COMMENT '字段名称',
  field_type VARCHAR(20) NOT NULL DEFAULT 'text' COMMENT '类型：text/textarea/select/radio/checkbox/date/number/email/phone',
  options_json TEXT COMMENT '下拉/单选/多选的选项（JSON 数组）',
  required TINYINT NOT NULL DEFAULT 0 COMMENT '是否必填',
  placeholder VARCHAR(200) DEFAULT NULL,
  sort_no INT NOT NULL DEFAULT 0,
  enabled TINYINT NOT NULL DEFAULT 1 COMMENT '是否启用',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_tenant_key (tenant_id, field_key),
  KEY idx_tenant_enabled (tenant_id, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='访客自定义字段定义';
");

// 字段值表（每个访客对应一行）
$pdo->exec("
CREATE TABLE IF NOT EXISTS kefu_visitor_field_value (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
  visitor_id VARCHAR(64) NOT NULL COMMENT '访客 ID',
  field_id INT UNSIGNED NOT NULL,
  field_value TEXT,
  session_id VARCHAR(64) DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_visitor_field (tenant_id, visitor_id, field_id),
  KEY idx_session (session_id),
  KEY idx_visitor (tenant_id, visitor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='访客自定义字段值';
");

echo "kefu_visitor_field 和 kefu_visitor_field_value 已创建\n";