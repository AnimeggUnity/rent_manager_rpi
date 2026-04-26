from flask import Flask, render_template, jsonify, request
from datetime import datetime
from flask_cors import CORS
import serial
import struct
import sqlite3
import threading
import time
import signal
import sys
from collections import deque

app = Flask(__name__)
CORS(app)

DB_PATH      = "/home/rpi/flask_web/meter_data.db"
TEMP_DB_PATH = "/home/rpi/flask_web/temp_data.db"
PORT = "/dev/ttyUSB0"
BAUD = 2400

ROOM_MAP = {1: "303室", 2: "403室", 3: "402室", 4: "401室", 5: "302室", 6: "301室"}
STATUS_MAP = {"05A1": "供電", "01D1": "預付", "00D0": "欠費", "00C0": "預關", "0080": "強關", "0000": "斷電"}

_ser = None
_ser_lock = threading.Lock()

latest_data = {i: {
    "id": i, "room": ROOM_MAP.get(i, f"ID {i}"), "kwh": 0.0,
    "watts": 0,
    "watts_calc": 0,
    "status": "---", "balance": 0.0, "last_update": "---",
    "raw_regs": []
} for i in range(1, 7)}

kwh_prev = {}  # {mid: (kwh, timestamp)}

write_buffer = deque()
buffer_lock = threading.Lock()
BUFFER_FLUSH_SIZE = 36


def crc16(data):
    crc = 0xFFFF
    for b in data:
        crc ^= b
        for _ in range(8):
            crc = (crc >> 1) ^ 0xA001 if crc & 1 else crc >> 1
    return crc


def init_db():
    with sqlite3.connect(DB_PATH) as conn:
        conn.execute("""
            CREATE TABLE IF NOT EXISTS readings (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                unit_id     INTEGER NOT NULL,
                record_time DATETIME NOT NULL,
                kwh         REAL NOT NULL,
                watts       INTEGER,
                status      TEXT,
                balance     REAL,
                UNIQUE(unit_id, record_time)
            )
        """)
        conn.execute("CREATE INDEX IF NOT EXISTS idx_readings_unit_time ON readings(unit_id, record_time)")
        conn.execute("""
            CREATE TABLE IF NOT EXISTS anomaly_log (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                ts        DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now','localtime')),
                raw_hex   TEXT NOT NULL,
                length    INTEGER NOT NULL,
                slave     INTEGER,
                func_code INTEGER,
                note      TEXT
            )
        """)


def init_temp_db():
    with sqlite3.connect(TEMP_DB_PATH) as conn:
        conn.execute("""
            CREATE TABLE IF NOT EXISTS temperatures (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                record_time DATETIME NOT NULL UNIQUE,
                temp_c      REAL NOT NULL
            )
        """)


def read_cpu_temp():
    try:
        with open("/sys/class/thermal/thermal_zone0/temp") as f:
            return round(int(f.read().strip()) / 1000.0, 1)
    except Exception:
        return None


def flush_to_db():
    with buffer_lock:
        if not write_buffer:
            return
        rows = list(write_buffer)
        write_buffer.clear()
    with sqlite3.connect(DB_PATH) as conn:
        conn.executemany("""
            INSERT OR IGNORE INTO readings (unit_id, record_time, kwh, watts, status, balance)
            VALUES (?, ?, ?, ?, ?, ?)
        """, rows)


def shutdown_handler(signum, frame):
    flush_to_db()
    sys.exit(0)

signal.signal(signal.SIGTERM, shutdown_handler)
signal.signal(signal.SIGINT, shutdown_handler)


