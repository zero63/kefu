<?php
/**
 * 管理后台 - 访客端样式自定义
 * 功能：管理后台对网页接入 widget / 小程序 / H5 访客端的展示样式进行自定义
 *       包括悬浮窗位置、颜色、欢迎语、入口图标、对话窗口布局、自动弹出等
 * 存储：kefu_config，key='visitor_style'，值为 JSON
 * 作者：kefu 开发团队
 *
 * 配置 schema（visitor_style 节）：
 * {
 *   "enabled": true,                         // 总开关
 *   "position": "bottom-right",              // 悬浮窗位置：bottom-right/bottom-left/top-right/top-left
 *   "offset_x": 24,                          // 横向偏移 px
 *   "offset_y": 24,                          // 纵向偏移 px
 *   "primary_color": "#0EA5E9",              // 主色
 *   "accent_color": "#F0F9FF",               // 辅色（气泡背景）
 *   "text_color": "#1F2937",                 // 文字颜色
 *   "icon_style": "round",                   // 入口图标样式：round/square/chat/support/headset
 *   "icon_text": "在线咨询",                  // 入口图标文字
 *   "welcome_text": "您好，请问有什么可以帮您？",
 *   "placeholder": "请输入您的问题…",
 *   "auto_popup": false,                     // 是否自动弹出
 *   "auto_popup_delay_sec": 5,               // 自动弹出延迟（秒）
 *   "auto_popup_msg": "欢迎光临，有什么可以帮您？",
 *   "show_avatar": true,                     // 显示客服头像
 *   "show_source_badge": true,               // 显示会话来源标签
 *   "show_typing_indicator": true,           // 显示"正在输入"
 *   "enable_emoji": true,                    // 启用表情
 *   "enable_file": true,                     // 启用文件上传
 *   "enable_voice": false,                   // 启用语音
 *   "enable_evaluate": true,                 // 启用评价
 *   "show_offline_form": true,               // 离线时显示留言表单
 *   "header_bg_color": "#0EA5E9",            // 顶部背景色
 *   "header_text_color": "#FFFFFF",          // 顶部文字色
 *   "company_logo": "",                       // 公司 logo URL
 *   "company_name": "",                       // 公司名称（顶部）
 *   "robot_name": "小助手",                   // 机器人名称
 *   "agent_name": "客服小 K",                 // 客服默认名
 *   "max_input_length": 500,                 // 输入最大长度
 *   "rate_limit_msg_per_min": 60,            // 每分钟最多消息数
 *   "z_index": 99999,                          // 层级
 *   "agent_bubble_bg": "#0EA5E9",             // 客服气泡背景色（访客端看到的客服消息颜色）
 *   "agent_bubble_color": "#FFFFFF",          // 客服气泡字体颜色
 *   "customer_bubble_bg": "#FFFFFF",          // 访客气泡背景色（访客端看到的访客消息颜色）
 *   "customer_bubble_color": "#1F2937",       // 访客气泡字体颜色
 *   "agent_console_agent_bg": "#0EA5E9",      // 客服工作台：客服侧消息气泡背景色
 *   "agent_console_agent_color": "#FFFFFF",   // 客服工作台：客服侧消息气泡字体颜色
 *   "agent_console_customer_bg": "#FFFFFF",   // 客服工作台：访客侧消息气泡背景色
 *   "agent_console_customer_color": "#1F2937",// 客服工作台：访客侧消息气泡字体颜色
 *   "agent_console_ai_bg": "#F5F3FF",         // 客服工作台：AI 消息气泡背景色
 *   "agent_console_ai_color": "#5B21B6"       // 客服工作台：AI 消息气泡字体颜色
 * }
 */
namespace app\controller\admin;
use support\Request;
use app\lib\Db;

class VisitorStyleController {
    const CFG_KEY = 'visitor_style';

