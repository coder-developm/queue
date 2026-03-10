
  let branding = null;
  async function loadBranding() {
    try {
      const r = await fetch('/api/site/branding');
      if (!r.ok) return null;
      branding = await r.json();
      return branding;
    } catch { return null; }
  }

  function applyStatusBranding() {
    if (!branding) return;
    const page = (branding.pages && (branding.pages.status || branding.pages.global)) || {};
    const root = document.documentElement;
    if (page.primary) root.style.setProperty('--primary', page.primary);
    if (page.accent) root.style.setProperty('--accent', page.accent);
    // theme only affects status page
    const mode = page.themeMode || (branding.pages?.global?.themeMode) || 'light';
    const dark = (mode === 'dark') || (mode === 'auto' && isDarkByTime());
    document.documentElement.classList.toggle('theme-dark', dark);
    // sound
    window.__notifySoundUrl = page.notifySoundUrl || branding.pages?.global?.notifySoundUrl || '/sounds/notify.wav';
  }
(function () {
  const cfg = window.APP_CONFIG || {};

  function isDarkByTime() {
    const h = new Date().getHours();
    return (h >= 18) || (h < 12);
  }
  const colors = cfg.colors || {};

  // Применяем цвета из конфига в CSS-переменные
  const root = document.documentElement;
  if (colors.bg) root.style.setProperty("--bg", colors.bg);
  if (colors.text) root.style.setProperty("--text", colors.text);
  if (colors.accent) root.style.setProperty("--accent", colors.accent);
  if (colors.divider) root.style.setProperty("--divider", colors.divider);

  // DOM
  const leftTitle = document.getElementById("leftTitle");
  const rightTitle = document.querySelector(".panel--right .panel__title");
  const prepareNumbersEl = document.getElementById("prepareNumbers");
  const cash1Pill = document.getElementById("cash1Pill");
  const cash2Pill = document.getElementById("cash2Pill");
  const cashRowEls = Array.from(document.querySelectorAll(".cashRow"));
  const cashRow1 = cashRowEls[0] || null;
  const cashRow2 = cashRowEls[1] || null;
  const cashRowLabels = Array.from(document.querySelectorAll(".cashRow__label"));
  const footerText = document.getElementById("footerText");
  const logo = document.getElementById("logo");
  const soundBtn = document.getElementById("soundBtn");
  const soundIcon = document.getElementById("soundIcon");

  // Лого из конфига
  if (logo && cfg.logoPath) logo.src = cfg.logoPath;

  // Иконки (SVG path) — в виде mask
  const ICON_SOUND_ON =
    'data:image/svg+xml;utf8,' +
    encodeURIComponent(`
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <path fill="black" d="M3 10v4h4l5 4V6L7 10H3zm13.5 2c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89 1 5 3.77 5 6.71s-2.11 5.71-5 6.71v2.06c4.01-1.1 7-4.79 7-8.77s-2.99-7.67-7-8.77z"/>
      </svg>
    `);

  const ICON_SOUND_OFF =
    'data:image/svg+xml;utf8,' +
    encodeURIComponent(`
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <path fill="black" d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zM19 12c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-3.98-2.99-7.67-7-8.77v2.06c2.89 1 5 3.77 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 4v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.19v2.06c1.37-.38 2.63-1.03 3.69-1.89L19.73 21 21 19.73 4.27 3zM12 6L9.91 7.67 12 9.76V6z"/>
      </svg>
    `);

  function setSoundIcon(isOn) {
    const icon = isOn ? ICON_SOUND_ON : ICON_SOUND_OFF;
    soundIcon.style.webkitMaskImage = `url("${icon}")`;
    soundIcon.style.maskImage = `url("${icon}")`;
  }

  function humanWord(n) {
    return window.I18N ? window.I18N.humanWord(n) : "человек";
  }

  function applyLangLabels() {
    const I = window.I18N;
    if (!I) return;
    if (rightTitle) rightTitle.textContent = I.t("invited_clients");
    if (cashRowLabels[0]) cashRowLabels[0].textContent = `${I.t("desk")} 1:`;
    if (cashRowLabels[1]) cashRowLabels[1].textContent = `${I.t("desk")} 2:`;
  }

  function setLeftTitleByCount(count) {
    const I = window.I18N;
    if (!I) {
      leftTitle.textContent = count === 1 ? "Приготовиться номерам" : "Приготовиться номерам";
      return;
    }
    leftTitle.textContent = count === 1 ? I.t("prepare_one") : I.t("prepare_many");
  }

  function renderPrepare(numbers) {
    prepareNumbersEl.innerHTML = "";
    (numbers || []).forEach((num) => {
      const div = document.createElement("div");
      div.className = "prepareNumber";
      div.textContent = num;
      prepareNumbersEl.appendChild(div);
    });
  }

  function renderFooter(queueCount) {
    const I = window.I18N;
    if (queueCount <= 0) {
      footerText.textContent = I ? I.t("no_one_queue") : "В очереди никого нет";
      return;
    }
    const word = humanWord(queueCount);
    if (I && I.lang === 'en') {
      footerText.innerHTML = `${I.t("total_in_queue")} <b>${queueCount}</b> <b>${word}</b>`;
    } else if (I) {
      footerText.innerHTML = `${I.t("total_in_queue")} <b>${queueCount}</b> <b>${word}</b>`;
    } else {
      footerText.innerHTML = `Всего в очереди <b>${queueCount}</b> <b>${word}</b>`;
    }
  }

  // Текущее состояние
  let state = {
    prepare: [],
    cashiers: { 1: "000", 2: "000" },
    soundOn: false,
    queueCount: 0,
  };

  function applyState(next) {
    state = {
      ...state,
      ...next,
      cashiers: (next && typeof next.cashiers === 'object') ? (next.cashiers || {}) : state.cashiers,
    };

    const prepare = state.prepare || [];
    const queueCount = typeof state.queueCount === "number" ? state.queueCount : prepare.length;

    setLeftTitleByCount(prepare.length);
    renderPrepare(prepare);

    cash1Pill.textContent = state.cashiers?.[1] ?? "000";
    cash2Pill.textContent = state.cashiers?.[2] ?? "000";

    // Hide rows for paused/hidden cashiers (backend may omit them)
    if (cashRow1) cashRow1.style.display = (state.cashiers && state.cashiers[1]) ? '' : 'none';
    if (cashRow2) cashRow2.style.display = (state.cashiers && state.cashiers[2]) ? '' : 'none';

    renderFooter(queueCount);
    setSoundIcon(!!state.soundOn);
  }

  // Переключатель звука (как на скринах)
  soundBtn.addEventListener("click", () => {
    applyState({ soundOn: !state.soundOn });
  });

  // Демо-режим (циклим состояния скринов по клавишам 1..4)
  const demo = Array.isArray(cfg.demoStates) ? cfg.demoStates : [];
  if (demo.length) {
    // стартовое — 1й скрин
    applyState({
      ...demo[0],
      queueCount: demo[0]?.prepare?.length ?? 0,
    });

    window.addEventListener("keydown", (e) => {
      const k = e.key;
      if (k >= "1" && k <= String(Math.min(9, demo.length))) {
        const idx = Number(k) - 1;
        const st = demo[idx];
        applyState({ ...st, queueCount: st?.prepare?.length ?? 0 });
      }
    });
  } else {
    applyState(state);
  }

  // Экспорт на будущее (если будешь дергать из внешнего кода)
  window.QueueScreen = {
    setData(data) {
      // data = { prepare: ["001"], cashiers: {1:"001",2:"000"}, soundOn:true, queueCount: 5 }
      applyState(data || {});
    },
  };


  // --- API poll + speech ---
  function basePrefix(){
    const p = location.pathname;
    const idx = p.indexOf('/status');
    return idx >= 0 ? p.slice(0, idx) : '';
  }
  const apiBase = cfg.apiBase || (location.origin + basePrefix() + "/api");

  function getQueueIdFromPath() {
    const parts = location.pathname.split("/").filter(Boolean);
    // /status/7528261
    const id = parts[1];
    return /^\d+$/.test(id) ? id : null;
  }
  const queueId = getQueueIdFromPath();

  async function apiGet(path) {
    const r = await fetch(apiBase + path);
    if (!r.ok) throw new Error(await r.text());
    return r.json();
  }

  let lastSpoken = { 1: null, 2: null };

  // notification sound (from global branding)
  let notifyAudio = null;
  let notifyAudioUrl = null;
  async function ensureNotifyAudio() {
    try {
      const all = await (window.SiteBranding ? window.SiteBranding.fetchBranding() : Promise.resolve({}));
      const global = (all && all['global']) ? all['global'] : {};
      const url = global.notifySoundUrl || '/sounds/notify.wav';
      if (!notifyAudio || notifyAudioUrl !== url) {
        notifyAudioUrl = url;
        notifyAudio = new Audio(url);
        notifyAudio.preload = 'auto';
      }
    } catch {
      if (!notifyAudio) {
        notifyAudioUrl = '/sounds/notify.wav';
        notifyAudio = new Audio('/sounds/notify.wav');
      }
    }
    return notifyAudio;
  }

  async function playNotifySound() {
    if (!state.soundOn) return;
    try {
      const a = await ensureNotifyAudio();
      a.currentTime = 0;
      await a.play();
    } catch {
      // ignore autoplay restrictions
    }
  }

  let statusLangSetting = 'auto';
  let ttsLangSetting = 'auto';

  function resolveSpeechLang() {
    if (ttsLangSetting && ttsLangSetting !== 'auto') return ttsLangSetting;
    const lang = window.I18N ? window.I18N.lang : (navigator.language || 'ru');
    return (lang || '').toLowerCase().startsWith('en') ? 'en-US' : 'ru-RU';
  }

  const ttsCache = new Map();

  async function speakCall(number, cashierIdx) {
    if (!state.soundOn) return;
    await playNotifySound();
    const lang = resolveSpeechLang();
    try {
      const key = `${number}|${cashierIdx}|${lang}`;
      let url = ttsCache.get(key);
      if (!url) {
        const r = await fetch(apiBase + '/speech/tts', {
          method: 'POST',
          headers: {'Content-Type':'application/json'},
          body: JSON.stringify({ number: String(number), cashier: String(cashierIdx), lang })
        });
        if (!r.ok) throw new Error(await r.text());
        const blob = await r.blob();
        url = URL.createObjectURL(blob);
        ttsCache.set(key, url);
      }
      const audio = new Audio(url);
      await audio.play();
      return;
    } catch (e) {
      // fallback to browser speech
    }
    if (!("speechSynthesis" in window)) return;
    const I = window.I18N;
    const text = I ? I.t('speech_call', number, cashierIdx) : `Номер ${number}. Пройдите к кассе ${cashierIdx}`;
    const u = new SpeechSynthesisUtterance(text);
    u.lang = lang;
    window.speechSynthesis.cancel();
    window.speechSynthesis.speak(u);
  }

  async function tick() {
    if (!queueId) return;
    try {
      const data = await apiGet(`/status/${queueId}`);

      // apply language coming from queue settings
      statusLangSetting = data.statusLang || 'auto';
      ttsLangSetting = data.ttsLang || 'auto';
      if (window.I18N) window.I18N.setLang(statusLangSetting);
      applyLangLabels();

      window.QueueScreen.setData({
        prepare: data.prepare || [],
        cashiers: data.cashiers || {},
        queueCount: data.queueCount || 0,
      });

      [1, 2].forEach((idx) => {
        const now = (data.cashiers && data.cashiers[idx]) ? String(data.cashiers[idx]) : "000";
        if (now !== "000" && now !== lastSpoken[idx]) {
          lastSpoken[idx] = now;
          // blink 3 seconds
          const pill = idx === 1 ? cash1Pill : cash2Pill;
          if (pill) {
            pill.classList.add('blink');
            setTimeout(()=>pill.classList.remove('blink'), 3000);
          }
          speakCall(now, idx);
        }
      });
    } catch (e) {
      // ignore
    }
  }

  setInterval(tick, 1500);
  (async function(){
    await loadBranding();
    applyStatusBranding();
    applyState(state);
    tick();
  })();
})();