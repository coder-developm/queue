(async function(){
  const $ = (s)=>document.querySelector(s);
  function basePrefix(){
    const p = location.pathname;
    const idx = p.indexOf('/admin');
    return idx >= 0 ? p.slice(0, idx) : '';
  }
  const api = (basePrefix() + '/api');

  // wait DOM (modal is after scripts in HTML)
  await new Promise(res=>{
    if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', ()=>res(), {once:true});
    else res();
  });

  
  function showModal(el){
    if(!el) return;
    el.hidden = false;
    el.classList.add('is-open');
    document.body.classList.add('modal-open');
  }
  function hideModal(el){
    if(!el) return;
    el.hidden = true;
    el.classList.remove('is-open');
    document.body.classList.remove('modal-open');
  }
function toast(msg){
    let t=document.getElementById('toast');
    if(!t){
      t=document.createElement('div');
      t.id='toast';
      t.className='toast';
      document.body.appendChild(t);
    }
    t.textContent=msg;
    t.classList.add('show');
    clearTimeout(t._tm);
    t._tm=setTimeout(()=>t.classList.remove('show'),1400);
  }

  // Safe HTML escaping for table rendering
  function escapeHtml(v){
    return String(v ?? '')
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/\"/g,'&quot;')
      .replace(/'/g,'&#39;');
  }

  async function copyText(text){
    if(navigator.clipboard && window.isSecureContext){
      await navigator.clipboard.writeText(text);
      return;
    }
    const ta=document.createElement('textarea');
    ta.value=text;
    ta.setAttribute('readonly','');
    ta.style.position='fixed';
    ta.style.left='-9999px';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  }

  async function jget(p){
    const r=await fetch(api+p, { credentials: 'same-origin' });
    const t=await r.text();
    if(!r.ok) throw {status:r.status, body:t};
    return t ? JSON.parse(t) : {};
  }
  async function jpost(p,b){
    const r=await fetch(api+p,{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify(b||{}),
      credentials: 'same-origin'
    });
    const t=await r.text();
    if(!r.ok) throw {status:r.status, body:t};
    return t ? JSON.parse(t) : {};
  }
  async function formPost(p, formData){
    const r=await fetch(api+p,{
      method:'POST',
      body:formData,
      credentials:'same-origin'
    });
    const t=await r.text();
    if(!r.ok) throw {status:r.status, body:t};
    return t ? JSON.parse(t) : {};
  }

  function parseErr(e){ try{ return JSON.parse(e.body); }catch(_){ return null; } }

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    return Uint8Array.from([...rawData].map((c) => c.charCodeAt(0)));
  }

  async function getCurrentBrowserPushSubscription(){
    if (!("serviceWorker" in navigator)) throw new Error('Service Worker не поддерживается');
    if (!("PushManager" in window)) throw new Error('Push API не поддерживается');
    let perm = Notification.permission;
    if (perm === 'default') perm = await Notification.requestPermission();
    if (perm !== 'granted') throw new Error('Нет разрешения на уведомления');
    const reg = await navigator.serviceWorker.register(basePrefix() + '/service-worker.js');
    let sub = await reg.pushManager.getSubscription();
    if (!sub) {
      const cfg = await jget('/push/public-config');
      if (!cfg || !cfg.publicKey) throw new Error('VAPID public key не настроен');
      sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(cfg.publicKey)
      });
    }
    const raw = sub.toJSON ? sub.toJSON() : sub;
    raw.appData = Object.assign({}, raw.appData || {}, {
      basePrefix: basePrefix(),
      ticketPath: basePrefix() + '/admin'
    });
    return raw;
  }

  const loginCard = $('#loginCard');
  const setupCard = $('#setupCard');
  const panelCard = $('#panelCard');

  function hideAll(){ loginCard.hidden=true; setupCard.hidden=true; panelCard.hidden=true; }
  function showLogin(){ hideAll(); loginCard.hidden=false; }
  function showSetup(){ hideAll(); setupCard.hidden=false; }
  function showPanel(){ hideAll(); panelCard.hidden=false; }

  let BRANDING = {};
  let ME = null;

  function hasPerm(key){
    if(!ME) return false;
    if((ME.role||'')==='owner') return true;
    return !!(ME.perms && ME.perms[key]);
  }

  function setupTabs(){
    const tabLinks = $('#tabLinks');
    const tabSecurity = $('#tabSecurity');
    const tabBranding = $('#tabBranding');
    const tabSpeech = $('#tabSpeech');
    const tabUsers = $('#tabUsers');
    const tabLogs = $('#tabLogs');
    const tabBackups = $('#tabBackups');

    const paneLinks = $('#paneLinks');
    const paneSecurity = $('#paneSecurity');
    const paneBranding = $('#paneBranding');
    const paneSpeech = $('#paneSpeech');
    const paneUsers = $('#paneUsers');
    const paneLogs = $('#paneLogs');
    const paneBackups = $('#paneBackups');

    tabLinks.hidden = !hasPerm('links');
    tabBranding.hidden = !hasPerm('branding');
    // Security доступен всем кто может войти
    tabSecurity.hidden = false;
    tabSpeech.hidden = !hasPerm('branding');
    tabUsers.hidden = !hasPerm('users');
    tabLogs.hidden = !(hasPerm('logs') || hasPerm('users'));
    // Backups are part of mini-admin tools; currently gated by 'users' permission
    tabBackups.hidden = !hasPerm('users');

    const tabs = [
      {k:'links', t:tabLinks, p:paneLinks},
      {k:'security', t:tabSecurity, p:paneSecurity},
      {k:'branding', t:tabBranding, p:paneBranding},
      {k:'speech', t:tabSpeech, p:paneSpeech},
      {k:'users', t:tabUsers, p:paneUsers},
      {k:'logs', t:tabLogs, p:paneLogs},
      {k:'backups', t:tabBackups, p:paneBackups},
    ].filter(x=>x.t && !x.t.hidden);

    function activate(which){
      tabs.forEach(x=>{
        const on = x.k===which;
        x.t.classList.toggle('is-active', on);
        x.t.setAttribute('aria-selected', on?'true':'false');
        x.p.hidden = !on;
      });
    }

    tabLinks?.addEventListener('click', ()=>activate('links'));
    tabSecurity?.addEventListener('click', ()=>activate('security'));
    tabBranding?.addEventListener('click', ()=>activate('branding'));
    tabSpeech?.addEventListener('click', ()=>{ activate('speech'); try{ loadSpeechSettings(); }catch(e){} });
    tabUsers?.addEventListener('click', ()=>activate('users'));
    tabLogs?.addEventListener('click', ()=>{ activate('logs'); try{ loadLogs(); }catch(e){} });
    tabBackups?.addEventListener('click', ()=>{ activate('backups'); try{ loadBackups(); }catch(e){} });

    // default first visible
    activate(tabs[0]?.k || 'security');
  }

  function setHint(id, text){
    const el=document.getElementById(id);
    if(el) el.textContent = text || '';
  }
  function setVal(id,v){
    const el=document.getElementById(id);
    if(el && el.tagName!=='INPUT' || (el.type!=='file')) el.value = (v??"");
  }
  function getVal(id){
    const el=document.getElementById(id);
    return el ? (el.value||'').trim() : '';
  }

  
  const BRANDING_PAGES = [
    { key: "global", title: "Глобально (по умолчанию)" },
    { key: "queue", title: "Страница очереди" },
    { key: "status", title: "Табло статуса" },
    { key: "poster", title: "Плакат (QR)" },
    { key: "manage", title: "Управление очередью" },
    { key: "create", title: "Создание очереди" },
    { key: "success", title: "Создано (success)" },
    { key: "miniadmin", title: "Админ панель" },
  ];

  function renderBrandingList(){
    const box = document.querySelector("#brandingList");
    if(!box) return;
    box.innerHTML = "";
    BRANDING_PAGES.forEach(p=>{
      const b = (BRANDING && BRANDING[p.key]) ? BRANDING[p.key] : {};
      const el = document.createElement("div");
      el.className = "listItem";
      const primary = b.primary || "—";
      const accent = b.accent || "—";
      const hasLogo = b.logoUrl ? "да" : "нет";
      el.innerHTML = `
        <div class="listItem__left">
          <div><strong>${p.title}</strong></div>
          <div class="badge">Primary: ${primary} • Accent: ${accent} • Logo: ${hasLogo}</div>
        </div>
        <div class="listItem__right">
          <button class="btn btn--secondary" data-edit-branding="${p.key}" type="button">Редактировать</button>
        </div>
      `;
      box.appendChild(el);
    });

    box.querySelectorAll("[data-edit-branding]").forEach(btn=>{
      btn.addEventListener("click", ()=> openBrandingModal(btn.getAttribute("data-edit-branding")));
    });
  }

  const brandingModal = document.querySelector("#brandingModal");
  const brandingModalTitle = document.querySelector("#brandingModalTitle");
  const brandPrimary = document.querySelector("#brandPrimary");
  const brandAccent = document.querySelector("#brandAccent");
  const brandThemeRow = document.querySelector("#brandThemeRow");
  const brandThemeMode = document.querySelector("#brandThemeMode");
  const brandLogoFile = document.querySelector("#brandLogoFile");
  const brandSoundRow = document.querySelector("#brandSoundRow");
  const brandSoundFile = document.querySelector("#brandSoundFile");
  const brandFaviconFile = document.querySelector("#brandFaviconFile");
  const brandingClose = document.querySelector("#brandingClose");
  const brandingCancel = document.querySelector("#brandingCancel");
  const brandingSave = document.querySelector("#brandingSave");

  let currentBrandingKey = null;

  function openBrandingModal(key){
    currentBrandingKey = key;
    const pageMeta = BRANDING_PAGES.find(p=>p.key===key);
    if(brandingModalTitle) brandingModalTitle.textContent = pageMeta ? pageMeta.title : "Брендинг";
    const b = (BRANDING && BRANDING[key]) ? BRANDING[key] : {};
    if(brandPrimary) brandPrimary.value = (b.primary || "#4e7cff");
    if(brandAccent) brandAccent.value = (b.accent || "#a6c4ff");
    // Theme only for status
    if(brandThemeRow) brandThemeRow.classList.toggle("hidden", key !== "status");
    if(brandThemeMode) brandThemeMode.value = (b.themeMode || "auto");
    // Sound only for global
    if(brandSoundRow) brandSoundRow.classList.toggle("hidden", key !== "global");
    if(brandLogoFile) brandLogoFile.value = "";
    if(brandSoundFile) brandSoundFile.value = "";
    if(brandFaviconFile) brandFaviconFile.value = "";
    showModal(brandingModal);
  }

  function closeBrandingModal(){ hideModal(brandingModal); currentBrandingKey=null; }

  brandingClose?.addEventListener("click", closeBrandingModal);
  brandingCancel?.addEventListener("click", closeBrandingModal);
  brandingModal?.addEventListener("click", (e)=>{
    const t=e.target;
    if(t && t.getAttribute && t.getAttribute("data-close")==="1") closeBrandingModal();
  });

  brandingSave?.addEventListener("click", async ()=>{
    if(!currentBrandingKey) return;
    const key = currentBrandingKey;
    let logoUrl = BRANDING?.[key]?.logoUrl || null;
    let notifySoundUrl = BRANDING?.[key]?.notifySoundUrl || null;
    let faviconUrl = BRANDING?.[key]?.faviconUrl || null;

    // upload logo if chosen
    if(brandLogoFile && brandLogoFile.files && brandLogoFile.files[0]){
      const fd=new FormData();
      fd.append("pageKey", key);
      fd.append("kind", "logo");
      fd.append("logo", brandLogoFile.files[0]);
      const r=await fetch(api+"/site/branding/upload",{method:"POST",body:fd,credentials:"same-origin"});
      const j=await r.json();
      if(r.ok){ logoUrl=j.logoUrl || logoUrl; } else { toast("Не удалось загрузить логотип"); return; }
    }

    if(brandFaviconFile && brandFaviconFile.files && brandFaviconFile.files[0]){
      const fd=new FormData();
      fd.append("pageKey", key);
      fd.append("kind", "favicon");
      fd.append("favicon", brandFaviconFile.files[0]);
      const r=await fetch(api+"/site/branding/upload",{method:"POST",body:fd,credentials:"same-origin"});
      const j=await r.json().catch(()=>({}));
      if(r.ok){ faviconUrl=j.faviconUrl || faviconUrl; } else { toast("Не удалось загрузить favicon"); return; }
    }

    // upload sound (global)
    if(key==="global" && brandSoundFile && brandSoundFile.files && brandSoundFile.files[0]){
      const fd=new FormData();
      fd.append("pageKey", key);
      fd.append("kind", "sound");
      fd.append("sound", brandSoundFile.files[0]);
      const r=await fetch(api+"/site/branding/upload",{method:"POST",body:fd,credentials:"same-origin"});
      const j=await r.json();
      if(r.ok){ notifySoundUrl=j.url; } else { toast("Не удалось загрузить звук"); return; }
    }

    const payload = { items: {} };
    payload.items[key] = {
      primary: brandPrimary?.value || null,
      accent: brandAccent?.value || null,
      themeMode: (key==="status" ? (brandThemeMode?.value || "auto") : null),
      logoUrl: logoUrl,
      notifySoundUrl: notifySoundUrl,
      faviconUrl: faviconUrl
    };

    const rr=await fetch(api+"/site/branding",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(payload),credentials:"same-origin"});
    const jj=await rr.json().catch(()=>({}));
    if(!rr.ok){ toast("Не удалось сохранить"); return; }

    // reload branding
    try{ const b=await jget("/site/branding"); BRANDING=b.branding||{}; }catch(e){}
    renderBrandingList();
    toast("Сохранено");
    closeBrandingModal();
  });

