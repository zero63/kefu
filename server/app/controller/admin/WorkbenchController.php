<?php
/**
 * 管理后台 - 客服工作台自定义
 * 功能：管理后台对客服工作台展示内容、布局、快捷入口、统计卡片等的自定义
 * 存储：kefu_config，key='workbench'，值为 JSON
 * 作者：kefu 开发团队
 *
 * 配置 schema（workbench 节）：
 * {
 *   "welcome_text": "欢迎来到客服工作台",
 *   "default_status": "online",            // online/busy/away
 *   "auto_accept": true,                   // 是否自动接单
 *   "show_customer_info": true,            // 显示客户信息卡
 *   "show_session_source": true,           // 显示会话来源
 *   "show_track_timeline": true,           // 显示客户轨迹
 *   "show_quick_reply": true,              // 显示快捷回复
 *   "show_kb_suggest": true,               // 显示知识库推荐
 *   "show_history_sessions": true,         // 显示历史会话
 *   "show_customer_note": true,            // 显示客户备注
 *   "show_internal_msg": true,             // 显示站内信入口
 *   "tabs": ["my_received","my_processed","my_followed","my_assigned"],
 *   "stat_cards": ["today_new","active_sessions","avg_response","satisfaction"],
 *   "quick_actions": ["send_msg","transfer","close","create_ticket","search_kb"],
 *   "max_concurrent_sessions": 5,          // 单客服最大并发
 *   "session_timeout_min": 30,             // 会话超时（分钟）
 *   "first_response_target_sec": 60,       // 首响目标时长（秒）
 *   "theme_color": "#0EA5E9"
 * }
 */
namespace app\controller\admin;
use support\Request;
use app\lib\Db;

class WorkbenchController {
    const CFG_KEY = 'workbench';

    /** 默认 schema */
    private function defaults() {
        return [
            'welcome_text' => '欢迎来到客服工作台',
            'default_status' => 'online',
            'auto_accept' => true,
            'show_customer_info' => true,
            'show_session_source' => true,
            'show_track_timeline' => true,
            'show_quick_reply' => true,
            'show_kb_suggest' => true,
            'show_history_sessions' => true,
            'show_customer_note' => true,
            'show_internal_msg' => true,
            'tabs' => ['my_received','my_processed','my_followed','my_assigned'],
            'stat_cards' => ['today_new','active_sessions','avg_response','satisfaction'],
            'quick_actions' => ['send_msg','transfer','close','create_ticket','search_kb'],
            'max_concurrent_sessions' => 5,
            'session_timeout_min' => 30,
            'first_response_target_sec' => 60,
            'theme_color' => '#0EA5E9',
        ];
    }

    /** 读取工作台配置（kefu_config） */
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

