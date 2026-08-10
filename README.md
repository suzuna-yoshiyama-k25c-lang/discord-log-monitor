# 🦊 Discord ログイン監視ツール

## 📌 これは何？
Linuxサーバーへの不正アクセスを検知・通知し、自動でブロックしたうえで、状況をダッシュボードで可視化するセキュリティ監視システムです。SSHの認証ログ（`/var/log/secure`）とWebサーバーのアクセスログを常時監視しています。SSHはログインが成功した場合、誰のアカウントであってもただちに「侵入の可能性」としてDiscordへ通知します。ログインに失敗した場合は、自分の管理者アカウントを狙った失敗のみを通知し、root狙いなど無差別なボット攻撃による失敗は記録のみ行い、通知はしません。Webサーバーへのアクセスでは、404や403などの異常なステータスコードや不審なリクエストを検知し、同一IPアドレスから一定回数以上アクセスがあった場合に通知します。さらに、一定回数以上の不審なアクセスを行ったIPアドレスはfail2banが自動的に一定時間ブロックします。これまでの攻撃状況（攻撃元の国・組織・時間帯の傾向など）は、ログイン認証と2段階認証で保護されたPHP製のダッシュボードでいつでも確認できます。

## 🛠️ 構成
このシステムは以下のような構成で動いています。
- `monitor_log.py`：ログ監視・Discord通知を行うPythonスクリプト。トークンなどの秘密情報は`.env`ファイルで管理し、systemdサービスとして常時自動実行しています。
- `dashboard.php`ほか：ログイン認証と2段階認証で保護された、攻撃状況を可視化するPHP製ダッシュボード。DB接続情報は`config.php`で別管理しています。
- `jail.local`ほか：fail2banの設定ファイル群。SSH・Webアクセス・ダッシュボードログインの3種類を監視し、不審なアクセスを自動でブロックします。

### ⚠️ こだわりポイント（ボット対策）
インターネット上には、SSHのログインを狙って無差別に攻撃を仕掛けてくる自動ボットが多数存在します。ログイン失敗をそのまま全て通知する設定にすると、これらのボットによる攻撃で通知が鳴り止まなくなってしまいます。そのため、ログイン失敗の通知は「自分の管理者アカウントを狙った失敗」のみに絞り込み、無差別なボット攻撃による失敗は記録だけ行い、通知はしないようにカスタマイズしてあります。

## 📸 動作イメージ

<img width="600" alt="discord_ssh_alerts_redacted_1" src="https://github.com/user-attachments/assets/4309148d-2f7b-48d1-87b7-ea406aac172f" />

SSHへのログイン試行があった際、Discordに送られる通知の例です。

<img width="600" alt="dashboard_table_redacted_4" src="https://github.com/user-attachments/assets/8942af54-53b2-4c83-8720-b8cd4a063039" />

SSHの監視ログとグラフを、ダッシュボード上で時系列に確認できます。

<img width="600" alt="dashboard_header_redacted_2" src="https://github.com/user-attachments/assets/99936687-f56a-4eb4-90b1-8fa3a22abdda" />

ダッシュボードにアクセスすると、不正ログインの疑いがある場合にこのような警告バナーが表示されます。


<img width="1321" height="313" alt="newtable_redacted_check_3" src="https://github.com/user-attachments/assets/78333695-c897-451e-9419-de6fdcb0b8dc" />


ブラウザからダッシュボードにアクセスした際の画面全体です。


<img width="1857" height="861" alt="1313d2f3-1786258583139_image" src="https://github.com/user-attachments/assets/74fe57b2-243b-407f-84f8-77feac1eab79" />


攻撃元IPアドレスの国・組織別のランキングをグラフで可視化しています。

<img width="600" alt="login_screen" src="https://github.com/user-attachments/assets/5291b02e-6635-4905-89b4-9543d3102283" />

ダッシュボードへのログイン画面です。IDとパスワードによる認証を行います。


<img width="600" alt="2fa_screen_cropped" src="https://github.com/user-attachments/assets/8700e2a7-b10a-47bf-816c-387f8837a17e" />

ログイン後、2段階認証（TOTP）のコード入力を求められます。