function fillBranding(all){
    BRANDING = all || {};
    renderBrandingList();
  }

  function renderTokens(items){
    const box = $('#list');
    box.innerHTML='';
    (items||[]).forEach(it=>{
      const el=document.createElement('div');
      el.className='item';
      const url = location.origin + '/create/' + it.token;
      const label = it.label ? it.label.replace(/</g,'&lt;') : '<span class="muted">Без комментария</span>';
      el.innerHTML = `
        <div class="meta">
          <div class="label">${label}</div>
          <div class="url" data-url>${url}</div>
        </div>
        <div class="right">
          <span class="badge ${(Number(it.active)===1)?'' :'off'}">${(Number(it.active)===1)?'active':'revoked'}</span>
          <button class="btn" data-copy>Копировать</button>
          ${(Number(it.active)===1)?'<button class="btn" data-revoke>Отозвать</button>':''}
        </div>`;

      el.querySelector('[data-copy]').addEventListener('click', async ()=>{
        try{ await copyText(url); toast('Скопировано'); }catch(e){ console.warn(e); toast('Не удалось'); }
      });

      const revoke = el.querySelector('[data-revoke]');
      if(revoke){
        revoke.addEventListener('click', async ()=>{
          await jpost('/create-tokens/'+it.token+'/revoke',{});
          refresh();
        });
      }
      box.appendChild(el);
    });
  }

  function dt(s){
    if(!s) return '';
    try{
      const d = new Date(String(s).replace(' ','T'));
      return d.toLocaleString();
    }catch{ return String(s); }
  }

  function renderSessions(items){
    const box = $('#sessionsList');
    if(!box) return;
    box.innerHTML='';
    if(!items || items.length===0){
      box.innerHTML = '<div class="muted small">Нет активных сессий.</div>';
      return;
    }
    items.forEach(s=>{
      const el=document.createElement('div');
      el.className='sess';
      const ua = (s.userAgent||'').replace(/</g,'&lt;');
      const ip = (s.ip||'');
      const kind = (s.kind==='short') ? 'Короткая' : 'Долгая';
      el.innerHTML = `
        <div class="sess__meta">
          <div class="sess__title">${s.isCurrent?'Текущая сессия':'Сессия'} • ${kind}</div>
          <div class="sess__sub">${ip} • Вход: ${dt(s.createdAt)} • Завершится: ${dt(s.expiresAt)}</div>
          <div class="sess__ua">${ua}</div>
        </div>
        <div class="sess__actions">
          <button class="btn" data-kill ${s.isCurrent?'disabled':''}>Выйти</button>
        </div>
      `;
      el.querySelector('[data-kill]').addEventListener('click', async ()=>{
        await jpost('/auth/sessions/'+encodeURIComponent(s.id)+'/revoke',{});
        toast('Готово');
        refresh();
      });
      box.appendChild(el);
    });
  }

  function renderAuthHistory(items){
    const box = document.getElementById('authHistory');
    if(!box) return;
    box.innerHTML='';
    if(!items || items.length===0){
      box.innerHTML = '<div class="muted small">Нет записей.</div>';
      return;
    }
    items.forEach(a=>{
      const row=document.createElement('div');
      row.className='item';
      row.innerHTML = `
        <div class="meta">
          <div class="label">${a.success==1?'Успешный вход':'Ошибка входа'} • ${dt(a.created_at||a.createdAt)}</div>
          <div class="url">${(a.ip||'')}</div>
        </div>
        <div class="right">
          <span class="badge ${(a.success==1)?'':'off'}">${a.success==1?'OK':'FAIL'}</span>
        </div>
      `;
      box.appendChild(row);
    });
  }

  function renderUsers(items){
    const box = $('#usersList');
    const err = $('#usersErr');
    if(!box) return;
    box.innerHTML='';
    if(err) err.hidden = true;

    (items||[]).forEach(u=>{
      const perms = (()=>{ try{return u.permissions_json?JSON.parse(u.permissions_json):{};}catch{return {}; } })();
      const isOwner = (u.role==='owner');

      const row=document.createElement('div');
      row.className='user';
      row.innerHTML = `
        <div class="left">
          <div class="uname">${(u.username||'').replace(/</g,'&lt;')}</div>
          <div class="uinfo">${u.comment ? ('Комментарий: '+String(u.comment).replace(/</g,'&lt;')) : 'Комментарий: —'}</div>
          <div class="uinfo">Создан: ${dt(u.created_at||u.createdAt)}</div>
        </div>
        <div class="right">
          ${isOwner ? '<span class="badge">OWNER</span>' : ''}
          <div class="btnrow">
            ${isOwner ? '' : '<button class="btn" data-perm>Доступы</button>'}
            ${isOwner ? '' : '<button class="btn" data-del>Удалить</button>'}
          </div>
        </div>
      `;

      row.querySelector('[data-perm]')?.addEventListener('click', ()=>{
        openPermModal(u, perms);
      });

      row.querySelector('[data-del]')?.addEventListener('click', async ()=>{
        try{
          await jpost('/auth/users/'+u.id+'/delete', {});
          toast('Удалено');
          refresh();
        }catch(e){
          const body=parseErr(e);
          if(err){
            err.hidden=false;
            if(body?.error==='CANNOT_DELETE_SELF') err.textContent = 'Нельзя удалить текущего пользователя.';
            else if(body?.error==='CANNOT_DELETE_OWNER') err.textContent = 'Нельзя удалить owner.';
            else err.textContent = 'Не удалось удалить.';
          }
        }
      });

      box.appendChild(row);
    });
  }

  // --- Permissions modal (owner) ---
  let permEditingUserId = null;
  function openPermModal(user, perms){
    permEditingUserId = Number(user.id)||null;
    const modal = $('#permModal');
    if(!modal) return;
    const lbl = $('#permUserLabel');
    if(lbl) lbl.textContent = 'Пользователь: ' + (user.username||'');
    $('#permEdit_links').checked = !!perms.links;
    $('#permEdit_branding').checked = !!perms.branding;
    $('#permEdit_users').checked = !!perms.users;
    modal.hidden = false;
  }
  function closePermModal(){
    const modal = $('#permModal');
    if(modal) modal.hidden = true;
    permEditingUserId = null;
  }
  $('#permClose')?.addEventListener('click', closePermModal);
  $('#permModal')?.addEventListener('click', (e)=>{ if(e.target && e.target.id==='permModal') closePermModal(); });
  $('#permSave')?.addEventListener('click', async ()=>{
    if(!permEditingUserId) return;
    const payload = {
      permissions: {
        links: $('#permEdit_links').checked ? 1 : 0,
        branding: $('#permEdit_branding').checked ? 1 : 0,
        users: $('#permEdit_users').checked ? 1 : 0,
      }
    };
    try{
      await jpost('/auth/users/'+permEditingUserId+'/permissions', payload);
      toast('Сохранено');
      closePermModal();
      refresh();
    }catch(e){
      toast('Не удалось сохранить');
    }
  });

