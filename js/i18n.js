(function(){
  const dict = {
    ru: {
      prepare_one: "Приготовиться номеру",
      prepare_many: "Приготовиться номерам",
      invited_clients: "Приглашены клиенты",
      desk: "Касса",
      no_one_queue: "В очереди никого нет",
      total_in_queue: "Всего в очереди",
      people_1: "человек",
      people_2_4: "человека",
      people_5: "человек",
      speech_call: (num, desk) => `Номер ${num}. Пройдите к кассе ${desk}`,
      self_reg_disabled: "Самостоятельная регистрация отключена",
      queue_full: "Очередь заполнена",
      before_you_none: "перед вами никого нет",
      you_will_be_called: "— вас скоро вызовут",
      before_you: "перед вами",
      invited: "Вы приглашены",
    },
    en: {
      prepare_one: "Get ready",
      prepare_many: "Get ready",
      invited_clients: "Invited clients",
      desk: "Desk",
      no_one_queue: "No one in the queue",
      total_in_queue: "Total in queue",
      people: "people",
      speech_call: (num, desk) => `Number ${num}. Please go to desk ${desk}`,
      self_reg_disabled: "Self registration is disabled",
      queue_full: "Queue is full",
      before_you_none: "no one ahead of you",
      you_will_be_called: "— you will be called soon",
      before_you: "ahead of you",
      invited: "You are invited",
    }
  };

  function normLang(v){
    v = (v || '').toLowerCase();
    if (v.startsWith('ru')) return 'ru';
    if (v.startsWith('en')) return 'en';
    return 'ru';
  }

  const qs = new URLSearchParams(location.search);
  let lang = qs.get('lang') || localStorage.getItem('lang') || navigator.language || 'ru';
  lang = normLang(lang);
  localStorage.setItem('lang', lang);

  function setLang(next){
    if (next === 'auto') {
      next = normLang(navigator.language || 'ru');
    }
    lang = normLang(next);
    localStorage.setItem('lang', lang);
    document.documentElement.setAttribute('lang', lang);
  }

  function t(key, ...args){
    const pack = dict[lang] || dict.ru;
    const v = pack[key] ?? dict.ru[key] ?? key;
    return (typeof v === 'function') ? v(...args) : v;
  }

  function humanWord(n){
    if (lang === 'en') return dict.en.people;
    const mod10 = n % 10;
    const mod100 = n % 100;
    if (mod100 >= 11 && mod100 <= 14) return dict.ru.people_5;
    if (mod10 === 1) return dict.ru.people_1;
    if (mod10 >= 2 && mod10 <= 4) return dict.ru.people_2_4;
    return dict.ru.people_5;
  }

  window.I18N = { get lang(){ return lang; }, setLang, t, humanWord };
  document.documentElement.setAttribute('lang', lang);
})();
