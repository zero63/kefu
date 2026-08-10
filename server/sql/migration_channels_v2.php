<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=kefu;charset=utf8mb4', 'kefu', 'adminkefu');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1. access_token 缓存表（微信官方必备，2小时过期）
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS kefu_access_token (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
        channel_code VARCHAR(32) NOT NULL,
        account_id INT UNSIGNED DEFAULT NULL COMMENT '对应 channel_account.id（多公众号时区分）',
        app_id VARCHAR(64) DEFAULT NULL,
        access_token VARCHAR(512) NOT NULL,
        expires_in INT UNSIGNED NOT NULL DEFAULT 7200 COMMENT '秒',
        expires_at DATETIME NOT NULL,
        refresh_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_account (tenant_id, channel_code, account_id),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='access_token 缓存'");
    echo "kefu_access_token OK\n";
} catch (Exception $e) { echo "kefu_access_token: " . $e->getMessage() . "\n"; }

// 2. 客服账号表（kfaccount 对应：公众号下的客服工号）
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS kefu_kf_account (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
        channel_account_id INT UNSIGNED NOT NULL COMMENT '对应 kefu_channel_account.id',
        kf_account VARCHAR(64) NOT NULL COMMENT '完整客服账号：前缀@公众号微信号',
        kf_id VARCHAR(32) DEFAULT NULL COMMENT '客服编号（微信侧）',
        kf_nick VARCHAR(32) DEFAULT NULL COMMENT '客服昵称',
        kf_avatar VARCHAR(255) DEFAULT NULL,
        invite_wx VARCHAR(64) DEFAULT NULL COMMENT '邀请绑定的微信',
        invite_status VARCHAR(16) DEFAULT NULL COMMENT 'waiting/rejected/expired/bound',
        invite_expire_time DATETIME DEFAULT NULL,
        password_hash VARCHAR(255) DEFAULT NULL,
        employee_id INT UNSIGNED DEFAULT NULL COMMENT '关联本系统员工',
        status TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_kf_account (channel_account_id, kf_account),
        INDEX idx_tenant (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客服账号（多客服）'");
    echo "kefu_kf_account OK\n";
} catch (Exception $e) { echo "kefu_kf_account: " . $e->getMessage() . "\n"; }

// 3. 微信消息事件日志
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS kefu_channel_event (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
        channel_code VARCHAR(32) NOT NULL,
        account_id INT UNSIGNED DEFAULT NULL,
        event_type VARCHAR(32) DEFAULT NULL COMMENT 'event/click/text/image/...',
        msg_id VARCHAR(64) DEFAULT NULL,
        openid VARCHAR(64) DEFAULT NULL,
        external_user_id VARCHAR(128) DEFAULT NULL,
        raw_payload TEXT DEFAULT NULL,
        processed TINYINT(1) NOT NULL DEFAULT 0,
        session_id BIGINT UNSIGNED DEFAULT NULL,
        message_id BIGINT UNSIGNED DEFAULT NULL,
        error_msg VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tenant_created (tenant_id, created_at),
        INDEX idx_msg_id (msg_id),
        INDEX idx_openid (openid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='渠道消息事件日志'");
    echo "kefu_channel_event OK\n";
} catch (Exception $e) { echo "kefu_channel_event: " . $e->getMessage() . "\n"; }

echo "done\n";