async function refresh(){
    const ns = await jget('/auth/needs-setup');
    if(ns.needsSetup){ showSetup(); return; }

    ME = await jget('/auth/me');
    if(!ME.isAdmin){ showLogin(); return; }

    showPanel();
    const who = document.querySelector('#whoamiName'); if(who) who.textContent = ME.username || '—';
    setupTabs();

    // Links
    if(hasPerm('links')){
      const list = await jget('/create-tokens');
      renderTokens(list.items||[]);
    }

    // Branding
    if(hasPerm('branding')){
      try{ const b=await jget("/site/branding"); fillBranding(b.branding||{}); }catch(e){}
    }

    // Sessions + history
    try{ const sess=await jget('/auth/sessions'); renderSessions(sess.items||[]);}catch(e){}
    try{ const hist=await jget('/auth/history'); renderAuthHistory(hist.items||[]);}catch(e){}

    // Users
    if((ME.role||'')==='owner' && hasPerm('users')){
      try{ const users=await jget('/auth/users'); renderUsers(users.items||[]);}catch(e){}
    }
  }

  // Login
  $('#loginBtn')?.addEventListener('click', async ()=>{
    $('#loginErr').hidden=true;
    const u = ($('#u').value||'').trim();
    const p = ($('#p').value||'');
    const rememberMe = !!($('#rememberMe')?.checked);
    try{
      await jpost('/auth/login', { username:u, password:p, rememberMe });
      refresh();
    }catch(e){
      const body=parseErr(e);
      if(body?.error==='RATE_LIMITED'){
        $('#loginErr').hidden=false;
        $('#loginErr').textContent = `Слишком много попыток. Повторите через ${body.retryAfter||0} сек.`;
      }else{
        $('#loginErr').hidden=false;
        $('#loginErr').textContent = 'Неверный логин или пароль';
      }
    }
  });

  // Setup
  $('#setupBtn')?.addEventListener('click', async ()=>{
    $('#setupErr').hidden=true;
    const u = ($('#su').value||'').trim();
    const p = ($('#sp').value||'');
    try{
      await jpost('/auth/setup', { username:u, password:p });
      refresh();
    }catch(e){
      const body=parseErr(e);
      $('#setupErr').hidden=false;
      $('#setupErr').textContent = body?.error || 'Ошибка';
    }
  });

  // Logout
  $('#logoutBtn')?.addEventListener('click', async ()=>{
    await jpost('/auth/logout', {});
    location.reload();
  });

  // New token
  $('#newBtn')?.addEventListener('click', async ()=>{
    try{
      await jpost('/create-tokens', { label: ($('#label').value||'').trim() });
      $('#label').value='';
      refresh();
    }catch(e){ toast('Ошибка'); }
  });

  // Change password
  $('#changePwBtn')?.addEventListener('click', async ()=>{
    $('#pwErr').hidden=true; $('#pwOk').hidden=true;
    const cur = ($('#curPw').value||'');
    const nw = ($('#newPw').value||'');
    const nw2 = ($('#newPw2').value||'');
    if(nw!==nw2){ $('#pwErr').hidden=false; $('#pwErr').textContent='Пароли не совпадают'; return; }
    try{
      await jpost('/auth/change-password', { currentPassword:cur, newPassword:nw });
      $('#pwOk').hidden=false;
      $('#curPw').value=''; $('#newPw').value=''; $('#newPw2').value='';
      // after password change long sessions revoked; current remains
      refresh();
    }catch(e){
      const body=parseErr(e);
      $('#pwErr').hidden=false;
      $('#pwErr').textContent = body?.error==='RATE_LIMITED' ? `Слишком много попыток. Повторите через ${body.retryAfter||0} сек.` : 'Ошибка';
    }
  });
  $('#changePwCancelBtn')?.addEventListener('click', ()=>{
    $('#curPw').value=''; $('#newPw').value=''; $('#newPw2').value='';
    $('#pwErr').hidden=true; $('#pwOk').hidden=true;
  });

  // Logout all sessions (including current)
  $('#logoutAllSessionsBtn')?.addEventListener('click', async ()=>{
    await jpost('/auth/sessions/revoke-all', {});
    location.href='/admin/';
  });

  // Branding save: upload selected logos first, then save colors + theme
  $('#saveBrandingBtn')?.addEventListener('click', async ()=>{
    const ok = $('#brandingOk'); const err = $('#brandingErr');
    if(ok) ok.hidden=true;
    if(err) err.hidden=true;

    const pages=["queue","status","poster","manage","create","success","miniadmin"];
    const items = {};

    // global theme mode + sound
    items['global'] = { themeMode: ($('#themeMode')?.value || 'auto'), notifySoundUrl: (BRANDING.global && BRANDING.global.notifySoundUrl) ? BRANDING.global.notifySoundUrl : null };

    try{
      // upload global notification sound (optional)
      const snd = document.getElementById('b_sound_global');
      if(snd && snd.files && snd.files[0]){
        const fd = new FormData();
        fd.append('pageKey','global');
        fd.append('kind','sound');
        fd.append('sound', snd.files[0]);
        const up = await formPost('/site/branding/upload', fd);
        items['global'].notifySoundUrl = up.notifySoundUrl || items['global'].notifySoundUrl;
        snd.value='';
      }

      for(const p of pages){
        const fileInput = document.getElementById(`b_logo_${p}`);
        let logoUrl = (BRANDING[p] && BRANDING[p].logoUrl) ? BRANDING[p].logoUrl : null;

        if(fileInput && fileInput.files && fileInput.files[0]){
          const fd = new FormData();
          fd.append('pageKey', p);
          fd.append('logo', fileInput.files[0]);
          const up = await formPost('/site/branding/upload', fd);
          logoUrl = up.logoUrl || logoUrl;
          // clear selection
          fileInput.value = '';
        }

        items[p] = {
          logoUrl,
          primary: getVal(`b_primary_${p}`) || null,
          accent: getVal(`b_accent_${p}`) || null,
        };
      }

      await jpost('/site/branding', { items });
      if(ok){ ok.hidden=false; ok.textContent='Сохранено'; }
      // refresh
      await refresh();
      toast('Сохранено');
    }catch(e){
      console.warn(e);
      const body=parseErr(e);
      if(err){ err.hidden=false; err.textContent = body?.error || 'Ошибка сохранения'; }
    }
  });

  // Create user
  $('#createUserBtn')?.addEventListener('click', async ()=>{
    const err = $('#usersErr');
    if(err) err.hidden=true;

    const username = ($('#newUserName').value||'').trim();
    const password = ($('#newUserPass').value||'');
    const comment = ($('#newUserComment').value||'').trim();
    const permissions = {
      links: !!$('#perm_links')?.checked,
      branding: !!$('#perm_branding')?.checked,
      users: !!$('#perm_users')?.checked,
    };

    try{
      await jpost('/auth/users', { username, password, comment, permissions });
      $('#newUserName').value='';
      $('#newUserPass').value='';
      $('#newUserComment').value='';
      $('#perm_links').checked=false;
      $('#perm_branding').checked=false;
      $('#perm_users').checked=false;
      toast('Добавлено');
      refresh();
    }catch(e){
      const body=parseErr(e);
      if(err){ err.hidden=false; err.textContent = body?.error==='VALIDATION' ? 'Проверьте логин/пароль' : 'Не удалось создать пользователя'; }
    }
  });

  // init: inject auth history container if missing (compat)
  const paneSecurity = $('#paneSecurity');
  if(paneSecurity && !document.getElementById('authHistory')){
    const h = document.createElement('div');
    h.innerHTML = `<h2 class="h2">История авторизаций</h2><p class="muted">Эта история не очищается.</p><div id="authHistory" class="list"></div>`;
    paneSecurity.appendChild(h);
  }

  // Boot
  try{ await refresh(); }catch(e){ console.warn(e); showLogin(); }
