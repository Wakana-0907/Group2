// 早起きバトル Service Worker

// Push通知を受信した時
self.addEventListener('push', function(event) {
    const data = event.data ? event.data.json() : {};
    const title = data.title || '⚔️ 早起きバトル';
    const options = {
        body: data.body || 'アラームの時間です！起きてバトルしましょう！',
        icon: data.icon || '🌅',
        badge: '⚔️',
        vibrate: [200, 100, 200, 100, 200],
        tag: 'early-bird-battle',
        renotify: true,
        requireInteraction: true,
        data: {
            url: self.registration.scope + 'alarm.php'
        }
    };
    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

// 通知がクリックされた時
self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    const targetUrl = event.notification.data?.url || 'alarm.php';
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
            // 既に開いているタブがあればフォーカス
            for (let i = 0; i < clientList.length; i++) {
                if (clientList[i].url.includes('alarm.php') && 'focus' in clientList[i]) {
                    return clientList[i].focus();
                }
            }
            // なければ新しいタブで開く
            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        })
    );
});

// メインスレッドからのメッセージを受信（ローカル通知スケジュール用）
self.addEventListener('message', function(event) {
    if (event.data && event.data.type === 'SCHEDULE_NOTIFICATION') {
        const delay = event.data.delay; // ミリ秒
        const title = event.data.title || '⚔️ 早起きバトル';
        const body = event.data.body || '起床時間です！攻撃ボタンを押してください！';
        
        setTimeout(() => {
            self.registration.showNotification(title, {
                body: body,
                vibrate: [200, 100, 200, 100, 200],
                tag: 'early-bird-alarm',
                renotify: true,
                requireInteraction: true,
                data: {
                    url: self.registration.scope + 'alarm.php'
                }
            });
        }, Math.max(0, delay));
    }
    
    if (event.data && event.data.type === 'SCHEDULE_PRE_NOTIFICATION') {
        const delay = event.data.delay; // ミリ秒
        
        setTimeout(() => {
            self.registration.showNotification('🌅 早起きバトル', {
                body: 'あと5分でバトル開始！準備はいいですか？',
                vibrate: [100, 50, 100],
                tag: 'early-bird-pre-alarm',
                data: {
                    url: self.registration.scope + 'alarm.php'
                }
            });
        }, Math.max(0, delay));
    }
});

// Service Worker インストール時
self.addEventListener('install', function(event) {
    self.skipWaiting();
});

// Service Worker アクティベート時
self.addEventListener('activate', function(event) {
    event.waitUntil(clients.claim());
});
