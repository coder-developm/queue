// =========================
// CONFIG: логотип + цвета
// =========================
const CONFIG = {
  logoSrc: "/create/success/img/logo.svg",

  colors: {
    primary: "#7B57FF",
    link: "#7B57FF",
    cardBg: "#f3f3f3",
    modalBackdrop: "rgba(0,0,0,.55)",
    okBg: "#7B57FF",
  }
};

// применяем конфиг в CSS-переменные
function applyThemeFromConfig() {
  const root = document.documentElement;
  root.style.setProperty("--primary", CONFIG.colors.primary);
  root.style.setProperty("--link", CONFIG.colors.link);
  root.style.setProperty("--card-bg", CONFIG.colors.cardBg);
  root.style.setProperty("--modal-backdrop", CONFIG.colors.modalBackdrop);
  root.style.setProperty("--ok-bg", CONFIG.colors.okBg);
}

applyThemeFromConfig();

// логотип
const logoEl = document.getElementById("logo");
logoEl.src = CONFIG.logoSrc;

// =========================
// Modal logic
// =========================
const modal = document.getElementById("modal");
const modalOk = document.getElementById("modalOk");
const modalBackdrop = document.getElementById("modalBackdrop");

let lastActiveEl = null;

function openModal() {
  lastActiveEl = document.activeElement;
  modal.classList.add("is-open");
  modal.setAttribute("aria-hidden", "false");
  modalOk.focus();
}

function closeModal() {
  modal.classList.remove("is-open");
  modal.setAttribute("aria-hidden", "true");
  if (lastActiveEl && typeof lastActiveEl.focus === "function") lastActiveEl.focus();
}

modalOk.addEventListener("click", closeModal);
modalBackdrop.addEventListener("click", closeModal);

document.addEventListener("keydown", (e) => {
  if (e.key === "Escape" && modal.classList.contains("is-open")) closeModal();
});

// =========================
// Copy logic
// =========================
const copyBtn = document.getElementById("copyBtn");
const infoCard = document.getElementById("infoCard");

async function copyText(text) {
  // modern clipboard
  if (navigator.clipboard && window.isSecureContext) {
    await navigator.clipboard.writeText(text);
    return;
  }

  // fallback
  const ta = document.createElement("textarea");
  ta.value = text;
  ta.setAttribute("readonly", "");
  ta.style.position = "fixed";
  ta.style.left = "-9999px";
  document.body.appendChild(ta);
  ta.select();
  document.execCommand("copy");
  document.body.removeChild(ta);
}

copyBtn.addEventListener("click", async () => {
  // копируем именно "человеческий" текст карточки
  const textToCopy = infoCard.innerText.trim();

  try {
    await copyText(textToCopy);
  } catch (err) {
    // даже если копирование не удалось — поведение можно оставить тем же
    // (но при желании можно поменять текст в модалке)
    console.warn("Copy failed:", err);
  }

  openModal();
});

// =========================
// Dynamic queue info + back to create
// =========================
const createMore = document.getElementById('createMore');
const cardTitleValue = document.querySelector('.card-title__value');
const infoText = document.getElementById('infoText');

function getCreateAccessToken() {
  // /create/success/<token>
  const parts = location.pathname.split('/').filter(Boolean);
  if (parts[0] === 'create' && parts[1] === 'success' && parts[2]) return parts[2];
  return sessionStorage.getItem('createAccessToken');
}

function buildLine(label, href) {
  const line = document.createElement('div');
  line.className = 'line';
  const k = document.createElement('span');
  k.className = 'k';
  k.textContent = label;
  const a = document.createElement('a');
  a.className = 'v';
  a.href = href;
  a.target = '_blank';
  a.rel = 'noreferrer';
  a.textContent = location.origin + href;
  line.appendChild(k);
  line.appendChild(a);
  return line;
}

try {
  const raw = sessionStorage.getItem('lastCreatedQueue');
  const data = raw ? JSON.parse(raw) : null;
  if (data && data.urls) {
    if (cardTitleValue) cardTitleValue.textContent = data.queueName || ('Очередь ' + data.publicId);
    if (infoText) {
      infoText.innerHTML = '';
      infoText.appendChild(buildLine('Ссылка на очередь (для QR кода):', data.urls.queue));
      infoText.appendChild(buildLine('Плакат с QR кодом:', data.urls.poster));
      infoText.appendChild(buildLine('Управление очередью:', data.urls.admin));
      infoText.appendChild(buildLine('Табло статуса очереди:', data.urls.status));
    }
  }
} catch (e) {
  console.warn('Failed to read lastCreatedQueue:', e);
}

if (createMore) {
  const t = getCreateAccessToken();
  createMore.href = t ? ('/create/' + t) : '/create';
}