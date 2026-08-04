import os
import re
import asyncio
import urllib.request
import json
import sys
import signal
import discord
import matplotlib.pyplot as plt
import mysql.connector
from collections import Counter
from discord.ext import tasks

DISCORD_BOT_TOKEN = os.environ.get("DISCORD_BOT_TOKEN")
TARGET_CHANNEL_ID = int(os.environ.get("TARGET_CHANNEL_ID"))
LOG_FILE = "/var/log/secure"

EXCLUDE_USERS = {"root", "admin", "user", "ubuntu", "test"}
APACHE_LOG_FILE = "/var/log/httpd/access_log"
TRUSTED_IPS = {os.environ.get("TRUSTED_IP", "")}
SUSPICIOUS_STATUS_CODES = {400, 401, 403, 404, 501}
VALID_METHODS = {"GET", "POST", "HEAD", "PUT", "DELETE", "OPTIONS"}
web_alert_counter = {}

DB_CONFIG = {
    "host": "localhost",
    "user": os.environ.get("MONITOR_DB_USER"),
    "password": os.environ.get("MONITOR_DB_PASS"),
    "database": "security_monitor"
}

intents = discord.Intents.default()
intents.message_content = True
client = discord.Client(intents=intents)


def get_db_connection():
    try:
        return mysql.connector.connect(**DB_CONFIG)
    except Exception as e:
        print(f"[DB ERROR] 接続失敗: {e}")
        return None


def save_log_to_db(status, username, ip_str, country, org_name):
    conn = get_db_connection()
    if conn is None:
        return
    try:
        cursor = conn.cursor()
        query = """
            INSERT INTO ssh_attack_logs
            (event_time, status, username, ip_address, country_code, organization)
            VALUES (NOW(), %s, %s, %s, %s, %s)
        """
        cursor.execute(query, (status, username, ip_str, country, org_name))
        conn.commit()
        cursor.close()
    except Exception as e:
        print(f"[DB ERROR] 保存失敗: {e}")
    finally:
        conn.close()

def save_web_log_to_db(ip_str, method, path, status_code, user_agent, country, org_name):
    conn = get_db_connection()
    if conn is None:
        return
    try:
        cursor = conn.cursor()
        query = """
            INSERT INTO web_attack_logs
            (event_time, ip_address, method, path, status_code, user_agent, country_code, organization)
            VALUES (NOW(), %s, %s, %s, %s, %s, %s, %s)
        """
        cursor.execute(query, (ip_str, method, path, status_code, user_agent, country, org_name))
        conn.commit()
        cursor.close()
    except Exception as e:
        print(f"[DB ERROR] Web攻撃ログ保存失敗: {e}")
    finally:
        conn.close()


def country_code_to_emoji(country_code):
    if not country_code or country_code == "??":
        return "🏴‍☠️"
    try:
        return "".join(chr(ord(c.upper()) + 127397) for c in country_code)
    except Exception:
        return "🌍"


_GEOIP_CACHE = {}

def get_whois_info_from_ip(ip):
    if ip == "Unknown IP" or ip.startswith("192.168.") or ip.startswith("10."):
        return "LOCAL", "Local Network"
    if ip in _GEOIP_CACHE:
        return _GEOIP_CACHE[ip]
    try:
        url = f"http://ip-api.com/json/{ip}?fields=status,countryCode,isp,org"
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req, timeout=2) as response:
            data = json.loads(response.read().decode())
            if data.get("status") == "success":
                country = data.get("countryCode", "??")
                isp = data.get("isp", "Unknown ISP")
                org = data.get("org", "Unknown ORG")
                org_info = org if org and org != isp else isp
                _GEOIP_CACHE[ip] = (country, org_info)
                return country, org_info
    except Exception:
        pass
    return "??", "Unknown Organization"


def analyze_secure_log():
    alma_success = 0
    alma_failure = 0
    attack_users = []
    if not os.path.exists(LOG_FILE):
        return None
    with open(LOG_FILE, "r", encoding="utf-8", errors="ignore") as f:
        for line in f:
            if "Accepted" in line:
                if "alma" in line:
                    alma_success += 1
            elif "Failed" in line:
                match = re.search(r"Failed password for (invalid user )?(\S+)", line)
                if match:
                    user = match.group(2)
                    if user == "alma":
                        alma_failure += 1
                    else:
                        attack_users.append(user)
    return alma_success, alma_failure, attack_users

