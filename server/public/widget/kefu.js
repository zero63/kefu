/**
 * Kefu 访客端 SDK v2.0
 *
 * 用法：
 *   <script src="https://kefu.example.com/widget/kefu.js" async></script>
 *   <script>
 *     window.KefuWidget && KefuWidget.init({
 *       tenantId: 1,
 *       position: 'bottom-right',
 *       name: '访客', avatar: '',  // 也可在后端 /api/visitor/info 上传
 *       // 是否默认展开对话窗口（嵌入网站时建议 true）
 *       autoOpen: true,
 *     });
 *   </script>
 *
 * 提供：
 *   KefuWidget.init(cfg)
 *   KefuWidget.open() / close() / toggle()
 *   KefuWidget.setVisitor({ name, avatar, meta })  // API 模式可调用
 *   KefuWidget.submitCustomFields(values)
 *   KefuWidget.on(event, cb)
 */
(function () {
  'use strict';
  var VERSION = '2.0.0';

  // host
  var HOST = (function () {
    var s = document.currentScript || document.querySelector('script[src*="widget/kefu.js"]');
    if (s) { try { return new URL(s.src).origin; } catch (e) {} }
    return location.origin;
  })();

  var state = {
    config: null,
    sessionId: localStorage.getItem('kefu_session_id') || genId('s'),
    visitorId: localStorage.getItem('kefu_visitor_id') || genId('v'),
    opened: false,
    onlineAgents: 0,
    messages: [],
    visitor: {
      name: localStorage.getItem('kefu_w_name') || '',
      avatar: localStorage.getItem('kefu_w_avatar') || '',
    },
    pollTimer: null,
    listeners: {},
    sessionInfo: null,
  };

  function genId(p) { return p + '_' + Math.random().toString(36).slice(2, 10) + Date.now().toString(36); }
  function saveIds() {
    try {
      localStorage.setItem('kefu_session_id', state.sessionId);
      localStorage.setItem('kefu_visitor_id', state.visitorId);
      if (state.visitor.name) localStorage.setItem('kefu_w_name', state.visitor.name);
      if (state.visitor.avatar) localStorage.setItem('kefu_w_avatar', state.visitor.avatar);
    } catch (e) {}
  }
  function $(s, r) { return (r || document).querySelector(s); }
  function ce(tag, attrs, children) {
    var el = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'style') el.style.cssText = attrs[k];
      else if (k === 'class') el.className = attrs[k];
      else if (k.indexOf('on') === 0) el.addEventListener(k.slice(2).toLowerCase(), attrs[k]);
      else if (k === 'html') el.innerHTML = attrs[k];
      else el.setAttribute(k, attrs[k]);
    });
    if (children) (Array.isArray(children) ? children : [children]).forEach(function (c) {
      if (c == null) return;
      if (typeof c === 'string') el.appendChild(document.createTextNode(c));
      else el.appendChild(c);
    });
    return el;
  }
  function escHtml(s) { return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

  // 默认头像生成（首字母彩色圆）
  function defaultAvatar(name) {
    var initial = (name || '访').slice(0, 1).toUpperCase();
    var colors = ['#0EA5E9', '#10B981', '#F59E0B', '#8B5CF6', '#EC4899', '#EF4444'];
    var c = colors[(name || '').charCodeAt(0) % colors.length];
    return 'data:image/svg+xml;utf8,' + encodeURIComponent(
      '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40"><rect width="40" height="40" rx="20" fill="' + c + '"/><text x="20" y="26" font-size="18" fill="white" text-anchor="middle" font-family="sans-serif" font-weight="600">' + escHtml(initial) + '</text></svg>'
    );
  }

  // ====== 样式（v2 聊天式） ======
  var css = `
    .kw-fab {
      position: fixed; width: 56px; height: 56px; border-radius: 50%;
      background: var(--kw-primary, #0EA5E9); color: #fff;
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; box-shadow: 0 6px 18px rgba(14,165,233,.4);
      z-index: 99999; font-size: 24px; transition: transform .15s;
    }
    .kw-fab:hover { transform: scale(1.08); }
    .kw-fab .badge {
      position: absolute; top: -4px; right: -4px; min-width: 18px; height: 18px;
      background: #EF4444; color: #fff; border-radius: 9px; font-size: 11px;
      display: none; align-items: center; justify-content: center;
      padding: 0 5px; font-weight: 600;
    }
    .kw-fab .badge.show { display: flex; }
    .kw-panel {
      position: fixed;
      width: 380px; max-width: calc(100vw - 32px); height: 580px; max-height: calc(100vh - 100px);
      background: #fff; border-radius: 14px;
      box-shadow: 0 12px 40px rgba(0,0,0,.18);
      display: none; flex-direction: column; overflow: hidden;
      z-index: 100000; font-family: -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif;
    }
    .kw-panel.open { display: flex; }
    .kw-head {
      padding: 14px 16px; color: #fff;
      background: var(--kw-primary, #0EA5E9);
      display: flex; align-items: center; gap: 10px; flex-shrink: 0;
    }
    .kw-head .info { flex: 1; min-width: 0; }
    .kw-head .info .name { font-size: 15px; font-weight: 600; line-height: 1.2; }
    .kw-head .info .status { font-size: 11px; opacity: .85; margin-top: 3px; }
    .kw-head .info .status .dot {
      display: inline-block; width: 8px; height: 8px; border-radius: 50%;
      background: #10B981; margin-right: 4px;
    }
    .kw-head .info .status .dot.off { background: #94A3B8; }
    .kw-head .close {
      cursor: pointer; opacity: .85; font-size: 20px; line-height: 1;
      padding: 0 4px; transition: opacity .15s;
    }
    .kw-head .close:hover { opacity: 1; }
    .kw-body {
      flex: 1; overflow-y: auto; padding: 16px 14px;
      background: #F8FAFC; display: flex; flex-direction: column; gap: 10px;
    }
    .kw-body::-webkit-scrollbar { width: 4px; }
    .kw-body::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 2px; }

    .kw-msg { display: flex; gap: 8px; align-items: flex-start; }
    .kw-msg.me { justify-content: flex-end; }
    .kw-msg .av {
      width: 32px; height: 32px; border-radius: 50%;
      background: #E2E8F0; flex-shrink: 0;
      background-size: cover; background-position: center;
    }
    .kw-msg .body { max-width: 70%; }
    .kw-msg .name {
      font-size: 11px; color: #94A3B8; margin-bottom: 3px;
    }
    .kw-msg.me .name { text-align: right; }
    .kw-msg .bubble {
      padding: 9px 12px; border-radius: 12px;
      font-size: 14px; line-height: 1.5; word-break: break-word;
      background: #fff; border: 1px solid #E2E8F0;
      color: var(--kw-text, #1F2937);
    }
    .kw-msg.me .bubble {
      background: var(--kw-primary, #0EA5E9);
      color: #fff; border-color: transparent;
    }
    .kw-msg .meta { font-size: 10px; color: #94A3B8; margin-top: 3px; }
    .kw-msg.me .meta { text-align: right; }

    .kw-sys {
      align-self: center; background: rgba(148,163,184,.15);
      color: #64748B; padding: 4px 12px; border-radius: 999px;
      font-size: 11px;
    }

    .kw-empty {
      text-align: center; padding: 30px 14px; color: #64748B;
    }
    .kw-empty .ico { font-size: 48px; margin-bottom: 8px; }

    .kw-form {
      padding: 10px 12px; background: #fff;
      border-top: 1px solid #E2E8F0; flex-shrink: 0;
    }
    .kw-form .row {
      display: flex; gap: 8px; align-items: flex-end;
    }
    .kw-form textarea {
      flex: 1; padding: 9px 12px; border: 1px solid #E2E8F0;
      border-radius: 18px; font-size: 14px; outline: none;
      resize: none; height: 38px; max-height: 100px;
      font-family: inherit; box-sizing: border-box;
      transition: border-color .15s;
    }
    .kw-form textarea:focus { border-color: var(--kw-primary, #0EA5E9); }
    .kw-form .send {
      width: 38px; height: 38px; border-radius: 50%;
      background: var(--kw-primary, #0EA5E9); color: #fff;
      border: 0; cursor: pointer; font-size: 16px;
      display: flex; align-items: center; justify-content: center;
      transition: background .15s;
    }
    .kw-form .send:hover { background: #0284C7; }
    .kw-form .send:disabled { background: #CBD5E1; cursor: not-allowed; }

    /* 留言表单 */
    .kw-leave { padding: 18px; }
    .kw-leave .header { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px dashed #E2E8F0; }
    .kw-leave .header .ico { width: 36px; height: 36px; border-radius: 50%; background: #FEF3C7; color: #F59E0B; display: flex; align-items: center; justify-content: center; font-size: 18px; }
    .kw-leave .header .text { flex: 1; }
    .kw-leave .header .ttl { font-size: 14px; font-weight: 700; color: #1F2937; }
    .kw-leave .header .desc { font-size: 11px; color: #64748B; margin-top: 2px; }
    .kw-leave .field { margin-bottom: 12px; }
    .kw-leave .field label { display: flex; justify-content: space-between; font-size: 12px; color: #475569; margin-bottom: 5px; font-weight: 600; }
    .kw-leave .field label .req-star { color: #EF4444; }
    .kw-leave .field .err { font-size: 11px; color: #EF4444; margin-top: 3px; display: none; }
    .kw-leave .field.has-error .err { display: block; }
    .kw-leave .field input, .kw-leave .field textarea {
      width: 100%; padding: 9px 12px; border: 1.5px solid #E2E8F0;
      border-radius: 8px; font-size: 13.5px; box-sizing: border-box;
      font-family: inherit; transition: border-color .15s, box-shadow .15s;
      outline: none;
    }
    .kw-leave .field input:focus, .kw-leave .field textarea:focus {
      border-color: var(--kw-primary, #0EA5E9);
      box-shadow: 0 0 0 3px rgba(14,165,233,.10);
    }
    .kw-leave .field.has-error input, .kw-leave .field.has-error textarea {
      border-color: #EF4444;
      background: #FEF2F2;
    }
    .kw-leave .field textarea { resize: vertical; min-height: 88px; }
    .kw-leave .row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .kw-leave .actions { display: flex; gap: 8px; margin-top: 8px; }
    .kw-leave .actions button {
      flex: 1; padding: 11px; background: var(--kw-primary, #0EA5E9); color: #fff;
      border: 0; border-radius: 8px; cursor: pointer;
      font-size: 14px; font-weight: 600;
      transition: background .15s, transform .1s;
    }
    .kw-leave .actions button:hover { background: #0284C7; }
    .kw-leave .actions button:active { transform: scale(0.98); }
    .kw-leave .actions button:disabled { background: #CBD5E1; cursor: not-allowed; }
    .kw-leave .actions .btn-secondary { background: #F1F5F9; color: #475569; }
    .kw-leave .actions .btn-secondary:hover { background: #E2E8F0; }
    .kw-leave .success-state { text-align: center; padding: 30px 18px; }
    .kw-leave .success-state .ico-big { font-size: 56px; margin-bottom: 10px; }
    .kw-leave .success-state .ttl { font-size: 16px; font-weight: 700; color: #047857; margin-bottom: 6px; }
    .kw-leave .success-state .desc { font-size: 13px; color: #64748B; line-height: 1.5; }
    .kw-leave .success-state .ticket { display: inline-block; margin-top: 10px; padding: 4px 10px; background: #F1F5F9; color: #475569; border-radius: 6px; font-family: monospace; font-size: 12px; }

    /* 自定义信息收集弹窗 */
    .kw-mask {
      position: fixed; inset: 0; background: rgba(0,0,0,.5);
      z-index: 100001; display: flex; align-items: center; justify-content: center;
    }
    .kw-mask .box {
      background: #fff; border-radius: 12px; padding: 22px;
      width: 380px; max-width: 92vw; max-height: 80vh; overflow-y: auto;
    }
    .kw-mask .ttl { font-size: 16px; font-weight: 700; margin-bottom: 4px; }
    .kw-mask .sub { font-size: 12px; color: #64748B; margin-bottom: 14px; }

    /* 移动端适配 */
    @media (max-width: 480px) {
      .kw-panel {
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        width: 100vw; height: 100vh; max-height: 100vh; border-radius: 0;
      }
    }
  `;

  function injectCss() {
    if (document.getElementById('kw-style')) return;
    var s = document.createElement('style');
    s.id = 'kw-style';
    s.textContent = css;
    document.head.appendChild(s);
  }

  function setCssVars(cfg) {
    var root = document.documentElement;
    root.style.setProperty('--kw-primary', cfg.primary_color || '#0EA5E9');
    root.style.setProperty('--kw-text', cfg.text_color || '#1F2937');
  }

  // ====== 网络 ======
  async function api(path, method, body) {
    var opts = {
      method: method || 'GET',
      headers: {
        'Content-Type': 'application/json',
        'X-Tenant-Id': String(state.config.tenantId || 1),
      },
      credentials: 'omit',
    };
    if (body) opts.body = JSON.stringify(body);
    try {
      var r = await fetch(HOST + path, opts);
      var text = await r.text();
      try { return JSON.parse(text); }
      catch (e) { return { code: -1, msg: '响应解析失败' }; }
    } catch (e) {
      return { code: -1, msg: '网络异常' };
    }
  }

  // ====== 业务 API ======
  async function fetchStyle() {
    // 公开：访客端样式
    var r = await api('/api/visitor/style/get-public', 'GET');
    if (r.code === 0 && r.data) {
      return r.data;
    }
    return {};
  }

  async function checkOnline() {
    var r = await api('/api/admin/employee/online-status', 'GET');
    state.onlineAgents = (r.code === 0) ? (r.data.online_count || 0) : 0;
    return state.onlineAgents;
  }

  async function loadSessionInfo() {
    // 拉取当前会话状态
    var r = await api('/api/visitor/session/get?session_id=' + state.sessionId, 'GET');
    if (r.code === 0) state.sessionInfo = r.data;
    else state.sessionInfo = null;
    return state.sessionInfo;
  }

  async function ensureSession(meta) {
    // 创建/获取会话，meta 是访客姓名/头像等
    var r = await api('/api/visitor/session/ensure', 'POST', {
      session_id: state.sessionId,
      visitor_id: state.visitorId,
      channel: 'widget',
      name: state.visitor.name,
      avatar: state.visitor.avatar,
      meta: meta || {},
    });
    return r;
  }

  async function fetchHistory() {
    var r = await api('/api/visitor/message/history?session_id=' + state.sessionId, 'GET');
    if (r.code === 0) state.messages = r.data.messages || [];
    return state.messages;
  }

  async function sendText(text) {
    var r = await api('/api/visitor/message/send', 'POST', {
      session_id: state.sessionId,
      sender_type: 'visitor',
      content: text,
    });
    return r;
  }

  function emit(event, data) {
    (state.listeners[event] || []).forEach(function (cb) {
      try { cb(data); } catch (e) { console.error(e); }
    });
  }
  function on(event, cb) {
    if (!state.listeners[event]) state.listeners[event] = [];
    state.listeners[event].push(cb);
  }

  // ====== 渲染 ======
  function applyPosition(cfg) {
    var fab = $('#kw-fab');
    var panel = $('#kw-panel');
    if (!fab || !panel) return;
    var pos = cfg.position || 'bottom-right';
    var ox = (cfg.offset_x || 24) + 'px';
    var oy = (cfg.offset_y || 24) + 'px';
    var panelOx = ((cfg.offset_x || 24) + 70) + 'px';
    var panelOy = ((cfg.offset_y || 24) + 10) + 'px';
    [fab, panel].forEach(function (el) { el.style.left = el.style.right = el.style.top = el.style.bottom = ''; });
    if (pos === 'bottom-right') {
      fab.style.right = ox; fab.style.bottom = oy;
      panel.style.right = ox; panel.style.bottom = panelOy;
    } else if (pos === 'bottom-left') {
      fab.style.left = ox; fab.style.bottom = oy;
      panel.style.left = ox; panel.style.bottom = panelOy;
    } else if (pos === 'top-right') {
      fab.style.right = ox; fab.style.top = oy;
      panel.style.right = ox; panel.style.top = panelOy;
    } else if (pos === 'top-left') {
      fab.style.left = ox; fab.style.top = oy;
      panel.style.left = ox; panel.style.top = panelOy;
    }
  }

  function renderFab() {
    if ($('#kw-fab')) return;
    var fab = ce('div', { class: 'kw-fab', id: 'kw-fab' });
    fab.innerHTML = '<span>💬</span><span class="badge" id="kw-fab-badge">0</span>';
    fab.addEventListener('click', function () { state.opened ? closePanel() : openPanel(); });
    document.body.appendChild(fab);
  }

  function renderPanel() {
    if ($('#kw-panel')) return;
    var panel = ce('div', { class: 'kw-panel', id: 'kw-panel' });
    panel.innerHTML =
      '<div class="kw-head">' +
        '<img class="av" id="kw-head-av" alt="">' +
        '<div class="info">' +
          '<div class="name" id="kw-head-name">在线客服</div>' +
          '<div class="status"><span class="dot" id="kw-head-dot"></span><span id="kw-head-status">连接中…</span></div>' +
        '</div>' +
        '<span class="close" id="kw-close">×</span>' +
      '</div>' +
      '<div class="kw-body" id="kw-body"></div>' +
      '<div class="kw-form" id="kw-form"></div>';
    document.body.appendChild(panel);
    $('#kw-close').onclick = closePanel;
  }

  function applyHeader(cfg) {
    var av = $('#kw-head-av');
    var nm = $('#kw-head-name');
    var dot = $('#kw-head-dot');
    var status = $('#kw-head-status');
    if (!av) return;
    var companyName = cfg.company_name || cfg.company_logo_name || '在线客服';
    nm.textContent = companyName;
    var agentAvatar = cfg.agent_avatar || '';
    av.src = agentAvatar || defaultAvatar(companyName);
    av.onerror = function () { av.src = defaultAvatar(companyName); };
    if (state.onlineAgents > 0) {
      dot.classList.remove('off');
      status.textContent = '当前 ' + state.onlineAgents + ' 位客服在线';
    } else {
      dot.classList.add('off');
      status.textContent = '暂不在线，可留言';
    }
  }

  function renderMessage(m) {
    var body = $('#kw-body');
    if (!body) return;
    var isMe = m.sender_type === 'visitor';
    var av = isMe ? (state.visitor.avatar || defaultAvatar(state.visitor.name)) : (m.agent_avatar || defaultAvatar(m.agent_name));
    var name = isMe ? (state.visitor.name || '我') : (m.agent_name || '客服');
    var wrap = ce('div', { class: 'kw-msg ' + (isMe ? 'me' : 'them') });
    if (!isMe) {
      wrap.appendChild(ce('div', { class: 'av', style: 'background-image:url(' + av + ')' }));
    }
    var b = ce('div', { class: 'body' });
    b.appendChild(ce('div', { class: 'name' }, name));
    b.appendChild(ce('div', { class: 'bubble' }, m.content || ''));
    b.appendChild(ce('div', { class: 'meta' }, formatTime(m.created_at)));
    wrap.appendChild(b);
    if (isMe) {
      wrap.appendChild(ce('div', { class: 'av', style: 'background-image:url(' + av + ')' }));
    }
    body.appendChild(wrap);
    body.scrollTop = body.scrollHeight;
  }

  function formatTime(t) {
    if (!t) return '';
    var d = typeof t === 'string' ? new Date(t.replace(' ', 'T')) : new Date(t);
    if (isNaN(d)) return '';
    var pad = function (n) { return String(n).padStart(2, '0'); };
    var h = d.getHours(), mi = pad(d.getMinutes());
    var h12 = h > 12 ? h - 12 : (h === 0 ? 12 : h);
    return h12 + ':' + mi + (h >= 12 ? ' PM' : ' AM');
  }

  function renderHistory(messages) {
    var body = $('#kw-body');
    if (!body) return;
    body.innerHTML = '';
    if (!messages.length) {
      var welcome = (state.config.welcome_text) || '您好，欢迎来到客服中心';
      body.appendChild(ce('div', { class: 'kw-sys' }, welcome));
      return;
    }
    messages.forEach(renderMessage);
  }

  function renderChatForm() {
    var form = $('#kw-form');
    if (!form) return;
    form.innerHTML = '<div class="row">' +
      '<textarea id="kw-input" placeholder="请输入消息…" rows="1"></textarea>' +
      '<button class="send" id="kw-send" title="发送">➤</button>' +
    '</div>';
    var input = $('#kw-input');
    var btn = $('#kw-send');
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        doSend();
      }
    });
    input.addEventListener('input', function () {
      input.style.height = '38px';
      input.style.height = Math.min(100, input.scrollHeight) + 'px';
    });
    btn.onclick = doSend;
  }

  async function doSend() {
    var input = $('#kw-input');
    var btn = $('#kw-send');
    var text = (input.value || '').trim();
    if (!text) return;
    btn.disabled = true;
    input.disabled = true;
    var r = await sendText(text);
    btn.disabled = false;
    input.disabled = false;
    if (r.code === 0) {
      input.value = '';
      input.style.height = '38px';
      input.focus();
      if (r.data && r.data.message) renderMessage(r.data.message);
      emit('send', { content: text });
    } else {
      alert('发送失败：' + r.msg);
    }
  }

  function renderLeaveForm() {
    var form = $('#kw-form');
    if (!form) return;
    form.innerHTML = '';
    var div = ce('div', { class: 'kw-leave' });

    // 顶部说明
    var header = ce('div', { class: 'header' });
    var ico = ce('div', { class: 'ico' }, '💬');
    var txt = ce('div', { class: 'text' });
    txt.appendChild(ce('div', { class: 'ttl' }, '当前客服暂不在线'));
    txt.appendChild(ce('div', { class: 'desc' }, '请留下您的问题和联系方式，客服上线后会尽快联系您'));
    header.appendChild(ico); header.appendChild(txt);
    div.appendChild(header);

    // 姓名
    div.appendChild(buildField({
      id: 'kw-lm-name', label: '您的姓名', type: 'text', required: true,
      placeholder: '请输入您的姓名', value: state.visitor.name || ''
    }));
    // 手机 + 邮箱 一行
    var row = ce('div', { class: 'row' });
    row.appendChild(buildField({
      id: 'kw-lm-phone', label: '手机号', type: 'tel', required: true,
      placeholder: '便于客服联系'
    }));
    row.appendChild(buildField({
      id: 'kw-lm-email', label: '邮箱（可选）', type: 'email', required: false,
      placeholder: '回复通知邮箱'
    }));
    div.appendChild(row);
    // 留言内容
    div.appendChild(buildField({
      id: 'kw-lm-content', label: '留言内容', type: 'textarea', required: true,
      placeholder: '请详细描述您的问题或需求，方便客服更好地为您服务…'
    }));

    // 操作按钮
    var actions = ce('div', { class: 'actions' });
    var btn = ce('button', { id: 'kw-lm-submit' }, '📨 提交留言');
    btn.onclick = submitLeave;
    actions.appendChild(btn);
    div.appendChild(actions);

    // 提示
    var tip = ce('div', { style: 'font-size:11px;color:#94A3B8;margin-top:10px;text-align:center;line-height:1.5' },
      '🔒 我们将妥善保管您的联系方式，仅用于本次客服沟通');
    div.appendChild(tip);

    form.appendChild(div);
  }

  /**
   * 构建表单字段
   */
  function buildField(opt) {
    var f = ce('div', { class: 'field' + (opt.required ? ' req' : '') });
    var label = ce('label', { for: opt.id });
    var labelText = ce('span', null, opt.label);
    label.appendChild(labelText);
    if (opt.required) label.appendChild(ce('span', { class: 'req-star' }, '*'));
    f.appendChild(label);
    var input;
    if (opt.type === 'textarea') {
      input = ce('textarea', { id: opt.id, placeholder: opt.placeholder || '', rows: 3 });
    } else {
      input = ce('input', { id: opt.id, type: opt.type || 'text', placeholder: opt.placeholder || '' });
      if (opt.value) input.value = opt.value;
    }
    f.appendChild(input);
    f.appendChild(ce('div', { class: 'err' }, ''));
    return f;
  }

  /**
   * 校验表单字段
   * @returns {boolean} 是否通过
   */
  function validateLeaveForm() {
    var ok = true;
    var fields = $('#kw-form').querySelectorAll('.field');
    fields.forEach(function (f) {
      var input = f.querySelector('input, textarea');
      if (!input) return;
      var err = f.querySelector('.err');
      f.classList.remove('has-error');
      // 必填校验
      if (f.classList.contains('req')) {
        if (!input.value.trim()) {
          f.classList.add('has-error');
          if (err) err.textContent = (input.placeholder || '此项') + ' 必填';
          ok = false;
          return;
        }
      }
      // 手机号格式
      if (input.id === 'kw-lm-phone' && input.value.trim()) {
        if (!/^[\d\-\+\s]{6,20}$/.test(input.value.trim())) {
          f.classList.add('has-error');
          if (err) err.textContent = '手机号格式不正确';
          ok = false;
        }
      }
      // 邮箱格式
      if (input.id === 'kw-lm-email' && input.value.trim()) {
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value.trim())) {
          f.classList.add('has-error');
          if (err) err.textContent = '邮箱格式不正确';
          ok = false;
        }
      }
    });
    return ok;
  }

  function renderLeaveSuccess(ticketNo) {
    var form = $('#kw-form');
    if (!form) return;
    form.innerHTML = '<div class="kw-leave"><div class="success-state">' +
      '<div class="ico-big">✅</div>' +
      '<div class="ttl">留言已提交成功</div>' +
      '<div class="desc">感谢您的留言！客服上线后会尽快通过您留下的方式联系您</div>' +
      (ticketNo ? '<div class="ticket">工单号：' + escHtml(ticketNo) + '</div>' : '') +
      '</div></div>';
  }
  function escHtml(s) { return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

  async function submitLeave() {
    if (!validateLeaveForm()) return;
    var name = $('#kw-lm-name').value.trim();
    var email = ($('#kw-lm-email') || {}).value || '';
    var phone = $('#kw-lm-phone').value.trim();
    var content = $('#kw-lm-content').value.trim();
    var btn = $('#kw-lm-submit');
    btn.disabled = true;
    btn.textContent = '提交中…';
    try {
      var r = await api('/api/visitor/leave-message', 'POST', {
        visitor_name: name,
        visitor_email: email.trim(),
        visitor_phone: phone,
        content: content,
        session_id: state.sessionId,
        visitor_id: state.visitorId,
        source: 'widget',
      });
      if (r.code === 0) {
        state.visitor.name = name; saveIds();
        renderLeaveSuccess(r.data && r.data.ticket_no);
      } else {
        btn.disabled = false; btn.textContent = '📨 提交留言';
        alert('提交失败：' + r.msg);
      }
    } catch (e) {
      btn.disabled = false; btn.textContent = '📨 提交留言';
      alert('网络异常：' + e.message);
    }
  }

  function openPanel() {
    if (!$('#kw-panel')) renderPanel();
    state.opened = true;
    $('#kw-panel').classList.add('open');
    applyPosition(state.config);
    applyHeader(state.config);
    bootstrap();
    setTimeout(function () { try { $('#kw-input') && $('#kw-input').focus(); } catch (e) {} }, 100);
  }
  function closePanel() {
    state.opened = false;
    $('#kw-panel') && $('#kw-panel').classList.remove('open');
  }

  async function bootstrap() {
    await checkOnline();
    applyHeader(state.config);
    var info = await loadSessionInfo();
    var sess = info && info.session;
    // serving_mode === 'message' 表示已转留言模式（无客服）
    var isMessageMode = sess && sess.serving_mode === 'message';
    if (sess && sess.id && !isMessageMode) {
      // 已有正常会话：拉历史
      await fetchHistory();
      renderHistory(state.messages);
      renderChatForm();
    } else if (isMessageMode) {
      // 已是留言模式：渲染留言表单
      await fetchHistory();
      renderHistory(state.messages);
      renderLeaveForm();
    } else {
      // 没会话：尝试创建
      var cr = await ensureSession();
      if (cr.code === 0 && cr.data) {
        if (cr.data.session) state.sessionId = cr.data.session.session_id || state.sessionId;
        if (cr.data.session_id) state.sessionId = cr.data.session_id;
        saveIds();
        var noAgent = cr.data.session && cr.data.session.auto_offline;
        var modeMsg = cr.data.serving_mode === 'message';
        if (noAgent || modeMsg) {
          renderHistory([]);
          renderLeaveForm();
        } else {
          await fetchHistory();
          renderHistory(state.messages);
          renderChatForm();
        }
      } else {
        renderHistory([]);
        renderLeaveForm();
      }
    }
    startPolling();
  }

  function startPolling() {
    if (state.pollTimer) clearInterval(state.pollTimer);
    state.pollTimer = setInterval(pollNew, 3000);
  }
  function stopPolling() {
    if (state.pollTimer) clearInterval(state.pollTimer);
    state.pollTimer = null;
  }

  let lastMsgTime = null;
  async function pollNew() {
    var url = '/api/visitor/message/poll?session_id=' + state.sessionId;
    if (lastMsgTime) url += '&since=' + encodeURIComponent(lastMsgTime);
    var r = await api(url, 'GET');
    if (r.code === 0 && r.data && r.data.messages) {
      var newMsgs = r.data.messages || [];
      var switchedToMsg = false;
      newMsgs.forEach(function (m) {
        renderMessage(m);
        if (m.sender_type !== 'visitor') {
          var badge = $('#kw-fab-badge');
          if (badge && !state.opened) {
            badge.textContent = (parseInt(badge.textContent) || 0) + 1;
            badge.classList.add('show');
          }
        }
        // 检测到 system 消息"已转留言"时切换到留言表单
        if (m.sender_type === 'system' && /留言|请留下/.test(m.content || '')) {
          switchedToMsg = true;
        }
        lastMsgTime = m.created_at || lastMsgTime;
      });
      if (switchedToMsg && $('#kw-input')) {
        renderLeaveForm();
      }
    }
  }

  // 初始化
  async function init(config) {
    config = config || {};
    state.config = Object.assign({
      tenantId: 1,
      position: 'bottom-right',
      offset_x: 24,
      offset_y: 24,
      welcome_text: '您好，欢迎来到客服中心',
      primary_color: '#0EA5E9',
      text_color: '#1F2937',
      require_user_info: false,  // true 时先弹收集访客信息表单
      autoOpen: false,           // 默认是否展开对话窗口（嵌入网站时建议 true）
    }, config);
    // API 模式可以从 init 传入 name/avatar
    if (config.name) state.visitor.name = config.name;
    if (config.avatar) state.visitor.avatar = config.avatar;
    saveIds();

    injectCss();
    setCssVars(state.config);

    // 拉取样式配置（覆盖默认值）
    try {
      var s = await fetchStyle();
      if (s && Object.keys(s).length) {
        Object.keys(s).forEach(function (k) {
          if (s[k] !== '' && s[k] !== null && s[k] !== undefined) {
            state.config[k] = s[k];
          }
        });
        setCssVars(state.config);
      }
    } catch (e) {}

    renderFab();
    renderPanel();
    applyPosition(state.config);

    // 收集访客自定义信息（如果开启）
    if (state.config.require_user_info) {
      KefuWidget.submitCustomFields({}).then(function () {
        if (state.config.autoOpen) openPanel();
      });
    } else if (state.config.autoOpen) {
      openPanel();
    }
  }

  // 暴露 API
  window.KefuWidget = {
    init: init,
    open: openPanel,
    close: closePanel,
    toggle: function () { state.opened ? closePanel() : openPanel(); },
    setVisitor: function (info) {
      info = info || {};
      if (info.name) state.visitor.name = info.name;
      if (info.avatar) state.visitor.avatar = info.avatar;
      if (info.meta) state.visitor.meta = info.meta;
      saveIds();
      // 同步到后端会话
      ensureSession(info.meta || {}).then(function () {
        applyHeader(state.config);
        renderChatForm();
      });
    },
    ensureSession: ensureSession,
    submitCustomFields: function (values) {
      return api('/api/visitor/field/save', 'POST', {
        visitor_id: state.visitorId,
        session_id: state.sessionId,
        values: values || {},
      });
    },
    sendMessage: function (text) { return sendText(text); },
    on: on,
    version: VERSION,
  };
})();