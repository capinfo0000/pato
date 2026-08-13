/**
 * pato岡山（仮）Service Worker
 *
 * 方針:
 * - アプリシェル（オフライン用ページと静的アセット）だけを事前キャッシュする
 * - ページ遷移は network-first（残高やステータスが古いと困るため）
 * - オフライン時のみキャッシュ済みのオフラインページを返す
 * - POST など GET 以外は一切キャッシュしない（課金・状態遷移を再送しない）
 */
const CACHE = 'pato-shell-v1';
const SHELL = ['/offline', '/icon.svg', '/manifest.json'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;

  // 金銭・状態が絡むリクエストはキャッシュしない
  if (request.method !== 'GET') return;

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(() => caches.match('/offline')));
    return;
  }

  event.respondWith(
    caches.match(request).then((hit) => hit || fetch(request).then((res) => {
      // 静的アセットのみ後追いキャッシュ
      if (res.ok && new URL(request.url).origin === self.location.origin) {
        const copy = res.clone();
        caches.open(CACHE).then((c) => c.put(request, copy));
      }
      return res;
    })),
  );
});

// Web Push（送信側の実 Adapter 導入後に有効化）
self.addEventListener('push', (event) => {
  if (!event.data) return;
  const data = event.data.json();
  event.waitUntil(
    self.registration.showNotification(data.title || 'pato岡山', {
      body: data.body || '',
      icon: '/icon.svg',
      data: data.data || {},
    }),
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const callId = event.notification.data?.call_id;
  event.waitUntil(self.clients.openWindow(callId ? `/calls/${callId}` : '/home'));
});
