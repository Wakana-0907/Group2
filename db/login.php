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

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            $error = 'ユーザー名とパスワードを入力してください';
        } else {
            // ユーザーを検索
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            if (!$stmt->execute([$username])) {
                throw new Exception('ユーザーの検索に失敗しました');
            }
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                // ログイン成功
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['display_name'] = $user['display_name'];
                header('Location: alarm.php');
                exit;
            } else {
                $error = 'ユーザー名またはパスワードが正しくありません';
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
    <title>ログイン - 早起きバトル</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            color: #333;
        }
        .login-container {
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
        .signup-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: #666;
        }
        .signup-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        .signup-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>🌅 早起きバトル</h1>
        <p class="subtitle">リアルタイム朝起き対戦ゲーム</p>
        
        <?php if (!empty($error)): ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="username">ユーザー名</label>
                <input type="text" id="username" name="username" value="<?= htmlspecialchars($username, ENT_QUOTES) ?>" required autofocus>
            </div>
            
            <div class="form-group">
                <label for="password">パスワード</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit">ログイン</button>
        </form>
        
        <div class="signup-link">
            アカウントをお持ちではありませんか？
            <a href="register.php">新規登録はこちら</a>
        </div>
    </div>
</body>
</html>