def process_registers(mid, regs):
    """解析暫存器資料，更新 latest_data"""
    if len(regs) < 24:
        return

    kwh = (regs[1] << 16 | regs[0]) / 100.0
    status_hex = f"{regs[3]:04X}"
    status = STATUS_MAP.get(status_hex, f"未知({status_hex})")
    balance = (regs[23] << 16 | regs[22]) / 100.0

    now = time.time()
    watts_calc = latest_data[mid]["watts_calc"]

    if mid in kwh_prev:
        dk = kwh - kwh_prev[mid][0]
        dt = now - kwh_prev[mid][1]
        if dk > 0 and dt > 1:
            watts_calc = int((dk / (dt / 3600)) * 1000)
            kwh_prev[mid] = (kwh, now)
    else:
        kwh_prev[mid] = (kwh, now)
        watts_calc = 0

    # 超過 10 分鐘沒跳格 → <60W
    if mid in kwh_prev and (now - kwh_prev[mid][1]) > 600:
        watts = -1
    else:
        watts = watts_calc if watts_calc > 0 else 0

    latest_data[mid]["raw_regs"] = list(regs)
    latest_data[mid].update({
        "kwh": round(kwh, 2),
        "watts": watts,
        "watts_calc": watts_calc,
        "status": status,
        "balance": round(balance, 2),
        "last_update": time.strftime("%H:%M:%S")
    })


def log_anomaly(buf, note=""):
    """把非常態幀存進 anomaly_log"""
    slave = buf[0] if len(buf) >= 1 else None
    fc    = buf[1] if len(buf) >= 2 else None
    try:
        with sqlite3.connect(DB_PATH) as conn:
            conn.execute(
                "INSERT INTO anomaly_log (raw_hex, length, slave, func_code, note) VALUES (?,?,?,?,?)",
                (buf.hex(), len(buf), slave, fc, note)
            )
    except Exception:
        pass


def is_normal_poll(buf):
    """
    正常 DAE 輪詢幀特徵：
      61B = 8B request + 53B response（合併）
       8B = 單獨 request（FC03 addr=0 count=24）
      53B = 單獨 response（FC03 byte_count=0x30）
    """
    if not (1 <= buf[0] <= 6):
        return False
    if len(buf) == 8:
        # 標準輪詢 request
        return (buf[1] == 0x03 and buf[2] == 0x00 and buf[3] == 0x00 and
                buf[4] == 0x00 and buf[5] == 0x18)
    if len(buf) == 53:
        # 標準輪詢 response
        return buf[1] == 0x03 and buf[2] == 0x30
    if len(buf) == 61:
        slave = buf[0]
        return (buf[1] == 0x03 and buf[2] == 0x00 and buf[3] == 0x00 and
                buf[4] == 0x00 and buf[5] == 0x18 and
                buf[8] == slave and buf[9] == 0x03 and buf[10] == 0x30)
    return False


def fc05_write(slave_id, coil_addr, value_int):
    """發送 FC05 WriteCoil 指令"""
    payload = bytes([slave_id, 0x05]) + struct.pack('>HH', coil_addr, value_int)
    pkt = payload + struct.pack('<H', crc16(payload))
    with _ser_lock:
        _ser.write(pkt)


def fc10_write_balance(slave_id, kwh):
    """FC10 寫入剩餘度數到 reg 0x0016，32-bit low-word 在前"""
    units = int(round(kwh * 100))
    low_word  = units & 0xFFFF
    high_word = (units >> 16) & 0xFFFF
    payload = (bytes([slave_id, 0x10]) +
               struct.pack('>HH', 0x0016, 2) +
               bytes([4]) +
               struct.pack('>HH', low_word, high_word))
    pkt = payload + struct.pack('<H', crc16(payload))
    with _ser_lock:
        _ser.write(pkt)