const logsState = { page: 1, perPage: 50, total: 0, pages: 1, sources: [] };

function fillLogSources(list, selected){
  const sel = document.querySelector('#lgSource');
  if(!sel) return;
  const current = selected || sel.value || '';
  sel.innerHTML = '<option value="">Все</option>' + (Array.isArray(list) ? list.map(x=>`<option value="${escapeHtml(x.source||'')}">${escapeHtml(x.source||'')} (${Number(x.count||0)})</option>`).join('') : '');
  sel.value = current;
}

  function renderLogsPager(){
    const box = document.querySelector('#logsPager');
    if(!box) return;
    const page = Number(logsState.page || 1);
    const pages = Number(logsState.pages || 1);
    const total = Number(logsState.total || 0);
    box.innerHTML = '';
    const info = document.createElement('div');
    info.className = 'pagerInfo';
    info.textContent = `Всего логов: ${total}. Страница ${page} из ${pages}`;
    const prev = document.createElement('button');
    prev.type='button'; prev.className='btn btn--secondary'; prev.textContent='← Назад'; prev.disabled = page <= 1;
    prev.addEventListener('click', ()=>{ if(page>1){ logsState.page = page-1; loadLogs(); } });
    const next = document.createElement('button');
    next.type='button'; next.className='btn btn--secondary'; next.textContent='Вперёд →'; next.disabled = page >= pages;
    next.addEventListener('click', ()=>{ if(page<pages){ logsState.page = page+1; loadLogs(); } });
    box.append(prev, next, info);
  }

  async function loadLogs(){
    if(!(hasPerm('logs') || hasPerm('users'))) return;
    try{
      try{
        const s = await jget('/admin/logs/settings');
        const set = s.settings||{};
        const a = document.querySelector('#lgAuto');
        const d = document.querySelector('#lgKeepDays');
        if(a) a.checked = Number(set.auto_delete)===1;
        if(d) d.value = Number(set.keep_days||30);
      }catch(_e){}

      const df = document.querySelector('#lgDateFrom')?.value || '';
      const dt = document.querySelector('#lgDateTo')?.value || '';
      const lvl = document.querySelector('#lgLevel')?.value || '';
      const src = document.querySelector('#lgSource')?.value || '';
      const apiStatus = document.querySelector('#lgApiStatus')?.value || '';
      const per = Math.max(10, Math.min(200, Number(document.querySelector('#lgPerPage')?.value || logsState.perPage || 50)));
      logsState.perPage = per;
      const params = new URLSearchParams();
      params.set('page', String(logsState.page || 1));
      params.set('perPage', String(per));
      if(df) params.set('dateFrom', df);
      if(dt) params.set('dateTo', dt);
      if(lvl) params.set('level', lvl);
      if(src) params.set('source', src);
      if(apiStatus) params.set('apiStatus', apiStatus);

      const r = await jget('/admin/logs?' + params.toString());
      const t = document.querySelector('#logsTable');
      if(!t) return;
      const rows = Array.isArray(r.items) ? r.items : [];
      logsState.page = Number(r.page || 1);
      logsState.perPage = Number(r.perPage || per);
      logsState.total = Number(r.total || rows.length);
      logsState.pages = Number(r.pages || 1);
      logsState.sources = Array.isArray(r.sources) ? r.sources : [];
      fillLogSources(logsState.sources, r.filters?.source || src);
      const apiSel = document.querySelector('#lgApiStatus'); if(apiSel && r.filters?.apiStatus !== undefined) apiSel.value = r.filters.apiStatus || '';
      t.innerHTML = '<tr><th>Время</th><th>Уровень</th><th>Источник</th><th>Сообщение</th><th>Детали</th></tr>' +
        rows.map(x=>{
          const ctx = x.context || {};
          const meta = [];
          if(ctx.method) meta.push(`Метод: ${ctx.method}`);
          if(ctx.path) meta.push(`Путь: ${ctx.path}`);
          if(ctx.uri) meta.push(`URI: ${ctx.uri}`);
          if(ctx.status !== undefined && ctx.status !== '') meta.push(`Статус: ${ctx.status}`);
          if(ctx.file) meta.push(`Файл: ${ctx.file}${ctx.line ? ':'+ctx.line : ''}`);
          if(ctx.ip) meta.push(`IP: ${ctx.ip}`);
          if(ctx.admin_user) meta.push(`Админ: ${ctx.admin_user}`);
          if(ctx.ms) meta.push(`Время: ${ctx.ms} ms`);
          if(ctx.body_trunc) meta.push(`Body: ${ctx.body_trunc}`);
          if(ctx.query) meta.push(`Query: ${ctx.query}`);
          return `<tr><td>${escapeHtml(x.created_at||'')}</td><td>${escapeHtml(x.level||'')}</td><td>${escapeHtml(x.source||'')}</td><td>${escapeHtml(x.message||'')}</td><td><div class="log-meta">${escapeHtml(meta.join(' • ') || '—')}</div></td></tr>`;
        }).join('');
      renderLogsPager();
    }catch(e){
      console.warn('loadLogs failed', e);
      toast('Не удалось загрузить логи');
    }
  }

  document.querySelector('#lgApply')?.addEventListener('click', async ()=>{
    logsState.page = 1;
    await loadLogs();
  });

  document.querySelector('#lgPerPage')?.addEventListener('change', async ()=>{
    logsState.page = 1;
    await loadLogs();
  });

  document.querySelector('#lgLevel')?.addEventListener('change', async ()=>{ logsState.page = 1; await loadLogs(); });
  document.querySelector('#lgSource')?.addEventListener('change', async ()=>{ logsState.page = 1; await loadLogs(); });
  document.querySelector('#lgApiStatus')?.addEventListener('change', async ()=>{ logsState.page = 1; await loadLogs(); });

  document.querySelector('#lgSave')?.addEventListener('click', async ()=>{
    const autoDel = document.querySelector('#lgAuto')?.checked ? 1:0;
    const keepDays = Math.max(1, Number(document.querySelector('#lgKeepDays')?.value||30));
    try{
      await jpost('/admin/logs/settings', { autoDelete: !!autoDel, keepDays });
      toast('Сохранено');
      loadLogs();
    }catch(e){ toast('Не удалось сохранить'); }
  });

  document.querySelector('#lgClear')?.addEventListener('click', async ()=>{
    if(!confirm('Очистить логи?')) return;
    try{
      await jpost('/admin/logs/clear', {});
      toast('Очищено');
      logsState.page = 1;
      loadLogs();
    }catch(e){ toast('Не удалось очистить'); }
  });

  async function loadBackups(){
    if(!hasPerm('users')) return;
    try{
      const s = await jget('/admin/backups/settings');
      const set = s.settings||{};
      const en = document.querySelector('#bkEnabled');
      const fr = document.querySelector('#bkFreq');
      if(en) en.checked = Number(set.enabled)===1;
      if(fr) fr.value = Number(set.frequency_minutes||1440);
      const ad = document.querySelector('#bkAutoDelete');
      const kd = document.querySelector('#bkKeepDays');
      if(ad) ad.checked = Number(set.auto_delete||0)===1;
      if(kd) kd.value = Number(set.keep_days||30);
    }catch(e){}
    try{
      const r = await jget('/admin/backups');
      const t = document.querySelector('#bkTable');
      if(!t) return;
      const rows = r.items || [];
      const tgMark = (v)=>{
        const n = Number(v||0);
        if(n===1) return '✓';
        if(n===2) return '✗';
        return '—';
      };
      t.innerHTML = '<tr><th>Дата</th><th>Файл</th><th>Размер</th><th>Telegram</th><th>Ошибка</th></tr>' +
        rows.map(x=>{
          const err = (x.telegram_error||'').toString();
          const errShort = err.length>80 ? (err.slice(0,80)+'…') : err;
          const errCell = err ? `<span title="${escapeHtml(err)}">${escapeHtml(errShort)}</span>` : '—';
          return `<tr><td>${x.created_at}</td><td><a href="${basePrefix()}/backups/${x.file_name}" target="_blank">${escapeHtml(x.file_name)}</a></td><td>${escapeHtml(String(x.size_bytes))}</td><td>${tgMark(x.sent_to_telegram)}</td><td>${errCell}</td></tr>`;
        }).join('');
    }catch(e){}
  }

  document.querySelector('#bkSave')?.addEventListener('click', async ()=>{
    const en = document.querySelector('#bkEnabled')?.checked ? 1:0;
    const fr = Number(document.querySelector('#bkFreq')?.value||1440);
    const ad = document.querySelector('#bkAutoDelete')?.checked ? 1:0;
    const kd = Number(document.querySelector('#bkKeepDays')?.value||30);
    try{
      await jpost('/admin/backups/settings', { enabled: !!en, frequencyMinutes: fr, autoDelete: !!ad, keepDays: kd });
      toast('Сохранено');
      loadBackups();
    }catch(e){ toast('Не удалось сохранить'); }
  });

  document.querySelector('#bkRun')?.addEventListener('click', async ()=>{
    try{
      await jpost('/admin/backups/run', {});
      toast('Бекап создан');
      loadBackups();
    }catch(e){ toast('Не удалось создать бекап'); }
  });


  document.querySelector('#bkPushTest')?.addEventListener('click', async ()=>{
    try{
      const subscription = await getCurrentBrowserPushSubscription();
      const r = await jpost('/admin/push/test', { subscription });
      const sent = Number(r?.sent || 0);
      if(sent > 0) toast('Push отправлен только в этот браузер');
      else toast(String(r?.message || 'Нет активной подписки в текущем браузере'));
    }catch(e){
      const pe = parseErr(e);
      const msg = (pe && (pe.message||pe.error)) ? String(pe.message||pe.error) : (e && e.message ? String(e.message) : 'Ошибка Push');
      toast(msg);
    }
  });

  // Telegram test button
  document.querySelector('#bkTgTest')?.addEventListener('click', async ()=>{
    try{
      const r = await jpost('/admin/backups/telegram-test', {});
      if(r && r.ok) toast('Telegram: OK');
      else toast('Telegram: ошибка');
    }catch(e){
      const pe = parseErr(e);
      const msg = (pe && (pe.message||pe.error)) ? String(pe.message||pe.error) : 'Ошибка Telegram';
      toast(msg);
    }
  });


