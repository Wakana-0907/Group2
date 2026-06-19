<?php
declare(strict_types=1);

$host = 'localhost';
$port = '5432';
$dbname = 'yf002023';
$user = 'yf002023';
$pass = 'eGnH4e4X';

$dsn = "pgsql:host={$host};port={$port};dbname={$dbname};options='--client_encoding=UTF8'";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    //$pdo->exec("SET search_path TO suggest_plan");

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "DB CONNECT ERROR\n";
    echo $e->getMessage();
    exit;
}


