(function(){
  async function fetchBranding(){
    const r = await fetch('/api/site/branding', {method:'GET', cache:'no-store'});
    if (!r.ok) return {};
    const j = await r.json();
    const branding = j.branding || {};
    return branding;
  }

  function applyColors(primary, accent){
    const root = document.documentElement;
    if (primary) root.style.setProperty('--primary', primary);
    if (accent)  {
      root.style.setProperty('--primary-soft', accent);
      root.style.setProperty('--accent', accent);
    }
  }

  function applyFavicon(url){
    if (!url) return;
    let link = document.querySelector('link[rel="icon"]');
    if (!link) {
      link = document.createElement('link');
      link.rel = 'icon';
      document.head.appendChild(link);
    }
    link.href = url;
  }

  function applyThemeMode(mode){
    const root = document.documentElement;
    const m = (mode || 'auto').toLowerCase();
    const apply = (isDark) => {
      root.dataset.theme = isDark ? 'dark' : 'light';
      // slightly lighter dark background
      document.body.style.backgroundColor = isDark ? '#111118' : '';
      document.body.style.color = isDark ? '#FFFFFF' : '';
    };
    if (m === 'dark') apply(true);
    else if (m === 'light') apply(false);
    else {
      // auto: after 18:00 local time -> dark, otherwise light
      const h = new Date().getHours();
      // dark from 18:00 to 12:00 (local time)
      apply(h >= 18 || h < 12);
    }
  }

  async function loadAndApply(pageKey){
    try {
      const all = await fetchBranding();
      const global = all['global'] || {};
      // Dark theme only affects status page; all other pages are always light
      if ((pageKey || '').toLowerCase() === 'status') applyThemeMode((all[pageKey] && all[pageKey].themeMode) ? all[pageKey].themeMode : global.themeMode);
      else {
        document.documentElement.dataset.theme = 'light';
      }
      const b = all[pageKey] || null;
      const primary = (b && b.primary) ? b.primary : (global.primary || null);
      const accent  = (b && b.accent) ? b.accent  : (global.accent  || null);
      applyColors(primary, accent);
      if (!b) { applyFavicon(global.faviconUrl || null); window.SiteBranding.current = { global }; return { global }; }
      const logos = document.querySelectorAll('[data-brand-logo]');
      logos.forEach(el => {
        if (b.logoUrl) {
          if (el.tagName === 'IMG') el.src = b.logoUrl;
          else el.style.backgroundImage = `url(${b.logoUrl})`;
        }
      });
      const favicon = (b && b.faviconUrl) ? b.faviconUrl : (global.faviconUrl || null);
      applyFavicon(favicon);
      const merged = { ...b, global };
      window.SiteBranding.current = merged;
      return merged;
    } catch {
      return null;
    }
  }

  window.SiteBranding = { fetchBranding, loadAndApply, current: null };
})();