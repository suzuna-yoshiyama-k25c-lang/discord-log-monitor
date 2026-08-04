<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
require_once('/var/www/config.php');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { die("接続失敗"); }

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="ssh_attack_logs_' . date('Ymd_His') . '.csv"');

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF"); // Excelで文字化けしないようにBOMを付与
fputcsv($output, ['日時', '状態', 'ユーザー', 'IPアドレス', '国コード', '組織']);

$result = $conn->query("SELECT * FROM ssh_attack_logs ORDER BY id DESC LIMIT 500");
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['event_time'],
        $row['status'],
        $row['username'],
        $row['ip_address'],
        $row['country_code'],
        $row['organization']
    ]);
}
fclose($output);
$conn->close();
