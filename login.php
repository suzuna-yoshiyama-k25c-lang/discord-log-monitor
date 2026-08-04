<?php
session_start();
require_once('/var/www/config.php');

use OTPHP\TOTP;

function getWhoisInfo($ip) {
    if ($ip === 'Unknown' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0 || $ip === '127.0.0.1') {
        return ['LOCAL', 'Local Network'];
    }
    $url = "http://ip-api.com/json/{$ip}?fields=status,countryCode,isp,org";
    $ctx = stream_context_create(['http' => ['timeout' => 2]]);
    $result = @file_get_contents($url, false, $ctx);
    if ($result) {
        $data = json_decode($result, true);
        if (isset($data['status']) && $data['status'] === 'success') {
            $country = $data['countryCode'] ?? '??';
            $org = $data['org'] ?: ($data['isp'] ?? 'Unknown');
            return [$country, $org];
        }
    }
    return ['??', 'Unknown'];
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp_code'])) {
    $otp_code = $_POST['otp_code'] ?? '';

    if (isset($_SESSION['pending_2fa']) && $_SESSION['pending_2fa'] === true) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $result = $conn->query("SELECT setting_value FROM dashboard_settings WHERE setting_key = '2fa_secret'");
        $row = $result->fetch_assoc();
        $conn->close();

        if ($row) {
            $totp = TOTP::createFromSecret($row['setting_value']);
            if ($totp->verify($otp_code)) {
                $_SESSION['logged_in'] = true;
                unset($_SESSION['pending_2fa']);
                header('Location: dashboard.php');
                exit;
            } else {
                $error = '認証コードが正しくありません。';
            }
        }
    } else {
        header('Location: login.php');
        exit;
    }
}
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_user = $_POST['username'] ?? '';
    $input_pass = $_POST['password'] ?? '';

    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $user_ip = $_SERVER['REMOTE_ADDR'];
    list($country, $org) = getWhoisInfo($user_ip);

    if ($input_user === DASHBOARD_USER && password_verify($input_pass, DASHBOARD_PASS_HASH)) {
        $_SESSION['pending_2fa'] = true;

        $stmt = $conn->prepare("INSERT INTO login_history (username, login_status, ip_address, login_time, country_code, organization) VALUES (?, 'SUCCESS', ?, NOW(), ?, ?)");
        $stmt->bind_param("ssss", $input_user, $user_ip, $country, $org);
        $stmt->execute();
        $stmt->close();
    } else {
        $error = 'ユーザー名またはパスワードが間違っています。';
        error_log(date('Y-m-d H:i:s') . " Dashboard login failed from {$user_ip}\n", 3, '/var/log/dashboard/login_fail.log');

        $stmt = $conn->prepare("INSERT INTO login_history (username, login_status, ip_address, login_time, country_code, organization) VALUES (?, 'FAILED', ?, NOW(), ?, ?)");
        $stmt->bind_param("ssss", $input_user, $user_ip, $country, $org);
        $stmt->execute();
        $stmt->close();
    }
    $conn->close();
}

$show_otp_screen = isset($_SESSION['pending_2fa']) && $_SESSION['pending_2fa'] === true;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ログイン - セキュリティ監視ダッシュボード</title>
    <style>
        body { background-color: #1a1a1a; color: #eee; font-family: sans-serif; 
               display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-box { background: #2a2a2a; padding: 40px; border-radius: 10px; width: 300px; }
        h1 { color: #ff5555; font-size: 1.3em; text-align: center; }
        input { width: 100%; padding: 10px; margin: 8px 0; box-sizing: border-box;
                background: #1a1a1a; border: 1px solid #444; color: #eee; border-radius: 5px; }
        button { width: 100%; padding: 10px; background: #ff5555; border: none; 
                  color: white; border-radius: 5px; cursor: pointer; font-weight: bold; }
        button:hover { background: #ff7777; }
        .error { color: #ffcc00; text-align: center; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="login-box">
        <?php if ($show_otp_screen): ?>
            <h1>🔐 認証コード入力</h1>
            <?php if ($error): ?>
                <p class="error"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>
            <form method="POST">
                <input type="text" name="otp_code" placeholder="6桁のコード" maxlength="6" required autofocus>
                <button type="submit">確認</button>
            </form>
        <?php else: ?>
            <h1>🛡️ 監視ダッシュボード</h1>
            <?php if ($error): ?>
                <p class="error"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>
            <form method="POST">
                <input type="text" name="username" placeholder="ユーザー名" required>
                <input type="password" name="password" placeholder="パスワード" required>
                <button type="submit">ログイン</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
