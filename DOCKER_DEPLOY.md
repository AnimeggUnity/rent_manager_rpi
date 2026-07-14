# Rent Manager — Docker 部署說明書（R5S 版，最終確認版）

> 目標：把 RPi 上的租管系統移到 R5S (iStoreOS) 的 Docker 容器裡跑
> 本版本已實際 SSH 進 R5S 查證過連接埠、驅動、溫度監控相容性，不是純推論。

---

## 架構概覽

```
R5S (192.168.101.1，root 已裝 SSH 金鑰)
└── Docker (27.3.1，已安裝)
    ├── container: rent_web    ← webdevops/php-nginx:8.4（現成 image，不用自己寫 Dockerfile）
    │   └── 掛載 /mnt/nvme0n1-1/data/rent_manager（程式碼 + 資料庫，見 1-6）
    └── container: rent_meter  ← 自己 build 的極簡 image（Python 套件鎖版本烘進去）
        └── 掛載 /dev/ttyUSB0（RS485 電表）
```

---

## 一、已查證的關鍵事實（部署前必看）

### 1-1 連接埠現況（實測，非推測）

```
:80    → uhttpd（iStoreOS 路由器自己的管理後台，綁 0.0.0.0，全介面）
:443   → uhttpd（同上）
:8080  → qbittorrent-nox（已被佔用，不能用）
:5001  → 空的，可直接用
:8081  → 空的，可直接用 ← web 服務改用這個
```

**web 服務的 host port 必須用 8081，不能用 80 或 8080**，兩個都已經被佔走，用了會 bind 失敗、容器起不來。之後網址是 `http://192.168.101.1:8081`。

R5S 上既有的其他 Docker 容器（rustdesk-hbbs、rustdesk-hbbr、adguardhome、istorepanel）都沒佔用 80 或 5001，不衝突（AdGuard 內部有個 `23000->80`，那是它自己容器內部的映射，跟本專案無關）。

### 1-2 RS485 / USB 轉接器

- `/dev/ttyUSB0` 目前**不存在**（轉接器還沒插上 R5S）。
- 驅動已就緒：`ftdi_sio`、`ch341`、`cp210x`、`pl2303` 都已載入，對應 kmod 套件也裝好了。**插上轉接器後 `/dev/ttyUSB0` 應該會自動出現**，不需要額外裝驅動。
- 注意：`devices: /dev/ttyUSB0:/dev/ttyUSB0` 是容器啟動當下才解析的。如果開機時裝置還沒被辨識，`rent_meter` 容器會啟動失敗；日後拔插一次 USB 轉接器，也可能要手動 `docker restart rent_meter`。
- **曾考慮過的替代方案（已否決）**：改用 `volumes: - /dev:/dev` + `privileged: true`，可以讓拔插後自動重新抓到裝置、不用手動重啟。但 `privileged: true` 等於把主機幾乎所有裝置的存取權都開給這個容器；查過 R5S 上現有的 4 個容器（rustdesk-hbbs、rustdesk-hbbr、adguardhome、istorepanel）**全部都是 `Privileged: false`**，這台機器同時是家裡的路由器、DNS、遠端連線主機，為了省一次手動重啟就開特權模式，風險跟效益不成比例。**維持原本 `devices:` 的寫法，接受「真拔插要手動重啟」這個小代價。**

### 1-3 CPU 溫度監控：可以直接沿用，路徑跟 RPi 一模一樣

```
/sys/class/thermal/thermal_zone0/type = cpu-thermal, temp = 55°C
/sys/class/thermal/thermal_zone1/type = gpu-thermal, temp ≈ 52°C
```
程式裡讀溫度的路徑（`/sys/class/thermal/thermal_zone0/temp`）在 R5S 上完全相容，不用改程式碼。R5S 硬體資訊比 Pi 更豐富，還有 NVMe 溫度、網卡溫度、PWM 風扇（`/sys/class/hwmon/hwmon5`）。

**前提**：容器要能讀到宿主的 `/sys`，`web` 容器的 compose 設定要加一行唯讀掛載：
```yaml
    volumes:
      - /sys:/sys:ro
```

### 1-4 CPU 調速模式：讀取沒問題，但「切換」按鈕會失效

`/sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq`、`scaling_governor` 在 R5S 上都讀得到（目前是 `408000` / `schedutil`）。但原本切換模式的按鈕呼叫 `sudo /usr/local/bin/set_cpu_governor`，這支腳本是專為 Pi 寫的，R5S 上沒有。

