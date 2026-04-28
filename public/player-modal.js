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

  // ── Bot info ──────────────────────────────────────────────────────────────
  const BOT_ABOUT = 'This bot bids by counting trump cards in hand, follows suit when required, ' +
    'and reneges on top trumps when allowed. It plays the same way every game.';

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
      overflow: hidden; position: relative;
    }
    #pm-header {
      display: flex; align-items: center; gap: 14px;
      padding: 18px 52px 14px 20px;
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
    #pm-title { flex: 1; min-width: 0; overflow: hidden; }
    #pm-username {
      font-size: 18px; font-weight: 800; letter-spacing: -0.01em;
      line-height: 1.1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    #pm-subtitle { font-size: 12px; color: #5c6670; margin-top: 2px; }
    #pm-close {
      position: absolute; top: 14px; right: 16px;
      background: none; border: 1px solid #ccc; border-radius: 8px;
      cursor: pointer; color: #888; font-size: 14px; padding: 5px 9px;
      transition: color 0.15s, border-color 0.15s; z-index: 1;
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

    /* Bot panel */
    .pm-bot-label { color: #5c6670; font-size: 12px; min-width: 110px; font-weight: 600; }

    /* Common games line */
    .pm-common-games {
      font-size: 13px; color: #3a3028; background: #f0ebe0;
      border-radius: 8px; padding: 9px 12px; margin-bottom: 14px;
    }
  `;

  let styleInjected = false;
  function injectStyles() {
    if (styleInjected) return;
    styleInjected = true;
    const style = document.createElement('style');
    style.textContent = MODAL_CSS;
    document.head.appendChild(style);
  }

  // ── Escape helper ─────────────────────────────────────────────────────────
  function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
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
  let dialog, loadedUserId, _openerEl, _loadedStats, _loadedUser;

  function focusableParts() {
    const box = document.getElementById('pm-box');
    if (!box) return [];
    return [...box.querySelectorAll('button, input, select, textarea, [tabindex]:not([tabindex="-1"])')].filter(
      el => !el.disabled && el.offsetParent !== null
    );
  }

  function ensureDialog() {
    if (dialog) return;
    injectStyles();

    dialog = document.createElement('div');
    dialog.id = 'pm-dialog';
    dialog.setAttribute('hidden', '');
    dialog.innerHTML = `
      <div id="pm-box">
        <button id="pm-close" onclick="closePlayerModal()">✕</button>
        <div id="pm-header">
          <div class="pm-avatar" id="pm-av"></div>
          <div id="pm-title">
            <div id="pm-username">Loading…</div>
            <div id="pm-subtitle"></div>
          </div>
        </div>
        <div id="pm-tabs"></div>
        <div id="pm-body"><div class="pm-spinner">Loading…</div></div>
      </div>
    `;
    document.body.appendChild(dialog);

    // Close on backdrop click
    dialog.addEventListener('click', e => { if (e.target === dialog) closePlayerModal(); });

    // Escape key closes; Tab/Shift-Tab trapped inside #pm-box
    dialog.addEventListener('keydown', e => {
      if (e.key === 'Escape') { e.preventDefault(); closePlayerModal(); return; }
      if (e.key === 'Tab') {
        const parts = focusableParts();
        if (parts.length === 0) return;
        const first = parts[0], last = parts[parts.length - 1];
        if (e.shiftKey) {
          if (document.activeElement === first) { e.preventDefault(); last.focus(); }
        } else {
          if (document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
      }
    });
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
  // Chart toggle state — 'months' or 'years' per open panel
  let _chartMode = 'months';
  // Player filter toggle — 'all', 'humans', 'bots'
  let _playerFilter = 'all';

  function yearbar(yearly) {
    if (!yearly || yearly.length === 0) {
      return '<div class="pm-no-data">No game history yet.</div>';
    }
    const maxGames = Math.max(...yearly.map(y => y.games), 1);
    let html = '<div style="display:flex;align-items:flex-end;gap:6px;height:80px;padding:4px 0">';
    yearly.forEach(y => {
      const h = Math.round((y.games / maxGames) * 68);
      html += `<div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px">
        <span style="font-size:9px;color:#0d6f66">${y.win_pct}%</span>
        <div style="width:100%;height:${h}px;background:#0d6f66;border-radius:3px 3px 0 0;min-height:3px"
             title="${y.games} games, ${y.wins} wins"></div>
        <span style="font-size:9px;color:#888">${y.year}</span>
      </div>`;
    });
    html += '</div>';
    return html;
  }

  function renderStats(stats, viewerId, isSelf, profileName) {
    const winPct = stats.win_pct != null ? stats.win_pct + '%' : '—';

    // Common games with viewer (human mode only)
    let commonHtml = '';
    if (viewerId && !isSelf) {
      const partner  = stats.partners  && stats.partners.find(p => p.user_id != null && Number(p.user_id) === Number(viewerId));
      const opponent = stats.opponents && stats.opponents.find(p => p.user_id != null && Number(p.user_id) === Number(viewerId));
      const pGames   = partner  ? partner.games  : 0;
      const oGames   = opponent ? opponent.games : 0;
      const total    = pGames + oGames;
      if (total > 0) {
        const wins   = (partner ? partner.wins : 0) + (opponent ? opponent.wins : 0);
        const losses = total - wins;
        const pct    = Math.round(wins / total * 100);
        commonHtml   = `<div class="pm-common-games">You and ${esc(profileName)}: ${total} game${total===1?'':'s'} · ${wins}–${losses} (${pct}%)</div>`;
      }
    }

    // KPI row — self gets bid/set counters too
    let kpiHtml = `
      <div class="pm-kpi-row" style="grid-template-columns:repeat(${isSelf ? 6 : 3},1fr)">
        <div class="pm-kpi"><div class="pm-kpi-val">${stats.games_played ?? 0}</div><div class="pm-kpi-lbl">Played</div></div>
        <div class="pm-kpi"><div class="pm-kpi-val">${stats.wins ?? 0}</div><div class="pm-kpi-lbl">Wins</div></div>
        <div class="pm-kpi"><div class="pm-kpi-val">${winPct}</div><div class="pm-kpi-lbl">Win Rate</div></div>
        ${isSelf ? `
        <div class="pm-kpi"><div class="pm-kpi-val">${stats.hands_bid ?? 0}</div><div class="pm-kpi-lbl">Hands Bid</div></div>
        <div class="pm-kpi"><div class="pm-kpi-val">${stats.bids_made ?? 0}</div><div class="pm-kpi-lbl">Bids Made</div></div>
        <div class="pm-kpi"><div class="pm-kpi-val">${stats.sets_taken ?? 0}</div><div class="pm-kpi-lbl">Sets Taken</div></div>
        ` : ''}
      </div>`;

    // Chart with month/year toggle (self only)
    const chartContent = _chartMode === 'years' ? yearbar(stats.yearly) : sparkline(stats.monthly);
    const chartToggle = isSelf ? `
      <div style="display:flex;gap:0;margin-bottom:6px">
        <button onclick="pmChartMode('months')" style="padding:4px 10px;font-size:11px;font-weight:600;border-radius:6px 0 0 6px;border:1px solid #c5baa8;cursor:pointer;background:${_chartMode==='months'?'#0d6f66':'#fff'};color:${_chartMode==='months'?'#fff':'#5c6670'}">Months</button>
        <button onclick="pmChartMode('years')" style="padding:4px 10px;font-size:11px;font-weight:600;border-radius:0 6px 6px 0;border:1px solid #c5baa8;border-left:none;cursor:pointer;background:${_chartMode==='years'?'#0d6f66':'#fff'};color:${_chartMode==='years'?'#fff':'#5c6670'}">Years</button>
      </div>` : '';

    // Player filter toggle (self only)
    const filterToggle = isSelf ? `
      <div style="display:flex;gap:0;margin-bottom:8px">
        <button onclick="pmPlayerFilter('all')" style="padding:4px 10px;font-size:11px;font-weight:600;border-radius:6px 0 0 6px;border:1px solid #c5baa8;cursor:pointer;background:${_playerFilter==='all'?'#0d6f66':'#fff'};color:${_playerFilter==='all'?'#fff':'#5c6670'}">All</button>
        <button onclick="pmPlayerFilter('humans')" style="padding:4px 10px;font-size:11px;font-weight:600;border-radius:0;border:1px solid #c5baa8;border-left:none;cursor:pointer;background:${_playerFilter==='humans'?'#0d6f66':'#fff'};color:${_playerFilter==='humans'?'#fff':'#5c6670'}">Humans</button>
        <button onclick="pmPlayerFilter('bots')" style="padding:4px 10px;font-size:11px;font-weight:600;border-radius:0 6px 6px 0;border:1px solid #c5baa8;border-left:none;cursor:pointer;background:${_playerFilter==='bots'?'#0d6f66':'#fff'};color:${_playerFilter==='bots'?'#fff':'#5c6670'}">Bots</button>
      </div>` : '';

    function filterRows(rows) {
      if (!isSelf || _playerFilter === 'all') return rows;
      if (_playerFilter === 'humans') return (rows || []).filter(r => r.user_id != null);
      if (_playerFilter === 'bots')   return (rows || []).filter(r => r.user_id == null);
      return rows;
    }

    function peopleTable(rows, label) {
      const filtered = filterRows(rows);
      if (!filtered || filtered.length === 0) {
        return '<div class="pm-no-data">No ' + label + ' data yet.</div>';
      }
      let html = '<table class="pm-table"><thead><tr><th>Player</th><th>Games</th><th>Win %</th><th></th></tr></thead><tbody>';
      filtered.forEach(r => {
        const pct  = r.win_pct != null ? r.win_pct : 0;
        const barW = Math.round(pct);
        const name = esc(r.username || (r.user_id ? '#' + r.user_id : '?'));
        const lastP = r.last_played ? `<div style="font-size:10px;color:#999;margin-top:1px">last ${r.last_played}</div>` : '';
        html += `<tr>
          <td>${name}${lastP}</td>
          <td>${r.games}</td>
          <td>${pct}%</td>
          <td><span class="pm-win-bar" style="width:${barW}px"></span></td>
        </tr>`;
      });
      html += '</tbody></table>';
      return html;
    }

    return `
      ${commonHtml}
      ${kpiHtml}
      <div class="pm-section-title">Win % Over Time</div>
      ${chartToggle}
      <div class="pm-chart" id="pm-chart-area">${chartContent}</div>
      <div class="pm-section-title" style="margin-top:14px">${filterToggle}Common Partners</div>
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
      <div class="pm-section-title">Email / Gravatar</div>
      <div style="display:flex;gap:10px;align-items:flex-start;margin-bottom:12px">
        <div id="pm-grav-preview" style="width:52px;height:52px;border-radius:50%;overflow:hidden;flex-shrink:0;background:#ddd">
          ${user.gravatar_url ? `<img src="${esc(user.gravatar_url)}" style="width:100%;height:100%;object-fit:cover" alt="">` : ''}
        </div>
        <div style="flex:1">
          <label style="font-size:11px;color:#5c6670;display:block;margin-bottom:4px">Set your email to use your Gravatar as avatar.</label>
          <input class="pm-input" type="email" id="pm-email" maxlength="200" placeholder="you@example.com" value="${esc(user.email || '')}">
        </div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:20px">
        <button class="pm-btn" onclick="pmSaveEmail()">Save Email</button>
        <span class="pm-msg" id="pm-email-msg"></span>
      </div>

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

  // ── AI panel ──────────────────────────────────────────────────────────────
  const AI_COLORS = ['#0d6f66','#c26a10','#6b21a8','#1e3a5f','#9f1239'];

  function renderAiPanel() {
    return `
      <table class="pm-table" style="margin-top:4px"><tbody>
        <tr><td class="pm-bot-label">Strategy</td><td>Algorithmic</td></tr>
        <tr><td class="pm-bot-label">Difficulty</td><td>Medium</td></tr>
        <tr>
          <td class="pm-bot-label" style="vertical-align:top;padding-top:8px">About</td>
          <td style="font-size:12px;line-height:1.6;color:#3a3028;padding:8px 8px 8px 8px">${esc(BOT_ABOUT)}</td>
        </tr>
        <tr><td class="pm-bot-label">Personality</td><td style="color:#aaa">—</td></tr>
        <tr><td class="pm-bot-label">Wins</td><td style="color:#aaa">—</td></tr>
      </tbody></table>
    `;
  }

  function showAiInfo(descriptor) {
    const name   = descriptor.display_name || 'Bot';
    const seat   = descriptor.seat != null ? (Number(descriptor.seat) + 1) : '?';
    const color  = AI_COLORS[name.charCodeAt(0) % AI_COLORS.length];

    const avEl = document.getElementById('pm-av');
    avEl.innerHTML = '';
    avEl.style.background = color;
    avEl.textContent = name.charAt(0).toUpperCase();

    document.getElementById('pm-username').textContent = name;
    document.getElementById('pm-subtitle').textContent = 'Computer player · Seat ' + seat;

    const tabBar = document.getElementById('pm-tabs');
    tabBar.innerHTML = '<button class="pm-tab active" data-tab="bot" onclick="pmSwitchTab(\'bot\')">Bot</button>';

    document.getElementById('pm-body').innerHTML =
      '<div class="pm-panel active" id="pm-panel-bot">' + renderAiPanel() + '</div>';

    const closeBtn = document.getElementById('pm-close');
    if (closeBtn) closeBtn.focus();
  }

  // ── Tab switching ─────────────────────────────────────────────────────────
  function activateTab(tabId) {
    document.querySelectorAll('.pm-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tabId));
    document.querySelectorAll('.pm-panel').forEach(p => p.classList.toggle('active', p.id === 'pm-panel-' + tabId));
  }

  // ── Load & render ─────────────────────────────────────────────────────────
  async function loadProfile(userId) {
    loadedUserId = userId;
    _loadedStats = null; _loadedUser = null;
    _chartMode = 'months'; _playerFilter = 'all';
    const body = document.getElementById('pm-body');
    body.innerHTML = '<div class="pm-spinner">Loading…</div>';
    document.getElementById('pm-tabs').innerHTML = '';
    document.getElementById('pm-username').textContent = 'Loading…';
    document.getElementById('pm-subtitle').textContent = '';
    document.getElementById('pm-av').textContent = '';
    document.getElementById('pm-av').style.background = '#bbb';

    const isSelfFetch = myUserId() && Number(myUserId()) === Number(userId);
    const statsUrl = '/api/player/stats?user_id=' + encodeURIComponent(userId) + (isSelfFetch ? '&include_ai=1' : '');
    let data;
    try {
      data = await apiFetch(statsUrl);
    } catch (err) {
      body.innerHTML = '<div class="pm-no-data">Could not load profile: ' + esc(err.message) + '</div>';
      return;
    }

    const user  = data.user;
    const stats = data.stats;
    _loadedStats = stats; _loadedUser = user;
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
      <div class="pm-panel" id="pm-panel-stats">${renderStats(stats, myUserId(), isSelf, user.display_name || user.username)}</div>
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

    // Move focus to the close button once content is loaded
    const closeBtn = document.getElementById('pm-close');
    if (closeBtn) closeBtn.focus();
  }

  // ── Public action handlers ────────────────────────────────────────────────
  window.pmSwitchTab = function (tabId) { activateTab(tabId); };

  window.pmChartMode = function (mode) {
    _chartMode = mode;
    const area = document.getElementById('pm-chart-area');
    if (!area || !_loadedStats) return;
    area.innerHTML = mode === 'years' ? yearbar(_loadedStats.yearly) : sparkline(_loadedStats.monthly);
    // Re-render the toggle buttons to reflect new selection
    pmReloadStats();
  };

  window.pmPlayerFilter = function (filter) {
    _playerFilter = filter;
    pmReloadStats();
  };

  function pmReloadStats() {
    if (!_loadedStats || !_loadedUser) return;
    const panel = document.getElementById('pm-panel-stats');
    if (panel) {
      const isSelf = myUserId() && Number(myUserId()) === Number(_loadedUser.id);
      panel.innerHTML = renderStats(_loadedStats, myUserId(), isSelf, _loadedUser.display_name || _loadedUser.username);
    }
  }

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

  window.pmSaveEmail = async function () {
    const input = document.getElementById('pm-email');
    const msg   = document.getElementById('pm-email-msg');
    try {
      const res = await apiFetch('/api/player/update_email', 'POST', { email: input.value.trim() });
      msg.textContent = 'Saved!'; msg.className = 'pm-msg ok';
      // Update gravatar preview
      const preview = document.getElementById('pm-grav-preview');
      if (preview && res.gravatar_url) {
        preview.innerHTML = `<img src="${esc(res.gravatar_url)}" style="width:100%;height:100%;object-fit:cover" alt="">`;
        // Also update header avatar if currently showing gravatar
        const avEl = document.getElementById('pm-av');
        if (avEl && !avEl.textContent.trim() && _loadedUser && !_loadedUser.avatar_code) {
          avEl.innerHTML = '';
          avEl.style.background = '#ddd';
          const img = document.createElement('img');
          img.src = res.gravatar_url;
          img.alt = '';
          img.style.cssText = 'width:100%;height:100%;border-radius:50%;object-fit:cover;display:block;';
          avEl.appendChild(img);
        }
      }
    } catch (err) { msg.textContent = err.message; msg.className = 'pm-msg err'; }
  };

  // ── Public API ────────────────────────────────────────────────────────────

  // openPlayerModal(123)                        — open for user id 123
  // openPlayerModal({kind:'user', userId:123})  — same
  // openPlayerModal({kind:'ai', display_name, seat}) — AI info panel
  window.openPlayerModal = function (arg) {
    if (!arg) return;
    _openerEl = document.activeElement || null;
    ensureDialog();
    dialog.removeAttribute('hidden');
    document.body.style.overflow = 'hidden';
    if (arg && typeof arg === 'object') {
      if (arg.kind === 'ai') { showAiInfo(arg); return; }
      if (arg.kind === 'user' && arg.userId) { loadProfile(Number(arg.userId)); return; }
    }
    loadProfile(Number(arg));
  };

  window.closePlayerModal = function () {
    if (dialog) dialog.setAttribute('hidden', '');
    document.body.style.overflow = '';
    if (_openerEl) { try { _openerEl.focus(); } catch(_) {} _openerEl = null; }
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
