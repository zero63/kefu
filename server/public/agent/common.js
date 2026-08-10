/* kefu 客服工作台公共 JS */
(function (global) {
  const K = {
    token: null, user: null, employeeId: 0,

    async login(u, p, t) {
      const r = await fetch('/api/common/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: u, password: p, tenant_code: t || 'demo' }),
      }).then(x => x.json());
      if (r.code !== 0) throw new Error(r.msg);
      this.token = r.data.token;
      this.user = r.data.user;
      this.employeeId = r.data.user.employee_id || r.data.user.id || 0;
      // 统一存到 kefu_token + kefu_user（与 admin/login.html 一致）
      localStorage.setItem('kefu_token', this.token);
      localStorage.setItem('kefu_user', JSON.stringify(this.user));
      return this.user;
    },

    restore() {
      // 修复：iframe 内时，优先从父窗口继承 token / user
      try {
        if (window.parent !== window && window.parent.localStorage) {
          const parentToken = window.parent.localStorage.getItem('kefu_token');
          if (parentToken && !this.token) this.token = parentToken;
          const parentUser = window.parent.localStorage.getItem('kefu_user');
          if (parentUser && !this.user) {
            try { this.user = JSON.parse(parentUser); } catch (e) {}
          }
          // 同步回 iframe 自己的 localStorage（备用）
          if (parentToken) {
            try { localStorage.setItem('kefu_token', parentToken); } catch (e) {}
          }
          if (parentUser) {
            try { localStorage.setItem('kefu_user', parentUser); } catch (e) {}
          }
        }
      } catch (e) { /* 跨域时 parent 不可访问，忽略 */ }
      if (!this.token) this.token = localStorage.getItem('kefu_token');
      if (!this.user) {
        try { this.user = JSON.parse(localStorage.getItem('kefu_user') || 'null'); } catch (e) {}
      }
      this.employeeId = this.user ? (this.user.employee_id || this.user.id || 0) : 0;
      return this.token;
    },

    logout() {
      localStorage.removeItem('kefu_token');
      localStorage.removeItem('kefu_user');
      location.href = '/agent/login.html';
    },

    async api(method, url, body) {
      const sep = url.indexOf('?') >= 0 ? '&' : '?';
      const realUrl = url + (this.token ? sep + 'token=' + this.token : '');
      const headers = { 'Content-Type': 'application/json' };
      if (this.token) headers['Authorization'] = 'Bearer ' + this.token;
      const o = { method, headers };
      if (body !== undefined && body !== null) o.body = JSON.stringify(body);
      const r = await fetch(realUrl, o);
      const txt = await r.text();
      try { return JSON.parse(txt); } catch (e) {
        // 修复：返回更详细的错误信息（前端能正确识别是 token 过期 / 接口不存在）
        if (r.status === 401) return { code: 401, msg: '登录已过期，请重新登录', raw: txt };
        if (r.status === 404) return { code: 404, msg: '接口不存在：' + url, raw: txt };
        return { code: r.status || -1, msg: '响应解析失败（HTTP ' + r.status + '）', raw: txt.slice(0, 200) };
      }
    },

    qs(s, r) { return (r || document).querySelector(s); },
    qsa(s, r) { return Array.from((r || document).querySelectorAll(s)); },
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

    toast(msg, type) {
      const t = this.el('div', { class: 'k-toast k-toast-' + (type || 'info'), text: msg });
      document.body.appendChild(t);
      setTimeout(() => t.classList.add('show'), 10);
      setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 2400);
    },

    requireLogin() {
      this.restore();
      if (!this.token) {
        if (location.pathname.indexOf('login.html') < 0) {
          // 在 iframe 内时，redirect 整个窗口；否则在当前页跳转
          try { if (window.parent !== window) { window.parent.location.href = '/agent/login.html?next=' + encodeURIComponent(window.parent.location.pathname + window.parent.location.search); return false; } } catch (e) {}
          location.href = '/agent/login.html';
        }
        return false;
      }
      return true;
    },

    fmtDate(d) {
      if (!d) return '-';
      const dt = typeof d === 'string' ? new Date(d.replace ? d.replace(' ', 'T') : d) : d;
      if (isNaN(dt)) return d;
      const p = n => String(n).padStart(2, '0');
      return dt.getFullYear() + '-' + p(dt.getMonth()+1) + '-' + p(dt.getDate()) + ' ' + p(dt.getHours()) + ':' + p(dt.getMinutes());
    },
    escapeHtml(s) {
      return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
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
  global.KefuAgent = K;
})(window);