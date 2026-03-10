/* =========================
   Конфиг (меняй тут)
========================= */
const CONFIG = {
  // Путь к логотипу (подставь свой). Можно абсолютный URL.
  logoSrc: "/poster/img/logo.svg",

  // Ссылка из БД:
  url: "",

  // Размер QR (внутри белого блока)
  qrSize: 560
};

let qr;


function getQueueIdFromPath(){
  const parts = location.pathname.split('/').filter(Boolean);
  // /poster/<id>
  if(parts[0]==='poster' && parts[1] && /^\d+$/.test(parts[1])) return parts[1];
  return null;
}


/* =========================
   QR + UI
========================= */
async function init() {
  const linkText = document.getElementById("linkText");
  const qid = getQueueIdFromPath();
  const joinUrl = qid ? (location.origin + '/' + qid) : CONFIG.url;
  CONFIG.url = joinUrl;
  if (window.SiteBranding && SiteBranding.fetchBranding) {
    try {
      const all = await SiteBranding.fetchBranding();
      const b = all && all.poster ? all.poster : null;
      if (b && b.logoUrl) CONFIG.logoSrc = b.logoUrl;
    } catch {}
  }
  linkText.textContent = joinUrl;
  linkText.href = joinUrl;

  buildQr(joinUrl);

  // сначала подгоним QR под контейнер
  rebuildWithResponsiveSize();

  // потом подгоняем ссылку (уменьшаем только если реально не влезает)
  requestAnimationFrame(() => fitLinkNow("screen"));

  // Кнопки
  document.getElementById("btnPrint").addEventListener("click", async () => {
    await prepareForPrint(); // ✅ логотип останется, но будет встроен data-uri
    window.print();
  });

  document.getElementById("btnDownload").addEventListener("click", async () => {
    await downloadQrSvg();
  });

  window.addEventListener(
    "resize",
    debounce(() => {
      rebuildWithResponsiveSize();
      requestAnimationFrame(() => fitLinkNow("screen"));
    }, 150)
  );

  // Если пользователь закрыл предпросмотр печати (Esc) или распечатал
  window.addEventListener("afterprint", () => {
    cleanupAfterPrint();
    requestAnimationFrame(() => fitLinkNow("screen"));
  });

  // Шрифты могут догрузиться позже и изменить ширины
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(() =>
      requestAnimationFrame(() => fitLinkNow("screen"))
    );
  }
}

function buildQr(url) {
  const mount = document.getElementById("qrMount");
  mount.innerHTML = "";

  qr = new QRCodeStyling({
    type: "svg",
    data: url,
    width: CONFIG.qrSize,
    height: CONFIG.qrSize,

    image: CONFIG.logoSrc,

    // внешний вид как “мягкий” QR
    dotsOptions: { type: "rounded", color: "#000000" },
    cornersSquareOptions: { type: "extra-rounded", color: "#000000" },
    cornersDotOptions: { type: "dot", color: "#000000" },

    backgroundOptions: { color: "#ffffff" },

    imageOptions: {
      crossOrigin: "anonymous",
      margin: 10,
      imageSize: 0.22 // размер логотипа относительно QR
    }
  });

  qr.append(mount);
}

function setLinkFromDb(newUrl) {
  CONFIG.url = newUrl;
  const a=document.getElementById("linkText");
  a.textContent = newUrl;
  a.href = newUrl;
  if (qr) qr.update({ data: newUrl });

  requestAnimationFrame(() => fitLinkNow("screen"));
}

async function downloadQrSvg() {
  if (!qr) return;

  const data = await qr.getRawData("svg");
  const blob = new Blob([data], { type: "image/svg+xml;charset=utf-8" });

  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = "qr-code.svg";
  document.body.appendChild(a);
  a.click();
  a.remove();

  URL.revokeObjectURL(a.href);
}