→ **待辦**：之後要嘛幫 R5S 另外寫一支對應的調速腳本，要嘛先把「切換調速模式」那顆按鈕隱藏，只保留唯讀顯示。溫度/頻率顯示本身不受影響。

### 1-5 兩個方案都沒處理的小缺口

- ~~`webdevops/php-nginx:8.4` 是否內建 `pdo_sqlite`~~：**已實測確認內建。** `docker run --rm webdevops/php-nginx:8.4 php -m | grep -i sqlite` 有輸出 `pdo_sqlite`。實測時會看到一行 `Failed loading .../ioncube_loader_lin_8.4.so` 警告，這是這個 image 常見的良性訊息（ionCube loader 沒裝，但本專案沒用到 ionCube 加密檔案，不影響）。
- **`index.php:418` 的 `fetch('/api/temp_history')` 一定會 404，已確認、不是猜測。** 追過程式碼才發現：這不是 PHP 內部路由問題，是在呼叫「電表監控」Flask 服務的溫度歷史 API。RPi 上原本靠 nginx 把 `/api/` 整段反向代理到 Flask 才能動；新的兩容器架構裡 `web` 跟 `meter` 是各自獨立開 port，沒有這條轉發規則，`web` 容器收到這個路徑只會去找 PHP 檔案，404 是必然發生，不是「可能」。
  **修法（已確認為最簡單的方式，不用寫 nginx 設定）**：把 `index.php:418` 那行改成直接打 meter 容器自己的 port：
  ```js
  fetch('http://192.168.101.1:5001/api/temp_history')
  ```
  一行 JS 改動即可，部署前記得先改這行。
  （曾考慮過用環境變數 `NGINX_TRY_FILES` 讓 webdevops image 自動處理乾淨網址——**查證後確認這個環境變數不存在，官方文件沒有此選項**，該image若真要客製 nginx 行為需另外把設定片段放進 `/opt/docker/etc/nginx/vhost.common.d/`，但上面這個案例根本不需要，因為問題本質是跨容器呼叫，不是 PHP 路由。）

### 1-6 資料存放路徑：改用 NVMe，不要用 `/data`

**實測發現，原計畫的 `/data` 路徑有問題**：R5S 的系統根目錄（`/`）只是一個 **1.9GB 的 overlay 分割區**，`df -h` 實測目前只剩 **896MB 可用空間**。若照原計畫 `mkdir -p /data/...`，程式碼、SQLite 資料庫、上傳檔案會全部落在這個小分割區，長期會有塞滿系統碟的風險。

R5S 上另外掛了一顆 **468GB 的 NVMe**，路徑是 `/mnt/nvme0n1-1`（`df -h` 顯示還有 366GB 可用），且 Docker 本身的資料（`Docker Root Dir`）跟既有的 `Configs`、`download` 資料夾也都已經放在這裡，是這台機器既有的慣例。

**結論：本文件下面所有 `/data/...` 路徑，實際部署時一律改成 `/mnt/nvme0n1-1/data/...`**（例如 `/data/rent_manager` → `/mnt/nvme0n1-1/data/rent_manager`），docker-compose.yml 的 volumes 掛載路徑也要對應更新。

### 1-7 R5S 上預設沒裝 git

實測 `git --version` 回傳 `not found`。`opkg update` 之後確認套件庫有 `git 2.50.1-r1`，部署前先執行：
```bash
opkg update
opkg install git
```

---

## 二、事前準備

### 2-1 SSH 進 R5S、裝 git、建目錄

SSH 進 R5S（金鑰已裝好，免密碼）：
```bash
ssh root@192.168.101.1
```

裝 git（預設沒裝，見 1-7）：
```bash
opkg update
opkg install git
```

建立資料夾（**放在 NVMe，不是 `/data`，理由見 1-6**）：
```bash
mkdir -p /mnt/nvme0n1-1/data/rent_manager
mkdir -p /mnt/nvme0n1-1/data/flask_web
mkdir -p /mnt/nvme0n1-1/data/docker
```

### 2-2 Clone 程式碼

```bash
git clone -b main https://github.com/AnimeggUnity/rent_manager_rpi.git /mnt/nvme0n1-1/data/rent_manager
git clone -b flask_web https://github.com/AnimeggUnity/rent_manager_rpi.git /mnt/nvme0n1-1/data/flask_web
```

