<?php
/**
 * 机器人推理进程
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 * 说明：
 *   - 独立进程，处理知识库匹配、寒暄库、意图识别
 *   - 通过内部 TCP 与主 webman 进程通信（端口 8788）
 *   - 演示版采用文件 + 数据库混合方式实现简化版
 *
 *   ⚠ Windows 下 webman 1.5 无法 fork 自定义进程（依赖 fork + exec），
 *     此进程会在 Linux 服务器上自动启动，Windows 下显示 process error,
 *     不影响主进程运行。
 */

namespace app\process;

use Workerman\Worker;

class RobotWorker
{
    /**
     * Worker 构造函数（webman framework 通过反射自动调用）
     * @param Worker|null $worker 可选（兼容 webman 自动注入）
     */
    public function __construct($worker = null)
    {
        // 这里可以初始化机器人配置
    }

    /**
     * 入口（webman 调用此方法）
     * @param Worker $worker
     */
    public function onWorkerStart($worker)
    {
        // 输出启动提示（仅 Linux）
        if (DIRECTORY_SEPARATOR === '/') {
            fwrite(STDOUT, "[robot-worker {$worker->id}] started\n");
        }
    }

    /**
     * 处理来自主进程的推理请求
     * 演示版：从 knowledge 表做关键词匹配
     * @param array $req 形如 ['q'=>'用户问题','tenant_id'=>1,'robot_id'=>1]
     * @return array 形如 ['answer'=>'...','matched'=>true/false,'knowledge_id'=>..]
     */
    public static function infer($req) {
        $tenantId = $req['tenant_id'] ?? 1;
        $robotId = $req['robot_id'] ?? 1;
        $q = trim($req['q'] ?? '');
        if ($q === '') {
            return ['answer' => '', 'matched' => false];
        }

        \app\lib\Db::setTenantId($tenantId);

        // 1. 精确匹配（标准问题）
        $row = \app\lib\Db::find(
            "SELECT id, answer FROM kefu_knowledge
             WHERE robot_id = :r AND status = 1 AND standard_q = :q
             LIMIT 1",
            [':r' => $robotId, ':q' => $q]
        );
        if ($row) {
            self::bumpHit($row['id']);
            return ['answer' => $row['answer'], 'matched' => true, 'knowledge_id' => $row['id']];
        }

        // 2. 相似匹配（相似问法）
        $row = \app\lib\Db::find(
            "SELECT k.id, k.answer
             FROM kefu_knowledge_similar s
             INNER JOIN kefu_knowledge k ON k.id = s.knowledge_id
             WHERE s.similar_q = :q AND k.status = 1 AND k.robot_id = :r
             LIMIT 1",
            [':q' => $q, ':r' => $robotId]
        );
        if ($row) {
            self::bumpHit($row['id']);
            return ['answer' => $row['answer'], 'matched' => true, 'knowledge_id' => $row['id']];
        }

        // 3. LIKE 模糊匹配（兜底）——用户问被标准问所包含（即用户问是 key 的子串）
        $row = \app\lib\Db::find(
            "SELECT id, answer FROM kefu_knowledge
             WHERE robot_id = :r AND status = 1 AND standard_q LIKE :q
             ORDER BY hit_count DESC, id DESC LIMIT 1",
            [':r' => $robotId, ':q' => '%' . $q . '%']
        );
        // 还没命中，反向：用户问包含标准问（即"退款政策是什么" 包含 "退款政策"）
        if (!$row) {
            $row = \app\lib\Db::find(
                "SELECT id, answer FROM kefu_knowledge
                 WHERE robot_id = :r AND status = 1 AND :q LIKE CONCAT('%', standard_q, '%')
                 ORDER BY LENGTH(standard_q) DESC, hit_count DESC LIMIT 1",
                [':r' => $robotId, ':q' => $q]
            );
        }
        if ($row) {
            self::bumpHit($row['id']);
            return ['answer' => $row['answer'], 'matched' => true, 'knowledge_id' => $row['id']];
        }

        // 4. 寒暄库兜底
        $row = \app\lib\Db::find(
            "SELECT answer FROM kefu_chitchat
             WHERE robot_id = :r AND status = 1 AND question = :q LIMIT 1",
            [':r' => $robotId, ':q' => $q]
        );
        if ($row) {
            return ['answer' => $row['answer'], 'matched' => true, 'matched_type' => 'chitchat'];
        }

        // 5. 未命中：转人工建议语
        return [
            'answer'  => '暂时无法回答您的问题，稍后将为您转接人工客服',
            'matched' => false,
            'transfer_human' => true,
        ];
    }

    private static function bumpHit($kid) {
        try {
            \app\lib\Db::exec(
                "UPDATE kefu_knowledge SET hit_count = hit_count + 1 WHERE id = :id",
                [':id' => $kid]
            );
        } catch (\Exception $e) { /* 静默 */ }
    }
}