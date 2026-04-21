/**
 * player-modal.js
 * Shared player profile modal for lobby and game pages.
 *
 * Requires window.APP_BASE_PATH (e.g. "/45s") and window.APP_CSRF_TOKEN
 * to be set by the host page before this script runs.
 *
 * Public API:
 *   openPlayerModal(userId)   — open the modal for any player
 *   closePlayerModal()        — close it
 */

(function () {
  'use strict';

  // ── Avatar definitions ────────────────────────────────────────────────────
  const AVATARS = [
    { code: 's-teal',   bg: '#0d6f66', sym: '♠' },
    { code: 'h-teal',   bg: '#0d6f66', sym: '♥' },
    { code: 'd-teal',   bg: '#0d6f66', sym: '♦' },
    { code: 'c-teal',   bg: '#0d6f66', sym: '♣' },
    { code: 's-amber',  bg: '#c26a10', sym: '♠' },
    { code: 'h-amber',  bg: '#c26a10', sym: '♥' },
    { code: 'd-amber',  bg: '#c26a10', sym: '♦' },
    { code: 'c-amber',  bg: '#c26a10', sym: '♣' },
    { code: 's-purple', bg: '#6b21a8', sym: '♠' },
    { code: 'h-purple', bg: '#6b21a8', sym: '♥' },
    { code: 'd-purple', bg: '#6b21a8', sym: '♦' },
    { code: 'c-purple', bg: '#6b21a8', sym: '♣' },
    { code: 's-navy',   bg: '#1e3a5f', sym: '♠' },
    { code: 'h-navy',   bg: '#1e3a5f', sym: '♥' },
    { code: 'd-navy',   bg: '#1e3a5f', sym: '♦' },
    { code: 'c-navy',   bg: '#1e3a5f', sym: '♣' },
    { code: 's-rose',   bg: '#9f1239', sym: '♠' },
    { code: 'h-rose',   bg: '#9f1239', sym: '♥' },
    { code: 'd-rose',   bg: '#9f1239', sym: '♦' },
    { code: 'c-rose',   bg: '#9f1239', sym: '♣' },
  ];

  function avatarForCode(code) {
    return AVATARS.find(a => a.code === code) || { bg: '#888', sym: '?' };
  }

  // ── CSS (injected once) ───────────────────────────────────────────────────
  const MODAL_CSS = `
    #pm-dialog {
      position: fixed; inset: 0; z-index: 9999;
      display: flex; align-items: center; justify-content: center;
      background: rgba(20,16,10,0.52);
      padding: 16px;
    }
    #pm-dialog[hidden] { display: none; }
    #pm-box {
      background: #fffdf8;
      border: 1px solid #d0c6b8;
      border-radius: 18px;
      box-shadow: 0 24px 64px rgba(30,20,10,0.22);
      width: 100%; max-width: 560px;
      max-height: 88vh; display: flex; flex-direction: column;
      overflow: hidden;
    }
    #pm-header {
      display: flex; align-items: center; gap: 14px;
      padding: 18px 20px 14px;
      border-bottom: 1px solid #e8dfd2;
    }
    .pm-avatar {
      width: 52px; height: 52px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 22px; color: #fff; flex-shrink: 0;
      font-family: sans-serif;
    }
    .pm-avatar-sm {
      width: 28px; height: 28px; border-radius: 50%;
      display: inline-flex; align-items: center; justify-content: center;
      font-size: 13px; color: #fff; flex-shrink: 0;
      font-family: sans-serif; cursor: pointer;
      vertical-align: middle;
    }
    #pm-title { flex: 1; min-width: 0; }
    #pm-username {
      font-size: 18px; font-weight: 800; letter-spacing: -0.01em;
      line-height: 1.1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    #pm-subtitle { font-size: 12px; color: #5c6670; margin-top: 2px; }
    #pm-close {
      background: none; border: 1px solid #ccc; border-radius: 8px;
      cursor: pointer; color: #888; font-size: 14px; padding: 5px 10px;
      flex-shrink: 0; transition: color 0.15s, border-color 0.15s;
    }
    #pm-close:hover { color: #333; border-color: #888; }
    #pm-tabs {
      display: flex; gap: 0; padding: 0 20px;
      border-bottom: 1px solid #e8dfd2; background: #f9f4ee;
    }
    .pm-tab {
      padding: 10px 16px; font-size: 13px; font-weight: 600;
      color: #5c6670; cursor: pointer; border-bottom: 2px solid transparent;
      margin-bottom: -1px; white-space: nowrap;
      transition: color 0.15s;
      background: none; border-top: none; border-left: none; border-right: none;
      border-radius: 0; width: auto;
    }
    .pm-tab:hover { color: #1d2126; }
    .pm-tab.active { color: #0d6f66; border-bottom-color: #0d6f66; }
    #pm-body { padding: 18px 20px; overflow-y: auto; flex: 1; }
    .pm-panel { display: none; }
    .pm-panel.active { display: block; }

    /* Stats */
    .pm-kpi-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
    .pm-kpi {
      background: #f5f0e8; border: 1px solid #e8dfd2; border-radius: 10px;
      padding: 10px 12px; text-align: center;
    }
    .pm-kpi-val { font-size: 22px; font-weight: 800; line-height: 1; color: #1d2126; }
    .pm-kpi-lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: #5c6670; margin-top: 3px; }

    .pm-section-title {
      font-size: 11px; font-weight: 700; text-transform: uppercase;
      letter-spacing: 0.06em; color: #5c6670; margin: 14px 0 6px;
    }
    .pm-table { width: 100%; border-collapse: collapse; font-size: 12px; }
    .pm-table th, .pm-table td { padding: 6px 8px; border-bottom: 1px solid #ece4d8; text-align: left; }
    .pm-table th { color: #5c6670; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
    .pm-table tr:last-child td { border-bottom: none; }
    .pm-win-bar {
      display: inline-block; height: 6px; border-radius: 3px;
      background: #0d6f66; vertical-align: middle; min-width: 2px;
    }
    .pm-no-data { text-align: center; padding: 20px; color: #999; font-size: 13px; }
    .pm-chart { margin-top: 6px; }

    /* Account */
    .pm-form-group { margin-bottom: 12px; }
    .pm-form-group label { display: block; font-size: 12px; color: #5c6670; margin-bottom: 4px; }
    .pm-input {
      width: 100%; border-radius: 8px; border: 1px solid #c5baa8;
      padding: 9px 10px; font-size: 13px; color: #1d2126; background: white;
      font-family: inherit;
    }
    .pm-btn {
      border: none; border-radius: 8px; padding: 9px 18px;
      font-size: 13px; font-weight: 700; cursor: pointer; color: white;
      background: #0d6f66; transition: background 0.15s; font-family: inherit; width: auto;
    }
    .pm-btn:hover { background: #0a5952; }
    .pm-btn.secondary { background: #4f5b66; }
    .pm-btn.secondary:hover { background: #3d4852; }
    .pm-msg { margin-top: 6px; min-height: 16px; font-size: 12px; }
    .pm-msg.ok  { color: #1f8a47; }
    .pm-msg.err { color: #a32121; }
    .pm-avatar-grid {
      display: grid; grid-template-columns: repeat(10, 1fr); gap: 6px; margin-top: 6px;
    }
    .pm-avatar-option {
      width: 32px; height: 32px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 14px; color: white; cursor: pointer;
      border: 3px solid transparent; box-sizing: border-box;
      transition: border-color 0.15s, transform 0.1s;
      font-family: sans-serif;
    }
    .pm-avatar-option:hover { transform: scale(1.1); }
    .pm-avatar-option.selected { border-color: white; box-shadow: 0 0 0 2px #0d6f66; }

    .pm-spinner { text-align: center; padding: 30px; color: #999; }
  `;

  let styleInjected = false;
  function injectStyles() {
    if (styleInjected) return;
    styleInjected = true;
    const style = document.createElement('style');
    style.textContent = MODAL_CSS;
    document.head.appendChild(style);
  }

  // ── Helpers ───────────────────────────────────────────────────────────────
  function basePath()  { return window.APP_BASE_PATH  || ''; }
  function csrfToken() { return window.APP_CSRF_TOKEN || ''; }
  function myUserId()  { return window.APP_USER_ID    || null; }

  async function apiFetch(path, method = 'GET', body = null) {
    const opts = {
      method,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    };
    if (body !== null) opts.body = JSON.stringify(body);
    const res = await fetch(basePath() + path, opts);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error((data && data.error) ? data.error : 'http_' + res.status);
    return data;
  }

  // ── DOM setup ─────────────────────────────────────────────────────────────
  let dialog, box, loadedUserId;

  function ensureDialog() {
    if (dialog) return;
    injectStyles();

    dialog = document.createElement('div');
    dialog.id = 'pm-dialog';
    dialog.setAttribute('hidden', '');
    dialog.innerHTML = `
      <div id="pm-box">
        <div id="pm-header">
          <div class="pm-avatar" id="pm-av"></div>
          <div id="pm-title">
            <div id="pm-username">Loading…</div>
            <div id="pm-subtitle"></div>
          </div>
          <button id="pm-close" onclick="closePlayerModal()">✕ Close</button>
        </div>
        <div id="pm-tabs"></div>
        <div id="pm-body"><div class="pm-spinner">Loading…</div></div>
      </div>
    `;
    document.body.appendChild(dialog);

    // Close on backdrop click
    dialog.addEventListener('click', e => { if (e.target === dialog) closePlayerModal(); });
  }

  // ── Avatar rendering ──────────────────────────────────────────────────────
  function avatarEl(code, className = 'pm-avatar', gravatarUrl = null) {
    const el = document.createElement('div');
    el.className = className;
    if (code) {
      const av = avatarForCode(code);
      el.style.background = av.bg;
      el.textContent = av.sym;
    } else if (gravatarUrl) {
      el.style.background = '#ddd';
      const img = document.createElement('img');
      img.src = gravatarUrl;
      img.alt = '';
      img.style.cssText = 'width:100%;height:100%;border-radius:50%;object-fit:cover;display:block;';
      el.appendChild(img);
    } else {
      el.style.background = '#888';
      el.textContent = '?';
    }
    return el;
  }

  // ── Chart ─────────────────────────────────────────────────────────────────
  function sparkline(monthly) {
    if (!monthly || monthly.length === 0) {
      return '<div class="pm-no-data">No game history yet.</div>';
    }
    const W = 500, H = 80, pad = 6;
    const n = monthly.length;
    const pts = monthly.map((m, i) => {
      const x = pad + (i / Math.max(n - 1, 1)) * (W - 2 * pad);
      const y = H - pad - (m.win_pct / 100) * (H - 2 * pad);
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    });
    const polyline = pts.join(' ');

    // Axis: 50% reference line
    const y50 = H - pad - 0.5 * (H - 2 * pad);

    let labels = '';
    if (n > 0) {
      const first = monthly[0].month;
      const last  = monthly[n - 1].month;
      labels = `<text x="${pad}" y="${H + 14}" fill="#999" font-size="10">${first}</text>
                <text x="${W - pad}" y="${H + 14}" fill="#999" font-size="10" text-anchor="end">${last}</text>`;
    }

    // Dot at last point
    const lastPt = pts[pts.length - 1].split(',');
    const lastWin = monthly[monthly.length - 1].win_pct;

    return `
      <svg viewBox="0 0 ${W} ${H + 18}" style="width:100%;display:block;" xmlns="http://www.w3.org/2000/svg">
        <line x1="${pad}" y1="${y50.toFixed(1)}" x2="${W - pad}" y2="${y50.toFixed(1)}"
              stroke="#e0d8cc" stroke-width="1" stroke-dasharray="4 4"/>
        <text x="${W - pad - 2}" y="${y50 - 3}" fill="#ccc" font-size="9" text-anchor="end">50%</text>
        <polyline points="${polyline}" fill="none" stroke="#0d6f66" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
        <circle cx="${lastPt[0]}" cy="${lastPt[1]}" r="3" fill="#0d6f66"/>
        <text x="${lastPt[0]}" y="${parseFloat(lastPt[1]) - 6}" fill="#0d6f66" font-size="10" text-anchor="middle">${lastWin}%</text>
        ${labels}
      </svg>`;
  }

  // ── Stats panel ───────────────────────────────────────────────────────────
  function renderStats(stats) {
    const winPct = stats.win_pct != null ? stats.win_pct + '%' : '—';

    function peopleTable(rows, myPerspective) {
      if (!rows || rows.length === 0) {
        return '<div class="pm-no-data">No ' + myPerspective + ' data yet.</div>';
      }
      let html = '<table class="pm-table"><thead><tr><th>Player</th><th>Games</th><th>Win %</th><th></th></tr></thead><tbody>';
      rows.forEach(r => {
        const pct = r.win_pct != null ? r.win_pct : 0;
        const barW = Math.round(pct);
        html += `<tr>
          <td>${esc(r.username || '#' + r.user_id)}</td>
          <td>${r.games}</td>
          <td>${pct}%</td>
          <td><span class="pm-win-bar" style="width:${barW}px"></span></td>
        </tr>`;
      });
      html += '</tbody></table>';
      return html;
    }

    return `
      <div class="pm-kpi-row">
        <div class="pm-kpi"><div class="pm-kpi-val">${stats.games_played ?? 0}</div><div class="pm-kpi-lbl">Played</div></div>
        <div class="pm-kpi"><div class="pm-kpi-val">${stats.wins ?? 0}</div><div class="pm-kpi-lbl">Wins</div></div>
        <div class="pm-kpi"><div class="pm-kpi-val">${winPct}</div><div class="pm-kpi-lbl">Win Rate</div></div>
      </div>
      <div class="pm-section-title">Win % Over Time</div>
      <div class="pm-chart">${sparkline(stats.monthly)}</div>
      <div class="pm-section-title">Common Partners</div>
      ${peopleTable(stats.partners, 'partner')}
      <div class="pm-section-title">Common Opponents</div>
      ${peopleTable(stats.opponents, 'opponent')}
    `;
  }

  // ── Account panel ─────────────────────────────────────────────────────────
  function renderAccount(user) {
    let avatarGrid = '<div class="pm-avatar-grid">';
    AVATARS.forEach(a => {
      const sel = (user.avatar_code === a.code) ? ' selected' : '';
      avatarGrid += `<div class="pm-avatar-option${sel}" style="background:${a.bg}" data-code="${a.code}" title="${a.code}">${a.sym}</div>`;
    });
    avatarGrid += '</div>';

    return `
      <div class="pm-section-title">Display Name</div>
      <div class="pm-form-group">
        <label style="font-size:11px;color:#5c6670;display:block;margin-bottom:4px">Shown on boards and in games. Defaults to username.</label>
        <input class="pm-input" type="text" id="pm-nick" maxlength="60" placeholder="${esc(user.username)}" value="${esc(user.nickname || '')}">
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <button class="pm-btn" onclick="pmSaveNickname()">Save Name</button>
        <span class="pm-msg" id="pm-nick-msg"></span>
      </div>

      <div class="pm-section-title" style="margin-top:20px">Avatar</div>
      ${avatarGrid}
      <div style="margin-top:8px;display:flex;gap:8px;align-items:center">
        <button class="pm-btn" onclick="pmSaveAvatar()">Save Avatar</button>
        <span class="pm-msg" id="pm-avatar-msg"></span>
      </div>

      <div class="pm-section-title" style="margin-top:20px">Change Password</div>
      <div class="pm-form-group">
        <label>Current Password</label>
        <input class="pm-input" type="password" id="pm-pw-cur" autocomplete="current-password">
      </div>
      <div class="pm-form-group">
        <label>New Password (8+ characters)</label>
        <input class="pm-input" type="password" id="pm-pw-new" autocomplete="new-password">
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <button class="pm-btn" onclick="pmSavePassword()">Change Password</button>
        <span class="pm-msg" id="pm-pw-msg"></span>
      </div>
    `;
  }

  // ── Tab switching ─────────────────────────────────────────────────────────
  function activateTab(tabId) {
    document.querySelectorAll('.pm-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tabId));
    document.querySelectorAll('.pm-panel').forEach(p => p.classList.toggle('active', p.id === 'pm-panel-' + tabId));
  }

  // ── Load & render ─────────────────────────────────────────────────────────
  async function loadProfile(userId) {
    loadedUserId = userId;
    const body = document.getElementById('pm-body');
    body.innerHTML = '<div class="pm-spinner">Loading…</div>';
    document.getElementById('pm-tabs').innerHTML = '';
    document.getElementById('pm-username').textContent = 'Loading…';
    document.getElementById('pm-subtitle').textContent = '';
    document.getElementById('pm-av').textContent = '';
    document.getElementById('pm-av').style.background = '#bbb';

    let data;
    try {
      data = await apiFetch('/api/player/stats?user_id=' + encodeURIComponent(userId));
    } catch (err) {
      body.innerHTML = '<div class="pm-no-data">Could not load profile: ' + esc(err.message) + '</div>';
      return;
    }

    const user  = data.user;
    const stats = data.stats;
    const isSelf = myUserId() && Number(myUserId()) === Number(user.id);

    // Header
    const avEl = document.getElementById('pm-av');
    avEl.innerHTML = '';
    if (user.avatar_code) {
      const av = avatarForCode(user.avatar_code);
      avEl.style.background = av.bg;
      avEl.textContent = av.sym;
    } else if (user.gravatar_url) {
      avEl.style.background = '#ddd';
      const img = document.createElement('img');
      img.src = user.gravatar_url;
      img.alt = '';
      img.style.cssText = 'width:100%;height:100%;border-radius:50%;object-fit:cover;display:block;';
      avEl.appendChild(img);
    } else {
      avEl.style.background = '#888';
      avEl.textContent = '?';
    }
    document.getElementById('pm-username').textContent = user.display_name || user.username;
    let sub = user.role;
    if (user.member_since) sub += ' · member since ' + user.member_since;
    document.getElementById('pm-subtitle').textContent = sub;

    // Tabs
    const tabs = [{ id: 'stats', label: 'Stats' }];
    if (isSelf) tabs.push({ id: 'account', label: 'Account' });

    const tabBar = document.getElementById('pm-tabs');
    tabBar.innerHTML = tabs.map(t =>
      `<button class="pm-tab" data-tab="${t.id}" onclick="pmSwitchTab('${t.id}')">${t.label}</button>`
    ).join('');

    // Panels
    body.innerHTML = `
      <div class="pm-panel" id="pm-panel-stats">${renderStats(stats)}</div>
      ${isSelf ? `<div class="pm-panel" id="pm-panel-account">${renderAccount(user)}</div>` : ''}
    `;

    activateTab('stats');

    // Wire up avatar selection clicks
    body.querySelectorAll('.pm-avatar-option').forEach(el => {
      el.addEventListener('click', () => {
        body.querySelectorAll('.pm-avatar-option').forEach(x => x.classList.remove('selected'));
        el.classList.add('selected');
      });
    });
  }

  // ── Public action handlers ────────────────────────────────────────────────
  window.pmSwitchTab = function (tabId) { activateTab(tabId); };

  window.pmSaveNickname = async function () {
    const input = document.getElementById('pm-nick');
    const msg   = document.getElementById('pm-nick-msg');
    try {
      const res = await apiFetch('/api/player/update_nickname', 'POST', { nickname: input.value.trim() });
      msg.textContent = 'Saved!'; msg.className = 'pm-msg ok';
      // Update the header username display
      const displayName = res.display_name || document.getElementById('pm-username').textContent;
      document.getElementById('pm-username').textContent = displayName;
    } catch (err) { msg.textContent = err.message; msg.className = 'pm-msg err'; }
  };

  window.pmSaveAvatar = async function () {
    const sel = document.querySelector('.pm-avatar-option.selected');
    const msg = document.getElementById('pm-avatar-msg');
    if (!sel) { msg.textContent = 'Pick an avatar first.'; msg.className = 'pm-msg err'; return; }
    const code = sel.dataset.code;
    try {
      await apiFetch('/api/player/update_avatar', 'POST', { avatar_code: code });
      msg.textContent = 'Saved!'; msg.className = 'pm-msg ok';
      // Refresh avatar in header
      const av = avatarForCode(code);
      const avEl = document.getElementById('pm-av');
      avEl.innerHTML = ''; avEl.style.background = av.bg; avEl.textContent = av.sym;
      // Update any .pm-avatar-sm for this user across the page
      document.querySelectorAll('[data-player-uid="' + loadedUserId + '"]').forEach(el => {
        el.innerHTML = ''; el.style.background = av.bg; el.textContent = av.sym;
      });
      if (window.APP_CSRF_TOKEN) {
        window.APP_CSRF_TOKEN = window.APP_CSRF_TOKEN; // may be refreshed elsewhere
      }
    } catch (err) { msg.textContent = err.message; msg.className = 'pm-msg err'; }
  };

  window.pmSavePassword = async function () {
    const cur = document.getElementById('pm-pw-cur');
    const nxt = document.getElementById('pm-pw-new');
    const msg = document.getElementById('pm-pw-msg');
    try {
      await apiFetch('/api/player/update_password', 'POST', {
        current_password: cur.value,
        new_password: nxt.value,
      });
      msg.textContent = 'Password updated.'; msg.className = 'pm-msg ok';
      cur.value = ''; nxt.value = '';
    } catch (err) { msg.textContent = err.message; msg.className = 'pm-msg err'; }
  };

  // ── Escape helper ─────────────────────────────────────────────────────────
  function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  // ── Public API ────────────────────────────────────────────────────────────
  window.openPlayerModal = function (userId) {
    if (!userId) return;
    ensureDialog();
    dialog.removeAttribute('hidden');
    document.body.style.overflow = 'hidden';
    loadProfile(Number(userId));
  };

  window.closePlayerModal = function () {
    if (dialog) dialog.setAttribute('hidden', '');
    document.body.style.overflow = '';
  };

  /**
   * Render a small clickable avatar for a player by user_id.
   * Fetches from a cache populated when profiles are loaded.
   * Useful for decorating player names inline.
   */
  const avatarCache = {};   // userId → { avatar_code, gravatar_url, username }

  window.playerAvatarEl = function (userId, avatarCode, username, gravatarUrl = null) {
    avatarCache[userId] = { avatar_code: avatarCode, gravatar_url: gravatarUrl, username };
    const el = avatarEl(avatarCode, 'pm-avatar-sm', gravatarUrl);
    el.dataset.playerUid = userId;
    el.title = username || 'Player #' + userId;
    el.setAttribute('role', 'button');
    el.style.cursor = 'pointer';
    el.onclick = () => openPlayerModal(userId);
    return el;
  };

})();
