// Меняйте тут логотип и основные тексты/данные.
// Цвета лучше менять в styles.css (CSS variables), но можете и тут — main.js подставит в :root.

window.APP_CONFIG = {
    logoUrl: "/img/logo.svg",
  
    texts: {
      queueName: "jandonks-test-2",
      queueShort: "gd",
    },
  
    services: ["g", "gd"],
  
    demo: {
      ticketWaiting: "#004",
      aheadText: { line1: "перед вами", line2: "1 человек" },
      eta: "≈9 минут",
  
      ticketCalled: "#003",
      desk: "Касса 1",
    },
  
    // опционально: прокинуть цвета из js в css-переменные
    theme: {
      primary: "#7C5CFA",
      primarySoft: "#E9E2FF",
    },
  };