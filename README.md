# rent_manager_rpi

租客管理 + 電力監控系統，運行於 Raspberry Pi。

## 系統架構

```
瀏覽器
  │
  ├─ /          → PHP 租客管理系統（nginx + php-fpm）
  └─ /meter     → Flask 即時電表監控（proxy → port 5001）

Flask app
  └─ RS485 → DEM540N 電表（Modbus，/dev/ttyUSB0）
```

## Branches

| Branch | 內容 |
|--------|------|
| `main` | PHP 租客管理系統 |
| `flask_web` | Flask 電表監控 |

---

## 快速安裝（換機器時）

### 前置條件
- Raspberry Pi（Debian 12/13）
- 已連上網路

### 步驟

```bash
# 1. 下載安裝腳本（不需要先設 SSH key，public repo 直接下載）
curl -O https://raw.githubusercontent.com/AnimeggUnity/rent_manager_rpi/main/setup.sh

# 2. 執行
bash setup.sh
```

腳本會自動完成：
- 安裝所有套件（nginx、php8.4、python3 等）
- Clone 程式碼（HTTPS，不需 SSH key）
- 互動式設定密碼，產生 `config.php`（管理員密碼、唯讀密碼）
- 建立空白 `config/meter_config.json`（DAE 平台帳號請安裝完後從瀏覽器設定）
- 設定 nginx、php-fpm
- 建立 systemd service（Flask 電表監控）
- 設定 cron（每日 00:00 快照電表度數）
- 產生 SSH key（供日後 `git push` 使用）

### 還原資料庫
安裝完成後，將備份的資料庫複製回來：

```bash
cp rent_manager.sqlite ~/rent_manager/database/rent_manager.sqlite
sudo chown www-data:www-data ~/rent_manager/database/rent_manager.sqlite
```

---

## 目錄結構

```
rent_manager/               ← PHP 管理系統（main branch）
├── api/                    ← API endpoints
├── assets/                 ← CSS
├── config/                 ← meter_config.json
├── cron/
│   ├── daily_meter_snapshot.php   ← 每日 00:00 快照電表
│   └── fix_gap_spread.php         ← 補齊缺漏資料工具
├── database/               ← SQLite（不進 git）
├── includes/               ← db.php、共用函式
├── uploads/                ← 上傳檔案（不進 git）
├── views/                  ← 頁面
├── config.php              ← 本機設定（不進 git）
├── config.example.php      ← 設定範本
├── index.php               ← 入口
└── routes.php

flask_web/                  ← Flask 電表監控（flask_web branch）
├── templates/
│   ├── index.html          ← 即時電表頁面
│   └── anomalies.html      ← 異常紀錄頁面
└── app.py                  ← Flask 主程式
```

---

## 服務管理

```bash
# 電表監控
sudo systemctl status meter_monitor
sudo systemctl restart meter_monitor
sudo journalctl -u meter_monitor -f

# Web
sudo systemctl restart nginx
sudo systemctl restart php8.4-fpm

# Cron log
tail -f ~/rent_manager/cron/meter_snapshot.log
```

---

## 電表對應（Modbus ID）

| Modbus ID | 房間 |
|-----------|------|
| 1 | 303室 |
| 2 | 403室 |
| 3 | 402室 |
| 4 | 401室 |
| 5 | 302室 |
| 6 | 301室 |

串口：`/dev/ttyUSB0`，Baud：2400

---

## 更新程式碼

```bash
# 管理系統
cd ~/rent_manager && git pull

# 電表監控
cd ~/flask_web && git pull
sudo systemctl restart meter_monitor
```

---

## 注意事項

- `config.php` 含密碼，**不進 git**，需手動建立或執行 `setup.sh`
- `database/*.sqlite` **不進 git**，請另行備份
- 硬體監控面板（CPU 溫度、記憶體等）只在 RPi 上顯示，部署到其他主機會自動隱藏
