
<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
require_once('/var/www/config.php');

function countryCodeToEmoji($code) {
    if (!$code || $code === '??' || $code === 'LOCAL') return '🏴‍☠️';
    $chars = str_split(strtoupper($code));
    $flag = '';
    foreach ($chars as $c) {
        if (ord($c) < 65 || ord($c) > 90) return '🌍';
        $flag .= mb_chr(ord($c) + 127397, 'UTF-8');
    }
    return $flag;
}

function countryCodeToJapanese($code) {
    $map = [
        'JP'=>'日本','US'=>'アメリカ','CN'=>'中国','KR'=>'韓国','RU'=>'ロシア',
        'IN'=>'インド','DE'=>'ドイツ','GB'=>'イギリス','FR'=>'フランス','BR'=>'ブラジル',
        'VN'=>'ベトナム','SG'=>'シンガポール','HK'=>'香港','TW'=>'台湾','NL'=>'オランダ',
        'CA'=>'カナダ','AU'=>'オーストラリア','IT'=>'イタリア','ES'=>'スペイン','MX'=>'メキシコ',
        'ID'=>'インドネシア','TH'=>'タイ','MY'=>'マレーシア','PH'=>'フィリピン','PK'=>'パキスタン',
        'BD'=>'バングラデシュ','TR'=>'トルコ','SA'=>'サウジアラビア','AE'=>'アラブ首長国連邦','IL'=>'イスラエル',
        'PL'=>'ポーランド','UA'=>'ウクライナ','SE'=>'スウェーデン','NO'=>'ノルウェー','FI'=>'フィンランド',
        'DK'=>'デンマーク','CH'=>'スイス','AT'=>'オーストリア','BE'=>'ベルギー','PT'=>'ポルトガル',
        'GR'=>'ギリシャ','CZ'=>'チェコ','RO'=>'ルーマニア','HU'=>'ハンガリー','IE'=>'アイルランド',
        'NZ'=>'ニュージーランド','ZA'=>'南アフリカ','EG'=>'エジプト','NG'=>'ナイジェリア','AR'=>'アルゼンチン',
        'CL'=>'チリ','CO'=>'コロンビア','PE'=>'ペルー','AD'=>'アンドラ','IS'=>'アイスランド',
        'LU'=>'ルクセンブルク','MC'=>'モナコ','MT'=>'マルタ','CY'=>'キプロス','EE'=>'エストニア',
        'LV'=>'ラトビア','LT'=>'リトアニア','SK'=>'スロバキア','SI'=>'スロベニア','HR'=>'クロアチア',
        'RS'=>'セルビア','BG'=>'ブルガリア','MD'=>'モルドバ','BY'=>'ベラルーシ','KZ'=>'カザフスタン',
        'UZ'=>'ウズベキスタン','MN'=>'モンゴル','LK'=>'スリランカ','NP'=>'ネパール','MM'=>'ミャンマー',
        'KH'=>'カンボジア','LA'=>'ラオス','QA'=>'カタール','KW'=>'クウェート','JO'=>'ヨルダン',
        'LB'=>'レバノン','IQ'=>'イラク','IR'=>'イラン','MA'=>'モロッコ','TN'=>'チュニジア',
        'DZ'=>'アルジェリア','KE'=>'ケニア','GH'=>'ガーナ','ET'=>'エチオピア','VE'=>'ベネズエラ',
        'EC'=>'エクアドル','UY'=>'ウルグアイ','PY'=>'パラグアイ','BO'=>'ボリビア','CR'=>'コスタリカ',
        'PA'=>'パナマ','CU'=>'キューバ','DO'=>'ドミニカ共和国','GT'=>'グアテマラ','HN'=>'ホンジュラス',
        'LY'=>'リビア','LOCAL'=>'社内ネットワーク','??'=>'不明'
    ];
    return $map[$code] ?? $code;
}

