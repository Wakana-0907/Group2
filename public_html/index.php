<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>アラーム2 - 通知設定テスト</title>
    <style>
        body { font-family: sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background-color: #f5f5f5; }
        .container { text-align: center; padding: 30px; background: white; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        button { padding: 12px 24px; font-size: 16px; cursor: pointer; background-color: #007bff; color: white; border: none; border-radius: 4px; }
        button:disabled { background-color: #cccccc; cursor: not-allowed; }
    </style>
</head>
<body>

<div class="container">
    <h2>アラーム2 通知設定</h2>
    <button id="subscribe-btn">通知を許可する</button>
</div>

<script>
// =================================================================
// 📢 【村上さん書き換えエリア①】
// 長谷川さんから共有された本番のVAPID公開鍵を反映しました！
// =================================================================
const VAPID_PUBLIC_KEY = 'BAAkMKjzX9H5pK8uL2T0Rd340FsopvmTBdZX0CPjuphM3ahM0nhbIHlaP60579dcTJAa1IFXzochiLFg-OCBKY0';


// =================================================================
// 📢 【村上さん書き換えエリア②】
// 長谷川さんが作った購読保存APIのファイル名が「subscribe.php」から変更になった場合のみ書き換えてください。
// =================================================================
const SUBSCRIBE_API_URL = 'subscribe.php';


// --- これより下のコードは一切書き換え不要です ---

window.addEventListener('load', async () => {
    if ('serviceWorker' in navigator && 'PushManager' in window) {
        try {
            const registration = await navigator.serviceWorker.register('service-worker.js');
            console.log('Service Worker 登録成功:', registration);
            initializePushUi(registration);
        } catch (error) {
            console.error('Service Worker 登録失敗:', error);
        }
    } else {
        console.warn('このブラウザは Web Push に対応していません。');
        const subscribeBtn = document.getElementById('subscribe-btn');
        if (subscribeBtn) subscribeBtn.textContent = 'Web Push非対応のブラウザです';
    }
});

function initializePushUi(registration) {
    const subscribeBtn = document.getElementById('subscribe-btn');
    if (!subscribeBtn) return;

    if (Notification.permission === 'denied') {
        subscribeBtn.textContent = '通知がブロックされています';
        subscribeBtn.disabled = true;
        return;
    }

    subscribeBtn.addEventListener('click', async () => {
        subscribeBtn.disabled = true;
        try {
            const permission = await Notification.requestPermission();
            
            if (permission === 'granted') {
                const subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
                });

                await sendSubscriptionToServer(subscription);
                subscribeBtn.textContent = '通知許可済み';
            } else {
                alert('通知が許可されませんでした。');
                subscribeBtn.disabled = false;
            }
        } catch (error) {
            console.error('購読処理中にエラーが発生しました:', error);
            subscribeBtn.disabled = false;
        }
    });
}

async function sendSubscriptionToServer(subscription) {
    const rawJson = subscription.toJSON();
    
    const payload = {
        user_id: 1, // テスト用の仮ユーザーID
        endpoint: rawJson.endpoint,
        keys: {
            p256dh: rawJson.keys.p256dh,
            auth: rawJson.keys.auth
        }
    };

    const response = await fetch(SUBSCRIBE_API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });

    if (!response.ok) {
        throw new Error('サーバーへの購読情報保存に失敗しました。');
    }
    console.log('購読情報が正常にサーバーへ送信されました。');
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}
</script>
</body>
</html>