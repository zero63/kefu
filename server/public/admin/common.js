/* ==========================================================================
 *  kefu 后台公共 JS
 *  依赖：fetch + ES6
 *  用法：<script src="/admin/common.js"></script>
 * ========================================================================== */
(function (global) {
  const KefuAdmin = {
    token: null,
    user: null,

    /* ============================ 鉴权 ============================ */
    async login(username, password, tenantCode) {
      const r = await fetch('/api/common/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, password, tenant_code: tenantCode || 'demo' }),
      }).then(x => x.json());
      if (r.code !== 0) throw new Error(r.msg);
      this.token = r.data.token;
      this.user = r.data.user;
      // 统一存到 kefu_token + kefu_user
      localStorage.setItem('kefu_token', this.token);
      localStorage.setItem('kefu_user', JSON.stringify(this.user));
      return this.user;
    },

    restore() {
      // 修复：iframe 内时，从父窗口继承 token / user
      try {
        if (window.parent !== window && window.parent.localStorage) {
          this.token = window.parent.localStorage.getItem('kefu_token') || this.token;
          const parentUser = window.parent.localStorage.getItem('kefu_user');
          if (parentUser) {
            try { this.user = JSON.parse(parentUser); } catch (e) {}
          }
        }
      } catch (e) { /* 跨域时 parent 不可访问，忽略 */ }
      if (!this.token) this.token = localStorage.getItem('kefu_token');
      if (!this.user) {
        try { this.user = JSON.parse(localStorage.getItem('kefu_user') || 'null'); } catch (e) {}
      }
      return this.token;
    },

    logout() {
      localStorage.removeItem('kefu_token');
      localStorage.removeItem('kefu_user');
      location.reload();
    },

    /* ============================ HTTP ============================ */
    async api(method, url, body, opts) {
      opts = opts || {};
      const sep = url.indexOf('?') >= 0 ? '&' : '?';
      const realUrl = opts.token === false ? url : (url + (this.token ? sep + 'token=' + this.token : ''));
      const headers = { 'Content-Type': 'application/json' };
      if (opts.token !== false && this.token) headers['Authorization'] = 'Bearer ' + this.token;
      const o = { method, headers };
      if (body !== undefined && body !== null) o.body = JSON.stringify(body);
      const r = await fetch(realUrl, o);
      const txt = await r.text();
      try { return JSON.parse(txt); }
      catch (e) { return { code: r.status, msg: 'parse error', raw: txt }; }
    },

    /* ============================ 工具 ============================ */
    qs(sel, root) { return (root || document).querySelector(sel); },
    qsa(sel, root) { return Array.from((root || document).querySelectorAll(sel)); },
    el(tag, attrs, children) {
      const e = document.createElement(tag);
      if (attrs) for (const k in attrs) {
        if (k === 'class') e.className = attrs[k];
        else if (k === 'html') e.innerHTML = attrs[k];
        else if (k === 'text') e.textContent = attrs[k];
        else if (k.startsWith('on')) e[k] = attrs[k];
        else e.setAttribute(k, attrs[k]);
      }
      if (children) (Array.isArray(children) ? children : [children]).forEach(c => e.appendChild(c));
      return e;
    },

    /* ============================ Toast ============================ */
    toast(msg, type) {
      const t = this.el('div', { class: 'k-toast k-toast-' + (type || 'info'), text: msg });
      document.body.appendChild(t);
      setTimeout(() => t.classList.add('show'), 10);
      setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 2400);
    },

    /* ============================ Modal ============================ */
    openModal(title, bodyHTML, onConfirm) {
      let modal = this.qs('#k-modal-bg');
      if (modal) modal.remove();
      modal = this.el('div', { class: 'k-modal-bg', id: 'k-modal-bg' });
      modal.innerHTML = `
        <div class="k-modal">
          <div class="k-modal-title">
            <span>${title}</span>
            <span class="k-modal-close">&times;</span>
          </div>
          <div class="k-modal-body" id="modal-form-body">${bodyHTML}</div>
          <div class="k-modal-foot">
            <button class="k-btn k-btn-secondary" data-act="cancel">取消</button>
            <button class="k-btn k-btn-primary" data-act="ok">确定</button>
          </div>
        </div>`;
      document.body.appendChild(modal);
      modal.querySelector('.k-modal-close').onclick = () => { modal.remove(); if (onConfirm) onConfirm(false); };
      modal.querySelector('[data-act="cancel"]').onclick = () => { modal.remove(); if (onConfirm) onConfirm(false); };
      modal.querySelector('[data-act="ok"]').onclick = async () => {
        const r = onConfirm && await onConfirm(true);
        if (r !== false) modal.remove();
      };
    },

    confirm(message) {
      return window.confirm(message);
    },

    /* ============================ 登录检查 ============================ */
    requireLogin() {
      this.restore();
      if (!this.token) {
        if (location.pathname.indexOf('login.html') < 0) {
          // 在 iframe 内时，redirect 整个窗口（清理 next 参数中的 hash / search）
          const cleanPath = (path, search) => {
            // 仅保留以 /admin/ 开头的相对路径，避免外跳
            let p = (path || '/admin/index.html').replace(/[<>"']/g, '');
            if (p.indexOf('/admin') !== 0) p = '/admin/index.html';
            // 过滤 search 中可能含中文乱码的字段
            const safe = [];
            if (search) {
              const params = new URLSearchParams(search);
              for (const [k, v] of params.entries()) {
                if (/^[\w\-=&%.]+$/.test(k + '=' + v)) safe.push(k + '=' + v);
              }
            }
            return p + (safe.length ? '?' + safe.join('&') : '');
          };
          try {
            if (window.parent !== window) {
              const next = encodeURIComponent(cleanPath(window.parent.location.pathname, window.parent.location.search));
              window.parent.location.href = '/admin/login.html?next=' + next;
              return false;
            }
          } catch (e) {}
          location.href = '/admin/login.html?next=' + encodeURIComponent(cleanPath(location.pathname, location.search));
        }
        return false;
      }
      return true;
    },

    /* ============================ 表单 ============================ */
    formToObject(formEl) {
      const out = {};
      this.qsa('input,select,textarea', formEl).forEach(el => {
        if (el.type === 'checkbox') out[el.name] = el.checked ? 1 : 0;
        else if (el.type === 'radio') { if (el.checked) out[el.name] = el.value; }
        else out[el.name] = el.value;
      });
      return out;
    },

    /* ============================ 日期工具 ============================ */
    today() { return new Date().toISOString().slice(0, 10); },
    daysAgo(n) { const d = new Date(); d.setDate(d.getDate() - n); return d.toISOString().slice(0, 10); },
    fmtDate(d) {
      if (!d) return '-';
      const dt = typeof d === 'string' ? new Date(d.replace ? d.replace(' ', 'T') : d) : d;
      if (isNaN(dt)) return d;
      const p = n => String(n).padStart(2, '0');
      return dt.getFullYear() + '-' + p(dt.getMonth()+1) + '-' + p(dt.getDate()) + ' ' + p(dt.getHours()) + ':' + p(dt.getMinutes());
    },

    /* ============================ 分页 ============================ */
    renderPagination(total, page, size, onChange) {
      const pages = Math.max(1, Math.ceil(total / size));
      const div = this.el('div', { class: 'k-pager' });
      div.appendChild(this.el('span', { text: '共 ' + total + ' 条' }));
      const prev = this.el('button', { class: 'k-btn-mini', text: '上一页' });
      prev.disabled = page <= 1;
      prev.onclick = () => onChange(page - 1);
      div.appendChild(prev);
      div.appendChild(this.el('span', { text: ' ' + page + '/' + pages + ' ', class: 'k-pageinfo' }));
      const next = this.el('button', { class: 'k-btn-mini', text: '下一页' });
      next.disabled = page >= pages;
      next.onclick = () => onChange(page + 1);
      div.appendChild(next);
      return div;
    }
  };

  global.KefuAdmin = KefuAdmin;
})(window);