/**
 * Kefu Web SDK v1.0
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 *
 * 用法（HTML H5）：
 *
 *  1. script src="https://your-server/kefu-sdk.js"  /script
 *  2. script
 *       Kefu.init({ apiBase: 'http://127.0.0.1:8787', tenantId: 1 });
 *       Kefu.showWidget();
 *     /script
 *
 * API:
 *   Kefu.init(options)
 *   Kefu.showWidget()
 *   Kefu.hideWidget()
 *   Kefu.sendMessage(content)
 *   Kefu.openSession()        // 创建或获取当前会话
 *   Kefu.closeSession(reason)
 *   Kefu.evaluate(score, comment)
 *   Kefu.track(event, payload) // 行为埋点
 *   Kefu.updateContext(ctx)    // 订单/购物车/用户画像
 *   Kefu.on(event, callback)   // 监听事件
 *   Kefu.off(event)
 */

(function (global, factory) {
    'use strict';
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = factory();
    } else {
        global.Kefu = factory();
    }
})(typeof window !== 'undefined' ? window : this, function () {

    const DEFAULT_OPTIONS = {
        apiBase: '',
        tenantId: 1,
        autoOpen: true,
        visitorToken: null,   // 可由外部注入（如已登录用户）
        pollInterval: 3000,    // 拉取消息的轮询间隔（fallback）
    };

    const WIDGET_TEMPLATE = `
<div id="kefu-widget" style="position:fixed;bottom:20px;right:20px;width:360px;height:520px;background:#fff;border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.18);display:flex;flex-direction:column;font-family:-apple-system,BlinkMacSystemFont,'PingFang SC',sans-serif;z-index:9999;overflow:hidden;border:1px solid #e6e8eb;">
  <div style="background:linear-gradient(135deg,#1f7aff,#4c8dff);color:#fff;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;">
    <div>
      <div style="font-weight:600;font-size:15px;">客服在线</div>
      <div id="kefu-agent-name" style="font-size:12px;opacity:.85;margin-top:2px;">为您接入客服</div>
    </div>
    <button id="kefu-min-btn" style="background:none;border:none;color:#fff;cursor:pointer;font-size:20px;line-height:1;">×</button>
  </div>
  <div id="kefu-messages" style="flex:1;overflow-y:auto;padding:14px;background:#f7f8fa;"></div>
  <div style="padding:10px;border-top:1px solid #e6e8eb;display:flex;align-items:center;gap:8px;background:#fff;">
    <textarea id="kefu-input" placeholder="请输入消息..." rows="2" style="flex:1;padding:8px 10px;border:1px solid #dcdfe6;border-radius:6px;resize:none;font-size:14px;outline:none;font-family:inherit;"></textarea>
    <button id="kefu-send" style="padding:8px 14px;background:#1f7aff;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:14px;">发送</button>
  </div>
  <div id="kefu-status" style="text-align:center;font-size:12px;color:#909399;padding:6px;background:#fafafa;border-top:1px solid #e6e8eb;">正在连接...</div>
</div>
<button id="kefu-launcher" style="position:fixed;bottom:20px;right:20px;width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#1f7aff,#4c8dff);color:#fff;border:none;cursor:pointer;font-size:24px;box-shadow:0 4px 12px rgba(31,122,255,.4);z-index:9998;display:none;">💬</button>`;

    function Kefu() {}

    let config = {};
    let visitorToken = null;
    let customerId = null;
    let currentSession = null;
    let listeners = {};
    let widgetVisible = false;
    let pollTimer = null;
    let lastSeq = 0;

    Kefu.init = function (options) {
        config = Object.assign({}, DEFAULT_OPTIONS, options || {});
        if (!config.apiBase) {
            console.warn('[Kefu] apiBase is required');
            return;
        }
        visitorToken = config.visitorToken || localStorage.getItem('kefu_visitor_token');
        customerId = localStorage.getItem('kefu_customer_id');

        // 注入 widget DOM
        if (!document.getElementById('kefu-widget')) {
            const wrap = document.createElement('div');
            wrap.innerHTML = WIDGET_TEMPLATE;
            document.body.appendChild(wrap);
            bindEvents();
        }

        // 自动打开会话
        if (config.autoOpen) {
            openSession().then(function() {
                startPolling();
            });
        }
    };

    Kefu.on = function (event, cb) {
        if (!listeners[event]) listeners[event] = [];
        listeners[event].push(cb);
    };
    Kefu.off = function (event, cb) {
        if (!listeners[event]) return;
        listeners[event] = listeners[event].filter(function (x) { return x !== cb; });
    };
    function emit(event, data) {
        (listeners[event] || []).forEach(function (cb) {
            try { cb(data); } catch (e) { console.error(e); }
        });
    }

    Kefu.showWidget = function () {
        const w = document.getElementById('kefu-widget');
        const l = document.getElementById('kefu-launcher');
        if (w) w.style.display = 'flex';
        if (l) l.style.display = 'none';
        widgetVisible = true;
    };
    Kefu.hideWidget = function () {
        const w = document.getElementById('kefu-widget');
        const l = document.getElementById('kefu-launcher');
        if (w) w.style.display = 'none';
        if (l) l.style.display = 'block';
        widgetVisible = false;
    };

    function setStatus(text) {
        const s = document.getElementById('kefu-status');
        if (s) s.textContent = text;
    }

    function setAgent(text) {
        const a = document.getElementById('kefu-agent-name');
        if (a) a.textContent = text;
    }

    function renderMessage(msg) {
        const list = document.getElementById('kefu-messages');
        if (!list) return;
        const isCustomer = msg.sender_type === 'customer';
        const wrap = document.createElement('div');
        wrap.style.cssText = 'display:flex;margin-bottom:12px;justify-content:' + (isCustomer ? 'flex-end' : 'flex-start') + ';';
        const bubble = document.createElement('div');
        bubble.style.cssText = 'max-width:75%;padding:9px 12px;border-radius:8px;font-size:14px;line-height:1.5;word-break:break-word;background:' + (isCustomer ? '#1f7aff;color:#fff;' : '#fff;color:#303133;border:1px solid #ebeef5;');
        bubble.textContent = msg.content || '';
        wrap.appendChild(bubble);
        list.appendChild(wrap);
        list.scrollTop = list.scrollHeight;
    }

    function bindEvents() {
        const minBtn = document.getElementById('kefu-min-btn');
        if (minBtn) minBtn.onclick = function () {
            Kefu.hideWidget();
        };
        const launch = document.getElementById('kefu-launcher');
        if (launch) launch.onclick = function () {
            Kefu.showWidget();
        };
        const sendBtn = document.getElementById('kefu-send');
        const input = document.getElementById('kefu-input');
        if (sendBtn && input) {
            const doSend = function () {
                const v = input.value.trim();
                if (!v) return;
                input.value = '';
                Kefu.sendMessage(v);
            };
            sendBtn.onclick = doSend;
            input.onkeydown = function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    doSend();
                }
            };
        }
    }

    Kefu.openSession = function () {
        return openSession();
    };
    function openSession() {
        const url = (config.apiBase || '') + '/api/visitor/auth/h5';
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ tenant_id: config.tenantId, visitor_token: visitorToken })
        }).then(function (r) { return r.json(); }).then(function (json) {
            if (json.code === 0) {
                visitorToken = json.data.visitor_token || json.data.customer_id;
                customerId = json.data.customer_id;
                currentSession = { id: json.data.session_id, status: json.data.session_status, agent: json.data.agent };
                localStorage.setItem('kefu_visitor_token', visitorToken);
                localStorage.setItem('kefu_customer_id', customerId);
                setStatus(currentSession.status === 'active' ? '客服已接入' : '排队中...');
                if (currentSession.agent && currentSession.agent.name) {
                    setAgent(currentSession.agent.name);
                }
                emit('session_created', currentSession);
                Kefu.showWidget();
            } else {
                setStatus('连接失败：' + json.msg);
            }
            return json;
        });
    }

    Kefu.sendMessage = function (content) {
        if (!currentSession || !currentSession.id) {
            return Promise.reject('session not ready');
        }
        const clientMsgId = 'c_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
        const url = (config.apiBase || '') + '/api/visitor/auth/h5/msg/' + currentSession.id;
        const payload = {
            tenant_id: config.tenantId,
            session_id: currentSession.id,
            content: content,
            sender_type: 'customer',
            client_msg_id: clientMsgId,
        };

        // 模拟本地回显（乐观 UI）
        renderMessage({ sender_type: 'customer', content: content });
        emit('message_sent', { client_msg_id: clientMsgId, content: content });

        return fetch((config.apiBase || '') + '/api/visitor/auth/h5/msg', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        }).then(function (r) { return r.json(); }).then(function (json) {
            if (json.code !== 0) {
                console.warn('[Kefu] send msg failed', json.msg);
            } else {
                emit('message_acked', json.data);
            }
            return json;
        }).catch(function (err) {
            console.warn('[Kefu] send error', err);
        });
    };

    Kefu.evaluate = function (score, comment) {
        if (!currentSession) return Promise.reject('no session');
        return fetch((config.apiBase || '') + '/api/evaluate/session', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                tenant_id: config.tenantId,
                session_id: currentSession.id,
                score: score,
                comment: comment || '',
                customer_id: customerId || '',
            }),
        }).then(function (r) { return r.json(); }).then(function (json) {
            emit('evaluated', json);
            return json;
        });
    };

    Kefu.track = function (eventType, payload, target) {
        return fetch((config.apiBase || '') + '/api/visitor/track', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                tenant_id: config.tenantId,
                customer_id: customerId || '',
                session_id: currentSession ? currentSession.id : '',
                event_type: eventType,
                target: target || '',
                payload: payload || {},
            }),
        }).then(function (r) { return r.json(); });
    };

    Kefu.updateContext = function (context) {
        return fetch((config.apiBase || '') + '/api/visitor/context/update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({
                tenant_id: config.tenantId,
                customer_id: customerId || '',
                session_id: currentSession ? currentSession.id : '',
            }, context || {})),
        }).then(function (r) { return r.json(); });
    };

    /**
     * 拉取历史消息（用于断线重连补差）
     */
    Kefu.fetchHistory = function (beforeSeq) {
        if (!currentSession) return Promise.reject('no session');
        const url = (config.apiBase || '') + '/api/visitor/auth/h5/history?session_id=' + currentSession.id + '&before_seq=' + (beforeSeq || 0);
        return fetch(url).then(function (r) { return r.json(); }).then(function (json) {
            if (json.code === 0) {
                (json.data.messages || []).forEach(function (m) {
                    renderMessage(m);
                });
                emit('history_loaded', json.data);
            }
            return json;
        });
    };

    /**
     * 简易轮询（仅做演示 — 真实推荐 WebSocket）
     * 调用 GET /api/poll/visitor 拉取新消息并渲染
     */
    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(function () {
            if (!currentSession || !currentSession.id || !widgetVisible) return;
            var url = (config.apiBase || '') + '/api/poll/visitor?session_id=' + encodeURIComponent(currentSession.id)
                + '&customer_id=' + encodeURIComponent(customerId || '')
                + '&tenant_id=' + encodeURIComponent(config.tenantId || 1)
                + '&after_seq=' + lastSeq;
            fetch(url).then(function (r) { return r.json(); }).then(function (json) {
                if (json.code !== 0 || !json.data || !json.data.events) return;
                json.data.events.forEach(function (ev) {
                    if (ev.seq && ev.seq > lastSeq) lastSeq = ev.seq;
                    var p = ev.payload || {};
                    // 只渲染非客户消息（客户消息已本地回显）
                    if (p.type === 'message' && p.sender_type !== 'customer') {
                        renderMessage(p);
                        emit('message', p);
                    } else if (p.type === 'session_closed') {
                        setStatus('会话已关闭');
                        emit('session_closed', p);
                    } else if (p.type === 'assigned' || p.type === 'transferred') {
                        var agentName = (p.agent && p.agent.name) || (p.to_agent && p.to_agent.name);
                        if (agentName) setAgent(agentName);
                        setStatus('客服已接入');
                        emit('agent_changed', p);
                    }
                });
            }).catch(function (err) {
                console.warn('[Kefu] poll error', err);
            });
        }, config.pollInterval);
    }
    Kefu._stopPolling = function () {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = null;
    };

    return Kefu;
});