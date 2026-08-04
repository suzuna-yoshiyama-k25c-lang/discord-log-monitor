<?php
session_start();
require_once('/var/www/config.php');

use OTPHP\TOTP;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

$result = $conn->query("SELECT setting_value FROM dashboard_settings WHERE setting_key = '2fa_secret'");
$existing = $result->fetch_assoc();

if ($existing) {
    $secret = $existing['setting_value'];
} else {
    $totp = TOTP::create();
    $secret = $totp->getSecret();

    $stmt = $conn->prepare("INSERT INTO dashboard_settings (setting_key, setting_value) VALUES ('2fa_secret', ?)");
    $stmt->bind_param("s", $secret);
    $stmt->execute();
    $stmt->close();
}

$totp = TOTP::createFromSecret($secret);
$totp->setLabel('SecurityMonitor');
$totp->setIssuer('MonitorApp');
$qrUrl = $totp->getProvisioningUri();

$qrCode = new QrCode($qrUrl);
$writer = new PngWriter();
$result = $writer->write($qrCode);
$qrDataUri = $result->getDataUri();

$conn->close();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>2段階認証セットアップ</title>
    <style>
        body { background-color: #1a1a1a; color: #eee; font-family: sans-serif; 
               display: flex; flex-direction: column; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .box { background: #2a2a2a; padding: 30px; border-radius: 10px; text-align: center; max-width: 400px; }
        h1 { color: #ff5555; font-size: 1.3em; }
        .secret { background: #1a1a1a; padding: 10px; border-radius: 5px; word-break: break-all; margin: 15px 0; color: #ffcc00; }
        p { line-height: 1.6; }
        a { color: #ff5555; }
    </style>
</head>
<body>
    <div class="box">
        <h1>🔐 2段階認証の設定</h1>
        <p>Google Authenticatorアプリでこの QRコードを読み取ってください。</p>
        <img src="<?php echo $qrDataUri; ?>" alt="QRコード">
        <p>読み取れない場合は、以下のコードを手動で入力してください。</p>
        <div class="secret"><?php echo $secret; ?></div>
        <p><a href="dashboard.php">← ダッシュボードに戻る</a></p>
    </div>
</body>
</html>