function organizationWithNote($org) {
    if (!$org) return '不明';
    $notes = [
        'Amazon'=>'米国系クラウド','AWS'=>'米国系クラウド','Google'=>'米国系クラウド',
        'Microsoft'=>'米国系クラウド','Azure'=>'米国系クラウド','Alibaba'=>'中国系クラウド',
        'Tencent'=>'中国系クラウド','Huawei'=>'中国系クラウド','DigitalOcean'=>'米国系VPS',
        'OVH'=>'欧州系VPS','Hetzner'=>'欧州系VPS','Linode'=>'米国系VPS','Vultr'=>'米国系VPS',
        'Glbb'=>'国内ISP','NTT'=>'国内ISP','KDDI'=>'国内ISP','SoftBank'=>'国内ISP','Sakura'=>'国内ISP',
        'Cloudflare'=>'米国系CDN','Oracle'=>'米国系クラウド','IBM'=>'米国系クラウド',
    ];
    foreach ($notes as $keyword => $note) {
        if (stripos($org, $keyword) !== false) {
            return htmlspecialchars($org) . '（' . $note . '）';
        }
    }
    return htmlspecialchars($org);
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { die("接続失敗: " . $conn->connect_error); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ack_success'])) {
    $conn->query("UPDATE dashboard_settings SET setting_value = NOW() WHERE setting_key='success_ack_time'");
    header('Location: dashboard.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ack_web_success'])) {
    $conn->query("UPDATE dashboard_settings SET setting_value = NOW() WHERE setting_key='web_success_ack_time'");
    header('Location: dashboard.php');
    exit;
}
$ack_row = $conn->query("SELECT setting_value FROM dashboard_settings WHERE setting_key='success_ack_time'")->fetch_assoc();
$success_ack_time = $ack_row ? $ack_row['setting_value'] : '2000-01-01 00:00:00';

$total = $conn->query("SELECT COUNT(*) AS cnt FROM ssh_attack_logs")->fetch_assoc()['cnt'];
$start_date = $conn->query("SELECT MIN(event_time) AS start_date FROM ssh_attack_logs")->fetch_assoc()["start_date"];
$success = $conn->query("SELECT COUNT(*) AS cnt FROM ssh_attack_logs WHERE status='SUCCESS' AND event_time > '" . $conn->real_escape_string($success_ack_time) . "'")->fetch_assoc()['cnt'];
$web_ack_row = $conn->query("SELECT setting_value FROM dashboard_settings WHERE setting_key='web_success_ack_time'")->fetch_assoc();
$web_success_ack_time = $web_ack_row ? $web_ack_row['setting_value'] : '2000-01-01 00:00:00';
$web_success = $conn->query("SELECT COUNT(*) AS cnt FROM login_history WHERE login_status='SUCCESS' AND login_time > '" . $conn->real_escape_string($web_success_ack_time) . "'")->fetch_assoc()['cnt'];
$failed = $conn->query("SELECT COUNT(*) AS cnt FROM ssh_attack_logs WHERE status='FAILED'")->fetch_assoc()['cnt'];
$fail2ban_status = trim(shell_exec('systemctl is-active fail2ban 2>&1'));
function getFail2banBannedCount($jail) {
    $output = shell_exec("sudo /usr/bin/fail2ban-client status " . escapeshellarg($jail) . " 2>&1");
    if (preg_match('/Total banned:\s*(\d+)/', $output, $m)) {
        return (int)$m[1];
    }
    return null;
}
$ssh_banned_total = getFail2banBannedCount("sshd");
$web_banned_total = getFail2banBannedCount("apache-scan");
$httpd_status = trim(shell_exec("systemctl is-active httpd 2>&1"));
$db_status = ($conn->connect_error) ? "stopped" : "active";

$logs = $conn->query("SELECT * FROM ssh_attack_logs ORDER BY id DESC LIMIT 50");
$web_logs = $conn->query("SELECT * FROM web_attack_logs ORDER BY id DESC LIMIT 50");
$web_total = $conn->query("SELECT COUNT(*) AS cnt FROM web_attack_logs")->fetch_assoc()["cnt"];
$login_logs = $conn->query("SELECT * FROM login_history ORDER BY id DESC LIMIT 10");

$user_ranking = $conn->query("SELECT username, COUNT(*) as cnt FROM ssh_attack_logs WHERE event_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY username ORDER BY cnt DESC LIMIT 10");
$ranking_labels = [];
$ranking_data = [];
while ($row = $user_ranking->fetch_assoc()) {
    $ranking_labels[] = $row['username'];
    $ranking_data[] = $row['cnt'];
}

