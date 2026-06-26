<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/connect_db.php';

header('Content-Type: text/plain; charset=utf-8');

try {

    if (!$pdo) {
        throw new Exception('データベース接続に失敗しました');
    }

    echo "1. DB接続OK\n";

    $pdo->exec("CREATE SCHEMA IF NOT EXISTS suggest_plan");
    echo "2. CREATE SCHEMA OK\n";

    $result = $pdo->exec("SET search_path TO suggest_plan");
    if ($result === false) {
        throw new Exception('スキーマ設定に失敗しました');
    }
    echo "3. SET SEARCH_PATH OK\n";

    $pdo->beginTransaction();
    // 既存テーブル削除（開発用）
    $pdo->exec("DROP TABLE IF EXISTS battles CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS matches CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS notifications CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS alarms CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS users CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS titles CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS ranks CASCADE");



    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ranks (
            rank_id SERIAL PRIMARY KEY,
            rank_name VARCHAR(50) NOT NULL,
            min_rating INT NOT NULL,
            rank_icon VARCHAR(10) NOT NULL
        );
    ");
    echo "4. ranks OK\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS titles (
            title_id SERIAL PRIMARY KEY,
            title_name VARCHAR(50) NOT NULL,
            required_wins INT NOT NULL
        );
    ");
    echo "5. titles OK\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            user_id SERIAL PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            display_name VARCHAR(50) NOT NULL,
            icon_image VARCHAR(255),
            win_count INT DEFAULT 0,
            lose_count INT DEFAULT 0,
            rating INT DEFAULT 1000,
            is_cpu BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            title_id INT,
            FOREIGN KEY (title_id)
                REFERENCES titles(title_id)
        );
    ");
    echo "6. users OK\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS alarms (
            alarm_id SERIAL PRIMARY KEY,
            user_id INT NOT NULL,
            wake_time TIME NOT NULL,
            is_active BOOLEAN DEFAULT TRUE,
            FOREIGN KEY (user_id)
                REFERENCES users(user_id)
                ON DELETE CASCADE
        );
    ");
    echo "7. alarms OK\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            notification_id SERIAL PRIMARY KEY,
            user_id INT NOT NULL,
            message TEXT NOT NULL,
            is_read BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id)
                REFERENCES users(user_id)
                ON DELETE CASCADE
        );
    ");
    echo "8. notifications OK\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS matches (
            match_id SERIAL PRIMARY KEY,
            player1_id INT NOT NULL,
            player2_id INT NOT NULL,
            match_date DATE NOT NULL,
            status VARCHAR(20) DEFAULT 'waiting',
            is_cpu_match BOOLEAN DEFAULT FALSE,

            FOREIGN KEY (player1_id)
                REFERENCES users(user_id),

            FOREIGN KEY (player2_id)
                REFERENCES users(user_id)
        );
    ");
    echo "9. matches OK\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS battles (
            battle_id SERIAL PRIMARY KEY,
            match_id INT NOT NULL,
            winner_id INT,
            player1_tap TIMESTAMP,
            player2_tap TIMESTAMP,
            cpu_wake_time TIMESTAMP,
            battle_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (match_id)
                REFERENCES matches(match_id)
                ON DELETE CASCADE,

            FOREIGN KEY (winner_id)
                REFERENCES users(user_id)
        );
    ");
    echo "10. battles OK\n";

    $pdo->commit();

    // 称号データを挿入
    $pdo->exec("SET search_path TO suggest_plan");
    $titles = [
        [1, '新人', 0],
        [2, '朝型戦士', 5],
        [3, '朝型マスター', 10],
        [4, '朝型王', 20],
        [5, '朝型伝説', 50]
    ];
    
    foreach ($titles as $title) {
        $stmt = $pdo->prepare("INSERT INTO titles (title_id, title_name, required_wins) VALUES (?, ?, ?)");
        if (!$stmt->execute($title)) {
            $error_info = $stmt->errorInfo();
            if (strpos($error_info[2], 'duplicate') === false && strpos($error_info[2], 'unique') === false) {
                throw new Exception('称号データの挿入に失敗しました: ' . implode(', ', $error_info));
            }
        }
    }

    // ランクデータを挿入
    $ranks = [
        [1, 'ブロンズ', 0, '🥉'],
        [2, 'シルバー', 1000, '🥈'],
        [3, 'ゴールド', 1200, '🥇'],
        [4, 'プラチナ', 1400, '💎'],
        [5, 'ダイヤモンド', 1600, '💠'],
        [6, 'マスター', 1800, '👑'],
        [7, 'レジェンド', 2000, '🔥']
    ];
    foreach ($ranks as $rank) {
        $stmt = $pdo->prepare("INSERT INTO ranks (rank_id, rank_name, min_rating, rank_icon) VALUES (?, ?, ?, ?)");
        $stmt->execute($rank);
    }

    // CPUユーザーを作成
    $cpu_password = password_hash('cpu_internal_account', PASSWORD_BCRYPT);
    $pdo->prepare("INSERT INTO users (username, password, display_name, is_cpu, title_id, rating) VALUES (?, ?, ?, true, 1, 1000)")
        ->execute(['__cpu_player__', $cpu_password, '寝坊助CPU']);

    echo "\nDB・テーブル作成完了（ランク・CPU含む）\n";

} catch (Throwable $e) {

    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo "\nエラー発生\n";
    echo $e->getMessage() . "\n";
    if (method_exists($e, 'getCode')) {
        echo "エラーコード: " . $e->getCode() . "\n";
    }
}