<?php
declare(strict_types=1);

// エラーとログを設定
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/connect_db.php';
    if (!$pdo) {
        throw new Exception('データベース接続に失敗しました');
    }
    
    $result = $pdo->exec("SET search_path TO suggest_plan");
    if ($result === false) {
        throw new Exception('スキーマ設定に失敗しました');
    }
    
    $json = file_get_contents('php://input');
    $data = json_decode($json, true) ?: [];
    $action = $_GET['action'] ?? $data['action'] ?? '';
    
    // user_id を GET または POST JSON から取得（game.php の GET パラメータにも対応）
    $my_user_id = (int)($_GET['user_id'] ?? $data['user_id'] ?? 0);
    
    if ($my_user_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'user_id が指定されていません']);
        exit;
    }

    // 1. アラーム設定 ＆ マッチング開始
    if ($action === 'set_alarm') {
        $wake_time = $data['wake_time'] ?? '';
        if (strlen($wake_time) === 5) $wake_time .= ':00';

        $pdo->beginTransaction();
        
        // 過去の自分の未消化アラームと今日のマッチングをリセット
        $stmt = $pdo->prepare("DELETE FROM alarms WHERE user_id = ?");
        $stmt->execute([$my_user_id]);
        
        $stmt = $pdo->prepare("DELETE FROM matches WHERE (player1_id = ? OR player2_id = ?) AND match_date = CURRENT_DATE");
        $stmt->execute([$my_user_id, $my_user_id]);

        // 新しいアラームを登録
        $stmt = $pdo->prepare("INSERT INTO alarms (user_id, wake_time, is_active) VALUES (?, ?, true)");
        $stmt->execute([$my_user_id, $wake_time]);

        // 同じ時間の相手を探す（自分以外で、今日まだマッチしていない人）
        $stmt = $pdo->prepare("
            SELECT a.user_id FROM alarms a
            LEFT JOIN matches m ON (a.user_id = m.player1_id OR a.user_id = m.player2_id) AND m.match_date = CURRENT_DATE
            WHERE a.wake_time = ? AND a.user_id != ? AND m.match_id IS NULL AND a.is_active = true
            LIMIT 1
        ");
        $stmt->execute([$wake_time, $my_user_id]);
        $enemy_id = $stmt->fetchColumn();

        if ($enemy_id) {
            // 相手がいたのでマッチング成立！
            $stmt = $pdo->prepare("INSERT INTO matches (player1_id, player2_id, match_date, status, is_cpu_match) VALUES (?, ?, CURRENT_DATE, 'waiting', false)");
            $stmt->execute([$my_user_id, $enemy_id]);
        }
        $pdo->commit();
        echo json_encode(['status' => 'ok', 'debug' => ['user_id' => $my_user_id, 'wake_time' => $wake_time, 'enemy_id' => $enemy_id, 'matched' => (bool)$enemy_id]]);
        exit;
    }

    // 1.5. CPU自動割り当て（30秒経過後にフロントから呼ばれる）
    if ($action === 'assign_cpu') {
        $pdo->beginTransaction();
        
        // もう一度マッチング済みか確認（タイミングの関係で対人が見つかっているかも）
        $stmt = $pdo->prepare("
            SELECT m.match_id FROM matches m
            WHERE (m.player1_id = ? OR m.player2_id = ?) AND m.match_date = CURRENT_DATE
            ORDER BY m.match_id DESC LIMIT 1
        ");
        $stmt->execute([$my_user_id, $my_user_id]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // すでにマッチング済み
            $pdo->commit();
            echo json_encode(['status' => 'ok', 'already_matched' => true]);
            exit;
        }
        
        // CPUユーザーを取得
        $stmt = $pdo->query("SELECT user_id FROM users WHERE username = '__cpu_player__' LIMIT 1");
        $cpu = $stmt->fetch();
        if (!$cpu) {
            $pdo->commit();
            echo json_encode(['status' => 'error', 'message' => 'CPUユーザーが見つかりません']);
            exit;
        }
        $cpu_id = $cpu['user_id'];
        
        // CPUとのマッチを作成
        $stmt = $pdo->prepare("INSERT INTO matches (player1_id, player2_id, match_date, status, is_cpu_match) VALUES (?, ?, CURRENT_DATE, 'waiting', true)");
        $stmt->execute([$my_user_id, $cpu_id]);
        $match_id = (int)$pdo->lastInsertId('matches_match_id_seq');
        
        // CPUの起床時刻を事前計算（設定時刻 + 0〜15分のランダム遅延）
        $stmt = $pdo->prepare("SELECT wake_time FROM alarms WHERE user_id = ? AND is_active = true ORDER BY alarm_id DESC LIMIT 1");
        $stmt->execute([$my_user_id]);
        $alarm = $stmt->fetch();
        
        if ($alarm) {
            $wake_time = $alarm['wake_time'];
            $random_delay_minutes = random_int(0, 15);
            $random_delay_seconds = random_int(0, 59);
            // CPUの起床時刻を計算
            $cpu_wake = date('Y-m-d') . ' ' . $wake_time;
            $cpu_wake_ts = strtotime($cpu_wake) + ($random_delay_minutes * 60) + $random_delay_seconds;
            $cpu_wake_formatted = date('Y-m-d H:i:s', $cpu_wake_ts);
            
            // battles レコードを事前作成（CPUの起床時刻を記録）
            $stmt = $pdo->prepare("INSERT INTO battles (match_id, cpu_wake_time, battle_time) VALUES (?, ?, CURRENT_TIMESTAMP)");
            $stmt->execute([$match_id, $cpu_wake_formatted]);
        }
        
        $pdo->commit();
        echo json_encode([
            'status' => 'ok',
            'match_id' => $match_id,
            'enemy_name' => '🤖 寝坊助CPU',
            'is_cpu' => true
        ]);
        exit;
    }

    // 2. マッチング成立チェック（待機中のポーリング用）
    if ($action === 'check_match') {
        $stmt = $pdo->prepare("
            SELECT m.match_id, u.display_name, u.is_cpu, m.is_cpu_match, u.streak_count
            FROM matches m 
            JOIN users u ON (u.user_id = m.player1_id OR u.user_id = m.player2_id)
            WHERE (m.player1_id = ? OR m.player2_id = ?) AND m.match_date = CURRENT_DATE AND u.user_id != ?
            ORDER BY m.match_id DESC LIMIT 1
        ");
        $stmt->execute([$my_user_id, $my_user_id, $my_user_id]);
        $match = $stmt->fetch();
        if ($match) {
            $enemy_display = $match['is_cpu_match'] ? '🤖 寝坊助CPU' : $match['display_name'];
            echo json_encode([
                'matched' => true, 
                'match_id' => $match['match_id'], 
                'enemy_name' => $enemy_display, 
                'is_cpu' => (bool)$match['is_cpu_match'],
                'enemy_streak' => (int)$match['streak_count']
            ]);
        } else {
            echo json_encode(['matched' => false]);
        }
        exit;
    }

    if ($action === 'attack') {
        $match_id = (int)($data['match_id'] ?? 0);

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT player1_id, player2_id, is_cpu_match FROM matches WHERE match_id = ?");
        $stmt->execute([$match_id]);
        $match = $stmt->fetch();
        
        if(!$match) {
            // マッチが削除されている＝相手が逃亡（キャンセル）した
            assignRating($pdo, $my_user_id, 10);
            handleStreakWin($pdo, $my_user_id);
            $rank_info = getRankInfo($pdo, $my_user_id);
            echo json_encode([
                'status' => 'ok', 
                'result' => 'enemy_fled', 
                'is_cpu' => false,
                'rating_change' => 10,
                'new_rating' => $rank_info['rating'],
                'rank_name' => $rank_info['rank_name'],
                'rank_icon' => $rank_info['rank_icon'],
                'bonus_type' => null
            ]);
            $pdo->commit();
            exit;
        }
        
        $is_cpu_match = (bool)$match['is_cpu_match'];
        $enemy_id = ($match['player1_id'] == $my_user_id) ? $match['player2_id'] : $match['player1_id'];
        $my_col = ($match['player1_id'] == $my_user_id) ? 'player1_tap' : 'player2_tap';

        // 排他ロックをかけて同時にタップされた時の競合を防ぐ
        $stmt = $pdo->prepare("SELECT * FROM battles WHERE match_id = ? FOR UPDATE");
        $stmt->execute([$match_id]);
        $battle = $stmt->fetch();

        // 自分と相手のストリークを取得
        $stmt = $pdo->prepare("SELECT streak_count FROM users WHERE user_id = ?");
        $stmt->execute([$my_user_id]);
        $my_streak = (int)$stmt->fetchColumn();

        $enemy_streak = 0;
        if (!$is_cpu_match) {
            $stmt = $pdo->prepare("SELECT streak_count FROM users WHERE user_id = ?");
            $stmt->execute([$enemy_id]);
            $enemy_streak = (int)$stmt->fetchColumn();
        }

        $result = 'lose';
        $rating_change = 0;
        $bonus_type = null; // combo, bounty, none
        
        if ($is_cpu_match) {
            // ====== CPU対戦の勝敗判定 ======
            $now = time();
            if ($battle && $battle['cpu_wake_time']) {
                $cpu_ts = strtotime($battle['cpu_wake_time']);
                if ($now <= $cpu_ts) {
                    // プレイヤーがCPUより先に起きた ＝ 勝利！
                    $result = 'win';
                    $rating_change = 10; // CPU勝利
                    if ($my_streak >= 2) { $rating_change = 12; $bonus_type = 'combo'; }
                    if ($my_streak >= 6) { $rating_change = 15; $bonus_type = 'combo'; }
                    
                    $stmt = $pdo->prepare("UPDATE battles SET $my_col = CURRENT_TIMESTAMP, winner_id = ? WHERE match_id = ?");
                    $stmt->execute([$my_user_id, $match_id]);
                    $stmt = $pdo->prepare("UPDATE users SET win_count = win_count + 1 WHERE user_id = ?");
                    $stmt->execute([$my_user_id]);
                    assignTitle($pdo, $my_user_id);
                    handleStreakWin($pdo, $my_user_id);
                } else {
                    // CPUが先に起きていた ＝ 敗北
                    $result = 'lose';
                    $rating_change = -15; // CPU敗北
                    if ($my_streak >= 3) { $rating_change = -25; }
                    
                    $stmt = $pdo->prepare("UPDATE battles SET $my_col = CURRENT_TIMESTAMP, winner_id = ? WHERE match_id = ?");
                    $stmt->execute([$enemy_id, $match_id]);
                    $stmt = $pdo->prepare("UPDATE users SET lose_count = lose_count + 1 WHERE user_id = ?");
                    $stmt->execute([$my_user_id]);
                    handleStreakLose($pdo, $my_user_id);
                }
            } else {
                // battleレコードがない場合（通常はありえないが安全策）
                $result = 'win';
                $rating_change = 10;
                $stmt = $pdo->prepare("INSERT INTO battles (match_id, $my_col, winner_id, battle_time) VALUES (?, CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP)");
                $stmt->execute([$match_id, $my_user_id]);
                $stmt = $pdo->prepare("UPDATE users SET win_count = win_count + 1 WHERE user_id = ?");
                $stmt->execute([$my_user_id]);
                assignTitle($pdo, $my_user_id);
                handleStreakWin($pdo, $my_user_id);
            }
        } else {
            // ====== 対人戦の勝敗判定 ======
            if (!$battle || $battle['winner_id'] === null) {
                // 勝利！
                if (!$battle) {
                    $stmt = $pdo->prepare("INSERT INTO battles (match_id, $my_col, winner_id, battle_time) VALUES (?, CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP)");
                    $stmt->execute([$match_id, $my_user_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE battles SET $my_col = CURRENT_TIMESTAMP, winner_id = ? WHERE match_id = ?");
                    $stmt->execute([$my_user_id, $match_id]);
                }
                $stmt = $pdo->prepare("UPDATE users SET win_count = win_count + 1 WHERE user_id = ?");
                $stmt->execute([$my_user_id]);
                $stmt = $pdo->prepare("UPDATE users SET lose_count = lose_count + 1 WHERE user_id = ?");
                $stmt->execute([$enemy_id]);
                assignTitle($pdo, $my_user_id);
                handleStreakWin($pdo, $my_user_id);
                handleStreakLose($pdo, $enemy_id);
                
                $result = 'win';
                $rating_change = 25; // 対人勝利
                
                if ($enemy_streak >= 3) {
                    $rating_change += 25; // バウンティボーナス
                    $bonus_type = 'bounty';
                } else if ($my_streak >= 2) {
                    $rating_change = (int)($rating_change * 1.2); 
                    $bonus_type = 'combo';
                    if ($my_streak >= 6) { $rating_change = (int)(25 * 1.5); }
                }
                
                $enemy_rating_loss = -20;
                if ($enemy_streak >= 3) {
                    $enemy_rating_loss = -35; // コンボ保持者の敗北
                } else if ($my_streak >= 3) {
                    $enemy_rating_loss = -25; // コンボ保持者に負けた
                }
                assignRating($pdo, $enemy_id, $enemy_rating_loss);

            } else {
                // 敗北
                $stmt = $pdo->prepare("UPDATE battles SET $my_col = CURRENT_TIMESTAMP WHERE match_id = ?");
                $stmt->execute([$match_id]);
                $result = 'lose';
                $rating_change = -20; 
                if ($my_streak >= 3) {
                    $rating_change = -35; 
                } else if ($enemy_streak >= 3) {
                    $rating_change = -25; 
                }
                handleStreakLose($pdo, $my_user_id);
            }
        }
        
        // 自分のレートを更新
        assignRating($pdo, $my_user_id, $rating_change);
        
        // 更新後のレートとランク情報を取得
        $rank_info = getRankInfo($pdo, $my_user_id);
        
        $pdo->commit();
        echo json_encode([
            'status' => 'ok', 
            'result' => $result, 
            'is_cpu' => $is_cpu_match,
            'rating_change' => $rating_change,
            'new_rating' => $rank_info['rating'],
            'rank_name' => $rank_info['rank_name'],
            'rank_icon' => $rank_info['rank_icon'],
            'bonus_type' => $bonus_type,
            'my_streak' => ($result === 'win') ? $my_streak + 1 : 0
        ]);
        exit;
    }

    // 4. バトル中に「相手が先に起きたか？」を監視する処理
    if ($action === 'check_battle') {
        $match_id = (int)($_GET['match_id'] ?? 0);
        
        // CPUマッチか確認
        $stmt = $pdo->prepare("SELECT is_cpu_match FROM matches WHERE match_id = ?");
        $stmt->execute([$match_id]);
        $match_info = $stmt->fetch();
        $is_cpu = $match_info && (bool)$match_info['is_cpu_match'];
        
        $stmt = $pdo->prepare("SELECT winner_id, cpu_wake_time FROM battles WHERE match_id = ?");
        $stmt->execute([$match_id]);
        $battle = $stmt->fetch();
        
        if ($battle && $battle['winner_id']) {
            $result = ($battle['winner_id'] == $my_user_id) ? 'win' : 'lose';
            
            // 現在の自分のレートを取得して返す（ここで正確な変動幅は計算しづらいが、DB上の現在値は返せる）
            $rank_info = getRankInfo($pdo, $my_user_id);
            echo json_encode([
                'finished' => true, 
                'result' => $result,
                'new_rating' => $rank_info['rating'],
                'rank_name' => $rank_info['rank_name'],
                'rank_icon' => $rank_info['rank_icon']
            ]);
        } else if ($is_cpu && $battle && $battle['cpu_wake_time']) {
            // CPUの起床時刻を過ぎたかチェック
            $cpu_ts = strtotime($battle['cpu_wake_time']);
            if (time() > $cpu_ts && !$battle['winner_id']) {
                // CPUが先に起きたことにする
                $stmt2 = $pdo->prepare("SELECT player1_id, player2_id FROM matches WHERE match_id = ?");
                $stmt2->execute([$match_id]);
                $m = $stmt2->fetch();
                $cpu_id = ($m['player1_id'] == $my_user_id) ? $m['player2_id'] : $m['player1_id'];
                
                $pdo->beginTransaction();
                $stmt2 = $pdo->prepare("UPDATE battles SET winner_id = ? WHERE match_id = ? AND winner_id IS NULL");
                $stmt2->execute([$cpu_id, $match_id]);
                $stmt2 = $pdo->prepare("UPDATE users SET lose_count = lose_count + 1 WHERE user_id = ?");
                $stmt2->execute([$my_user_id]);
                
                // ストリークによるペナルティ増大
                $stmt2 = $pdo->prepare("SELECT streak_count FROM users WHERE user_id = ?");
                $stmt2->execute([$my_user_id]);
                $my_streak = (int)$stmt2->fetchColumn();
                $rating_change = -15;
                if ($my_streak >= 3) { $rating_change = -25; }
                
                assignRating($pdo, $my_user_id, $rating_change);
                handleStreakLose($pdo, $my_user_id);
                $pdo->commit();
                
                $rank_info = getRankInfo($pdo, $my_user_id);
                echo json_encode([
                    'finished' => true, 
                    'result' => 'lose',
                    'rating_change' => -15,
                    'new_rating' => $rank_info['rating'],
                    'rank_name' => $rank_info['rank_name'],
                    'rank_icon' => $rank_info['rank_icon']
                ]);
            } else {
                echo json_encode(['finished' => false]);
            }
        } else {
            echo json_encode(['finished' => false]);
        }
        exit;
    }

    // 5. マッチングをキャンセルする処理
    if ($action === 'cancel_alarm') {
        $pdo->beginTransaction();
        
        // 自分が参加しているマッチングを取得
        $stmt = $pdo->prepare("SELECT match_id, player1_id, player2_id FROM matches WHERE (player1_id = ? OR player2_id = ?) AND match_date = CURRENT_DATE");
        $stmt->execute([$my_user_id, $my_user_id]);
        $match = $stmt->fetch();
        
        // もしマッチングが成立していたらペナルティ（-10pt & コンボリセット）
        if ($match) {
            assignRating($pdo, $my_user_id, -10);
            handleStreakLose($pdo, $my_user_id);
            
            // 相手のアラームを無効化し、勝手に再マッチングされるのを防ぐ
            $enemy_id = ($match['player1_id'] == $my_user_id) ? $match['player2_id'] : $match['player1_id'];
            $stmt = $pdo->prepare("UPDATE alarms SET is_active = false WHERE user_id = ?");
            $stmt->execute([$enemy_id]);
            
            // 自分が参加しているマッチングを削除
            $stmt = $pdo->prepare("DELETE FROM matches WHERE match_id = ?");
            $stmt->execute([$match['match_id']]);
        }
        
        // 自分のアラームを削除
        $stmt = $pdo->prepare("DELETE FROM alarms WHERE user_id = ?");
        $stmt->execute([$my_user_id]);
        
        $pdo->commit();
        echo json_encode(['status' => 'ok', 'message' => 'マッチングをキャンセルしました']);
        exit;
    }

    // 6. バトル終了時にアラームをクリーンアップ
    if ($action === 'finish_battle') {
        $pdo->beginTransaction();
        
        // 自分のアラームを非アクティブにする
        $stmt = $pdo->prepare("UPDATE alarms SET is_active = false WHERE user_id = ? AND is_active = true");
        $stmt->execute([$my_user_id]);
        
        $pdo->commit();
        echo json_encode(['status' => 'ok', 'message' => 'バトル終了']);
        exit;
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

// ========== ヘルパー関数 ==========

/**
 * ユーザーの勝利数に応じて称号を自動付与
 */
function assignTitle($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT win_count FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $win_count = $stmt->fetchColumn();

    $title_id = 1;
    if ($win_count >= 50) $title_id = 5;      // 朝型伝説
    elseif ($win_count >= 20) $title_id = 4;  // 朝型王
    elseif ($win_count >= 10) $title_id = 3;  // 朝型マスター
    elseif ($win_count >= 5) $title_id = 2;   // 朝型戦士

    $stmt = $pdo->prepare("UPDATE users SET title_id = ? WHERE user_id = ?");
    $stmt->execute([$title_id, $user_id]);
}

/**
 * ユーザーのレーティングを変動させる（下限 0）
 */
function assignRating($pdo, $user_id, $change) {
    // CPUユーザーのレートは変動させない
    $stmt = $pdo->prepare("SELECT is_cpu FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $is_cpu = $stmt->fetchColumn();
    if ($is_cpu) return;
    
    $stmt = $pdo->prepare("UPDATE users SET rating = GREATEST(0, rating + ?) WHERE user_id = ?");
    $stmt->execute([$change, $user_id]);
}

/**
 * ユーザーの現在のレートとランク情報を取得
 */
function getRankInfo($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT rating FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $rating = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT rank_name, rank_icon FROM ranks WHERE min_rating <= ? ORDER BY min_rating DESC LIMIT 1");
    $stmt->execute([$rating]);
    $rank = $stmt->fetch();
    
    return [
        'rating' => $rating,
        'rank_name' => $rank ? $rank['rank_name'] : 'ブロンズ',
        'rank_icon' => $rank ? $rank['rank_icon'] : '🥉'
    ];
}

/**
 * 勝者のストリーク加算
 */
function handleStreakWin($pdo, $user_id) {
    // CPUユーザーは除外
    $stmt = $pdo->prepare("SELECT is_cpu FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    if ($stmt->fetchColumn()) return;
    
    $stmt = $pdo->prepare("UPDATE users SET streak_count = streak_count + 1 WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $stmt = $pdo->prepare("UPDATE users SET max_streak_count = GREATEST(max_streak_count, streak_count) WHERE user_id = ?");
    $stmt->execute([$user_id]);
}

/**
 * 敗者のストリークリセット
 */
function handleStreakLose($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT is_cpu FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    if ($stmt->fetchColumn()) return;
    
    $stmt = $pdo->prepare("UPDATE users SET streak_count = 0 WHERE user_id = ?");
    $stmt->execute([$user_id]);
}
?>