@tasks.loop(seconds=1.0)
async def watch_log_file():
    if not os.path.exists(LOG_FILE):
        return

    if not hasattr(watch_log_file, "file_pos"):
        watch_log_file.file_pos = os.path.getsize(LOG_FILE)
        return

    current_size = os.path.getsize(LOG_FILE)
    if current_size < watch_log_file.file_pos:
        watch_log_file.file_pos = 0

    if current_size > watch_log_file.file_pos:
        with open(LOG_FILE, "r", encoding="utf-8", errors="ignore") as f:
            f.seek(watch_log_file.file_pos)
            lines = f.readlines()
            watch_log_file.file_pos = f.tell()

            channel = client.get_channel(TARGET_CHANNEL_ID)
            if not channel:
                return

            for line in lines:
                # ログイン成功・失敗の行だけを対象にする
                is_accepted = bool(re.search(r"Accepted (password|publickey|keyboard-interactive/pam) for", line))
                is_failed = "Failed password" in line
                # sudoコマンド使用の監視（alma以外の使用、または認証失敗）
                if "sudo:" in line and "COMMAND=" in line:
                    sudo_user_match = re.search(r"sudo:\s*(\S+)\s*:", line)
                    sudo_user = sudo_user_match.group(1) if sudo_user_match else "unknown"
                    if sudo_user != "alma":
                        embed = discord.Embed(
                            title="⚠️ SUDO_USAGE_ALERT（sudo不正使用の疑い）",
                            description=(
                                "**almaではないユーザーによるsudo実行を検知しました。**\n"
                                "🟡 重大度: Warning"
                            ),
                            color=discord.Color.orange()
                        )
                        embed.add_field(
                            name="✅ 推奨アクション",
                            value="1. `who` で現在ログイン中のユーザーを確認\n2. 自分の操作でなければ該当セッションを強制切断（sudo pkill -KILL -u ユーザー名）\n3. 直ちにパスワードを変更",
                            inline=False
                        )
                        embed.add_field(name="👤 User", value=f"`{sudo_user}`", inline=True)
                        embed.timestamp = discord.utils.utcnow()
                        embed.set_footer(text="security-monitor（練習環境）")
                        await channel.send(embed=embed)
                    continue
                if "sudo:" in line and "authentication failure" in line:
                    embed = discord.Embed(
                        title="🚨 SUDO_AUTH_FAILURE（sudo認証失敗）",
                        description=(
                            "**sudo認証の失敗を検知しました（権限昇格の試み）。**\n"
                            "🟠 重大度: High"
                        ),
                        color=discord.Color.gold()
                    )
                    embed.add_field(
                        name="✅ 推奨アクション",
                        value="1. `who` で現在ログイン中のユーザーを確認\n2. 自分の操作でなければ該当セッションを強制切断（sudo pkill -KILL -u ユーザー名）\n3. 直ちにパスワードを変更",
                        inline=False
                    )
                    embed.timestamp = discord.utils.utcnow()
                    embed.set_footer(text="security-monitor（練習環境）")
                    await channel.send(embed=embed)
                    continue

                if not (is_accepted or is_failed):
                    continue

                # ユーザー名を抽出（alma以外も含めて全員拾う）
                user_match = re.search(r"for (invalid user )?(\S+) from", line)
                username = user_match.group(2) if user_match else "unknown"

                ip_match = re.search(r"from (\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})", line)
                ip_str = ip_match.group(1) if ip_match else "Unknown IP"
                country, org_name = get_whois_info_from_ip(ip_str)
                flag_emoji = country_code_to_emoji(country)

                if is_accepted:
                    # DBには全員分を保存
                    save_log_to_db("SUCCESS", username, ip_str, country, org_name)

                    # ログイン成功は誰であっても重大なので必ずDiscord通知
                    embed = discord.Embed(
                        title="🚨🚨🚨 SSH_BREACH_ALERT（侵入検知） 🚨🚨🚨",
                        description=(
                            "**システム内部へのログイン成功（侵入）が検知されました！**\n"
                            "🔴 重大度: Critical"
                        ),
                        color=discord.Color.red()
                    )
                    embed.add_field(name="👤 User", value=f"`{username}`", inline=True)
                    embed.add_field(name="🌐 IP Address", value=f"`{ip_str}`", inline=True)
                    embed.add_field(name="🌍 Country", value=f"{flag_emoji} `{country}`", inline=True)
                    embed.add_field(name="🏢 Organization (Whois)", value=f"`{org_name}`", inline=False)
                    embed.add_field(
                        name="✅ 推奨アクション",
                        value="1. 自分自身のログインか確認\n2. 違う場合は `who` で確認し、該当セッションを強制切断（sudo pkill -KILL -u ユーザー名）\n3. 直ちにパスワードを変更",
                        inline=False
                    )
                    embed.add_field(
                        name="🔗 詳細",
			value=f"[ダッシュボードを開く]({DASHBOARD_URL})",
                        inline=False
                    )
                    embed.timestamp = discord.utils.utcnow()
                    embed.set_footer(text="security-monitor（練習環境）")
                    await channel.send(embed=embed)

                elif is_failed:
                    # DBには全員分を保存
                    save_log_to_db("FAILED", username, ip_str, country, org_name)

                    # Discord通知は「alma（正規アカウント）」への攻撃のみ即時通知
                    # それ以外（無差別攻撃）は通知を送らず、DBには記録するだけにする
                    if username == "alma":
                        embed = discord.Embed(
                            title="⚠️ SSH_DROP_INTELLIGENCE（ログイン失敗）",
                            description="ユーザー名 `alma` のログイン失敗を検知しました。",
                            color=discord.Color.gold()
                        )
                        embed.add_field(name="👤 User", value=f"`{username}`", inline=True)
                        embed.add_field(name="🌐 IP Address", value=f"`{ip_str}`", inline=True)
                        embed.add_field(name="🌍 Country", value=f"{flag_emoji} `{country}`", inline=True)
                        embed.add_field(name="🏢 Organization (Whois)", value=f"`{org_name}`", inline=False)
                        embed.add_field(
                         name="🔗 詳細",
			value=f"[ダッシュボードを開く]({DASHBOARD_URL})",                           
			 inline=False
                        )
                        embed.timestamp = discord.utils.utcnow()
                        await channel.send(embed=embed)
