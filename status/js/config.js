// Все, что нужно быстро менять — здесь
window.APP_CONFIG = {
    logoPath: "/img/logo.svg",
  
    colors: {
      bg: "#ffffff",
      text: "#000000",
      accent: "#7B5CFA",
      divider: "#7B5CFA",
    },
  
    // Демоданные (как на скринах) — можно удалить и подставлять реальные данные из API
    demoStates: [
      // 1) никого нет
      {
        prepare: [],
        cashiers: { 1: "000", 2: "000" },
        soundOn: false,
      },
      // 2) два номера готовятся
      {
        prepare: ["001", "002"],
        cashiers: { 1: "000", 2: "000" },
        soundOn: false,
      },
      // 3) один номер готовится, касса 1 вызывает 001
      {
        prepare: ["002"],
        cashiers: { 1: "001", 2: "000" },
        soundOn: false,
      },
      // 4) один номер готовится, звук включен
      {
        prepare: ["002"],
        cashiers: { 1: "000", 2: "000" },
        soundOn: true,
      },
    ],
  };