<?php
/**
 * 敏感词/违禁词过滤引擎
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 * 说明：
 *   - 访客敏感词：替换为同长度 * 号，不阻断
 *   - 客服违禁词：拦截 / 替换 / 警告 三种模式可选
 *   - 支持普通词和正则表达式
 */

namespace app\lib;

use app\lib\Db;

class SensitiveFilter
{
    /**
     * 缓存词库（避免每次请求都查库）
     * @var array
     */
    private static $wordCache = [];
    private static $cacheExpire = 0;

    /**
     * 加载敏感词库到缓存
     */
    private static function loadWords() {
        if (self::$cacheExpire > time()) return;

        // 加载全局词库（tenant_id=0）和当前租户词库
        $tenantId = Db::getTenantId();
        $rows = Db::query(
            "SELECT word, category, action, is_regex FROM kefu_sensitive_word WHERE status=1 AND (tenant_id=0 OR tenant_id=:tid)",
            [':tid' => $tenantId]
        );
        self::$wordCache = $rows ?: [];
        self::$cacheExpire = time() + 300; // 5 分钟缓存
    }

    /**
     * 统一入口：过滤任意文本
     * @param string $senderType visitor / agent / robot / system / custom
     * @param string $content
     * @return array
     */
    public static function filter($senderType, $content) {
        $direction = in_array($senderType, ['visitor', 'customer']) ? 'visitor'
                  : (in_array($senderType, ['agent', 'robot']) ? 'agent' : 'visitor');
        return self::process($content, $direction);
    }

    /**
     * 管理 API：列出所有敏感词
     */
    public static function listWords($tenantId, $params = []) {
        Db::setTenantId($tenantId);
        $where = 'WHERE 1=1';
        $bind = [];
        if (!empty($params['category'])) {
            $where .= ' AND category = :c';
            $bind[':c'] = $params['category'];
        }
        if (!empty($params['action'])) {
            $where .= ' AND action = :a';
            $bind[':a'] = $params['action'];
        }
        if (!empty($params['keyword'])) {
            $where .= ' AND word LIKE :k';
            $bind[':k'] = '%' . $params['keyword'] . '%';
        }
        if (isset($params['scope']) && $params['scope'] === 'tenant') {
            $where .= ' AND tenant_id = :t';
            $bind[':t'] = $tenantId;
        } elseif (isset($params['scope']) && $params['scope'] === 'global') {
            $where .= ' AND tenant_id = 0';
        }
        return Db::query("SELECT id, tenant_id, word, category, action, is_regex, status, created_by, created_at FROM kefu_sensitive_word $where ORDER BY id DESC LIMIT 500", $bind);
    }

    public static function addWord($tenantId, $params, $operatorId) {
        $word = trim($params['word'] ?? '');
        if ($word === '') return ['code' => 400, 'msg' => 'word required'];
        $category = $params['category'] ?? 'common';
        $action = $params['action'] ?? 'replace';
        if (!in_array($action, ['replace', 'block', 'warn'])) {
            $action = 'replace';
        }
        $isRegex = !empty($params['is_regex']) ? 1 : 0;
        $scope = $params['scope'] ?? 'tenant'; // tenant / global

        Db::setTenantId($tenantId);

        $tid = $scope === 'global' ? 0 : $tenantId;
        $exists = Db::value("SELECT id FROM kefu_sensitive_word WHERE tenant_id=:t AND word=:w",
            [':t' => $tid, ':w' => $word]);
        if ($exists) return ['code' => 400, 'msg' => '已存在同名字'];

        $id = Db::insert('kefu_sensitive_word', [
            'tenant_id' => $tid,
            'word'      => $word,
            'category'  => $category,
            'action'    => $action,
            'is_regex'  => $isRegex,
            'status'    => 1,
            'created_by'=> $operatorId,
        ]);
        self::clearCache();
        return ['code' => 0, 'msg' => 'ok', 'data' => ['id' => $id]];
    }

    public static function removeWord($tenantId, $id) {
        Db::setTenantId($tenantId);
        Db::exec("DELETE FROM kefu_sensitive_word WHERE id=:id AND (tenant_id=:t OR tenant_id=0)",
            [':id' => $id, ':t' => $tenantId]);
        self::clearCache();
        return ['code' => 0, 'msg' => 'ok'];
    }

    /**
     * 测试给定文本中是否命中
     */
    public static function test($text) {
        if (empty($text)) return ['hit' => false, 'words' => []];
        return self::process($text, 'visitor');
    }

    /**
     * 过滤访客消息（替换为 * 号）
     * @param string $content
     * @return array ['content' => 处理后内容, 'hit' => 是否命中, 'words' => 命中的词]
     */
    public static function filterVisitor($content) {
        return self::process($content, 'visitor');
    }