@tasks.loop(seconds=2.0)
async def watch_apache_log():
    if not os.path.exists(APACHE_LOG_FILE):
        return
    if not hasattr(watch_apache_log, "file_pos"):
        watch_apache_log.file_pos = os.path.getsize(APACHE_LOG_FILE)
        return
    current_size = os.path.getsize(APACHE_LOG_FILE)
    if current_size < watch_apache_log.file_pos:
        watch_apache_log.file_pos = 0
    if current_size > watch_apache_log.file_pos:
        with open(APACHE_LOG_FILE, "r", encoding="utf-8", errors="ignore") as f:
            f.seek(watch_apache_log.file_pos)
            lines = f.readlines()
            watch_apache_log.file_pos = f.tell()
            channel = client.get_channel(TARGET_CHANNEL_ID)
            for line in lines:
                match = re.search(
                    r'^(\S+) \S+ \S+ \[.*?\] "(\S+) (\S+) \S+" (\d+) \S+',
                    line
                )
                if not match:
                    continue
                ip_str, method, path, status_str = match.groups()
                try:
                    status_code = int(status_str)
                except ValueError:
                    continue
                if ip_str in TRUSTED_IPS:
                    continue
                is_bad_method = method not in VALID_METHODS
                is_bad_status = status_code in SUSPICIOUS_STATUS_CODES
                if not (is_bad_method or is_bad_status):
                    continue
                user_agent_match = re.search(r'"([^"]*)"$', line.strip())
                user_agent = user_agent_match.group(1) if user_agent_match else "Unknown"
                country, org_name = get_whois_info_from_ip(ip_str)
                save_web_log_to_db(ip_str, method, path, status_code, user_agent, country, org_name)
                web_alert_counter[ip_str] = web_alert_counter.get(ip_str, 0) + 1
                if web_alert_counter[ip_str] == 5 and channel:
                    embed = discord.Embed(
                        title="⚠️ WEB_SCAN_ALERT（不審アクセス検知）",
                        description=(
                            "**Webサーバーへの不審なアクセスを複数検知しました。**\n"
                            "🟡 重大度: Warning"
                        ),
                        color=discord.Color.orange()
                    )
                    embed.add_field(name="🌐 IP Address", value=f"`{ip_str}`", inline=True)
                    embed.add_field(name="📄 直近のパス", value=f"`{path}`", inline=True)
                    embed.add_field(name="🔢 ステータスコード", value=f"`{status_code}`", inline=True)
                    embed.add_field(
                        name="✅ 推奨アクション",
                        value="1. アクセス元IPを確認\n2. fail2banが自動でIPを一時ブロックします（1時間）\n3. 継続する場合は手動で恒久ブロックを検討",
                        inline=False
                    )
                    embed.add_field(
                        name="🔗 詳細",
			value=f"[ダッシュボードを開く]({DASHBOARD_URL})",
                        inline=False
                    )
                    embed.timestamp = discord.utils.utcnow()
                    embed.set_footer(text="security-monitor（練習環境）")
                    await channel.send(embed=embed)
def receive_signal(signum, frame):
    if os.getpid() == os.getpgid(0):
        client.loop.create_task(shutdown_notification())
    else:
        asyncio.run_coroutine_threadsafe(client.close(), client.loop)


async def shutdown_notification():
    try:
        channel = client.get_channel(TARGET_CHANNEL_ID)
        if channel:
            embed = discord.Embed(title="🛑 システム停止", color=discord.Color.light_grey())
            await channel.send(embed=embed)
    except Exception:
        pass
    finally:
        await client.close()


@client.event
async def on_ready():
    signal.signal(signal.SIGTERM, receive_signal)
    signal.signal(signal.SIGINT, receive_signal)

    channel = client.get_channel(TARGET_CHANNEL_ID)
    if channel:
        embed = discord.Embed(title="🛡️ 監視システム 起動", color=discord.Color.green())
        await channel.send(embed=embed)
    if not watch_log_file.is_running():
        watch_log_file.start()
    if not watch_apache_log.is_running():
        watch_apache_log.start()


client.run(DISCORD_BOT_TOKEN)
