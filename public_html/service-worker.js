// =================================================================
// 📢 【村上さん書き換えエリア③】
// バトルチーム（岡田さん・福井さん）に確認して、バトル画面のURL（ファイル名）が
// 決まったら、下のURL（仮で '/battle/index.php' にしています）を書き換えてください。
// =================================================================
const DEFAULT_BATTLE_URL = '/battle/index.php';


// --- これより下のコードは一切書き換え不要です ---

self.addEventListener('push', function(event) {
    // デフォルトの通知テキスト
    let data = {
        title: '起床時刻です！',
        body: 'バトル画面へ移動してアラームを止めましょう！',
        data: { url: DEFAULT_BATTLE_URL }
    };

    // サーバーからカスタム通知データ（ペイロード）が届いた場合は上書き
    if (event.data) {
        try {
            data = event.data.json();
        } catch (e) {
            console.error('通知ペイロードの解析に失敗しました:', e);
        }
    }

    const title = data.title;
    const options = {
        body: data.body,
        icon: '/favicon.ico',
        badge: '/favicon.ico',
        data: {
            // サーバー側から個別にURL指定がなければ、上記のデフォルトURLを使う
            url: data.data && data.data.url ? data.data.url : DEFAULT_BATTLE_URL
        }
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();

    const targetUrl = event.notification.data.url;

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
            for (let i = 0; i < clientList.length; i++) {
                let client = clientList[i];
                if ('navigate' in client) {
                    client.focus();
                    return client.navigate(targetUrl);
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        })
    );
});