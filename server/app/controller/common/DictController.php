<?php
/**
 * 系统字典接口
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 * 说明：从 kefu_config 表读取所有配置返回 key-value 字典
 */
namespace app\controller\common;

use support\Request;
use app\lib\Db;

class DictController
{
    /**
     * 获取系统配置字典
     * GET /api/common/dict
     */
    public function index(Request $request)
    {
        // 查询所有配置（默认租户 1）
        $rows = Db::query(
            "SELECT config_key, config_value FROM kefu_config WHERE tenant_id = 1"
        );

        // 转为 key-value 字典
        $dict = [];
        foreach ($rows as $row) {
            $dict[$row['config_key']] = $row['config_value'];
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => $dict]);
    }
}
