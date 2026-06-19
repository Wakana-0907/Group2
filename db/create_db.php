<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/connect_db.php';

header('Content-Type: text/plain; charset=utf-8');

try {

    echo "1. DB接続OK\n";

    $pdo->exec("CREATE SCHEMA IF NOT EXISTS suggest_plan");
    echo "2. CREATE SCHEMA OK\n";

    $pdo->exec("SET search_path TO suggest_plan");
    echo "3. SET SEARCH_PATH OK\n";

    $pdo->beginTransaction();
    // 既存テーブル削除（開発用）
    $pdo->exec("DROP TABLE IF EXISTS battles CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS matches CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS notifications CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS alarms CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS users CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS titles CASCADE");



    $pdo->exec("
        CREATE TABLE IF NOT EXISTS titles (
            title_id SERIAL PRIMARY KEY,
            title_name VARCHAR(50) NOT NULL,
            required_wins INT NOT NULL
        );
    ");
    echo "4. titles OK\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            user_id SERIAL PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            display_name VARCHAR(50) NOT NULL,
            icon_image VARCHAR(255),
            win_count INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            title_id INT,
            FOREIGN KEY (title_id)
                REFERENCES titles(title_id)
        );
    ");
    echo "5. users OK\n";

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
    echo "6. alarms OK\n";

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
    echo "7. notifications OK\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS matches (
            match_id SERIAL PRIMARY KEY,
            player1_id INT NOT NULL,
            player2_id INT NOT NULL,
            match_date DATE NOT NULL,
            status VARCHAR(20) DEFAULT 'waiting',

            FOREIGN KEY (player1_id)
                REFERENCES users(user_id),

            FOREIGN KEY (player2_id)
                REFERENCES users(user_id)
        );
    ");
    echo "8. matches OK\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS battles (
            battle_id SERIAL PRIMARY KEY,
            match_id INT NOT NULL,
            winner_id INT,
            player1_tap TIMESTAMP,
            player2_tap TIMESTAMP,
            battle_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (match_id)
                REFERENCES matches(match_id)
                ON DELETE CASCADE,

            FOREIGN KEY (winner_id)
                REFERENCES users(user_id)
        );
    ");
    echo "9. battles OK\n";

    $pdo->commit();

    echo "\nDB・テーブル作成完了";

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo "\nエラー発生\n";
    echo $e->getMessage();
}