### 2-3 建立 config.php

```bash
cp /mnt/nvme0n1-1/data/rent_manager/config.example.php /mnt/nvme0n1-1/data/rent_manager/config.php
nano /mnt/nvme0n1-1/data/rent_manager/config.php
# 填入管理員密碼、唯讀密碼、API Key、sc_ELEC_RATE 等
```

### 2-4 建立資料庫目錄

```bash
mkdir -p /mnt/nvme0n1-1/data/rent_manager/database
mkdir -p /mnt/nvme0n1-1/data/rent_manager/uploads
mkdir -p /mnt/nvme0n1-1/data/rent_manager/data/cookies
```

### 2-5 把 RPi 現有資料搬過來（原計畫漏掉的一步，2026-07-14 補上）

`git clone` 只會帶程式碼，**不會**帶正式資料（sqlite 資料庫、房客上傳的合約/憑證檔案）。這些要另外從 RPi 搬：

```bash
# 在能同時 SSH 到 RPi 跟 R5S 的機器上執行（例如開發機）
ssh rpi@192.168.101.21 "tar -cf - -C /home/rpi/rent_manager database/rent_manager.sqlite uploads" \
  | ssh root@192.168.101.1 "tar -xf - -C /mnt/nvme0n1-1/data/rent_manager"
```

**注意：這只是一份「當下快照」。** 只要 RPi 還在正式運作，資料會持續變動。這一步在部署初期先做一次是為了讓 R5S 上的環境能先跑起來測試；**真正切換服務的當天，一定要再重新跑一次這個指令**，抓最新的資料，才能無縫切換、不遺失切換前這段時間的異動。

---

## 三、建立 Docker 設定檔

放到 `/mnt/nvme0n1-1/data/docker/` 目錄下，共 3 個檔案（不用寫 web 的 Dockerfile，但 meter 一定要有自己的 Dockerfile，避免開機時才裝套件）。

### 3-1 `/mnt/nvme0n1-1/data/docker/requirements.txt`（meter 套件鎖版本）

```text
flask==3.1.3
flask-cors==5.0.0
minimalmodbus==2.1.1
pyserial==3.5
rich==13.9.4
```

### 3-2 `/mnt/nvme0n1-1/data/docker/Dockerfile.meter`

```dockerfile
FROM python:3.13-slim

COPY requirements.txt /requirements.txt
RUN pip install --no-cache-dir -r /requirements.txt

WORKDIR /app
CMD ["python3", "app.py"]
```

**為什麼一定要這樣做，不能用「容器啟動時才 pip install」**：R5S 是路由器，開機時對外網路常常還沒通。如果套件是在容器啟動當下才去 PyPI 下載，遇到開機瞬間網路未就緒、PyPI 抽風、或套件無鎖版本被上游改壞相容性，電表監控服務會在「路由器剛重開機」這個最不該掛的時間點起不來。用 `Dockerfile.meter` 在 `docker compose build` 時把套件烘進 image，之後開機只是啟動現成 image，**不需要網路、秒起、版本固定**。

### 3-3 `/mnt/nvme0n1-1/data/docker/docker-compose.yml`

```yaml
services:
  web:
    image: webdevops/php-nginx:8.4
    container_name: rent_web
    ports:
      - "8081:80"       # 80/8080 都已被 R5S 佔用，改用 8081
    volumes:
      - /mnt/nvme0n1-1/data/rent_manager:/app
      - /sys:/sys:ro     # 讓溫度/頻率監控功能讀得到宿主硬體資訊
    environment:
      - WEB_DOCUMENT_ROOT=/app
    restart: unless-stopped

  meter:
    build:
      context: /mnt/nvme0n1-1/data/docker
      dockerfile: Dockerfile.meter
    container_name: rent_meter
    volumes:
      - /mnt/nvme0n1-1/data/flask_web:/app
    devices:
      - /dev/ttyUSB0:/dev/ttyUSB0
    ports:
      - "5001:5001"
    restart: unless-stopped
```

---

## 四、第一次部署

```bash
cd /mnt/nvme0n1-1/data/docker

# 1. 把 meter 的 Python 套件 build 進本地 image（只需要在有網路時做這一次）
docker compose build meter

# 2. 啟動所有服務
docker compose up -d

# 3. 確認有在跑
docker compose ps
```

