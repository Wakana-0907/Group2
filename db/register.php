<?php
declare(strict_types=1);

// エラーとログを設定
ini_set('display_errors', '0');
error_reporting(E_ALL);

// セッション開始
session_start();

// 既にログインしている場合は alarm.php にリダイレクト
if (isset($_SESSION['user_id'])) {
    header('Location: alarm.php');
    exit;
}

try {
    require_once __DIR__ . '/connect_db.php';
    if (!$pdo) {
        throw new Exception('データベース接続に失敗しました');
    }
    $pdo->exec("SET search_path TO suggest_plan");
    
    // ====== 応急処置：titlesテーブルが空ならデータを自動追加 ======
    try {
        $titles = [
            [1, '新人', 0],
            [2, '朝型戦士', 5],
            [3, '朝型マスター', 10],
            [4, '朝型王', 20],
            [5, '朝型伝説', 50]
        ];
        foreach ($titles as $title) {
            $checkStmt = $pdo->prepare("SELECT title_id FROM titles WHERE title_id = ?");
            $checkStmt->execute([$title[0]]);
            if (!$checkStmt->fetch()) {
                $insertStmt = $pdo->prepare("INSERT INTO titles (title_id, title_name, required_wins) VALUES (?, ?, ?)");
                $insertStmt->execute($title);
            }
        }
    } catch (Exception $e) {
        // 既にデータがある場合や重複エラー等は無視して進む
    }
    // =======================================================
} catch (Exception $e) {
    die('データベース接続エラー: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES));
}

$error = '';
$success = '';
$username = '';
$display_name = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $username = trim($_POST['username'] ?? '');
        $display_name = trim($_POST['display_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';
        
        // バリデーション
        if (empty($username) || empty($display_name) || empty($password)) {
            $error = 'すべてのフィールドを入力してください';
        } elseif (strlen($username) < 3 || strlen($username) > 20) {
            $error = 'ユーザー名は3〜20文字で入力してください';
        } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
            $error = 'ユーザー名は英数字、アンダースコア、ハイフンのみ使用可能です';
        } elseif (strlen($display_name) < 1 || strlen($display_name) > 50) {
            $error = '表示名は1〜50文字で入力してください';
        } elseif (strlen($password) < 6) {
            $error = 'パスワードは6文字以上で入力してください';
        } elseif ($password !== $password_confirm) {
            $error = 'パスワードが一致しません';
        } else {
            // ユーザー名が既に存在するか確認
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
            if (!$stmt->execute([$username])) {
                throw new Exception('ユーザー名の確認に失敗しました: ' . implode(', ', $stmt->errorInfo()));
            }
            if ($stmt->fetch()) {
                $error = 'このユーザー名は既に使用されています';
            } else {
                // 新規ユーザーを作成
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, password, display_name, title_id)
                    VALUES (?, ?, ?, 1)
                ");
                if (!$stmt->execute([$username, $hashed_password, $display_name])) {
                    throw new Exception('ユーザー登録に失敗しました: ' . implode(', ', $stmt->errorInfo()));
                }
                
                $success = 'アカウントを作成しました。ログインしてください。';
                $username = '';
                $display_name = '';
            }
        }
    } catch (Exception $e) {
        $error = 'エラーが発生しました: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>新規登録 - 早起きバトル</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Outfit', 'Noto Sans JP', sans-serif; 
            text-align: center; 
            background: linear-gradient(-45deg, #0f172a, #1e1b4b, #312e81, #0f172a);
            background-size: 400% 400%;
            animation: gradientBG 15s ease infinite;
            color: #e2e8f0; 
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }
        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .container {
            background: rgba(15, 23, 42, 0.6) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            backdrop-filter: blur(16px) !important;
            -webkit-backdrop-filter: blur(16px) !important;
            box-shadow: 0 20px 50px rgba(0,0,0,0.5) !important;
            color: #fff !important;
        }
        .stat-card {
            background: rgba(255,255,255,0.1) !important;
            border: 1px solid rgba(255,255,255,0.2) !important;
        }
        
        .register-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            padding: 40px;
            width: 100%;
            max-width: 400px;
        }
        h1 {
            text-align: center;
            color: #667eea;
            margin-bottom: 10px;
            font-size: 32px;
        }
        .subtitle {
            text-align: center;
            color: #999;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        button {
            width: 100%;
            padding: 12px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        button:hover {
            background: #764ba2;
        }
        .error {
            background: #fee;
            color: #c33;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #c33;
        }
        .success {
            background: #efe;
            color: #3c3;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #3c3;
        }
        .login-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: #666;
        }
        .login-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        .login-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <h1>🌅 早起きバトル</h1>
        <p class="subtitle">新規アカウント作成</p>
        
        <?php if (!empty($error)): ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="success"><?= htmlspecialchars($success, ENT_QUOTES) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="username">ユーザー名 (3〜20文字)</label>
                <input type="text" id="username" name="username" value="<?= htmlspecialchars($username, ENT_QUOTES) ?>" required autofocus>
            </div>
            
            <div class="form-group">
                <label for="display_name">表示名 (1〜50文字)</label>
                <input type="text" id="display_name" name="display_name" value="<?= htmlspecialchars($display_name, ENT_QUOTES) ?>" required>
            </div>
            
            <div class="form-group">
                <label for="password">パスワード (6文字以上)</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <div class="form-group">
                <label for="password_confirm">パスワード確認</label>
                <input type="password" id="password_confirm" name="password_confirm" required>
            </div>
            
            <button type="submit">登録</button>
        </form>
        
        <div class="login-link">
            既にアカウントをお持ちですか？
            <a href="login.php">ログインはこちら</a>
        </div>
    </div>
</body>
</html>
