/* ==========================================================================
 *  kefu 跨界面 UI 工具库 (KefuUI)
 *  依赖：design-system.css 必须先加载
 * ========================================================================== */
(function (global) {
  const UI = {
    /* =================== Toast =================== */
    toast(msg, type, ms) {
      type = type || 'info';
      const t = document.createElement('div');
      t.className = 'k-toast k-toast-' + type;
      t.textContent = msg;
      document.body.appendChild(t);
      requestAnimationFrame(() => t.classList.add('show'));
      setTimeout(() => {
        t.classList.remove('show');
        setTimeout(() => t.remove(), 300);
      }, ms || 2400);
      return t;
    },
    success(msg) { return this.toast(msg, 'success'); },
    error  (msg) { return this.toast(msg, 'error', 4000); },
    warn   (msg) { return this.toast(msg, 'warning'); },
    info   (msg) { return this.toast(msg, 'info'); },

    /* =================== Modal =================== */
    modal(title, bodyHTML, onConfirm) {
      const old = document.getElementById('k-modal-bg');
      if (old) old.remove();
      const bg = document.createElement('div');
      bg.className = 'k-modal-bg'; bg.id = 'k-modal-bg';
      bg.innerHTML = `<div class="k-modal">
        <div class="k-modal-title"><span></span><span class="k-modal-close">×</span></div>
        <div class="k-modal-body" id="modal-form-body"></div>
        <div class="k-modal-foot">
          <button class="k-btn k-btn-secondary" data-act="cancel">取消</button>
          <button class="k-btn k-btn-primary" data-act="ok">确定</button>
        </div>
      </div>`;
      bg.querySelector('.k-modal-title span:first-child').textContent = title || '提示';
      bg.querySelector('#modal-form-body').innerHTML = bodyHTML || '';
      document.body.appendChild(bg);
      bg.querySelector('.k-modal-close').onclick = () => { bg.remove(); if (onConfirm) onConfirm(false); };
      bg.querySelector('[data-act="cancel"]').onclick = () => { bg.remove(); if (onConfirm) onConfirm(false); };
      bg.querySelector('[data-act="ok"]').onclick = async () => {
        const r = onConfirm && await onConfirm(true);
        if (r !== false) bg.remove();
      };
      return bg;
    },
    confirm(message) { return window.confirm(message); },

    /* =================== Spinner (按钮内) =================== */
    btnLoading(btn, on) {
      if (on) {
        btn.dataset._text = btn.textContent;
        btn.textContent = '处理中...';
        btn.disabled = true;
      } else {
        btn.textContent = btn.dataset._text || btn.textContent;
        btn.disabled = false;
      }
    },

    /* =================== Format =================== */
    fmtDate(d) {
      if (!d) return '-';
      const dt = typeof d === 'string' ? new Date(d.replace ? d.replace(' ', 'T') : d) : d;
      if (isNaN(dt)) return d;
      const p = n => String(n).padStart(2, '0');
      return dt.getFullYear() + '-' + p(dt.getMonth()+1) + '-' + p(dt.getDate()) + ' ' + p(dt.getHours()) + ':' + p(dt.getMinutes());
    },
    today() { return new Date().toISOString().slice(0, 10); },
    daysAgo(n) { const d = new Date(); d.setDate(d.getDate() - n); return d.toISOString().slice(0, 10); },
    escapeHtml(s) {
      return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    },

    /* =================== HTTP (wrapper for fetch) =================== */
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
      try { return JSON.parse(txt); } catch (e) { return { code: r.status, msg: txt }; }
    },

    /* =================== Pagination =================== */
    renderPagination(total, page, size, onChange) {
      const pages = Math.max(1, Math.ceil(total / size));
      const div = document.createElement('div'); div.className = 'k-pager';
      div.appendChild(this.el('span', { class: 'info', text: '共 ' + total + ' 条' }));
      const prev = this.el('button', { class: 'k-btn-mini', text: '上一页' });
      prev.disabled = page <= 1;
      prev.onclick = () => onChange(page - 1);
      div.appendChild(prev);
      div.appendChild(this.el('span', { class: 'info', text: ' ' + page + ' / ' + pages + ' ' }));
      const next = this.el('button', { class: 'k-btn-mini', text: '下一页' });
      next.disabled = page >= pages;
      next.onclick = () => onChange(page + 1);
      div.appendChild(next);
      return div;
    },

    /* =================== el() — safe DOM build =================== */
    el(tag, attrs, children) {
      const e = document.createElement(tag);
      if (attrs) for (const k in attrs) {
        if (k === 'class') e.className = attrs[k];
        else if (k === 'html') e.innerHTML = attrs[k];
        else if (k === 'text') e.textContent = attrs[k];
        else if (k.startsWith('on')) e[k.toLowerCase()] = attrs[k];
        else e.setAttribute(k, attrs[k]);
      }
      if (children) (Array.isArray(children) ? children : [children]).forEach(c => e.appendChild(c));
      return e;
    },
    qs(s, r) { return (r || document).querySelector(s); },
    qsa(s, r) { return Array.from((r || document).querySelectorAll(s)); },

    /* =================== Form to Object =================== */
    formToObject(root) {
      const out = {};
      this.qsa('input,select,textarea', root).forEach(el => {
        if (!el.name) return;
        if (el.type === 'checkbox') out[el.name] = el.checked ? 1 : 0;
        else if (el.type === 'radio') { if (el.checked) out[el.name] = el.value; }
        else out[el.name] = el.value;
      });
      return out;
    },

    /* =================== Avatar =================== */
    avatar(name, color) {
      const c = color || '#1989fa';
      return '<span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:' + c + ';color:#fff;font-weight:600;font-size:13px;">' + (name || '?').slice(0, 1).toUpperCase() + '</span>';
    },
  };
  global.UI = global.KefuUI = UI;
})(window);