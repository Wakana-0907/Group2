<?php
// 接続点①：購読情報を受け取るAPI

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data || !isset($data['endpoint']) || !isset($data['keys']['p256dh']) || !isset($data['keys']['auth'])) {
    http_response_code(400);
    echo json_encode(['error' => '必要なデータが不足しています。']);
    exit;
}

$userId   = $data['user_id'] ?? null;
$endpoint = $data['endpoint'];
$p256dh   = $data['keys']['p256dh'];
$auth     = $data['keys']['auth'];

// =================================================================
// 📢 【長谷川さんの実装タスクエリア】
// 村上さん側の作業はここまでで完了しています。
// 長谷川さんがここから下に、DB（push_subscriptionsテーブル）への保存処理を書きます。
// =================================================================


// フロントへの成功レスポンス
http_response_code(200);
echo json_encode([
    'status' => 'success', 
    'message' => '購読情報をPHPサーバーで正常に受け取りました。'
]);