(function () {
  const cfg = window.APP_CONFIG || {};
  const $ = (sel) => document.querySelector(sel);
  const $$ = (sel) => Array.from(document.querySelectorAll(sel));

  // ---- URL / API helpers ----
  function basePrefix(){
    // Supports installs in subfolders like /que2/...
    const p = location.pathname;
    const cut = ['/admin','/status','/poster','/a','/create'];
    for (const c of cut){
      const idx = p.indexOf(c);
      if (idx >= 0) return p.slice(0, idx);
    }
    // For public queue page like /123 or /que2/123
    const parts = p.split('/').filter(Boolean);
    if (parts.length >= 2 && /^\d+$/.test(parts[parts.length-1])) {
      return '/' + parts.slice(0, -1).join('/');
    }
    return '';
  }

  const apiBase = cfg.apiBase || (location.origin + basePrefix() + "/api");

  function getQueueIdFromPath() {
    // supports /<id> and /<base>/<id>
    const parts = location.pathname.split("/").filter(Boolean);
    for (let i = parts.length - 1; i >= 0; i--) {
      if (/^\d+$/.test(parts[i])) return parts[i];
    }
    return cfg.queueId || null;
  }

  const queueId = getQueueIdFromPath();
  if (!queueId) return console.warn("No queueId in URL");

  const LS_KEY = `queue_ticket_${queueId}`;
  let requireUserInput = true;

  async function apiGet(path) {
    const r = await fetch(apiBase + path, { method: "GET" });
    if (!r.ok) throw new Error(await r.text());
    return r.json();
  }
  async function apiPost(path, body) {
    const r = await fetch(apiBase + path, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body || {}),
    });
    if (!r.ok) throw new Error(await r.text());
    return r.json();
  }

  function humanWord(n) {
    return window.I18N ? window.I18N.humanWord(n) : "человек";
  }

  // ---- Elements ----
  const logo = $("#logo");
  const ringEl = $(".ring");

  const joinTitle = $("#joinTitle");
  const joinSubtitle = $("#joinSubtitle");
  const idInput = $("#idInput");
  const joinBtn = $("#joinBtn");

  const errorBanner = $("#errorBanner");

  const select = $("#serviceSelect");
  const selectHead = $("#selectHead");
  const selectText = $("#selectText");
  const selectList = $("#selectList");

  const queueNameTop = $("#queueNameTop");
  const queueNameTop2 = $("#queueNameTop2");

  const ticketNum = $("#ticketNum");
  const bigLine1 = $("#bigLine1");
  const bigLine2 = $("#bigLine2");
  const eta = $("#eta");

  const ticketNum2 = $("#ticketNum2");
  const deskLine = $("#deskLine");

  const notifBtn = $("#notifBtn");
  const leaveBtn = $("#leaveBtn");
  const returnBtn = $("#returnBtn");

  const leaveModal = $("#leaveModal");
  const confirmLeaveBtn = $("#confirmLeaveBtn");
  const cancelLeaveBtn = $("#cancelLeaveBtn");

  const inviteModal = $("#inviteModal");
  const inviteDesk = $("#inviteDesk");
  const inviteOkBtn = $("#inviteOkBtn");

  if (logo && cfg.logoUrl) logo.src = cfg.logoUrl;

  // ---- Views ----
  const viewNodes = $$(".view");
  function showView(name) {
    viewNodes.forEach((n) => (n.hidden = n.dataset.view !== name));
  }

  // ---- Select ----
  let services = [];
  let selectedService = null;
  let currentServiceLabel = "";

  function renderSelect() {
    selectList.innerHTML = "";
    services.forEach((s) => {
      const item = document.createElement("div");
      item.className = "select__item";
      if (s._unsupported) item.classList.add('is-bad');
      item.textContent = s.label || s.code;
      item.addEventListener("click", () => {
        selectedService = s.code;
        currentServiceLabel = s.label || s.code || "";
        closeSelect();
        updateSelectText();
        applyServiceTitle();
        updateJoinButtonState();
      });
      selectList.appendChild(item);
    });
  }
  function applyServiceTitle() {
    const txt = currentServiceLabel || "";
    if (queueNameTop) queueNameTop.textContent = txt;
    if (queueNameTop2) queueNameTop2.textContent = txt;
    if (joinSubtitle && txt) joinSubtitle.textContent = txt;
  }

  function updateSelectText() {
    if (!selectedService) {
      selectText.textContent = "Выберите услугу";
      selectText.classList.remove("is-value");
    } else {
      const s = services.find((x) => x.code === selectedService);
      selectText.textContent = s?.label || selectedService;
      selectText.classList.add("is-value");
    }
  }
  function openSelect() {
    select.classList.add("is-open");
    selectList.hidden = false;
    selectHead.setAttribute("aria-expanded", "true");
  }
  function closeSelect() {
    select.classList.remove("is-open");
    selectList.hidden = true;
    selectHead.setAttribute("aria-expanded", "false");
  }
  function toggleSelect() {
    if (select.classList.contains("is-open")) closeSelect();
    else openSelect();
  }
  selectHead.addEventListener("click", toggleSelect);
  document.addEventListener("click", (e) => {
    if (!select.contains(e.target)) closeSelect();
  });

  // ---- Validation ----
  function isValidId(v) {
    if (!v) return false;
    return /^[0-9a-f-]+$/i.test(v.trim());
  }
  function setError(on) {
    errorBanner.hidden = !on;
    if (on) {
      idInput.style.borderColor = "rgba(226,75,75,.9)";
      idInput.style.boxShadow = "0 0 0 3px rgba(226,75,75,.14)";
    } else {
      idInput.style.borderColor = "";
      idInput.style.boxShadow = "";
    }
  }
  function updateJoinButtonState() {
    const idOk = requireUserInput ? isValidId(idInput.value) : true;
    const serviceOk = (services && services.length > 0) ? !!selectedService : true;
    const ok = idOk && serviceOk;
    joinBtn.disabled = !ok;
  }
  idInput.addEventListener("input", () => {
    if (!requireUserInput) return;
    const v = idInput.value.trim();
    if (!v) setError(false);
    else setError(!isValidId(v));
    updateJoinButtonState();
  });

  // ---- Modals ----
  function openModal(m) {
    if (!m) return;
    m.hidden = false;
  }
  function closeModal(m) {
    if (!m) return;
    m.hidden = true;
  }
  $$(".modal").forEach((m) => {
    m.addEventListener("click", (e) => {
      const close = e.target?.getAttribute?.("data-close");
      if (close) closeModal(m);
    });
  });
  inviteOkBtn?.addEventListener("click", () => closeModal(inviteModal));

  
  // ---- Notifications (browser notifications) ----
  const NOTIF_KEY = `queue_notif_${queueId}`;
  let notifEnabled = localStorage.getItem(NOTIF_KEY) === "1";

  async function enableNotifications() {
    if (!("Notification" in window)) return false;
    try {
      const perm = await Notification.requestPermission();
      if (perm === "granted") {
        notifEnabled = true;
        localStorage.setItem(NOTIF_KEY, "1");
        return true;
      }
    } catch {}
    return false;
  }

  function maybeNotify(title, body) {
    if (!notifEnabled) return;
    if (!("Notification" in window)) return;
    if (Notification.permission !== "granted") return;
    try { new Notification(title, { body }); } catch {}
  }
