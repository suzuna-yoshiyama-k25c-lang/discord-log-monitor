import time
import subprocess
import urllib.request
import json

# ==========================================
# 【修正済み】教えてもらったDiscordのURLを設定しました
WEBHOOK_URL = ''  # ここにDiscordのWebhook URLを設定してください
# ==========================================

def send_discord(message):
    payload = {"content": message}
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        WEBHOOK_URL,
        data=data,
        headers={"User-Agent": "Mozilla/5.0", "Content-Type": "application/json"}
    )
    try:
        with urllib.request.urlopen(req) as res:
            res.read()
    except Exception as e:
        print(f"❌ Discord送信エラー: {e}")

print("👀 ログの監視を開始します...（停止するには Ctrl+C を押してください）")

# 1. ログファイルを開く
with open("/var/log/secure", "r") as f:
    # 2. 起動した瞬間に、ファイルの「一番最後」にワープ（過去ログを無視する！）
    f.seek(0, 2)

    # 3. ここからずーっと最新の書き込みだけを見張り続ける
    while True:
        line = f.readline()  # ★ループの中で毎回、新しい書き込みを1行読み込 む！

        if not line:
            time.sleep(1)    # 新しい文字がなければ1秒待ってやり直す
            continue

        # 4. 新しいログイン失敗を検知したら通知
       # 4. 新しいログイン失敗を検知したら通知（かつ alma のときだけ）
        if "Failed password" in line and "alma" in line:
            print("🚨 自分のアカウント（alma）へのログイン失敗を検知しました ！")
            send_discord("🚨 【警告】あなたのユーザー名（alma）でログイン失敗が検知されました！")