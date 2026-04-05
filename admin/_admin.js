// /admin/_admin.js
// Правки:
// - window.Admin создаётся сразу (чтобы не ловить «Admin is not defined» из‑за порядка/кэша/ошибок загрузки)
// - initAdminHeader теперь принимает одну ИЛИ несколько ролей: initAdminHeader('operator') или initAdminHeader('organizer','operator')
//   (это решает кейс «organizer (админ) заходит в operator‑страницы»)
(function () {
  'use strict';

  // Создаём глобальный объект сразу, чтобы любая страница могла обращаться к window.Admin
  // даже если дальше что-то упадёт (или порядок загрузки будет нестандартным).
  const Admin = (window.Admin && typeof window.Admin === 'object') ? window.Admin : {};
  window.Admin = Admin;

  const API_ME = '/api/tournament/auth/me.php';
  const API_LOGIN = '/api/tournament/auth/login.php';
  const API_LOGOUT = '/api/tournament/auth/logout.php';

  const HOME_BY_ROLE = {
    organizer: '/admin/organizer/',
    operator:  '/admin/operator/',
  };

  function qs(name) {
    return new URLSearchParams(location.search).get(name) || '';
  }

  function safeNext(next) {
    next = String(next || '').trim();
    // разрешаем редирект только внутрь админки
    if (!next.startsWith('/admin/')) return '';
    // без протоколов/домена
    if (next.includes('://')) return '';
    return next;
  }

  function toLogin(opts = {}) {
    const url = new URL('/admin/login.html', location.origin);

    if (opts.logout) {
      url.searchParams.set('logout', '1');
    } else {
      const next = safeNext(opts.next || (location.pathname + location.search));
      if (next) url.searchParams.set('next', next);
    }
    location.href = url.pathname + url.search;
  }

  async function readJson(res) {
    const txt = await res.text();
    let j = null;
    try { j = JSON.parse(txt); } catch (e) {}

    if (!res.ok) {
      const msg = (j && (j.error || j.details)) ? (j.error || j.details) : (txt.slice(0, 300) || ('HTTP ' + res.status));
      const err = new Error(msg);
      err.httpStatus = res.status;
      err.payload = j;
      throw err;
    }
    return j;
  }

  async function me({ redirectOn401 = true } = {}) {
    try {
      const res = await fetch(API_ME, { credentials: 'same-origin', cache: 'no-store' });
      const j = await readJson(res);
      if (!j || !j.ok) return null;
      return j.user || null; // {login, role}
    } catch (e) {
      if (e.httpStatus === 401 && redirectOn401) {
        toLogin({ next: location.pathname + location.search });
        return null;
      }
      // 403/500 показываем как есть — пусть страница решит
      throw e;
    }
  }

  function routeAfterLogin(user, nextFromQuery) {
    const next = safeNext(nextFromQuery);
    if (next) return next;
    const r = String(user && user.role || '');
    return HOME_BY_ROLE[r] || '/admin/';
  }

  async function login(loginStr, passwordStr, opts = {}) {
    const login = String(loginStr || '').trim();
    const password = String(passwordStr || '');

    if (!login || !password) {
      throw new Error('Укажи логин и пароль');
    }

    const res = await fetch(API_LOGIN, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ login, password })
    });

    const j = await readJson(res);
    if (!j || !j.ok) throw new Error(j && j.error ? j.error : 'Login failed');

    const user = j.user || {};
    // если logout=1 — игнорируем next (чтобы не улетать обратно сразу)
    const next = (qs('logout') === '1') ? '' : (opts.next || qs('next'));
    location.href = routeAfterLogin(user, next);
    return user;
  }

  async function logout() {
    try {
      await fetch(API_LOGOUT, { method: 'POST', credentials: 'same-origin', cache: 'no-store' }).catch(() => null);
    } finally {
      toLogin({ logout: true });
    }
  }

function markActiveNav() {
  const path = location.pathname;
  document.querySelectorAll('#adminNav a, #adminNavMobile a, [data-admin-nav] a').forEach(a => {
    const href = a.getAttribute('href') || '';
    if (href === path) {
      const li = a.closest('li');
      if (li) li.classList.add('uk-active');
    }
  });
}


  function setUserText(user) {
    const txt = user ? `${user.login} (${user.role})` : '—';
    const el1 = document.getElementById('adminUser');
    const el2 = document.getElementById('adminUserMobile');
    const el3 = document.getElementById('who');
    if (el1) el1.textContent = txt;
    if (el2) el2.textContent = txt;
    if (el3) el3.textContent = user ? `Вы: ${txt}` : '—';
  }

  
  function bindSettings(user) {
    // показываем шестерёнку только организатору
    if (!user || String(user.role||'') !== 'organizer') return;

    const settingsUrl = '/admin/organizer/settings.html';
    const btnLogout = document.getElementById('btnLogout');
    if (btnLogout && !document.getElementById('btnSettings')) {
      const a = document.createElement('a');
      a.id = 'btnSettings';
      a.href = settingsUrl;
      a.className = 'uk-button uk-button-default';
      a.innerHTML = '<span uk-icon="cog"></span>';
      a.title = 'Настройки';
      btnLogout.parentNode.insertBefore(a, btnLogout);
    }

    const btnLogoutM = document.getElementById('btnLogoutMobile');
    if (btnLogoutM && !document.getElementById('btnSettingsMobile')) {
      const a = document.createElement('a');
      a.id = 'btnSettingsMobile';
      a.href = settingsUrl;
      a.className = 'uk-button uk-button-default';
      a.innerHTML = '<span uk-icon="cog"></span>';
      a.title = 'Настройки';
      // в мобильном меню обычно кнопка - вставим перед logout
      btnLogoutM.parentNode.insertBefore(a, btnLogoutM);
    }
  }