// ---- Ticket state ----
  let pollTimer = null;
  let lastCallKey = null; // чтобы модалку “Вы приглашены” показать один раз
  let notifyAudio = null;
  async function ensureNotifyAudio() {
    if (notifyAudio) return notifyAudio;
    try {
      const all = await (window.SiteBranding ? window.SiteBranding.fetchBranding() : Promise.resolve({}));
      const global = (all && all['global']) ? all['global'] : {};
      const url = global.notifySoundUrl || '/sounds/notify.wav';
      notifyAudio = new Audio(url);
      notifyAudio.preload = 'auto';
    } catch {
      notifyAudio = new Audio('/sounds/notify.wav');
    }
    return notifyAudio;
  }
  async function calledSequence(cashierName, displayNumber) {
    // 1) sound
    await playNotifySound();
    // 2) ring turns green
    if (ringEl) ringEl.classList.add("is-called");
    // 3) browser notification
    maybeNotify("Вас пригласили", `${displayNumber} — ${cashierName || "Касса"}`);
    // 4) open modal after short delay
    setTimeout(() => {
      // hide "Понятно" on called stage
      if (inviteOkBtn) inviteOkBtn.hidden = true;
      openModal(inviteModal);
    }, 350);
  }
function playNotifySound() {
    return new Promise((resolve) => {
      const url = (cfg.notifySoundUrl || "/sounds/notify.wav");
      try {
        const a = new Audio(url);
        a.volume = 1;
        a.addEventListener("ended", () => resolve(), { once: true });
        a.addEventListener("error", () => resolve(), { once: true });
        // Some browsers don't fire ended; fallback
        setTimeout(resolve, 1200);
        a.play().catch(() => resolve());
      } catch { resolve(); }
    });
  }

  async function loadQueue() {
    let q;
    try {
      q = await apiGet(`/queue/${queueId}`);
    } catch (e) {
      // Queue not found
      location.replace("/404.html");
      return;
    }

    // Apply branding (colors + logos + custom texts)
    const b = q.branding || {};
    if (b.primary) document.documentElement.style.setProperty('--primary', b.primary);
    if (b.accent) document.documentElement.style.setProperty('--primary-soft', b.accent);
    if (logo) {
      const src = b.logoQueue || cfg.logoUrl;
      if (src) logo.src = src;
    }

    const texts = b.texts || {};
    joinTitle.textContent = `Встать в очередь «${q.queueName}»?`;
    if (texts.joinTitle) joinTitle.textContent = String(texts.joinTitle).replace('{name}', q.queueName);

    requireUserInput = q.requireUserInput !== false;

    currentServiceLabel = '';
    applyServiceTitle();

    // services: always allow user to join regardless of cashier filters
    services = (q.services || []).map(x=>({ ...x, _unsupported:false }));
    // if no services configured – hide selection
    if (!services.length) {
      selectedService = null;
      currentServiceLabel = q.queueName || '';
      if (select) select.style.display = 'none';
      applyServiceTitle();
    } else {
      if (select) select.style.display = '';
      renderSelect();
      updateSelectText();
      applyServiceTitle();
    }

    if (q.userPrompt) idInput.placeholder = q.userPrompt;
    const mask = (q.inputMask || 'uuid');
    if (mask === 'digits') {
      idInput.inputMode = 'numeric';
      idInput.pattern = '[0-9]*';
    } else {
      idInput.inputMode = 'text';
      idInput.removeAttribute('pattern');
    }

    // if input not required – hide field visually
    const fieldWrap = idInput.closest('.field');
    if (!requireUserInput) {
      idInput.value = '';
      if (fieldWrap) fieldWrap.style.display = 'none';
    } else {
      if (fieldWrap) fieldWrap.style.display = '';
    }

    // Disable self registration if turned off in settings
    if (q.allowSelfRegistration === false) {
      joinBtn.disabled = true;
      if (errorBanner) {
        errorBanner.hidden = false;
        errorBanner.textContent = window.I18N ? window.I18N.t('self_reg_disabled') : 'Самостоятельная регистрация отключена';
      }
    }

    updateJoinButtonState();
  }

  function applyWaitingView(displayNumber, ahead) {
    const I = window.I18N;
    if (ringEl) ringEl.classList.remove("is-called");
    ticketNum.textContent = displayNumber;
    if (ahead <= 0) {
      bigLine1.textContent = I ? I.t('before_you_none') : "перед вами никого нет";
      bigLine2.textContent = I ? I.t('you_will_be_called') : "— вас скоро вызовут";
    } else {
      bigLine1.textContent = I ? I.t('before_you') : "перед вами";
      bigLine2.textContent = `${ahead} ${humanWord(ahead)}`;
    }
    if (eta) eta.textContent = "";
    showView("waiting");
  }

  function applyCalledView(displayNumber, cashierName) {
    ticketNum2.textContent = displayNumber;
    deskLine.textContent = cashierName || "Касса";
    inviteDesk.textContent = cashierName || "Касса";
    showView("called");
  }

  async function pollTicket(uuid) {
    try {
      const t = await apiGet(`/ticket/${uuid}`);
      if (t.service && services && services.length) {
        const svc = services.find((x) => x.code === t.service);
        currentServiceLabel = (svc && (svc.label || svc.code)) || currentServiceLabel;
        applyServiceTitle();
      }

      if (t.status === "waiting") {
        applyWaitingView(t.displayNumber, t.ahead);
        return;
      }

      if (t.status === "called") {
        applyCalledView(t.displayNumber, t.calledCashierName);

        const callKey = `${t.displayNumber}_${t.calledCashierIdx}`;
        if (lastCallKey !== callKey) {
          lastCallKey = callKey;
          calledSequence(t.calledCashierName, t.displayNumber);
        }
        return;
      }

      localStorage.removeItem(LS_KEY);
      showView("left");
    } catch (e) {
      localStorage.removeItem(LS_KEY);
      showView("join");
    }
  }

  function startPolling(uuid) {
    stopPolling();
    pollTicket(uuid);
    pollTimer = setInterval(() => pollTicket(uuid), 1500);
  }
  function stopPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = null;
  }

  
  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    return Uint8Array.from([...rawData].map((c) => c.charCodeAt(0)));
  }

  async function registerPushSubscription(ticketUuid) {
    if (!("serviceWorker" in navigator)) return false;
    const reg = await navigator.serviceWorker.register(basePrefix() + '/service-worker.js');
    if (!("PushManager" in window)) return true;
    try {
      let sub = await reg.pushManager.getSubscription();
      if (!sub) {
        let vapid = null;
        try {
          const cfgPush = await apiGet('/push/public-config');
          if (cfgPush && cfgPush.publicKey) vapid = cfgPush.publicKey;
        } catch {}
        const opts = vapid ? { userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(vapid) } : { userVisibleOnly: true };
        sub = await reg.pushManager.subscribe(opts);
      }
      try { const raw = sub.toJSON ? sub.toJSON() : sub; raw.appData = Object.assign({}, raw.appData || {}, { ticketPath: basePrefix() + '/' + queueId, basePrefix: basePrefix() }); await apiPost(`/queue/${queueId}/push-subscribe`, { ticketUuid: ticketUuid || null, subscription: raw }); } catch {}
      return true;
    } catch {
      return true;
    }
  }

  notifBtn?.addEventListener("click", async () => {
    const ok = await enableNotifications();
    if (ok) {
      const ticketUuid = localStorage.getItem(LS_KEY);
      await registerPushSubscription(ticketUuid);
      notifBtn.textContent = "Уведомления включены";
      notifBtn.disabled = true;
    }
  });
