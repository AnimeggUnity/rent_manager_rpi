#!/bin/bash
# setup.sh — 全新 RPi 環境安裝腳本
# 適用：Debian 12/13（Bookworm/Trixie）
# 用法：bash setup.sh

set -e

REPO="git@github.com:AnimeggUnity/rent_manager_rpi.git"
RENT_DIR="$HOME/rent_manager"
FLASK_DIR="$HOME/flask_web"
VENV_DIR="$HOME/modbus_venv"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()    { echo -e "${GREEN}[INFO]${NC} $1"; }
warn()    { echo -e "${YELLOW}[WARN]${NC} $1"; }
confirm() { read -p "$1 [y/N] " r; [[ "$r" =~ ^[Yy]$ ]]; }

# ── 1. 系統套件 ────────────────────────────────────────────────────────────────
info "安裝系統套件..."
sudo apt-get update -qq
sudo apt-get install -y \
    git nginx \
    php8.4-fpm php8.4-cli php8.4-sqlite3 php8.4-mbstring php8.4-curl \
    python3 python3-venv \
    sqlite3 curl

# ── 2. SSH key（若無則產生） ────────────────────────────────────────────────────
if [ ! -f "$HOME/.ssh/id_ed25519" ]; then
    info "產生 SSH key..."
    ssh-keygen -t ed25519 -C "rpi@rent_manager" -f "$HOME/.ssh/id_ed25519" -N ""
    echo ""
    warn "請將以下公鑰加到 GitHub Settings → SSH keys，完成後按 Enter 繼續："
    echo ""
    cat "$HOME/.ssh/id_ed25519.pub"
    echo ""
    read -p "（加完後按 Enter）"
    ssh -o StrictHostKeyChecking=no -T git@github.com 2>&1 || true
fi

# ── 3. Clone 程式碼 ────────────────────────────────────────────────────────────
info "Clone 程式碼..."
[ -d "$RENT_DIR" ] && warn "$RENT_DIR 已存在，略過 clone" || \
    git clone -b main "$REPO" "$RENT_DIR"

[ -d "$FLASK_DIR" ] && warn "$FLASK_DIR 已存在，略過 clone" || \
    git clone -b flask_web "$REPO" "$FLASK_DIR"

# ── 4. PHP 設定檔 ──────────────────────────────────────────────────────────────
if [ ! -f "$RENT_DIR/config.php" ]; then
    info "設定 config.php..."
    echo ""
    read -p "管理員密碼：" ADMIN_PASS
    read -p "唯讀密碼：  " VIEWER_PASS
    read -p "系統名稱 [柯柯管理系統]：" APP_NAME
    APP_NAME=${APP_NAME:-柯柯管理系統}

    sed \
        -e "s/your_admin_password/$ADMIN_PASS/" \
        -e "s/your_viewer_password/$VIEWER_PASS/" \
        -e "s/管理系統/$APP_NAME/" \
        "$RENT_DIR/config.example.php" > "$RENT_DIR/config.php"
    info "config.php 已產生"
else
    warn "config.php 已存在，略過"
fi

# ── 5. 建立資料庫目錄與權限 ────────────────────────────────────────────────────
info "設定目錄權限..."
mkdir -p "$RENT_DIR/database" "$RENT_DIR/uploads" "$RENT_DIR/data/cookies"
sudo chown -R www-data:www-data "$RENT_DIR/database" "$RENT_DIR/uploads"
sudo chmod -R 775 "$RENT_DIR/database" "$RENT_DIR/uploads"

# ── 6. Python venv ─────────────────────────────────────────────────────────────
info "建立 Python venv..."
if [ ! -d "$VENV_DIR" ]; then
    python3 -m venv "$VENV_DIR"
fi
"$VENV_DIR/bin/pip" install --quiet \
    Flask flask-cors minimalmodbus pyserial rich
info "Python 套件安裝完成"

# ── 7. Nginx 設定 ──────────────────────────────────────────────────────────────
info "設定 Nginx..."
sudo tee /etc/nginx/sites-available/rent_manager > /dev/null << 'NGINXEOF'
server {
    listen 80 default_server;
    listen [::]:80 default_server;

    root /home/rpi/rent_manager;
    index index.php index.html;

    location /meter {
        rewrite ^/meter/?(.*)$ /$1 break;
        proxy_pass http://127.0.0.1:5001;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }

    location /api/ {
        proxy_pass http://127.0.0.1:5001;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }

    location /anomalies {
        proxy_pass http://127.0.0.1:5001;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
}
NGINXEOF

sudo ln -sf /etc/nginx/sites-available/rent_manager /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl restart nginx
info "Nginx 設定完成"

# ── 8. Systemd service（Flask 電表監控） ────────────────────────────────────────
info "設定 meter_monitor.service..."
sudo tee /etc/systemd/system/meter_monitor.service > /dev/null << SVCEOF
[Unit]
Description=DEM540N Meter Monitor Flask Web
After=network.target

[Service]
ExecStart=$VENV_DIR/bin/python3 $FLASK_DIR/app.py
WorkingDirectory=$FLASK_DIR
StandardOutput=inherit
StandardError=inherit
Restart=always
User=root

[Install]
WantedBy=multi-user.target
SVCEOF

sudo systemctl daemon-reload
sudo systemctl enable meter_monitor
sudo systemctl start meter_monitor
info "meter_monitor.service 已啟動"

# ── 9. Cron ────────────────────────────────────────────────────────────────────
info "設定 cron..."
CRON_JOB="0 0 * * * /usr/bin/php $RENT_DIR/cron/daily_meter_snapshot.php >> $RENT_DIR/cron/meter_snapshot.log 2>&1"
( crontab -l 2>/dev/null | grep -v "daily_meter_snapshot"; echo "$CRON_JOB" ) | crontab -
info "Cron 設定完成"

# ── 10. 完成 ───────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}=============================${NC}"
echo -e "${GREEN} 安裝完成！${NC}"
echo -e "${GREEN}=============================${NC}"
echo ""
echo "  管理系統：http://$(hostname -I | awk '{print $1}')/"
echo "  電表監控：http://$(hostname -I | awk '{print $1}')/meter"
echo ""
echo "  若需還原資料庫，請複製備份的 .sqlite 到："
echo "  $RENT_DIR/database/rent_manager.sqlite"
echo ""