    /** 写入工作台配置 */
    private function write($tenantId, $cfg) {
        Db::setTenantId($tenantId);
        $now = date('Y-m-d H:i:s');
        $sql = "INSERT INTO kefu_config (tenant_id, config_key, config_value, updated_at)
                VALUES (:t, :k, :v, :n)
                ON DUPLICATE KEY UPDATE config_value = :v2, updated_at = :n2";
        $pdo = Db::pdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':t'  => $tenantId, ':k'  => self::CFG_KEY,
            ':v'  => json_encode($cfg, JSON_UNESCAPED_UNICODE),
            ':n'  => $now,
            ':v2' => json_encode($cfg, JSON_UNESCAPED_UNICODE),
            ':n2' => $now,
        ]);
    }

    /** GET /api/admin/workbench/get */
    public function get(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        if ($tenantId <= 0) return json(['code' => 400, 'msg' => '租户未识别']);
        $cfg = $this->read($tenantId);
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'config' => $cfg,
            'defaults' => $this->defaults(),
        ]]);
    }

    /** POST /api/admin/workbench/save */
    public function save(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        if ($tenantId <= 0) return json(['code' => 400, 'msg' => '租户未识别']);

        $body = $request->post();
        $defaults = $this->defaults();
        $workbench = [];
        foreach ($defaults as $k => $v) {
            if (array_key_exists($k, $body)) $workbench[$k] = $body[$k];
        }
        // 类型修正
        foreach (['auto_accept','show_customer_info','show_session_source','show_track_timeline','show_quick_reply','show_kb_suggest','show_history_sessions','show_customer_note','show_internal_msg'] as $b) {
            if (array_key_exists($b, $workbench)) $workbench[$b] = $workbench[$b] ? true : false;
        }
        foreach (['max_concurrent_sessions','session_timeout_min','first_response_target_sec'] as $i) {
            if (array_key_exists($i, $workbench)) $workbench[$i] = intval($workbench[$i]);
        }
        // 数组字段支持逗号分隔字符串
        foreach (['tabs','stat_cards','quick_actions'] as $arr) {
            if (isset($workbench[$arr]) && is_string($workbench[$arr])) {
                $workbench[$arr] = array_values(array_filter(array_map('trim', explode(',', $workbench[$arr]))));
            } elseif (isset($workbench[$arr]) && !is_array($workbench[$arr])) {
                $workbench[$arr] = [];
            }
        }

        $merged = array_merge($defaults, $workbench);
        $this->write($tenantId, $merged);
        return json(['code' => 0, 'msg' => '保存成功', 'data' => ['config' => $merged]]);
    }

    /** POST /api/admin/workbench/reset */
    public function reset(Request $request) {
        $tenantId = intval($request->tenant_id ?? 0);
        if ($tenantId <= 0) return json(['code' => 400, 'msg' => '租户未识别']);
        $this->write($tenantId, $this->defaults());
        return json(['code' => 0, 'msg' => '已重置', 'data' => ['config' => $this->defaults()]]);
    }

    /** GET /api/admin/workbench/options - 选项字典（供前端多选下拉） */
    public function options(Request $request) {
        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'tabs' => [
                ['value'=>'my_received','label'=>'我的接待'],
                ['value'=>'my_processed','label'=>'处理中'],
                ['value'=>'my_followed','label'=>'我关注的'],
                ['value'=>'my_assigned','label'=>'待我处理'],
                ['value'=>'unassigned','label'=>'未分配'],
                ['value'=>'all','label'=>'全部'],
            ],
            'stat_cards' => [
                ['value'=>'today_new','label'=>'今日新增'],
                ['value'=>'active_sessions','label'=>'进行中会话'],
                ['value'=>'avg_response','label'=>'平均响应'],
                ['value'=>'satisfaction','label'=>'满意度'],
                ['value'=>'resolved_today','label'=>'今日解决'],
                ['value'=>'pending_tickets','label'=>'待办工单'],
            ],
            'quick_actions' => [
                ['value'=>'send_msg','label'=>'发送消息'],
                ['value'=>'transfer','label'=>'转接'],
                ['value'=>'close','label'=>'关闭会话'],
                ['value'=>'create_ticket','label'=>'创建工单'],
                ['value'=>'search_kb','label'=>'搜索知识库'],
                ['value'=>'mark_favorite','label'=>'收藏客户'],
                ['value'=>'add_tag','label'=>'打标签'],
                ['value'=>'send_file','label'=>'发送文件'],
            ],
            'default_status' => [
                ['value'=>'online','label'=>'在线'],
                ['value'=>'busy','label'=>'忙碌'],
                ['value'=>'away','label'=>'离开'],
            ],
            'theme_colors' => [
                ['value'=>'#0EA5E9','label'=>'科技蓝'],
                ['value'=>'#10B981','label'=>'清新绿'],
                ['value'=>'#8B5CF6','label'=>'紫罗兰'],
                ['value'=>'#F59E0B','label'=>'温暖橙'],
                ['value'=>'#EF4444','label'=>'活力红'],
                ['value'=>'#1F2937','label'=>'深空黑'],
            ],
        ]]);
    }
}