瀏覽器開 `http://192.168.101.1:8081` 確認是否正常。

**上線前必做（見 1-5、七-2、七-3）**：
```bash
docker exec rent_web php -m | grep -i sqlite   # 確認 pdo_sqlite 存在
```
並確認 `index.php:418` 的 `fetch('/api/temp_history')` 已經改成打 `http://192.168.101.1:5001/api/temp_history`，否則溫度趨勢圖會 404。

---

## 五、日常操作

### 更新程式碼

```bash
cd /mnt/nvme0n1-1/data/rent_manager && git pull
cd /mnt/nvme0n1-1/data/flask_web && git pull

# PHP/前端程式碼改動：重啟就好，不用重新 build
docker compose -f /mnt/nvme0n1-1/data/docker/docker-compose.yml restart web

# meter 若改了 Python 依賴（requirements.txt），要重新 build
docker compose -f /mnt/nvme0n1-1/data/docker/docker-compose.yml build meter
docker compose -f /mnt/nvme0n1-1/data/docker/docker-compose.yml up -d meter
```

### 看 log

```bash
docker logs rent_web
docker logs rent_meter
docker logs -f rent_meter   # 即時追蹤
```

### 重啟 / 停止

```bash
docker compose -f /mnt/nvme0n1-1/data/docker/docker-compose.yml restart web
docker compose -f /mnt/nvme0n1-1/data/docker/docker-compose.yml restart meter
docker compose -f /mnt/nvme0n1-1/data/docker/docker-compose.yml down
```

---

## 六、資料備份

```bash
cp /mnt/nvme0n1-1/data/rent_manager/database/rent_manager.sqlite /backup/rent_manager_$(date +%Y%m%d).sqlite
tar -czf /backup/rent_manager_$(date +%Y%m%d).tar.gz /mnt/nvme0n1-1/data/rent_manager/database /mnt/nvme0n1-1/data/rent_manager/uploads
```

（沿用 RPi 上原本就有的每月自動備份到 VPS 機制，`cron/monthly_backup_to_vps.php`，遷移後這支腳本也要一併移到 R5S 上排程，或改在 R5S 上另建一份等效的排程。）

---

## 七、尚未解決、之後要處理的事項清單