    /** 默认 schema */
    private function defaults() {
        return [
            'enabled' => true,
            'position' => 'bottom-right',
            'offset_x' => 24,
            'offset_y' => 24,
            'primary_color' => '#0EA5E9',
            'accent_color' => '#F0F9FF',
            'text_color' => '#1F2937',
            'icon_style' => 'round',
            'icon_text' => '在线咨询',
            'welcome_text' => '您好，请问有什么可以帮您？',
            'placeholder' => '请输入您的问题…',
            'auto_popup' => false,
            'auto_popup_delay_sec' => 5,
            'auto_popup_msg' => '欢迎光临，有什么可以帮您？',
            'show_avatar' => true,
            'show_source_badge' => true,
            'show_typing_indicator' => true,
            'enable_emoji' => true,
            'enable_file' => true,
            'enable_voice' => false,
            'enable_evaluate' => true,
            'show_offline_form' => true,
            'header_bg_color' => '#0EA5E9',
            'header_text_color' => '#FFFFFF',
            'company_logo' => '',
            'company_name' => '',
            'robot_name' => '小助手',
            'agent_name' => '客服小 K',
            'max_input_length' => 500,
            'rate_limit_msg_per_min' => 60,
            'z_index' => 99999,
            'agent_bubble_bg' => '#0EA5E9',
            'agent_bubble_color' => '#FFFFFF',
            'customer_bubble_bg' => '#FFFFFF',
            'customer_bubble_color' => '#1F2937',
            'agent_console_agent_bg' => '#7C3AED',
            'agent_console_agent_color' => '#FFFFFF',
            'agent_console_customer_bg' => '#FFFFFF',
            'agent_console_customer_color' => '#1F2937',
            'agent_console_ai_bg' => '#F5F3FF',
            'agent_console_ai_color' => '#5B21B6',
        ];
    }

    private function read($tenantId) {
        Db::setTenantId($tenantId);
        $row = Db::find(
            "SELECT config_value FROM kefu_config WHERE tenant_id = :t AND config_key = :k",
            [':t' => $tenantId, ':k' => self::CFG_KEY]
        );
        if (!$row || empty($row['config_value'])) return $this->defaults();
        $cfg = json_decode($row['config_value'], true);
        return is_array($cfg) ? array_merge($this->defaults(), $cfg) : $this->defaults();
    }

