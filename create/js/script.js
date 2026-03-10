/* ====== Create queue page: now calls backend API ====== */
(function () {
  const cfg = window.APP_CONFIG || {};
  function basePrefix(){
    const p = location.pathname;
    const idx = p.indexOf('/create');
    return idx >= 0 ? p.slice(0, idx) : '';
  }
  const apiBase = cfg.apiBase || (location.origin + basePrefix() + "/api");

  const el = {
    logoImg: document.getElementById("logoImg"),
    queueName: document.getElementById("queueName"),
    requireToggle: document.getElementById("requireToggle"),
    userPrompt: document.getElementById("userPrompt"),
    userPromptWrap: document.getElementById("userPromptWrap"),
    inputMask: document.getElementById("inputMask"),
    maskWrap: document.getElementById("maskWrap"),
    cashiersList: document.getElementById("cashiersList"),
    addCashierBtn: document.getElementById("addCashierBtn"),
    servicesList: document.getElementById("servicesList"),
    addServiceBtn: document.getElementById("addServiceBtn"),
    createBtn: document.getElementById("createBtn"),
  };

  // logo from /create/img/logo.svg
  if (el.logoImg) el.logoImg.src = "/create/img/logo.svg";

  function getCreateAccessToken() {
    // /create/<token>
    const parts = location.pathname.split('/').filter(Boolean);
    if (parts[0] === 'create' && parts[1] && parts[1] !== 'success') return parts[1];
    return null;
  }
  const accessToken = getCreateAccessToken();
  if (accessToken) sessionStorage.setItem('createAccessToken', accessToken);

  // ----- UI helpers (match existing CSS: .row + slide-right delete) -----
  function closeAllRows() {
    document.querySelectorAll(".row.is-open").forEach((r) => r.classList.remove("is-open"));
  }

  function makeRowInput(placeholder, value = "") {
    const row = document.createElement("div");
    row.className = "row";

    const main = document.createElement("div");
    main.className = "row-main";

    const minus = document.createElement("button");
    minus.type = "button";
    minus.className = "icon-minus";
    minus.textContent = "−";
    minus.addEventListener("click", (e) => {
      e.stopPropagation();
      const open = row.classList.toggle("is-open");
      if (open) {
        document.querySelectorAll(".row.is-open").forEach((r) => {
          if (r !== row) r.classList.remove("is-open");
        });
      }
    });

    const input = document.createElement("input");
    input.type = "text";
    input.className = "input";
    input.placeholder = placeholder;
    input.value = value;
    input.autocomplete = "off";
    input.addEventListener("input", validate);

    main.appendChild(minus);
    main.appendChild(input);

    const del = document.createElement("button");
    del.type = "button";
    del.className = "delete-slide-right";
    del.textContent = "Удалить";
    del.addEventListener("click", (e) => {
      e.stopPropagation();
      row.remove();
      validate();
    });

    row.appendChild(main);
    row.appendChild(del);
    return row;
  }

  // init: no default cashiers/services

  el.addCashierBtn.addEventListener("click", () => {
    const n = el.cashiersList.querySelectorAll("input").length + 1;
    el.cashiersList.appendChild(makeRowInput(`Касса ${n}`, `Касса ${n}`));
    validate();
  });

  el.addServiceBtn.addEventListener("click", () => {
    el.servicesList.appendChild(makeRowInput("Название услуги", ""));
    validate();
  });

  // ----- Switch: it's a button role=switch (no .checked, no change event) -----
  function setRequire(on) {
    el.requireToggle.setAttribute("aria-checked", on ? "true" : "false");
    el.userPromptWrap.classList.toggle("hidden", !on);
    el.maskWrap.classList.toggle("hidden", !on);
    validate();
  }
  function getRequire() {
    return el.requireToggle.getAttribute("aria-checked") === "true";
  }
  el.requireToggle.addEventListener("click", () => setRequire(!getRequire()));
  setRequire(false);

  // close slide-delete when clicking outside
  document.addEventListener("click", (e) => {
    const withinRow = e.target && (e.target.closest?.(".row") || e.target.closest?.(".add-btn"));
    if (!withinRow) closeAllRows();
  });

  async function apiPost(path, body) {
    const r = await fetch(apiBase + path, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body || {}),
    });
    if (!r.ok) throw new Error(await r.text());
    return r.json();
  }

  function collectInputs(listEl) {
    const raw = Array.from(listEl.querySelectorAll("input"))
      .map((i) => (i.value || '').trim())
      .filter(Boolean);
    // De-duplicate (case-insensitive) to prevent DB unique constraint errors
    const seen = new Set();
    const out = [];
    for (const v of raw) {
      const k = v.toLowerCase();
      if (seen.has(k)) continue;
      seen.add(k);
      out.push(v);
    }
    return out;
  }

  function validate() {
    const queueName = el.queueName.value.trim();
    let ok = !!queueName;
    if (getRequire()) {
      const prompt = (el.userPrompt.value || '').trim();
      ok = ok && prompt.length > 0;
    }
    el.createBtn.disabled = !ok;
  }

  el.queueName.addEventListener("input", validate);
  el.userPrompt.addEventListener("input", validate);
  validate();

  el.createBtn.addEventListener("click", async () => {
    const queueName = el.queueName.value.trim();
    if (!queueName) {
      alert("Введите имя очереди");
      return;
    }

    const cashiers = collectInputs(el.cashiersList);
    const services = collectInputs(el.servicesList);

    try {
      const res = await apiPost("/queues", {
        queueName,
        createToken: sessionStorage.getItem('createAccessToken') || undefined,
        requireUserInput: getRequire(),
        userPrompt: el.userPrompt.value.trim(),
        inputMask: (el.inputMask && el.inputMask.value) ? el.inputMask.value : 'uuid',
        cashiers, // can be empty -> backend will create "Касса 1"
        services, // can be empty -> service selection is hidden
      });

      // show success UI
      sessionStorage.setItem('lastCreatedQueue', JSON.stringify({
        queueName,
        publicId: res.publicId,
        adminToken: res.adminToken,
        urls: res.urls,
      }));

      // keep access token in URL if we have it
      const t = sessionStorage.getItem('createAccessToken');
      location.href = t ? (`/create/success/${t}`) : '/create/success';
    } catch (e) {
      console.error(e);
      alert("Ошибка создания очереди (проверь API и БД)");
    }
  });
})();