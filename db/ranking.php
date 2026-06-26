<?php
declare(strict_types=1);

// エラーとログを設定
ini_set('display_errors', '0');
error_reporting(E_ALL);

// セッション開始
session_start();

// ログインチェック
if (!isset($_SESSION['user_id'])) {
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

try {
    // 自分の順位と周辺ユーザーを取得（レート順、CPU除外）
    $stmt = $pdo->prepare("
        WITH ranked_users AS (
            SELECT 
                u.user_id,
                u.display_name,
                u.win_count,
                u.rating,
                u.icon_image,
                t.title_name,
                (SELECT r.rank_icon FROM ranks r WHERE r.min_rating <= u.rating ORDER BY r.min_rating DESC LIMIT 1) as rank_icon,
                (SELECT r.rank_name FROM ranks r WHERE r.min_rating <= u.rating ORDER BY r.min_rating DESC LIMIT 1) as user_rank_name,
                ROW_NUMBER() OVER (ORDER BY u.rating DESC) as rank
            FROM users u
            LEFT JOIN titles t ON u.title_id = t.title_id
            WHERE u.is_cpu = false
            ORDER BY u.rating DESC
        )
        SELECT * FROM ranked_users
        WHERE rank <= 100
    ");
    if (!$stmt->execute()) {
        throw new Exception('ランキングの取得に失敗しました');
    }
    $all_users = $stmt->fetchAll();
    
    // 自分の順位を取得
    $my_rank = null;
    $my_wins = 0;
    $my_rating = 1000;
    $my_rank_icon = '🥉';
    $my_rank_name_display = 'ブロンズ';
    foreach ($all_users as $user) {
        if ($user['user_id'] == $my_user_id) {
            $my_rank = $user['rank'];
            $my_wins = $user['win_count'];
            $my_rating = $user['rating'];
            $my_rank_icon = $user['rank_icon'] ?? '🥉';
            $my_rank_name_display = $user['user_rank_name'] ?? 'ブロンズ';
            break;
        }
    }

    // ランキングを3つのセクションに分ける
    $top_10 = array_filter($all_users, fn($u) => $u['rank'] <= 10);
    $my_section = array_filter($all_users, fn($u) => $u['rank'] >= max(1, $my_rank - 3) && $u['rank'] <= min(100, $my_rank + 3));
    $all_ranking = $all_users;
} catch (Exception $e) {
    die('エラーが発生しました: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES));
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ランキング - 早起きバトル</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            color: #333;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .header h1 { font-size: 24px; }
        .header a { color: white; text-decoration: none; background: rgba(255, 255, 255, 0.2); padding: 8px 16px; border-radius: 4px; }
        .header a:hover { background: rgba(255, 255, 255, 0.3); }
        
        .container {
            max-width: 700px;
            margin: 30px auto;
        }
        
        .my-status {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #667eea;
        }
        
        .my-status-content {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .my-status-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 40px;
            flex-shrink: 0;
        }
        
        .my-status-info {
            flex: 1;
        }
        
        .my-rank {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 8px;
        }
        
        .my-info {
            font-size: 16px;
            margin-bottom: 4px;
        }
        
        .my-title {
            font-size: 14px;
            color: #999;
        }
        
        .ranking-section {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }
        
        .ranking-title {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            font-size: 18px;
            font-weight: 600;
        }
        
        .ranking-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .ranking-row {
            border-bottom: 1px solid #eee;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: background 0.2s;
        }
        
        .ranking-row:hover {
            background: #f9f9f9;
        }
        
        .ranking-row.my-row {
            background: #f0f4ff;
            border-left: 4px solid #667eea;
        }
        
        .rank {
            font-size: 18px;
            font-weight: bold;
            color: #667eea;
            width: 50px;
            text-align: center;
        }
        
        .rank.gold { color: #ffc107; font-size: 24px; }
        .rank.silver { color: #c0c0c0; font-size: 24px; }
        .rank.bronze { color: #cd7f32; font-size: 24px; }
        
        .user-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }
        
        .user-icon img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .user-info {
            flex: 1;
        }
        
        .user-name {
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .user-title {
            font-size: 12px;
            color: #999;
        }
        
        .user-wins {
            font-size: 16px;
            font-weight: bold;
            color: #667eea;
            text-align: right;
            width: 80px;
        }
        
        .back-link {
            display: inline-block;
            margin-top: 30px;
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        .medal { 
            display: inline-block;
            font-size: 20px;
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🌅 ランキング</h1>
        <a href="alarm.php">ゲームに戻る</a>
    </div>
    
    <div class="container">
        <?php if ($my_rank): ?>
            <div class="my-status">
                <div class="my-status-content">
                    <div class="my-status-icon">
                        <?php if (isset($all_users[array_search($my_user_id, array_column($all_users, 'user_id'))]) && $all_users[array_search($my_user_id, array_column($all_users, 'user_id'))]['icon_image']): ?>
                            <img src="<?= htmlspecialchars($all_users[array_search($my_user_id, array_column($all_users, 'user_id'))]['icon_image'], ENT_QUOTES) ?>" style="width:100%;height:100%;border-radius:50%;object-fit:cover;" alt="プロフィール">
                        <?php else: ?>
                            👤
                        <?php endif; ?>
                    </div>
                    <div class="my-status-info">
                        <div class="my-rank"><?= $my_rank ?>位</div>
                        <div class="my-info"><?= htmlspecialchars($my_rank_icon, ENT_QUOTES) ?> <?= $my_rating ?>pt · <?= $my_wins ?>勝</div>
                        <?php 
                        $my_user_data = array_filter($all_users, fn($u) => $u['user_id'] == $my_user_id);
                        $my_user_data = reset($my_user_data);
                        ?>
                        <div class="my-title"><?= htmlspecialchars($my_rank_name_display, ENT_QUOTES) ?> / <?= htmlspecialchars($my_user_data['title_name'] ?? '新人', ENT_QUOTES) ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Top 10 ランキング -->
        <div class="ranking-section">
            <div class="ranking-title">🏆 Top 10</div>
            <div class="ranking-table">
                <?php foreach ($top_10 as $user): ?>
                    <div class="ranking-row <?= ($user['user_id'] == $my_user_id) ? 'my-row' : '' ?>">
                        <div class="rank <?php 
                            if ($user['rank'] == 1) echo 'gold';
                            elseif ($user['rank'] == 2) echo 'silver';
                            elseif ($user['rank'] == 3) echo 'bronze';
                        ?>">
                            <?php 
                            if ($user['rank'] == 1) echo '🥇';
                            elseif ($user['rank'] == 2) echo '🥈';
                            elseif ($user['rank'] == 3) echo '🥉';
                            else echo $user['rank'];
                            ?>
                        </div>
                        <div class="user-icon">
                            <?php if ($user['icon_image']): ?>
                                <img src="<?= htmlspecialchars($user['icon_image'], ENT_QUOTES) ?>" alt="<?= htmlspecialchars($user['display_name'], ENT_QUOTES) ?>">
                            <?php else: ?>
                                👤
                            <?php endif; ?>
                        </div>
                        <div class="user-info">
                            <div class="user-name"><?= htmlspecialchars($user['display_name'], ENT_QUOTES) ?></div>
                            <div class="user-title"><?= htmlspecialchars($user['user_rank_name'] ?? 'ブロンズ', ENT_QUOTES) ?> / <?= htmlspecialchars($user['title_name'] ?? '新人', ENT_QUOTES) ?></div>
                        </div>
                        <div class="user-wins"><?= htmlspecialchars($user['rank_icon'] ?? '🥉', ENT_QUOTES) ?> <?= $user['rating'] ?>pt<br><small><?= $user['win_count'] ?>勝</small></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- 全体ランキング -->
        <div class="ranking-section">
            <div class="ranking-title">📊 全体ランキング（Top 100）</div>
            <div class="ranking-table">
                <?php foreach ($all_ranking as $user): ?>
                    <div class="ranking-row <?= ($user['user_id'] == $my_user_id) ? 'my-row' : '' ?>">
                        <div class="rank">
                            <?php 
                            if ($user['rank'] == 1) echo '🥇';
                            elseif ($user['rank'] == 2) echo '🥈';
                            elseif ($user['rank'] == 3) echo '🥉';
                            else echo $user['rank'];
                            ?>
                        </div>
                        <div class="user-icon">
                            <?php if ($user['icon_image']): ?>
                                <img src="<?= htmlspecialchars($user['icon_image'], ENT_QUOTES) ?>" alt="<?= htmlspecialchars($user['display_name'], ENT_QUOTES) ?>">
                            <?php else: ?>
                                👤
                            <?php endif; ?>
                        </div>
                        <div class="user-info">
                            <div class="user-name"><?= htmlspecialchars($user['display_name'], ENT_QUOTES) ?></div>
                            <div class="user-title"><?= htmlspecialchars($user['user_rank_name'] ?? 'ブロンズ', ENT_QUOTES) ?> / <?= htmlspecialchars($user['title_name'] ?? '新人', ENT_QUOTES) ?></div>
                        </div>
                        <div class="user-wins"><?= htmlspecialchars($user['rank_icon'] ?? '🥉', ENT_QUOTES) ?> <?= $user['rating'] ?>pt<br><small><?= $user['win_count'] ?>勝</small></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <a href="alarm.php" class="back-link">← ゲームに戻る</a>
    </div>
</body>
</html>
