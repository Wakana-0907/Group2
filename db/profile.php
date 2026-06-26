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
$success = '';
$error = '';

// ユーザー情報を取得
try {
    // レーティング・ランク情報も含めてユーザーデータを取得
    $stmt = $pdo->prepare("
        SELECT u.display_name, u.icon_image, u.win_count, u.rating, u.lose_count, t.title_name,
               u.streak_count, u.max_streak_count,
            (SELECT rank_icon FROM ranks WHERE min_rating <= u.rating ORDER BY min_rating DESC LIMIT 1) as rank_icon,
            (SELECT rank_name FROM ranks WHERE min_rating <= u.rating ORDER BY min_rating DESC LIMIT 1) as user_rank_name
        FROM users u
        LEFT JOIN titles t ON u.title_id = t.title_id
        WHERE u.user_id = ?
    ");
    if (!$stmt->execute([$my_user_id])) {
        throw new Exception('ユーザー情報の取得に失敗しました');
    }
    $user = $stmt->fetch();
    
    if (!$user) {
        throw new Exception('ユーザーが見つかりません');
    }
    
    $display_name = $user['display_name'];
    $icon_image = $user['icon_image'] ?? '';
    $win_count = (int)$user['win_count'];
    $lose_count = (int)($user['lose_count'] ?? 0);
    $rating = (int)($user['rating'] ?? 1000);
    $title_name = $user['title_name'] ?? '新人';
    $rank_icon = $user['rank_icon'] ?? '🥉';
    $rank_name = $user['user_rank_name'] ?? 'ブロンズ';
    $streak_count = (int)($user['streak_count'] ?? 0);
    $max_streak_count = (int)($user['max_streak_count'] ?? 0);
    // 勝率を計算（試合数 0 の場合は 0%）
    $total_matches = $win_count + $lose_count;
    $win_rate = $total_matches > 0 ? round($win_count / $total_matches * 100, 1) : 0;
    
    // プロフィール更新処理
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $new_display_name = trim($_POST['display_name'] ?? '');
        
        if (empty($new_display_name)) {
            $error = '表示名を入力してください';
        } elseif (strlen($new_display_name) > 50) {
            $error = '表示名は50文字以内で入力してください';
        } else {
            // アイコン画像のアップロード処理
            if (isset($_FILES['icon_image']) && $_FILES['icon_image']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['icon_image'];
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                
                if (!in_array($file['type'], $allowed_types)) {
                    $error = 'JPEG、PNG、GIF、WebP形式の画像をアップロードしてください';
                } elseif ($file['size'] > 2 * 1024 * 1024) {
                    $error = '画像は2MB以下にしてください';
                } else {
                    // ファイルを保存
                    $upload_dir = __DIR__ . '/../uploads/';
                    if (!is_dir($upload_dir)) {
                        if (!mkdir($upload_dir, 0755, true)) {
                            throw new Exception('アップロードディレクトリの作成に失敗しました');
                        }
                    }
                    
                    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    if (!in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                        throw new Exception('無効なファイル拡張子です');
                    }
                    
                    $file_name = 'user_' . $my_user_id . '_' . time() . '.' . $file_extension;
                    $file_path = $upload_dir . $file_name;
                    
                    if (!move_uploaded_file($file['tmp_name'], $file_path)) {
                        throw new Exception('ファイルのアップロードに失敗しました');
                    }
                    
                    $icon_image = '../uploads/' . $file_name;
                    $stmt = $pdo->prepare("UPDATE users SET display_name = ?, icon_image = ? WHERE user_id = ?");
                    if (!$stmt->execute([$new_display_name, $icon_image, $my_user_id])) {
                        throw new Exception('プロフィール更新に失敗しました');
                    }
                    $success = 'プロフィールを更新しました';
                    $display_name = $new_display_name;
                }
            } else {
                // アイコンなしで表示名のみ更新
                $stmt = $pdo->prepare("UPDATE users SET display_name = ? WHERE user_id = ?");
                if (!$stmt->execute([$new_display_name, $my_user_id])) {
                    throw new Exception('プロフィール更新に失敗しました');
                }
                $success = 'プロフィールを更新しました';
                $display_name = $new_display_name;
            }
        }
    }
} catch (Exception $e) {
    $error = 'エラーが発生しました: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>プロフィール - 早起きバトル</title>
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
            max-width: 600px;
            margin: 40px auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 20px;
            text-align: center;
        }
        
        .profile-icon {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 50px;
            margin: 0 auto 20px;
            border: 3px solid white;
        }
        
        .profile-icon img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .profile-info {
            text-align: center;
        }
        .profile-name { font-size: 24px; font-weight: bold; margin-bottom: 8px; }
        .profile-title { font-size: 16px; opacity: 0.9; margin-bottom: 8px; }
        .profile-wins { font-size: 18px; font-weight: bold; opacity: 0.9; }
        .profile-rank { font-size: 20px; margin-bottom: 6px; font-weight: bold; }
        .profile-rating { font-size: 16px; opacity: 0.85; margin-bottom: 4px; }
        
        /* バトル戦績カードのスタイル */
        .stats-section {
            margin-bottom: 28px;
        }
        .stats-section h2 {
            font-size: 20px;
            margin-bottom: 16px;
            color: #333;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        @media (min-width: 600px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        .stat-card {
            background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%);
            border-radius: 10px;
            padding: 16px 12px;
            text-align: center;
            border: 1px solid #e0d4fd;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .stat-card .stat-value {
            font-size: 22px;
            font-weight: 700;
            color: #5b21b6;
            margin-bottom: 4px;
        }
        .stat-card .stat-label {
            font-size: 12px;
            color: #7c3aed;
            font-weight: 600;
        }
        .stat-card.highlight {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        .stat-card.highlight .stat-value {
            color: white;
        }
        .stat-card.highlight .stat-label {
            color: rgba(255, 255, 255, 0.85);
        }
        
        .content {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        input[type="text"],
        input[type="file"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        input[type="text"]:focus,
        input[type="file"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .file-preview {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-top: 12px;
            padding: 12px;
            background: #f5f5f5;
            border-radius: 6px;
        }
        
        .file-preview img {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .success {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #f5c6cb;
        }
        
        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: transform 0.2s;
        }
        
        button:hover {
            transform: translateY(-2px);
        }
        
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🌅 早起きバトル</h1>
        <a href="alarm.php">ゲームに戻る</a>
    </div>
    
    <div class="container">
        <div class="profile-header">
            <div class="profile-icon">
                <?php if ($icon_image): ?>
                    <img src="<?= htmlspecialchars($icon_image, ENT_QUOTES) ?>" alt="プロフィール画像">
                <?php else: ?>
                    👤
                <?php endif; ?>
            </div>
            <div class="profile-info">
                <div class="profile-name"><?= htmlspecialchars($display_name, ENT_QUOTES) ?></div>
                <div class="profile-title">🏆 <?= htmlspecialchars($title_name, ENT_QUOTES) ?></div>
                <div class="profile-rank"><?= htmlspecialchars($rank_icon, ENT_QUOTES) ?> <?= htmlspecialchars($rank_name, ENT_QUOTES) ?></div>
                <div class="profile-rating"><?= $rating ?> pt</div>
                <div class="profile-wins"><?= $win_count ?>勝 <?= $lose_count ?>敗 (勝率 <?= $win_rate ?>%)</div>
            </div>
        </div>
        
        <div class="content">
            <?php if (!empty($success)): ?>
                <div class="success"><?= htmlspecialchars($success, ENT_QUOTES) ?></div>
            <?php endif; ?>
            
            <?php if (!empty($error)): ?>
                <div class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
            <?php endif; ?>
            
            <!-- バトル戦績セクション -->
            <div class="stats-section">
                <h2>⚔️ バトル戦績</h2>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?= $total_matches ?></div>
                        <div class="stat-label">総試合数</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= $win_count ?></div>
                        <div class="stat-label">勝利</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= $lose_count ?></div>
                        <div class="stat-label">敗北</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= $win_rate ?>%</div>
                        <div class="stat-label">勝率</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= $streak_count ?>🔥</div>
                        <div class="stat-label">現在コンボ</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= $max_streak_count ?></div>
                        <div class="stat-label">最大コンボ</div>
                    </div>
                    <div class="stat-card highlight">
                        <div class="stat-value"><?= $rating ?></div>
                        <div class="stat-label">レーティング</div>
                    </div>
                    <div class="stat-card highlight">
                        <div class="stat-value"><?= htmlspecialchars($rank_icon, ENT_QUOTES) ?></div>
                        <div class="stat-label"><?= htmlspecialchars($rank_name, ENT_QUOTES) ?></div>
                    </div>
                </div>
            </div>
            
            <h2 style="margin-bottom: 24px; font-size: 20px;">プロフィール編集</h2>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="display_name">表示名</label>
                    <input type="text" id="display_name" name="display_name" value="<?= htmlspecialchars($display_name, ENT_QUOTES) ?>" maxlength="50" required>
                </div>
                
                <div class="form-group">
                    <label for="icon_image">プロフィール画像（JPEG/PNG/GIF/WebP、2MB以内）</label>
                    <input type="file" id="icon_image" name="icon_image" accept="image/*">
                    <?php if ($icon_image): ?>
                        <div class="file-preview">
                            <img src="<?= htmlspecialchars($icon_image, ENT_QUOTES) ?>" alt="現在のプロフィール画像">
                            <div>
                                <strong>現在の画像</strong><br>
                                <small>新しい画像を選択して置き換えます</small>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <button type="submit">保存</button>
            </form>
            
            <a href="alarm.php" class="back-link">← ゲームに戻る</a>
        </div>
    </div>
</body>
</html>