def modbus_sniffer():
    """被動旁聽 RS485，解析 DAE master 的輪詢回應，並記錄異常幀"""
    global _ser
    _ser = serial.Serial(PORT, BAUD, timeout=0.02)  # 20ms inter-frame timeout
    ser = _ser

    while True:
        # 收集一幀：讀到 timeout 為止
        buf = bytearray()
        while True:
            b = ser.read(1)
            if not b:
                break
            buf.extend(b)

        if len(buf) < 4:
            continue

        # 情況1：61B 合併幀（正常 DAE 輪詢）
        if len(buf) == 61:
            slave_addr = buf[0]
            if (1 <= slave_addr <= 6 and buf[1] == 0x03 and
                    buf[9] == 0x03 and buf[10] == 0x30):
                if crc16(buf[8:59]) == struct.unpack('<H', buf[59:61])[0]:
                    regs = [struct.unpack('>H', buf[11+j*2:13+j*2])[0] for j in range(24)]
                    process_registers(slave_addr, regs)
                    if not is_normal_poll(buf):
                        log_anomaly(buf, "61B但非標準輪詢")
                else:
                    log_anomaly(buf, "CRC錯誤")
            else:
                log_anomaly(buf, "61B非預期格式")
            continue

        # 情況2：分開幀（CRC 有效）
        if len(buf) >= 5 and crc16(buf[:-2]) == struct.unpack('<H', buf[-2:])[0]:
            # 先判斷是否為常態幀，是的話直接略過
            if is_normal_poll(buf):
                # 若是分開的 response 幀，仍需解析資料
                if len(buf) == 53 and buf[1] == 0x03 and buf[2] == 0x30:
                    regs = [struct.unpack('>H', buf[3+j*2:5+j*2])[0] for j in range(24)]
                    process_registers(buf[0], regs)
                continue

            slave_addr = buf[0]
            fc = buf[1]
            if 1 <= slave_addr <= 6 and fc == 0x03 and buf[2] == len(buf) - 5:
                n = buf[2] // 2
                regs = [struct.unpack('>H', buf[3+j*2:5+j*2])[0] for j in range(n)]
                process_registers(slave_addr, regs)
            else:
                fc_name = {0x03: "ReadHoldingRegs", 0x06: "WriteSingleReg",
                           0x10: "WriteMultiRegs", 0x05: "WriteCoil"}.get(fc, f"FC{fc:02X}")
                log_anomaly(buf, fc_name)
        elif len(buf) > 8:
            log_anomaly(buf, "CRC無效")


