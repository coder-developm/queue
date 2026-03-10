self.addEventListener('install', event => { self.skipWaiting(); });
self.addEventListener('activate', event => { event.waitUntil(self.clients.claim()); });
self.addEventListener('push', event => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { try { data = { title: 'Уведомление', body: event.data.text() }; } catch (e2) {} }
  const title = data.title || 'Очередь';
  const options = { body: data.body || '', icon: data.icon || '/img/logo.svg', badge: data.badge || '/img/logo.svg', data: data.data || {} };
  event.waitUntil(self.registration.showNotification(title, options));
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || '/';
  event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
    for (const client of list) {
      if ('focus' in client) { client.navigate(target); return client.focus(); }
    }
    if (clients.openWindow) return clients.openWindow(target);
  }));
});