function bindLogout() {
    const b1 = document.getElementById('btnLogout');
    const b2 = document.getElementById('btnLogoutMobile');
    if (b1) b1.addEventListener('click', logout);
    if (b2) b2.addEventListener('click', (e) => { e.preventDefault(); logout(); });
  }

  async function initAdminHeader(...needRoles) {
    // поддержка обоих вариантов:
    // 1) initAdminHeader('operator')
    // 2) initAdminHeader('organizer','operator')
    // 3) initAdminHeader(['organizer','operator'])
    if (needRoles.length === 1 && Array.isArray(needRoles[0])) needRoles = needRoles[0];
    needRoles = (needRoles || []).map(String).map(s => s.trim()).filter(Boolean);

    const user = await me({ redirectOn401: true });
    if (!user) return;

    // если зашёл “не туда” — перекидываем в свою админку
    if (needRoles.length && !needRoles.includes(user.role)) {
      location.href = HOME_BY_ROLE[user.role] || '/admin/';
      return;
    }

    window.Admin.applyRoleVisibility(user.role);

    setUserText(user);
    bindSettings(user);
    bindLogout();
    markActiveNav();
  }

  // Инициализация логина (чтобы на странице login.html было “просто работает”)
  async function initLoginPage() {
    const isLogout = (qs('logout') === '1');

    // если уже залогинен — отправляем в нужное место (НО не при logout=1)
    if (!isLogout) {
      try {
        const user = await me({ redirectOn401: false });
        if (user) {
          location.href = routeAfterLogin(user, qs('next'));
          return;
        }
      } catch (e) {
        // молча — на логине это не критично
      }
    }

    const inpLogin = document.querySelector('#login, input[name="login"]');
    const inpPass  = document.querySelector('#password, input[name="password"]');
    const btn      = document.querySelector('#btnLogin, button[type="submit"], .js-login');
    const errBox   = document.querySelector('#err, #error, .js-error');

    async function onDoLogin() {
      if (errBox) errBox.textContent = '';
      try {
        await login(inpLogin ? inpLogin.value : '', inpPass ? inpPass.value : '');
      } catch (e) {
        if (errBox) errBox.textContent = (e && e.message) ? e.message : 'Ошибка входа';
      }
    }

    if (btn) {
      btn.addEventListener('click', (e) => { e.preventDefault(); onDoLogin(); });
    }

    // enter
    [inpLogin, inpPass].filter(Boolean).forEach(el => {
      el.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); onDoLogin(); }
      });
    });
  }

function applyRoleVisibility(role) {
  role = String(role || '').trim();

  document.querySelectorAll('[data-show-for]').forEach(el => {
    const allow = String(el.getAttribute('data-show-for') || '')
      .split(',')
      .map(s => s.trim())
      .filter(Boolean);

    // если атрибут пустой — на всякий покажем
    const visible = !allow.length || allow.includes(role);
    el.style.display = visible ? '' : 'none';
  });
}


  async function requireRole(roles) {
    const user = await me({ redirectOn401: true });
    if (!user) return null;

    if (Array.isArray(roles) && roles.length && !roles.includes(user.role)) {
      try {
        if (window.UIkit && UIkit.notification) {
          UIkit.notification({
            message: 'Недостаточно прав: нужен ' + roles.join(', '),
            status: 'danger',
            pos: 'top-right',
            timeout: 2500
          });
        }
      } catch (e) {}
      // вернем на главную админки (или можно на operator)
      location.href = '/admin/';
      return null;
    }

    return user;
  }

  // export
  window.Admin = window.Admin || {};
  window.Admin.me = me;
  window.Admin.login = login;
  window.Admin.logout = logout;
  window.Admin.requireRole = requireRole;
  window.Admin.initAdminHeader = initAdminHeader;
  window.Admin.initLoginPage = initLoginPage;
  window.Admin.applyRoleVisibility = applyRoleVisibility;

  // --- compat aliases (чтобы страницы могли использовать Admin.apiMe/apiGet/apiPost) ---
  window.Admin.apiMe = window.Admin.apiMe || (async function () {
    const res = await fetch(API_ME, { credentials: 'same-origin', cache: 'no-store' });
    const j = await readJson(res);
    if (!j || !j.ok) throw new Error((j && (j.error || j.details)) || 'me failed');
    return j;
  });

  window.Admin.apiGet = window.Admin.apiGet || (async function (url) {
    const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
    return await readJson(res);
  });

  window.Admin.apiPost = window.Admin.apiPost || (async function (url, body) {
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {})
    });
    return await readJson(res);
  });

})();