$country_ranking = $conn->query("SELECT country_code, SUM(cnt) as cnt FROM (SELECT country_code, COUNT(*) as cnt FROM ssh_attack_logs WHERE country_code IS NOT NULL AND country_code != '' AND event_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY country_code UNION ALL SELECT country_code, COUNT(*) as cnt FROM web_attack_logs WHERE country_code IS NOT NULL AND country_code != '' AND event_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY country_code) as combined GROUP BY country_code ORDER BY cnt DESC LIMIT 10");
$country_labels = [];
$country_data = [];
while ($row = $country_ranking->fetch_assoc()) {
    $country_labels[] = countryCodeToJapanese($row['country_code']);
    $country_data[] = $row['cnt'];
}

$daily_trend = $conn->query("SELECT day, SUM(cnt) as cnt FROM (SELECT DATE(event_time) as day, COUNT(*) as cnt FROM ssh_attack_logs GROUP BY DATE(event_time) UNION ALL SELECT DATE(event_time) as day, COUNT(*) as cnt FROM web_attack_logs GROUP BY DATE(event_time)) as combined GROUP BY day ORDER BY day ASC LIMIT 30");
$trend_labels = [];
$trend_data = [];
while ($row = $daily_trend->fetch_assoc()) {
    $trend_labels[] = $row['day'];
    $trend_data[] = $row['cnt'];
}