def recorder():
    """每 10 分鐘快照存 buffer，buffer 滿寫入 DB"""
    last_saved_slot = None
    prev_kwh = {}

    while True:
        now = time.localtime()
        slot_min = (now.tm_min // 10) * 10
        current_slot = (now.tm_year, now.tm_mon, now.tm_mday, now.tm_hour, slot_min)

        if last_saved_slot != current_slot:
            record_time = time.strftime(f"%Y-%m-%d %H:{slot_min:02d}:00", now)

            with buffer_lock:
                for mid in range(1, 7):
                    d = latest_data[mid]
                    if d["status"] == "---":
                        continue
                    watts = None
                    if mid in prev_kwh:
                        delta_kwh = d["kwh"] - prev_kwh[mid]
                        watts = int(delta_kwh * 6000) if delta_kwh > 0 else 0
                    prev_kwh[mid] = d["kwh"]
                    write_buffer.append((mid, record_time, d["kwh"], watts, d["status"], d["balance"]))

            if len(write_buffer) >= BUFFER_FLUSH_SIZE:
                flush_to_db()

            # 記錄溫度
            temp = read_cpu_temp()
            if temp is not None:
                with sqlite3.connect(TEMP_DB_PATH) as conn:
                    conn.execute("INSERT OR IGNORE INTO temperatures (record_time, temp_c) VALUES (?, ?)",
                                 (record_time, temp))

            last_saved_slot = current_slot

        time.sleep(30)


init_db()
init_temp_db()
threading.Thread(target=modbus_sniffer, daemon=True).start()
threading.Thread(target=recorder, daemon=True).start()


@app.route("/")
def index():
    return render_template("index.html")


@app.route("/api/status")
def get_status():
    sorted_list = sorted(latest_data.values(), key=lambda x: x["room"])
    return jsonify({"time": datetime.now().strftime("%Y-%m-%d %H:%M:%S"), "temp_c": read_cpu_temp(), "rooms": sorted_list})


@app.route("/api/temp_history")
def get_temp_history():
    try:
        with sqlite3.connect(TEMP_DB_PATH) as conn:
            rows = conn.execute("""
                SELECT record_time, temp_c FROM temperatures
                WHERE record_time >= datetime('now', '-24 hours', 'localtime')
                ORDER BY record_time ASC
            """).fetchall()
        return jsonify([{"t": r[0], "c": r[1]} for r in rows])
    except Exception as e:
        return jsonify({"error": str(e)}), 500


@app.route("/api/raw/<int:unit_id>")
def get_raw(unit_id):
    if unit_id not in range(1, 7):
        return jsonify({"error": "invalid unit_id"}), 400
    regs = latest_data[unit_id].get("raw_regs", [])
    if not regs:
        return jsonify({"error": "no data yet"}), 503
    return jsonify({
        "unit_id": unit_id,
        "room": ROOM_MAP.get(unit_id, f"ID {unit_id}"),
        "last_update": latest_data[unit_id]["last_update"],
        "registers": [
            {"i": i, "dec": v, "hex": f"0x{v:04X}", "hi": (v >> 8) & 0xFF, "lo": v & 0xFF}
            for i, v in enumerate(regs)
        ]
    })


@app.route("/api/history/<int:unit_id>")
def get_history(unit_id):
    """指定日期 00:00~23:00 固定 24 個 slot，沒資料填 0；不帶 date 參數則為今天"""
    date_str = request.args.get('date', '')
    try:
        target_date = datetime.strptime(date_str, '%Y-%m-%d').strftime('%Y-%m-%d') if date_str else None
    except ValueError:
        target_date = None

    try:
        with sqlite3.connect(DB_PATH) as conn:
            if target_date:
                db_rows = conn.execute("""
                    SELECT strftime("%H", record_time) as hour,
                           SUM(CASE WHEN watts IS NOT NULL AND watts >= 0 THEN watts / 6000.0 ELSE 0 END) as usage,
                           MAX(kwh) as kwh
                    FROM readings
                    WHERE unit_id = ? AND date(record_time) = ?
                    GROUP BY hour ORDER BY hour ASC
                """, (unit_id, target_date)).fetchall()
            else:
                db_rows = conn.execute("""
                    SELECT strftime("%H", record_time) as hour,
                           SUM(CASE WHEN watts IS NOT NULL AND watts >= 0 THEN watts / 6000.0 ELSE 0 END) as usage,
                           MAX(kwh) as kwh
                    FROM readings
                    WHERE unit_id = ? AND date(record_time) = date('now', 'localtime')
                    GROUP BY hour ORDER BY hour ASC
                """, (unit_id,)).fetchall()

        data = {r[0]: (round(r[1], 3), r[2]) for r in db_rows}
        labels = [f"{h:02d}:00" for h in range(24)]
        usage  = [data[f"{h:02d}"][0] if f"{h:02d}" in data else 0 for h in range(24)]
        kwh    = [data[f"{h:02d}"][1] if f"{h:02d}" in data else None for h in range(24)]
        return jsonify({"labels": labels, "usage": usage, "readings": kwh})
    except Exception as e:
        return jsonify({"error": str(e)}), 500


@app.route("/api/history_table")
def get_history_table():
    days = min(int(request.args.get("days", 7)), 90)
    try:
        with sqlite3.connect(DB_PATH) as conn:
            db_rows = conn.execute("""
                SELECT unit_id, record_time, kwh, watts, status, balance
                FROM readings
                WHERE record_time >= strftime("%Y-%m-%d %H:%M:%S", "now", "localtime", ? || " days")
                ORDER BY record_time DESC, unit_id ASC
            """, (f"-{days}",)).fetchall()

        with buffer_lock:
            buf_rows = [(r[0], r[1], r[2], r[3], r[4], r[5]) for r in write_buffer]

        rows = list(db_rows) + buf_rows
        rows_asc = sorted(rows, key=lambda r: (r[0], r[1]))

        prev_kwh = {}
        result = []
        for r in rows_asc:
            uid, rec_time, kwh, watts, status, balance = r
            usage = None
            if uid in prev_kwh:
                diff = round(kwh - prev_kwh[uid], 4)
                usage = max(0, diff)
            prev_kwh[uid] = kwh
            result.append({
                "time": rec_time,
                "room": ROOM_MAP.get(uid, f"ID {uid}"),
                "kwh": kwh,
                "usage": usage
            })
        result.reverse()
        return jsonify({"rows": result})
    except Exception as e:
        return jsonify({"error": str(e)}), 500


ACTIONS = {
    "force_on":      [(0x000A, 0x0000), (0x000B, 0x0000), (0x0002, 0xFF00)],
    "force_off":     [(0x000A, 0x0000), (0x000B, 0x0000), (0x0002, 0x0000)],
    "prepaid_mode":  [(0x000A, 0x0000), (0x000B, 0xFF00)],
    "exit_prepaid":  [(0x000A, 0x0000), (0x000B, 0x0000)],
    "prepaid_on":    [(0x0052, 0xFF00)],
    "prepaid_off":   [(0x0052, 0x0000)],
}
REQUIRES_PREPAID     = {"prepaid_on", "prepaid_off", "exit_prepaid"}
REQUIRES_NON_PREPAID = {"prepaid_mode"}


PREPAID_STATUSES = {"預付", "欠費", "預關"}


def _guard(unit_id, action):
    status = latest_data[unit_id]["status"]
    is_prepaid = status in PREPAID_STATUSES
    if action in REQUIRES_PREPAID and not is_prepaid:
        return f"需要儲值模式（目前：{status}）"
    if action in REQUIRES_NON_PREPAID and is_prepaid:
        return "儲值模式下無法執行，請先退出儲值模式"
    return None


@app.route("/api/action/<int:unit_id>/<action>", methods=["POST"])
def do_action(unit_id, action):
    if unit_id not in range(1, 7):
        return jsonify({"error": "invalid unit_id"}), 400
    if _ser is None:
        return jsonify({"error": "serial not ready"}), 503
    if action not in ACTIONS:
        return jsonify({"error": "unknown action"}), 400
    err = _guard(unit_id, action)
    if err:
        return jsonify({"error": err}), 400
    for coil, value in ACTIONS[action]:
        fc05_write(unit_id, coil, value)
        time.sleep(0.1)
    return jsonify({"ok": True})


@app.route("/api/recharge/<int:unit_id>", methods=["POST"])
def recharge_unit(unit_id):
    if unit_id not in range(1, 7):
        return jsonify({"error": "invalid unit_id"}), 400
    if _ser is None:
        return jsonify({"error": "serial not ready"}), 503
    err = _guard(unit_id, "prepaid_on")
    if err:
        return jsonify({"error": err}), 400
    body = request.get_json()
    add_kwh = float(body.get("kwh", 0))
    if add_kwh <= 0:
        return jsonify({"error": "invalid kwh"}), 400
    current = latest_data[unit_id]["balance"]
    new_total = round(current + add_kwh, 2)
    for coil, value in ACTIONS["prepaid_mode"]:  # 確保在儲值模式
        fc05_write(unit_id, coil, value)
        time.sleep(0.1)
    time.sleep(0.2)
    fc10_write_balance(unit_id, new_total)
    return jsonify({"ok": True, "before": current, "added": add_kwh, "after": new_total})


@app.route("/api/zero/<int:unit_id>", methods=["POST"])
def zero_unit(unit_id):
    if unit_id not in range(1, 7):
        return jsonify({"error": "invalid unit_id"}), 400
    if _ser is None:
        return jsonify({"error": "serial not ready"}), 503
    err = _guard(unit_id, "prepaid_on")
    if err:
        return jsonify({"error": err}), 400
    for coil, value in ACTIONS["prepaid_mode"]:
        fc05_write(unit_id, coil, value)
        time.sleep(0.1)
    time.sleep(0.2)
    fc10_write_balance(unit_id, 0)
    return jsonify({"ok": True})


@app.route("/api/recorded_dates")
def get_recorded_dates():
    try:
        with sqlite3.connect(DB_PATH) as conn:
            rows = conn.execute(
                "SELECT DISTINCT date(record_time) FROM readings ORDER BY 1 DESC"
            ).fetchall()
        return jsonify([r[0] for r in rows])
    except Exception as e:
        return jsonify({"error": str(e)}), 500


@app.route("/anomalies")
def anomalies_page():
    return render_template("anomalies.html")


@app.route("/api/anomalies")
def get_anomalies():
    limit = min(int(request.args.get("limit", 200)), 1000)
    try:
        with sqlite3.connect(DB_PATH) as conn:
            rows = conn.execute("""
                SELECT ts, raw_hex, length, slave, func_code, note
                FROM anomaly_log
                ORDER BY id DESC LIMIT ?
            """, (limit,)).fetchall()
        return jsonify([{
            "ts": r[0], "raw_hex": r[1], "length": r[2],
            "slave": r[3], "func_code": r[4], "note": r[5]
        } for r in rows])
    except Exception as e:
        return jsonify({"error": str(e)}), 500


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5001, debug=False)
