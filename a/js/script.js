(function () {
  const cfg = window.APP_CONFIG || {};
  const root = document.documentElement;

  // Apply theme from config
  if (cfg.colors) {
    if (cfg.colors.primary) root.style.setProperty("--primary", cfg.colors.primary);
    if (cfg.colors.bg) root.style.setProperty("--bg", cfg.colors.bg);
  }
  if (cfg.fontFamily) root.style.setProperty("--font", cfg.fontFamily);

  function basePrefix(){
    const p = location.pathname;
    const idx = p.indexOf('/a/');
    return idx >= 0 ? p.slice(0, idx) : '';
  }
  const apiBase = cfg.apiBase || (location.origin + basePrefix() + "/api");
  const parts = location.pathname.split("/").filter(Boolean); // ["a", "{id}", "{token}"]
  const queueId = parts[1];
  const token = parts[2];

  const $ = (id) => document.getElementById(id);

  const el = {
    queueName: $("queueName"),
    statusLine: $("statusLine"),

    cashierBtn: $("cashierBtn"),
    cashierLabel: $("cashierLabel"),

    pauseBtn: $("pauseBtn"),
    pauseIco: $("pauseIco"),
    playIco: $("playIco"),
    hideCashBtn: null,

    pauseAlert: $("pauseAlert"),
    alertTitle: $("alertTitle"),
    alertSub: $("alertSub"),

    currentNumber: $("currentNumber"),
    currentService: $("currentService"),
    currentUuid: $("currentUuid"),
    copyUuidBtn: $("copyUuidBtn"),
    returnBtn: $("returnBtn"),

    inviteNextBtn: $("inviteNextBtn"),
    inviteOutBtn: $("inviteOutBtn"),
    servedBtn: $("servedBtn"),
    addVisitorBtn: $("addVisitorBtn"),
    removeBtn: $("removeBtn"),

    serviceTimer: $("serviceTimer"),
    serviceTimerValue: $("serviceTimerValue"),

    // Modals
    workModal: $("workModal"),
    workCashBtn: $("workCashBtn"),
    workCashLabel: $("workCashLabel"),
    workDoneBtn: $("workDoneBtn"),

    queueModal: $("queueModal"),
    settingsBtn: $("settingsBtn"),
    queueSaveBtn: $("queueSaveBtn"),
    visitorLink: $("visitorLink"),
    statusLink: $("statusLink"),
    queueNameInput: $("queueNameInput"),
    selfRegSwitch: $("selfRegSwitch"),
    requireUserSwitch: $("requireUserSwitch"),
    userPromptInput: $("userPromptInput"),
    capacityInput: $("capacityInput"),
    statusLangLabel: $("statusLangLabel"),

    addModal: $("addModal"),
    visitorIdInput: $("visitorIdInput"),
    serviceSelectBtn: $("serviceSelectBtn"),
    serviceSelectLabel: $("serviceSelectLabel"),
    addSubmitBtn: $("addSubmitBtn"),

    // Sheet
    pickSheet: $("pickSheet"),
    pickTitle: $("pickTitle"),
    pickAction: $("pickAction"),
    pickList: document.querySelector("#pickSheet .sheet__list"),

    confirmModal: $("confirmModal"),
    confirmText: $("confirmText"),
    confirmYes: $("confirmYes"),

    toast: $("toast"),
    backdrop: $("backdrop"),
  };

  function qs(sel, parent = document) {
    return parent.querySelector(sel);
  }

  // --------------------
  // API helpers
  // --------------------
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

  // --------------------
  // Toast
  // --------------------
  let toastTimer = null;
  function toast(msg) {
    if (!el.toast) return;
    el.toast.textContent = msg;
    el.toast.classList.remove("hidden");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.toast.classList.add("hidden"), 1600);
  }

  async function copyText(value) {
    const v = String(value ?? "");
    if (!v) return false;
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(v);
        return true;
      }
    } catch {}
    try {
      const ta = document.createElement("textarea");
      ta.value = v;
      ta.setAttribute("readonly", "");
      ta.style.position = "fixed";
      ta.style.left = "-9999px";
      document.body.appendChild(ta);
      ta.select();
      const ok = document.execCommand("copy");
      document.body.removeChild(ta);
      return !!ok;
    } catch {
      return false;
    }
  }


  // --------------------
  // Modal / Sheet helpers (close on outside click)
  // --------------------
  function showBackdrop(on) {
    if (!el.backdrop) return;
    el.backdrop.classList.toggle("hidden", !on);
  }
  function openModal(modal) {
    if (!modal) return;
    modal.classList.remove("hidden");
    showBackdrop(true);
  }
  function closeModal(modal) {
    if (!modal) return;
    modal.classList.add("hidden");
    // hide backdrop if nothing else open
    const anyOpen = Array.from(document.querySelectorAll(".modal, .sheet")).some((n) => !n.classList.contains("hidden"));
    if (!anyOpen) showBackdrop(false);
  }
  function openSheet(sheet) {
    if (!sheet) return;
    sheet.classList.remove("hidden");
    showBackdrop(true);
  }
  function closeSheet(sheet) {
    if (!sheet) return;
    sheet.classList.add("hidden");
    const anyOpen = Array.from(document.querySelectorAll(".modal, .sheet")).some((n) => !n.classList.contains("hidden"));
    if (!anyOpen) showBackdrop(false);
  }

  // --------------------
  // Confirm modal helper
  // --------------------
  let confirmHandler = null;
  function openConfirm(text, isDanger, onYes) {
    if (!el.confirmModal || !el.confirmText || !el.confirmYes) return;
    el.confirmText.textContent = text;
    el.confirmText.classList.toggle('is-danger', !!isDanger);
    confirmHandler = typeof onYes === 'function' ? onYes : null;
    openModal(el.confirmModal);
  }
  el.confirmYes?.addEventListener('click', async () => {
    try {
      if (confirmHandler) await confirmHandler();
    } finally {
      confirmHandler = null;
      closeModal(el.confirmModal);
    }
  });


  // close buttons
  document.querySelectorAll("[data-close]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const m = btn.closest(".modal");
      if (m) closeModal(m);
    });
  });

  // close by outside click
  document.querySelectorAll(".modal").forEach((m) => {
    m.addEventListener("click", (e) => {
      const panel = qs(".modal__panel", m);
      if (panel && !panel.contains(e.target)) closeModal(m);
    });
  });
  el.pickSheet?.addEventListener("click", (e) => {
    const panel = qs(".sheet__panel", el.pickSheet);
    if (panel && !panel.contains(e.target)) closeSheet(el.pickSheet);
  });
  el.backdrop?.addEventListener("click", () => {
    // close everything
    document.querySelectorAll(".modal").forEach(closeModal);
    closeSheet(el.pickSheet);
    showBackdrop(false);
  });

  // --------------------
  // Dropdowns (select)
  // --------------------
  function wireSelect(selectRoot, btnEl, onPick) {
    if (!selectRoot || !btnEl) return;
    const menu = qs(".select__menu", selectRoot);
    const items = Array.from(selectRoot.querySelectorAll(".select__item"));
    function close() {
      btnEl.setAttribute("aria-expanded", "false");
      menu?.classList.remove("is-open");
      menu && (menu.style.display = "none");
    }
    function open() {
      btnEl.setAttribute("aria-expanded", "true");
      menu?.classList.add("is-open");
      menu && (menu.style.display = "block");
    }
    btnEl.addEventListener("click", (e) => {
      e.preventDefault();
      const expanded = btnEl.getAttribute("aria-expanded") === "true";
      expanded ? close() : open();
    });
    items.forEach((it) => {
      it.addEventListener("click", () => {
        const v = it.getAttribute("data-value") ?? it.textContent.trim();
        onPick(v);
        close();
      });
    });
    document.addEventListener("click", (e) => {
      if (!selectRoot.contains(e.target)) close();
    });
  }

  // --------------------
  // State
  // --------------------
  let cashiers = [];
  let services = [];
  let currentCalled = {}; // { idx: {uuid,displayNumber,service,userId,serviceStartedAt} | null }

  let serviceTimerInterval = null;

  let queueSettings = {
    allowSelfRegistration: true,
    requireUserInput: true,
    userPrompt: 'Введите ваш ID',
    maxCapacity: 1000,
    statusLang: 'auto',
    ttsLang: 'auto',
  };

  let activeCashierIdx = Number(localStorage.getItem("admin_cashier_idx") || 1);
  let pauseEverClicked = false; // for "eye" behavior

  function getActiveCashier() {
    return cashiers.find((c) => Number(c.idx) === Number(activeCashierIdx)) || null;
  }

  function parseAllowedServices(raw) {
    const s = String(raw || '').trim();
    if (!s) return [];
    return s.split(',').map(x=>x.trim()).filter(Boolean);
  }

  function isServiceSupported(serviceCode) {
    const c = getActiveCashier();
    const allowed = parseAllowedServices(c?.allowed_services || c?.allowedServices);
    if (!allowed.length) return true;
    const sc = String(serviceCode || '').trim();
    if (!sc) return true;
    return allowed.includes(sc);
  }

  function setActiveCashier(idx) {
    activeCashierIdx = Number(idx) || 1;
    localStorage.setItem("admin_cashier_idx", String(activeCashierIdx));
    const c = getActiveCashier();
    el.cashierLabel.textContent = c?.name || `Касса ${activeCashierIdx}`;
    el.workCashLabel && (el.workCashLabel.textContent = el.cashierLabel.textContent);
    renderTopControls();
    renderCard();
  }

  function renderStatusLine(queueCount) {
    if (!el.statusLine) return;
    if (!queueCount) el.statusLine.textContent = "В очереди никого нет";
    else el.statusLine.textContent = `В очереди ${queueCount} человек`;
  }

  // --------------------
  // Queue settings modal (real persistence)
  // --------------------
  const langSelectRoot = document.querySelector('[data-dd="langMode"]');
  const langSelectBtn = langSelectRoot ? qs('.select__btn', langSelectRoot) : null;

  function langLabel(v) {
    if (v === 'ru') return 'Русский';
    if (v === 'en') return 'English';
    return 'Auto';
  }

  function syncSettingsUI() {
    if (el.queueNameInput) el.queueNameInput.value = el.queueName.textContent || '';
    if (el.selfRegSwitch) el.selfRegSwitch.checked = !!queueSettings.allowSelfRegistration;
    if (el.requireUserSwitch) el.requireUserSwitch.checked = !!queueSettings.requireUserInput;
    if (el.userPromptInput) {
      el.userPromptInput.value = queueSettings.userPrompt || '';
      el.userPromptInput.disabled = !queueSettings.requireUserInput;
    }
    if (el.capacityInput) el.capacityInput.value = String(queueSettings.maxCapacity || 1000);
    if (el.statusLangLabel) el.statusLangLabel.textContent = langLabel(queueSettings.statusLang);

    // links
    if (el.visitorLink) {
      el.visitorLink.href = `${location.origin}/${queueId}`;
      el.visitorLink.textContent = `${location.origin}/${queueId}`;
    }
    if (el.statusLink) {
      el.statusLink.href = `${location.origin}/status/${queueId}`;
      el.statusLink.textContent = `${location.origin}/status/${queueId}`;
    }
  }

  async function loadSettingsAndOpen() {
    try {
      const s = await apiGet(`/admin/${queueId}/${token}/settings`);
      queueSettings = {
        allowSelfRegistration: !!s.allowSelfRegistration,
        requireUserInput: !!s.requireUserInput,
        userPrompt: s.userPrompt || 'Введите ваш ID',
        maxCapacity: Number(s.maxCapacity || 1000),
        statusLang: s.statusLang || 'auto',
        ttsLang: s.ttsLang || 'auto',
      };
      if (el.queueNameInput) el.queueNameInput.value = s.queueName || '';
      syncSettingsUI();
      openModal(el.queueModal);
    } catch (e) {
      toast('Ошибка загрузки настроек');
    }
  }

  async function saveSettings() {
    const payload = {
      queueName: el.queueNameInput ? el.queueNameInput.value.trim() : undefined,
      allowSelfRegistration: el.selfRegSwitch ? !!el.selfRegSwitch.checked : undefined,
      requireUserInput: el.requireUserSwitch ? !!el.requireUserSwitch.checked : undefined,
      userPrompt: el.userPromptInput ? el.userPromptInput.value.trim() : undefined,
      maxCapacity: el.capacityInput ? Number(String(el.capacityInput.value).replace(/\D+/g,'')) : undefined,
      statusLang: queueSettings.statusLang,
      ttsLang: queueSettings.ttsLang,
    };
    if (!payload.maxCapacity || payload.maxCapacity <= 0) payload.maxCapacity = 1000;

    try {
      await apiPost(`/admin/${queueId}/${token}/settings`, payload);
      closeModal(el.queueModal);
      toast('Сохранено');
      await refresh();
    } catch (e) {
      toast('Ошибка сохранения');
    }
  }

  // wire events
  el.settingsBtn?.addEventListener('click', loadSettingsAndOpen);
  el.queueSaveBtn?.addEventListener('click', saveSettings);
  el.requireUserSwitch?.addEventListener('change', () => {
    queueSettings.requireUserInput = !!el.requireUserSwitch.checked;
    syncSettingsUI();
  });

  wireSelect(langSelectRoot, langSelectBtn, (v) => {
    queueSettings.statusLang = String(v);
    // For now use same value for speech language
    queueSettings.ttsLang = (v === 'ru') ? 'ru-RU' : (v === 'en' ? 'en-US' : 'auto');
    syncSettingsUI();
  });

  function renderTopControls() {
    const c = getActiveCashier();
    const paused = !!Number(c?.paused || 0);
    const hidden = !!Number(c?.hidden || 0);

    // pause/play icons
    el.pauseIco?.classList.toggle("hidden", paused);
    el.playIco?.classList.toggle("hidden", !paused);

    // alert: shows only when paused
    el.pauseAlert?.classList.toggle("hidden", !paused);

    if (paused) {
      el.alertTitle.textContent = "Обслуживание приостановлено";
      el.alertSub.innerHTML = "Чтобы возобновить работу нажмите <span class=\"alert__play\">▶</span>";
    }
  }

  function renderCard() {
    const c = getActiveCashier();
    const paused = !!Number(c?.paused || 0);
    const cur = currentCalled[String(activeCashierIdx)] || null;

    // Toggle action buttons
    const hasCurrent = !!cur;
    const showInvite = !paused && !hasCurrent;
    if (el.inviteNextBtn) el.inviteNextBtn.style.display = showInvite ? '' : 'none';
    if (el.inviteOutBtn) el.inviteOutBtn.style.display = showInvite ? '' : 'none';
    if (el.servedBtn) el.servedBtn.classList.toggle('hidden', !(hasCurrent && !paused));

    // Timer
    if (serviceTimerInterval) { clearInterval(serviceTimerInterval); serviceTimerInterval = null; }
    if (el.serviceTimer) el.serviceTimer.classList.toggle('hidden', !hasCurrent);
    if (hasCurrent && el.serviceTimerValue) {
      const startedAt = cur.serviceStartedAt ? new Date(cur.serviceStartedAt) : null;
      const tick = () => {
        if (!startedAt || isNaN(startedAt.getTime())) { el.serviceTimerValue.textContent = '00:00'; return; }
        const diff = Math.max(0, Date.now() - startedAt.getTime());
        const sec = Math.floor(diff/1000);
        const mm = String(Math.floor(sec/60)).padStart(2,'0');
        const ss = String(sec%60).padStart(2,'0');
        el.serviceTimerValue.textContent = `${mm}:${ss}`;
      };
      tick();
      serviceTimerInterval = setInterval(tick, 1000);
    }

    // Card content
    const hasServices = Array.isArray(services) && services.length > 0;
    const showUserInfo = !!queueSettings.requireUserInput;

    if (!cur) {
      el.currentNumber.textContent = '—';
      if (el.currentService) {
        el.currentService.textContent = '—';
        el.currentService.style.display = hasServices ? '' : 'none';
      }
      if (el.currentUuid) el.currentUuid.textContent = '—';
      const idRow = el.currentUuid?.closest('.card__idrow');
      if (idRow) idRow.style.display = showUserInfo ? '' : 'none';
      el.returnBtn.disabled = true;
      return;
    }

    el.currentNumber.textContent = (cur.displayNumber || '—').replace('#','');

    if (el.currentService) {
      el.currentService.textContent = cur.service || '—';
      el.currentService.style.display = hasServices ? '' : 'none';
    }

    const idRow = el.currentUuid?.closest('.card__idrow');
    if (idRow) idRow.style.display = showUserInfo ? '' : 'none';
    if (el.currentUuid) el.currentUuid.textContent = showUserInfo ? (cur.userId || '—') : '—';

    el.returnBtn.disabled = false;
  }

  // --------------------
  // Workplace modal (cashier selection)
  // --------------------
  function rebuildWorkCashierMenu() {
    const selectRoot = qs("[data-dd=workCash]", el.workModal);
    const menu = qs(".select__menu", selectRoot);
    if (!menu) return;
    menu.innerHTML = "";

    // first item
    const first = document.createElement("button");
    first.type = "button";
    first.className = "select__item";
    first.setAttribute("data-value", "");
    first.textContent = "Выберите кассу";
    menu.appendChild(first);

    cashiers
      .slice()
      .sort((a, b) => Number(a.idx) - Number(b.idx))
      .forEach((c) => {
        const it = document.createElement("button");
        it.type = "button";
        it.className = "select__item";
        it.setAttribute("data-value", String(c.idx));
        it.textContent = c.name;
        menu.appendChild(it);
      });

    wireSelect(selectRoot, el.workCashBtn, (v) => {
      const idx = Number(v);
      if (Number.isFinite(idx) && idx > 0) {
        setActiveCashier(idx);
        rebuildWorkServices();
      }
    });
  }

  function rebuildWorkServices() {
    const box = document.getElementById('workServices');
    const lbl = document.getElementById('workServicesLabel');
    const hint = document.getElementById('workServicesHint');
    if (!box || !lbl || !hint) return;
    box.innerHTML = '';

    if (!services || services.length === 0) {
      lbl.style.display = 'none';
      hint.style.display = 'none';
      box.style.display = 'none';
      return;
    }
    lbl.style.display = '';
    hint.style.display = '';
    box.style.display = '';

    const c = cashiers.find(x=>Number(x.idx)===Number(activeCashierIdx)) || null;
    const allowed = (c && Array.isArray(c.allowedServices) ? c.allowedServices : []);
    const hasRestrictions = allowed && allowed.length > 0;

    services.forEach(s=>{
      const row = document.createElement('div');
      row.className = 'switchrow';
      const checked = !hasRestrictions ? true : allowed.includes(s.code);
      if (cashiers.length === 1 && !checked) row.classList.add('switchrow--bad');
      row.innerHTML = `
        <div class="switchrow__name">${(s.label||s.code).replace(/</g,'&lt;')}</div>
        <label class="switch">
          <input type="checkbox" ${checked?'checked':''} data-srv="${s.code}">
          <span class="switch__ui"></span>
        </label>
      `;
      row.querySelector('input').addEventListener('change', (e)=>{
        if (cashiers.length === 1) {
          row.classList.toggle('switchrow--bad', !e.target.checked);
        }
      });
      box.appendChild(row);
    });
  }

  el.cashierBtn?.addEventListener("click", () => {
    rebuildWorkCashierMenu();
    rebuildWorkServices();
    // set current label
    el.workCashLabel.textContent = el.cashierLabel.textContent;
    openModal(el.workModal);
  });
  el.workDoneBtn?.addEventListener("click", async () => {
    // Save cashier allowed services (if services configured)
    try {
      if (services && services.length) {
        const checks = Array.from(document.querySelectorAll('#workServices input[data-srv]'));
        const allowed = checks.filter(i=>i.checked).map(i=>i.getAttribute('data-srv'));
        // If all selected -> store empty restrictions
        const allSelected = allowed.length === services.length;
        await apiPost(`/admin/${queueId}/${token}/cashier/config`, {
          cashierIdx: activeCashierIdx,
          allowedServices: allSelected ? [] : allowed
        });
      }
      await loadState();
      closeModal(el.workModal);
    } catch(e) {
      console.warn(e);
      closeModal(el.workModal);
    }
  });

  // --------------------
  // Add visitor modal
  // --------------------
  function rebuildServiceMenu() {
    const selectRoot = qs("[data-dd=service]", el.addModal);
    if (selectRoot && (!services || services.length === 0)) {
      // no services configured – hide select and allow adding without service
      selectRoot.style.display = 'none';
      el.serviceSelectBtn.dataset.service = '';
      el.serviceSelectLabel.textContent = '';
      return;
    }
    if (selectRoot) selectRoot.style.display = '';
    const menu = qs(".select__menu", selectRoot);
    if (!menu) return;
    menu.innerHTML = "";

    // placeholder
    const ph = document.createElement("button");
    ph.type = "button";
    ph.className = "select__item";
    ph.setAttribute("data-value", "");
    ph.textContent = "Выберите услугу";
    menu.appendChild(ph);

    services.forEach((s) => {
      const it = document.createElement("button");
      it.type = "button";
      it.className = "select__item";
      it.setAttribute("data-value", s.code);
      it.textContent = s.label || s.code;
      menu.appendChild(it);
    });

    const setPicked = (code) => {
      el.serviceSelectBtn.dataset.service = code;
      if (!code) {
        el.serviceSelectLabel.textContent = "Выберите услугу";
        el.serviceSelectLabel.classList.add("muted");
        return;
      }
      const s = services.find((x) => x.code === code);
      el.serviceSelectLabel.textContent = s?.label || code;
      el.serviceSelectLabel.classList.remove("muted");
    };

    wireSelect(selectRoot, el.serviceSelectBtn, (v) => setPicked(v));
    setPicked(services?.[0]?.code || "");
  }

  el.addVisitorBtn?.addEventListener("click", () => {
    el.visitorIdInput.value = "";
    rebuildServiceMenu();
    openModal(el.addModal);
  });

  el.addSubmitBtn?.addEventListener("click", async () => {
    const userId = el.visitorIdInput.value.trim();
    const service = el.serviceSelectBtn.dataset.service || "";
    if (services && services.length > 0 && !service) return toast("Выберите услугу");
    try {
      const payload = { userId };
      if (services && services.length > 0) payload.service = service;
      const res = await apiPost(`/admin/${queueId}/${token}/ticket/add`, payload);
      closeModal(el.addModal);
      toast(`Добавлен ${res.displayNumber}`);
      await refresh(true);
    } catch (e) {
      console.error(e);
      toast("Ошибка добавления");
    }
  });

  // --------------------
  // Pick sheet (invite out / remove)
  // --------------------
  const pickState = {
    mode: "invite", // invite | remove
    selectedUuid: null,
    selectedNumber: null,
  };

  function avatarDataUrl(seed) {
    // simple colored circle with number (SVG data)
    const n = String(seed).slice(-3);
    const hue = (Number(seed) * 47) % 360;
    const svg = `
      <svg xmlns='http://www.w3.org/2000/svg' width='48' height='48'>
        <rect width='48' height='48' rx='24' fill='hsl(${hue},70%,80%)'/>
        <text x='24' y='30' text-anchor='middle' font-family='Inter,Arial' font-size='16' fill='hsl(${hue},40%,25%)'>${n}</text>
      </svg>`;
    return "data:image/svg+xml;utf8," + encodeURIComponent(svg.trim());
  }

  function makeVisitorRow(item) {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "visitor";
    btn.dataset.uuid = item.uuid;
    btn.dataset.number = String(item.number);
    btn.dataset.service = String(item.service_code || "");
    if (!isServiceSupported(item.service_code)) btn.classList.add("is-unsupported");

    btn.innerHTML = `
      <div class="visitor__ico"><img alt="" /></div>
      <div class="visitor__meta">
        <div class="visitor__num">${String(item.displayNumber || "#000").replace("#", "")}</div>
        <div class="visitor__sub mono">${item.uuid}, ${item.service_code}</div>
      </div>
      <div class="visitor__check" aria-hidden="true" style="opacity:0">
        <svg class="ico" width="22" height="22" viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/>
          <path d="M8 12.5l2.6 2.6L16.5 9.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </div>`;

    const img = btn.querySelector("img");
    img.src = avatarDataUrl(item.number);

    btn.addEventListener("click", () => {
      // unselect all
      Array.from(el.pickList.querySelectorAll(".visitor")).forEach((n) => {
        n.classList.remove("is-selected");
        const c = n.querySelector(".visitor__check");
        if (c) c.style.opacity = "0";
      });
      btn.classList.add("is-selected");
      const c = btn.querySelector(".visitor__check");
      if (c) c.style.opacity = "1";
      pickState.selectedUuid = item.uuid;
      pickState.selectedNumber = item.number;
      el.pickAction.disabled = false;

      const unsupported = !isServiceSupported(item.service_code);
      el.pickTitle.classList.toggle('is-danger', unsupported);

    });

    return btn;
  }

  async function openPickSheet(mode) {
    pickState.mode = mode;
    pickState.selectedUuid = null;
    pickState.selectedNumber = null;
    el.pickAction.disabled = true;

    el.pickTitle.textContent = mode === "invite" ? "Выберите посетителя по номеру" : "Выберите посетителя для удаления";
    el.pickAction.textContent = mode === "invite" ? "Пригласить" : "Удалить";

    el.pickList.innerHTML = "";
    openSheet(el.pickSheet);

    try {
      const res = await apiGet(`/admin/${queueId}/${token}/waiting`);
      const items = res.items || [];
      if (!items.length) {
        const empty = document.createElement("div");
        empty.style.padding = "18px";
        empty.style.opacity = "0.7";
        empty.textContent = "В очереди никого нет";
        el.pickList.appendChild(empty);
        return;
      }
      items.forEach((it) => el.pickList.appendChild(makeVisitorRow(it)));
    } catch (e) {
      console.error(e);
      toast("Не удалось загрузить очередь");
    }
  }

  el.inviteOutBtn?.addEventListener("click", () => openPickSheet("invite"));
  el.removeBtn?.addEventListener("click", () => openPickSheet("remove"));

  el.pickAction?.addEventListener("click", async () => {
    if (!pickState.selectedUuid || !pickState.selectedNumber) return;
    try {
      if (pickState.mode === "invite") {
        await apiPost(`/admin/${queueId}/${token}/invite/byNumber`, {
          cashierIdx: activeCashierIdx,
          number: pickState.selectedNumber,
        });
        toast(`Приглашен #${String(pickState.selectedNumber).padStart(3, "0")}`);
      } else {
        await apiPost(`/admin/${queueId}/${token}/ticket/remove`, { uuid: pickState.selectedUuid });
        toast("Удалено");
      }
      closeSheet(el.pickSheet);
      await refresh(true);
    } catch (e) {
      console.error(e);
      toast("Ошибка");
    }
  });

  // --------------------
  // Main actions
  // --------------------
  el.inviteNextBtn?.addEventListener("click", async () => {
    const c = getActiveCashier();
    const paused = !!Number(c?.paused || 0);
    if (paused) return toast("Касса на паузе");

    // Peek next ticket from waiting list (supported first), then confirm
    try {
      const resW = await apiGet(`/admin/${queueId}/${token}/waiting`);
      const items = (resW.items || []).slice().sort((a,b)=>Number(a.number)-Number(b.number));
      if (!items.length) return toast("Очередь пустая");

      let candidate = null;
      const supported = items.filter(it => isServiceSupported(it.service_code));
      candidate = supported[0] || items[0];
      const isUnsup = !isServiceSupported(candidate.service_code);

      const num = String(candidate.displayNumber || ('#'+String(candidate.number).padStart(3,'0'))).replace('#','');
      const svc = candidate.service_code ? `, ${candidate.service_code}` : '';
      const uid = candidate.user_id ? `, ${candidate.user_id}` : '';
      const txt = `Номер следующего посетителя ${num}${uid}${svc}. Хотите пригласить следующего посетителя?`;

      openConfirm(txt, isUnsup, async () => {
        const r = await apiPost(`/admin/${queueId}/${token}/invite/next`, { cashierIdx: activeCashierIdx, forceUnsupported: isUnsup });
        if (!r.ok) {
          if (r.reason === 'UNSUPPORTED') return toast('Услуга не поддерживается');
          if (r.reason === 'EMPTY') return toast('Очередь пустая');
          return toast('Не удалось пригласить');
        }
        toast(`Приглашен ${r.ticket.displayNumber}`);
        await refresh(true);
      });
    } catch (e) {
      console.error(e);
      toast("Ошибка вызова");
    }
  });



  el.servedBtn?.addEventListener('click', async () => {
    const cur = currentCalled[String(activeCashierIdx)] || null;
    if (!cur?.uuid) return;
    try {
      await apiPost(`/admin/${queueId}/${token}/ticket/served`, { uuid: cur.uuid, cashierIdx: activeCashierIdx });
      toast('Клиент обслужен');
      await refresh(true);
    } catch (e) {
      console.error(e);
      toast('Ошибка');
    }
  });
  el.pauseBtn?.addEventListener("click", async () => {
    const c = getActiveCashier();
    const paused = !Number(c?.paused || 0);
    try {
      await apiPost(`/admin/${queueId}/${token}/cashier/pause`, { cashierIdx: activeCashierIdx, paused });
      await refresh(false);
      toast(paused ? "Пауза" : "Продолжить");
    } catch (e) {
      console.error(e);
      toast("Ошибка паузы");
    }
  });

  // hideCashBtn removed

  el.returnBtn?.addEventListener("click", async () => {
    const cur = currentCalled[String(activeCashierIdx)] || null;
    if (!cur?.uuid) return;
    try {
      await apiPost(`/admin/${queueId}/${token}/ticket/return`, { uuid: cur.uuid, cashierIdx: activeCashierIdx });
      toast("Возвращено в очередь");
      await refresh(true);
    } catch (e) {
      console.error(e);
      toast("Ошибка");
    }
  });

  el.copyUuidBtn?.addEventListener("click", async () => {
    const v = (el.currentUuid.textContent || "").trim();
    if (!v || v === "—") return;
    const ok = await copyText(v);
    toast(ok ? "Скопировано" : "Не удалось скопировать");
  });

  // settings button wired earlier to load from API and open modal

  // --------------------
  // Refresh loop
  // --------------------
  let inFlight = false;
  async function refresh(forceWorkMenus) {
    if (inFlight) return;
    inFlight = true;
    try {
      const st = await apiGet(`/admin/${queueId}/${token}/state`);
      el.queueName.textContent = st.queueName || `Очередь ${queueId}`;
      renderStatusLine(st.queueCount || 0);
      cashiers = st.cashiers || [];
      services = st.services || [];
      currentCalled = st.currentCalled || {};

      // ensure active cashier exists
      const exists = cashiers.some((c) => Number(c.idx) === Number(activeCashierIdx));
      if (!exists) activeCashierIdx = Number(cashiers?.[0]?.idx || 1);
      setActiveCashier(activeCashierIdx);

      if (forceWorkMenus) {
        // keep add/work menus in sync when opening
        // (no-op here)
      }
    } finally {
      inFlight = false;
    }
  }

  // init
  (async function init() {
    if (!queueId || !token) {
      toast("Неверная ссылка админки");
      return;
    }
    try {
      await refresh(true);
      setInterval(() => refresh(false), 1200);
    } catch (e) {
      console.error(e);
      toast("API/БД не готовы");
    }
  })();
})();
