<?php
declare(strict_types=1);

// エラーとログを設定
ini_set('display_errors', '0');
error_reporting(E_ALL);

session_start();

// ログイン確認
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// ログアウト処理
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

try {
    require_once __DIR__ . '/connect_db.php';
    if (!$pdo) {
        throw new Exception('データベース接続に失敗しました');
    }
    $pdo->exec("SET search_path TO suggest_plan");
} catch (Exception $e) {
    die('データベース接続エラー: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES));
}

$my_user_id = (int)$_SESSION['user_id'];

// 現在の自分の情報を取得（タイトルも一緒に取得）
try {
    $stmt = $pdo->prepare("
        SELECT u.display_name, u.win_count, u.title_id, u.icon_image, u.rating, t.title_name, u.streak_count,
               (SELECT r.rank_icon FROM ranks r WHERE r.min_rating <= u.rating ORDER BY r.min_rating DESC LIMIT 1) as rank_icon,
               (SELECT r.rank_name FROM ranks r WHERE r.min_rating <= u.rating ORDER BY r.min_rating DESC LIMIT 1) as rank_name
        FROM users u
        LEFT JOIN titles t ON u.title_id = t.title_id
        WHERE u.user_id = ?
    ");
    if (!$stmt->execute([$my_user_id])) {
        throw new Exception('ユーザー情報の取得に失敗しました: ' . implode(', ', $stmt->errorInfo()));
    }
    $my_user = $stmt->fetch();
    
    if (!$my_user) {
        throw new Exception('ユーザーが見つかりません。ログインしてください。');
    }
    
    $my_display_name = $my_user['display_name'];
    $my_win_count = $my_user['win_count'];
    $my_title_name = $my_user['title_name'] ?? '新人';
    $my_icon_image = $my_user['icon_image'] ?? '';
    $my_rating = $my_user['rating'] ?? 1000;
    $my_rank_icon = $my_user['rank_icon'] ?? '🥉';
    $my_rank_name = $my_user['rank_name'] ?? 'ブロンズ';
    $my_streak_count = (int)($my_user['streak_count'] ?? 0);
} catch (Exception $e) {
    die('エラーが発生しました: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES));
}

// ページを開いた時、すでにアラーム設定済みかチェック
$stmt = $pdo->prepare("SELECT wake_time FROM alarms WHERE user_id = ? AND is_active = true ORDER BY alarm_id DESC LIMIT 1");
$stmt->execute([$my_user_id]);
$alarm = $stmt->fetch();
$target_time = $alarm ? $alarm['wake_time'] : '';

// ページを開いた時、すでにマッチング済みかチェック（アクティブなアラームがある場合のみ）
$match_id = 0;
$enemy_name = "";
if ($target_time) {
    $stmt = $pdo->prepare("
        SELECT m.match_id, u.display_name, m.is_cpu_match
        FROM matches m 
        JOIN users u ON (u.user_id = m.player1_id OR u.user_id = m.player2_id)
        WHERE (m.player1_id = ? OR m.player2_id = ?) AND m.match_date = CURRENT_DATE AND u.user_id != ?
        ORDER BY m.match_id DESC LIMIT 1
    ");
    $stmt->execute([$my_user_id, $my_user_id, $my_user_id]);
    $match = $stmt->fetch();
    if ($match) {
        $match_id = $match['match_id'];
        $enemy_name = $match['is_cpu_match'] ? '🤖 寝坊助CPU' : $match['display_name'];
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>早起きバトル</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            text-align: center; 
            background-color: #f5f5f5; 
            transition: all 0.5s ease; 
            color: #333; 
        }
        .header { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            color: white; 
            padding: 15px 30px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .header a { 
            color: white; 
            text-decoration: none; 
            font-size: 14px; 
            background: rgba(255, 255, 255, 0.2); 
            padding: 8px 16px; 
            border-radius: 6px; 
            transition: background 0.2s;
            font-weight: 600;
        }
        .header a:hover { background: rgba(255, 255, 255, 0.3); }
        .header .logout-btn { background: rgba(255, 87, 34, 0.9); }
        .header .logout-btn:hover { background: rgba(255, 87, 34, 1); }
        
        .container { 
            padding: 40px; 
            width: 100%; 
            max-width: 450px; 
            margin: 60px auto 0; 
            border-radius: 16px; 
            background-color: #fff; 
            box-shadow: 0 10px 40px rgba(0,0,0,0.08); 
            transition: all 0.5s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        @media (min-width: 768px) {
            .container {
                max-width: 850px;
                flex-direction: row;
                justify-content: space-around;
                align-items: center;
                gap: 40px;
                padding: 50px;
            }
            .screen {
                flex: 1;
                width: 100%;
                display: none;
            }
            .active-screen {
                display: flex !important;
                flex-direction: column;
                justify-content: center;
                animation: fadeIn 0.5s ease;
            }
            .clock-widget {
                flex: 1;
                margin: 0 !important;
            }
        }
        
        .time-display { 
            display: none; /* old text clock hidden */
        }
        
        /* === Clock Widget === */
        .clock-widget {
            margin: 40px auto 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }
        .analog-clock {
            position: relative;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            box-shadow: 
                8px 8px 16px rgba(0, 0, 0, 0.1),
                -8px -8px 16px rgba(255, 255, 255, 0.8),
                inset 2px 2px 5px rgba(255, 255, 255, 0.5),
                inset -3px -3px 7px rgba(0, 0, 0, 0.05);
            border: 4px solid white;
            display: flex;
            justify-content: center;
            align-items: center;
            transition: all 0.5s ease;
        }
        .night-mode .analog-clock {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            box-shadow: 
                8px 8px 16px rgba(0, 0, 0, 0.5),
                -8px -8px 16px rgba(255, 255, 255, 0.05),
                inset 2px 2px 5px rgba(255, 255, 255, 0.05),
                inset -3px -3px 7px rgba(0, 0, 0, 0.2);
            border-color: #334155;
        }
        /* Clock markers */
        .analog-clock::before {
            content: '';
            position: absolute;
            width: 100%; height: 100%;
            border-radius: 50%;
            background-image: 
                linear-gradient(0deg, transparent 48%, #cbd5e1 48%, #cbd5e1 52%, transparent 52%),
                linear-gradient(90deg, transparent 48%, #cbd5e1 48%, #cbd5e1 52%, transparent 52%);
            background-size: 100% 100%;
            opacity: 0.3;
        }
        .night-mode .analog-clock::before {
            background-image: 
                linear-gradient(0deg, transparent 48%, #475569 48%, #475569 52%, transparent 52%),
                linear-gradient(90deg, transparent 48%, #475569 48%, #475569 52%, transparent 52%);
        }
        .hand {
            position: absolute;
            bottom: 50%;
            left: 50%;
            transform-origin: bottom center;
            border-radius: 10px;
            z-index: 10;
        }
        .hour-hand {
            width: 6px;
            height: 45px;
            background-color: #334155;
            margin-left: -3px;
        }
        .night-mode .hour-hand { background-color: #e2e8f0; }
        
        .minute-hand {
            width: 4px;
            height: 65px;
            background-color: #64748b;
            margin-left: -2px;
        }
        .night-mode .minute-hand { background-color: #94a3b8; }
        
        .second-hand {
            width: 2px;
            height: 75px;
            background-color: #ef4444;
            margin-left: -1px;
            z-index: 11;
        }
        .clock-center {
            position: absolute;
            width: 12px;
            height: 12px;
            background-color: #ef4444;
            border-radius: 50%;
            z-index: 12;
            box-shadow: 0 0 4px rgba(0,0,0,0.3);
        }
        .digital-clock {
            font-size: 2.2em;
            font-weight: 800;
            font-family: 'Segoe UI', Tahoma, monospace;
            color: #444;
            letter-spacing: 2px;
            background: rgba(255,255,255,0.5);
            padding: 8px 24px;
            border-radius: 20px;
            box-shadow: inset 0 2px 5px rgba(0,0,0,0.05);
            transition: all 0.5s ease;
        }
        .night-mode .digital-clock {
            color: #93c5fd;
            background: rgba(15, 23, 42, 0.5);
            box-shadow: inset 0 2px 5px rgba(0,0,0,0.3);
        }
        .btn { 
            padding: 16px 30px; 
            font-size: 16px; 
            cursor: pointer; 
            border: none; 
            border-radius: 8px; 
            color: white; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-weight: bold; 
            width: 100%; 
            transition: transform 0.1s, box-shadow 0.3s;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }
        .btn:active { transform: translateY(1px); }
        
        #attack-btn { 
            background: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%); 
            font-size: 2.2em; 
            padding: 20px;
            display: none; 
            box-shadow: 0 0 25px rgba(255, 65, 108, 0.6); 
            animation: pulse 1s infinite; 
            border-radius: 12px;
        }
        @keyframes pulse { 
            0% { transform: scale(1); box-shadow: 0 0 25px rgba(255, 65, 108, 0.6); } 
            50% { transform: scale(1.03); box-shadow: 0 0 40px rgba(255, 65, 108, 0.9); } 
            100% { transform: scale(1); box-shadow: 0 0 25px rgba(255, 65, 108, 0.6); } 
        }
        
        .screen { display: none; width: 100%; }
        .active-screen { display: block; animation: fadeIn 0.5s ease; }
        @media (min-width: 768px) {
            .active-screen {
                display: flex;
                flex-direction: column;
                justify-content: center;
            }
        }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
        
        .char-icon { font-size: 6em; margin: 15px 0; }
        .alert-text { font-size: 1.4em; color: #ff4b2b; font-weight: bold; margin-bottom: 25px; }
        
        .ripple-loader {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background-color: #667eea;
            margin: 20px auto;
            animation: ripple 1.5s infinite ease-in-out;
        }
        @keyframes ripple {
            0% { transform: scale(0.8); opacity: 1; }
            100% { transform: scale(2.5); opacity: 0; }
        }

        /* Custom Time Picker */
        .time-picker-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 20px;
            margin: 20px auto 30px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            padding: 20px 30px;
            border-radius: 24px;
            box-shadow: inset 0 2px 15px rgba(0,0,0,0.03);
            backdrop-filter: blur(10px);
            width: fit-content;
            position: relative;
        }
        .time-picker-wrapper::after {
            content: ':';
            position: absolute;
            font-size: 36px;
            font-weight: bold;
            color: #667eea;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -55%);
        }
        .wheel-container {
            height: 150px;
            width: 80px;
            overflow-y: auto;
            scroll-snap-type: y mandatory;
            scrollbar-width: none;
            -ms-overflow-style: none;
            position: relative;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            scroll-behavior: smooth;
        }
        .wheel-container::-webkit-scrollbar { display: none; }
        .wheel-item {
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            scroll-snap-align: center;
            font-size: 24px;
            font-weight: bold;
            color: #ccc;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            user-select: none;
            cursor: pointer;
        }
        .wheel-item.active {
            color: #667eea;
            font-size: 34px;
        }
        .wheel-spacer {
            height: 50px;
        }
        
        .night-mode {
            background-color: #0f172a !important;
            color: #f8fafc !important;
        }
        .night-mode #main-container {
            background-color: rgba(30, 41, 59, 0.8) !important;
            backdrop-filter: blur(15px);
            box-shadow: 0 10px 40px rgba(0,0,0,0.3) !important;
        }
        .night-mode h2, .night-mode #digital-clock {
            color: #e2e8f0 !important;
        }
        h2 { color: #444; margin-bottom: 25px; transition: color 0.5s ease; }
    </style>
</head>
<body>

<div class="header" id="app-header">
    <div style="font-size: 16px; font-weight:600; display:flex; align-items:center;">
        <?php if ($my_icon_image): ?>
            <img src="<?= htmlspecialchars($my_icon_image, ENT_QUOTES) ?>" style="width:36px;height:36px;border-radius:50%;margin-right:10px;object-fit:cover;">
        <?php else: ?>
            <span style="font-size: 24px; margin-right: 8px;">👤</span>
        <?php endif; ?>
        <?= htmlspecialchars($my_display_name, ENT_QUOTES) ?> <span style="font-size:13px; opacity:0.8; margin-left:8px; font-weight:normal;"><?= htmlspecialchars($my_rank_icon, ENT_QUOTES) ?> <?= $my_rating ?>pt · <?= $my_win_count ?>勝</span>
    </div>
    <div style="display: flex; gap: 10px; align-items: center;">
        <a href="profile.php">プロフィール</a>
        <a href="ranking.php">ランキング</a>
        <a href="?logout=1" class="logout-btn">ログアウト</a>
    </div>
</div>

<div class="container" id="main-container">
    <div id="lobby-screen" class="screen active-screen">
        <h2>アラーム設定</h2>
        <?php if ($my_streak_count >= 2): ?>
            <div style="text-align:center; margin-bottom: 20px; color: #ffcc00; font-weight: bold; font-size: 1.1em; background: rgba(255,204,0,0.1); padding: 8px; border-radius: 8px; border: 1px solid rgba(255,204,0,0.3);">
                🔥 現在 <?= $my_streak_count ?> 連勝中！ボーナス発生中！
            </div>
        <?php endif; ?>
        
        <div id="setup-form">
            <div class="time-picker-wrapper">
                <div class="wheel-container" id="hour-wheel">
                    <div class="wheel-spacer"></div>
                    <!-- JS generated -->
                    <div class="wheel-spacer"></div>
                </div>
                <div class="wheel-container" id="minute-wheel">
                    <div class="wheel-spacer"></div>
                    <!-- JS generated -->
                    <div class="wheel-spacer"></div>
                </div>
            </div>
            <button id="set-alarm-btn" class="btn">アラームをセットしてマッチング検索</button>
        </div>
        
        <div id="searching-ui" style="display:none; margin-top:20px;">
            <div class="ripple-loader"></div>
            <p style="color: #667eea; font-weight: bold; font-size: 1.2em;">対戦相手を検索中...</p>
            <p style="font-size: 12px; color: #666;">(同じ時間に起きるプレイヤーを待っています)</p>
            <p id="cpu-countdown" style="font-size: 14px; color: #999; margin-top: 10px; display: none;">🤖 あと <span id="cpu-timer">30</span>秒で CPU と対戦</p>
            <button class="cancel-match-btn btn" style="background-color:#dc3545; margin-top:10px;">検索をキャンセル</button>
        </div>

        <div id="matched-ui" style="display:none; margin-top:20px;">
            <p style="color: blue; font-weight: bold; font-size: 1.5em;" id="enemy-name-display"></p>
            <p>準備完了！この画面のまま寝てください。<br>時間になるとバトルが始まります。</p>
            <button id="auth-audio-btn" class="btn" style="background-color:#ff9800; margin-top:15px;">音声再生を許可して就寝</button>
            <button class="cancel-match-btn btn" style="background-color:#dc3545; margin-top:10px;">マッチングをキャンセル</button>
        </div>
    </div>

    <div id="battle-screen" class="screen">
        <h2 style="color:white;">EARLY BIRD BATTLE</h2>
        <div class="char-icon" id="char-icon">⚔️</div>
        <p class="alert-text" id="status-text">敵が隙を見せている！今すぐ攻撃しろ！！</p>
        <button id="attack-btn" class="btn">攻撃する！！</button>
        <button id="back-to-home-btn" class="btn" style="background-color:#6c757d; margin-top:10px;">ホームに戻る</button>
        <p id="rating-result" style="margin-top: 15px; font-size: 1.1em; font-weight: bold; display: none;"></p>
    </div>

    <!-- Clock Widget -->
    <div class="clock-widget">
        <div class="analog-clock">
            <div class="hand hour-hand" id="hour-hand"></div>
            <div class="hand minute-hand" id="minute-hand"></div>
            <div class="hand second-hand" id="second-hand"></div>
            <div class="clock-center"></div>
        </div>
        <div class="digital-clock" id="digital-clock">00:00:00</div>
    </div>
</div>

<audio id="alarm-sound" src="Clock-Alarm05-3(Mid-Loop).mp3" preload="auto" loop></audio>
<audio id="click-sound" src="Clock-Alarm05-5(Toggle).mp3" preload="auto"></audio>

<script>
    let myUserId = <?= $my_user_id ?>;
    let myDisplayName = "<?= htmlspecialchars($my_display_name, ENT_QUOTES) ?>";
    let myWinCount = <?= $my_win_count ?>;
    let myTitleName = "<?= htmlspecialchars($my_title_name, ENT_QUOTES) ?>";
    let myIconImage = "<?= htmlspecialchars($my_icon_image, ENT_QUOTES) ?>";
    let myRating = <?= $my_rating ?>;
    let myRankIcon = "<?= htmlspecialchars($my_rank_icon, ENT_QUOTES) ?>";
    let myRankName = "<?= htmlspecialchars($my_rank_name, ENT_QUOTES) ?>";
    let targetTimeStr = "<?= $target_time ?>";
    let matchId = <?= $match_id ?: 'null' ?>;
    let enemyName = "<?= $enemy_name ?>";
    
    let currentState = 'setup'; // setup, searching, sleeping, battle, finished
    let cpuCountdown = null; // CPUカウントダウンタイマー
    let cpuTimerValue = 30;

    const alarmAudio = document.getElementById('alarm-sound');
    const clickAudio = document.getElementById('click-sound');

    // === ドラムロール式タイムピッカーの初期化 ===
    const hourWheel = document.getElementById('hour-wheel');
    const minuteWheel = document.getElementById('minute-wheel');
    let selectedHour = "06";
    let selectedMinute = "00";

    function initWheel(wheel, max) {
        for (let i = 0; i <= max; i++) {
            const div = document.createElement('div');
            div.className = 'wheel-item';
            div.dataset.value = i.toString().padStart(2, '0');
            div.innerText = div.dataset.value;
            // クリックでその時間にスクロール
            div.addEventListener('click', () => {
                wheel.scrollTo({ top: i * 50, behavior: 'smooth' });
            });
            wheel.insertBefore(div, wheel.lastElementChild);
        }

        let scrollTimeout;
        wheel.addEventListener('scroll', () => {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(() => {
                // スクロール停止時に一番近い位置にスナップ（念のため）
                const centerIndex = Math.round(wheel.scrollTop / 50);
                wheel.scrollTo({ top: centerIndex * 50, behavior: 'smooth' });
                updateWheelSelection(wheel);
            }, 100);
        });
    }

    function updateWheelSelection(wheel) {
        const itemHeight = 50;
        const centerIndex = Math.round(wheel.scrollTop / itemHeight);
        const items = wheel.querySelectorAll('.wheel-item');
        
        items.forEach((item, index) => {
            if (index === centerIndex) {
                item.classList.add('active');
                if (wheel.id === 'hour-wheel') selectedHour = item.dataset.value;
                if (wheel.id === 'minute-wheel') selectedMinute = item.dataset.value;
            } else {
                item.classList.remove('active');
            }
        });
    }

    initWheel(hourWheel, 23);
    initWheel(minuteWheel, 59);

    // 初期時間を 06:00 に設定
    setTimeout(() => {
        hourWheel.scrollTop = 6 * 50;
        minuteWheel.scrollTop = 0 * 50;
        updateWheelSelection(hourWheel);
        updateWheelSelection(minuteWheel);
    }, 100);

    // リロード時の状態復元
    if (targetTimeStr && !matchId) {
        changeState('searching');
    } else if (targetTimeStr && matchId) {
        changeState('sleeping');
    }

    // マッチング開始
    document.getElementById('set-alarm-btn').addEventListener('click', () => {
        const timeVal = selectedHour + ":" + selectedMinute;
        if (!timeVal) return alert('時間を入力してください');
        
        targetTimeStr = timeVal + (timeVal.length === 5 ? ":00" : "");
        
        fetch('api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'set_alarm', user_id: myUserId, wake_time: timeVal})
        }).then(() => {
            changeState('searching');
            startCpuCountdown();
            scheduleNotification(targetTimeStr);
        });
    });

    // 音声許可
    document.getElementById('auth-audio-btn').addEventListener('click', function() {
        this.style.display = 'none';
        alarmAudio.play().then(() => { alarmAudio.pause(); alarmAudio.currentTime = 0; }).catch(e=>{});
        clickAudio.play().then(() => { clickAudio.pause(); clickAudio.currentTime = 0; }).catch(e=>{});
    });

    // マッチングキャンセル（検索中または成立後の両方に対応）
    document.querySelectorAll('.cancel-match-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            if (!confirm('マッチングをキャンセルしますか？')) return;
            stopCpuCountdown();
            
            fetch('api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'cancel_alarm', user_id: myUserId})
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'ok') {
                    targetTimeStr = '';
                    matchId = null;
                    enemyName = '';
                    changeState('setup');
                    alert('マッチングをキャンセルしました');
                }
            });
        });
    });

    // 攻撃ボタン
    document.getElementById('attack-btn').addEventListener('click', function() {
        if(currentState !== 'battle') return;
        this.disabled = true;
        this.innerText = "通信中...";
        alarmAudio.pause();
        clickAudio.play();

        fetch('api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'attack', match_id: matchId, user_id: myUserId})
        })
        .then(r => r.json())
        .then(data => {
            if(data.status === 'ok') {
                endBattle(data.result, data.rating_change, data.new_rating, data.rank_name, data.rank_icon, data.bonus_type);
            }
        });
    });

    // 1秒ごとの監視ループと時計描画
    setInterval(() => {
        const now = new Date();
        const h = now.getHours();
        const m = now.getMinutes();
        const s = now.getSeconds();
        
        // デジタル時計更新
        const hStr = String(h).padStart(2, '0');
        const mStr = String(m).padStart(2, '0');
        const sStr = String(s).padStart(2, '0');
        document.getElementById('digital-clock').innerText = `${hStr}:${mStr}:${sStr}`;
        
        // アナログ時計の針の角度更新
        const hourDeg = (h % 12) * 30 + (m * 0.5); // 1時間=30度、1分=0.5度
        const minuteDeg = m * 6 + (s * 0.1);       // 1分=6度、1秒=0.1度
        const secondDeg = s * 6;                   // 1秒=6度
        
        document.getElementById('hour-hand').style.transform = `rotate(${hourDeg}deg)`;
        document.getElementById('minute-hand').style.transform = `rotate(${minuteDeg}deg)`;
        document.getElementById('second-hand').style.transform = `rotate(${secondDeg}deg)`;

        if (currentState === 'searching' || (currentState === 'sleeping' && s % 5 === 0)) {
            fetch(`api.php?action=check_match&user_id=${myUserId}`)
            .then(r => r.json())
            .then(data => {
                if (data.matched) {
                    if (currentState === 'searching') {
                        stopCpuCountdown();
                        matchId = data.match_id;
                        enemyName = data.enemy_name;
                        if (data.enemy_streak && data.enemy_streak >= 3) {
                            enemyName += ` <span style="color:#ff416c;">(🔥${data.enemy_streak}連勝中の賞金首)</span>`;
                        }
                        changeState('sleeping');
                    } else if (currentState === 'sleeping') {
                        if (matchId !== null && matchId !== data.match_id) {
                            matchId = data.match_id;
                            enemyName = data.enemy_name;
                            if (data.enemy_streak && data.enemy_streak >= 3) {
                                enemyName += ` <span style="color:#ff416c;">(🔥${data.enemy_streak}連勝中の賞金首)</span>`;
                            }
                            document.getElementById('enemy-name-display').innerHTML = `マッチ再成立！ VS ${enemyName} <br><span style="font-size: 0.8em; color: #ffcc00;">(前の相手は恐れをなして逃亡しました...)</span>`;
                        }
                    }
                } else {
                    if (currentState === 'sleeping') {
                        document.getElementById('enemy-name-display').innerHTML = `相手が逃亡しました！<br><span style="font-size: 0.8em; color: #ffcc00;">このまま寝て、アラーム時に起きれば不戦勝です。</span>`;
                    }
                }
            });
        } 
        else if (currentState === 'sleeping' || currentState === 'battle') {
            const currentMins = now.getHours() * 60 + now.getMinutes();
            const parts = targetTimeStr.split(':');
            const targetMins = parseInt(parts[0]) * 60 + parseInt(parts[1]);
            
            let diff = currentMins - targetMins;
            if (diff < -720) diff += 1440; else if (diff > 720) diff -= 1440;

            // 10分前〜60分後 が攻撃可能ウィンドウ
            if (diff >= -10 && diff <= 60 && currentState === 'sleeping') {
                changeState('battle');
            } else if (diff > 60 && currentState === 'battle') {
                endBattle('lose_timeout', -25, Math.max(0, myRating - 25), myRankName, myRankIcon);
            }

            // バトル中なら「相手が先に攻撃したか」を監視
            if (currentState === 'battle') {
                fetch(`api.php?action=check_battle&match_id=${matchId}&user_id=${myUserId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.finished) {
                        let diff = data.rating_change;
                        if (diff === undefined && data.new_rating !== undefined) {
                            diff = data.new_rating - myRating;
                        }
                        endBattle(data.result, diff, data.new_rating, data.rank_name, data.rank_icon, data.bonus_type);
                    }
                });
            }
        }
    }, 1000);

    function changeState(newState) {
        currentState = newState;
        document.getElementById('setup-form').style.display = 'none';
        document.getElementById('searching-ui').style.display = 'none';
        document.getElementById('matched-ui').style.display = 'none';
        
        if (newState === 'setup') {
            document.body.classList.remove('night-mode');
            document.getElementById('setup-form').style.display = 'block';
        } else if (newState === 'searching') {
            document.body.classList.remove('night-mode');
            document.getElementById('searching-ui').style.display = 'block';
        } else if (newState === 'sleeping') {
            document.body.classList.add('night-mode');
            document.getElementById('matched-ui').style.display = 'block';
            document.getElementById('enemy-name-display').innerHTML = `マッチ成立！ VS ${enemyName}`;
            document.getElementById('enemy-name-display').style.color = "#93c5fd";
        } else if (newState === 'battle') {
            document.body.classList.remove('night-mode');
            document.getElementById('lobby-screen').classList.remove('active-screen');
            document.getElementById('battle-screen').classList.add('active-screen');
            document.body.style.backgroundColor = "#1a0b16";
            document.getElementById('main-container').style.backgroundColor = "#2a1622";
            document.getElementById('main-container').style.boxShadow = "0 0 50px rgba(255, 65, 108, 0.3)";
            document.getElementById('digital-clock').style.color = "#ff416c";
            document.querySelector('#battle-screen h2').style.color = "#fff";
            document.getElementById('attack-btn').style.display = "block";
            document.getElementById('app-header').style.display = "none";
            alarmAudio.play().catch(e=>{});
        }
    }

    function endBattle(result, ratingChange, newRating, rankName, rankIcon, bonusType) {
        currentState = 'finished';
        document.getElementById('attack-btn').style.display = "none";
        alarmAudio.pause();
        
        const statusText = document.getElementById('status-text');
        const charIcon = document.getElementById('char-icon');
        const ratingResult = document.getElementById('rating-result');

        let bonusMessage = '';
        if (bonusType === 'bounty') {
            bonusMessage = '<br><span style="color:#ff416c; font-size:1.2em;">⚔️ 賞金首討伐！下剋上ボーナス獲得！</span>';
        } else if (bonusType === 'combo') {
            const multiplier = ratingChange > 25 ? (ratingChange >= 35 ? '1.5倍' : '1.2倍') : '';
            bonusMessage = `<br><span style="color:#ffcc00; font-size:1.2em;">🔥 コンボボーナス${multiplier ? '（'+multiplier+'）' : ''}でレートUP！</span>`;
        }

        if (result === 'win') {
            charIcon.innerText = "🏆";
            statusText.innerHTML = "最速起床！見事相手を撃破した！" + bonusMessage;
            statusText.style.color = "#00ffcc";
        } else if (result === 'enemy_fled') {
            charIcon.innerText = "💨";
            statusText.innerHTML = "不戦勝！相手は恐れをなして逃亡した！";
            statusText.style.color = "#ffcc00";
        } else if (result === 'lose_timeout') {
            charIcon.innerText = "💀";
            statusText.innerText = "寝坊しました。タイムアウト敗北...";
        } else {
            charIcon.innerText = "💥";
            statusText.innerText = "遅かった！相手に先に攻撃された！";
        }
        
        // レート変動表示
        if (ratingChange !== undefined && ratingChange !== null) {
            const arrow = ratingChange >= 0 ? '🔼' : '🔽';
            const sign = ratingChange >= 0 ? '+' : '';
            const color = ratingChange >= 0 ? '#00ffcc' : '#ff6b6b';
            ratingResult.innerHTML = `${arrow} ${sign}${ratingChange}pt → ${newRating}pt（${rankIcon || ''} ${rankName || ''}）`;
            ratingResult.style.color = color;
            ratingResult.style.display = 'block';
            
            // ローカル変数も更新
            if (newRating !== undefined) myRating = newRating;
            if (rankName) myRankName = rankName;
            if (rankIcon) myRankIcon = rankIcon;
        }
        
        document.getElementById('back-to-home-btn').style.display = "block";
    }
    
    // ホームに戻るボタン
    document.getElementById('back-to-home-btn').addEventListener('click', function() {
        fetch('api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'finish_battle', user_id: myUserId})
        })
        .then(r => r.json())
        .then(data => {
            targetTimeStr = '';
            matchId = null;
            enemyName = '';
            currentState = 'setup';
            
            const appHeader = document.getElementById('app-header');
            appHeader.style.display = "flex";
            
            document.getElementById('lobby-screen').classList.add('active-screen');
            document.getElementById('battle-screen').classList.remove('active-screen');
            document.body.classList.remove('night-mode');
            document.body.style.backgroundColor = "#f5f5f5";
            document.getElementById('main-container').style.backgroundColor = "#fff";
            document.getElementById('main-container').style.boxShadow = "0 10px 40px rgba(0,0,0,0.08)";
            document.getElementById('digital-clock').style.color = ""; // reset color
            document.querySelector('#battle-screen h2').style.color = "#444";
            
            document.getElementById('attack-btn').style.display = "none";
            document.getElementById('attack-btn').disabled = false;
            document.getElementById('attack-btn').innerText = "攻撃する！！";
            document.getElementById('back-to-home-btn').style.display = "none";
            document.getElementById('rating-result').style.display = "none";
            
            document.getElementById('setup-form').style.display = 'block';
            document.getElementById('searching-ui').style.display = 'none';
            document.getElementById('matched-ui').style.display = 'none';
        });
    });

    // ====== CPUカウントダウン機能 ======
    function startCpuCountdown() {
        cpuTimerValue = 30;
        const countdownEl = document.getElementById('cpu-countdown');
        const timerEl = document.getElementById('cpu-timer');
        countdownEl.style.display = 'block';
        timerEl.innerText = cpuTimerValue;
        
        cpuCountdown = setInterval(() => {
            cpuTimerValue--;
            timerEl.innerText = cpuTimerValue;
            
            if (cpuTimerValue <= 0) {
                stopCpuCountdown();
                // CPUを自動割り当て
                fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'assign_cpu', user_id: myUserId})
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'ok' && !data.already_matched) {
                        matchId = data.match_id;
                        enemyName = data.enemy_name || '🤖 寝坊助CPU';
                        changeState('sleeping');
                    }
                });
            }
        }, 1000);
    }
    
    function stopCpuCountdown() {
        if (cpuCountdown) {
            clearInterval(cpuCountdown);
            cpuCountdown = null;
        }
        const el = document.getElementById('cpu-countdown');
        if (el) el.style.display = 'none';
    }

    // ====== Service Worker & 通知機能 ======
    let swRegistration = null;
    
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js').then(function(reg) {
            swRegistration = reg;
            console.log('Service Worker 登録成功');
        }).catch(function(err) {
            console.log('Service Worker 登録失敗:', err);
        });
    }
    
    function scheduleNotification(timeStr) {
        if (!('Notification' in window)) return;
        
        Notification.requestPermission().then(function(permission) {
            if (permission !== 'granted' || !swRegistration) return;
            
            const parts = timeStr.split(':');
            const targetDate = new Date();
            targetDate.setHours(parseInt(parts[0]), parseInt(parts[1]), 0, 0);
            
            // 時刻が過去なら翌日に設定
            if (targetDate.getTime() <= Date.now()) {
                targetDate.setDate(targetDate.getDate() + 1);
            }
            
            const delay = targetDate.getTime() - Date.now();
            
            // メインアラーム通知
            if (swRegistration.active) {
                swRegistration.active.postMessage({
                    type: 'SCHEDULE_NOTIFICATION',
                    delay: delay,
                    title: '⚔️ 早起きバトル',
                    body: '起床時間です！攻撃ボタンを押してください！'
                });
                
                // 5分前の予告通知
                if (delay > 5 * 60 * 1000) {
                    swRegistration.active.postMessage({
                        type: 'SCHEDULE_PRE_NOTIFICATION',
                        delay: delay - (5 * 60 * 1000)
                    });
                }
            }
        });
    }
</script>

<div style="position: fixed; bottom: 10px; right: 15px; font-size: 11px; color: #888; text-align: right; z-index: 1000; pointer-events: auto;">
    使用した音素材：<a href="https://otologic.jp" target="_blank" style="color: #667eea; text-decoration: none;">OtoLogic</a>
</div>

</body>
</html>