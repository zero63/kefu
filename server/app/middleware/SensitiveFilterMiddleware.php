<?php
/**
 * 敏感词过滤中间件
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 *
 * 作用：
 *   - 对 JSON body 中携带的 content 字段自动做敏感词替换 / 拦截
 *   - 把过滤结果记入 _filtered_content、_sensitive_words 供 controller / service 读取
 *
 * 触发条件（按 URL 路径匹配）：
 *   - /api/visitor/*（访客主动消息）
 *   - /api/agent/message/send（客服主动消息）
 *   - /api/common/upload（敏感上传校验：文件名）
 *
 * 行为（按 sender_type）：
 *   - customer：替换为同长度 *（不阻断，标记 _sensitive_hit=1）
 *   - agent：    命中违禁词则阻断（403）
 *   - robot/其它：替换
 */

namespace app\middleware;

use app\lib\SensitiveFilter;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class SensitiveFilterMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $method = $request->method();
        if (!in_array($method, ['POST', 'PUT'])) {
            return $next($request);
        }

        $path = $request->path();
        // 修复：path 可能带或不带前导 /，统一用正则匹配
        $shouldFilter = preg_match('#^/?(api/(visitor(/|$)|agent/message/send|common/upload))#', $path);
        if (!$shouldFilter) {
            return $next($request);
        }

        $body = $request->rawBody();
        if (empty($body)) {
            return $next($request);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return $next($request);
        }

        $senderType = $data['sender_type'] ?? 'visitor';

        // 收集需要过滤的字段
        $fields = ['content', 'message', 'title', 'remark', 'reason', 'solution', 'comment', 'evaluate'];
        $hits = [];
        $maxHitFound = ['hit' => false, 'blocked' => false];

        foreach ($fields as $f) {
            if (!isset($data[$f]) || !is_string($data[$f]) || $data[$f] === '') {
                continue;
            }
            $sender = ($f === 'title' || $f === 'remark' || $f === 'reason' || $f === 'solution') ? 'agent' : $senderType;
            $res = SensitiveFilter::filter($sender, $data[$f]);
            $data[$f] = $res['content'];
            if ($res['hit']) {
                $hits[$f] = $res['words'];
                $maxHitFound['hit'] = true;
                if (!empty($res['blocked'])) {
                    $maxHitFound['blocked'] = true;
                }
            }
        }

        // agent 命中违禁词直接拦截
        if ($maxHitFound['blocked']) {
            return json([
                'code' => 403,
                'msg'  => '消息包含违禁词，已被拦截',
                'data' => ['hits' => $hits],
            ]);
        }

        // 把处理后 body 通过 reflection 写回 $request->_data['post']
        // webman 的 Request::_data 是 protected，直接赋值不允许，用 reflection
        // 先主动触发 post()，确保 _data['post'] 已被初始化（懒加载）
        try {
            $request->post();
            $ref = new \ReflectionClass($request);
            if ($ref->hasProperty('_data')) {
                $prop = $ref->getProperty('_data');
                $prop->setAccessible(true);
                $cur = $prop->getValue($request);
                if (is_array($cur) && isset($cur['post']) && is_array($cur['post'])) {
                    foreach ($data as $k => $v) {
                        $cur['post'][$k] = $v;
                    }
                    $prop->setValue($request, $cur);
                }
            }
        } catch (\Throwable $e) {
            \app\lib\Logger::error('sensitive_middleware_error', ['err' => $e->getMessage()]);
            // 兜底不影响主流程
        }

        // 公开属性供 controller / service 直接读取
        $request->tunnelBody = $data;
        $request->tunnelHits = $hits;
        $request->tunnelBlocked = $maxHitFound['hit'];

        return $next($request);
    }
}