$hourly = $conn->query("SELECT hr, SUM(cnt) as cnt FROM (SELECT HOUR(event_time) as hr, COUNT(*) as cnt FROM ssh_attack_logs WHERE DATE(event_time) = CURDATE() GROUP BY HOUR(event_time) UNION ALL SELECT HOUR(event_time) as hr, COUNT(*) as cnt FROM web_attack_logs WHERE DATE(event_time) = CURDATE() GROUP BY HOUR(event_time)) as combined GROUP BY hr ORDER BY hr ASC");
$hour_map = array_fill(0, 24, 0);
while ($row = $hourly->fetch_assoc()) {
    $hour_map[(int)$row['hr']] = (int)$row['cnt'];
}
$hourly_labels = [];
$hourly_data = [];
for ($i = 0; $i < 24; $i++) {
    $hourly_labels[] = sprintf('%02d:00', $i);
    $hourly_data[] = $hour_map[$i];
}
?><!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>セキュリティ監視ダッシュボード</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#ff5555">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
    <style>
        body { background-color: #1a1a1a; color: #eee; font-family: sans-serif; padding: 20px; }
        h1 { color: #ff5555; margin-bottom: 20px; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
        .top-bar-buttons { display: flex; flex-wrap: wrap; gap: 10px; }
        .top-bar-buttons a { color: #ffcc00; text-decoration: none; border: 1px solid #ffcc00; padding: 5px 15px; border-radius: 5px; }
        .alert-card { text-align: center; padding: 30px; border-radius: 10px; margin-bottom: 20px; }
        .alert-safe { background: #1a3a1a; border: 2px solid #55ff55; }
        .alert-safe h2 { color: #55ff55; font-size: 2.5em; margin: 0; }
        .alert-safe p { color: #88cc88; font-size: 1.2em; }
        .alert-danger { background: #3a1a1a; border: 2px solid #ff5555; }
        .alert-danger h2 { color: #ff5555; font-size: 2.5em; margin: 0; }
        .alert-danger p { color: #cc8888; font-size: 1.2em; }
        .sub-cards { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 20px; }
        .sub-card { background: #2a2a2a; padding: 15px; border-radius: 8px; flex: 1; text-align: center; }
        .sub-card h3 { margin: 0; font-size: 1.8em; color: #ffcc00; }
        .sub-card p { margin: 5px 0 0; color: #aaa; }
        table { width: 100%; border-collapse: collapse; background: #2a2a2a; margin-bottom: 20px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #444; word-break: break-word; }
        th { background: #333; color: #ff5555; }
        .SUCCESS { color: #ff5555; font-weight: bold; }
        .FAILED { color: #ffcc00; }
        .charts-row { display: flex; gap: 20px; margin-bottom: 20px; flex-wrap: wrap; }
        .chart-box { background: #2a2a2a; padding: 20px; border-radius: 8px; flex: 1; min-width: 300px; height: 350px; position: relative; }
        .chart-box canvas { max-height: 300px !important; }
        .chart-wide { background: #2a2a2a; padding: 20px; border-radius: 8px; margin-bottom: 20px; height: 350px; position: relative; }
        .chart-wide canvas { max-height: 300px !important; }
        .accordion { background: #2a2a2a; border-radius: 8px; margin-bottom: 15px; overflow: hidden; }
        .accordion-header { padding: 15px 20px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; background: #333; user-select: none; }
        .accordion-header:hover { background: #3a3a3a; }
        .accordion-header .arrow { transition: transform 0.2s; }
        .accordion.open .arrow { transform: rotate(90deg); }
        .accordion-body { max-height: 0; overflow-y: hidden; overflow-x: auto; transition: max-height 0.3s ease; }
        .accordion.open .accordion-body { max-height: 3000px; }
        .accordion-body table { margin-bottom: 0; border-radius: 0; }
    </style>
</head>
<body>
    <div class="top-bar">
        <h1>🛡️ セキュリティ監視ダッシュボード</h1>
        <div class="top-bar-buttons">
            <a href="export_csv.php">📥 SSH攻撃ログCSV</a>
            <a href="export_web_csv.php">📥 Web攻撃ログCSV</a>
            <a href="logout.php">ログアウト</a>
        </div>
    </div>
<?php
    $last_login = $conn->query("SELECT login_time, ip_address FROM login_history WHERE login_status='SUCCESS' ORDER BY id DESC LIMIT 1 OFFSET 1");
    $last = $last_login->fetch_assoc();
    if ($last): ?>
    <p style="color:#aaa; text-align:right; margin-bottom:10px;">前回のログイン：<?php echo htmlspecialchars($last['login_time']); ?>（IP: <?php echo htmlspecialchars($last['ip_address']); ?>）</p>
    <?php endif; ?>
    <?php if ($success > 0): ?>
    <div class="alert-card alert-danger">
        <h2>🚨 <?php echo $success; ?>件の侵入成功を検知</h2>
        <p>SSHへの不正ログイン成功を検知。直ちにログを確認してください</p>
        <form method="post" style="margin-top:10px;">
            <button type="submit" name="ack_success" style="padding:8px 16px; background:#fff; color:#a00; border:none; border-radius:4px; font-weight:bold; cursor:pointer;">確認済みにする（リセット）</button>
        </form>
    </div>
    <?php else: ?>
    <div class="alert-card alert-safe">
        <h2>✅ 侵入なし</h2>
        <p>現時点で不正ログインの成功は検知されていません</p>
    </div>
    <?php endif; ?>

    <?php if ($web_success > 0): ?>
    <div class="alert-card alert-danger">
        <h2>🚨 <?php echo $web_success; ?>件のダッシュボード不正ログインを検知</h2>
        <p>ダッシュボードへの不審なログイン成功を検知。直ちにログイン履歴を確認してください</p>
        <form method="post" style="margin-top:10px;">
            <button type="submit" name="ack_web_success" style="padding:8px 16px; background:#fff; color:#a00; border:none; border-radius:4px; font-weight:bold; cursor:pointer;">確認済みにする（リセット）</button>
        </form>
    </div>
    <?php endif; ?>
    <p style="color:#aaa; margin-bottom:10px;"><?php echo date("Y-m-d", strtotime($start_date)); ?>から集計中</p>
    <div class="sub-cards">
        <p style="flex-basis:100%; margin:0 0 5px 0; color:#8ab4f8; font-weight:bold;">🔒 SSH監視</p>
        <div class="sub-card"><h3><?php echo $total; ?></h3><p>総攻撃件数（累計）</p><p style="color:#888; font-size:0.8em; margin-top:4px;">うち防御成功(ログイン失敗)<?php echo $failed; ?>件</p></div>
        <div class="sub-card"><h3><?php echo $ssh_banned_total !== null ? $ssh_banned_total : '-'; ?></h3><p>自動ブロック件数（累計）</p></div>
        <p style="flex-basis:100%; margin:-10px 0 5px 0; color:#888; font-size:0.75em;">※fail2ban起動時からの累計(データベースの集計期間とは基準が異なります)。同じIPから10分以内に5回検知されると1時間自動ブロックされます。</p>
        <p style="flex-basis:100%; margin:15px 0 5px 0; color:#f4a261; font-weight:bold;">🌐 Web監視</p>
        <div class="sub-card"><h3><?php echo $web_total; ?></h3><p>Web不審アクセス件数（累計）</p></div>
        <div class="sub-card"><h3><?php echo $web_banned_total !== null ? $web_banned_total : '-'; ?></h3><p>自動ブロック件数（累計）</p></div>
        <p style="flex-basis:100%; margin:-10px 0 5px 0; color:#888; font-size:0.75em;">※fail2ban起動時からの累計(データベースの集計期間とは基準が異なります)。同じIPから10分以内に5回検知されると1時間自動ブロックされます。</p>
    </div>
    </div>

    <div class="chart-wide">
        <canvas id="hourlyChart"></canvas>
    </div>

    <div class="charts-row">
        <div class="chart-box">
            <canvas id="countryChart"></canvas>
        </div>
        <div class="chart-box">
            <canvas id="rankingChart"></canvas>
        </div>
    </div>

    <div class="accordion">
        <div class="accordion-header" onclick="this.parentElement.classList.toggle('open')">
            <span>📈 日別 攻撃件数の推移（SSH+Web合算）</span>
            <span class="arrow">▶</span>
        </div>
        <div class="accordion-body">
            <div style="padding:20px; height:300px; position:relative;">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
    </div>

    <div class="accordion">
        <div class="accordion-header" onclick="this.parentElement.classList.toggle('open')">
            <span>📋 SSH監視ログ（直近50件）</span>
            <span class="arrow">▶</span>
        </div>
        <div class="accordion-body">
            <table>
                <tr>
                    <th>日時</th><th>状態</th><th>ユーザー</th><th>IPアドレス</th><th>国</th><th>組織</th>
                </tr>
                <?php while ($row = $logs->fetch_assoc()):
                    $isSuccess = ($row['status'] === 'SUCCESS');
                    $displayUser = $isSuccess
                        ? mb_substr($row['username'], 0, 1) . str_repeat('*', max(mb_strlen($row['username']) - 1, 3))
                        : htmlspecialchars($row['username']);
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['event_time']); ?></td>
                    <td class="<?php echo $row['status']; ?>"><?php echo $row['status']; ?></td>
                    <td><?php echo $displayUser; ?></td>
                    <td><?php echo htmlspecialchars($row['ip_address']); ?></td>
                    <td><?php echo countryCodeToEmoji($row['country_code']); ?> <?php echo countryCodeToJapanese($row['country_code']); ?></td>
                    <td><?php echo organizationWithNote($row['organization']); ?></td>
                </tr>
                <?php endwhile; ?>
            </table>
        </div>
    </div>
    <div class="accordion">
        <div class="accordion-header" onclick="this.parentElement.classList.toggle('open')">
            <span>🌐 Web攻撃ログ（直近50件）</span>
            <span class="arrow">▶</span>
        </div>
        <div class="accordion-body">
            <table>
                <tr>
                    <th>日時</th><th>IPアドレス</th><th>メソッド</th><th>パス</th><th>ステータス</th><th>国</th><th>組織</th>
                </tr>
                <?php while ($row = $web_logs->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['event_time']); ?></td>
                    <td><?php echo htmlspecialchars($row['ip_address']); ?></td>
                    <td><?php echo htmlspecialchars($row['method']); ?></td>
                    <td><?php echo htmlspecialchars($row['path']); ?></td>
                    <td><?php echo htmlspecialchars($row['status_code']); ?></td>
                    <td><?php echo countryCodeToEmoji($row['country_code'] ?? ''); ?> <?php echo countryCodeToJapanese($row['country_code'] ?? ''); ?></td>
                    <td><?php echo organizationWithNote($row['organization'] ?? ''); ?></td>
                </tr>
                <?php endwhile; ?>
            </table>
        </div>
</div>
<div class="accordion">
        <div class="accordion-header" onclick="this.parentElement.classList.toggle('open')">
            <span>🔐 ダッシュボードへのログイン履歴（直近10件）</span>
            <span class="arrow">▶</span>
        </div>
        <div class="accordion-body">
            <table>
                <tr>
                    <th>日時</th><th>状態</th><th>ユーザー</th><th>IPアドレス</th><th>国</th><th>組織</th>
                </tr>
                <?php while ($row = $login_logs->fetch_assoc()):
    $isSuccess = ($row['login_status'] === 'SUCCESS');
    $statusLabel = $isSuccess ? 'success' : 'fail';
    $displayUser = $isSuccess
        ? mb_substr($row['username'], 0, 1) . str_repeat('*', max(mb_strlen($row['username']) - 1, 3))
        : htmlspecialchars($row['username']);
?>
                <tr>
                    <td><?php echo htmlspecialchars($row['login_time']); ?></td>
                    <td class="<?php echo $isSuccess ? 'SUCCESS' : 'FAILED'; ?>"><?php echo $statusLabel; ?></td>
                    <td><?php echo $displayUser; ?></td>
                    <td><?php echo htmlspecialchars($row['ip_address']); ?></td>
                    <td><?php echo countryCodeToEmoji($row['country_code'] ?? ''); ?> <?php echo countryCodeToJapanese($row['country_code'] ?? ''); ?></td>
                    <td><?php echo organizationWithNote($row['organization'] ?? ''); ?></td>
                </tr>
                <?php endwhile; ?>
            </table>
        </div>
    </div>

    <div class="accordion">
        <div class="accordion-header" onclick="this.parentElement.classList.toggle('open')">
            <span>📝 更新履歴</span>
            <span class="arrow">▶</span>
        </div>
        <div class="accordion-body">
            <table>
                <tr><th>日付</th><th>内容</th></tr>
                <tr><td>2026-07-30</td><td>Web攻撃ログに国・組織情報を追加（DB・Python・PHPを・手して対応）</td></tr>
                <tr><td>2026-07-30</td><td>国別ランキング・時間別・日別のグラフをSSH+Web合算に変更</td></tr>
                <tr><td>2026-07-30</td><td>fail2banの実際の自動ブロック件数をダッシュボードに表示</td></tr>
                <tr><td>2026-07-30</td><td>上部の集計をSSH監視・Web監視のセクションに整理</td></tr>
                <tr><td>2026-07-30</td><td>重複して記録されていたログをデータベースから削除</td></tr>
                <tr><td>2026-07-30</td><td>監視スクリプトの誤検知バグ（"Accepted"文字列の誤マッチ）を発見・修正</td></tr>
                <tr><td>2026-07-30</td><td>侵入検知アラートに「確認済みにする（リセット）」ボタンを追加</td></tr>
                <tr><td>2026-07-29</td><td>Discord通知のタイトルに日本語を併記し、分かりやすく改善</td></tr>
                <tr><td>2026-07-29</td><td>Web攻撃通知にダッシュボードへのリンクを追加</td></tr>
                <tr><td>2026-07-29</td><td>テスト用データを削除し、システム状態パネルを実際の監視項目のみに整理</td></tr>
                <tr><td>2026-07-29</td><td>fail2banにApache用ルールを追加し、Webへの不審アクセスを自動ブロック</td></tr>
                <tr><td>2026-07-29</td><td>sudo通知に推奨アクション（who確認・セッション切断・パスワード変更）を追加</td></tr>
                <tr><td>2026-07-29</td><td>Web攻撃ログのCSVダウンロードに対応</td></tr>
                <tr><td>2026-07-29</td><td>CSVダウンロードのボタン名をSSH/Webで統一</td></tr>
                <tr><td>2026-07-29</td><td>Apacheアクセスログ監視を追加し、不審なWebアクセスをDiscord通知</td></tr>
                <tr><td>2026-07-29</td><td>sudoコマンドの使用・認証失敗を監視し、権限昇格の兆候をDiscord通知</td></tr>
                <tr><td>2026-07-29</td><td>CSVエクスポートの文字化けを修正</td></tr>
                <tr><td>2026-07-29</td><td>パネル表示順を整理し、Web攻撃件数の集計カードを追加</td></tr>
                <tr><td>2026-07-29</td><td>侵入検知の判定をユーザー名基準からIPアドレス基準に変更</td></tr>
                <tr><td>2026-07-29</td><td>攻撃元国別・狙われたユーザー名ランキングを直近7日間に変更</td></tr>
                <tr><td>2026-07-29</td><td>国コード「LY（リビア）」の表示に対応</td></tr>
                <tr><td>2026-07-29</td><td>ログイン成功時のユーザー名マスク表示に対応（SSH監視ログ・ログイン履歴の両方）</td></tr>
                <tr><td>2026-07-29</td><td>7日より古い攻撃ログを自動整理（手動実行）</td></tr>
                <tr><td>2026-07-28</td><td>fail2banを導入し、ブルートフォース攻撃を自動ブロック</td></tr>
                <tr><td>2026-07-28</td><td>Discord Bot通知に重大度・推奨アクション・リンク・タイムスタンプを追加</td></tr>
            </table>
        </div>
    </div>
    <div class="accordion">
        <div class="accordion-header" onclick="this.parentElement.classList.toggle('open')">
            <span>🖥️ システム状態</span>
            <span class="arrow">▶</span>
        </div>
        <div class="accordion-body">
            <table>
                <tr><th>項目</th><th>状態</th></tr>
                <tr><td>fail2ban（自動防御）</td><td style="color:<?php echo ($fail2ban_status === 'active') ? '#55ff55' : '#ff5555'; ?>;"><?php echo ($fail2ban_status === 'active') ? '稼働中' : '停止中'; ?></td></tr>
            </table>
        </div>
    </div>
    <script>
        const ctxHourly = document.getElementById('hourlyChart');
        new Chart(ctxHourly, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($hourly_labels); ?>,
                datasets: [{
                    label: '本日の攻撃件数',
                    data: <?php echo json_encode($hourly_data); ?>,
                    backgroundColor: 'rgba(255,204,0,0.7)',
                    borderColor: '#ffcc00',
                    borderWidth: 1
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { labels: { color: '#eee' } },
                    title: { display: true, text: '本日の時間別 攻撃件数（SSH+Web合算）', color: '#eee', font: { size: 16 } }
                },
                scales: {
                    x: { ticks: { color: '#eee' }, grid: { color: '#444' } },
                    y: { ticks: { color: '#eee' }, grid: { color: '#444' }, beginAtZero: true }
                }
            }
        });

        const ctxCountry = document.getElementById('countryChart');
        new Chart(ctxCountry, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($country_labels); ?>,
                datasets: [{
                    label: '攻撃件数',
                    data: <?php echo json_encode($country_data); ?>,
                    backgroundColor: '#ffcc00'
                }]
            },
            options: {
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                    title: { display: true, text: '攻撃元 国別ランキング（SSH+Web合算・直近7日間）', color: '#eee', font: { size: 16 } }
                },
                scales: {
                    x: { ticks: { color: '#eee' }, grid: { color: '#444' } },
                    y: { ticks: { color: '#eee' }, grid: { color: '#444' } }
                }
            }
        });

        const ctxRanking = document.getElementById('rankingChart');
        new Chart(ctxRanking, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($ranking_labels); ?>,
                datasets: [{
                    label: '試行回数',
                    data: <?php echo json_encode($ranking_data); ?>,
                    backgroundColor: '#ff5555'
                }]
            },
            options: {
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                    title: { display: true, text: '狙われたユーザー名 TOP10（SSHのみ・直近7日間）', color: '#eee', font: { size: 16 } }
                },
                scales: {
                    x: { ticks: { color: '#eee' }, grid: { color: '#444' } },
                    y: { ticks: { color: '#eee' }, grid: { color: '#444' } }
                }
            }
        });

        const ctxTrend = document.getElementById('trendChart');
        new Chart(ctxTrend, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($trend_labels); ?>,
                datasets: [{
                    label: '日別 攻撃件数',
                    data: <?php echo json_encode($trend_data); ?>,
                    borderColor: '#ff5555',
                    backgroundColor: 'rgba(255,85,85,0.2)',
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { labels: { color: '#eee' } },
                    title: { display: true, text: '日別 攻撃件数の推移（SSH+Web合算）', color: '#eee', font: { size: 16 } }
                },
                scales: {
                    x: { ticks: { color: '#eee' }, grid: { color: '#444' } },
                    y: { ticks: { color: '#eee' }, grid: { color: '#444' }, beginAtZero: true }
                }
            }
        });
    </script>
</body>
</html>