async function loadSpeechSettings(){
  if(!hasPerm('branding')) return;
  try{
    const r = await jget('/admin/speech/settings');
    const s = r.settings || {};
    const set = (id, v)=>{ const el=document.querySelector(id); if(el) el.value = v ?? ''; };
    const setc = (id, v)=>{ const el=document.querySelector(id); if(el) el.checked = Number(v||0)===1; };
    setc('#spEnabled', s.enabled);
    set('#spApiKey', s.api_key || '');
    set('#spFolderId', s.folder_id || '');
    set('#spVoice', s.voice || 'filipp');
    set('#spEmotion', s.emotion || '');
    set('#spSpeed', s.speed || '1');
    set('#spTemplate', s.template_text || 'Номер {number}. Пройдите к кассе {cashier}');
  }catch(e){ toast('Не удалось загрузить Yandex Speech'); }
}

document.querySelector('#spSave')?.addEventListener('click', async ()=>{
  try{
    await jpost('/admin/speech/settings', {
      enabled: !!document.querySelector('#spEnabled')?.checked,
      apiKey: document.querySelector('#spApiKey')?.value || '',
      folderId: document.querySelector('#spFolderId')?.value || '',
      voice: document.querySelector('#spVoice')?.value || 'filipp',
      emotion: document.querySelector('#spEmotion')?.value || '',
      speed: Number(document.querySelector('#spSpeed')?.value || 1),
      templateText: document.querySelector('#spTemplate')?.value || ''
    });
    toast('Сохранено');
  }catch(e){
    const pe = parseErr(e);
    toast((pe && (pe.message||pe.error)) ? String(pe.message||pe.error) : 'Не удалось сохранить Yandex Speech');
  }
});

document.querySelector('#spTest')?.addEventListener('click', async ()=>{
  try{
    const r = await fetch(api + '/admin/speech/test', { method:'POST', credentials:'same-origin' });
    if(!r.ok) throw new Error(await r.text());
    const blob = await r.blob();
    const url = URL.createObjectURL(blob);
    const audio = new Audio(url);
    await audio.play().catch(()=>{});
    audio.onended = ()=>URL.revokeObjectURL(url);
  }catch(e){ toast('Не удалось выполнить тест озвучки'); }
});

})();