/* =========================
   Responsive QR size
========================= */
function rebuildWithResponsiveSize() {
  const inner = document.querySelector(".qrFrame__inner");
  if (!inner) return;

  // доступная ширина белого блока
  const padding = 22 * 2; // .qrFrame__inner padding
  const max = inner.clientWidth - padding;

  // лимиты — чтобы не был огромным/мелким
  const size = clamp(max, 240, CONFIG.qrSize);

  if (!qr) return;

  // если сильно поменялся — обновим размер
  qr.update({ width: size, height: size });
}

function clamp(v, min, max) {
  return Math.max(min, Math.min(max, v));
}

function debounce(fn, ms) {
  let t;
  return (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn(...args), ms);
  };
}

/* =========================
   Fit link (экран + печать)
   Уменьшаем шрифт ТОЛЬКО если строка не помещается.
========================= */
function getAvailableWidthForLink(el) {
  const frame = el.closest(".qrFrame");
  if (!frame) return el.parentElement?.clientWidth || 0;

  const cs = getComputedStyle(frame);
  const padL = parseFloat(cs.paddingLeft) || 0;
  const padR = parseFloat(cs.paddingRight) || 0;

  // запас под скругления
  const safety = 18;

  return frame.clientWidth - padL - padR - safety;
}

function fitTextToWidth(el, { minPx = 12, maxPx = 48 } = {}) {
  if (!el) return;

  // ширина доступная именно для текста (с учетом padding самого элемента)
  const cs = getComputedStyle(el);
  const padL = parseFloat(cs.paddingLeft) || 0;
  const padR = parseFloat(cs.paddingRight) || 0;
  const available = el.clientWidth - padL - padR;

  if (!available) return;

  // стартуем с максимума
  let size = maxPx;
  el.style.fontSize = size + "px";

  // если помещается — ок
  if (el.scrollWidth <= el.clientWidth) return;

  // уменьшаем пока не влезет
  while (size > minPx && el.scrollWidth > el.clientWidth) {
    size -= 1;
    el.style.fontSize = size + "px";
  }
}

function fitLinkNow(mode = "screen") {
  const linkEl = document.getElementById("linkText");
  if (!linkEl) return;

  if (mode === "print") {
    fitTextToWidth(linkEl, { minPx: 10, maxPx: 30 });
  } else {
    // на экране крупно, но уменьшаем только если реально не влезло
    fitTextToWidth(linkEl, { minPx: 16, maxPx: 48 });
  }
}


/* =========================
   Print fix (с логотипом!)
   На время печати встраиваем логотип как data-uri, чтобы он не пропал.
========================= */
let __printPrepared = false;
let __originalLogoSrc = null;

async function fetchAsDataUrl(url) {
  const res = await fetch(url, { cache: "force-cache" });
  if (!res.ok) throw new Error("Failed to fetch logo: " + res.status);

  const blob = await res.blob();
  const dataUrl = await new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = reject;
    reader.readAsDataURL(blob);
  });
  return dataUrl;
}

async function prepareForPrint() {
  if (!qr || __printPrepared) return;
  __printPrepared = true;

  __originalLogoSrc = CONFIG.logoSrc;

  // ✅ Встраиваем логотип в data-uri, чтобы печать не теряла <image href="...">
  let embeddedLogo = null;
  try {
    embeddedLogo = await fetchAsDataUrl(CONFIG.logoSrc);
  } catch (e) {
    // если не смогли (например, CORS) — оставим как есть
    embeddedLogo = CONFIG.logoSrc;
  }

  qr.update({ image: embeddedLogo });

  // даём DOM обновиться
  await new Promise((r) => requestAnimationFrame(() => r()));

  rebuildWithResponsiveSize();
  fitLinkNow("print");

  // небольшой таймаут на стабилизацию перед print()
  await new Promise((r) => setTimeout(r, 80));
}

function cleanupAfterPrint() {
  if (!qr) return;

  // возвращаем исходный путь к логотипу
  if (__originalLogoSrc) qr.update({ image: __originalLogoSrc });

  __printPrepared = false;
  __originalLogoSrc = null;

  rebuildWithResponsiveSize();
  fitLinkNow("screen");
}

document.addEventListener("DOMContentLoaded", init);

// Если нужно снаружи дергать:
// window.setLinkFromDb = setLinkFromDb;