// ---- Actions ----
  joinBtn.addEventListener("click", async () => {
    if (requireUserInput && !isValidId(idInput.value)) {
      setError(true);
      return;
    }
    setError(false);

    try {
      const payload = {
        userId: requireUserInput ? idInput.value.trim() : "",
      };
      if (services && services.length > 0) payload.service = selectedService;
      const res = await apiPost(`/queue/${queueId}/join`, payload);
      localStorage.setItem(LS_KEY, res.ticketUuid);
      if (selectedService && services && services.length) {
        const svc = services.find((x)=>x.code===selectedService);
        currentServiceLabel = (svc && (svc.label || svc.code)) || currentServiceLabel;
        applyServiceTitle();
      }
      if (notifEnabled) {
        try { await registerPushSubscription(res.ticketUuid); } catch {}
      }
      lastCallKey = null;
      if (ringEl) ringEl.classList.remove('is-called');
      startPolling(res.ticketUuid);
    } catch (e) {
      console.error(e);
      // Try to show meaningful message
      let msg = null;
      try {
        const j = JSON.parse(String(e.message || e));
        if (j?.error === 'SELF_REG_DISABLED') msg = window.I18N ? window.I18N.t('self_reg_disabled') : 'Самостоятельная регистрация отключена';
        if (j?.error === 'QUEUE_FULL') msg = window.I18N ? window.I18N.t('queue_full') : 'Очередь заполнена';
      } catch {}
      if (msg && errorBanner) {
        errorBanner.hidden = false;
        errorBanner.textContent = msg;
      } else {
        setError(true);
      }
    }
  });

  leaveBtn?.addEventListener("click", () => openModal(leaveModal));
  confirmLeaveBtn?.addEventListener("click", async () => {
    closeModal(leaveModal);
    const uuid = localStorage.getItem(LS_KEY);
    if (uuid) {
      try {
        await apiPost(`/ticket/${uuid}/leave`, {});
      } catch {}
    }
    localStorage.removeItem(LS_KEY);
    stopPolling();
    showView("left");
  });
  cancelLeaveBtn?.addEventListener("click", () => closeModal(leaveModal));

  returnBtn?.addEventListener("click", () => {
    localStorage.removeItem(LS_KEY);
    stopPolling();
    if (ringEl) ringEl.classList.remove('is-called');
    showView("join");
  });

  // ---- init ----
  (async function init() {
    showView("join");
    await loadQueue();

    const saved = localStorage.getItem(LS_KEY);
    if (notifEnabled && notifBtn) {
      notifBtn.textContent = 'Уведомления включены';
      notifBtn.disabled = true;
      try { await registerPushSubscription(saved || null); } catch {}
    }
    if (saved) {
      showView("waiting");
      startPolling(saved);
    }

    updateJoinButtonState();
  })();
})();