    private function write($tenantId, $cfg) {
        Db::setTenantId($tenantId);
        $now = date('Y-m-d H:i:s');
        $json = json_encode($cfg, JSON_UNESCAPED_UNICODE);
        $sql = "INSERT INTO kefu_config (tenant_id, config_key, config_value, updated_at)
                VALUES (:t, :k, :v, :n)
                ON DUPLICATE KEY UPDATE config_value = :v2, updated_at = :n2";
        $pdo = Db::pdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':t'  => $tenantId, ':k'  => self::CFG_KEY,
            ':v'  => $json,      ':n'  => $now,
            ':v2' => $json,      ':n2' => $now,
        ]);
    }

    public function get(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        if ($tenantId <= 0) return json(['code' => 400, 'msg' => '租户未识别']);
        // 当前请求的 host（用于前端拼接嵌入代码）
        $host = $request->header('host', '');
        $scheme = $request->header('x-forwarded-proto', '');
        if (!$scheme) {
            $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        }
        $baseUrl = '';
        if ($host) {
            $baseUrl = $scheme . '://' . $host;
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'config' => $this->read($tenantId),
            'defaults' => $this->defaults(),
            'tenantId' => $tenantId,
            'host' => $host,
            'baseUrl' => $baseUrl,  // 当前服务地址，前端用来拼接嵌入代码
        ]]);
    }

    /**
     * GET /api/visitor-style/get
     * 公共接口：客服端 console.html 读取样式配置（不需要管理员鉴权）
     * tenant_id 通过 query / header 传入
     */
    public function publicGet(Request $request) {
        $tenantId = intval($request->get('tenant_id', 0));
        if ($tenantId <= 0) $tenantId = intval($request->header('x-tenant-id', 0));
        if ($tenantId <= 0) $tenantId = 1;
        $cfg = $this->read($tenantId);
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'config' => $cfg,
            'tenantId' => $tenantId,
        ]]);
    }

    public function save(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        if ($tenantId <= 0) return json(['code' => 400, 'msg' => '租户未识别']);

        $body = $request->post();
        $defaults = $this->defaults();
        $cfg = [];
        foreach ($defaults as $k => $v) {
            if (array_key_exists($k, $body)) $cfg[$k] = $body[$k];
        }
        // 类型修正
        foreach (['enabled','auto_popup','show_avatar','show_source_badge','show_typing_indicator','enable_emoji','enable_file','enable_voice','enable_evaluate','show_offline_form'] as $b) {
            if (array_key_exists($b, $cfg)) $cfg[$b] = $cfg[$b] ? true : false;
        }
        foreach (['offset_x','offset_y','auto_popup_delay_sec','max_input_length','rate_limit_msg_per_min','z_index'] as $i) {
            if (array_key_exists($i, $cfg)) $cfg[$i] = intval($cfg[$i]);
        }
        // 颜色：保留 16 进制格式
        foreach (['primary_color','accent_color','text_color','header_bg_color','header_text_color',
                  'agent_bubble_bg','agent_bubble_color','customer_bubble_bg','customer_bubble_color',
                  'agent_console_agent_bg','agent_console_agent_color','agent_console_customer_bg',
                  'agent_console_customer_color','agent_console_ai_bg','agent_console_ai_color'] as $c) {
            if (isset($cfg[$c]) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $cfg[$c])) {
                return json(['code' => 400, 'msg' => '颜色格式错误（需 #RRGGBB）']);
            }
        }

        $merged = array_merge($defaults, $cfg);
        $this->write($tenantId, $merged);
        return json(['code' => 0, 'msg' => '保存成功', 'data' => ['config' => $merged]]);
    }

    public function reset(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        if ($tenantId <= 0) return json(['code' => 400, 'msg' => '租户未识别']);
        $this->write($tenantId, $this->defaults());
        return json(['code' => 0, 'msg' => '已重置', 'data' => ['config' => $this->defaults()]]);
    }

    /**
     * 预览：返回渲染预览所需的 CSS 片段
     */
    public function preview(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        if ($tenantId <= 0) return json(['code' => 400, 'msg' => '租户未识别']);
        $cfg = $this->read($tenantId);

        // 位置映射
        $posMap = [
            'bottom-right' => 'bottom:' . $cfg['offset_y'] . 'px;right:' . $cfg['offset_x'] . 'px;',
            'bottom-left'  => 'bottom:' . $cfg['offset_y'] . 'px;left:' . $cfg['offset_x'] . 'px;',
            'top-right'    => 'top:' . $cfg['offset_y'] . 'px;right:' . $cfg['offset_x'] . 'px;',
            'top-left'     => 'top:' . $cfg['offset_y'] . 'px;left:' . $cfg['offset_x'] . 'px;',
        ];
        $pos = $posMap[$cfg['position']] ?? $posMap['bottom-right'];

        // 生成内联 CSS
        $css = ":root{--kf-primary:{$cfg['primary_color']};--kf-accent:{$cfg['accent_color']};--kf-text:{$cfg['text_color']};--kf-header-bg:{$cfg['header_bg_color']};--kf-header-text:{$cfg['header_text_color']};}
#kefu-widget{position:fixed;{$pos}z-index:{$cfg['z_index']};font-family:-apple-system,BlinkMacSystemFont,'PingFang SC','Microsoft YaHei',sans-serif;}
#kefu-widget .kf-launcher{width:60px;height:60px;border-radius:50%;background:{$cfg['primary_color']};color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,.15);font-size:13px;font-weight:600}
#kefu-widget .kf-panel{position:absolute;bottom:80px;width:360px;height:520px;background:#fff;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.12);display:none;flex-direction:column;overflow:hidden}
#kefu-widget .kf-header{background:{$cfg['header_bg_color']};color:{$cfg['header_text_color']};padding:14px 16px;font-size:14px;font-weight:600}
#kefu-widget .kf-body{flex:1;background:{$cfg['accent_color']};overflow-y:auto;padding:14px}
#kefu-widget .kf-input{padding:10px;border-top:1px solid #eee;display:flex;gap:6px}
#kefu-widget .kf-input input{flex:1;padding:8px 10px;border:1px solid #ddd;border-radius:6px;color:{$cfg['text_color']}}";

        // 生成内联 HTML
        $html = '<div id="kefu-widget" class="kf-' . $cfg['icon_style'] . '">' .
            '<div class="kf-panel">' .
              '<div class="kf-header">' . htmlspecialchars($cfg['company_name'] ?: '在线客服') . '</div>' .
              '<div class="kf-body"><div class="kf-msg">' . htmlspecialchars($cfg['welcome_text']) . '</div></div>' .
              '<div class="kf-input"><input placeholder="' . htmlspecialchars($cfg['placeholder']) . '" maxlength="' . $cfg['max_input_length'] . '" /></div>' .
            '</div>' .
            '<div class="kf-launcher">' . htmlspecialchars($cfg['icon_text']) . '</div>' .
          '</div>';

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'css' => $css,
            'html' => $html,
            'config' => $cfg,
        ]]);
    }
}