1. **CPU 調速切換按鈕**：R5S 上沒有 `set_cpu_governor` 腳本，需重寫或先隱藏該 UI（見 1-4）。
2. **`index.php:418` 的 `fetch('/api/temp_history')` 部署前必改**：已確認會 404，改成打 `http://192.168.101.1:5001/api/temp_history`（見 1-5）。
3. ~~**pdo_sqlite**~~：**已實測確認內建**，`docker run --rm webdevops/php-nginx:8.4 php -m | grep -i sqlite` 有輸出（見 1-5）。
4. ~~**RS485 轉接器**~~：2026-07-14 已實際插上 R5S，`/dev/ttyUSB0` 自動出現，跟預期一致。
5. **開機自動恢復（尚未驗證，維持已知限制）**：`/dev/ttyUSB0` 若在容器啟動後才插上/重新辨識，`rent_meter` 需要手動 `docker restart`，尚無自動偵測重連機制——已評估過用 `privileged: true` 解決，但因安全代價太高而否決（見 1-2）。
6. ~~**`index.php:418` 尚未在本機程式碼真的改**~~：**已改完**（2026-07-14），現在打 `http://192.168.101.1:5001/api/temp_history`。改之前有先備份成 `index.php.bak`（本機 untracked，不用 commit 這份備份）。
7. **正式切換當天要重搬一次資料**：2026-07-14 已從 RPi 搬過一份 `rent_manager.sqlite` + `uploads` 到 R5S 當測試快照（見 2-5），但這份會隨 RPi 持續使用而過期，**真正切換服務時必須重新執行一次 2-5 的搬移指令**，抓最新資料再切換，否則會遺失切換前的異動。
8. **`index.php:418` 目前只在 R5S 上直接改了檔案，本機 git 還沒 commit/push**：本機 `index.php` 有這行未提交的修改，R5S 上是直接 `sed` 改同一行讓它先能動（測試階段）。**這代表兩邊暫時是分別維護的狀態**：之後如果本機又 `git pull` 或 R5S 又重新 `git clone`，這行修正都會不見。正式切換前務必把本機這個修改 commit + push 上 GitHub，讓兩邊回到同一個真實來源。
9. ~~**CPU 溫度歷史圖表暫時還是空的**~~：2026-07-14 RS485 轉接器插上後 `meter` 容器已成功啟動，`/api/temp_history` 實測回應 200，圖表可以正常運作了。
10. **CPU 調速切換按鈕已實測確認失效**：`docker exec` 測試 `POST /api/cpu_freq.php?action=set&governor=performance` 回傳 `{"ok":false,"governor":"schedutil"}`，跟 1-4 預期的一致（容器內沒有 `sudo`/`set_cpu_governor` 可執行），不會噴錯但切換無效，UI 待隱藏或改寫（同第 1 項）。
11. **`flask_web/app.py` 有寫死的 RPi 路徑，原計畫沒發現，2026-07-14 已在 R5S 上直接修正**：
    - `DB_PATH`／`TEMP_DB_PATH` 原本寫死 `/home/rpi/flask_web/meter_data.db`、`/home/rpi/flask_web/temp_data.db`，容器裡沒有這個路徑，一啟動就 crash（`sqlite3.OperationalError: unable to open database file`）。已改成相對容器內的 `/app/meter_data.db`、`/app/temp_data.db`。
    - `PORT = "/dev/ttyMeter"`：這個裝置名稱是 RPi 上一條綁定 USB 序號（`BG03CT4W`）的 udev 規則建立的符號連結（`/etc/udev/rules.d/99-usb-serial.rules`）。**R5S 是 OpenWrt，用的是 mdev 不是 udev**，沒有對應機制可以比照建立這個符號連結，硬要移植這條規則過去太脆弱。已直接改成跟 docker-compose 掛載一致的 `/dev/ttyUSB0`。
    - 這兩處修正**目前只做在 R5S 上**，本機/GitHub 的 `flask_web` 分支還沒同步這個修正，跟 `index.php:418` 一樣是「先讓功能動起來，之後要記得補進版本控制」的技術債（見第 8 項）。
    - 修正後已實測：`meter_data.db`、`temp_data.db` 也從 RPi 搬過一份（跟 2-5 的資料搬移一樣是快照，正式切換要重搬），`meter` 容器啟動成功、6 個房間都抓到即時讀數（RS485 是「被動旁聽」DAE 主機既有的輪詢流量，不是自己主動發 Modbus 請求，所以只要實體接線正確、DAE 主機本身在正常輪詢，資料就會自動出現）。
12. **`index.php:12` 的 `$is_pi` 偵測寫法在 Docker 環境下必然失效，原計畫沒發現，2026-07-14 已修正**：原本用 `file_exists('/sys/firmware/devicetree/base/model')` 判斷「是否為樹莓派/是否顯示硬體面板」，這個路徑在 R5S 主機上是有的，**但 Docker 預設會遮蔽（mask）`/sys/firmware` 這個路徑，就算 `/sys:/sys:ro` 整個掛進容器也一樣看不到**——這是 Docker 的標準安全行為（runc/containerd 預設 MaskedPaths 清單內建 `/sys/firmware`），不是 R5S 或這次遷移特有的坑，換成 RPi 上跑 Docker 一樣會壞。導致「硬體狀態」整塊面板（含溫度趨勢圖 `<canvas id="temp-chart">`）在容器裡永遠不顯示，不會報錯，就是單純不出現，容易被忽略。**已改成偵測 `/sys/class/thermal/thermal_zone0/temp`**（`sysinfo.php` 本來就在用這個路徑，已確認容器內讀得到），本機跟 R5S 都已修正，R5S 上並 `docker restart rent_web` 讓 PHP opcache 更新生效。

---

## 版本對照

| 軟體 | 版本 |
|------|------|
| PHP | 8.4（隨 webdevops/php-nginx:8.4） |
| Python | 3.13-slim |
| Flask | 3.1.3 |
| minimalmodbus | 2.1.1 |
| pyserial | 3.5 |
| Docker | 27.3.1（R5S 上已安裝） |

---

## 現有機器

| 機器 | IP | 用途 |
|------|-----|------|
| R5S | 192.168.101.1 | 路由器 + Docker（遷移目標，root 已裝 SSH 金鑰） |
| RPi | 192.168.101.21 | 現行租管系統（Pi 3B+，2018年硬體，15年老 SD 卡，待退役） |