    /**
     * 过滤客服消息（按 action 处理）
     * @param string $content
     * @return array ['content' => 处理后内容, 'hit' => 是否命中, 'blocked' => 是否拦截, 'words' => 命中的词]
     */
    public static function filterAgent($content) {
        return self::process($content, 'agent');
    }

    /**
     * 通用处理
     * @param string $content
     * @param string $direction visitor / agent
     * @return array
     */
    private static function process($content, $direction) {
        if (empty($content)) {
            return ['content' => $content, 'hit' => false, 'blocked' => false, 'words' => []];
        }

        self::loadWords();

        $hitWords = [];
        $blocked = false;
        // 修复：在原始 content 上做所有命中区间计算，最后再统一替换
        // 防止"短词命中优先 → 长词被切断"的 bug
        $intervalList = []; // [[start, end, word, action]]

        foreach (self::$wordCache as $row) {
            $word = $row['word'];
            $action = $row['action'];
            $category = $row['category'];
            $isRegex = $row['is_regex'];

            // 只处理匹配方向的词
            if ($direction === 'visitor' && $category !== 'visitor' && $category !== 'common') continue;
            if ($direction === 'agent' && $category !== 'agent' && $category !== 'common') continue;

            if ($isRegex) {
                $pattern = '/' . str_replace('/', '\/', $word) . '/u';
                if (preg_match_all($pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
                    foreach ($m[0] as $mm) {
                        $intervalList[] = [$mm[1], $mm[1] + strlen($mm[0]), $word, $action];
                        $hitWords[] = $word;
                        if ($action === 'block') $blocked = true;
                    }
                }
            } else {
                $wordLower = mb_strtolower($word);
                $contentLower = mb_strtolower($content);
                $offset = 0;
                while (($pos = mb_stripos($contentLower, $wordLower, $offset)) !== false) {
                    $intervalList[] = [$pos, $pos + mb_strlen($word), $word, $action];
                    $hitWords[] = $word;
                    if ($action === 'block') $blocked = true;
                    $offset = $pos + mb_strlen($word);
                    if ($offset >= mb_strlen($contentLower)) break;
                }
            }
        }

        // agent 侧 block 行为直接返回
        if ($blocked) {
            return [
                'content' => $content,
                'hit' => true,
                'blocked' => true,
                'words' => array_values(array_unique($hitWords)),
            ];
        }

        // 按 start 升序排序，合并重叠区间（取合并后能覆盖范围更大的词）
        usort($intervalList, function ($a, $b) { return $a[0] <=> $b[0]; });
        $merged = [];
        foreach ($intervalList as $iv) {
            $replaced = false;
            foreach ($merged as $j => $m) {
                // 重叠：当前区间被已有区间完全覆盖
                if ($iv[0] >= $m[0] && $iv[1] <= $m[1]) {
                    $replaced = true;
                    break;
                }
                // 已有区间被当前区间覆盖
                if ($m[0] >= $iv[0] && $m[1] <= $iv[1]) {
                    $merged[$j] = $iv;
                    $replaced = true;
                    break;
                }
            }
            if (!$replaced) $merged[] = $iv;
        }

        // 倒序替换（避免前面替换影响后面的位置偏移）
        usort($merged, function ($a, $b) { return $b[0] <=> $a[0]; });
        $result = $content;
        foreach ($merged as $iv) {
            $start = $iv[0];
            $end = $iv[1];
            $len = $end - $start;
            $stars = str_repeat('*', $len);
            $result = mb_substr($result, 0, $start) . $stars . mb_substr($result, $end);
        }

        // 记录命中日志
        if (!empty($hitWords)) {
            self::logHit($direction, $content, $hitWords, $blocked);
        }

        return [
            'content' => $result,
            'hit'     => !empty($hitWords),
            'blocked' => $blocked,
            'words'   => array_values(array_unique($hitWords)),
        ];
    }

    /**
     * 记录命中日志
     */
    private static function logHit($direction, $originalContent, $words, $blocked) {
        try {
            Db::insert('kefu_sensitive_log', [
                'tenant_id'        => Db::getTenantId(),
                'sender_type'      => $direction,
                'sender_id'        => '',
                'word'             => implode(',', $words),
                'action'           => $blocked ? 'block' : 'replace',
                'original_content' => mb_substr($originalContent, 0, 500),
            ]);
        } catch (\Exception $e) {
            // 静默失败，不影响主流程
            Logger::error('敏感词日志写入失败', ['err' => $e->getMessage()]);
        }
    }

    /**
     * 清除缓存（敏感词更新时调用）
     */
    public static function clearCache() {
        self::$wordCache = [];
        self::$cacheExpire = 0;
    }
}